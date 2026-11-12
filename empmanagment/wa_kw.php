<?php
/**
 * wa_kw.php — BUTTON REPLIES. The owner makes a card per campaign button (50 ml combo, 20 ml combo, Offer …): the words a customer taps / types, the message to send, an optional photo.
 * When a customer's message matches a card, the reply goes out AT ONCE (no AI) — automatically while "Automatic" is on, and "Send to waiting" answers the ones already waiting.
 * Skipped when: the chat is with a person (assigned), a complaint, opted out, already answered, outside the 24 h window, or the customer is not writing text. One answer per customer message.
 * Setting wkw_cfg = {auto, rules:[{id, name, on, words[], text, lib_id}]}. First time: cards for 20 / 50 / 100 ml (copied from the Pre-made messages titled like that) and Offer.
 * Actions: wa.kw_get · wa.kw_save [Admin/HR] · wa.kw_waiting (GET preview, POST send a batch). Called from wag_prebuild (wa_growth.php) right after the webhook is answered. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wkw_cfg')) {

function wkw_norm(string $s): string
{
    $t = mb_strtolower($s); $t = preg_replace('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/u', ' ', $t); $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t); return ' ' . trim((string)$t) . ' ';
}
function wkw_default_offer(): string { return "Which perfume do you want? 😊 Tell me first, and after that I will share the best offer for it."; }
function wkw_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wkw_cfg', ''), true);
    if (is_array($j) && isset($j['rules'])) return ['auto' => !isset($j['auto']) || !empty($j['auto']), 'rules' => array_values(array_filter((array)$j['rules'], 'is_array'))];
    $q = []; try { $q = $pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id")->fetchAll(); } catch (Throwable $e) { }
    $rules = [];
    foreach (['20', '50', '100'] as $n) { $txt = ''; foreach ($q as $r) { if (preg_match('/(?<!\d)' . $n . '\s*ml(?!\w)/iu', (string)$r['title'])) { $txt = (string)$r['body']; break; } }
        $rules[] = ['id' => 'ml' . $n, 'name' => $n . ' ml combo', 'on' => $txt !== '', 'words' => [$n . ' ml'], 'text' => $txt, 'lib_id' => 0]; }
    $rules[] = ['id' => 'offer', 'name' => 'Offer', 'on' => true, 'words' => ['offer', 'offers'], 'text' => wkw_default_offer(), 'lib_id' => 0];
    return ['auto' => true, 'rules' => $rules];
}
function wkw_clean(array $in): array
{
    $rules = []; $seen = [];
    foreach ((array)($in['rules'] ?? []) as $r) {
        if (!is_array($r)) continue; $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 60); if ($name === '') continue;
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($r['id'] ?? ''))); if ($id === '' || isset($seen[$id])) $id = 'r' . substr(md5($name . microtime(true) . count($rules)), 0, 8); $seen[$id] = 1;
        $words = []; foreach ((array)($r['words'] ?? []) as $w) { $w = mb_substr(trim((string)$w), 0, 40); if ($w !== '') $words[] = $w; }
        $rules[] = ['id' => $id, 'name' => $name, 'on' => !empty($r['on']), 'words' => array_slice(array_values(array_unique($words)), 0, 12), 'text' => mb_substr(trim((string)($r['text'] ?? '')), 0, 1000), 'lib_id' => max(0, (int)($r['lib_id'] ?? 0))];
    }
    return ['auto' => !empty($in['auto']), 'rules' => array_slice($rules, 0, 40)];
}
/** The rule a customer message belongs to (or null): the longest matching word wins; only short messages (a button / a few words) count. */
function wkw_match(array $cfg, string $text): ?array
{
    $t = wkw_norm($text); if (trim($t) === '' || count(explode(' ', trim($t))) > 6) return null; $best = null; $bl = 0;
    foreach ($cfg['rules'] as $r) { if (empty($r['on'])) continue; foreach ((array)$r['words'] as $w) { $n = wkw_norm((string)$w); if (trim($n) === '') continue; if (strpos($t, $n) !== false && strlen($n) > $bl) { $best = $r; $bl = strlen($n); } } }
    return $best;
}
/** Answers the newest customer message of this chat when it matches a card. true = something was sent. $auto = called by the webhook (needs "Automatic" on). */
function wkw_try(PDO $pdo, string $key, bool $auto = true, string $onlyRule = ''): bool
{
    try {
        $cfg = wkw_cfg($pdo); if ($auto && !$cfg['auto']) return false;
        $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $st->execute([$key]); $c = $st->fetch(); if (!$c) return false;
        if ((int)$c['optout'] === 1 || !empty($c['cx_id']) || (int)($c['assigned_emp'] ?? 0) > 0 || empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return false;
        if (!empty($c['last_out_at']) && strtotime((string)$c['last_out_at']) >= strtotime((string)$c['last_in_at'])) return false;
        $li = $pdo->prepare("SELECT id, mtype, body, wamid FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) return false;
        $r = wkw_match($cfg, (string)$m['body']); if (!$r || ($onlyRule !== '' && (string)$r['id'] !== $onlyRule)) return false;
        $mk = 'wkw_' . $key; if ((string)setting_get($pdo, $mk, '') === (string)$m['id']) return false;
        $text = trim((string)$r['text']); if ($text === '' && (int)$r['lib_id'] <= 0) { setting_set($pdo, $mk, (string)$m['id']); if (function_exists('wa_event')) wa_event($pdo, $key, '⚠ The customer used the button "' . $r['name'] . '" but its message is empty — write it in Campaigns → ⚡ Button replies.'); return false; }
        setting_set($pdo, $mk, (string)$m['id']);
        $nm = trim((string)($c['name'] ?? '')); $fn = preg_split('/\s+/u', $nm)[0] ?? ''; if ($fn === '' || !preg_match('/^\p{L}[\p{L}\p{M}.\'-]*$/u', $fn)) $fn = '';
        $text = $fn !== '' ? str_ireplace('{name}', $fn, $text) : preg_replace('/[ ]*\{name\}/i', '', $text);
        $ok = false;
        if ((int)$r['lib_id'] > 0 && function_exists('wa_lib_row') && function_exists('wa_lib_media_id') && function_exists('wa_send_media')) {   // 📷 the photo (the message is its caption)
            $row = wa_lib_row($pdo, (int)$r['lib_id']);
            if ($row) { $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID');
                [$mid] = wa_lib_media_id($pdo, $row, $pid);
                if ($mid !== '') { [$ok] = wa_send_media($pdo, $c, (string)$row['kind'], $mid, (string)$row['mime'], $text, (string)$row['filename'], 0); if ($ok) { try { $pdo->prepare("UPDATE wa_contacts SET state = ? WHERE phone = ?")->execute([(string)$c['state'], $key]); } catch (Throwable $e) { } } } }
        }
        if (!$ok && $text !== '') { [$ok] = wa_send_text($pdo, $c, $text, 'bot', 0, (string)($m['wamid'] ?? '')); }
        if ($ok) { $dk = 'wkn_' . $r['id'] . '_' . date('Y-m-d'); setting_set($pdo, $dk, (string)((int)setting_get($pdo, $dk, '0') + 1)); }
        return (bool)$ok;
    } catch (Throwable $e) { return false; }
}
/** Chats still waiting whose newest customer message matches a card: [phone => rule id]. */
function wkw_waiting(PDO $pdo, int $max = 600): array
{
    $out = []; $cfg = wkw_cfg($pdo);
    try {
        $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND assigned_emp = 0 AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at DESC LIMIT " . (int)$max);
        $st->execute([date('Y-m-d H:i:s', time() - 86400 + 600)]);
        $li = $pdo->prepare("SELECT id, mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) {
            $li->execute([$ph]); $m = $li->fetch(); if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) continue;
            $r = wkw_match($cfg, (string)$m['body']); if (!$r || (string)setting_get($pdo, 'wkw_' . $ph, '') === (string)$m['id']) continue; $out[(string)$ph] = (string)$r['id'];
        }
    } catch (Throwable $e) { }
    return $out;
}

}

