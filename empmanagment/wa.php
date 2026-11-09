<?php
/**
 * wa.php — WhatsApp Cloud API inside the HRMS (Step 1: plumbing).
 * Included from api.php by ONE line (see the install note) and answers only the actions that start with "wa.".
 *
 *   wa.webhook      GET  Meta's "verify" call · POST every incoming message / delivery status (no login; checked with the app secret)
 *   wa.status       GET  config check + counts + log (?test=1 also asks Meta about the number and the webhook)      [Admin / HR]
 *   wa.subscribe    POST connect this WhatsApp account to the webhook                                              [Admin / HR]
 *   wa.register     POST {pin} register the number on the Cloud API                                                [Admin / HR]
 *   wa.quick_list / wa.quick_save / wa.quick_del   quick replies (list: any login · save/delete: Admin / HR)
 *   wa.chats        GET  {scope: mine|unassigned|all|closed|emp, emp, q}  the customer list, newest first (+ counts). A salesperson sees only their own.
 *   wa.chat         GET  {phone}  the full chat of ONE customer — all numbers merged into one timeline
 *   wa.send         POST {phone, text}  reply from the number the customer last used (inside the 24-hour window)
 *   wa.people       GET  salespeople: present today, open chats, and the recommended one                            [Admin / HR / sales head]
 *   wa.assign       POST {phone, emp_id}  hand a chat to a person (0 = unassigned) — logged, the person gets a push   [Admin / HR / sales head]
 *   wa.transfer     POST {phone, emp_id}  the holder of a chat hands it to a colleague
 *   wa.optin        POST {phone, opted_in}  the "Opted in" switch
 *   wa.close / wa.reopen  POST {phone}  close or re-open a chat (the assignee or an admin)
 *   wa.tag          POST {phone, force}  Claude's tag + short summary of the chat
 *   wa.tpl_*        pre-made messages (templates) — see wa_tpl.php
 *   wa.overview / wa.send_media   the WhatsApp console: counters + number health, and photo / video / document sending — see wa_console.php
 *   wa.media        GET  {id}  a photo / voice note / document, fetched through the server (the token never reaches the browser)
 *   wa.numbers … wa.reports   numbers & profile, contacts, team & routing, media library, follow-ups, campaigns, reports — see wa_more.php / wa_media.php / wa_camp.php
 *
 * ONE CUSTOMER = ONE PHONE NUMBER. Customers are keyed by the last 10 digits of their phone, whichever of your numbers they write to,
 * so a chat that started on number A and continues on number B is one conversation, with each message tagged with its own number.
 * Secrets live only in config.php: WA_TOKEN, WA_PHONE_ID, WA_WABA_ID, WA_APP_SECRET, WA_VERIFY_TOKEN (optional: WA_GRAPH, default v21.0).
 */
