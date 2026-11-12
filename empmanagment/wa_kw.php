<?php
/**
 * wa_kw.php — KEYWORD AUTO-REPLIES. A customer writes "100 ML combo" / "20ml combo" / "50ml" / "offer" → the matching Pre-made message goes out AT ONCE (no AI, no waiting).
 *   100 ML combo → the Pre-made message titled "100ml" (or "100ml combo")   (same for 20 and 50; a size alone, in a short message, also counts)
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
    $plain = null;
    foreach ($rows as $r) {
        $ti = mb_strtolower((string)$r['title']); $ti2 = preg_replace('/\s+/u', '', $ti);
        if ($kind === 'offer') { if (preg_match('/^\W*offer\W*$/u', $ti)) return (string)$r['body']; continue; }
        $n = substr($kind, 2); if (preg_match('/(?<!\d)' . $n . 'ml(?!\w)/u', $ti2)) { if (strpos($ti2, 'combo') !== false) return (string)$r['body']; if ($plain === null) $plain = (string)$r['body']; }   // "100ml COMBO" or just "100ml"
    }
    if ($kind !== 'offer' && $plain !== null) return $plain;
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

/** Chats still waiting whose newest customer message is one of the keywords: [phone => kind]. */
function wkw_waiting(PDO $pdo, int $max = 600): array
{
    $out = [];
    try {
        $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND assigned_emp = 0 AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at DESC LIMIT " . (int)$max);
        $st->execute([date('Y-m-d H:i:s', time() - 86400 + 600)]);
        $li = $pdo->prepare("SELECT id, mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) {
            $li->execute([$ph]); $m = $li->fetch(); if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) continue;
            $k = wkw_match((string)$m['body']); if ($k === '' || (string)setting_get($pdo, 'wkw_' . $ph, '') === (string)$m['id']) continue; $out[(string)$ph] = $k;
        }
    } catch (Throwable $e) { }
    return $out;
}

}

$__kwa = (string)($action ?? ($_GET['action'] ?? ''));
if ($__kwa === 'wa.kw_backfill') {
    $auth = require_auth(); if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (!(function_exists('wa_is_admin') && wa_is_admin($pdo, $auth))) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    $w = wkw_waiting($pdo); $cnt = ['ml20' => 0, 'ml50' => 0, 'ml100' => 0, 'offer' => 0]; foreach ($w as $k) $cnt[$k]++;
    $miss = []; foreach ($cnt as $k => $n) if ($n > 0 && wkw_text($pdo, $k) === '') $miss[] = $k === 'offer' ? 'Offer' : strtoupper(substr($k, 2)) . 'ML Combo';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['ok' => true, 'total' => count($w), 'counts' => $cnt, 'missing' => $miss]);
    if ($miss) json_response(['error' => 'Save these Pre-made messages first: ' . implode(', ', $miss) . '.'], 422);
    @set_time_limit(120); $sent = 0; $fail = 0; $t0 = microtime(true);
    foreach ($w as $ph => $k) {
        if ($sent + $fail >= 20 || microtime(true) - $t0 > 80) break;
        if (wkw_try($pdo, (string)$ph)) $sent++; else $fail++; usleep(300000);
    }
    $left = count(wkw_waiting($pdo)); json_response(['ok' => true, 'sent' => $sent, 'failed' => $fail, 'left' => $left]);
}
