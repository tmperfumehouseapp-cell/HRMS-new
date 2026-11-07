<?php
/**
 * wa_greet.php — Greetings: the decided first answer to "hi / good morning / namaste", per kind of customer, plus the fixed replies that follow.
 *
 *   greetings     rows of [customer says (hi | morning | afternoon | evening | namaste)] × [who (everyone | new | bought before | not ordered yet |
 *                 campaign customer | from a Meta ad)] → the message. The most specific row wins (wa_greet_pick). Used by wag_greet_quick (wa_growth.php):
 *                 a plain greeting gets this answer at once, no AI.
 *   fixed replies short answers for what customers ask right after the greeting (price, COD, delivery …): saved as Quick replies (wa_quick, by title)
 *                 so they are one tap in every chat.
 *   Claude        "✨ Write it" asks the CUSTOMER shelf of the one brain (selling rules, prices, limits, tone) to write a greeting / reply from one line.
 *   Teach sync    every save rewrites the "[Greetings]" lines of the Selling rules (lib_facts 'style', the Teach board's saved rules) and calls kb_touch,
 *                 so the bot, the suggestions and the Teach screen all know the same greetings.
 * Actions: wa.greet_get · wa.greet_save · wa.greet_ai. Loaded by wa_more.php (before wa_seg.php). PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wa_greet_cfg')) {

function wa_greet_says(): array { return ['hi' => 'Hi / Hello', 'morning' => 'Good morning', 'afternoon' => 'Good afternoon', 'evening' => 'Good evening / night', 'namaste' => 'Namaste / Jai Shri Krishna']; }
function wa_greet_whos(): array { return ['any' => 'Everyone', 'new' => 'New customer (first message ever)', 'bought' => 'Bought before', 'not_ordered' => 'Chatted, never ordered', 'campaign' => 'Campaign customer', 'from_ad' => 'Came from a Meta ad']; }
function wa_greet_default(): array
{
    return ['rows' => [
        ['say' => 'hi', 'who' => 'any', 'text' => "Hi {name}! 🙏 Welcome to TM Perfume House. Which fragrance are you looking for?"],
        ['say' => 'hi', 'who' => 'bought', 'text' => "Hi {name}! 🙏 So good to see you again. Would you like to reorder your last perfume or try something new?"],
        ['say' => 'hi', 'who' => 'campaign', 'text' => "Hi {name}! 🙏 Thanks for replying. Tell me which perfume caught your eye and I'll share the details."],
        ['say' => 'morning', 'who' => 'any', 'text' => "Good morning {name}! ☀️ Welcome to TM Perfume House. How can I help you today?"],
        ['say' => 'evening', 'who' => 'any', 'text' => "Good evening {name}! 🌙 Welcome to TM Perfume House. How can I help you today?"],
        ['say' => 'namaste', 'who' => 'any', 'text' => "Namaste {name}! 🙏 Welcome to TM Perfume House. Which fragrance would you like to see?"],
    ], 'replies' => [], 'at' => '', 'by' => ''];
}
function wa_greet_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wa_greet', ''), true);
    if (!is_array($j) || !isset($j['rows'])) return wa_greet_default();
    return ['rows' => array_values((array)$j['rows']), 'replies' => array_values((array)($j['replies'] ?? [])), 'at' => (string)($j['at'] ?? ''), 'by' => (string)($j['by'] ?? '')];
}
function wa_greet_clean(array $in): array
{
    $S = wa_greet_says(); $W = wa_greet_whos(); $rows = []; $seen = [];
    foreach (array_slice((array)($in['rows'] ?? []), 0, 40) as $r) {
        $say = (string)($r['say'] ?? 'hi'); $who = (string)($r['who'] ?? 'any'); $t = trim(mb_substr((string)($r['text'] ?? ''), 0, 1000));
        if (!isset($S[$say]) || !isset($W[$who]) || $t === '' || isset($seen[$say . '|' . $who])) continue; $seen[$say . '|' . $who] = true;
        $rows[] = ['say' => $say, 'who' => $who, 'text' => $t];
    }
    $reps = []; $seenT = [];
    foreach (array_slice((array)($in['replies'] ?? []), 0, 30) as $r) {
        $t = trim(mb_substr((string)($r['title'] ?? ''), 0, 60)); $b = trim(mb_substr((string)($r['body'] ?? ''), 0, 1500)); $w = trim(mb_substr((string)($r['words'] ?? ''), 0, 200));
        if ($t === '' || $b === '' || isset($seenT[mb_strtolower($t)])) continue; $seenT[mb_strtolower($t)] = true;
        $reps[] = ['title' => $t, 'words' => $w, 'body' => $b];
    }
    return ['rows' => $rows, 'replies' => $reps];
}
/** Which greeting the customer wrote: morning / afternoon / evening / namaste / hi. */
function wa_greet_kind(string $t): string
{
    if (preg_match('/morning|\bgm\b|suprabhat/i', $t)) return 'morning';
    if (preg_match('/afternoon/i', $t)) return 'afternoon';
    if (preg_match('/evening|night|\bgn\b/i', $t)) return 'evening';
    if (preg_match('/namaste|namaskar|jsk|jai\s*shri|jay\s*shree|ram\s*ram|kem\s*cho/i', $t)) return 'namaste';
    return 'hi';
}
/** The groups this customer belongs to (for the "who" column). */
function wa_greet_groups(PDO $pdo, array $c): array
{
    $ph = (string)($c['phone'] ?? ''); $g = ['any' => true]; $orders = 0; $ins = 0;
    try { $q = $pdo->prepare("SELECT orders FROM wa_cust WHERE phone = ?"); $q->execute([$ph]); $orders = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'in'"); $q->execute([$ph]); $ins = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    if ($orders >= 1) $g['bought'] = true; elseif ($ins <= 1) $g['new'] = true; else $g['not_ordered'] = true;
    try { $q = $pdo->prepare("SELECT 1 FROM wa_ctags WHERE phone = ? AND tag = 'campaign' LIMIT 1"); $q->execute([$ph]); if ($q->fetchColumn()) $g['campaign'] = true; } catch (Throwable $e) { }
    if ((string)($c['source'] ?? '') === 'query') $g['from_ad'] = true;
    return $g;
}
/** The best saved greeting for this message and customer, or null. Specific customer group beats "everyone"; the exact greeting beats "hi". */
function wa_greet_pick(PDO $pdo, string $text, array $c): ?string
{
    $rows = wa_greet_cfg($pdo)['rows']; if (!$rows) return null;
    $kind = wa_greet_kind($text); $grp = wa_greet_groups($pdo, $c); $rank = ['campaign' => 5, 'from_ad' => 4, 'bought' => 3, 'new' => 3, 'not_ordered' => 2, 'any' => 0];
    $best = null; $bs = -1;
    foreach ($rows as $r) {
        if (!isset($grp[$r['who']])) continue; if ($r['say'] !== $kind && $r['say'] !== 'hi') continue;
        $s = ($rank[$r['who']] ?? 0) * 2 + ($r['say'] === $kind ? 1 : 0); if ($s > $bs) { $bs = $s; $best = $r; }
    }
    return $best ? (string)$best['text'] : null;
}
/** Writes the greetings + fixed replies into the Selling rules ("[Greetings]" lines, replaced every save) and tells every screen the knowledge changed. */
function wa_greet_teach_sync(PDO $pdo, array $cfg): int
{
    if (!function_exists('teach_facts_get') || !function_exists('teach_facts_set')) return 0;
    $S = wa_greet_says(); $W = wa_greet_whos(); $cur = (string)(teach_facts_get($pdo)['style'] ?? '');
    try { $q = $pdo->query("SELECT body FROM lib_facts WHERE k = 'style'"); $v = $q->fetchColumn(); if ($v !== false) $cur = (string)$v; } catch (Throwable $e) { }
    $keep = []; foreach (preg_split('/\R/u', $cur) as $ln) if (!preg_match('/^\s*\d+[.)]\s*\[Greetings\]/u', $ln)) $keep[] = $ln;
    $cur = trim(implode("\n", $keep)); $n = 0; if (preg_match_all('/^\s*(\d+)[.)]/m', $cur, $m)) $n = max(array_map('intval', $m[1]));
    $lines = [];
    foreach ($cfg['rows'] as $r) $lines[] = (++$n) . '. [Greetings] When ' . ($r['who'] === 'any' ? 'a customer' : 'a customer who is "' . $W[$r['who']] . '"') . ' only greets us with "' . $S[$r['say']] . '", answer exactly: ' . str_replace(["\r", "\n"], ' ', $r['text']);
    foreach ($cfg['replies'] as $r) $lines[] = (++$n) . '. [Greetings] Fixed reply "' . $r['title'] . '"' . ($r['words'] !== '' ? ' (when the customer asks: ' . $r['words'] . ')' : '') . ': ' . str_replace(["\r", "\n"], ' ', $r['body']);
    teach_facts_set($pdo, 'style', mb_substr(($cur !== '' ? $cur . "\n" : '') . implode("\n", $lines), 0, 20000));
    if (function_exists('kb_touch')) { try { kb_touch($pdo, 'Greetings'); } catch (Throwable $e) { } }
    return count($lines);
}
/** Fixed replies → Quick replies (same title = updated, never deletes the owner's other quick replies). */
function wa_greet_quick_sync(PDO $pdo, array $replies): int
{
    $n = 0; $have = []; foreach ($pdo->query("SELECT id, title FROM wa_quick")->fetchAll() as $r) $have[mb_strtolower(trim((string)$r['title']))] = (int)$r['id'];
    $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM wa_quick")->fetchColumn();
    foreach ($replies as $r) {
        $k = mb_strtolower($r['title']);
        if (isset($have[$k])) $pdo->prepare("UPDATE wa_quick SET body = ? WHERE id = ?")->execute([$r['body'], $have[$k]]);
        else $pdo->prepare("INSERT INTO wa_quick (title, body, sort_order) VALUES (?, ?, ?)")->execute([$r['title'], $r['body'], ++$so]);
        $n++;
    }
    return $n;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router
$__wga = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wga, ['wa.greet_get', 'wa.greet_save', 'wa.greet_ai'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    $adm = function_exists('wa_is_admin') && wa_is_admin($pdo, $auth);
    if ($__wga === 'wa.greet_get') {
        $q = []; try { foreach ($pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id")->fetchAll() as $r) $q[] = ['title' => (string)$r['title'], 'body' => (string)$r['body']]; } catch (Throwable $e) { }
        json_response(['cfg' => wa_greet_cfg($pdo), 'says' => wa_greet_says(), 'whos' => wa_greet_whos(), 'quick' => $q, 'can_edit' => $adm, 'ai' => function_exists('brain_ai_text'), 'origin' => function_exists('wa_origin_cfg') ? wa_origin_cfg($pdo) : null]);
    }
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can change the greetings.'], 403);
    $in = json_input();
    if ($__wga === 'wa.greet_save') {
        $c = wa_greet_clean($in); if (!$c['rows']) json_response(['error' => 'Keep at least one greeting.'], 422);
        $nm = function_exists('employee_names_by_id') ? (employee_names_by_id($pdo)[(int)($auth['employee_id'] ?? 0)] ?? '') : '';
        $c['at'] = date('Y-m-d H:i:s'); $c['by'] = (string)$nm; setting_set($pdo, 'wa_greet', json_encode($c, JSON_UNESCAPED_UNICODE));
        $qn = wa_greet_quick_sync($pdo, $c['replies']); $tn = wa_greet_teach_sync($pdo, $c);
        try { $pdo->exec("DELETE FROM wa_sugg"); } catch (Throwable $e) { }   // pre-written replies are written again with the new greetings
        json_response(['ok' => true, 'cfg' => $c, 'quick' => $qn, 'teach_lines' => $tn]);
    }
    if ($__wga === 'wa.greet_ai') {
        $ask = trim(mb_substr((string)($in['ask'] ?? ''), 0, 500)); $kind = (string)($in['kind'] ?? 'greeting');
        $S = wa_greet_says(); $W = wa_greet_whos(); $say = $S[(string)($in['say'] ?? 'hi')] ?? 'Hi'; $who = $W[(string)($in['who'] ?? 'any')] ?? 'Everyone';
        $sys = "You write ONE WhatsApp message for TM Perfume House (Surat, India), following the owner's selling rules, prices and tone below exactly. Use {name} where the customer's first name goes. "
             . "Short (1-3 lines), warm, Hinglish or English like the owner's rules, at most 2 emoji, end with one easy question. Never promise a price, discount or stock the rules do not give. Reply with the message text only.";
        $user = $kind === 'reply' ? "Write the fixed reply titled \"" . mb_substr((string)($in['title'] ?? ''), 0, 60) . "\" for when the customer asks: " . mb_substr((string)($in['words'] ?? ''), 0, 200) . ".\nOwner's wish: " . ($ask !== '' ? $ask : '(none)')
                                  : "Write the greeting for when a customer only says \"" . $say . "\". This customer is: " . $who . ".\nOwner's wish: " . ($ask !== '' ? $ask : '(none)');
        if (!empty($in['current'])) $user .= "\nCurrent text (improve it): " . mb_substr((string)$in['current'], 0, 800);
        $t = function_exists('brain_ai_text') ? brain_ai_text('customer', 'whatsapp', $sys, $user, 400) : '';
        if ($t === '') json_response(['error' => 'Claude could not write it right now' . (!empty($GLOBALS['brain_last_error']) ? ' (' . $GLOBALS['brain_last_error'] . ')' : '') . '.'], 502);
        json_response(['ok' => true, 'text' => trim($t, " \t\n\r\"")]);
    }
}
