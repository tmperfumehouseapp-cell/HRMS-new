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

/** Meta's answer when the template could not even be CREATED (a name / language that already exists, an API error): not a review of the wording, so it must not teach the writer anything. */
function wa_util_is_creation_error(string $why): bool
{
    return $why !== '' && (stripos($why, 'Unsupported post request') === 0 || stripos($why, 'already has') !== false || stripos($why, 'already exists') !== false || mb_strpos($why, 'પહેલેથી') !== false || stripos($why, 'content already') !== false);
}
/** 🧠 LEARNING FROM META (owner, 17 Nov 2026): every utility message written so far is looked at again — what Meta APPROVED, what it REJECTED or moved to marketing, and the reason it gave.
 *  The writer (Claude) is shown real examples of both and the words that appear in rejected messages but never in approved ones; when more than half of the last decisions are rejections it is told to write much plainer
 *  (STRICT). The learned words are also refused locally before anything goes to Meta. Remembered for 10 minutes. Returns ['appr','rej','rate','strict','bad' => [words],'good' => [..],'badx' => [[body, reason]]]. */
function wa_util_learn(PDO $pdo, bool $force = false): array
{
    static $memo = null; if ($memo !== null && !$force) return $memo;
    $c = json_decode((string)setting_get($pdo, 'wa_util_learn', ''), true); if (!$force && is_array($c) && (int)($c['at'] ?? 0) > time() - 600) { $GLOBALS['__wa_util_learned_bad'] = (array)($c['bad'] ?? []); return $memo = $c; }
    $o = ['at' => time(), 'appr' => 0, 'rej' => 0, 'rate' => 0, 'strict' => false, 'bad' => [], 'good' => [], 'badx' => []]; $A = []; $Rj = [];
    try {
        $q = $pdo->prepare("SELECT t.id, t.body, t.relabelled_at, GROUP_CONCAT(CONCAT(w.status, ':', LEFT(w.reason, 100)) SEPARATOR '|') AS st FROM wa_templates t JOIN wa_tpl_waba w ON w.tpl_id = t.id
            WHERE t.category = 'UTILITY' AND t.name LIKE 'util\\_%' AND t.created_at >= ? GROUP BY t.id, t.body, t.relabelled_at ORDER BY t.id DESC LIMIT 300"); $q->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
        foreach ($q->fetchAll() as $r) {
            $rows = array_filter(explode('|', (string)$r['st'])); $stt = []; $why = ''; foreach ($rows as $x) { $p2 = explode(':', $x, 2); $stt[] = strtoupper($p2[0]); if ($why === '' && in_array(strtoupper($p2[0]), ['REJECTED', 'PAUSED', 'DISABLED'], true)) $why = trim((string)($p2[1] ?? '')); if (strtoupper($p2[0]) === 'REJECTED' && wa_util_is_creation_error((string)($p2[1] ?? ''))) { array_pop($stt); $stt[] = 'ERROR'; } }
            $body = (string)$r['body'];
            if (!empty($r['relabelled_at'])) { $Rj[] = [$body, 'Meta moved it to marketing']; }
            elseif (in_array('APPROVED', $stt, true)) { $A[] = $body; }
            elseif (!array_intersect(['PENDING', 'IN_APPEAL', 'ERROR'], $stt) && in_array('REJECTED', $stt, true)) { $Rj[] = [$body, $why]; }
        }
    } catch (Throwable $e) { }
    $o['appr'] = count($A); $o['rej'] = count($Rj); $dec = $o['appr'] + $o['rej']; $o['rate'] = $dec ? round($o['rej'] / $dec, 2) : 0; $o['strict'] = $dec >= 8 && $o['rate'] >= 0.5;
    $words = function (string $b): array { $b = mb_strtolower(preg_replace('/\{\{\d+\}\}/', ' ', $b)); preg_match_all('/[\p{L}]{4,}/u', $b, $m); return array_values(array_unique($m[0])); };
    $df = function (array $docs) use ($words): array { $d = []; foreach ($docs as $b) foreach ($words($b) as $w) $d[$w] = ($d[$w] ?? 0) + 1; return $d; };
    $dr = $df(array_map(fn($x) => $x[0], $Rj)); $da = $df($A); $stop = ['this', 'that', 'with', 'have', 'your', 'from', 'about', 'please', 'reply', 'here', 'and', 'for', 'you', 'the', 'perfume', 'enquiry', 'size', 'details', 'house', 'thanks', 'kya', 'aap', 'aapka', 'aapke', 'hain', 'hai', 'main', 'tha', 'liye'];
    $bad = []; if (count($Rj) >= 3) foreach ($dr as $w => $n) if ($n >= 3 && empty($da[$w]) && $n / count($Rj) >= 0.3 && !in_array($w, $stop, true)) $bad[$w] = $n;
    arsort($bad); $o['bad'] = array_slice(array_keys($bad), 0, 15);
    $o['good'] = array_map(fn($b) => mb_substr($b, 0, 260), array_slice($A, 0, 4)); $o['badx'] = array_map(fn($x) => [mb_substr($x[0], 0, 260), mb_substr($x[1], 0, 100)], array_slice($Rj, 0, 5));
    $GLOBALS['__wa_util_learned_bad'] = $o['bad']; try { setting_set($pdo, 'wa_util_learn', json_encode($o, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { }
    return $memo = $o;
}
/** The text added to the writer's instructions: what worked, what Meta refused (with its reason), the words to avoid, and STRICT mode when most are refused. */
function wa_util_learn_text(PDO $pdo): string
{
    $L = wa_util_learn($pdo); if (!$L['appr'] && !$L['rej']) return '';
    $t = "WHAT META DID WITH THE UTILITY MESSAGES WRITTEN SO FAR (last 30 days: " . $L['appr'] . " approved, " . $L['rej'] . " rejected or moved to marketing). Learn from it and CHANGE your pattern when needed.\n";
    if ($L['good']) { $t .= "APPROVED by Meta as utility — write in this plain style:\n"; foreach ($L['good'] as $i => $b) $t .= ($i + 1) . ') ' . $b . "\n"; }
    if ($L['badx']) { $t .= "REJECTED / moved to marketing — do NOT write like these:\n"; foreach ($L['badx'] as $i => $x) $t .= ($i + 1) . ') ' . $x[0] . ($x[1] !== '' ? '   [Meta: ' . $x[1] . ']' : '') . "\n"; }
    if ($L['bad']) $t .= "Words that appear in rejected messages and never in approved ones — do NOT use them: " . implode(', ', $L['bad']) . ".\n";
    if ($L['strict']) $t .= "STRICT MODE: more than half of the recent messages were refused. Write MUCH plainer: at most two short sentences, no emojis, no exclamation marks, no sales tone, no 'ready / available / best / special / new'; only confirm what the customer did (their enquiry about {{2}}) and ask ONE neutral question about what they need next. Change the wording and structure completely from the rejected examples.\n";
    return $t . "\n";
}

/** Why a utility body cannot be used ('' = fine): Meta's marketing words, or the variables are not right. */
function wa_util_check(string $body): string
{
    $body = trim($body); if ($body === '') return 'The message is empty.';
    $low = mb_strtolower($body); $bad = array_values(array_filter(array_merge(wa_util_bad_words(), (array)($GLOBALS['__wa_util_learned_bad'] ?? [])), fn($w) => $w !== '' && mb_strpos($low, $w) !== false));   // + the words learned from Meta's rejections (wa_util_learn)
    if ($bad) return 'Meta would call it marketing because of: ' . implode(', ', $bad) . '.';
    preg_match_all('/\{\{(\d+)\}\}/', $body, $vm); $vars = array_map('intval', $vm[1]);
    if (!in_array(1, $vars, true) || !in_array(2, $vars, true)) return 'It must use {{1}} (customer name) and {{2}} (the perfume).';
    if (max($vars ?: [0]) > 3) return 'Only {{1}} name, {{2}} perfume and {{3}} date can be used.';
    if (preg_match('/^\s*\{\{|\{\{\d\}\}\s*$/', $body) || preg_match('/\}\}\s*\{\{/', $body) || preg_match('/\{\{\d+\}\}[^\p{L}\p{N}{]*$/u', $body) || preg_match('/^[^\p{L}\p{N}{]*\{\{\d+\}\}/u', $body)) return 'A variable cannot be at the very start or end, or next to another variable.';
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
    $wabas = function_exists('wa_wabas') ? array_values(wa_wabas($pdo)) : [];   // wa_wabas() already returns the account ids (a list) — array_keys() made them 0,1,2,3 and every submit went to accounts that do not exist if (!$wabas) return ['error' => 'No WhatsApp account is set up.'];
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
         . wa_util_learn_text($pdo)
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
    $d = wa_util_pool_defs_raw();
    // owner 24 Nov 2026: a SPACED twin (a blank line between the thoughts) of every warm Hinglish message that was made line by line (the first version stays in rotation; they are compared by reply rate)
    if (!function_exists('wa_space_body') && is_file(__DIR__ . '/wa_space.php')) { try { require_once __DIR__ . '/wa_space.php'; } catch (Throwable $e) { } }
    if (function_exists('wa_space_body')) { foreach ($d as $k => $x) { if (strpos($k, '_hi_') === false || substr($k, -2) === '_s') continue; $sb = wa_space_body((string)$x['body']); if ($sb !== (string)$x['body']) { $x['body'] = $sb; $d[$k . '_s'] = $x; } } }
    return $d;
}
function wa_util_pool_defs_raw(): array
{
    $qr = fn(array $l) => array_map(fn($t) => ['type' => 'QUICK_REPLY', 'text' => $t], $l);
    return [
        'ask_followup' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Yes, I need it', 'Not now']),
            'body' => "Hi {{1}}, you asked us about {{2}} on {{3}}. Do you still need it? Reply here and we will help you complete it."],
        'ask_stock' => ['kind' => 'ask', 'labels' => ['name', 'perfume'], 'ex' => ['Rahul', 'Dior Sauvage'], 'buttons' => $qr(['Yes, keep it', 'Show options']),
            'body' => "Hello {{1}}, an update on your enquiry about {{2}}: it is available now. Would you like us to keep it for you?"],
        'ask_size' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Share the sizes', 'Not now']),
            'body' => "Hi {{1}}, about your enquiry for {{2}} on {{3}}: please tell us the size you need, so we can confirm it for you."],
        'ask_checked' => ['kind' => 'ask', 'labels' => ['name', 'date'], 'ex' => ['Rahul', '28 Oct'], 'buttons' => $qr(['Yes, I need help', 'Not now']),
            'body' => "Hi {{1}}, you contacted TM Perfume House on {{2}}. Do you still need any help with your perfume choice? Reply here and we will help you."],
        'open_welcome' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, please', 'Not now']),
            'body' => "Hi {{1}}, thank you for being a TM Perfume House customer. Would you like us to share news about our perfumes and help you with your next choice? Tap a button to reply."],
        'open_hello' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, please', 'Not now']),
            'body' => "Hello {{1}}, this is TM Perfume House. Are you looking for a perfume for yourself or as a gift? Tap a button to reply."],
        'open_help' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['I need help', 'Not now']),
            'body' => "Hi {{1}}, we are here if you need help choosing a perfume or checking an order. Reply with a button below."],
        'open_thanks' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, please', 'Not now']),
            'body' => "Hi {{1}}, thank you for choosing TM Perfume House earlier. May we help you with anything today? Tap a button to reply."],
        'open_checkin' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Perfume', 'Order or delivery']),
            'body' => "Hello {{1}}, a quick check from TM Perfume House: would you like help with a perfume, an order or delivery? Tap a button to reply."],
        'open_catalog' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Send it', 'Not now']),
            'body' => "Hi {{1}}, would you like us to share our perfume catalogue with you on WhatsApp? Tap a button to reply."],
        'open_gift' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, a gift', 'Not now']),
            'body' => "Hi {{1}}, are you planning a gift? We can suggest perfumes for any budget. Tap a button to reply."],
        'open_friendly' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, please', 'Not now']),
            'body' => "Hello {{1}}, TM Perfume House here. Is there anything we can help you with? Tap a button to reply."],
        'ask_pincode' => ['kind' => 'ask', 'labels' => ['name', 'perfume'], 'ex' => ['Rahul', 'Dior Sauvage'], 'buttons' => $qr(['Send my pincode', 'Not now']),
            'body' => "Hi {{1}}, to check the delivery time for {{2}} to your area, please reply with your pincode."],
        'order_check' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Yes, all good', 'Need help']),
            'body' => "Hi {{1}}, did your order {{2}} placed on {{3}} reach you in good condition? Reply here if you need any help with it."],
        'order_care' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Ask a question', 'All good']),
            'body' => "Hello {{1}}, this is about your order {{2}} from {{3}}. If you have any question about using or storing your perfume, just reply here and we will help."],
        // 💬 owner, 18 Nov 2026 (evening): 5 more welcome messages (the first set was moved to marketing by Meta / paused for low quality), and 3 new kinds — the order experience, the experience with TM Perfume House, a request to the team. All are about the customer's own relationship with the shop: no offer, no price, no promotion words.
        'open_w_care' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['I need help', 'Not now']),
            'body' => "Hi {{1}}, thank you for being a TM Perfume House customer. We are here to help with your orders, deliveries and any question about your perfume. Tap a button to reply."],
        'open_w_team' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Order help', 'Not now']),
            'body' => "Hello {{1}}, this is the TM Perfume House customer care team. If you need help with an order, a delivery or a perfume you bought from us, tap a button below."],
        'open_w_saved' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['I need help', 'Not now']),
            'body' => "Hi {{1}}, your contact is saved with TM Perfume House, so we can help you faster with any order or question. Reply with a button below whenever you need us."],
        'open_w_back' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Order status', 'Perfume help']),
            'body' => "Hello {{1}}, welcome back to TM Perfume House. You can reply here any time for order status, delivery updates or help with a perfume. Tap a button to reply."],
        'open_w_customer' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, I need help', 'Not now']),
            'body' => "Hi {{1}}, this message is from TM Perfume House, where you are a registered customer. Reply here if you need help with an order, a delivery or a product."],
        // 🔔 owner, 19 Nov 2026: "perfume reminder" messages for campaigns Meta flagged as spam (131048) — name-only, plain, no offer and no price, so they fit any customer on the list. They carry on a paused campaign by themselves (wa_camp_spam_rescue).
        'open_rem_perfume' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Perfume help', 'Not now']),
            'body' => "Hi {{1}}, a friendly reminder from TM Perfume House: we are here to help you with your perfume, your order or a delivery. Tap a button to reply."],
        'open_rem_next' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Yes, please', 'Not now']),
            'body' => "Hello {{1}}, this is a reminder from TM Perfume House. Would you like help choosing the perfume you need next? Tap a button to reply."],
        'open_rem_care' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['I need help', 'Not now']),
            'body' => "Hi {{1}}, a reminder from TM Perfume House: our team can help you with your perfume or any order. Reply with a button below."],
        'open_rem_chat' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Chat with us', 'Not now']),
            'body' => "Hello {{1}}, a quick reminder that you can chat with TM Perfume House any time for help with a perfume or an order. Tap a button to reply."],
        // 💬 owner, 24 Nov 2026: the warm Hinglish style the customers like (name, emoji, one clear choice by button) - for people who never wrote: no offer, no deal word, no claim of an enquiry or an order
        'open_hi_1' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Apne liye', 'Gift ke liye']),
            'body' => "Hi {{1}} 🤩\n\nTM Perfume House me is season ki sabse zyada pasand ki jaane wali khushbuyein aa gayi hain 🔥\n\nAap apne liye dekhna chahenge ya kisi ko gift dene ke liye ? Button dabake dekhiye 👇"],
        'open_hi_2' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Halki', 'Tez', 'Mix']),
            'body' => "Hello {{1}} 😍\n\nShaadi aur winter season ki khaas khushbuyein tayaar hain ✨\n\nAapko kaisi pasand hai ? Button dabake apni pasand batayein 👇"],
        'open_hi_3' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi']),
            'body' => "Hi {{1}} 🎁\n\nGift season shuru ho gaya he, aur humne aapke liye ek khaas pick tayaar rakha he 👀\n\nDekhna chahenge ? Button dabaiye 👇"],
        // ✨ owner, 25 Nov 2026: utility messages that are exciting but still ABOUT THE CUSTOMER'S OWN earlier chat / enquiry / order (so Meta keeps them utility): season, curiosity, spacing, one choice by button, no deal words
        'open_ex_1' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi']),
            'body' => "Hi {{1}} 🤩\n\nAapne pehle TM Perfume House se baat ki thi, aur is shaadi-season ke liye ek khaas khushbu ki list aapke liye taiyaar he ✨\n\nDekhna chahenge ? Button dabaiye 👇"],
        'open_ex_2' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['20ML', '50ML', '100ML']),
            'body' => "Hello {{1}} 😍\n\nSardi shuru ho gayi he, aur aapki pasand ki khushbuyein aapke ek message ka intezaar kar rahi hain 🔥\n\nKaunsa size dekhna chahenge ? 👇"],
        'open_ex_3' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Apne liye', 'Gift ke liye']),
            'body' => "Hi {{1}} 🎁\n\nAapke pichhle sawal ke baad humne gift-season ke liye ek khaas surprise taiyaar kiya he 👀\n\nBatayein, apne liye ya kisi ko gift dene ke liye ? 👇"],
        'open_ex_4' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Haan, dikhao', 'Baad me']),
            'body' => "Hello {{1}} 🌟\n\nAapse baat hone ke baad humare paas kuch nayi khushbuyein aayi hain, jo aapko pasand aa sakti hain 🤩\n\nDekhna chahenge ? 👇"],
        'open_ex_5' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Zaroor', 'Abhi nahi']),
            'body' => "Hi {{1}} 💫\n\nWinter wedding-season me aapke liye ek chhota sa secret humne bachake rakha he 🤫\n\nJaanna chahenge ? Button dabaiye 👇"],
        'ask_ex_1' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi']),
            'body' => "Hi {{1}} 😊\n\nAapne {{3}} ko {{2}} ke bare me poocha tha 🌸\n\nAb uske jaisi hi ek khaas khushbu humare paas he 🤩 Dekhna chahenge ? 👇"],
        'ask_ex_2' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['20ML', '50ML', '100ML']),
            'body' => "Hello {{1}} 😍\n\n{{2}} ke liye aapka sawal ({{3}}) humein yaad he, aur ab uska ek naya size aapke liye taiyaar he ✨\n\nKaunsa size dekhna chahenge ? 👇"],
        'order_ex_1' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Dikhao', 'Baad me']),
            'body' => "Hi {{1}} 🤩\n\nAapka order {{2}} ({{3}}) kaisa laga ? Isi khushbu jaisi ek aur khaas khushbu aapke liye taiyaar he 🔥\n\nJaanna chahenge ? 👇"],
        // 💬 owner, 24 Nov 2026: the same warm Hinglish style for the enquiry / order follow-ups (they join the rotation, so they are tested against the older wording by reply rate)
        'ask_hi_followup' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Haan, chahiye', 'Abhi nahi']),
            'body' => "Hi {{1}} 😊 aapne {{3}} ko {{2}} ke bare me poocha tha... abhi bhi chahiye ? Neeche button dabaiye 👇"],
        'ask_hi_size' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['20ML', '50ML', '100ML']),
            'body' => "Hi {{1}} 🌸 {{2}} ke liye aapka sawal ({{3}}) humein yaad he... kaunsa size chahiye ? Button dabaiye 👇"],
        'ask_hi_checked' => ['kind' => 'ask', 'labels' => ['name', 'date'], 'ex' => ['Rahul', '28 Oct'], 'buttons' => $qr(['Haan, dikhao', 'Abhi nahi']),
            'body' => "Hello {{1}} 😊\n\nAapne {{2}} ko TM Perfume House se baat ki thi, aur ab ek nayi khushbu aapka intezaar kar rahi he 🤩\n\nDekhna chahenge ? Button dabaiye 👇"],
        'order_hi_check' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Sab badhiya', 'Madad chahiye']),
            'body' => "Hi {{1}} 😊 aapka order {{2}} ({{3}}) sahi se pahunch gaya na ? Koi dikkat ho to button dabaiye 👇"],
        'order_hi_exp' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Bahut badhiya', 'Thoda alag']),
            'body' => "Hello {{1}} 🌸 order {{2}} ({{3}}) ka perfume aapko kaisa laga ? Apna experience button se batayein 👇"],
        'open_hi_4' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['20ML', '50ML', '100ML']),
            'body' => "Hi {{1}} 🌟\n\nAapki pasand jaisi hi kuch nayi khushbuyein abhi aayi hain 🤩\n\nKaunsa size dekhna chahenge ? Button dabaiye 👇"],
        // 📦 owner, 24 Nov 2026: ORDER-CENTRIC utility: each one is about the customer's OWN enquiry or order and ends with an order step (a button); the offer goes free after the reply (wa_util_offer_reply). No price, no offer, no urgency word.
        'ask_ord_complete' => ['kind' => 'ask', 'labels' => ['name', 'perfume', 'date'], 'ex' => ['Rahul', 'Dior Sauvage', '28 Oct'], 'buttons' => $qr(['Order banana he', 'Abhi nahi']),
            'body' => "Hi {{1}} 😊\n\n{{3}} ko aapne {{2}} ke bare me poocha tha.\n\nOrder complete karna he ? Size aur address bata dijiye, hum order bana denge 📦"],
        'ask_ord_address' => ['kind' => 'ask', 'labels' => ['name', 'perfume'], 'ex' => ['Rahul', 'Dior Sauvage'], 'buttons' => $qr(['Address bhejta hoon', 'Abhi nahi']),
            'body' => "Hello {{1}} 📦\n\nAapki {{2}} ki request ke liye delivery address aur pincode bhej dijiye, hum order process kar denge 🙏"],
        'order_ord_reorder' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Dobara order', 'Sab badhiya']),
            'body' => "Hi {{1}} 😊\n\nAapka order {{2}} ({{3}}) kaisa raha ?\n\nDobara order karna ho to neeche tap kijiye, hum turant order bana denge 📦"],
        'order_ord_more' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Aur chahiye', 'Abhi nahi']),
            'body' => "Hello {{1}} ✨\n\nOrder {{2}} ({{3}}) ke baad aur koi perfume chahiye to bataiye, hum aapka naya order bana denge 📦"],
        'order_exp_how' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['It was great', 'I had an issue']),
            'body' => "Hi {{1}}, your order {{2}} placed on {{3}} should have reached you by now. How was your experience with it? Tap a button to share."],
        'order_exp_share' => ['kind' => 'order', 'labels' => ['name', 'order', 'date'], 'ex' => ['Rahul', '#1234', '28 Oct'], 'buttons' => $qr(['Share experience', 'All good']),
            'body' => "Hello {{1}}, we hope you are enjoying the perfume from your order {{2}} of {{3}}. Please share your experience with us by replying here."],
        'open_exp_tm' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Very good', 'Could be better']),
            'body' => "Hi {{1}}, thank you for shopping with TM Perfume House. How was your experience with us overall? Tap a button to share."],
        'open_exp_feedback' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Share feedback', 'Not now']),
            'body' => "Hello {{1}}, your feedback on TM Perfume House matters to us. Please tell us how we did, in one short reply."],
        'open_req_help' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['I have a request', 'No request']),
            'body' => "Hi {{1}}, do you have any request for TM Perfume House, such as an order change, a delivery question or a product query? Tap a button and we will help you."],
        'open_req_team' => ['kind' => 'open', 'labels' => ['name'], 'ex' => ['Rahul'], 'buttons' => $qr(['Raise a request', 'Not now']),
            'body' => "Hello {{1}}, if you want to request anything from TM Perfume House, for example a return, a replacement or an invoice, reply here and our team will assist you."],
    ];
}
/** The pool rows that exist: key => template row (+ kind). */
function wa_util_pool_rows(PDO $pdo, bool $withRetired = false): array
{
    $o = []; foreach ($pdo->query("SELECT * FROM wa_templates WHERE extra LIKE '%util_pool%' ORDER BY id")->fetchAll() as $r) { $x = json_decode((string)($r['extra'] ?? ''), true); if (!$withRetired && is_array($x) && !empty($x['retired'])) continue;   /* replaced by an approved, more exciting twin (owner 25 Nov 2026) */ $k = is_array($x) ? (string)($x['util_pool'] ?? '') : ''; if ($k !== '' && !isset($o[$k])) { $r['pool_kind'] = (string)($x['util_kind'] ?? 'ask'); $o[$k] = $r; } }
    return $o;
}
/** Creates the missing pool messages and sends them to Meta on every WhatsApp account. */
function wa_util_pool_make(PDO $pdo, int $by): array
{
    $have = wa_util_pool_rows($pdo, true); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 6 * max(1, count($wabas))]; $made = []; $err = [];
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
/** A message to "anyone" (the open kind) must not claim a recent enquiry, order, purchase or saved details: the customer may never have done that (a false claim makes people report the message as spam). Used for the messages the system writes by itself. */
function wa_util_claims_history(string $body): bool
{
    return (bool)preg_match('/\b(recent|your (?:recent )?(?:enquiry|inquiry|order|purchase|catalogue)|on (?:file|record)|you (?:asked|enquired|inquired|bought|purchased|ordered|received)|(?:bought|received) from us)\b/i', $body);
}
/** 🎯 owner, 18 Nov 2026 (night): a self-written message that names an enquiry or an order goes ONLY to a customer whose behaviour makes it true: wrote to us within the asked-days (enquiry) / ordered within the order-days (order). A claim about a catalogue is never made (we do not know what they asked). */
function wa_util_claim_ok(string $body, bool $hasEnq, bool $hasOrd): bool
{
    if (preg_match('/catalogue/i', $body)) return false;
    $e = (bool)preg_match('/\b(enquiry|inquiry|enquired|inquired|asked)\b/i', $body); $o = (bool)preg_match('/\b(orders?|purchase|purchased|bought|ordered|received)\b/i', $body);
    if ($e && $o) return $hasEnq || $hasOrd; if ($e) return $hasEnq; if ($o) return $hasOrd; return $hasEnq || $hasOrd;
}
/** 🧠 Words that only appear in the messages Meta MOVED to marketing (never in the ones it kept as utility): the new message must not use them. */
function wa_util_learn_avoid(array $keptBodies, array $movedBodies): array
{
    $tok = function (string $b): array { $b = preg_replace('/\{\{\d+\}\}/', ' ', mb_strtolower($b)); preg_match_all('/[a-z]{4,}/', (string)$b, $m); return array_values(array_unique($m[0])); };
    $keep = []; foreach ($keptBodies as $b) foreach ($tok((string)$b) as $w) $keep[$w] = 1;
    $out = []; foreach ($movedBodies as $b) foreach ($tok((string)$b) as $w) if (!isset($keep[$w]) && !in_array($w, ['this', 'that', 'with', 'from', 'your', 'have', 'will', 'tell'], true)) $out[$w] = ($out[$w] ?? 0) + 1;
    arsort($out); return array_slice(array_keys($out), 0, 12);
}
/** Safe wordings (the pattern of the messages Meta kept: an order, a delivery, a request, the team) used when the AI cannot answer; [body, buttons] or null. */
function wa_util_learn_fallback(string $kind, array $usedBodies, int $n): ?array
{
    $bank = ['open' => [
        ["Hi {{1}}, this is TM Perfume House customer care. If you need help with an order or a delivery from TM Perfume House, tap a button below.", ['Order help', 'Not now']],
        ["Hello {{1}}, you are a TM Perfume House customer, so this line is open for your order and delivery questions. Tap a button to reach our team.", ['Delivery help', 'Not now']],
        ["Hi {{1}}, if you need to track, change or ask about an order placed with TM Perfume House, tap a button and our team will reply.", ['Track my order', 'Change my order']],
        ["Hello {{1}}, TM Perfume House customer care can help with the status of an order or a return. Tap a button to message our team.", ['Order status', 'Not now']]],
        'order' => [
        ["Hi {{1}}, a quick check on your order {{2}} placed on {{3}}: did it reach you in good condition? Tap a button to tell our team.", ['Yes, received', 'I need help']],
        ["Hello {{1}}, this is TM Perfume House customer care about your order {{2}} of {{3}}. If anything is missing or damaged, tap a button and we will fix it.", ['All good', 'Report a problem']]]];
    $used = array_map(fn($b) => preg_replace('/\s+/', ' ', trim((string)$b)), $usedBodies); $list = $bank[$kind] ?? []; if (!$list) return null;
    for ($i = 0; $i < count($list); $i++) { $c = $list[($n + $i) % count($list)]; if (!in_array($c[0], $used, true)) return $c; }
    return null;
}
/** 🧠 owner, 18 Nov 2026 (night): a standard follow-up that Meta MOVED to marketing gets a NEW UTILITY replacement, written in the pattern of the ones Meta KEPT as utility (the AI sees both groups and the words only the moved ones use; if it cannot answer, a safe wording from the bank is used).
 *  At most 2 replacements per message, 2 made per round, 16 a day; the new one goes to Meta on every account. Switch off: setting wa_ru_learn = 0. Returns ['made' => [keys], 'note' => ''] */
