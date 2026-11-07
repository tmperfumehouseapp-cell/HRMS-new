<?php
/**
 * wa_origin.php — 📍 "Where did you find us?" asked automatically when a new enquiry starts.
 *
 *   When   a NEW enquiry (first message ever, or the first one after 24 hours of silence) and we do NOT already know where the customer came
 *          from, and the first message is ONLY a hello ("Hi", "Hello", "Good morning"): a message with a real request ("I want to buy … perfume")
 *          is answered by the normal greeting instead. Also: no Meta ad click, not a reply to our campaign of the last 3 days, not the campaign number, not "community" in their own words,
 *          no open complaint. Asked once: not again within 30 days of asking / an answer.
 *   What   one WhatsApp list message ("Choose one") with 5 options. Free: it is sent inside the 24-hour window the customer just opened.
 *   Answer a tap on an option, or the customer's own words within 24 hours ("insta", "community se", "offer dekha", "ad", "friend" …):
 *            community → source Community (+ tag)   → the query is Community, the order is allocated as Campaign
 *            campaign  → source Campaign  (+ tag)   → the query is Campaign,  the order is allocated as Campaign
 *            ad        → source Query (from an ad)  → the query is Meta,      the order is allocated as Query
 *            instagram / friend → their own tag     → the order is allocated as Query
 *          The answer is written into the chat as a line ("📍 Came from: Instagram") and shown as a chip in the salesperson's list.
 *   Setup  WhatsApp → Greetings → "Ask where the customer came from" (on/off, question, button, option names). Actions: wa.origin_save
 *          (the settings come with wa.greet_get). Loaded by wa_more.php. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wa_origin_cfg')) {

function wa_origin_keys(): array { return ['instagram', 'community', 'campaign', 'ad', 'friend']; }
function wa_origin_default(): array
{
    return ['on' => true, 'text' => "Welcome to TM Perfume House 🙏\nQuick question, so we can help you better — where did you find us? Tap below 👇", 'button' => 'Choose one',
        'opts' => ['instagram' => '📸 Instagram', 'community' => '👥 WhatsApp community', 'campaign' => '📢 Our offer message', 'ad' => '📱 Facebook / Insta ad', 'friend' => '🙋 Friend / other']];
}
function wa_origin_cfg(PDO $pdo): array
{
    $d = wa_origin_default(); $j = json_decode((string)setting_get($pdo, 'wa_origin', ''), true); if (!is_array($j)) return $d;
    $o = $d['opts']; foreach (wa_origin_keys() as $k) if (isset($j['opts'][$k]) && trim((string)$j['opts'][$k]) !== '') $o[$k] = mb_substr(trim((string)$j['opts'][$k]), 0, 24);
    return ['on' => !array_key_exists('on', $j) || !empty($j['on']), 'text' => trim((string)($j['text'] ?? '')) !== '' ? mb_substr(trim((string)$j['text']), 0, 900) : $d['text'],
        'button' => trim((string)($j['button'] ?? '')) !== '' ? mb_substr(trim((string)$j['button']), 0, 20) : $d['button'], 'opts' => $o];
}
function wa_origin_table(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_origin (phone VARCHAR(12) NOT NULL PRIMARY KEY, asked_at DATETIME NULL, answer VARCHAR(12) NOT NULL DEFAULT '', answered_at DATETIME NULL, how VARCHAR(8) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}
/** The customer's own words → an answer key ('' = not about where they came from). English, Hindi, Gujarati, short forms and typos. */
function wa_origin_words(string $t): string
{
    $t = mb_strtolower(trim($t)); if ($t === '' || mb_strlen($t) > 160) return '';
    if (preg_match('/c[o0]m+[u]+n+[i1]t[iy]e?|comunity|kam+y?u+ni+t+i|कम्यु?निटी|कमुनिटी|કમ્યુ?નિટી|કોમ્યુનિટી|\bgroup\b|ग्रुप|ગ્રુપ/iu', $t)) return 'community';
    if (preg_match('/\b(ad|ads|advert\w*|sponsor\w*|facebook|fb)\b|विज्ञापन|એડ\b|જાહેરાત/iu', $t)) return 'ad';
    if (preg_match('/insta\w*|\big\b|इंस्टा|ઇન્સ્ટા|reel/iu', $t)) return 'instagram';
    if (preg_match('/campaign|camp\b|offer|broadcast|your (message|msg)|aapka (message|msg)|ऑफर|ઓફર|મેસેજ/iu', $t)) return 'campaign';
    if (preg_match('/friend|dost|relative|family|reference|refer\w*|google|youtube|website|दोस्त|મિત્ર|ભાઈબંધ/iu', $t)) return 'friend';
    return '';
}
/** True when the message is ONLY a hello ("hi", "hello", "hey TM Perfume House", "good morning", "namaste"…): no product, no question. Only then do we ask "where did you find us?". */
function wa_origin_bare_hello(string $body): bool
{
    $t = mb_strtolower(trim($body)); if ($t === '' || mb_strlen($t) > 60) return false;
    $w = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY); if (!$w || count($w) > 5) return false;
    $ok = ['hi', 'hii', 'hiii', 'hy', 'hey', 'heyy', 'hello', 'hlo', 'helo', 'hellow', 'hola', 'heya', 'yo', 'there', 'good', 'morning', 'afternoon', 'evening', 'night', 'gm', 'gn', 'ji', 'sir', 'madam', 'mam', 'bhai', 'team', 'tm', 'perfume', 'perfumes', 'house', 'namaste', 'namaskar', 'jsk', 'jai', 'jay', 'shri', 'shree', 'krishna', 'ram', 'kem', 'cho', 'hai', 'ha', 'haan',
        'हाय', 'हैलो', 'हेलो', 'नमस्ते', 'जय', 'श्री', 'कृष्ण', 'राम', 'हाई', 'हेल्लो', 'હાય', 'હાઈ', 'હેલો', 'હેલ્લો', 'નમસ્તે', 'જય', 'શ્રી', 'કૃષ્ણ', 'રામ', 'કેમ', 'છો'];
    foreach ($w as $x) {
        if (in_array($x, $ok, true)) continue;
        if (preg_match('/^h+i+$|^h+e+y+$|^h+e+l+o+w*$/u', $x)) continue;   // hiiii, heyyy, hellooo
        return false;
    }
    return true;
}
function wa_camp_number_safe(PDO $pdo, array $c): bool { return function_exists('wa_camp_number') && wa_camp_number($pdo, (int)($c['last_number_id'] ?? 0)); }
function wa_origin_label(PDO $pdo, string $k): string { $o = wa_origin_cfg($pdo)['opts']; return (string)($o[$k] ?? ''); }

