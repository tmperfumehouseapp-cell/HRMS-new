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
/** 💬 Last resort for Module B (owner, 17 Nov 2026: "if utility is not possible, marketing goes"): the welcome message that Meta moved to MARKETING is still approved and still sendable — it goes to the failed / held people no utility message fits.
 *  Only the 'open' (name-only) pool kind; the other pool messages are never sent once they are relabelled. */
function wa_util_pool_fallback(PDO $pdo): array
{
    $o = []; foreach (wa_util_pool_rows($pdo) as $k => $r) { if (($r['pool_kind'] ?? '') !== 'open') continue; if (empty($r['relabelled_at']) && strtoupper((string)($r['category_now'] ?? '')) !== 'MARKETING') continue;
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
        $open = array_values(array_filter($pool, fn($t) => $t['pool_kind'] === 'open'));
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