function wa_util_pool_learn(PDO $pdo, int $by = 0): array
{
    $res = ['made' => [], 'note' => ''];
    if ((string)setting_get($pdo, 'wa_ru_learn', '1') === '0') { $res['note'] = 'off'; return $res; }
    if ((int)setting_get($pdo, 'wa_ru_learn_block', '0') > time()) { $res['note'] = 'Meta weekly limit for new utility messages'; return $res; }
    try { $lim = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba w JOIN wa_templates t ON t.id = w.tpl_id WHERE t.extra LIKE '%util_learned%' AND t.created_at >= ? AND w.status = 'ERROR' AND w.reason LIKE '%weekly limit%'"); $lim->execute([gmdate('Y-m-d H:i:s', time() + 19800 - 43200)]);
        if ((int)$lim->fetchColumn() > 0) { setting_set($pdo, 'wa_ru_learn_block', (string)(time() + 12 * 3600)); if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'Utility follow-up learn paused for 12 hours: Meta says the business reached the weekly limit for creating utility messages'); $res['note'] = 'Meta weekly limit'; return $res; } } catch (Throwable $e) { }   // no more new messages while Meta refuses them (the limit renews weekly)
    try { $got = (int)$pdo->query("SELECT GET_LOCK('wa_ru_learn', 0)")->fetchColumn(); } catch (Throwable $e) { $got = 0; } if ($got !== 1) { $res['note'] = 'busy'; return $res; }
    try {
        if ((int)setting_get($pdo, 'wa_ru_learn_done', '0') > time() - 540) { $res['note'] = 'recent'; try { $pdo->query("SELECT RELEASE_LOCK('wa_ru_learn')"); } catch (Throwable $e) { } return $res; }   // two ticks at once must not both run a round
        setting_set($pdo, 'wa_ru_learn_done', (string)time());
        $appr = []; foreach ($pdo->query("SELECT tpl_id, SUM(status = 'APPROVED') ok FROM wa_tpl_waba GROUP BY tpl_id")->fetchAll() as $w) $appr[(int)$w['tpl_id']] = (int)$w['ok'];
        $G = []; $keptBodies = []; $movedBodies = []; $allBodies = [];
        foreach ($pdo->query("SELECT * FROM wa_templates WHERE extra LIKE '%util_pool%' ORDER BY id")->fetchAll() as $r) {
            $x = json_decode((string)($r['extra'] ?? ''), true); if (!is_array($x) || empty($x['util_pool'])) continue; $k = (string)$x['util_pool']; $base = (string)($x['util_base'] ?? preg_replace('/_r\d+$/', '', $k)); $try = (int)($x['util_try'] ?? 0); $kind = (string)($x['util_kind'] ?? 'open');
            $moved = !empty($r['relabelled_at']) || strtoupper((string)$r['category']) === 'MARKETING'; if (!$moved && $kind === 'open' && !empty($x['util_learned']) && wa_util_claims_history((string)$r['body'])) $moved = true;   // it claims a recent enquiry / order: treated as a failed try, the next one is written without it
            $allBodies[] = (string)$r['body'];
            if ($moved) $movedBodies[] = (string)$r['body']; elseif (($appr[(int)$r['id']] ?? 0) > 0) $keptBodies[] = (string)$r['body'];
            $g = &$G[$base]; if (!isset($g)) $g = ['kind' => $kind, 'kept' => 0, 'moved' => 0, 'try' => 0, 'row' => $r, 'keys' => []]; $g['keys'][$k] = 1; $g['try'] = max($g['try'], $try);
            if ($moved) $g['moved']++; else $g['kept']++; unset($g);
        }
        if (!$keptBodies) { $res['note'] = 'no kept utility message to learn from yet'; return $res; }
        $day = date('Y-m-d'); $cnt = json_decode((string)setting_get($pdo, 'wa_ru_learn_day', ''), true); $cnt = (is_array($cnt) && ($cnt['d'] ?? '') === $day) ? (int)$cnt['n'] : 0;
        $avoid = wa_util_learn_avoid($keptBodies, $movedBodies); $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 6 * max(1, count($wabas))]; $round = 0;
        foreach ($G as $base => $g) {
            if ($g['kept'] > 0 || $g['moved'] < 1 || $g['try'] >= 2 || $round >= 2 || $cnt >= 16) continue;
            $r = $g['row']; $kind = $g['kind']; $newKey = $base . '_r' . ($g['try'] + 1); $ex = json_decode((string)($r['examples'] ?? ''), true); $ex = is_array($ex) ? $ex : []; $labels = array_map('strval', (array)($ex['labels'] ?? [])); $exv = array_map('strval', (array)($ex['body'] ?? [])); $nv = count($labels);
            $body = ''; $buttons = [];
            if (function_exists('wa_tpl_model')) {
                $sys = "You write ONE WhatsApp UTILITY message for TM Perfume House (a perfume shop in Surat, India). Meta decides the category: it KEPT the messages listed as KEPT as utility (clearly about the customer's own order, delivery, purchase, request or customer care) and MOVED the ones listed as MOVED to marketing (soft relationship / welcome / invitation wording). Write a NEW message with the same purpose and exactly the same variables ({{1}}" . ($nv > 1 ? ' … {{' . $nv . '}}' : '') . ") in the same order as the MOVED one, built like the KEPT ones: name the order, delivery, purchase or customer-care reason openly, a plain helpful tone, no offer, no price, no emoji, no capital shouting, under 300 characters, never start or end with a variable. IMPORTANT: the message also goes to customers who never enquired or ordered, so it must NEVER claim that the customer asked, enquired, ordered, bought or received anything, or that you hold their details, and never say 'recent' (only a message that has an order number {{2}} may name the order). Say only that TM Perfume House customer care is here to help with orders, deliveries and questions. Do not use these words: " . implode(', ', $avoid) . ". Give two short buttons (max 20 characters each).";
                $ctx = "KEPT as utility:\n- " . implode("\n- ", array_slice($keptBodies, 0, 8)) . "\n\nMOVED to marketing:\n- " . implode("\n- ", array_slice($movedBodies, 0, 6)) . "\n\nThe message to replace (kind " . $kind . "): " . (string)$r['body'];
                $sch = ['type' => 'OBJECT', 'properties' => ['body' => ['type' => 'STRING'], 'button1' => ['type' => 'STRING'], 'button2' => ['type' => 'STRING']], 'required' => ['body', 'button1', 'button2']];
                try { [$ok, $v] = wa_tpl_model($sys, $ctx, $sch, 1200); } catch (Throwable $e) { $ok = false; $v = null; }
                if ($ok && is_array($v)) { $b = trim((string)($v['body'] ?? '')); $bad = false; foreach ($avoid as $w) if (stripos($b, $w) !== false) $bad = true;
                    if ($kind === 'open' && wa_util_claims_history($b)) $bad = true;
                    if ($b !== '' && !$bad && mb_strlen($b) <= 400 && count(wa_tpl_vars($b)) === $nv && !in_array(preg_replace('/\s+/', ' ', $b), array_map(fn($z) => preg_replace('/\s+/', ' ', trim($z)), $allBodies), true)) { $body = $b; $buttons = [mb_substr(trim((string)$v['button1']), 0, 20), mb_substr(trim((string)$v['button2']), 0, 20)]; } }
            }
            if ($body === '') { $fb = wa_util_learn_fallback($kind, $allBodies, $g['try']); if (!$fb || count(wa_tpl_vars($fb[0])) !== $nv) continue; $body = $fb[0]; $buttons = $fb[1]; }
            [$t, $e] = wa_tpl_clean(['name' => 'tmph_fu_' . $newKey, 'language' => 'en', 'category' => 'UTILITY', 'body' => $body, 'footer' => '', 'buttons' => array_map(fn($b0) => ['type' => 'QUICK_REPLY', 'text' => $b0], array_filter($buttons, fn($b0) => $b0 !== '')), 'examples' => ['body' => $exv, 'labels' => $labels], 'folder' => 'utility']);
            if ($e !== '') { if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'Utility follow-up learned: ' . $newKey . ' not made: ' . $e); continue; }
            $t['extra'] = ['util_pool' => $newKey, 'util_kind' => $kind, 'util_base' => $base, 'util_try' => $g['try'] + 1, 'util_learned' => date('Y-m-d H:i:s')];
            $id = wa_tpl_insert($pdo, $t, $by, 'Utility follow-up (learned): ' . $kind, 'Replacement of ' . $base . ' (Meta moved it to marketing), written like the messages Meta kept as utility'); $row = wa_tpl_row($pdo, $id);
            foreach ($wabas as $w) wa_tpl_try_create($pdo, $row, (string)$w, $budget);
            $res['made'][] = $newKey; $allBodies[] = $body; $round++; $cnt++;   // the next one of this round must not get the same wording
             setting_set($pdo, 'wa_ru_learn_day', json_encode(['d' => $day, 'n' => $cnt]));
            if (function_exists('wa_log')) wa_log($pdo, 'template', true, 'Utility follow-up learned: ' . $newKey . ' made and sent to Meta (Meta moved ' . $base . ' to marketing; the new one follows the pattern of the messages Meta kept as utility)');
        }
    } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'template', false, 'Utility follow-up learn error: ' . mb_substr($e->getMessage(), 0, 140)); }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_ru_learn')"); } catch (Throwable $e) { }
    return $res;
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
function wa_util_pool_usable(PDO $pdo, bool $behaviour = false): array
{
    $o = []; $__blk = (string)setting_get($pdo, 'wa_block_dull', '1') !== '0'; if ($__blk && !function_exists('wa_rs_dull') && is_file(__DIR__ . '/wa_restyle.php')) { try { require_once __DIR__ . '/wa_restyle.php'; } catch (Throwable $e) { } }
    foreach (wa_util_pool_rows($pdo) as $k => $r) { if (!empty($r['relabelled_at']) || strtoupper((string)($r['category_now'] ?? '')) === 'MARKETING') continue; if ($__blk && function_exists('wa_rs_dull') && wa_rs_dull((string)($r['body'] ?? ''))) continue;   /* a basic message is not picked (owner 25 Nov) */ $claims = ($r['pool_kind'] ?? '') === 'open' && strpos((string)($r['extra'] ?? ''), 'util_learned') !== false && wa_util_claims_history((string)$r['body']); if ($claims && !$behaviour) continue;   // a self-written message that claims a recent enquiry / order is only for Module B, which sends it to customers whose behaviour makes it true
        $t = wa_camp_tpl($pdo, (int)$r['id'], true, 0); if (!$t) continue; if ($claims) { $t['claims'] = true; $t['body'] = (string)$r['body']; }
        $x = json_decode((string)($r['examples'] ?? ''), true); $t['labels'] = is_array($x) ? array_map('strval', (array)($x['labels'] ?? [])) : []; $t['pool_kind'] = $r['pool_kind']; $o[] = $t; }
    return $o;
}
/** 💬 Last resort for Module B (owner, 17 Nov 2026: "if utility is not possible, marketing goes"): the welcome message that Meta moved to MARKETING is still approved and still sendable — it goes to the failed / held people no utility message fits.
 *  Only the 'open' (name-only) pool kind; the other pool messages are never sent once they are relabelled. */
