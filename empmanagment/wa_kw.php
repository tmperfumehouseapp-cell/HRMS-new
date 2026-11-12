<?php
/**
 * wa_kw.php — KEYWORD AUTO-REPLIES. A customer writes "100 ML combo" / "20ml combo" / "50ml" / "offer" → the matching Pre-made message goes out AT ONCE (no AI, no waiting).
 *   100 ML combo → the Pre-made message whose title contains "100" + "combo"   (same for 20 and 50; a size alone, in a short message, also counts)
 *   offer        → the Pre-made titled "Offer" (if none: "Which perfume do you want? Tell me first, after that I will share the best offer for it.")
 * The owner edits the texts in the normal Pre-made list (Quick replies) — nothing else to set up. Skipped when: the chat is with a person (assigned), a complaint, opted out,
 * already answered, outside the 24 h window, or the customer is not writing text. A matching word with no Pre-made saved leaves a note in the chat instead of guessing.
 * Called from wag_prebuild (wa_growth.php) right after the webhook is answered. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wkw_match')) {

/** 'ml20' | 'ml50' | 'ml100' | 'offer' | '' for one customer message. */
function wkw_match(string $text): string
{
    $t = mb_strtolower(trim($text)); if ($t === '' || mb_strlen($t) > 80) return '';
    if (preg_match('/(?<!\d)(20|50|100)\s*-?\s*ml\b/u', $t, $m)) { if (preg_match('/combo|share|price|rate|kimat|bhav|deal/u', $t) || mb_strlen($t) <= 14) return 'ml' . $m[1]; }
    if (preg_match('/\b(offers?|ofer|offr)\b/u', $t) && !preg_match('/(?<!\d)\d{2,3}\s*ml/u', $t)) return 'offer';
    return '';
}
function wkw_default_offer(): string { return "Which perfume do you want? 😊 Tell me first, and after that I will share the best offer for it."; }
/** The Pre-made text for a kind, or '' when none is saved. */
function wkw_text(PDO $pdo, string $kind): string
{
    try { $rows = $pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id")->fetchAll(); } catch (Throwable $e) { return $kind === 'offer' ? wkw_default_offer() : ''; }
    foreach ($rows as $r) {
        $ti = mb_strtolower((string)$r['title']); $ti2 = preg_replace('/\s+/u', '', $ti);
        if ($kind === 'offer') { if (preg_match('/^\W*offer\W*$/u', $ti)) return (string)$r['body']; continue; }
        $n = substr($kind, 2); if (strpos($ti2, $n . 'ml') !== false && strpos($ti2, 'combo') !== false) return (string)$r['body'];
    }
    return $kind === 'offer' ? wkw_default_offer() : '';
}
/** Answers the newest customer message of this chat when it is one of the keywords. Returns true when something was sent. */
function wkw_try(PDO $pdo, string $key): bool
{
    try {
        $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $st->execute([$key]); $c = $st->fetch(); if (!$c) return false;
        if ((int)$c['optout'] === 1 || !empty($c['cx_id']) || (int)($c['assigned_emp'] ?? 0) > 0 || empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return false;
        if (!empty($c['last_out_at']) && strtotime((string)$c['last_out_at']) >= strtotime((string)$c['last_in_at'])) return false;
        $li = $pdo->prepare("SELECT id, mtype, body, wamid FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) return false;
        $kind = wkw_match((string)$m['body']); if ($kind === '') return false;
        $mk = 'wkw_' . $key; if ((string)setting_get($pdo, $mk, '') === (string)$m['id']) return false; setting_set($pdo, $mk, (string)$m['id']);   // once per customer message
        $text = wkw_text($pdo, $kind);
        if ($text === '') { if (function_exists('wa_event')) wa_event($pdo, $key, '⚠ The customer asked for "' . strtoupper(substr($kind, 2)) . ($kind === 'offer' ? '' : ' ML combo') . '" but no Pre-made message with that title is saved — add one (Pre-made → title "' . substr($kind, 2) . 'ML Combo") and it will go automatically next time.'); return false; }
        $nm = trim((string)($c['name'] ?? '')); $fn = preg_split('/\s+/u', $nm)[0] ?? ''; if ($fn === '' || !preg_match('/^\p{L}[\p{L}\p{M}.\'-]*$/u', $fn)) $fn = '';
        $text = $fn !== '' ? str_ireplace('{name}', $fn, $text) : preg_replace('/[ ]*\{name\}/i', '', $text);
        [$ok] = wa_send_text($pdo, $c, $text, 'bot', 0, (string)($m['wamid'] ?? ''));
        return (bool)$ok;
    } catch (Throwable $e) { return false; }
}

}