$__kwa = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__kwa, ['wa.kw_get', 'wa.kw_save', 'wa.kw_waiting'], true)) {
    $auth = require_auth(); if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (!(function_exists('wa_is_admin') && wa_is_admin($pdo, $auth))) json_response(['error' => 'Only Admin, HR or the control tower can use Button replies.'], 403);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($__kwa === 'wa.kw_save') {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wkw_clean(json_input()); setting_set($pdo, 'wkw_cfg', json_encode($cfg, JSON_UNESCAPED_UNICODE)); json_response(['ok' => true, 'cfg' => $cfg]);
    }
    if ($__kwa === 'wa.kw_get') {
        $cfg = wkw_cfg($pdo); $w = wkw_waiting($pdo); $stats = [];
        foreach ($cfg['rules'] as $r) { $stats[$r['id']] = ['sent' => (int)setting_get($pdo, 'wkn_' . $r['id'] . '_' . date('Y-m-d'), '0'), 'waiting' => 0]; }
        foreach ($w as $rid) if (isset($stats[$rid])) $stats[$rid]['waiting']++;
        json_response(['ok' => true, 'cfg' => $cfg, 'stats' => $stats, 'total_waiting' => count($w)]);
    }
    // wa.kw_waiting: POST {rule?: id} sends one batch of 20 (the page repeats until left = 0)
    $only = $isPost ? preg_replace('/[^a-z0-9_]/', '', (string)(json_input()['rule'] ?? '')) : '';
    $w = wkw_waiting($pdo); if ($only !== '') $w = array_filter($w, fn($rid) => $rid === $only);
    if (!$isPost) json_response(['ok' => true, 'total' => count($w)]);
    @set_time_limit(120); $sent = 0; $fail = 0; $t0 = microtime(true);
    foreach ($w as $ph => $rid) { if ($sent + $fail >= 20 || microtime(true) - $t0 > 80) break; if (wkw_try($pdo, (string)$ph, false, $only)) $sent++; else $fail++; usleep(300000); }
    $left = wkw_waiting($pdo); if ($only !== '') $left = array_filter($left, fn($rid) => $rid === $only); json_response(['ok' => true, 'sent' => $sent, 'failed' => $fail, 'left' => count($left)]);
}