function wa_util_pool_fallback(PDO $pdo): array
{
    $o = []; foreach (wa_util_pool_rows($pdo) as $k => $r) { if (($r['pool_kind'] ?? '') !== 'open') continue; if (strpos((string)($r['extra'] ?? ''), 'util_learned') !== false && wa_util_claims_history((string)$r['body'])) continue; if (empty($r['relabelled_at']) && strtoupper((string)($r['category_now'] ?? '')) !== 'MARKETING') continue;
        $t = wa_camp_tpl($pdo, (int)$r['id'], true, 0); if (!$t) continue; $x = json_decode((string)($r['examples'] ?? ''), true); $t['labels'] = is_array($x) ? array_map('strval', (array)($x['labels'] ?? [])) : []; $t['pool_kind'] = 'open'; $t['as_marketing'] = true; $o[] = $t; }
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
function wa_util_pick(PDO $pdo, array $pool, array $ct, int $ordDays, int $nowTs, int $rot = 0, int $askDays = 30, bool $anyone = false): ?array
{
    if (!$pool || (int)($ct['optout'] ?? 0) === 1) return null; $phone = (string)$ct['phone']; $name = function_exists('wa_first_name') ? wa_first_name((string)($ct['name'] ?? '')) : 'ji';
    $ask = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'ask')); $ord = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'order'));
    $in = !empty($ct['last_in_at']) ? (int)strtotime((string)$ct['last_in_at']) : 0; $fill = function (array $t, array $v) { $o = []; foreach (array_slice($t['labels'], 0, $t['nvars']) as $l) { $x = trim((string)($v[strtolower((string)$l)] ?? '')); if ($x === '') return null; $o[] = mb_substr($x, 0, 60); } return count($o) === (int)$t['nvars'] ? $o : null; };
    $hasEnq = $in >= $nowTs - max(1, $askDays) * 86400; $hasOrd = false; if ($ordDays > 0) { $oo = wa_util_orders($pdo, $ordDays)[$phone] ?? null; $hasOrd = $oo && (string)$oo['order'] !== ''; }
    if ($hasEnq || $hasOrd) { $cl = array_values(array_filter($pool, fn($t) => !empty($t['claims']) && wa_util_claim_ok((string)($t['body'] ?? ''), $hasEnq, $hasOrd)));   // 🎯 half of the people who wrote to us / ordered get a message that says so (true for them)
        if ($cl && $rot % 2 === 0) { $t = $cl[intdiv($rot, 2) % count($cl)]; $v = $fill($t, ['name' => $name]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'open']; } }
    if ($ask && $in >= $nowTs - max(1, $askDays) * 86400) {
        $p = ''; try { if (function_exists('wa_camp_lazy') ? wa_camp_lazy('wa_prod_last_asked', 'wa_prod.php') : function_exists('wa_prod_last_asked')) $p = (string)wa_prod_last_asked($pdo, $phone); } catch (Throwable $e) { }
        if ($p !== '') { $t = $ask[$rot % count($ask)]; $v = $fill($t, ['name' => $name, 'perfume' => $p, 'date' => date('j M', $in)]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'ask']; }
        else {   // no perfume on record for this customer: the message that only says they contacted us on that date (true for every customer who wrote to us)
            $gen = array_values(array_filter($ask, fn($t) => !in_array('perfume', (array)($t['labels'] ?? []), true)));
            if ($gen) { $t = $gen[$rot % count($gen)]; $v = $fill($t, ['name' => $name, 'date' => date('j M', $in)]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'ask']; }
        }
    }
    if ($ord && $ordDays > 0) { $o = wa_util_orders($pdo, $ordDays)[$phone] ?? null;
        if ($o && $o['order'] !== '') { $t = $ord[$rot % count($ord)]; $v = $fill($t, ['name' => $name, 'order' => $o['order'], 'date' => date('j M', (int)strtotime($o['at']))]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'order']; } }
    if ($anyone) {   // 💸 utility first (owner, 17 Nov 2026): a customer with no enquiry / order on record (an old customer, an imported list) still gets the short welcome message
        $open = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'open' && empty($t['claims'])));
        if ($open) { $t = $open[$rot % count($open)]; $v = $fill($t, ['name' => $name]); if ($v) return ['t' => $t, 'vars' => $v, 'kind' => 'open']; }
    }
    return null;
}
/** For the preview: how many of these people qualify (enquiry in 30 days / order in the window) — counted cheaply, the perfume is checked when sending. */
function wa_util_split(PDO $pdo, array $phones, int $ordDays, int $askDays = 30): array
{
    $o = ['ask' => 0, 'order' => 0, 'none' => 0]; $ord = wa_util_orders($pdo, max(1, $ordDays)); $cut = date('Y-m-d H:i:s', time() - max(1, $askDays) * 86400);
    foreach (array_chunk($phones, 500) as $ch) { if (!$ch) continue; $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") AND optout = 0 AND last_in_at >= ?"); $q->execute(array_merge($ch, [$cut])); $a = array_flip(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($ch as $p) { if (isset($a[(string)$p])) $o['ask']++; elseif (isset($ord[(string)$p])) $o['order']++; else $o['none']++; } }
    return $o;
}
/** 🎁 The offer goes FREE after a reply: a customer who answers a utility follow-up (sent in the last 48 h) gets the campaign's offer text once, as a normal chat message. */
function wa_util_offer_reply(PDO $pdo, string $key, string $text = ''): void
{
    try {
        if ($text !== '' && preg_match('/^\s*(not now|no\b|nope|stop|unsubscribe|don.?t|nahi|nahin|नहीं|ના)/iu', $text)) return;   // "Not now" / "No" / "Stop": no offer
        $q = $pdo->prepare("SELECT i.id AS iid, i.name AS iname, i.camp_id, c.extra, c.tpl_id, c.media_lib_id, c.vars FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.phone = ? AND i.variant = 'U' AND i.status = 'sent' AND i.sent_at >= ? ORDER BY i.id DESC LIMIT 1"); $q->execute([$key, date('Y-m-d H:i:s', time() - 48 * 3600)]); $r = $q->fetch(); if (!$r) return;
        $x = json_decode((string)$r['extra'], true); $x = is_array($x) ? $x : []; $isUtilC = ($x['kind'] ?? '') === 'util'; $offer = trim((string)($x['offer'] ?? ''));
        if ($isUtilC && $offer === '') return;
        if (!$isUtilC && (string)setting_get($pdo, 'wa_u_first', '1') === '0') return;   // utility first is switched off: nothing is sent by itself
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_util_offer (camp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, at DATETIME NULL, PRIMARY KEY (camp_id, phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ct = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$ct || (int)($ct['optout'] ?? 0) === 1 || !function_exists('wa_send_text')) return;
        if (!$isUtilC) {   // 🎁 utility first (owner, 17 Nov 2026): the customer answered the utility message → the campaign's OWN marketing message goes now as a free chat message (the 24-hour window is open)
            $dl = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND mtype = 'text' AND ts >= ?"); $dl->execute([$key, date('Y-m-d H:i:s', time() - 24 * 3600)]); if ((int)$dl->fetchColumn() > 0) return;   // an offer already went in the last day
            if (!function_exists('wa_camp_tpl') || !function_exists('wa_camp_vars_norm') || !function_exists('wa_camp_resolve_vars') || !function_exists('wa_tpl_shown')) return;
            $t = wa_camp_tpl($pdo, (int)$r['tpl_id'], true, (int)$r['media_lib_id']); if (!$t) return;
            $specs = wa_camp_vars_norm(json_decode((string)$r['vars'], true), (int)$t['nvars']); $used = []; $vars = wa_camp_resolve_vars($pdo, (int)$t['nvars'], $specs, $ct, (string)($r['iname'] ?: ($ct['name'] ?? '')), (int)$r['iid'], $used);
            $offer = wa_tpl_shown($t, $vars); if (trim($offer) === '') return;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_util_offer (camp_id, phone, at) VALUES (?, ?, ?)"); $ins->execute([(int)$r['camp_id'], $key, date('Y-m-d H:i:s')]); if ($ins->rowCount() === 0) return;   // once per person per campaign
        $name = function_exists('wa_first_name') ? wa_first_name((string)($ct['name'] ?? '')) : ''; wa_send_text($pdo, $ct, str_replace(['{name}', '{{name}}'], $name, $offer), 'campaign', 0);
    } catch (Throwable $e) { error_log('wa_util_offer_reply: ' . $e->getMessage()); }
}
/** The utility messages a campaign can use: this message's family first, then any approved utility message. */
function wa_util_list(PDO $pdo, int $tplId, bool $onlyMine = false): array
{
    $m = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, $tplId) : null; $fam = $m ? (int)($m['family_id'] ?: $m['id']) : 0; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
    $o = []; $famQ = $fam > 0 ? $fam : -1;
    // this message's own utility versions (any state) + EVERY approved utility message of every other marketing message (they can be used for any campaign): the newest 1,200
    $sql = "SELECT t.* FROM wa_templates t WHERE t.category = 'UTILITY' AND (t.family_id = ?" . ($onlyMine ? ")" : " OR (t.relabelled_at IS NULL AND EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED'))) ") . " ORDER BY (t.family_id = ?) DESC, t.id DESC LIMIT 1200";
    $q = $pdo->prepare($sql); $q->execute([$famQ, $famQ]); $rows = $q->fetchAll(); $srcIds = [];
    foreach ($rows as $r) { $x = json_decode((string)($r['extra'] ?? ''), true); if (is_array($x) && !empty($x['utility_for'])) $srcIds[(int)$x['utility_for']] = 1; }
    $srcName = []; if ($srcIds) { try { foreach ($pdo->query("SELECT id, name FROM wa_templates WHERE id IN (" . implode(',', array_map('intval', array_keys($srcIds))) . ")")->fetchAll() as $z) $srcName[(int)$z['id']] = (string)$z['name']; } catch (Throwable $e) { } }
    foreach ($rows as $r) {
        $mine = $fam > 0 && (int)($r['family_id'] ?? 0) === $fam;
        if ($mine) { $st = function_exists('wa_tpl_status') ? wa_tpl_status($pdo, (int)$r['id'], $wabas) : ['status' => '?']; $status = is_array($st) ? (string)($st['status'] ?? '?') : (string)$st; } else $status = 'approved';
        $ex = json_decode((string)($r['examples'] ?? ''), true); $lab = is_array($ex) ? array_map('strval', (array)($ex['labels'] ?? [])) : []; preg_match_all('/\{\{(\d+)\}\}/', (string)$r['body'], $vm);
        $vars = []; foreach (array_unique(array_map('intval', $vm[1])) as $vi) $vars[] = ['n' => $vi, 'what' => ['name' => 'customer name', 'perfume' => 'perfume they asked about / ordered', 'date' => 'date they asked / ordered'][$lab[$vi - 1] ?? ''] ?? ($lab[$vi - 1] ?? '')];
        $per = []; if ($mine) { $ws = []; $q2 = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q2->execute([(int)$r['id']]); foreach ($q2->fetchAll() as $w) $ws[(string)$w['waba_id']] = strtolower((string)$w['status']);
            foreach (wa_util_numbers($pdo) as $n) $per[] = ['label' => $n['label'], 'status' => $ws[$n['waba']] ?? 'not sent']; }
        $draft = $mine && !array_filter($per, fn($p) => $p['status'] !== 'not sent');
        $xx = json_decode((string)($r['extra'] ?? ''), true); $srcId = is_array($xx) ? (int)($xx['utility_for'] ?? 0) : 0;
        $o[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'body' => (string)$r['body'], 'status' => $draft ? 'draft' : $status, 'draft' => $draft, 'relabelled' => strtoupper((string)($r['category_now'] ?? '')) === 'MARKETING' || !empty($r['relabelled_at']), 'mine' => $mine, 'vars' => $vars, 'per' => $per, 'labels' => $lab, 'source' => $srcName[$srcId] ?? '', 'at' => (string)($r['created_at'] ?? '')];
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
    if ($__wut === 'wa.tpl_utility_list') json_response(['ok' => true, 'items' => wa_util_list($pdo, (int)($_GET['tpl_id'] ?? 0), !empty($_GET['mine']))]);
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