/** Sends the question (a list message) on the number the customer wrote to and writes it into the chat. [ok, wamid|error] */
function wa_origin_send(PDO $pdo, array $c): array
{
    $cfg = wa_origin_cfg($pdo); $n = function_exists('wa_number_row') ? wa_number_row($pdo, (int)($c['last_number_id'] ?? 0)) : null;
    $pid = $n && (string)$n['phone_id'] !== '' ? (string)$n['phone_id'] : (function_exists('wa_cfg_val') ? (string)wa_cfg_val('WA_PHONE_ID') : ''); if ($pid === '') return [false, 'no number'];
    $rows = []; foreach ($cfg['opts'] as $k => $t) $rows[] = ['id' => 'org_' . $k, 'title' => mb_substr($t, 0, 24)];
    $inter = ['type' => 'list', 'body' => ['text' => $cfg['text']], 'action' => ['button' => $cfg['button'], 'sections' => [['title' => 'Where did you find us?', 'rows' => $rows]]]];
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => (string)(($c['wa_id'] ?? '') ?: '91' . $c['phone']), 'type' => 'interactive', 'interactive' => $inter]);
    $wamid = (string)($d['messages'][0]['id'] ?? ''); if ($code < 200 || $code >= 300 || $wamid === '') return [false, function_exists('wa_err') ? wa_err($d) : 'Meta refused'];
    $now = date('Y-m-d H:i:s');
    try { $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'interactive', ?, 'sent', 'auto', 0, ?)")
        ->execute([$wamid, (string)$c['phone'], (int)($n['id'] ?? 0), $cfg['text'] . "\n[" . implode('] [', array_values($cfg['opts'])) . ']', $now]); } catch (Throwable $e) { }
    return [true, $wamid];
}

