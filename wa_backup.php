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
 *   wa.media        GET  {id}  a photo / voice note / document, fetched through the server (the token never reaches the browser)
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
    static $ok = null; if ($ok !== null) return $ok;
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
        $ok = true;
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
/** Graph API call with the token. $path like "/123/messages". */
function wa_api(string $method, string $path, ?array $json = null): array
{
    $tok = wa_cfg_val('WA_TOKEN'); if ($tok === '') return [0, ['error' => ['message' => 'WA_TOKEN is missing in config.php.']], ''];
    $h = ['Authorization: Bearer ' . $tok]; $body = null;
    if ($json !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($json, JSON_UNESCAPED_UNICODE); }
    return wa_http($method, 'https://graph.facebook.com/' . wa_graph() . $path, $h, $body);
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
    $q = $pdo->prepare("SELECT phone, source FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $ex = $q->fetch();
    if (!$ex) $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, last_in_at, last_number_id, state, status, source, ref_text) VALUES (?, ?, ?, ?, ?, ?, 'bot', 'open', ?, ?)")->execute([$key, (string)($m['from'] ?? ''), mb_substr($name, 0, 120), $now, $now, $numberId, $ref ? 'query' : '', $refTxt]);
    else {
        $pdo->prepare("UPDATE wa_contacts SET wa_id = ?, name = CASE WHEN name = '' THEN ? ELSE name END, last_in_at = ?, last_number_id = ?, status = 'open', closed_at = NULL WHERE phone = ?")->execute([(string)($m['from'] ?? ''), mb_substr($name, 0, 120), $now, $numberId, $key]);   // a closed chat re-opens when the customer writes again
        if ($ref && (string)$ex['source'] === '') $pdo->prepare("UPDATE wa_contacts SET source = 'query', ref_text = ? WHERE phone = ?")->execute([$refTxt, $key]);
    }
    try {
        $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, ts) VALUES (?, ?, ?, 'in', ?, ?, ?, ?, 'received', 'customer', ?)")
            ->execute([(string)($m['id'] ?? ''), $key, $numberId, mb_substr($t, 0, 16), $body, $mid, mb_substr($mime, 0, 60), $now]);
    } catch (PDOException $e) { /* the same wamid twice (Meta retries) — already stored */ }
    // STOP / unsubscribe words → opt out for every number
    if ($t === 'text' && preg_match('/^\s*(stop|unsubscribe|band karo|band kro|no more)\s*[.!]*\s*$/iu', $body)) $pdo->prepare("UPDATE wa_contacts SET optout = 1 WHERE phone = ?")->execute([$key]);
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
function wa_can_chat(PDO $pdo, array $auth, array $contact): bool
{
    if (wa_is_admin($pdo, $auth)) return true;
    $e = (int)($auth['employee_id'] ?? 0); return $e > 0 && (int)$contact['assigned_emp'] === $e;
}
function wa_contact(PDO $pdo, string $key): ?array { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$key]); $r = $q->fetch(); return $r ?: null; }

/** Sends a text from the number the customer last used. Inside the 24-hour window only (templates come with the campaign step). */
function wa_send_text(PDO $pdo, array $c, string $text, string $who, int $empId): array
{
    if ((int)$c['optout'] === 1) return [false, 'This customer opted out — do not message them.'];
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return [false, 'The 24-hour reply window is closed. Only an approved template can be sent now.'];
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn();
    if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]]);
    $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err($d); wa_log($pdo, 'send', false, $c['phone'] . ' · ' . $e); return [false, $e]; }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'text', ?, 'sent', ?, ?, ?)")->execute([$wamid, $c['phone'], (int)$c['last_number_id'], $text, $who, $empId, $now]);
    $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$now, $c['phone']]);
    return [true, $wamid];
}

if (!defined('WA_MAX_OPEN')) define('WA_MAX_OPEN', 8);   // one salesperson holds at most 8 open chats
if (!defined('WA_TAGS')) define('WA_TAGS', ['price' => 'Price', 'buy' => 'Wants to buy', 'order_status' => 'Order status', 'complaint' => 'Complaint', 'other' => 'Other']);