if (!function_exists('wa_key')) {

if (!function_exists('wa_raw')) { function wa_raw(string $s, int $code = 200): void { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $s; exit; } }

/** The customer key: last 10 digits of the phone ("+91 97123 59275", "09712359275", "919712359275" → 9712359275). */
function wa_key($n): string { $d = preg_replace('/\D+/', '', (string)$n) ?? ''; return strlen($d) > 10 ? substr($d, -10) : $d; }
if (!function_exists('wa_body')) { function wa_body(): string { return (string)file_get_contents('php://input'); } }
function wa_cfg_val(string $k): string { if (!defined($k)) return ''; $v = trim((string)constant($k)); return (stripos($v, 'paste-') === 0 || stripos($v, 'any-long-random') === 0) ? '' : $v; }
function wa_graph(): string { $g = wa_cfg_val('WA_GRAPH'); return $g !== '' ? $g : 'v21.0'; }

function wa_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY, phone_id VARCHAR(40) NOT NULL, display VARCHAR(30) NOT NULL DEFAULT '', label VARCHAR(60) NOT NULL DEFAULT '',
            panel VARCHAR(20) NOT NULL DEFAULT '', waba_id VARCHAR(30) NOT NULL DEFAULT '', provider VARCHAR(10) NOT NULL DEFAULT 'cloud', created_at DATETIME NULL, UNIQUE KEY uq_phone (phone_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_contacts (
            phone VARCHAR(12) NOT NULL PRIMARY KEY, wa_id VARCHAR(20) NOT NULL DEFAULT '', name VARCHAR(120) NOT NULL DEFAULT '',
            first_seen DATETIME NULL, last_in_at DATETIME NULL, last_out_at DATETIME NULL, last_number_id INT NOT NULL DEFAULT 0,
            assigned_emp INT NOT NULL DEFAULT 0, state VARCHAR(12) NOT NULL DEFAULT 'unassigned', optout TINYINT NOT NULL DEFAULT 0,
            source VARCHAR(12) NOT NULL DEFAULT '', KEY k_last (last_in_at), KEY k_emp (assigned_emp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, wamid VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL, number_id INT NOT NULL DEFAULT 0,
            dir VARCHAR(3) NOT NULL, mtype VARCHAR(16) NOT NULL DEFAULT 'text', body TEXT NULL, media_id VARCHAR(80) NOT NULL DEFAULT '', media_mime VARCHAR(60) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'received', who VARCHAR(10) NOT NULL DEFAULT 'customer', emp_id INT NOT NULL DEFAULT 0, ts DATETIME NULL,
            UNIQUE KEY uq_wamid (wamid), KEY k_phone (phone, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NULL, kind VARCHAR(16) NOT NULL, ok TINYINT NOT NULL DEFAULT 1, info VARCHAR(300) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_quick (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(60) NOT NULL, body TEXT NOT NULL, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_assign_log (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, from_emp INT NOT NULL DEFAULT 0, to_emp INT NOT NULL DEFAULT 0, by_emp INT NOT NULL DEFAULT 0, how VARCHAR(10) NOT NULL DEFAULT 'manual', at DATETIME NULL, KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // columns added after the first release — added once, only when missing
        foreach (['tag' => "VARCHAR(20) NOT NULL DEFAULT ''", 'summary' => "VARCHAR(600) NOT NULL DEFAULT ''", 'tagged_at' => 'DATETIME NULL', 'status' => "VARCHAR(8) NOT NULL DEFAULT 'open'",
                  'closed_at' => 'DATETIME NULL', 'assigned_at' => 'DATETIME NULL', 'ref_text' => "VARCHAR(200) NOT NULL DEFAULT ''", 'seen_msg_id' => 'BIGINT NOT NULL DEFAULT 0'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_contacts LIMIT 0"); }
            catch (PDOException $e) {
                $pdo->exec("ALTER TABLE wa_contacts ADD COLUMN $col $def");
                if ($col === 'seen_msg_id') $pdo->exec("UPDATE wa_contacts SET seen_msg_id = (SELECT COALESCE(MAX(m.id), 0) FROM wa_messages m WHERE m.phone = wa_contacts.phone)");   // chats that already exist start as "read"
            }
        }
        foreach (['err' => "VARCHAR(200) NOT NULL DEFAULT ''", 'err_code' => 'INT NOT NULL DEFAULT 0', 'reply_to' => "VARCHAR(120) NOT NULL DEFAULT ''"] as $col => $def) {   // reply_to: the message the customer swiped to reply to (Meta's context.id)   // why Meta could not deliver a message (filled by the status webhook)
            try { $pdo->query("SELECT $col FROM wa_messages LIMIT 0"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_messages ADD COLUMN $col $def"); } catch (PDOException $e2) { } }
        }
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wa_log(PDO $pdo, string $kind, bool $ok, string $info): void
{
    try { $pdo->prepare("INSERT INTO wa_log (at, kind, ok, info) VALUES (?, ?, ?, ?)")->execute([date('Y-m-d H:i:s'), $kind, $ok ? 1 : 0, mb_substr($info, 0, 300)]);
          $pdo->exec("DELETE FROM wa_log WHERE id < (SELECT m FROM (SELECT MAX(id) - 300 AS m FROM wa_log) t)"); } catch (PDOException $e) { }
}

/** One HTTP call. Replaceable in tests (defined first by the test harness). Returns [httpCode, decodedJson|null, rawBody]. */
if (!function_exists('wa_http')) {
    function wa_http(string $method, string $url, array $headers = [], ?string $body = null, bool $rawOut = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($raw === false) return [0, ['error' => ['message' => 'Could not reach Meta: ' . $err]], ''];
        return [$code, $rawOut ? null : json_decode((string)$raw, true), (string)$raw];
    }
}
// ---------------------------------------------------------------------------------------------------------------- ⚡ media cache: customer photos / videos / documents
// Meta keeps a customer's file for about 30 days and every view used to download it again from Meta through PHP (slow for a video, and gone after 30 days).
// Now each file is downloaded ONCE to wa_files/cache (not reachable from the web, served only through wa.media with the login check) — new incoming files
// are fetched in the background (wa_mcache_prefetch, from the engine tick), so an unboxing video opens at once and stays available. Old files are removed after 90 days.
function wa_mcache_dir(): string
{
    $base = function_exists('wa_lib_dir') ? wa_lib_dir() : __DIR__ . '/wa_files'; if (!is_dir($base)) @mkdir($base, 0755, true);
    if (is_dir($base) && !is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    $d = $base . '/cache'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d;
}
/** [path, mime, error] — the cached file, downloaded from Meta first when it is not here yet. */
function wa_mcache_get(PDO $pdo, string $id): array
{
    $id = preg_replace('/[^0-9]/', '', $id); if ($id === '') return ['', '', 'No media id.'];
    $dir = wa_mcache_dir(); $f = $dir . '/' . $id . '.bin'; $mf = $dir . '/' . $id . '.mime';
    if (is_file($f) && filesize($f) > 0) { @touch($f); return [$f, is_file($mf) ? trim((string)@file_get_contents($mf)) : 'application/octet-stream', '']; }
    [$cd, $d] = wa_api('GET', '/' . $id); if ($cd < 200 || $cd >= 300 || empty($d['url'])) return ['', '', wa_err($d, 'Meta no longer has this file (WhatsApp keeps customer files for about 30 days).')];
    if ((int)($d['file_size'] ?? 0) > 120 * 1048576) return ['', '', 'This file is too big to open here (over 120 MB).'];
    $tmp = $f . '.part' . getmypid(); $fh = @fopen($tmp, 'wb'); if (!$fh) return ['', '', 'The server cannot save files in the wa_files folder — allow writing (permission 755).'];
    $ch = curl_init((string)$d['url']); curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . wa_cfg_val('WA_TOKEN')]]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
    if (!$ok || $code < 200 || $code >= 300 || filesize($tmp) === 0) { @unlink($tmp); return ['', '', 'Could not download the file from Meta — try again.']; }
    @rename($tmp, $f); $mime = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string)($d['mime_type'] ?? '')) ? (string)$d['mime_type'] : 'application/octet-stream'; @file_put_contents($mf, $mime);
    return [$f, $mime, ''];
}
/** The signature of a media link: HMAC of id + expiry with the app secret (or, when config has none, a random key kept in the settings). */
function wa_mfile_sig(PDO $pdo, string $id, int $e): string
{
    $k = defined('APP_SECRET') ? (string)APP_SECRET : ''; if (strlen($k) < 16) { $k = (string)setting_get($pdo, 'wa_mfile_key', ''); if (strlen($k) < 32) { $k = bin2hex(random_bytes(24)); setting_set($pdo, 'wa_mfile_key', $k); } }
    return substr(hash_hmac('sha256', 'wamf|' . $id . '|' . $e, $k), 0, 40);
}
/** Sends a cached file in pieces, with HTTP byte ranges (iPhone / Android play and seek videos only with ranges). */
/** The real type of a cached file. Meta says "audio/ogg; codecs=opus" for voice notes: that was refused as "not a plain type" and saved as application/octet-stream, which (with nosniff) browsers will not PLAY. Strip the parameters, and when the type is unknown read the file's first bytes. */
function wa_mime_fix(string $path, string $mime): string
{
    $m = strtolower(trim(explode(';', $mime)[0]));
    if ($m !== '' && $m !== 'application/octet-stream' && preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $m)) return $m === 'audio/ogg' ? 'audio/ogg; codecs=opus' : $m;
    $h = ''; try { $fh = @fopen($path, 'rb'); if ($fh) { $h = (string)fread($fh, 16); fclose($fh); } } catch (Throwable $e) { }
    if (strncmp($h, 'OggS', 4) === 0) return 'audio/ogg; codecs=opus';
    if (strncmp($h, 'ID3', 3) === 0 || (strlen($h) > 1 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) return 'audio/mpeg';
    if (strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp') return 'video/mp4';
    if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WAVE') return 'audio/wav';
    if (strncmp($h, "\x89PNG", 4) === 0) return 'image/png'; if (strncmp($h, "\xFF\xD8", 2) === 0) return 'image/jpeg'; if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') return 'image/webp'; if (strncmp($h, '%PDF', 4) === 0) return 'application/pdf';
    if (strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0) return 'audio/webm'; if (strncmp($h, '#!AMR', 5) === 0) return 'audio/amr';
    return $mime !== '' ? $mime : 'application/octet-stream';
}
function wa_mcache_send(string $path, string $mime): void
{
    $mime = wa_mime_fix($path, $mime);
    $size = (int)filesize($path); $start = 0; $end = $size - 1;
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: ' . $mime); header('Accept-Ranges: bytes'); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); } else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end || $start >= $size) { header('HTTP/1.1 416 Range Not Satisfiable'); header('Content-Range: bytes */' . $size); return; }
        header('HTTP/1.1 206 Partial Content'); header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fh = fopen($path, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $buf = fread($fh, (int)min(262144, $left)); if ($buf === false) break; echo $buf; $left -= strlen($buf); flush(); }
    fclose($fh);
}
/** Background: the newest customer photos / videos / documents / voice notes are downloaded before anyone opens them (3 per run), and files older than 90 days are removed (once a day). */
function wa_mcache_prefetch(PDO $pdo): int
{
    if ((int)setting_get($pdo, 'wa_mcache_at', '0') > time() - 50) return 0; setting_set($pdo, 'wa_mcache_at', (string)time()); $n = 0; $dir = wa_mcache_dir(); $t0 = microtime(true);
    try { $q = $pdo->prepare("SELECT media_id FROM wa_messages WHERE dir = 'in' AND media_id <> '' AND mtype IN ('video','image','document','audio','sticker') AND ts >= ? GROUP BY media_id ORDER BY MAX(id) DESC LIMIT 30"); $q->execute([date('Y-m-d H:i:s', time() - 12 * 3600)]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mid) { $mid = preg_replace('/[^0-9]/', '', (string)$mid); if ($mid === '' || is_file($dir . '/' . $mid . '.bin')) continue; if ($n >= 3 || microtime(true) - $t0 > 25) break; [$p] = wa_mcache_get($pdo, $mid); if ($p !== '') $n++; } } catch (Throwable $e) { }
    if ((string)setting_get($pdo, 'wa_mcache_gc', '') !== date('Y-m-d')) { setting_set($pdo, 'wa_mcache_gc', date('Y-m-d')); foreach ((array)glob($dir . '/*') as $f) if (is_file($f) && filemtime($f) < time() - 90 * 86400) @unlink($f); }
    return $n;
}
/** Graph API call with the token. $path like "/123/messages". */
function wa_api(string $method, string $path, ?array $json = null): array
{
    $tok = wa_cfg_val('WA_TOKEN'); if ($tok === '') return [0, ['error' => ['message' => 'WA_TOKEN is missing in config.php.']], ''];
    $h = ['Authorization: Bearer ' . $tok]; $body = null;
    if ($json !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($json, JSON_UNESCAPED_UNICODE); }
    return wa_http($method, 'https://graph.facebook.com/' . wa_graph() . $path, $h, $body);
}
/** Keeps Meta's EXACT answer for every failure (the send response, or the failed-status webhook) — so a failure is never "no reason". Table wa_fail_raw, newest 5,000 kept. */
function wa_fail_raw(PDO $pdo, string $kind, string $phone, string $wamid, int $code, $payload): void
{
    try {
        static $made = false; if (!$made) { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_fail_raw (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, kind VARCHAR(8) NOT NULL, phone VARCHAR(12) NOT NULL DEFAULT '', wamid VARCHAR(120) NOT NULL DEFAULT '', code INT NOT NULL DEFAULT 0, payload TEXT NULL, KEY k_w (wamid), KEY k_p (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $made = true; }
        $pdo->prepare("INSERT INTO wa_fail_raw (at, kind, phone, wamid, code, payload) VALUES (?, ?, ?, ?, ?, ?)")->execute([date('Y-m-d H:i:s'), mb_substr($kind, 0, 8), mb_substr($phone, -10), mb_substr($wamid, 0, 120), $code, mb_substr(is_string($payload) ? $payload : (string)json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 4000)]);
        if (random_int(1, 200) === 1) $pdo->exec("DELETE FROM wa_fail_raw WHERE id < (SELECT m FROM (SELECT MAX(id) - 5000 AS m FROM wa_fail_raw) x)");
    } catch (Throwable $e) { }
}
function wa_err(?array $d, string $fallback = 'Meta did not accept this.'): string
{
    $m = is_array($d) && isset($d['error']['message']) ? (string)$d['error']['message'] : $fallback;
    $c = is_array($d) && isset($d['error']['code']) ? ' (code ' . $d['error']['code'] . ')' : '';
    return $m . $c;
}

function wa_number_id(PDO $pdo, string $phoneId, string $display = ''): int
{
    if ($phoneId === '') return 0;
    $q = $pdo->prepare("SELECT id, display FROM wa_numbers WHERE phone_id = ?"); $q->execute([$phoneId]); $r = $q->fetch();
    if ($r) { if ($display !== '' && $r['display'] !== $display) $pdo->prepare("UPDATE wa_numbers SET display = ? WHERE id = ?")->execute([$display, $r['id']]); return (int)$r['id']; }
    $pdo->prepare("INSERT INTO wa_numbers (phone_id, display, created_at) VALUES (?, ?, ?)")->execute([$phoneId, $display, date('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
}
/** Makes sure the number from config.php (WA_PHONE_ID) is in the numbers list, named "Main number" until you rename it. */
function wa_numbers_sync(PDO $pdo): void
{
    $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return;
    $id = wa_number_id($pdo, $pid, '');
    $pdo->prepare("UPDATE wa_numbers SET label = 'Main number' WHERE id = ? AND label = ''")->execute([$id]);
}
function wa_number_row(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wa_numbers WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; }
/** Which WhatsApp Business Account holds this Phone number ID? Looks only in the accounts the HRMS already knows (the ones saved on other numbers + WA_WABA_ID), asking Meta for each account's phone numbers. '' = not found. */
function wa_find_waba(PDO $pdo, string $phoneId): string
{
    $cands = [];
    foreach ($pdo->query("SELECT DISTINCT waba_id FROM wa_numbers WHERE waba_id <> ''")->fetchAll() as $r) $cands[(string)$r['waba_id']] = 1;
    $cfg = wa_cfg_val('WA_WABA_ID'); if ($cfg !== '') $cands[$cfg] = 1;
    foreach (array_keys($cands) as $w) {
        [$c, $d] = wa_api('GET', '/' . $w . '/phone_numbers?fields=id&limit=100');
        if ($c >= 200 && $c < 300 && is_array($d)) foreach ((array)($d['data'] ?? []) as $x) if ((string)($x['id'] ?? '') === $phoneId) return $w;
    }
    return '';
}
function wa_waba_of(array $n): string { return trim((string)($n['waba_id'] ?? '')) !== '' ? trim((string)$n['waba_id']) : wa_cfg_val('WA_WABA_ID'); }
/** Asks Meta about one number (name approval, quality, status) and whether its WhatsApp account is connected to the webhook. */
function wa_number_check(array $n): array
{
    $out = ['info' => null, 'error' => '', 'subscribed' => null, 'sub_error' => ''];
    [$c, $d] = wa_api('GET', '/' . $n['phone_id'] . '?fields=display_phone_number,verified_name,name_status,quality_rating,status,platform_type');
    if ($c >= 200 && $c < 300 && is_array($d)) $out['info'] = $d; else $out['error'] = wa_err($d, 'No answer from Meta.');
    $wb = wa_waba_of($n);
    if ($wb !== '') { [$c, $d] = wa_api('GET', '/' . $wb . '/subscribed_apps'); if ($c >= 200 && $c < 300 && is_array($d)) $out['subscribed'] = !empty($d['data']); else $out['sub_error'] = wa_err($d, 'Could not check the webhook.'); }
    else $out['sub_error'] = 'No WhatsApp Business Account ID for this number.';
    return $out;
}
function wa_ts($t): string { $n = (int)$t; return $n > 0 ? date('Y-m-d H:i:s', $n) : date('Y-m-d H:i:s'); }

/** A customer message's readable text + media. */
function wa_parse_msg(array $m): array
{
    $t = (string)($m['type'] ?? 'text'); $body = ''; $mid = ''; $mime = '';
    if ($t === 'text') $body = (string)($m['text']['body'] ?? '');
    elseif (in_array($t, ['image', 'video', 'document', 'audio', 'sticker'], true)) {
        $o = $m[$t] ?? []; $mid = (string)($o['id'] ?? ''); $mime = (string)($o['mime_type'] ?? ''); $cap = (string)($o['caption'] ?? $o['filename'] ?? '');
        $body = ($t === 'audio' ? '🎤 Voice note' : ($t === 'image' ? '📷 Photo' : ($t === 'video' ? '🎬 Video' : ($t === 'sticker' ? 'Sticker' : '📄 Document')))) . ($cap !== '' ? ': ' . $cap : '');
    }
    elseif ($t === 'button') $body = (string)($m['button']['text'] ?? '');
    elseif ($t === 'interactive') $body = (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '');
    elseif ($t === 'location') $body = '📍 Location' . (isset($m['location']['name']) ? ': ' . $m['location']['name'] : '');
    elseif ($t === 'reaction') $body = (string)($m['reaction']['emoji'] ?? '👍');
    else $body = '[' . $t . ']';
    return [$t, $body, $mid, $mime];
}

/** Stores one incoming message (one customer = one row, whichever number it came on). Returns the customer key. */
function wa_store_in(PDO $pdo, array $m, string $name, int $numberId): string
{
    $key = wa_key($m['from'] ?? ''); if ($key === '') return '';
    $now = wa_ts($m['timestamp'] ?? 0); [$t, $body, $mid, $mime] = wa_parse_msg($m);
    $ref = (array)($m['referral'] ?? []); $refTxt = $ref ? mb_substr(trim((string)($ref['headline'] ?? $ref['body'] ?? $ref['source_url'] ?? '')) ?: 'Meta ad', 0, 200) : '';   // the customer came from a Meta ad → a "Query"
    $q = $pdo->prepare("SELECT phone, source, last_in_at FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $ex = $q->fetch();
    if (!$ex) $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, last_in_at, last_number_id, state, status, source, ref_text) VALUES (?, ?, ?, ?, ?, ?, 'bot', 'open', ?, ?)")->execute([$key, (string)($m['from'] ?? ''), mb_substr($name, 0, 120), $now, $now, $numberId, $ref ? 'query' : '', $refTxt]);
    else {
        $pdo->prepare("UPDATE wa_contacts SET wa_id = ?, name = CASE WHEN name = '' THEN ? ELSE name END, last_in_at = ?, last_number_id = ?, status = 'open', closed_at = NULL WHERE phone = ?")->execute([(string)($m['from'] ?? ''), mb_substr($name, 0, 120), $now, $numberId, $key]);   // a closed chat re-opens when the customer writes again
        if ($ref && (string)$ex['source'] === '') $pdo->prepare("UPDATE wa_contacts SET source = 'query', ref_text = ? WHERE phone = ?")->execute([$refTxt, $key]);
    }
    try {
        $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, ts) VALUES (?, ?, ?, 'in', ?, ?, ?, ?, 'received', 'customer', ?)")
            ->execute([(string)($m['id'] ?? ''), $key, $numberId, mb_substr($t, 0, 16), $body, $mid, mb_substr($mime, 0, 60), $now]);
        $ctx = (string)($m['context']['id'] ?? ''); if ($ctx !== '') { try { $pdo->prepare("UPDATE wa_messages SET reply_to = ? WHERE wamid = ?")->execute([mb_substr($ctx, 0, 120), (string)($m['id'] ?? '')]); } catch (PDOException $e) { } }   // ↩ swiped reply: remember which message it answers
    } catch (PDOException $e) { /* the same wamid twice (Meta retries) — already stored */ }
    // STOP / unsubscribe words → opt out for every number
    if ($t === 'text' && preg_match('/^\s*(stop|unsubscribe|band karo|band kro|no more)\s*[.!]*\s*$/iu', $body)) $pdo->prepare("UPDATE wa_contacts SET optout = 1 WHERE phone = ?")->execute([$key]);
    if (function_exists('wa_source_hook')) wa_source_hook($pdo, $key, $ex ? (string)$ex['last_in_at'] : null, $body, $now);   // community link / reply to a campaign
    if (function_exists('wag_after_in')) wag_after_in($pdo, $key, $m, !$ex, $ref);   // growth engine: ad id, flows that start or continue
    if (function_exists('wq_after_in')) wq_after_in($pdo, $key, $m, !$ex, $ref);   // WhatsApp Sales: type the query (never blocks)
    if (function_exists('wch_after_in')) wch_after_in($pdo, $key, $m, !$ex, $ref);   // Chain cart: reads the customer's answer to the chain options (never blocks)
    if (function_exists('wa_origin_in')) wa_origin_in($pdo, $key, $m, $ex ? (string)$ex['last_in_at'] : null, $ref);   // 📍 a new enquiry: "where did you find us?" / reads the answer (never blocks)
    return $key;
}
/** 📱 A message from the WhatsApp Business app on the phone (coexistence): an echo of what a salesperson typed in the app (out), or a past message Meta shares (history, in/out).
 *  Stored once (same wamid = skipped). It never starts the bot, the follow-ups or the assignment — it only completes the chat. A history-only customer is added as a closed chat. */
function wa_store_phone_msg(PDO $pdo, array $m, string $key, int $numberId, string $dir, bool $history): string
{
    if ($key === '' || (string)($m['id'] ?? '') === '') return '';
    $ts = wa_ts($m['timestamp'] ?? 0); [$t, $body, $mid, $mime] = wa_parse_msg($m);
    $q = $pdo->prepare("SELECT phone, last_in_at, last_out_at FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $ex = $q->fetch();
    if (!$ex) { try { $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, last_in_at, last_out_at, last_number_id, state, status) VALUES (?, ?, '', ?, ?, ?, ?, 'human', ?)")
        ->execute([$key, $key, $ts, $dir === 'in' ? $ts : null, $dir === 'out' ? $ts : null, $numberId, $history ? 'closed' : 'open']); } catch (PDOException $e) { } }
    try {
        $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([(string)$m['id'], $key, $numberId, $dir, mb_substr($t, 0, 16), $body, $mid, mb_substr($mime, 0, 60), $dir === 'in' ? 'received' : 'sent', $dir === 'in' ? 'customer' : 'phone', $ts]);
    } catch (PDOException $e) { return ''; }   // already stored (Meta repeats) — nothing more to do
    if ($dir === 'out' && $ex && (empty($ex['last_out_at']) || (string)$ex['last_out_at'] < $ts)) $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$ts, $key]);
    if ($dir === 'in' && $ex && $history && (empty($ex['last_in_at']) || (string)$ex['last_in_at'] < $ts)) $pdo->prepare("UPDATE wa_contacts SET last_in_at = ? WHERE phone = ?")->execute([$ts, $key]);
    return $key;
}
function wa_status_rank(string $s): int { return ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4][$s] ?? 0; }

function wa_sig_ok(string $raw): bool
{
    $sec = wa_cfg_val('WA_APP_SECRET'); if ($sec === '') return false;
    $h = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''); if (stripos($h, 'sha256=') !== 0) return false;
    return hash_equals('sha256=' . hash_hmac('sha256', $raw, $sec), $h);
}

function wa_guard_admin(PDO $pdo, array $auth): void { if (!convo_is_admin($auth) && !ct_admin_ok($pdo, $auth)) require_role(['Admin', 'HR'], $auth); }
function wa_is_admin(PDO $pdo, array $auth): bool { return convo_is_admin($auth) || ct_admin_ok($pdo, $auth) || in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true); }
/** May this login read / write the chat? Admin / HR / control tower: all. A salesperson: only chats assigned to them. */
/** TAKING OVER CHATS — everyone in a Sales department may open the 👥 Team list (every live chat, filter by person, search) and TAKE OVER any chat
 *  ("Y took it from X"; X's copy locks at once). The admin only ticks the few people who must NOT (Team & rules → Team & routing → Taking over chats),
 *  so nobody has to be set up one by one. Complaint chats are never taken over by a salesperson. Watching = read only; replying needs Take over. */
function wa_sales_ids(PDO $pdo): array { static $s = null; if ($s !== null) return $s; $s = []; try { $s = array_map('intval', $pdo->query("SELECT e.employee_id FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { } return $s; }
function wa_to_blocked(PDO $pdo): array { static $b = null; if ($b !== null) return $b; $v = json_decode((string)setting_get($pdo, 'wa_to_block', '[]'), true); return $b = array_values(array_filter(array_map('intval', is_array($v) ? $v : []))); }
function wa_can_takeover(PDO $pdo, array $auth): bool { if (wa_is_admin($pdo, $auth)) return true; $e = (int)($auth['employee_id'] ?? 0); return $e > 0 && in_array($e, wa_sales_ids($pdo), true) && !in_array($e, wa_to_blocked($pdo), true); }
function wa_is_manager(PDO $pdo, array $auth): bool { return wa_can_takeover($pdo, $auth); }   // (old name, used by the team list)
function wa_can_chat(PDO $pdo, array $auth, array $contact): bool
{
    if (wa_is_admin($pdo, $auth)) return true;
    $e = (int)($auth['employee_id'] ?? 0); if ($e > 0 && (int)$contact['assigned_emp'] === $e) return true;
    return (int)($contact['cx_id'] ?? 0) > 0 && wa_cx_owner($pdo, $auth, $contact);   // 🎫 the complaint team member who owns this customer's open ticket uses the same chat box (voice notes, photos, "/", pre-made messages)
}
/** May READ this chat (the 👥 Team list): its holder, an admin, or anyone allowed to take over. Sending still needs wa_can_chat. */
function wa_can_watch(PDO $pdo, array $auth, array $contact): bool { return wa_can_chat($pdo, $auth, $contact) || wa_can_takeover($pdo, $auth); }
/** 🙋 A chat in the Requests list may be OPENED (read only) by any salesperson before taking it: 'req' = nobody has it yet, 'grab' = its person has not
 *  answered the customer for the late-reply minutes. '' = not a Requests chat. Sending still needs the chat (Take over). */
function wa_req_peek(PDO $pdo, array $c): string
{
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint' || (int)($c['optout'] ?? 0) === 1 || (string)($c['status'] ?? '') !== 'open') return '';
    $in = strtotime((string)($c['last_in_at'] ?? '')) ?: 0; if ($in < time() - 86400) return '';
    if ((int)($c['assigned_emp'] ?? 0) <= 0) return 'req';
    $out = strtotime((string)($c['last_out_at'] ?? '')) ?: 0; $min = function_exists('wq_late_min') ? wq_late_min($pdo) : (function_exists('wag_cfg') ? max(1, (int)(wag_cfg($pdo)['unans_min'] ?? 4)) : 4);
    return ($out < $in && $in <= time() - $min * 60) ? 'grab' : '';
}
/** Once: an index so "every chat this person ever had" stays fast. */
function wa_assign_idx(PDO $pdo): void
{
    static $d = false; if ($d) return; $d = true;
    try { if ((string)setting_get($pdo, 'wa_assign_idx', '') === '1') return; $pdo->exec("ALTER TABLE wa_assign_log ADD INDEX k_to (to_emp, phone)"); } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_assign_idx', '1'); } catch (Throwable $e) { }
}
/** 🔎 A phone search pattern: "+91 98791 94711", "919879194711" or "98791-94711" all find the chat (numbers are kept as the last 10 digits). */
function wa_q_like(string $q): string
{
    $q = trim($q); $d = preg_replace('/\D+/', '', $q);
    if (strlen($d) >= 10) return '%' . substr($d, -10) . '%';
    if (strlen($d) >= 4 && !preg_match('/\p{L}/u', $q)) return '%' . $d . '%';
    return '%' . addcslashes($q, '%_\\') . '%';
}
/** 🏷 The person's tag: true when this chat was ever given to $emp (the assign log keeps every move), so they may READ it even after someone else took it over. */
function wa_was_mine(PDO $pdo, string $key, int $emp): bool
{
    if ($emp <= 0 || $key === '') return false;
    try { $q = $pdo->prepare("SELECT 1 FROM wa_assign_log WHERE phone = ? AND to_emp = ? LIMIT 1"); $q->execute([$key, $emp]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** 🏷 First person each chat was given to (their name tag) + everyone it was with. [phone => ['first' => emp, 'all' => [emp, …]]] */
function wa_owner_tags(PDO $pdo, array $phones): array
{
    wa_assign_idx($pdo);
    $phones = array_values(array_unique(array_filter(array_map('strval', $phones)))); if (!$phones) return []; $o = [];
    try { $q = $pdo->prepare("SELECT phone, to_emp FROM wa_assign_log WHERE to_emp > 0 AND phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ") ORDER BY id"); $q->execute($phones);
        foreach ($q->fetchAll() as $r) { $k = (string)$r['phone']; $e = (int)$r['to_emp']; if (!isset($o[$k])) $o[$k] = ['first' => $e, 'all' => []]; $o[$k]['all'][$e] = 1; } } catch (Throwable $e) { }
    foreach ($o as &$x) $x['all'] = array_keys($x['all']); unset($x); return $o;
}
/** 🎫 The Complaint team may open a customer's photos / videos when that customer has a complaint ticket: the person who owns or raised one of
 *  this customer's tickets, or anyone who handled a ticket in the last 90 days (the Complaint team), for a customer with a ticket in the last 90 days. */
if (!function_exists('cx_can_media')) {
function cx_can_media(PDO $pdo, array $auth, array $phones): bool
{
    $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0 || !$phones) return false; $since = date('Y-m-d H:i:s', time() - 90 * 86400);
    try {
        $in = implode(',', array_fill(0, count($phones), '?'));
        $q = $pdo->prepare("SELECT owner_emp, raised_by FROM cx_cases WHERE phone IN ($in) AND raised_at >= ?"); $q->execute(array_merge(array_values($phones), [$since])); $rows = $q->fetchAll();
        if (!$rows) return false;
        foreach ($rows as $r) if ((int)$r['owner_emp'] === $me || (int)$r['raised_by'] === $me) return true;
        $t = $pdo->prepare("SELECT 1 FROM cx_cases WHERE owner_emp = ? AND raised_at >= ? LIMIT 1"); $t->execute([$me, $since]); if ($t->fetchColumn()) return true;
        $t = $pdo->prepare("SELECT 1 FROM cx_events WHERE emp_id = ? AND at >= ? LIMIT 1"); $t->execute([$me, $since]); return (bool)$t->fetchColumn();
    } catch (Throwable $e) { return false; }
}
}
/** ⚠ A chat with an OPEN complaint: the complaint team speaks to the customer (one voice). The salesperson keeps the chat, reads it and writes NOTES
 *  for the team (wa.cx_note). Returns '' when this person may send, otherwise the reason. Admins and the case owner may always send. */
function wa_cx_lock(PDO $pdo, array $auth, array $c): string
{
    $id = (int)($c['cx_id'] ?? 0); if ($id <= 0 || wa_is_admin($pdo, $auth)) return '';
    try { $q = $pdo->prepare("SELECT id, owner_emp, status FROM cx_cases WHERE id = ?"); $q->execute([$id]); $k = $q->fetch(); } catch (Throwable $e) { return ''; }
    if (!$k || !in_array((string)$k['status'], ['open', 'reopened'], true)) return '';
    if ((int)$k['owner_emp'] > 0 && (int)$k['owner_emp'] === (int)($auth['employee_id'] ?? 0)) return '';
    return 'This customer has an open complaint (C-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) . ') — the complaint team answers them now, so the customer hears one voice. Write a 📝 note for the team instead.';
}
/** The person who owns this chat's open complaint (the complaint team member) — they may message the customer even though the chat stays with the salesperson. */
function wa_cx_owner(PDO $pdo, array $auth, array $c): bool
{
    $id = (int)($c['cx_id'] ?? 0); $me = (int)($auth['employee_id'] ?? 0); if ($id <= 0 || $me <= 0) return false;
    try { $q = $pdo->prepare("SELECT owner_emp, status FROM cx_cases WHERE id = ?"); $q->execute([$id]); $k = $q->fetch(); } catch (Throwable $e) { return false; }
    return $k && (int)$k['owner_emp'] === $me && in_array((string)$k['status'], ['open', 'reopened'], true);
}
function wa_contact(PDO $pdo, string $key): ?array { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $r = $q->fetch(); return $r ?: null; }

/** Sends a text from the number the customer last used. Inside the 24-hour window only (templates come with the campaign step). */
function wa_send_text(PDO $pdo, array $c, string $text, string $who, int $empId, string $replyTo = ''): array   // $replyTo: the wamid of the customer's / our message this one answers (↩ swipe to reply)
{
    if ((int)$c['optout'] === 1) return [false, 'This customer opted out — do not message them.'];
    if ($empId === 0 && in_array($who, ['bot', 'auto', 'flow', 'campaign'], true) && function_exists('wa_auto_muted') && wa_auto_muted($pdo, (string)$c['phone'])) return [false, 'Order completed — automatic messages are off for this customer until tomorrow night.'];   // ✅ one lock for every engine
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return [false, 'The 24-hour reply window is closed. Only an approved template can be sent now.'];
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn();
    if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    if ((int)$c['last_number_id'] > 0) {   // WhatsApp's 24-hour window belongs to ONE number: a chat moved to another number has no window there yet → only a pre-made message can open it
        try { $wq = $pdo->prepare("SELECT SUM(number_id = ?) AS here, COUNT(*) AS tot FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ?"); $wq->execute([(int)$c['last_number_id'], $c['phone'], date('Y-m-d H:i:s', time() - 86400)]); $wr = $wq->fetch();
            if ($wr && (int)$wr['tot'] > 0 && (int)$wr['here'] === 0) return [false, 'This customer has not written to this number in the last 24 hours — send a pre-made message from it first (WhatsApp only allows that here).']; } catch (Throwable $e) { }
    }
    if ($who !== 'emp') {   // the bot / flows / auto-replies: never the same words to the same customer twice within a minute (two engines answering one message)
        try { $q = $pdo->prepare("SELECT wamid FROM wa_messages WHERE phone = ? AND dir = 'out' AND body = ? AND ts >= ? LIMIT 1"); $q->execute([$c['phone'], $text, date('Y-m-d H:i:s', time() - 60)]); $w = $q->fetchColumn(); if ($w !== false) return [true, (string)$w]; } catch (Throwable $e) { }
    }
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]] + ($replyTo !== '' ? ['context' => ['message_id' => $replyTo]] : []));
    $wamid = (string)($d['messages'][0]['id'] ?? '');
    $useNum = (int)$c['last_number_id'];
    if ($code < 200 || $code >= 300 || $wamid === '') {
        $e = wa_err($d); $ec = (int)($d['error']['code'] ?? 0); wa_log($pdo, 'send', false, $c['phone'] . ' · ' . $e);
        if ($ec === 131042) {   // Meta: the WhatsApp account of this number has a payment problem — every message from it fails
            $lab = ''; try { $q2 = $pdo->prepare("SELECT label, display FROM wa_numbers WHERE id = ?"); $q2->execute([$useNum]); $r2 = $q2->fetch(); $lab = $r2 ? trim((string)($r2['label'] ?: $r2['display'])) : ''; } catch (Throwable $e2) { }
            try { setting_set($pdo, 'wa_payblock_' . $useNum, date('Y-m-d H:i:s')); } catch (Throwable $e2) { }   // the Numbers page shows a red 'Payment problem' tag on this number
            $alt = null;   // another number the customer wrote to in the last 24 hours can still answer (WhatsApp allows free text only inside that window, per number)
            try { $q3 = $pdo->prepare("SELECT n.id, n.phone_id FROM wa_messages m JOIN wa_numbers n ON n.id = m.number_id WHERE m.phone = ? AND m.dir = 'in' AND m.number_id <> ? AND m.ts >= ? ORDER BY m.ts DESC LIMIT 1"); $q3->execute([$c['phone'], $useNum, date('Y-m-d H:i:s', time() - 86400)]); $alt = $q3->fetch() ?: null; } catch (Throwable $e2) { }
            if ($alt) {
                [$code2, $d2] = wa_api('POST', '/' . $alt['phone_id'] . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]] + ($replyTo !== '' ? ['context' => ['message_id' => $replyTo]] : []));
                $wamid2 = (string)($d2['messages'][0]['id'] ?? '');
                if ($code2 >= 200 && $code2 < 300 && $wamid2 !== '') { $wamid = $wamid2; $useNum = (int)$alt['id']; $replyTo = ''; $pdo->prepare("UPDATE wa_contacts SET last_number_id = ? WHERE phone = ?")->execute([$useNum, $c['phone']]); wa_log($pdo, 'send', true, $c['phone'] . ' · answered from another number because ' . ($lab !== '' ? $lab : 'the first number') . ' has a payment problem'); }
            }
            if ($wamid === '') return [false, 'Payment problem on ' . ($lab !== '' ? '“' . $lab . '”' : 'this number') . ' (Meta code 131042): its WhatsApp account has no working payment method. Fix it in Meta → WhatsApp Manager → that account → Settings → Payment settings. This customer has not written to another number in the last 24 hours, so it cannot be answered from another number yet.'];
        } else return [false, $e];
    }
    $now = date('Y-m-d H:i:s');
    try { if ((string)setting_get($pdo, 'wa_payblock_' . $useNum, '') !== '') setting_set($pdo, 'wa_payblock_' . $useNum, ''); } catch (Throwable $e2) { }   // a message went out: the payment problem is gone
    $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'text', ?, 'sent', ?, ?, ?)")->execute([$wamid, $c['phone'], $useNum, $text, $who, $empId, $now]);
    if ($replyTo !== '') { try { $pdo->prepare("UPDATE wa_messages SET reply_to = ? WHERE wamid = ?")->execute([mb_substr($replyTo, 0, 120), $wamid]); } catch (Throwable $e) { } }
    $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$now, $c['phone']]);
    return [true, $wamid];
}

/** Answer the browser now and keep working (LiteSpeed on Hostinger / PHP-FPM). Without either, it is a normal answer at the end. */
function wa_reply_early(array $data): void
{
    if (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')) { echo json_encode($data); return; }
    http_response_code(200); if (!headers_sent()) header('Content-Type: application/json'); echo json_encode($data);
    ignore_user_abort(true); if (function_exists('litespeed_finish_request')) litespeed_finish_request(); else fastcgi_finish_request();
}
/** Duplicate stop for wa.send. Returns the wamid of the first send when this is a repeat, else null (and claims the tap id). */
function wa_send_dup(PDO $pdo, string $key, string $text, string $cid): ?string
{
    try {
        if (!(function_exists('schema_done') && schema_done($pdo, 'wa_send_ids', __FILE__))) { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_send_ids (cid VARCHAR(40) NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL, wamid VARCHAR(120) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); if (function_exists('schema_mark')) schema_mark($pdo, 'wa_send_ids', __FILE__); }
        if ($cid !== '') {
            $q = $pdo->prepare("INSERT IGNORE INTO wa_send_ids (cid, phone, at) VALUES (?, ?, ?)"); $q->execute([$cid, $key, date('Y-m-d H:i:s')]);
            if ($q->rowCount() === 0) { $w = $pdo->prepare("SELECT wamid FROM wa_send_ids WHERE cid = ?"); $w->execute([$cid]); return (string)$w->fetchColumn() ?: 'pending'; }   // the same tap again
            if (mt_rand(1, 200) === 1) $pdo->exec("DELETE FROM wa_send_ids WHERE at < '" . date('Y-m-d H:i:s', time() - 2 * 86400) . "'");
        }
        $q = $pdo->prepare("SELECT wamid FROM wa_messages WHERE phone = ? AND dir = 'out' AND body = ? AND ts >= ? ORDER BY id DESC LIMIT 1"); $q->execute([$key, $text, date('Y-m-d H:i:s', time() - 20)]);
        $w = $q->fetchColumn(); if ($w !== false) { wa_send_claim_done($pdo, $cid, (string)$w); return (string)$w; }   // the same words to the same customer a moment ago
    } catch (Throwable $e) { }
    return null;
}
function wa_send_claim_done(PDO $pdo, string $cid, string $wamid): void { if ($cid === '') return; try { $pdo->prepare("UPDATE wa_send_ids SET wamid = ? WHERE cid = ?")->execute([$wamid, $cid]); } catch (Throwable $e) { } }
function wa_send_claim_drop(PDO $pdo, string $cid): void { if ($cid === '') return; try { $pdo->prepare("DELETE FROM wa_send_ids WHERE cid = ?")->execute([$cid]); } catch (Throwable $e) { } }   // it failed: a retry with the same id may go

if (!defined('WA_MAX_OPEN')) define('WA_MAX_OPEN', 8);   // one salesperson holds at most 8 open chats
if (!defined('WA_TAGS')) define('WA_TAGS', ['price' => 'Price', 'buy' => 'Wants to buy', 'order_status' => 'Order status', 'complaint' => 'Complaint', 'other' => 'Other']);

/** Sales people (department name contains "sales"), who is present today, how many open chats each has — best pick first. */
function wa_people(PDO $pdo, int $numId = 0): array
{
    $maxOpen = function_exists('wa_team_cfg') ? (int)wa_team_cfg($pdo)['max_open'] : WA_MAX_OPEN;
    $handlers = []; if ($numId > 0) { $hq = $pdo->prepare("SELECT handlers FROM wa_numbers WHERE id = ?"); try { $hq->execute([$numId]); $handlers = array_values(array_filter(array_map('intval', explode(',', (string)$hq->fetchColumn())))); } catch (PDOException $e) { } }   // the people who handle this number come first
    $stats = function_exists('wa_person_stats') ? wa_person_stats($pdo) : [];
    $rows = $pdo->query("SELECT e.employee_id, e.full_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%' ORDER BY e.full_name")->fetchAll();
    $since = date('Y-m-d H:i:s', time() - 20 * 3600); $out = [];   // same rule as attendance_open_row(): punched in within 20 h, not out yet
    foreach ($rows as $r) {
        $id = (int)$r['employee_id'];
        $q = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ?"); $q->execute([$id, $since]); $present = (int)$q->fetchColumn() > 0;   // open shift of the last 20 h, not "today's row": shifts run past midnight
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = ? AND status = 'open'"); $q->execute([$id]); $open = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT MAX(at) FROM wa_assign_log WHERE to_emp = ?"); $q->execute([$id]); $last = (string)$q->fetchColumn();
        $out[] = ['emp_id' => $id, 'name' => (string)$r['full_name'], 'present' => $present, 'open' => $open, 'full' => $open >= $maxOpen, 'last_assigned' => $last, 'handler' => in_array($id, $handlers, true), 'median' => $stats[$id]['median'] ?? null];
    }
    // present first, not full first, fewest open chats, then the one who has waited longest since the last assignment
    // present first, not full first, the number's own handlers, fewest open chats, the quicker replier (median minutes), then the one who has waited longest since the last assignment
    usort($out, fn($a, $b) => [(int)$b['present'], (int)!$b['full'], (int)$b['handler'], $a['open'], $a['median'] ?? 99999, $a['last_assigned'], $a['name']] <=> [(int)$a['present'], (int)!$a['full'], (int)$a['handler'], $b['open'], $b['median'] ?? 99999, $b['last_assigned'], $b['name']]);
    $best = true;
    foreach ($out as &$o) {
        $o['best'] = $best && $o['present'] && !$o['full']; if ($o['best']) $best = false;
        $o['why'] = !$o['present'] ? 'Offline · not punched in' : ($o['full'] ? 'Has ' . $o['open'] . ' open chats (full)' : ($o['best'] ? 'Present · ' . ($o['handler'] ? 'handles this number · ' : '') . 'fewest open chats (' . $o['open'] . ')' : 'Present · ' . ($o['handler'] ? 'handles this number · ' : '') . $o['open'] . ' open chat' . ($o['open'] === 1 ? '' : 's')));
    } unset($o);
    return $out;
}
/** Everyone who is not in sales (for an admin's Assign search): at work or not, open chats. Sorted: at work first, then by name. */
function wa_people_others(PDO $pdo, array $skip): array
{
    $maxOpen = function_exists('wa_team_cfg') ? (int)wa_team_cfg($pdo)['max_open'] : WA_MAX_OPEN; $since = date('Y-m-d H:i:s', time() - 20 * 3600); $out = [];
    try { $rows = $pdo->query("SELECT employee_id, full_name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll(); } catch (PDOException $e) { return []; }
    $pq = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ?");
    $oq = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = ? AND status = 'open'");
    foreach ($rows as $r) {
        $id = (int)$r['employee_id']; if (in_array($id, $skip, true)) continue;
        $present = false; try { $pq->execute([$id, $since]); $present = (int)$pq->fetchColumn() > 0; } catch (PDOException $e) { }
        $oq->execute([$id]); $open = (int)$oq->fetchColumn();
        $out[] = ['emp_id' => $id, 'name' => (string)$r['full_name'], 'present' => $present, 'open' => $open, 'full' => $open >= $maxOpen, 'best' => false, 'other' => true, 'why' => $present ? 'Online · not in sales' : 'Offline · not in sales'];
    }
    usort($out, fn($a, $b) => [(int)$b['present'], $a['name']] <=> [(int)$a['present'], $b['name']]);
    return $out;
}
function wa_counts(PDO $pdo, bool $adm, int $emp): array
{
    $one = function (string $where, array $p = []) use ($pdo) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE $where"); $q->execute($p); return (int)$q->fetchColumn(); };
    $wait = "last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) AND optout = 0 AND status = 'open'";
    if ($emp <= 0) $emp = -1;   // an admin login that is not an employee has no chats of their own
    if (!$adm) return ['mine' => $one("assigned_emp = ? AND status = 'open'", [$emp]), 'closed' => $one("assigned_emp = ? AND status = 'closed'", [$emp]), 'waiting' => $one("assigned_emp = ? AND $wait", [$emp])];
    return ['mine' => $one("assigned_emp = ? AND status = 'open'", [$emp]), 'unassigned' => $one("assigned_emp = 0 AND status = 'open' AND last_in_at IS NOT NULL"), 'all' => $one("status = 'open' AND last_in_at IS NOT NULL"), 'closed' => $one("status = 'closed' AND last_in_at IS NOT NULL"), 'waiting' => $one($wait)];
}
/** Stage B: open chats per feed (query / assigned / community / campaign) — the whole team for an admin, only your own for a salesperson. */
/** When the bot's rules (Teach by talking / facts) were last changed, so an open WhatsApp inbox can refresh its suggestions on the spot. */
function wa_rules_state(PDO $pdo): array
{
    $o = ['at' => '', 'age' => 0, 'lines' => []];
    try {
        $r = $pdo->query("SELECT MAX(updated_at) FROM lib_facts WHERE k IN ('style', 'prices', 'offers', 'rules', 'budget', 'pl', 'limits')")->fetchColumn();
        $kb = function_exists('setting_get') ? json_decode((string)setting_get($pdo, 'kb_changed', ''), true) : null;   // prices / store info / company brain / products (kb_touch)
        if (is_array($kb) && !empty($kb['at']) && (!$r || strcmp((string)$kb['at'], (string)$r) > 0)) { $r = (string)$kb['at']; foreach (array_slice((array)($kb['what'] ?? []), 0, 3) as $w) $o['lines'][] = 'Updated: ' . $w; }
        if (!$r) return $o;
        $o['at'] = (string)$r; $o['age'] = max(0, time() - (int)strtotime((string)$r));
        $b = (string)$pdo->query("SELECT body FROM lib_facts WHERE k = 'style'")->fetchColumn();
        $sl = []; foreach (array_reverse(preg_split('/\R/u', trim($b))) as $l) if (preg_match('/^\s*\d+[.)]\s*(.+)$/u', $l, $m)) { $sl[] = mb_substr(trim($m[1]), 0, 140); if (count($sl) >= 3) break; }
        $o['lines'] = array_slice(array_merge($o['lines'], array_reverse($sl)), 0, 4);
    } catch (Throwable $e) { }
    return $o;
}
/** When this business day started (10:00 → 10:00, the same day as attendance), as 'Y-m-d H:i:s'. */
function wa_bizday_start(PDO $pdo): string
{
    $now = time(); $day = function_exists('biz_day_of') ? biz_day_of($pdo, $now) : (date('H') < 10 ? date('Y-m-d', $now - 86400) : date('Y-m-d', $now));
    $st = function_exists('day_start_for') ? (string)day_start_for($pdo, $day) : '10:00'; if (!preg_match('/^\d{1,2}:\d{2}$/', $st)) $st = '10:00';
    return date('Y-m-d H:i:s', strtotime($day . ' ' . $st . ':00'));
}
function wa_src_counts(PDO $pdo, bool $adm, int $emp): array
{
    $base = "status = 'open' AND last_in_at IS NOT NULL" . ($adm ? '' : ' AND assigned_emp = ' . ($emp > 0 ? $emp : -1)); $o = [];
    try {
        foreach (['community', 'campaign'] as $s) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE $base AND source = ?"); $q->execute([$s]); $o[$s] = (int)$q->fetchColumn(); }
        if (function_exists('wa_ad_backfill')) wa_ad_backfill($pdo); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE $base AND source = 'query' AND last_in_at >= ?"); $q->execute([wa_bizday_start($pdo)]); $o['query'] = (int)$q->fetchColumn();
        $o['assigned'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE $base AND assigned_emp > 0")->fetchColumn();
        // 📱 queries of this business day per WhatsApp number (TM Perfume House Main / Second …) — a permanent row of chips beside Query / Assigned / Community / Campaign
        // since the number was deployed: every customer who EVER wrote to the number (all-time, open or closed). Counting them is heavy, so it is cached for 5 minutes.
        $per = []; $cache = json_decode((string)setting_get($pdo, 'wa_numq_cache', ''), true);
        if (is_array($cache) && (int)($cache['t'] ?? 0) > time() - 300 && is_array($cache['v'] ?? null)) { foreach ($cache['v'] as $k => $v) $per[(int)$k] = (int)$v; }
        else { foreach ($pdo->query("SELECT number_id, COUNT(DISTINCT phone) n FROM wa_messages WHERE dir = 'in' AND number_id > 0 GROUP BY number_id")->fetchAll() as $r) $per[(int)$r['number_id']] = (int)$r['n']; setting_set($pdo, 'wa_numq_cache', json_encode(['t' => time(), 'v' => $per])); }
        $o['nums'] = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) $o['nums'][] = ['id' => (int)$n['id'], 'label' => trim((string)$n['label']) !== '' ? (string)$n['label'] : (string)$n['display'], 'n' => $per[(int)$n['id']] ?? 0];
    } catch (Throwable $e) { }
    return $o;
}
/** ↩ Swiped replies: each message that answers an earlier one gets 'quote' = that message (id, dir, type, a short text), so the chat can show it on top and jump to it. */
function wa_add_quotes(PDO $pdo, array &$msgs): void
{
    $ids = []; foreach ($msgs as $m) if (!empty($m['reply_to'])) $ids[(string)$m['reply_to']] = 1; if (!$ids) return; $ids = array_keys($ids); $map = [];
    try { $q = $pdo->prepare("SELECT id, wamid, dir, mtype, body FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $q->execute($ids);
        foreach ($q->fetchAll() as $r) { $b = trim((string)$r['body']); $lab = ['image' => '📷 Photo', 'video' => '🎥 Video', 'audio' => '🎤 Voice note', 'voice' => '🎤 Voice note', 'document' => '📄 Document', 'sticker' => '🙂 Sticker', 'template' => ''][(string)$r['mtype']] ?? '';
            $map[(string)$r['wamid']] = ['id' => (int)$r['id'], 'dir' => (string)$r['dir'], 'text' => mb_substr($b !== '' ? ($lab !== '' && $lab !== $b ? $lab . ' · ' . $b : $b) : ($lab !== '' ? $lab : 'Message'), 0, 160)]; } } catch (Throwable $e) { }
    foreach ($msgs as &$m) { if (!empty($m['reply_to'])) $m['quote'] = $map[(string)$m['reply_to']] ?? ['id' => 0, 'dir' => '', 'text' => 'An earlier message']; } unset($m);
}
/** A line in the chat timeline ("Given to Priya by Admin") — not a WhatsApp message, never sent to the customer. */
function wa_event(PDO $pdo, string $key, string $text): void
{
    try { $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, ts) VALUES (?, ?, 0, 'sys', 'event', ?, 'sent', 'system', ?)")->execute(['sys.' . uniqid('', true), $key, mb_substr($text, 0, 300), date('Y-m-d H:i:s')]); } catch (PDOException $e) { }
}
/** 🔔 In-app notices for a salesperson (e.g. "Rahul took over Priya's chat"): one row per event, shown to that person only, until they close it. */
function wa_notice_table(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_notices (id INT AUTO_INCREMENT PRIMARY KEY, to_emp INT NOT NULL, by_emp INT NOT NULL DEFAULT 0, phone VARCHAR(12) NOT NULL DEFAULT '', cname VARCHAR(120) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL DEFAULT '', at DATETIME NOT NULL, seen TINYINT NOT NULL DEFAULT 0, KEY k_to (to_emp, seen, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}
function wa_notice_add(PDO $pdo, int $to, int $by, string $phone, string $cname, string $kind): void
{
    if (!wa_notice_table($pdo)) return;
    try { $pdo->prepare("INSERT INTO wa_notices (to_emp, by_emp, phone, cname, kind, at, seen) VALUES (?, ?, ?, ?, ?, ?, 0)")->execute([$to, $by, $phone, mb_substr($cname, 0, 120), $kind, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
}
function wa_assign_to(PDO $pdo, string $key, int $emp, int $by, string $how): void
{
    $c = wa_contact($pdo, $key); if (!$c) return; $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ?, assigned_at = ?, state = ?, status = 'open', closed_at = NULL WHERE phone = ?")->execute([$emp, $emp > 0 ? $now : null, $emp > 0 ? 'human' : 'unassigned', $key]);
    $pdo->prepare("INSERT INTO wa_assign_log (phone, from_emp, to_emp, by_emp, how, at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$key, (int)$c['assigned_emp'], $emp, $by, $how, $now]);
    $nm = employee_names_by_id($pdo); $to = $emp > 0 ? ($nm[$emp] ?? 'someone') : ''; $from = (int)$c['assigned_emp'] > 0 ? ($nm[(int)$c['assigned_emp']] ?? 'someone') : ''; $who = $by > 0 ? ($nm[$by] ?? 'Admin') : ($how === 'auto' ? 'the system (automatic)' : 'Admin');
    if (function_exists('wag_q_moved')) wag_q_moved($pdo, $key, (int)$c['assigned_emp'], $emp);   // the query ledger follows the chat
    if (function_exists('wq_after_assign')) wq_after_assign($pdo, $key, (int)$c['assigned_emp'], $emp, $by, $how);   // WhatsApp Sales: clears Attention needed
    if (function_exists('wa_callnow_open')) wa_callnow_open($pdo, $key, $emp, $by);   // the "call this customer now" alert
    // 🔔 someone took this chat from its holder (take over / grab): the holder sees a small notice at the top of THEIR WhatsApp (only theirs)
    if ($emp > 0 && (int)$c['assigned_emp'] > 0 && (int)$c['assigned_emp'] !== $emp && $by === $emp && in_array($how, ['takeover', 'grab'], true)) wa_notice_add($pdo, (int)$c['assigned_emp'], $emp, $key, (string)($c['name'] ?? ''), $how);
    wa_event($pdo, $key, $emp <= 0 ? 'Taken off ' . ($from ?: 'the team') . ' by ' . $who : ($how === 'transfer' ? $from . ' transferred this chat to ' . $to : ($from !== '' ? 'Moved from ' . $from . ' to ' . $to . ' by ' . $who : 'Given to ' . $to . ' by ' . $who)));
    if ($emp > 0 && $emp !== $by && function_exists('ct_push')) { try { ct_push($pdo, [$emp], 'New WhatsApp chat', trim(($c['name'] !== '' ? $c['name'] : '+91 ' . $key) . ' was given to you.'), '/my_chats.html?phone=' . $key, 'wa-' . $key); } catch (Throwable $e) { } }
}
/** Claude reads the last messages and writes a tag + a 1–2 line summary. Skipped when nothing new came in since the last time (unless $force). */
function wa_tag_contact(PDO $pdo, array $c, bool $force): array
{
    $last = $c['last_in_at'] ? strtotime((string)$c['last_in_at']) : 0;
    if (!$force && $c['tag'] !== '' && $c['tagged_at'] && strtotime((string)$c['tagged_at']) >= $last) return [true, ['tag' => $c['tag'], 'summary' => $c['summary'], 'fresh' => false]];
    if (!function_exists('convo_ai')) return [false, 'The AI is not available.'];
    $st = $pdo->prepare("SELECT dir, body, ts FROM wa_messages WHERE phone = ? ORDER BY id DESC LIMIT 30"); $st->execute([$c['phone']]); $rows = array_reverse($st->fetchAll());
    if (!$rows) return [false, 'No messages yet.'];
    $txt = ''; foreach ($rows as $r) $txt .= ($r['dir'] === 'in' ? 'CUSTOMER' : 'SHOP') . ': ' . mb_substr((string)$r['body'], 0, 400) . "\n";
    if ($c['ref_text'] !== '') $txt = "(The customer came from a Meta ad: " . $c['ref_text'] . ")\n" . $txt;
    $sys = "You read a WhatsApp chat between a customer and a perfume shop (perfumes, attars, shower gel, wax). The customer may write in English, Hindi, Gujarati or a mix. Give: tag = the ONE best label of price (asking price/discount), buy (ready to order or choosing a product), order_status (asks about delivery or an existing order), complaint (unhappy, return, refund, damaged), other. summary = at most 2 short plain-English sentences: what the customer wants and where the chat stands. Use only what is in the chat.";
    $schema = ['type' => 'OBJECT', 'properties' => ['tag' => ['type' => 'STRING', 'enum' => array_keys(WA_TAGS)], 'summary' => ['type' => 'STRING']], 'required' => ['tag', 'summary']];
    try { [$ok, $v, $why] = convo_ai($sys, [['text' => "The chat:\n" . $txt]], $schema, false, 1200, 'claude'); } catch (Throwable $e) { return [false, 'The AI could not read this chat.']; }
    if (!$ok || !is_array($v)) return [false, (string)($why ?: 'The AI could not read this chat.')];
    $tag = isset(WA_TAGS[(string)($v['tag'] ?? '')]) ? (string)$v['tag'] : 'other'; $sum = mb_substr(trim((string)($v['summary'] ?? '')), 0, 600);
    $pdo->prepare("UPDATE wa_contacts SET tag = ?, summary = ?, tagged_at = ? WHERE phone = ?")->execute([$tag, $sum, date('Y-m-d H:i:s'), $c['phone']]);
    return [true, ['tag' => $tag, 'summary' => $sum, 'fresh' => true]];
}
/** The soft colour of a chat row, from live data. hot (ready to buy) · late (customer waiting longer than the alert) · active (talking now)
 *  · slow (we answered, the customer went quiet 3 h+) · cx (complaint) · biz (an auto-reply from another business). '' = nothing to show. */
function wa_heat(array $r, array $flags = [], int $lateMin = 5): string
{
    $now = time(); $in = !empty($r['last_in_at']) ? strtotime((string)$r['last_in_at']) : 0; $o = !empty($r['last_out_at']) ? strtotime((string)$r['last_out_at']) : 0;
    if (!empty($flags['biz'])) return 'biz';
    if ((int)($r['cx_id'] ?? 0) > 0 || (string)($r['tag'] ?? '') === 'complaint' || !empty($flags['cx'])) return 'cx';
    if ((int)($r['hot'] ?? 0) === 1 || (string)($r['tag'] ?? '') === 'buy' || !empty($flags['hot'])) return 'hot';
    $waiting = $in > $o && (int)($r['optout'] ?? 0) === 0 && $in > $now - 86400;
    if ($waiting && $now - $in >= $lateMin * 60) return 'late';
    if ($waiting || ($in && $now - $in < 3 * 3600)) return 'active';
    if ($o > $in && $now - $o >= 3 * 3600 && $in > $now - 7 * 86400) return 'slow';
    return '';
}
/** Which of these chats are a business auto-reply / a complaint, from the customer tags (one query for the whole list). */
function wa_heat_flags(PDO $pdo, array $phones): array
{
    $out = []; $phones = array_values(array_unique(array_filter(array_map('strval', $phones)))); if (!$phones) return $out;
    try {
        $st = $pdo->prepare("SELECT phone, tag FROM wa_ctags WHERE tag IN ('business_autoreply', 'complaint', 'hot') AND phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ")"); $st->execute($phones);
        foreach ($st->fetchAll() as $t) $out[(string)$t['phone']][$t['tag'] === 'business_autoreply' ? 'biz' : ($t['tag'] === 'complaint' ? 'cx' : 'hot')] = true;
    } catch (Throwable $e) { }
    return $out;
}
function wa_contact_row(array $r, array $names): array
{
    $in = $r['last_in_at'] ? strtotime((string)$r['last_in_at']) : 0; $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
    return ['phone' => $r['phone'], 'name' => $r['name'], 'assigned_emp' => (int)$r['assigned_emp'], 'assigned_name' => $names[(int)$r['assigned_emp']] ?? '', 'state' => $r['state'], 'status' => $r['status'], 'optout' => (int)$r['optout'] === 1,
        'source' => $r['source'], 'ref_text' => $r['ref_text'], 'unread' => (int)($r['unread'] ?? 0), 'tag' => $r['tag'], 'tag_label' => WA_TAGS[$r['tag']] ?? '', 'summary' => $r['summary'],
        'hot' => (int)($r['hot'] ?? 0), 'hot_age' => !empty($r['hot_at']) ? max(0, time() - (int)strtotime((string)$r['hot_at'])) : 0, 'remind_at' => $r['remind_at'] ?? null, 'pay_at' => $r['pay_at'] ?? null,
        'last_at' => $r['last_in_at'] ?: $r['last_out_at'], 'waiting' => $in > 0 && $in > $o && (int)$r['optout'] === 0, 'window_open' => $in > time() - 86400, 'window_left_min' => $in > time() - 86400 ? (int)floor(($in + 86400 - time()) / 60) : 0];
}

// ------------------------------------------------------------------------------------------------------------------------ router
$__wa = (string)($action ?? ($_GET['action'] ?? ''));
if (preg_match('/^(wa|fu|learn|wq|wch|wl|wmo)\./', $__wa)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    require_once __DIR__ . '/wa_tpl.php';   // pre-made messages (templates): defines its helpers and answers the wa.tpl_* actions
    require_once __DIR__ . '/wa_console.php';   // the WhatsApp console: wa.overview (counters + number health) and wa.send_media (photo / video / document from a chat)
    require_once __DIR__ . '/wa_more.php';   // numbers & profile, contacts, team & routing, media library, follow-ups, campaigns, reports (wa_media.php + wa_camp.php come with it)

    // ---- Meta calls this one without a login
    if ($__wa === 'wa.webhook') {
        if (!wa_tables($pdo)) wa_raw('storage not ready', 500);
        if (!$isPost) {   // "verify" handshake
            $vt = wa_cfg_val('WA_VERIFY_TOKEN');
            if (($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && $vt !== '' && hash_equals($vt, (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) {
                wa_log($pdo, 'verify', true, 'Meta verified the webhook.'); wa_raw((string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''));
            }
            wa_log($pdo, 'verify', false, 'Verify failed — the verify token does not match WA_VERIFY_TOKEN.'); wa_raw('forbidden', 403);
        }
        if (!defined('WA_IN_WEBHOOK')) define('WA_IN_WEBHOOK', true);   // heavy AI work waits for the next tick, never inside Meta's request
        $raw = wa_body();
        if (!wa_sig_ok($raw)) { wa_log($pdo, 'webhook', false, 'Rejected: the signature does not match WA_APP_SECRET.'); wa_raw('bad signature', 403); }
        $j = json_decode($raw, true); $n = 0; $st = 0; $__keys = [];
        try {
            foreach ((array)($j['entry'] ?? []) as $en) foreach ((array)($en['changes'] ?? []) as $ch) {
                $v = (array)($ch['value'] ?? []); $md = (array)($v['metadata'] ?? []);
                if (($ch['field'] ?? '') === 'message_template_status_update') { if (wa_tpl_tables($pdo)) wa_tpl_status_event($pdo, $v); continue; }   // Meta approved / rejected a pre-made message
                if (($ch['field'] ?? '') === 'template_category_update') { if (wa_tpl_tables($pdo)) wa_tpl_category_event($pdo, $v); continue; }   // Meta changed the category of a pre-made message (Utility → Marketing: the mix stops using it as Utility)
                if (($ch['field'] ?? '') === 'message_template_quality_update') { if (wa_tpl_tables($pdo)) wa_tpl_quality_event($pdo, $v); continue; }   // the quality rating of a pre-made message changed
                $nid = wa_number_id($pdo, (string)($md['phone_number_id'] ?? ''), (string)($md['display_phone_number'] ?? ''));
                // 📱 the WhatsApp Business APP on the phone (coexistence): messages a salesperson types in the app (echoes) and the chat history Meta shares — kept like any other message
                if (($ch['field'] ?? '') === 'smb_message_echoes') { foreach ((array)($v['message_echoes'] ?? []) as $m) { $k = wa_store_phone_msg($pdo, (array)$m, wa_key($m['to'] ?? ''), $nid, 'out', false); if ($k !== '') { $n++; } } continue; }
                if (($ch['field'] ?? '') === 'history') { foreach ((array)($v['history'] ?? []) as $hh) foreach ((array)($hh['threads'] ?? []) as $th) { $ck = wa_key($th['id'] ?? ''); if ($ck === '') continue;
                    foreach ((array)($th['messages'] ?? []) as $m) { $out = wa_key($m['from'] ?? '') !== $ck; if (wa_store_phone_msg($pdo, (array)$m, $ck, $nid, $out ? 'out' : 'in', true) !== '') $n++; } } continue; }
                $names = []; foreach ((array)($v['contacts'] ?? []) as $ct) $names[wa_key($ct['wa_id'] ?? '')] = (string)($ct['profile']['name'] ?? '');
                foreach ((array)($v['messages'] ?? []) as $m) { $k = wa_store_in($pdo, (array)$m, $names[wa_key($m['from'] ?? '')] ?? '', $nid); if ($k !== '') { $n++; $__keys[$k] = true; } }
                foreach ((array)($v['statuses'] ?? []) as $s) {
                    $new = (string)($s['status'] ?? ''); $id = (string)($s['id'] ?? ''); if ($id === '' || wa_status_rank($new) === 0) continue;
                    $q = $pdo->prepare("SELECT status FROM wa_messages WHERE wamid = ?"); $q->execute([$id]); $old = $q->fetchColumn();
                    $e0 = (array)($s['errors'][0] ?? []); $ecode = (int)($e0['code'] ?? 0);   // why a message failed: Meta's first error (code + plain text) — kept on the message and on the campaign person
                    $et = trim((string)($e0['title'] ?? '')); $em = trim((string)($e0['message'] ?? '')); if ($em === '') $em = trim((string)($e0['error_data']['details'] ?? ''));
                    if ($new === 'failed') wa_fail_raw($pdo, 'status', wa_key($s['recipient_id'] ?? ''), $id, $ecode, $s); $etext = mb_substr($et !== '' && $em !== '' && $et !== $em ? $et . ' — ' . $em : ($em !== '' ? $em : $et), 0, 190);
                    if ($old !== false && wa_status_rank($new) > wa_status_rank((string)$old)) {
                        if ($new === 'failed') { try { $pdo->prepare("UPDATE wa_messages SET status = ?, err = ?, err_code = ? WHERE wamid = ?")->execute([$new, $etext, $ecode, $id]); } catch (PDOException $e) { $pdo->prepare("UPDATE wa_messages SET status = ? WHERE wamid = ?")->execute([$new, $id]); } }
                        else $pdo->prepare("UPDATE wa_messages SET status = ? WHERE wamid = ?")->execute([$new, $id]);
                        $st++;
                    }
                    if (!empty($s['pricing']) && is_array($s['pricing'])) {   // 💰 what Meta bills for this message: its category and whether it is charged (Billing screen)
                        $pc = mb_substr(strtolower((string)($s['pricing']['category'] ?? '')), 0, 16); $pt = strtolower((string)($s['pricing']['type'] ?? ''));
                        $pb = strpos($pt, 'free') === 0 ? 0 : (array_key_exists('billable', $s['pricing']) ? ((bool)$s['pricing']['billable'] ? 1 : 0) : null);
                        $sqlB = "UPDATE wa_messages SET bill_cat = ?, billable = ? WHERE wamid = ?";
                        try { $pdo->prepare($sqlB)->execute([$pc, $pb, $id]); }
                        catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_messages ADD COLUMN bill_cat VARCHAR(16) NOT NULL DEFAULT ''"); } catch (PDOException $e2) { } try { $pdo->exec("ALTER TABLE wa_messages ADD COLUMN billable TINYINT NULL"); } catch (PDOException $e2) { } try { $pdo->prepare($sqlB)->execute([$pc, $pb, $id]); } catch (PDOException $e2) { } }
                    }
                    try {   // campaign people: keep the latest delivery state (and the reason when it failed) on the person, so the retry engine and the "who got what" list can use it
                        if (function_exists('wa_more_tables')) wa_more_tables($pdo);
                        $skip = $new === 'sent' ? "'sent', 'delivered', 'read', 'failed'" : ($new === 'delivered' ? "'delivered', 'read', 'failed'" : ($new === 'read' ? "'read', 'failed'" : "'failed'"));
                        if ($new === 'failed') $pdo->prepare("UPDATE wa_camp_items SET last_status = 'failed', err = ?, err_code = ? WHERE wamid = ? AND last_status <> 'failed'")->execute([$etext !== '' ? $etext : 'Meta could not deliver this message.', $ecode, $id]);
                        else $pdo->prepare("UPDATE wa_camp_items SET last_status = ? WHERE wamid = ? AND last_status NOT IN ($skip)")->execute([$new, $id]);
                    } catch (Throwable $e) { }
                    if ($new === 'failed') wa_log($pdo, 'status', false, wa_key($s['recipient_id'] ?? '') . ' · ' . ((string)($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'message failed')) . ($ecode ? ' (code ' . $ecode . ')' : ''));
                }
            }
            if ($n && function_exists('wa_tick')) { try { wa_more_tables($pdo); wa_tick($pdo); } catch (Throwable $e) { } }   // assignment + call-now alerts react right away
            if ($n || $st) wa_log($pdo, 'webhook', true, $n . ' message' . ($n === 1 ? '' : 's') . ', ' . $st . ' status update' . ($st === 1 ? '' : 's'));
        } catch (Throwable $e) { wa_log($pdo, 'webhook', false, 'Error: ' . $e->getMessage()); }
        if ($__keys && function_exists('wag_prebuild')) {   // write the reply for each waiting customer NOW, after Meta has its 200 — it is ready before the salesperson even opens the chat
            $__k = array_slice(array_keys($__keys), 0, 5);
            register_shutdown_function(function () use ($pdo, $__k) {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
                @ignore_user_abort(true); @set_time_limit(60);
                try { foreach ($__k as $key) wag_prebuild($pdo, (string)$key); } catch (Throwable $e) { }
            });
        }
        wa_raw('ok');   // always 200 once the signature is right, so Meta does not retry forever
    }

    if ($__wa === 'wa.mfile') {   // ⚡ a customer photo / video / document by a SIGNED link (made by wa.media_link for a logged-in person who may see that chat, valid 3 hours):
        // the browser can stream it with byte ranges, so a video starts in a second instead of downloading first. The login token never goes into a link.
        $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? '')); $e = (int)($_GET['e'] ?? 0); $sg = (string)($_GET['s'] ?? '');
        if ($id === '' || $e < time() || !hash_equals(wa_mfile_sig($pdo, $id, $e), $sg)) { http_response_code(403); header('Content-Type: text/plain'); echo 'This link has expired — open the chat again.'; exit; }
        @set_time_limit(300); [$path, $mime, $err] = wa_mcache_get($pdo, $id); if ($path === '') { http_response_code(502); header('Content-Type: text/plain'); echo $err; exit; }
        wa_mcache_send($path, $mime); exit;
    }
    $auth = require_auth();
    if (!wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    switch ($__wa) {
        case 'wa.media_link': {   // a signed link to one customer file (see wa.mfile) — the same check as wa.media: any chat that has this file must be yours
            $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? '')); if ($id === '') json_response(['error' => 'No media id.'], 422);
            $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE media_id = ? LIMIT 50"); $q->execute([$id]); $phs = $q->fetchAll(PDO::FETCH_COLUMN); if (!$phs) json_response(['error' => 'Media not found.'], 404);
            $okc = false; foreach ($phs as $ph0) { $c = wa_contact($pdo, (string)$ph0); if ($c && wa_can_watch($pdo, $auth, $c)) { $okc = true; break; } }
            if (!$okc && function_exists('cx_can_media')) $okc = cx_can_media($pdo, $auth, $phs);
            if (!$okc) foreach ($phs as $ph0) { $c0 = wa_contact($pdo, (string)$ph0); if ($c0 && wa_req_peek($pdo, $c0) !== '') { $okc = true; break; } }
            if (!$okc) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $e = time() + 3 * 3600; json_response(['ok' => true, 'id' => $id, 'e' => $e, 's' => wa_mfile_sig($pdo, $id, $e), 'cached' => is_file(wa_mcache_dir() . '/' . $id . '.bin')]);   // the page builds the link with api.buildUrl('wa.mfile', {id, e, s})
        }
        case 'wa.status': {
            wa_guard_admin($pdo, $auth);
            $cfg = ['token' => wa_cfg_val('WA_TOKEN') !== '', 'phone_id' => wa_cfg_val('WA_PHONE_ID') !== '', 'waba_id' => wa_cfg_val('WA_WABA_ID') !== '', 'secret' => wa_cfg_val('WA_APP_SECRET') !== '', 'verify' => wa_cfg_val('WA_VERIFY_TOKEN') !== ''];
            $scheme = 'https';   // Meta only accepts https
            $out = ['config' => $cfg, 'webhook_url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'fourpirates.space') . '/empmanagment/api.php?action=wa.webhook'];
            $lw = $pdo->query("SELECT MAX(at) FROM wa_log WHERE kind = 'webhook' AND ok = 1")->fetchColumn(); $out['last_webhook'] = $lw ?: null;
            $out['counts'] = ['contacts' => (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts")->fetchColumn(), 'messages' => (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE dir != 'sys'")->fetchColumn(),
                'waiting' => (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) AND optout = 0")->fetchColumn()];
            $out['log'] = $pdo->query("SELECT at, kind, ok, info FROM wa_log ORDER BY id DESC LIMIT 15")->fetchAll();
            wa_numbers_sync($pdo);
            $out['numbers'] = $pdo->query("SELECT id, phone_id, display, label, panel, waba_id, provider FROM wa_numbers ORDER BY id")->fetchAll();
            foreach ($out['numbers'] as &$nn) { $nn['id'] = (int)$nn['id']; $nn['pay_blocked'] = (string)setting_get($pdo, 'wa_payblock_' . (int)$nn['id'], '') !== ''; $nn['messages'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE number_id = " . (int)$nn['id'])->fetchColumn(); } unset($nn);
            if (!empty($_GET['test'])) {
                foreach ($out['numbers'] as &$nn) $nn['check'] = wa_number_check($nn); unset($nn);
                $f = $out['numbers'][0] ?? null;   // the first number also fills the older single-number fields
                if ($f) { $ck = $f['check']; if ($ck['info']) $out['phone'] = $ck['info']; else $out['phone_error'] = $ck['error']; if ($ck['subscribed'] !== null) $out['subscribed'] = $ck['subscribed']; elseif ($ck['sub_error']) $out['subscribed_error'] = $ck['sub_error']; }
                else $out['phone_error'] = 'No number yet — add WA_PHONE_ID to config.php or add a number below.';
            }
            json_response($out);
        }
        case 'wa.subscribe': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $nid = (int)(json_input()['id'] ?? 0); $nr = $nid > 0 ? wa_number_row($pdo, $nid) : null;
            $wb = $nr ? wa_waba_of($nr) : wa_cfg_val('WA_WABA_ID'); if ($wb === '') json_response(['error' => 'No WhatsApp Business Account ID — set WA_WABA_ID in config.php or fill it for this number.'], 422);
            [$c, $d] = wa_api('POST', '/' . $wb . '/subscribed_apps', []);
            if ($c < 200 || $c >= 300) { $e = wa_err($d); wa_log($pdo, 'subscribe', false, $e); if ($nr && ($wb === (string)$nr['phone_id'] || stripos($e, 'nonexisting field') !== false || stripos($e, 'does not exist') !== false)) $e = 'The WhatsApp Business Account ID saved for this number (' . $wb . ') is not an account. Fix the second box: it must be the account ID from Meta (Business settings → Messaging accounts), not the Phone number ID. (Meta said: ' . mb_substr($e, 0, 120) . ')'; json_response(['error' => $e], 502); }
            wa_log($pdo, 'subscribe', true, 'This WhatsApp account is now connected to the webhook.'); json_response(['ok' => true, 'message' => 'Connected. Messages to this number now reach the HRMS.']);
        }
        case 'wa.register': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $pin = trim((string)($in['pin'] ?? '')); if (!preg_match('/^\d{6}$/', $pin)) json_response(['error' => 'The PIN must be exactly 6 digits.'], 422);
            $nid = (int)($in['id'] ?? 0); $nr = $nid > 0 ? wa_number_row($pdo, $nid) : null; $pid = $nr ? (string)$nr['phone_id'] : wa_cfg_val('WA_PHONE_ID');
            if ($pid === '') json_response(['error' => 'WA_PHONE_ID is missing in config.php.'], 422);
            [$c, $d] = wa_api('POST', '/' . $pid . '/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);
            if ($c < 200 || $c >= 300) { $e = wa_err($d); wa_log($pdo, 'register', false, $e); json_response(['error' => $e], 502); }
            wa_log($pdo, 'register', true, 'Number registered on the Cloud API.'); json_response(['ok' => true, 'message' => 'Number registered. Press “Check now” to see its status.']);
        }
        case 'wa.number_save': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $label = trim(mb_substr((string)($in['label'] ?? ''), 0, 60)); $panel = trim(mb_substr((string)($in['panel'] ?? ''), 0, 20));
            $pid = preg_replace('/\D+/', '', (string)($in['phone_id'] ?? '')); $wb = preg_replace('/\D+/', '', (string)($in['waba_id'] ?? ''));
            if ($id > 0) {
                $cur = wa_number_row($pdo, $id); if (!$cur) json_response(['error' => 'Number not found.'], 404);
                if ($wb !== '' && $wb === (string)$cur['phone_id']) json_response(['error' => 'The WhatsApp Business Account ID cannot be the same as the Phone number ID. Use the account ID from Meta (Business settings → Messaging accounts → the long ID under the account name).'], 422);
                if ($wb === '') $wb = wa_find_waba($pdo, (string)$cur['phone_id']);
                $pdo->prepare("UPDATE wa_numbers SET label = ?, panel = ?, waba_id = ? WHERE id = ?")->execute([$label, $panel, $wb, $id]);   // the Phone number ID of a saved number never changes
            } else {
                if (strlen($pid) < 8 || strlen($pid) > 20) json_response(['error' => 'Paste the Phone number ID from Meta (digits only, about 15 long) — not the phone number itself.'], 422);
                if ($wb !== '' && $wb === $pid) json_response(['error' => 'The WhatsApp Business Account ID is the same as the Phone number ID — they are two different numbers. The account ID is the one shown under the account name in Meta (Business settings → Messaging accounts). Leave that box empty and the HRMS will try to find the account by itself.'], 422);
                if ($wb === '') $wb = wa_find_waba($pdo, $pid);   // not typed: find the account among the ones already known
                $q = $pdo->prepare("SELECT id FROM wa_numbers WHERE phone_id = ?"); $q->execute([$pid]); if ($q->fetch()) json_response(['error' => 'This number is already in the list.'], 422);
                if ($label === '') json_response(['error' => 'Give the number a name, e.g. “TM Perfume House One”.'], 422);
                $pdo->prepare("INSERT INTO wa_numbers (phone_id, label, panel, waba_id, created_at) VALUES (?, ?, ?, ?, ?)")->execute([$pid, $label, $panel, $wb, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId();
            }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.number_del': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $id = (int)(json_input()['id'] ?? 0); $n = $id > 0 ? wa_number_row($pdo, $id) : null; if (!$n) json_response(['error' => 'Number not found.'], 404);
            if ((int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE number_id = " . $id)->fetchColumn() > 0) json_response(['error' => 'This number already has chats, so it cannot be removed. Rename it instead.'], 422);
            if ((string)$n['phone_id'] === wa_cfg_val('WA_PHONE_ID')) json_response(['error' => 'This is the main number from config.php — remove WA_PHONE_ID there first.'], 422);
            $pdo->prepare("DELETE FROM wa_numbers WHERE id = ?")->execute([$id]); json_response(['ok' => true]);
        }
        case 'wa.number_check': {
            wa_guard_admin($pdo, $auth); $n = wa_number_row($pdo, (int)($_GET['id'] ?? 0)); if (!$n) json_response(['error' => 'Number not found.'], 404);
            json_response(['check' => wa_number_check($n)]);
        }
        case 'wa.quick_list': {
            $rows = $pdo->query("SELECT id, title, body FROM wa_quick ORDER BY sort_order, id")->fetchAll();
            json_response(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body']], $rows)]);
        }
        case 'wa.quick_save': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $t = trim(mb_substr((string)($in['title'] ?? ''), 0, 60)); $b = trim(mb_substr((string)($in['body'] ?? ''), 0, 1500));
            if ($t === '' || $b === '') json_response(['error' => 'Write both a title and a message.'], 422);
            if ($id > 0) $pdo->prepare("UPDATE wa_quick SET title = ?, body = ? WHERE id = ?")->execute([$t, $b, $id]);
            else { $pdo->prepare("INSERT INTO wa_quick (title, body, sort_order) VALUES (?, ?, ?)")->execute([$t, $b, (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM wa_quick")->fetchColumn()]); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.quick_del': {
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $pdo->prepare("DELETE FROM wa_quick WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); json_response(['ok' => true]);
        }
        case 'wa.quick_starter': {   // Admin: adds the starter quick replies (greetings + common questions) that are not there yet — never changes or removes yours
            wa_guard_admin($pdo, $auth); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $set = [
                ['Hi / Hello', "Hi {name}! 🙏 Welcome to TM Perfume House. Which fragrance are you looking for?"],
                ['Good morning', "Good morning {name}! ☀️ Welcome to TM Perfume House. How can I help you today?"],
                ['Good afternoon', "Good afternoon {name}! Welcome to TM Perfume House. How can I help you today?"],
                ['Good evening', "Good evening {name}! 🌙 Welcome to TM Perfume House. How can I help you today?"],
                ['Namaste', "Namaste {name}! 🙏 Welcome to TM Perfume House. Which fragrance would you like to see?"],
                ['Price', "Hi {name}! Tell me the perfume and size you like and I'll share the exact price right away 🙏"],
                ['Discount', "Thank you {name}! Tell me what you would like to order and I'll check the best offer for you 🙏"],
                ['COD', "Hi {name}! Please share your pincode and I'll confirm if Cash on Delivery is available there."],
                ['Delivery', "Hi {name}! Please share your pincode and I'll tell you the delivery time for your area 🚚"],
                ['Original?', "All our perfumes are made with 30% oil, so they stay long on skin and clothes. Would you like to try one, {name}?"],
                ['Payment link', "Sure {name}! Sending you the payment link now 🙏 Please confirm the perfume, size and delivery address."],
                ['Photos', "Sending photos now 📸 Which fragrance would you like to see, {name}?"],
                ['Return / problem', "Sorry about that, {name}! Please send your order number and a photo, and I'll look into it right away."],
                ['Store address', "Our store: TMPerfumehouse Store, G8, Eco Commerz, Vesu Canal Rd, opposite Rajhans Zion, Vesu, Surat 395007. You are welcome to visit! 🙏"],
            ];
            $have = []; foreach ($pdo->query("SELECT title FROM wa_quick")->fetchAll() as $r) $have[mb_strtolower(trim((string)$r['title']))] = true;
            $n = 0; $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM wa_quick")->fetchColumn();
            foreach ($set as [$t, $b]) { if (isset($have[mb_strtolower($t)])) continue; $pdo->prepare("INSERT INTO wa_quick (title, body, sort_order) VALUES (?, ?, ?)")->execute([$t, $b, ++$so]); $n++; }
            json_response(['ok' => true, 'added' => $n, 'total' => count($set)]);
        }
        case 'wa.chats': {
            $scope = (string)($_GET['scope'] ?? 'mine'); $q = trim((string)($_GET['q'] ?? '')); $emp = (int)($auth['employee_id'] ?? 0); $adm = wa_is_admin($pdo, $auth) && empty($_GET['mine']);   // mine=1 → the My Chats page: even an admin sees only their own
            $w = []; $p = []; $srcQ = (string)($_GET['src'] ?? ''); $numFeed = $adm && preg_match('/^num:(\d+)$/', $srcQ, $numM) ? (int)$numM[1] : 0;
            if ($numFeed) { $w[] = 'c.phone IN (SELECT m0.phone FROM wa_messages m0 WHERE m0.dir = \'in\' AND m0.number_id = ?)'; $p[] = $numFeed; }   // 📱 everybody who ever wrote to this number (since it was deployed), open or closed
            elseif (!$adm) { $w[] = 'c.assigned_emp = ?'; $p[] = $emp; $w[] = $scope === 'closed' ? "c.status = 'closed'" : "c.status = 'open'"; }
            elseif ($scope === 'mine') { $w[] = 'c.assigned_emp = ?'; $p[] = $emp > 0 ? $emp : -1; $w[] = "c.status = 'open'"; }
            elseif ($scope === 'unassigned') { $w[] = 'c.assigned_emp = 0'; $w[] = "c.status = 'open'"; }
            elseif ($scope === 'closed') $w[] = "c.status = 'closed'";
            elseif ($scope === 'emp') { $ee = (int)($_GET['emp'] ?? 0); if (!empty($_GET['tag'])) { $w[] = '(c.assigned_emp = ? OR c.phone IN (SELECT l.phone FROM wa_assign_log l WHERE l.to_emp = ?))'; $p[] = $ee; $p[] = $ee; } else { $w[] = 'c.assigned_emp = ?'; $p[] = $ee; $w[] = "c.status = 'open'"; } }   // 🏷 tag=1: every chat this person ever had, wherever it is now
            else $w[] = "c.status = 'open'";
            if ($adm && $scope !== 'mine') $w[] = 'c.last_in_at IS NOT NULL';   // people a campaign reached but who never wrote are in Contacts, not in Chats
            if (!$numFeed && $scope !== 'closed' && trim((string)($_GET['q'] ?? '')) === '') { $w[] = 'COALESCE(c.last_in_at, c.last_out_at) >= ?'; $p[] = date('Y-m-d H:i:s', time() - 90 * 86400); }   // the list sorts only the last 90 days (it sorted every open contact ever); search still finds anyone
            $src = (string)($_GET['src'] ?? '');   // Stage B: feed filter — query | community | campaign | assigned
            if ($src === 'query') { if (function_exists('wa_ad_backfill')) wa_ad_backfill($pdo); $w[] = "c.source = 'query'"; $w[] = 'c.last_in_at >= ?'; $p[] = wa_bizday_start($pdo); }   // 📢 fresh Meta-ad queries of this business day (10:00 → 10:00); campaign / community have their own feeds
            elseif (in_array($src, ['community', 'campaign'], true)) { $w[] = 'c.source = ?'; $p[] = $src; } elseif ($src === 'assigned') $w[] = 'c.assigned_emp > 0';
            if ($q !== '') { $w[] = '(c.phone LIKE ? OR c.name LIKE ?)'; $p[] = wa_q_like($q); $p[] = '%' . addcslashes($q, '%_\\') . '%'; }
            // 🔽 the Filter menu: show only … (any mix) and the order
            $wBase = $w; $pBase = $p;   // the view without the Filter menu: tab (All open / Closed / …) + chip (Query / Community / …) + person + search — the Not-responded count is for THIS view
            $flt = array_flip(array_filter(explode(',', (string)($_GET['flt'] ?? '')))); $noRep = "(c.last_in_at IS NOT NULL AND c.last_in_at >= '" . date('Y-m-d H:i:s', time() - 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_messages hm WHERE hm.phone = c.phone AND hm.dir = 'out' AND hm.emp_id > 0 AND hm.ts >= c.last_in_at))";   // 🙅 not responded: the customer wrote (last 24 h) and no SALESPERSON answered after it (a bot / auto message does not count)
            if (isset($flt['noreply'])) $w[] = $noRep;
            if (isset($flt['unassigned'])) $w[] = 'c.assigned_emp = 0';
            if (isset($flt['hot'])) $w[] = 'c.hot = 1';
            if (isset($flt['late'])) { $w[] = $noRep; $w[] = 'c.last_in_at <= ?'; $p[] = date('Y-m-d H:i:s', time() - 60 * (function_exists('wa_team_cfg') ? max(1, (int)(wa_team_cfg($pdo)['unans_min'] ?? 5)) : 5)); }
            if (isset($flt['complaint'])) $w[] = "(c.cx_id > 0 OR c.tag = 'complaint')";
            // 📅 Today's chats / Yesterday's chats (the customer wrote that calendar day); both ticked = today AND yesterday together
            { $dC = []; $d0 = date('Y-m-d 00:00:00'); $d1 = date('Y-m-d 00:00:00', strtotime('-1 day'));
              if (isset($flt['today'])) $dC[] = "c.last_in_at >= '$d0'"; if (isset($flt['yesterday'])) $dC[] = "(c.last_in_at >= '$d1' AND c.last_in_at < '$d0')"; if ($dC) $w[] = '(' . implode(' OR ', $dC) . ')'; }
            $sort = (string)($_GET['sort'] ?? '');
            $ord = ['new' => 'COALESCE(c.last_in_at, c.last_out_at) DESC', 'old' => 'COALESCE(c.last_in_at, c.last_out_at) ASC', 'az' => "(c.name = '') ASC, c.name ASC, c.phone ASC", 'za' => "(c.name = '') ASC, c.name DESC, c.phone DESC"][$sort] ?? null;
            // a chat that was just GIVEN to this person (transfer, take over, admin assign) and not answered by them yet sits at the very top, like a fresh customer message
            $boost = "(c.assigned_emp > 0 AND c.assigned_at IS NOT NULL AND c.assigned_at >= '" . date('Y-m-d H:i:s', time() - 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_messages hm WHERE hm.phone = c.phone AND hm.dir = 'out' AND hm.who IN ('emp','phone') AND hm.ts >= c.assigned_at))";   // 🟢 given to a person, and no PERSON has written in it since (bots do not count)
            $boostOrd = (!$adm || $scope === 'mine') ? $boost : '0';
            $sql = "SELECT c.*, IF(" . $boost . ", 1, 0) AS fresh_agent, (SELECT body FROM wa_messages m WHERE m.phone = c.phone AND m.dir != 'sys' ORDER BY m.id DESC LIMIT 1) AS last_body, (SELECT m2.ts FROM wa_messages m2 WHERE m2.phone = c.phone AND m2.dir != 'sys' ORDER BY m2.id DESC LIMIT 1) AS last_ts,
                    (SELECT COUNT(*) FROM wa_messages u WHERE u.phone = c.phone AND u.dir = 'in' AND u.id > c.seen_msg_id) AS unread
                    FROM wa_contacts c WHERE " . implode(' AND ', $w) . " ORDER BY " . ($ord ? $ord . ', ' : '') . "CASE WHEN (c.last_in_at IS NOT NULL AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) AND c.last_in_at >= '" . date('Y-m-d H:i:s', time() - 86400) . "') OR " . $boostOrd . " THEN 0 ELSE 1 END, GREATEST(COALESCE(c.last_in_at, c.last_out_at, '1970-01-01'), IF(" . $boostOrd . ", c.assigned_at, '1970-01-01')) DESC LIMIT " . (!empty($_GET['ids']) ? 3000 : (isset($flt['today']) || isset($flt['yesterday']) ? 1500 : ($flt || $ord ? 400 : 150)));   // a customer waiting for a reply first; our own sends / campaigns never move a chat
            $st = $pdo->prepare($sql); $st->execute($p); $rows = $st->fetchAll(); $names = employee_names_by_id($pdo);
            $fl = wa_heat_flags($pdo, array_column($rows, 'phone')); $lm = function_exists('wa_team_cfg') ? max(1, (int)(wa_team_cfg($pdo)['unans_min'] ?? 5)) : 5;
            if (!empty($_GET['ids'])) json_response(['ids' => array_map(fn($r) => ['phone' => (string)$r['phone'], 'name' => (string)($r['name'] ?? '')], $rows)]);   // ☑ "Select all" in the bulk bar: just the phones of the whole view
            $ot = wa_owner_tags($pdo, array_column($rows, 'phone'));
            $out = array_map(function ($r) use ($names, $fl, $lm, $ot) { $x = wa_contact_row($r, $names); $f = (int)($ot[(string)$r['phone']]['first'] ?? 0); $x['first_emp'] = $f; $x['first_name'] = $f ? ($names[$f] ?? '') : ''; $x['preview'] = mb_substr((string)$r['last_body'], 0, 90); $x['last_msg_at'] = (string)($r['last_ts'] ?? ''); $x['fresh_agent'] = (int)($r['fresh_agent'] ?? 0) === 1; $x['heat'] = wa_heat($r, $fl[(string)$r['phone']] ?? [], $lm); $x['biz'] = !empty($fl[(string)$r['phone']]['biz']); return $x; }, $rows);
            $cn = wa_counts($pdo, $adm, $emp); $cn['src'] = wa_src_counts($pdo, $adm, $emp);
            if ($adm && $scope === 'emp') { try { $ee = (int)($_GET['emp'] ?? 0); $a = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = ? AND status = 'open' AND COALESCE(last_in_at, last_out_at) >= ?"); $a->execute([$ee, date('Y-m-d H:i:s', time() - 90 * 86400)]); $cn['emp_now'] = (int)$a->fetchColumn();
                $b = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts c WHERE (c.assigned_emp = ? OR c.phone IN (SELECT l.phone FROM wa_assign_log l WHERE l.to_emp = ?)) AND COALESCE(c.last_in_at, c.last_out_at) >= ?"); $b->execute([$ee, $ee, date('Y-m-d H:i:s', time() - 90 * 86400)]); $cn['emp_tag'] = (int)$b->fetchColumn(); } catch (Throwable $e) { } }
            if ($adm) { try { $nq = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts c WHERE " . implode(' AND ', array_merge($wBase, [$noRep]))); $nq->execute($pBase); $cn['noresp'] = (int)$nq->fetchColumn(); } catch (Throwable $e) { } }
            json_response(['chats' => $out, 'counts' => $cn, 'admin' => $adm, 'rules' => wa_rules_state($pdo) + ['auto' => function_exists('wa_auto_state') ? wa_auto_state($pdo) : ['on' => false, 'secs' => 20]]]);
        }
        case 'wa.chat': {
            $key = wa_key($_GET['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null;
            if (!$c) json_response(['error' => 'Chat not found.'], 404);
            $peek = ''; if (!wa_can_watch($pdo, $auth, $c)) { $peek = wa_req_peek($pdo, $c); if ($peek === '' && wa_was_mine($pdo, $key, (int)($auth['employee_id'] ?? 0))) $peek = 'tag'; if ($peek === '') json_response(['error' => 'This chat is assigned to someone else.'], 403); }   // 🙋 a Requests chat may be READ before taking it; replying needs Take over
            // "anything new?" — one small query: the newest message, the delivery ticks and the chat's own fields. Same as the page has → {same:1} (the 10-second
            // refresh of an open chat used to re-load 100 messages and ~11 queries every time)
            $sq = $pdo->prepare("SELECT MAX(id) mx, COUNT(*) n, SUM(status = 'read') r, SUM(status = 'delivered') dl, SUM(status = 'failed') f FROM wa_messages WHERE phone = ?"); $sq->execute([$key]); $sg = $sq->fetch() ?: [];
            $sig = md5(json_encode([$sg, $c['assigned_emp'] ?? 0, $c['status'] ?? '', $c['tag'] ?? '', $c['optout'] ?? 0, $c['cx_id'] ?? 0, $c['hot'] ?? 0, $c['summary'] ?? '', $c['last_in_at'] ?? '', $c['state'] ?? '', $c['remind_at'] ?? '']));
            if (empty($_GET['before']) && (string)($_GET['sig'] ?? '') === $sig) json_response(['same' => 1, 'sig' => $sig]);
            $lim = max(1, min(300, (int)($_GET['limit'] ?? 100))); $before = (int)($_GET['before'] ?? 0);
            $st = $pdo->prepare("SELECT m.id, m.dir, m.mtype, m.body, m.media_id, m.media_mime, m.status, m.who, m.emp_id, m.ts, m.number_id, m.reply_to, m.err, m.err_code FROM wa_messages m WHERE m.phone = ?" . ($before ? ' AND (m.ts < ? OR (m.ts = ? AND m.id < ?))' : '') . " ORDER BY m.ts DESC, m.id DESC LIMIT " . ($lim + 1));   // by time: past chats Meta shares later (📱 phone app history) sit in their right place
            $bts = ''; if ($before) { $bq = $pdo->prepare("SELECT ts FROM wa_messages WHERE id = ? AND phone = ?"); $bq->execute([$before, $key]); $bts = (string)$bq->fetchColumn(); }   // page by (time, id) — the same order the chat is shown in, so old messages Meta shared later are not skipped
            $st->execute($before ? [$key, $bts, $bts, $before] : [$key]); $msgs = $st->fetchAll(); $more = count($msgs) > $lim; if ($more) array_pop($msgs); $msgs = array_reverse($msgs);   // one extra row tells the page that older messages exist ("Load older messages")
            $nums = []; foreach ($pdo->query("SELECT id, display, label, panel FROM wa_numbers")->fetchAll() as $n) $nums[(int)$n['id']] = $n['label'] !== '' ? $n['label'] : ($n['display'] !== '' ? $n['display'] : 'Number ' . $n['id']);
            $names = employee_names_by_id($pdo);
            foreach ($msgs as &$m) { $m['id'] = (int)$m['id']; $m['number'] = $nums[(int)$m['number_id']] ?? ''; $m['emp_name'] = $names[(int)$m['emp_id']] ?? ''; } unset($m); wa_add_quotes($pdo, $msgs);
            $emp0 = (int)($auth['employee_id'] ?? 0);
            if (($emp0 > 0 && (int)$c['assigned_emp'] === $emp0) || ((int)$c['assigned_emp'] === 0 && wa_is_admin($pdo, $auth))) {   // the owner of the chat opened it → read ( an admin peeking at someone else's chat does not clear their unread )
                $pdo->prepare("UPDATE wa_contacts SET seen_msg_id = (SELECT COALESCE(MAX(id), 0) FROM wa_messages WHERE phone = ? AND dir = 'in') WHERE phone = ?")->execute([$key, $key]); $c['unread'] = 0;
            } else { $q3 = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'in' AND id > ?"); $q3->execute([$key, (int)$c['seen_msg_id']]); $c['unread'] = (int)$q3->fetchColumn(); }
            $ct = wa_contact_row($c, $names); $ct['numbers_used'] = count(array_unique(array_filter(array_column(array_filter($msgs, fn($m) => $m['dir'] !== 'sys'), 'number_id')))); $ct['last_number'] = $nums[(int)$c['last_number_id']] ?? '';
            $one = function (string $sql) use ($pdo, $key) { $q4 = $pdo->prepare($sql); $q4->execute([$key]); return $q4->fetchColumn(); };
            $ct['session_msgs'] = (int)$one("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir != 'sys' AND mtype != 'template'"); $ct['template_msgs'] = (int)$one("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND mtype = 'template'");
            $ct['first_message'] = mb_substr((string)$one("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id LIMIT 1"), 0, 120); $ct['first_seen'] = $c['first_seen'];
            $log = []; if (wa_is_admin($pdo, $auth)) { $q2 = $pdo->prepare("SELECT from_emp, to_emp, by_emp, how, at FROM wa_assign_log WHERE phone = ? ORDER BY id DESC LIMIT 10"); $q2->execute([$key]);
                foreach ($q2->fetchAll() as $l) $log[] = ['at' => $l['at'], 'how' => $l['how'], 'from' => $names[(int)$l['from_emp']] ?? '', 'to' => $names[(int)$l['to_emp']] ?? '', 'by' => $names[(int)$l['by_emp']] ?? '']; }
            json_response(['contact' => $ct, 'messages' => $msgs, 'more' => $more, 'assign_log' => $log, 'sig' => $sig, 'peek' => $peek]);
        }
        case 'wa.cx_note': {   // 📝 the salesperson's note for the complaint team (goes into the case history; the customer never sees it)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 600));
            $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c || (int)($c['cx_id'] ?? 0) <= 0) json_response(['error' => 'This chat has no open complaint.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (mb_strlen($text) < 2) json_response(['error' => 'Write the note first.'], 422);
            $me = (int)($auth['employee_id'] ?? 0); $now = date('Y-m-d H:i:s');
            try { $pdo->prepare("INSERT INTO cx_events (case_id, at, emp_id, kind, text) VALUES (?, ?, ?, 'note', ?)")->execute([(int)$c['cx_id'], $now, $me, '📝 From the salesperson: ' . $text]); }
            catch (Throwable $e) { json_response(['error' => 'The note could not be saved.'], 500); }
            try { $o = $pdo->prepare("SELECT owner_emp FROM cx_cases WHERE id = ?"); $o->execute([(int)$c['cx_id']]); $ow = (int)$o->fetchColumn(); if ($ow > 0 && $ow !== $me && function_exists('ct_push')) ct_push($pdo, [$ow], 'Note on C-' . str_pad((string)$c['cx_id'], 4, '0', STR_PAD_LEFT), mb_substr($text, 0, 120), 'complaints.html', 'cxnote'); } catch (Throwable $e) { }
            json_response(['ok' => true]);
        }
        case 'wa.send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $text = trim((string)($in['text'] ?? ''));
            $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if (($__cx = wa_cx_lock($pdo, $auth, $c)) !== '') json_response(['error' => $__cx, 'complaint' => (int)$c['cx_id']], 423);
            if ($text === '') json_response(['error' => 'Write a message first.'], 422); if (mb_strlen($text) > 4000) json_response(['error' => 'The message is too long (max 4000 characters).'], 422);
            // never twice: the same tap arriving again (slow network, double tap, a retry) or the same text to the same customer within 20 seconds → answered with the first send, nothing goes out
            $cid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['cid'] ?? '')); $dup = wa_send_dup($pdo, $key, $text, $cid);
            if ($dup !== null) json_response(['ok' => true, 'wamid' => $dup, 'duplicate' => true]);
            if (function_exists('wq_send_guard') && ($__g = wq_send_guard($pdo, $c, $text, $in)) !== '') { wa_send_claim_drop($pdo, $cid); json_response(['error' => $__g, 'already_answered' => true], 409); }   // the bot / holding message / countdown already answered this message
            $__cxFirst = 0; if (empty($in['auto']) && (int)($c['cx_id'] ?? 0) > 0) { try { $q0 = $pdo->prepare("SELECT id FROM cx_cases WHERE id = ? AND first_reply_at IS NULL AND status IN ('open','reopened')"); $q0->execute([(int)$c['cx_id']]); $__cxFirst = (int)$q0->fetchColumn(); } catch (Throwable $e) { } }
            if ($__cxFirst) { $__code = 'C-' . str_pad((string)$__cxFirst, 4, '0', STR_PAD_LEFT); if (strpos($text, $__code) === false) $text .= "\n\nYour complaint number is " . $__code . " — please mention it if you write to us again."; }   // 🎫 the ticket ID goes with the FIRST reply on a complaint
            $__rt = ''; if (!empty($in['reply_id'])) { try { $q9 = $pdo->prepare("SELECT wamid FROM wa_messages WHERE id = ? AND phone = ? AND dir IN ('in','out')"); $q9->execute([(int)$in['reply_id'], $key]); $__rt = (string)$q9->fetchColumn(); } catch (Throwable $e) { } }   // ↩ swiped: answer that message
            [$ok, $r] = wa_send_text($pdo, $c, $text, !empty($in['auto']) ? 'bot' : 'emp', !empty($in['auto']) ? 0 : (int)($auth['employee_id'] ?? 0), $__rt);   // the browser countdown (auto:true) is the bot's reply, not a person's
            if (!$ok) { wa_send_claim_drop($pdo, $cid); json_response(['error' => $r], 422); }
            wa_send_claim_done($pdo, $cid, (string)$r);
            if ($__cxFirst) { try { $pdo->prepare("UPDATE cx_cases SET first_reply_at = ?, updated_at = ? WHERE id = ? AND first_reply_at IS NULL")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $__cxFirst]); } catch (Throwable $e) { } }
            if (empty($in['auto'])) $pdo->prepare("UPDATE wa_contacts SET state = 'human', status = 'open', closed_at = NULL, remind_at = NULL WHERE phone = ?")->execute([$key]);   // replied → the reminder is done   // a person replied → the bot stays quiet, a closed chat re-opens
            else $pdo->prepare("UPDATE wa_contacts SET status = 'open', closed_at = NULL WHERE phone = ?")->execute([$key]);
            wa_reply_early(['ok' => true, 'wamid' => $r]);   // the phone gets its answer now; the bookkeeping below runs after
            if (empty($in['auto']) && function_exists('wa_learn_log')) wa_learn_log($pdo, $c, $text, (int)($auth['employee_id'] ?? 0));   // used / edited / own reply → the Learning queue
            exit;
        }
        case 'wa.people': {   // salespeople with today's presence + open chats; the first "best" one is the recommendation (a salesperson gets the short list for "Transfer to")
            if (function_exists('wa_more_tables')) wa_more_tables($pdo);
            $pk = wa_key($_GET['phone'] ?? ''); $pc = $pk !== '' ? wa_contact($pdo, $pk) : null;   // for one chat: the people of the number the customer last used come first
            $pp = wa_people($pdo, $pc ? (int)$pc['last_number_id'] : 0);
            if (!empty($_GET['all']) && wa_is_admin($pdo, $auth)) $pp = array_merge($pp, wa_people_others($pdo, array_column($pp, 'emp_id')));   // the header Assign search: every employee, not only sales
            if (!wa_is_admin($pdo, $auth)) $pp = array_map(fn($x) => ['emp_id' => $x['emp_id'], 'name' => $x['name'], 'present' => $x['present'], 'full' => $x['full']], $pp);
            json_response(['people' => $pp, 'max_open' => function_exists('wa_team_cfg') ? wa_team_cfg($pdo)['max_open'] : WA_MAX_OPEN]);
        }
        case 'wa.transfer': {   // the person who holds a chat hands it to a colleague (an admin can move any chat)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $to = (int)($in['emp_id'] ?? 0); $me = (int)($auth['employee_id'] ?? 0);
            $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_is_admin($pdo, $auth) && (int)$c['assigned_emp'] !== $me) json_response(['error' => 'Only the person who holds this chat can transfer it.'], 403);
            $ids = array_column(wa_people($pdo), 'emp_id'); if (!in_array($to, $ids, true)) json_response(['error' => 'Pick a salesperson from the list.'], 422);
            if ($to === (int)$c['assigned_emp']) json_response(['error' => 'The chat is already with this person.'], 422);
            wa_assign_to($pdo, $key, $to, $me, 'transfer'); json_response(['ok' => true]);
        }
        case 'wa.bulk_number': {   // 📱 many chats → answer from ANOTHER of our numbers (e.g. the Second number has a payment problem → move them to Main). Admin only.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only an admin can move chats to another number.'], 403);
            $in = json_input(); $nid = (int)($in['number_id'] ?? 0); $nq = $pdo->prepare("SELECT id, label, display FROM wa_numbers WHERE id = ?"); $nq->execute([$nid]); $nr = $nq->fetch(); if (!$nr) json_response(['error' => 'Pick one of your numbers.'], 422);
            $lab = trim((string)($nr['label'] ?: $nr['display']));
            $phones = array_slice(array_values(array_unique(array_filter(array_map(fn($p) => wa_key($p), (array)($in['phones'] ?? []))))), 0, 1500); if (!$phones) json_response(['error' => 'Select at least one chat.'], 422);
            $moved = 0; $same = 0; $up = $pdo->prepare("UPDATE wa_contacts SET last_number_id = ? WHERE phone = ? AND last_number_id <> ?");
            foreach ($phones as $key) { $c = wa_contact($pdo, $key); if (!$c) continue; if ((int)$c['last_number_id'] === $nid) { $same++; continue; } $up->execute([$nid, $key, $nid]); if ($up->rowCount() > 0) { $moved++; try { wa_event($pdo, $key, '📱 Moved to ' . $lab . ' by ' . ($auth['username'] ?? 'Admin') . ' — replies now go from it. The customer has not written to this number yet, so start with a pre-made message.'); } catch (Throwable $e) { } } }
            json_response(['ok' => true, 'moved' => $moved, 'same' => $same, 'number' => $lab]);
        }
        case 'wa.bulk_assign': {   // ☑ many chats at once → one person. Admin: any chat. A salesperson: only the chats they hold (same rule as Transfer). Max 1500 per call.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $to = (int)($in['emp_id'] ?? 0); $me = (int)($auth['employee_id'] ?? 0); $adm = wa_is_admin($pdo, $auth);
            $ids = array_column(wa_people($pdo), 'emp_id'); if ($adm) $ids = array_merge($ids, array_column(wa_people_others($pdo, $ids), 'emp_id'));
            if (!in_array($to, array_map('intval', $ids), true)) json_response(['error' => 'Pick a person from the list.'], 422);
            $phones = array_slice(array_values(array_unique(array_filter(array_map(fn($p) => wa_key($p), (array)($in['phones'] ?? []))))), 0, 1500); if (!$phones) json_response(['error' => 'Select at least one chat.'], 422);
            $moved = 0; $skipped = []; $nm = employee_names_by_id($pdo);
            foreach ($phones as $key) {
                $c = wa_contact($pdo, $key); if (!$c) { $skipped[] = ['phone' => $key, 'why' => 'chat not found']; continue; }
                if (!$adm && (int)$c['assigned_emp'] !== $me) { $skipped[] = ['phone' => $key, 'why' => 'not your chat']; continue; }
                if ((int)$c['assigned_emp'] === $to) { $skipped[] = ['phone' => $key, 'why' => 'already with ' . ($nm[$to] ?? 'them')]; continue; }
                try { wa_assign_to($pdo, $key, $to, $me, $adm ? 'manual' : 'transfer'); $moved++; } catch (Throwable $e) { $skipped[] = ['phone' => $key, 'why' => 'could not move']; }
            }
            json_response(['ok' => true, 'moved' => $moved, 'skipped' => array_slice($skipped, 0, 30), 'skipped_n' => count($skipped), 'to' => $nm[$to] ?? '']);
        }
        case 'wa.notices': {   // 🔔 my own unseen notices (someone took my chat): only the logged-in person's, last 3 days
            $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0 || !wa_notice_table($pdo)) json_response(['notices' => []]);
            $q = $pdo->prepare("SELECT id, by_emp, phone, cname, kind, at FROM wa_notices WHERE to_emp = ? AND seen = 0 AND at >= ? ORDER BY id DESC LIMIT 20"); $q->execute([$me, date('Y-m-d H:i:s', time() - 3 * 86400)]); $nm = employee_names_by_id($pdo);
            json_response(['notices' => array_map(fn($r) => ['id' => (int)$r['id'], 'by' => (string)($nm[(int)$r['by_emp']] ?? 'Someone'), 'phone' => (string)$r['phone'], 'name' => (string)$r['cname'], 'kind' => (string)$r['kind'], 'at' => (string)$r['at']], $q->fetchAll())]);
        }
        case 'wa.notice_seen': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0 || !wa_notice_table($pdo)) json_response(['ok' => true]);
            $in = json_input(); $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))); if (!empty($in['all'])) $pdo->prepare("UPDATE wa_notices SET seen = 1 WHERE to_emp = ?")->execute([$me]);
            elseif ($ids) $pdo->prepare("UPDATE wa_notices SET seen = 1 WHERE to_emp = ? AND id IN (" . implode(',', $ids) . ")")->execute([$me]);
            json_response(['ok' => true]);
        }
        case 'wa.takeover': {   // a manager (or admin) takes a chat from whoever has it — at any time, no waiting rule. The salesperson's app locks that chat at once.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (!wa_can_takeover($pdo, $auth)) json_response(['error' => 'You are not allowed to take over chats. Ask the admin (Team & rules → Taking over chats).'], 403);
            $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0) json_response(['error' => 'Your login has no employee profile.'], 422);
            $key = wa_key(json_input()['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_is_admin($pdo, $auth) && ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint')) json_response(['error' => 'This is a complaint chat — it stays with its person. Ask the admin.'], 409);
            $from = (int)$c['assigned_emp']; if ($from === $me) json_response(['ok' => true, 'already' => true]);
            wa_assign_to($pdo, $key, $me, $me, 'takeover');
            $nm = employee_names_by_id($pdo); $who = $nm[$me] ?? 'Manager'; $was = $from > 0 ? ($nm[$from] ?? 'someone') : '';
            wa_event($pdo, $key, '🔄 ' . $who . ' took over this chat' . ($was !== '' ? ' from ' . $was : ''));
            if ($from > 0 && function_exists('ct_push')) { try { ct_push($pdo, [$from], 'Chat taken over', $who . ' took over ' . ((string)$c['name'] !== '' ? (string)$c['name'] : '+91 ' . $key) . '.', '/my_chats.html', 'wa-to-' . $key); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'from' => $was]);
        }
        case 'wa.to_get': case 'wa.to_save': {   // who may NOT take over chats (everyone else in Sales may)  [Admin / HR]
            wa_guard_admin($pdo, $auth);
            if ($__wa === 'wa.to_save') { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $ids = array_values(array_unique(array_filter(array_map('intval', (array)(json_input()['blocked'] ?? []))))); setting_set($pdo, 'wa_to_block', json_encode($ids)); json_response(['ok' => true, 'blocked' => $ids]); }
            $nm = employee_names_by_id($pdo); $sales = []; foreach (wa_sales_ids($pdo) as $i) $sales[] = ['emp_id' => $i, 'name' => (string)($nm[$i] ?? ('#' . $i))];
            usort($sales, fn($a, $b) => strcmp($a['name'], $b['name'])); json_response(['blocked' => wa_to_blocked($pdo), 'sales' => $sales]);
        }
        case 'wa.price_photo': {   // 💰 the customer price photo (made on the Price charts page: sizes + one column's price, no %)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $u = function_exists('pc_photo_url') ? pc_photo_url($pdo) : ''; if ($u === '') json_response(['error' => 'No price photo yet. Admin makes it on the Price charts page (Save makes the photo).'], 422);
            if (!function_exists('wa_send_image_link') && is_file(__DIR__ . '/wa_prod.php')) require_once __DIR__ . '/wa_prod.php';
            [$ok, $res] = wa_send_image_link($pdo, $c, $u, '', 'emp', (int)($auth['employee_id'] ?? 0)); if (!$ok) json_response(['error' => (string)$res], 422);
            json_response(['ok' => true, 'message' => 'Price photo sent.']);
        }
        case 'wa.optin': {   // the "Opted in" switch: off = the customer is never messaged (same as the customer sending STOP)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $out = empty($in['opted_in']) ? 1 : 0; $pdo->prepare("UPDATE wa_contacts SET optout = ? WHERE phone = ?")->execute([$out, $key]);
            wa_event($pdo, $key, $out ? 'Marked as opted out — no messages will be sent' : 'Opted back in'); json_response(['ok' => true, 'optout' => $out === 1]);
        }
        case 'wa.assign': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $emp = max(0, (int)($in['emp_id'] ?? 0)); $c0 = $key ? wa_contact($pdo, $key) : null;
            if (!$c0) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_is_admin($pdo, $auth)) {   // a salesperson may only take an unassigned chat for themselves ("Take it")
                $me = (int)($auth['employee_id'] ?? 0);
                if ($me <= 0 || $emp !== $me) json_response(['error' => 'Only an admin can assign a chat to someone else. Use Transfer for your own chats.'], 403);
                if ((int)$c0['assigned_emp'] > 0) json_response(['error' => 'This chat is already with ' . (employee_names_by_id($pdo)[(int)$c0['assigned_emp']] ?? 'someone') . '.'], 409);
                wa_assign_to($pdo, $key, $me, $me, 'take'); json_response(['ok' => true]);
            }
            if ($emp > 0 && !isset(employee_names_by_id($pdo)[$emp])) json_response(['error' => 'That person was not found.'], 422);
            wa_assign_to($pdo, $key, $emp, (int)($auth['employee_id'] ?? 0), !empty($in['auto']) ? 'auto' : 'manual');
            json_response(['ok' => true]);
        }
        case 'wa.close': case 'wa.reopen': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $key = wa_key(json_input()['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $nm0 = employee_names_by_id($pdo)[(int)($auth['employee_id'] ?? 0)] ?? 'Admin';
            if ($__wa === 'wa.close' && function_exists('wag_close_guard') && ($why = wag_close_guard($pdo, $auth, $c)) !== '') json_response(['error' => $why, 'need_result' => true], 422);   // every chat ends with a result
            if ($__wa === 'wa.close') { $pdo->prepare("UPDATE wa_contacts SET status = 'closed', closed_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $key]); wa_event($pdo, $key, 'Resolved by ' . $nm0); }
            else { $pdo->prepare("UPDATE wa_contacts SET status = 'open', closed_at = NULL WHERE phone = ?")->execute([$key]); wa_event($pdo, $key, 'Re-opened by ' . $nm0); }
            json_response(['ok' => true]);
        }
        case 'wa.tag': {   // Claude's tag + summary for one chat (re-reads only when something new arrived, unless force)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            ignore_user_abort(true);
            [$ok, $r] = wa_tag_contact($pdo, $c, !empty($in['force']));
            if (!$ok) json_response(['error' => (string)$r], 502);
            json_response(['ok' => true, 'tag' => $r['tag'], 'tag_label' => WA_TAGS[$r['tag']] ?? '', 'summary' => $r['summary'], 'fresh' => $r['fresh']]);
        }
        case 'wa.media': {
            $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? '')); if ($id === '') json_response(['error' => 'No media id.'], 422);
            // a library photo keeps the same Meta id for 25 days and goes to many customers: allowed when ANY chat that has it is yours (not just the first one found)
            $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE media_id = ? LIMIT 50"); $q->execute([$id]); $phs = $q->fetchAll(PDO::FETCH_COLUMN); if (!$phs) json_response(['error' => 'Media not found.'], 404);
            $okc = false; foreach ($phs as $ph0) { $c = wa_contact($pdo, (string)$ph0); if ($c && wa_can_watch($pdo, $auth, $c)) { $okc = true; break; } }
            if (!$okc) $okc = cx_can_media($pdo, $auth, $phs);   // 🎫 the Complaint team sees the customer's photos / videos of a ticket
            if (!$okc) foreach ($phs as $ph0) { $c0 = wa_contact($pdo, (string)$ph0); if ($c0 && wa_req_peek($pdo, $c0) !== '') { $okc = true; break; } }   // 🙋 a Requests chat being read before Take over
            if (!$okc) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            @set_time_limit(300); [$path, $mime, $err] = wa_mcache_get($pdo, $id); if ($path === '') json_response(['error' => $err], 502);
            wa_mcache_send($path, $mime); exit;   // ⚡ from our own server (cached), with byte ranges so phones can play / seek videos at once
        }
        default: json_response(['error' => 'Unknown WhatsApp action.'], 404);
    }
}
}