/** Saves an answer and puts it to work (source, tag, the query type, a line in the chat). $how: tap | words | first */
function wa_origin_apply(PDO $pdo, string $key, string $ans, string $how): void
{
    if (!in_array($ans, wa_origin_keys(), true)) return; $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO wa_origin (phone, answer, answered_at, how) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE answer = VALUES(answer), answered_at = VALUES(answered_at), how = VALUES(how)")->execute([$key, $ans, $now, $how]);
    $tag = function (string $t) use ($pdo, $key, $now) { try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'auto', ?)")->execute([$key, $t, $now]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)")->execute([$key, $t]); } catch (Throwable $e) { } };
    $wq = '';
    if ($ans === 'campaign') { if (function_exists('wa_camp_mark')) wa_camp_mark($pdo, $key, $now); else $pdo->prepare("UPDATE wa_contacts SET source = 'campaign' WHERE phone = ?")->execute([$key]); $wq = 'campaign'; }
    elseif ($ans === 'community') { $pdo->prepare("UPDATE wa_contacts SET source = 'community' WHERE phone = ?")->execute([$key]); $tag('community'); $wq = 'community'; }
    elseif ($ans === 'ad') { $pdo->prepare("UPDATE wa_contacts SET source = 'query' WHERE phone = ? AND source NOT IN ('campaign', 'community')")->execute([$key]); $tag('meta_ad'); $wq = 'meta'; }
    else $tag($ans);   // instagram / friend
    // the enquiry's type follows the answer (only the newest enquiry, opened in the last 2 days)
    if ($wq !== '' && function_exists('wq_retype')) {
        try { $q = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? AND opened_at >= ? ORDER BY id DESC LIMIT 1"); $q->execute([$key, date('Y-m-d H:i:s', time() - 2 * 86400)]); $qid = (int)$q->fetchColumn();
            if ($qid > 0) wq_retype($pdo, $qid, $wq, 'the customer said: ' . wa_origin_label($pdo, $ans), 0); } catch (Throwable $e) { }
    }
    if (function_exists('wa_event')) { try { wa_event($pdo, $key, '📍 Came from: ' . wa_origin_label($pdo, $ans) . ($how === 'tap' ? ' (customer chose)' : ' (customer said)')); } catch (Throwable $e) { } }
}