/** Sales people (department name contains "sales"), who is present today, how many open chats each has — best pick first. */
function wa_people(PDO $pdo): array
{
    $rows = $pdo->query("SELECT e.employee_id, e.full_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%' ORDER BY e.full_name")->fetchAll();
    $since = date('Y-m-d H:i:s', time() - 20 * 3600); $out = [];   // same rule as attendance_open_row(): punched in within 20 h, not out yet
    foreach ($rows as $r) {
        $id = (int)$r['employee_id'];
        $q = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ?"); $q->execute([$id, $since]);   // open shift of the last 20 h, not "today's row": shifts run past midnight $present = (int)$q->fetchColumn() > 0;
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = ? AND status = 'open'"); $q->execute([$id]); $open = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT MAX(at) FROM wa_assign_log WHERE to_emp = ?"); $q->execute([$id]); $last = (string)$q->fetchColumn();
        $out[] = ['emp_id' => $id, 'name' => (string)$r['full_name'], 'present' => $present, 'open' => $open, 'full' => $open >= WA_MAX_OPEN, 'last_assigned' => $last];
    }
    // present first, not full first, fewest open chats, then the one who has waited longest since the last assignment
    usort($out, fn($a, $b) => [(int)$b['present'], (int)!$b['full'], $a['open'], $a['last_assigned'], $a['name']] <=> [(int)$a['present'], (int)!$a['full'], $b['open'], $b['last_assigned'], $b['name']]);
    $best = true;
    foreach ($out as &$o) {
        $o['best'] = $best && $o['present'] && !$o['full']; if ($o['best']) $best = false;
        $o['why'] = !$o['present'] ? 'Not at work today' : ($o['full'] ? 'Has ' . $o['open'] . ' open chats (full)' : ($o['best'] ? 'Present · fewest open chats (' . $o['open'] . ')' : 'Present · ' . $o['open'] . ' open chat' . ($o['open'] === 1 ? '' : 's')));
    } unset($o);
    return $out;
}
function wa_counts(PDO $pdo, bool $adm, int $emp): array
{
    $one = function (string $where, array $p = []) use ($pdo) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE $where"); $q->execute($p); return (int)$q->fetchColumn(); };
    $wait = "last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) AND optout = 0 AND status = 'open'";
    if ($emp <= 0) $emp = -1;   // an admin login that is not an employee has no chats of their own
    if (!$adm) return ['mine' => $one("assigned_emp = ? AND status = 'open'", [$emp]), 'closed' => $one("assigned_emp = ? AND status = 'closed'", [$emp]), 'waiting' => $one("assigned_emp = ? AND $wait", [$emp])];
    return ['mine' => $one("assigned_emp = ? AND status = 'open'", [$emp]), 'unassigned' => $one("assigned_emp = 0 AND status = 'open'"), 'all' => $one("status = 'open'"), 'closed' => $one("status = 'closed'"), 'waiting' => $one($wait)];
}
/** A line in the chat timeline ("Given to Priya by Admin") — not a WhatsApp message, never sent to the customer. */
function wa_event(PDO $pdo, string $key, string $text): void
{
    try { $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, status, who, ts) VALUES (?, ?, 0, 'sys', 'event', ?, 'sent', 'system', ?)")->execute(['sys.' . uniqid('', true), $key, mb_substr($text, 0, 300), date('Y-m-d H:i:s')]); } catch (PDOException $e) { }
}
function wa_assign_to(PDO $pdo, string $key, int $emp, int $by, string $how): void
{
    $c = wa_contact($pdo, $key); if (!$c) return; $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ?, assigned_at = ?, state = ?, status = 'open', closed_at = NULL WHERE phone = ?")->execute([$emp, $emp > 0 ? $now : null, $emp > 0 ? 'human' : 'unassigned', $key]);
    $pdo->prepare("INSERT INTO wa_assign_log (phone, from_emp, to_emp, by_emp, how, at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$key, (int)$c['assigned_emp'], $emp, $by, $how, $now]);
    $nm = employee_names_by_id($pdo); $to = $emp > 0 ? ($nm[$emp] ?? 'someone') : ''; $from = (int)$c['assigned_emp'] > 0 ? ($nm[(int)$c['assigned_emp']] ?? 'someone') : ''; $who = $by > 0 ? ($nm[$by] ?? 'Admin') : 'Admin';
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
function wa_contact_row(array $r, array $names): array
{
    $in = $r['last_in_at'] ? strtotime((string)$r['last_in_at']) : 0; $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
    return ['phone' => $r['phone'], 'name' => $r['name'], 'assigned_emp' => (int)$r['assigned_emp'], 'assigned_name' => $names[(int)$r['assigned_emp']] ?? '', 'state' => $r['state'], 'status' => $r['status'], 'optout' => (int)$r['optout'] === 1,
        'source' => $r['source'], 'ref_text' => $r['ref_text'], 'unread' => (int)($r['unread'] ?? 0), 'tag' => $r['tag'], 'tag_label' => WA_TAGS[$r['tag']] ?? '', 'summary' => $r['summary'],
        'last_at' => $r['last_in_at'] ?: $r['last_out_at'], 'waiting' => $in > 0 && $in > $o && (int)$r['optout'] === 0, 'window_open' => $in > time() - 86400, 'window_left_min' => $in > time() - 86400 ? (int)floor(($in + 86400 - time()) / 60) : 0];
}

// ------------------------------------------------------------------------------------------------------------------------ router
$__wa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wa, 'wa.') === 0) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    require_once __DIR__ . '/wa_tpl.php';   // pre-made messages (templates): defines its helpers and answers the wa.tpl_* actions

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
        $raw = wa_body();
        if (!wa_sig_ok($raw)) { wa_log($pdo, 'webhook', false, 'Rejected: the signature does not match WA_APP_SECRET.'); wa_raw('bad signature', 403); }
        $j = json_decode($raw, true); $n = 0; $st = 0;
        try {
            foreach ((array)($j['entry'] ?? []) as $en) foreach ((array)($en['changes'] ?? []) as $ch) {
                $v = (array)($ch['value'] ?? []); $md = (array)($v['metadata'] ?? []);
                if (($ch['field'] ?? '') === 'message_template_status_update') { if (wa_tpl_tables($pdo)) wa_tpl_status_event($pdo, $v); continue; }   // Meta approved / rejected a pre-made message
                $nid = wa_number_id($pdo, (string)($md['phone_number_id'] ?? ''), (string)($md['display_phone_number'] ?? ''));
                $names = []; foreach ((array)($v['contacts'] ?? []) as $ct) $names[wa_key($ct['wa_id'] ?? '')] = (string)($ct['profile']['name'] ?? '');
                foreach ((array)($v['messages'] ?? []) as $m) { $k = wa_store_in($pdo, (array)$m, $names[wa_key($m['from'] ?? '')] ?? '', $nid); if ($k !== '') $n++; }
                foreach ((array)($v['statuses'] ?? []) as $s) {
                    $new = (string)($s['status'] ?? ''); $id = (string)($s['id'] ?? ''); if ($id === '' || wa_status_rank($new) === 0) continue;
                    $q = $pdo->prepare("SELECT status FROM wa_messages WHERE wamid = ?"); $q->execute([$id]); $old = $q->fetchColumn();
                    if ($old !== false && wa_status_rank($new) > wa_status_rank((string)$old)) { $pdo->prepare("UPDATE wa_messages SET status = ? WHERE wamid = ?")->execute([$new, $id]); $st++; }
                    if ($new === 'failed') wa_log($pdo, 'status', false, wa_key($s['recipient_id'] ?? '') . ' · ' . ((string)($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'message failed')));
                }
            }
            if ($n || $st) wa_log($pdo, 'webhook', true, $n . ' message' . ($n === 1 ? '' : 's') . ', ' . $st . ' status update' . ($st === 1 ? '' : 's'));
        } catch (Throwable $e) { wa_log($pdo, 'webhook', false, 'Error: ' . $e->getMessage()); }
        wa_raw('ok');   // always 200 once the signature is right, so Meta does not retry forever
    }

    $auth = require_auth();
    if (!wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    switch ($__wa) {
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
            foreach ($out['numbers'] as &$nn) { $nn['id'] = (int)$nn['id']; $nn['messages'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_messages WHERE number_id = " . (int)$nn['id'])->fetchColumn(); } unset($nn);
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
            if ($c < 200 || $c >= 300) { $e = wa_err($d); wa_log($pdo, 'subscribe', false, $e); json_response(['error' => $e], 502); }
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
                if (!wa_number_row($pdo, $id)) json_response(['error' => 'Number not found.'], 404);
                $pdo->prepare("UPDATE wa_numbers SET label = ?, panel = ?, waba_id = ? WHERE id = ?")->execute([$label, $panel, $wb, $id]);   // the Phone number ID of a saved number never changes
            } else {
                if (strlen($pid) < 8 || strlen($pid) > 20) json_response(['error' => 'Paste the Phone number ID from Meta (digits only, about 15 long) — not the phone number itself.'], 422);
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
        case 'wa.chats': {
            $scope = (string)($_GET['scope'] ?? 'mine'); $q = trim((string)($_GET['q'] ?? '')); $emp = (int)($auth['employee_id'] ?? 0); $adm = wa_is_admin($pdo, $auth) && empty($_GET['mine']);   // mine=1 → the My Chats page: even an admin sees only their own
            $w = []; $p = [];
            if (!$adm) { $w[] = 'c.assigned_emp = ?'; $p[] = $emp; $w[] = $scope === 'closed' ? "c.status = 'closed'" : "c.status = 'open'"; }
            elseif ($scope === 'mine') { $w[] = 'c.assigned_emp = ?'; $p[] = $emp > 0 ? $emp : -1; $w[] = "c.status = 'open'"; }
            elseif ($scope === 'unassigned') { $w[] = 'c.assigned_emp = 0'; $w[] = "c.status = 'open'"; }
            elseif ($scope === 'closed') $w[] = "c.status = 'closed'";
            elseif ($scope === 'emp') { $w[] = 'c.assigned_emp = ?'; $p[] = (int)($_GET['emp'] ?? 0); $w[] = "c.status = 'open'"; }
            else $w[] = "c.status = 'open'";
            if ($q !== '') { $w[] = '(c.phone LIKE ? OR c.name LIKE ?)'; $p[] = '%' . addcslashes($q, '%_\\') . '%'; $p[] = '%' . addcslashes($q, '%_\\') . '%'; }
            $sql = "SELECT c.*, (SELECT body FROM wa_messages m WHERE m.phone = c.phone AND m.dir != 'sys' ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT COUNT(*) FROM wa_messages u WHERE u.phone = c.phone AND u.dir = 'in' AND u.id > c.seen_msg_id) AS unread
                    FROM wa_contacts c WHERE " . implode(' AND ', $w) . " ORDER BY COALESCE(c.last_in_at, c.last_out_at) DESC LIMIT 150";
            $st = $pdo->prepare($sql); $st->execute($p); $rows = $st->fetchAll(); $names = employee_names_by_id($pdo);
            $out = array_map(function ($r) use ($names) { $x = wa_contact_row($r, $names); $x['preview'] = mb_substr((string)$r['last_body'], 0, 90); return $x; }, $rows);
            json_response(['chats' => $out, 'counts' => wa_counts($pdo, $adm, $emp), 'admin' => $adm]);
        }
        case 'wa.chat': {
            $key = wa_key($_GET['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null;
            if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $lim = max(1, min(300, (int)($_GET['limit'] ?? 100))); $before = (int)($_GET['before'] ?? 0);
            $st = $pdo->prepare("SELECT m.id, m.dir, m.mtype, m.body, m.media_id, m.media_mime, m.status, m.who, m.emp_id, m.ts, m.number_id FROM wa_messages m WHERE m.phone = ?" . ($before ? ' AND m.id < ?' : '') . " ORDER BY m.id DESC LIMIT $lim");
            $st->execute($before ? [$key, $before] : [$key]); $msgs = array_reverse($st->fetchAll());
            $nums = []; foreach ($pdo->query("SELECT id, display, label, panel FROM wa_numbers")->fetchAll() as $n) $nums[(int)$n['id']] = $n['label'] !== '' ? $n['label'] : ($n['display'] !== '' ? $n['display'] : 'Number ' . $n['id']);
            $names = employee_names_by_id($pdo);
            foreach ($msgs as &$m) { $m['id'] = (int)$m['id']; $m['number'] = $nums[(int)$m['number_id']] ?? ''; $m['emp_name'] = $names[(int)$m['emp_id']] ?? ''; } unset($m);
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
            json_response(['contact' => $ct, 'messages' => $msgs, 'assign_log' => $log]);
        }
        case 'wa.send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $text = trim((string)($in['text'] ?? ''));
            $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if ($text === '') json_response(['error' => 'Write a message first.'], 422); if (mb_strlen($text) > 4000) json_response(['error' => 'The message is too long (max 4000 characters).'], 422);
            [$ok, $r] = wa_send_text($pdo, $c, $text, 'emp', (int)($auth['employee_id'] ?? 0));
            if (!$ok) json_response(['error' => $r], 422);
            $pdo->prepare("UPDATE wa_contacts SET state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$key]);   // a person replied → the bot stays quiet, a closed chat re-opens
            json_response(['ok' => true, 'wamid' => $r]);
        }
        case 'wa.people': {   // salespeople with today's presence + open chats; the first "best" one is the recommendation (a salesperson gets the short list for "Transfer to")
            $pp = wa_people($pdo);
            if (!wa_is_admin($pdo, $auth)) $pp = array_map(fn($x) => ['emp_id' => $x['emp_id'], 'name' => $x['name'], 'present' => $x['present'], 'full' => $x['full']], $pp);
            json_response(['people' => $pp, 'max_open' => WA_MAX_OPEN]);
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
        case 'wa.optin': {   // the "Opted in" switch: off = the customer is never messaged (same as the customer sending STOP)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $out = empty($in['opted_in']) ? 1 : 0; $pdo->prepare("UPDATE wa_contacts SET optout = ? WHERE phone = ?")->execute([$out, $key]);
            wa_event($pdo, $key, $out ? 'Marked as opted out — no messages will be sent' : 'Opted back in'); json_response(['ok' => true, 'optout' => $out === 1]);
        }
        case 'wa.assign': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); wa_guard_admin($pdo, $auth);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $emp = max(0, (int)($in['emp_id'] ?? 0));
            if (!$key || !wa_contact($pdo, $key)) json_response(['error' => 'Chat not found.'], 404);
            if ($emp > 0 && !isset(employee_names_by_id($pdo)[$emp])) json_response(['error' => 'That person was not found.'], 422);
            wa_assign_to($pdo, $key, $emp, (int)($auth['employee_id'] ?? 0), !empty($in['auto']) ? 'auto' : 'manual');
            json_response(['ok' => true]);
        }
        case 'wa.close': case 'wa.reopen': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $key = wa_key(json_input()['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $nm0 = employee_names_by_id($pdo)[(int)($auth['employee_id'] ?? 0)] ?? 'Admin';
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
            $q = $pdo->prepare("SELECT phone, media_mime FROM wa_messages WHERE media_id = ? LIMIT 1"); $q->execute([$id]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Media not found.'], 404);
            $c = wa_contact($pdo, (string)$r['phone']); if (!$c || !wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            [$cd, $d] = wa_api('GET', '/' . $id); if ($cd < 200 || $cd >= 300 || empty($d['url'])) json_response(['error' => wa_err($d, 'Meta could not give this file (it may have expired).')], 502);
            [$c2, , $raw] = wa_http('GET', (string)$d['url'], ['Authorization: Bearer ' . wa_cfg_val('WA_TOKEN')], null, true);
            if ($c2 < 200 || $c2 >= 300 || $raw === '') json_response(['error' => 'Could not download the file from Meta.'], 502);
            $mime = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string)($d['mime_type'] ?? '')) ? (string)$d['mime_type'] : 'application/octet-stream';
            header('Content-Type: ' . $mime); header('Cache-Control: private, max-age=3600'); echo $raw; exit;
        }
        default: json_response(['error' => 'Unknown WhatsApp action.'], 404);
    }
}
}