/** The inbound hook (after the message is stored, after wa_source_hook). Never throws. */
function wa_origin_in(PDO $pdo, string $key, array $m, ?string $prevIn, array $ref): void
{
    try {
        if ($key === '' || !wa_origin_table($pdo)) return;
        $t = (string)($m['type'] ?? 'text');
        // 1 · a tap on one of our options
        $rid = (string)($m['interactive']['list_reply']['id'] ?? $m['interactive']['button_reply']['id'] ?? '');
        if (strpos($rid, 'org_') === 0) { wa_origin_apply($pdo, $key, substr($rid, 4), 'tap'); return; }
        $body = $t === 'text' ? (string)($m['text']['body'] ?? '') : '';
        $q = $pdo->prepare("SELECT * FROM wa_origin WHERE phone = ?"); $q->execute([$key]); $o = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        // 2 · they answered in their own words (within 24 hours of our question, not answered yet)
        if ($o && !empty($o['asked_at']) && (string)$o['answer'] === '' && strtotime((string)$o['asked_at']) > time() - 86400) { $w = wa_origin_words($body); if ($w !== '') wa_origin_apply($pdo, $key, $w, 'words'); return; }
        // 3 · a NEW enquiry: should we ask?
        $cfg = wa_origin_cfg($pdo); if (!$cfg['on'] || defined('WA_ORIGIN_OFF')) return;
        if ($prevIn !== null && $prevIn !== '' && strtotime($prevIn) > time() - 86400) return;   // the same conversation — not a new enquiry
        if ($t === 'reaction' || $t === 'unsupported') return;
        if ($o && ((!empty($o['asked_at']) && strtotime((string)$o['asked_at']) > time() - 30 * 86400) || (!empty($o['answered_at']) && strtotime((string)$o['answered_at']) > time() - 30 * 86400))) return;   // asked / answered lately
        if ($ref) return;                                                                                     // clicked a Meta ad: we know
        if ($body !== '' && function_exists('wa_is_ad_text') && wa_is_ad_text($body)) return;   // a promotion / ad ready-made text ("I need the Diwali special offer: 4 × 50ml for 2350") is a Query: never guess Campaign from the word "offer"
        $w0 = wa_origin_words($body);
        if ($w0 === '' && !wa_origin_bare_hello($body)) return;   // a real message (e.g. "I want to buy … perfume") or a photo/voice: the normal greeting answers it — we ask only after a bare "Hi"
        if ($body !== '' && preg_match('/more info(rmation)? (on|about) this|interested in this|saw (your|this) ad|can i get more info|i\'?m interested in this/iu', $body)) return;   // Meta's ready-made ad text ("I want to order" is NOT enough: we ask)
        $c = wa_contact($pdo, $key); if (!$c || (int)($c['optout'] ?? 0) === 1 || (int)($c['cx_id'] ?? 0) > 0) return;
        if ($body !== '' && preg_match('/complain|complaint|refund|damage|broken|leak|wrong (item|product)|शिकायत|ફરિયાદ/iu', $body)) return;   // a problem, not a new enquiry
        $w = wa_origin_words($body); if ($w !== '' && $w !== 'community' && $w !== 'campaign' && !wa_camp_number_safe($pdo, $c)) { wa_origin_apply($pdo, $key, $w, 'first'); return; }   // they said it themselves ("saw you on insta")
        if (in_array((string)$c['source'], ['campaign', 'community'], true)) return;                         // campaign reply / campaign number / said community
        if (function_exists('wa_camp_number') && wa_camp_number($pdo, (int)($c['last_number_id'] ?? 0))) return;
        $cq = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND who = 'campaign' AND ts >= ?"); $cq->execute([$key, date('Y-m-d H:i:s', time() - 3 * 86400)]); if ((int)$cq->fetchColumn() > 0) return;
        $pdo->prepare("INSERT INTO wa_origin (phone, asked_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE asked_at = VALUES(asked_at), answer = '', answered_at = NULL, how = ''")->execute([$key, date('Y-m-d H:i:s')]);   // marked first: never asked twice
        [$ok, $r] = wa_origin_send($pdo, $c); if (!$ok && function_exists('wa_log')) wa_log($pdo, 'send', false, $key . ' · where-did-you-find-us question · ' . $r);
    } catch (Throwable $e) { error_log('wa_origin_in: ' . $e->getMessage()); }
}
/** phone => the label of the answer, for the salesperson's list (Instagram / ad / friend only: campaign and community already show as the chat's source). */
function wa_origin_chips(PDO $pdo, array $phones): array
{
    if (!$phones || !wa_origin_table($pdo)) return []; $out = [];
    try { $q = $pdo->prepare("SELECT phone, answer FROM wa_origin WHERE answer IN ('instagram', 'ad', 'friend') AND phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ")"); $q->execute(array_values($phones));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['phone']] = wa_origin_label($pdo, (string)$r['answer']); } catch (Throwable $e) { }
    return $out;
}

}

// ---- the settings (Greetings screen)
$__wor = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wor === 'wa.origin_save') {
    $auth = require_auth();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    if (!function_exists('wa_is_admin') || !wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can change this.'], 403);
    $in = json_input(); $cur = wa_origin_cfg($pdo); $opts = $cur['opts'];
    foreach (wa_origin_keys() as $k) if (isset($in['opts'][$k])) { $v = trim((string)$in['opts'][$k]); if ($v === '') json_response(['error' => 'Every option needs a name.'], 422); if (mb_strlen($v) > 24) json_response(['error' => 'Option names can be at most 24 letters (WhatsApp limit): ' . $v], 422); $opts[$k] = $v; }
    $btn = trim((string)($in['button'] ?? $cur['button'])); if ($btn === '' || mb_strlen($btn) > 20) json_response(['error' => 'The button text must be 1–20 letters (WhatsApp limit).'], 422);
    $txt = trim((string)($in['text'] ?? $cur['text'])); if ($txt === '') json_response(['error' => 'Write the question.'], 422);
    $c = ['on' => !empty($in['on']), 'text' => mb_substr($txt, 0, 900), 'button' => $btn, 'opts' => $opts];
    setting_set($pdo, 'wa_origin', json_encode($c, JSON_UNESCAPED_UNICODE)); json_response(['ok' => true, 'origin' => wa_origin_cfg($pdo)]);
}
