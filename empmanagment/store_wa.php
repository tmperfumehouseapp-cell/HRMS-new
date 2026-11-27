<?php
/**
 * Store → WhatsApp: the Store's OWN WhatsApp inbox on its own Cloud-API number (a number added in WhatsApp → Numbers, in its own WhatsApp Business Account).
 *
 *  - The number is chosen in the Store WhatsApp setup (setting 'swa' in st_cs_settings: number_id + handlers). Until it is chosen nothing here changes anything.
 *  - Everything a customer writes to THAT number is kept by sw_in() (called from the webhook in wa.php) and never goes through the sales pipeline
 *    (no bot, no auto-assign, no growth flows, no wa_contacts row): the sales team never sees these chats.
 *  - Only the "handlers" (the Store staff the Admin ticks) and Admin / HR open this inbox (sw_auth).
 *  - The bill message, the birthday message and the reminders of the Store go out from this number (store_lib.php picks it with sw_cfg()).
 *  - Messages live in their OWN table st_wa_msg (never in wa_messages), so no sales / complaint / AI code can read them. Delivery / read ticks come through sw_status() from the webhook.
 * Actions (all st_wa.*): cfg, cfg_save, check, chats, chat, poll, send, tpl_list, tpl_send, send_media, media_link, note_add, tag_set, assign, status, quick_save, quick_del.
 * Own tables: st_wa_chat, st_wa_note, st_wa_tag, st_wa_read, st_wa_quick.
 */

function sw_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_chat (phone VARCHAR(12) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', assigned_emp INT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'open',
            last_in_at DATETIME NULL, last_out_at DATETIME NULL, optout TINYINT NOT NULL DEFAULT 0, updated_at DATETIME NULL, KEY k_st (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_msg (id BIGINT AUTO_INCREMENT PRIMARY KEY, wamid VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL, dir VARCHAR(3) NOT NULL, mtype VARCHAR(16) NOT NULL DEFAULT 'text', body TEXT NULL,
            media_id VARCHAR(80) NOT NULL DEFAULT '', media_mime VARCHAR(60) NOT NULL DEFAULT '', status VARCHAR(12) NOT NULL DEFAULT '', who VARCHAR(12) NOT NULL DEFAULT '', emp_id INT NOT NULL DEFAULT 0, ts DATETIME NULL, reply_to VARCHAR(120) NULL,
            UNIQUE KEY uq_wamid (wamid), KEY k_phone (phone, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_note (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, emp_name VARCHAR(120) NOT NULL DEFAULT '', txt TEXT NULL, at DATETIME NULL, KEY k_p (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_tag (phone VARCHAR(12) NOT NULL, tag VARCHAR(30) NOT NULL, PRIMARY KEY (phone, tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_read (emp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, last_id BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (emp_id, phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_view (emp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, at DATETIME NULL, PRIMARY KEY (emp_id, phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS st_wa_quick (id INT AUTO_INCREMENT PRIMARY KEY, shortcut VARCHAR(30) NOT NULL, body TEXT NULL, sort_no INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE st_wa_msg ADD COLUMN err VARCHAR(220) NOT NULL DEFAULT ''"); } catch (Throwable $e) { }      // why WhatsApp could not deliver a message (shown in the chat)
        try { $pdo->exec("UPDATE st_wa_msg SET who = 'emp' WHERE dir = 'out' AND who = 'store' AND mtype <> 'template'"); } catch (Throwable $e) { }      // an earlier build marked a person's own text / photo as "automatic"
        if ((int)$pdo->query("SELECT COUNT(*) FROM st_wa_quick")->fetchColumn() === 0 && (string)setting_get($pdo, 'sw_quick_seed', '') === '') {      // only neutral ones: the owner writes the rest (address, exchange, offers ...)
            $ins = $pdo->prepare("INSERT INTO st_wa_quick (shortcut, body, sort_no) VALUES (?, ?, ?)");
            foreach ([['/hello', 'Hello {name}, thank you for writing to TM Perfume House. How can we help you?'], ['/thanks', 'Thank you for shopping at TM Perfume House! We hope to see you again 🙏'], ['/bill', 'Your bill link: {bill_link}']] as $i => $q) $ins->execute([$q[0], $q[1], $i + 1]);
            setting_set($pdo, 'sw_quick_seed', '1');
        }
        return $ok = true;
    } catch (Throwable $e) { error_log('sw_tables: ' . $e->getMessage()); return $ok = false; }
}

/** The Store WhatsApp settings: number_id (wa_numbers.id; 0 = not chosen) and handlers (employee ids who may open the inbox). */
function sw_cfg(PDO $pdo): array
{
    $d = ['number_id' => 0, 'handlers' => [], 'tpl_ids' => []];
    try { $v = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = 'swa'")->fetchColumn(); $j = $v ? json_decode((string)$v, true) : null; if (is_array($j)) foreach ($d as $k => $_) if (array_key_exists($k, $j)) $d[$k] = $j[$k]; } catch (Throwable $e) { }
    $d['number_id'] = (int)$d['number_id']; $d['handlers'] = array_values(array_unique(array_filter(array_map('intval', (array)$d['handlers'])))); $d['tpl_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)$d['tpl_ids']))));
    return $d;
}
function sw_cfg_put(PDO $pdo, array $c): void { $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES ('swa', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([json_encode($c)]); }
/** The wa_numbers row of the Store number, or null when none is chosen / it was removed. */
function sw_number(PDO $pdo): ?array
{
    $c = sw_cfg($pdo); if ($c['number_id'] <= 0) return null;
    try { $q = $pdo->prepare("SELECT * FROM wa_numbers WHERE id = ?"); $q->execute([$c['number_id']]); $r = $q->fetch(); return $r ?: null; } catch (Throwable $e) { return null; }
}
function sw_is_number(PDO $pdo, int $numberId): bool { $c = sw_cfg($pdo); return $numberId > 0 && $c['number_id'] === $numberId; }

/** Who may open the inbox: Admin, HR, and the handlers. Returns [employee_id, is_manager]. */
function sw_auth(PDO $pdo, array $auth, bool $managerOnly = false): array
{
    $emp = (int)($auth['employee_id'] ?? 0); $mgr = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if ($managerOnly) { if (!$mgr) json_response(['error' => 'Only the Admin or HR can change this.'], 403); return [$emp, true]; }
    if ($mgr) return [$emp, true];
    if ($emp > 0 && in_array($emp, sw_cfg($pdo)['handlers'], true)) return [$emp, false];
    json_response(['error' => 'The Store WhatsApp is only for the Store team. Ask the Admin to add you.'], 403);
}
function sw_empname(PDO $pdo, int $id): string { if ($id <= 0) return ''; try { $q = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ?"); $q->execute([$id]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; } }

/* ============================== inbound (called by the webhook in wa.php for the Store number) ============================== */
function sw_in(PDO $pdo, array $m, string $name, int $numberId): string
{
    $key = wa_key($m['from'] ?? ''); if ($key === '' || !sw_tables($pdo)) return '';
    $now = wa_ts($m['timestamp'] ?? 0); [$t, $body, $mid, $mime] = wa_parse_msg($m);
    try {
        $pdo->prepare("INSERT INTO st_wa_msg (wamid, phone, dir, mtype, body, media_id, media_mime, status, who, ts) VALUES (?, ?, 'in', ?, ?, ?, ?, 'received', 'customer', ?)")
            ->execute([(string)($m['id'] ?? ''), $key, mb_substr($t, 0, 16), $body, $mid, mb_substr($mime, 0, 60), $now]);
        $ctx = (string)($m['context']['id'] ?? ''); if ($ctx !== '') { try { $pdo->prepare("UPDATE st_wa_msg SET reply_to = ? WHERE wamid = ?")->execute([mb_substr($ctx, 0, 120), (string)($m['id'] ?? '')]); } catch (PDOException $e) { } }
    } catch (PDOException $e) { return ''; }      // the same wamid twice (Meta retries): already stored
    $stop = $t === 'text' && preg_match('/^\s*(stop|unsubscribe|band karo|band kro|no more)\s*[.!]*\s*$/iu', $body) ? 1 : 0;
    $ex = $pdo->prepare("SELECT phone FROM st_wa_chat WHERE phone = ?"); $ex->execute([$key]);
    if ($ex->fetch()) $pdo->prepare("UPDATE st_wa_chat SET name = CASE WHEN name = '' THEN ? ELSE name END, last_in_at = ?, status = 'open', optout = IF(? = 1, 1, optout), updated_at = ? WHERE phone = ?")->execute([mb_substr($name, 0, 120), $now, $stop, $now, $key]);
    else $pdo->prepare("INSERT INTO st_wa_chat (phone, name, status, last_in_at, optout, updated_at) VALUES (?, ?, 'open', ?, ?, ?)")->execute([$key, mb_substr($name, 0, 120), $now, $stop, $now]);
    try {      // a little push to the Store team (never blocks)
        if (function_exists('ct_push') && $t !== 'reaction' && $t !== 'system') { $h = sw_cfg($pdo)['handlers']; if ($h) ct_push($pdo, $h, 'Store WhatsApp', ($name !== '' ? $name : '+' . $key) . ': ' . mb_substr($body !== '' ? $body : '[' . $t . ']', 0, 80), '/store_wa.html?phone=' . $key, 'sw-' . $key); }
    } catch (Throwable $e) { }
    return $key;
}

/** Delivery / read / failed receipts of the Store number's messages (called by the webhook for the Store number only). */
function sw_status(PDO $pdo, array $s): int
{
    $id = (string)($s['id'] ?? ''); $st = strtolower((string)($s['status'] ?? '')); if ($id === '' || !sw_tables($pdo)) return 0;
    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3]; if ($st === 'failed') { $e0 = is_array($s['errors'][0] ?? null) ? $s['errors'][0] : []; $why = trim((string)($e0['code'] ?? '') . ' ' . (string)($e0['title'] ?? '') . ' ' . (string)($e0['error_data']['details'] ?? $e0['message'] ?? '')); $pdo->prepare("UPDATE st_wa_msg SET status = 'failed', err = ? WHERE wamid = ?")->execute([mb_substr($why, 0, 215), $id]);
        try { $ph = $pdo->prepare("SELECT phone, mtype, who FROM st_wa_msg WHERE wamid = ?"); $ph->execute([$id]); $mm = $ph->fetch(); if ($mm && $mm['mtype'] === 'template' && $mm['who'] === 'store' && function_exists('st_wa_bill_failed')) st_wa_bill_failed($pdo, (string)$mm['phone']); } catch (Throwable $e) { } /* a bill that did not arrive must not stay marked sent: the cashier's button comes back */ try { wa_log($pdo, 'send', false, 'store · ' . (string)($s['recipient_id'] ?? '') . ' · ' . mb_substr(json_encode($s['errors'] ?? ''), 0, 160)); } catch (Throwable $e) { } return 1; }
    if (!isset($rank[$st])) return 0; $q = $pdo->prepare("SELECT status FROM st_wa_msg WHERE wamid = ?"); $q->execute([$id]); $cur = $q->fetchColumn(); if ($cur === false) return 0;
    if (($rank[(string)$cur] ?? 0) >= $rank[$st] && $cur !== 'failed') return 0; $pdo->prepare("UPDATE st_wa_msg SET status = ? WHERE wamid = ?")->execute([$st, $id]); return 1;
}

/* ============================== helpers ============================== */
/** [open?, hours left] for the 24-hour reply window of one customer on the Store number. */
function sw_window(PDO $pdo, string $phone, int $nid): array
{
    $q = $pdo->prepare("SELECT MAX(ts) FROM st_wa_msg WHERE phone = ? AND dir = 'in'"); $q->execute([$phone]); $t = $q->fetchColumn();
    if (!$t) return [false, 0.0]; $left = strtotime((string)$t) + 86400 - time(); return [$left > 0, $left > 0 ? round($left / 3600, 1) : 0.0];
}
function sw_names(PDO $pdo, array $phones): array
{
    $out = []; if (!$phones) return $out; $in = implode(',', array_fill(0, count($phones), '?'));
    try { $q = $pdo->prepare("SELECT phone, name FROM st_wa_chat WHERE phone IN ($in) AND name <> ''"); $q->execute(array_values($phones)); foreach ($q->fetchAll() as $r) $out[(string)$r['phone']] = (string)$r['name']; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT RIGHT(phone, 10) k, name FROM st_bill_people WHERE RIGHT(phone, 10) IN ($in) AND name <> '' AND declined = 0 ORDER BY person_id"); $q->execute(array_values($phones)); foreach ($q->fetchAll() as $r) $out[(string)$r['k']] = (string)$r['name']; } catch (Throwable $e) { }   // the name given at billing wins
    return $out;
}
function sw_bills(PDO $pdo, string $phone): array
{
    $o = []; try { $q = $pdo->prepare("SELECT b.bill_no, b.total, b.biz_date, b.status, p.token, p.returning_c FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id WHERE RIGHT(p.phone, 10) = ? ORDER BY b.bill_id DESC LIMIT 8"); $q->execute([$phone]);
        foreach ($q->fetchAll() as $r) $o[] = ['bill_no' => (string)$r['bill_no'], 'total' => (float)$r['total'], 'date' => (string)$r['biz_date'], 'status' => (string)$r['status'], 'link' => function_exists('st_bill_link') ? st_bill_link((string)$r['token']) : '', 'returning' => (int)$r['returning_c']]; } catch (Throwable $e) { }
    return $o;
}
function sw_chat_row(PDO $pdo, string $phone): array
{
    $q = $pdo->prepare("SELECT * FROM st_wa_chat WHERE phone = ?"); $q->execute([$phone]); $r = $q->fetch();
    return $r ?: ['phone' => $phone, 'name' => '', 'assigned_emp' => 0, 'status' => 'open', 'last_in_at' => null, 'last_out_at' => null, 'optout' => 0];
}
function sw_chat_touch(PDO $pdo, string $phone, string $field, string $now): void
{
    $pdo->prepare("INSERT INTO st_wa_chat (phone, status, $field, updated_at) VALUES (?, 'open', ?, ?) ON DUPLICATE KEY UPDATE $field = VALUES($field), status = 'open', updated_at = VALUES(updated_at)")->execute([$phone, $now, $now]);
}
function sw_log_out(PDO $pdo, string $wamid, string $phone, int $nid, string $type, string $body, string $mediaId, string $mime, int $emp): void
{
    $pdo->prepare("INSERT INTO st_wa_msg (wamid, phone, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts) VALUES (?, ?, 'out', ?, ?, ?, ?, 'sent', 'emp', ?, ?)")
        ->execute([$wamid, $phone, mb_substr($type, 0, 16), $body, $mediaId, mb_substr($mime, 0, 60), $emp, date('Y-m-d H:i:s')]);      // typed / sent by a PERSON (an Admin without an employee card has emp 0): never shown as "sent automatically"
}
/** The "to" of a Cloud API call: 91 + the 10 digits. */
function sw_to(string $phone): string { return '91' . substr(preg_replace('/\D/', '', $phone), -10); }
/** Does Meta (the store WABA) have this pre-made message approved? */
function sw_tpl_ok(PDO $pdo, array $num, int $tplId): bool
{
    $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([$tplId, wa_waba_of($num)]); return strtoupper((string)$q->fetchColumn()) === 'APPROVED';
}
/** The pre-made messages the Store inbox shows: the ones made for the Store (name tmph_store_*) and the ones the Admin ticked. */
function sw_tpl_rows(PDO $pdo, array $num): array
{
    $c = sw_cfg($pdo); $out = [];
    foreach ($pdo->query("SELECT * FROM wa_templates ORDER BY id DESC")->fetchAll() as $r) {
        $id = (int)$r['id']; if (!(strpos((string)$r['name'], 'tmph_store_') === 0 || in_array($id, $c['tpl_ids'], true))) continue;
        $t = wa_tpl_row($pdo, $id); if (!$t) continue; $ok = sw_tpl_ok($pdo, $num, $id);
        $st = $ok ? 'APPROVED' : 'waiting'; try { $s = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $s->execute([$id, wa_waba_of($num)]); $x = strtoupper((string)$s->fetchColumn()); if ($x !== '') $st = $x; } catch (Throwable $e) { }
        $out[] = ['id' => $id, 'name' => (string)$t['name'], 'category' => (string)($t['category'] ?? ''), 'body' => (string)$t['body'], 'vars' => count(wa_tpl_vars((string)$t['body'])), 'header' => (string)($t['header_format'] ?? ''), 'status' => $st, 'sendable' => $ok];
    }
    return $out;
}

/* ============================== the actions ============================== */
function sw_handle(PDO $pdo, string $action): void
{
    $auth = require_auth();
    // the WhatsApp helpers (wa_waba_of, wa_api, the template and media functions) are not loaded for an st_* action: load them the same way the billing's automatic WhatsApp does
    if (!function_exists('st_wa_load') || !st_wa_load()) json_response(['error' => 'The WhatsApp module is not available on this server.'], 500);
    if (!function_exists('wa_media_rules') && is_file(__DIR__ . '/wa_console.php')) { try { require_once __DIR__ . '/wa_console.php'; } catch (Throwable $e) { error_log('sw: wa_console ' . $e->getMessage()); } }
    if (function_exists('wa_tpl_tables')) { try { wa_tpl_tables($pdo); } catch (Throwable $e) { } } [$me, $mgr] = sw_auth($pdo, $auth, in_array($action, ['st_wa.cfg_save', 'st_wa.quick_save', 'st_wa.quick_del', 'st_wa.fu_cfg_save', 'st_wa.fu_tpls', 'st_wa.fu_tpl_seed', 'st_wa.fu_tpl_submit', 'st_wa.fu_tpl_sync', 'st_wa.tpl_all', 'st_wa.tpl_seed', 'st_wa.tpl_save', 'st_wa.tpl_check', 'st_wa.tpl_submit', 'st_wa.tpl_sync', 'st_wa.tpl_test', 'st_wa.tpl_diag', 'st_wa.photo_store', 'st_wa.photo_upload'], true));
    if (!sw_tables($pdo)) json_response(['error' => 'The Store WhatsApp tables could not be made.'], 500);
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $num = sw_number($pdo); $cfg = sw_cfg($pdo);
    $need = function () use ($num) { if (!$num) json_response(['error' => 'No Store WhatsApp number is chosen yet. The Admin sets it in the Setup tab.'], 409); return $num; };
    $nid = $num ? (int)$num['id'] : 0;
    if (strpos($action, 'st_wa.fu_') === 0) { require_once __DIR__ . '/store_wa_follow.php'; sr_tables($pdo); swf_handle($pdo, $action, $me, $post); }      // follow-ups 7 / 30 / 60 days after a purchase
    if (strpos($action, 'st_wa.photo_') === 0 || (strpos($action, 'st_wa.tpl_') === 0 && !in_array($action, ['st_wa.tpl_list', 'st_wa.tpl_send'], true))) { require_once __DIR__ . '/store_wa_tpl.php'; swt_handle($pdo, $action, $me, $post); }      // the Store's own pre-made messages (Admin / HR)

    if ($action === 'st_wa.cfg') {
        $o = ['number' => $num ? ['id' => $nid, 'label' => (string)$num['label'], 'display' => (string)($num['display'] ?? ''), 'waba_id' => wa_waba_of($num), 'phone_id' => (string)$num['phone_id']] : null, 'handlers' => $cfg['handlers'], 'manager' => $mgr, 'me' => $me, 'my_name' => sw_empname($pdo, $me),
            'quick' => $pdo->query("SELECT id, shortcut, body FROM st_wa_quick ORDER BY sort_no, id")->fetchAll()];
        foreach ($o['quick'] as &$q) $q['id'] = (int)$q['id']; unset($q);
        if ($mgr) {
            $o['numbers'] = array_map(function ($n) { return ['id' => (int)$n['id'], 'label' => (string)$n['label'], 'display' => (string)($n['display'] ?? ''), 'waba_id' => wa_waba_of($n), 'hold' => (int)($n['hold'] ?? 0)]; }, $pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll());
            $o['staff'] = array_map(function ($e) { return ['id' => (int)$e['employee_id'], 'name' => (string)$e['full_name']]; }, $pdo->query("SELECT employee_id, full_name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll());
            $o['tpl_ids'] = $cfg['tpl_ids'];
            if ($num) { try { $o['templates'] = sw_tpl_rows($pdo, $num); } catch (Throwable $e) { $o['templates'] = []; } }
        }
        json_response($o);
    }
    if ($action === 'st_wa.cfg_save') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $c = $cfg;
        if (array_key_exists('number_id', $in)) { $n = (int)$in['number_id']; if ($n > 0) { $q = $pdo->prepare("SELECT id FROM wa_numbers WHERE id = ?"); $q->execute([$n]); if (!$q->fetch()) json_response(['error' => 'That number is not in WhatsApp → Numbers.'], 422); } $c['number_id'] = $n; }
        if (is_array($in['handlers'] ?? null)) $c['handlers'] = array_slice(array_values(array_unique(array_filter(array_map('intval', $in['handlers'])))), 0, 20);
        if (is_array($in['tpl_ids'] ?? null)) $c['tpl_ids'] = array_slice(array_values(array_unique(array_filter(array_map('intval', $in['tpl_ids'])))), 0, 100);
        sw_cfg_put($pdo, $c); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.quick_save') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $id = (int)($in['id'] ?? 0);
        $sc = '/' . ltrim(preg_replace('/[^\p{L}\p{N}_-]/u', '', mb_strtolower(trim((string)($in['shortcut'] ?? '')))), '/'); $b = trim(mb_substr((string)($in['body'] ?? ''), 0, 1000));
        if (mb_strlen($sc) < 2 || $b === '') json_response(['error' => 'Write a short name (like /address) and the message.'], 422);
        if ($id > 0) $pdo->prepare("UPDATE st_wa_quick SET shortcut = ?, body = ? WHERE id = ?")->execute([mb_substr($sc, 0, 30), $b, $id]);
        else $pdo->prepare("INSERT INTO st_wa_quick (shortcut, body, sort_no) VALUES (?, ?, ?)")->execute([mb_substr($sc, 0, 30), $b, (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) + 1 FROM st_wa_quick")->fetchColumn()]);
        json_response(['ok' => true]);
    }
    if ($action === 'st_wa.quick_del') { if (!$post) json_response(['error' => 'Method not allowed.'], 405); $pdo->prepare("DELETE FROM st_wa_quick WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); json_response(['ok' => true]); }
    if ($action === 'st_wa.check') { $need(); json_response(['check' => wa_number_check($num)]); }

    if ($action === 'st_wa.chats') {
        $need(); $f = (string)($_GET['f'] ?? 'all'); $s = trim((string)($_GET['q'] ?? ''));
        $rows = $pdo->prepare("SELECT phone, MAX(id) mid FROM st_wa_msg GROUP BY phone ORDER BY mid DESC LIMIT 400"); $rows->execute(); $L = $rows->fetchAll(); if (!$L) json_response(['chats' => [], 'unread' => 0, 'unanswered' => 0, 'counts' => ['all' => 0, 'unanswered' => 0, 'mine' => 0, 'done' => 0]]);
        $ph = array_map(fn($r) => (string)$r['phone'], $L); $ids = array_map(fn($r) => (int)$r['mid'], $L); $in = implode(',', array_fill(0, count($ph), '?'));
        $last = []; $q = $pdo->prepare("SELECT id, phone, dir, mtype, body, ts, status FROM st_wa_msg WHERE id IN (" . implode(',', $ids) . ")"); $q->execute(); foreach ($q->fetchAll() as $r) $last[(string)$r['phone']] = $r;
        $un = []; $q = $pdo->prepare("SELECT m.phone, COUNT(*) c FROM st_wa_msg m LEFT JOIN st_wa_read r ON r.emp_id = ? AND r.phone = m.phone WHERE m.dir = 'in' AND m.phone IN ($in) AND m.id > COALESCE(r.last_id, 0) GROUP BY m.phone"); $q->execute(array_merge([$me], $ph)); foreach ($q->fetchAll() as $r) $un[(string)$r['phone']] = (int)$r['c'];
        $meta = []; $q = $pdo->prepare("SELECT * FROM st_wa_chat WHERE phone IN ($in)"); $q->execute($ph); foreach ($q->fetchAll() as $r) $meta[(string)$r['phone']] = $r;
        $tags = []; $q = $pdo->prepare("SELECT phone, tag FROM st_wa_tag WHERE phone IN ($in)"); $q->execute($ph); foreach ($q->fetchAll() as $r) $tags[(string)$r['phone']][] = (string)$r['tag'];
        $names = sw_names($pdo, $ph); $win = []; $q = $pdo->prepare("SELECT phone, MAX(ts) t FROM st_wa_msg WHERE dir = 'in' AND phone IN ($in) GROUP BY phone"); $q->execute($ph); foreach ($q->fetchAll() as $r) $win[(string)$r['phone']] = strtotime((string)$r['t']);
        $out = []; $unread = 0; $unans = 0; $cnt = ['all' => 0, 'unanswered' => 0, 'mine' => 0, 'done' => 0];
        foreach ($ph as $p) {
            $m = $last[$p] ?? null; if (!$m) continue; $mt = $meta[$p] ?? null; $status = $mt ? (string)$mt['status'] : 'open'; $name = $names[$p] ?? ''; $c = $un[$p] ?? 0; $answered = $m['dir'] === 'out'; $w = $win[$p] ?? 0;
            if ($status !== 'done') { $unread += $c; if (!$answered) $unans++; }
            if ($status === 'done') $cnt['done']++; else { $cnt['all']++; if (!$answered) $cnt['unanswered']++; if ((int)($mt['assigned_emp'] ?? 0) === $me) $cnt['mine']++; }
            $show = $f === 'done' ? $status === 'done' : ($status !== 'done' && ($f === 'unanswered' ? !$answered : ($f === 'mine' ? (int)($mt['assigned_emp'] ?? 0) === $me : true))); if (!$show) continue;
            if ($s !== '' && stripos($name, $s) === false && strpos($p, preg_replace('/\D/', '', $s) ?: "\x01") === false) continue;
            $body = (string)$m['body']; if ($body === '' || $m['mtype'] !== 'text') $body = ['image' => '📷 Photo', 'video' => '🎥 Video', 'audio' => '🎙 Voice note', 'document' => '📄 Document', 'template' => $body !== '' ? $body : '🧾 Template', 'sticker' => 'Sticker', 'location' => '📍 Location'][$m['mtype']] ?? ($body !== '' ? $body : '…');
            $out[] = ['phone' => $p, 'name' => $name, 'last' => mb_substr($body, 0, 90), 'dir' => (string)$m['dir'], 'ts' => (string)$m['ts'], 'unread' => $c, 'status' => $status, 'window_open' => $w > time() - 86400, 'tags' => $tags[$p] ?? [], 'mine' => (int)($mt['assigned_emp'] ?? 0) === $me, 'ticks' => (string)$m['status']];
        }
        json_response(['chats' => $out, 'unread' => $unread, 'unanswered' => $unans, 'counts' => $cnt]);
    }
    if ($action === 'st_wa.chat') {
        $need(); $p = wa_key($_GET['phone'] ?? ''); if ($p === '') json_response(['error' => 'No customer.'], 422); $after = max(0, (int)($_GET['after'] ?? 0));
        $q = $pdo->prepare("SELECT id, wamid, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts, reply_to, err FROM st_wa_msg WHERE phone = ? AND id > ? ORDER BY id DESC LIMIT 300"); $q->execute([$p, $after]); $msgs = array_reverse($q->fetchAll());
        $emps = []; foreach ($msgs as &$m) { $m['id'] = (int)$m['id']; $m['emp_id'] = (int)$m['emp_id']; if ($m['dir'] === 'out' && $m['who'] === 'emp') { if ($m['emp_id'] > 0) { if (!isset($emps[$m['emp_id']])) $emps[$m['emp_id']] = sw_empname($pdo, $m['emp_id']); $m['emp_name'] = $emps[$m['emp_id']] !== '' ? $emps[$m['emp_id']] : 'Store team'; } else $m['emp_name'] = 'Admin'; } } unset($m);
        $maxId = $msgs ? (int)end($msgs)['id'] : $after; if ($maxId > 0) $pdo->prepare("INSERT INTO st_wa_read (emp_id, phone, last_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_id = GREATEST(last_id, VALUES(last_id))")->execute([$me, $p, $maxId]);
        $o = ['messages' => $msgs, 'last_id' => $maxId]; [$wo, $wl] = sw_window($pdo, $p, $nid); $o['window_open'] = $wo; $o['window_hours'] = $wl;
        $pdo->prepare("INSERT INTO st_wa_view (emp_id, phone, at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE at = NOW()")->execute([$me, $p]);      // who is looking at this chat right now (a colleague opening the same chat sees it)
        $vq = $pdo->prepare("SELECT emp_id FROM st_wa_view WHERE phone = ? AND emp_id <> ? AND at > DATE_SUB(NOW(), INTERVAL 25 SECOND)"); $vq->execute([$p, $me]); $o['viewers'] = array_values(array_filter(array_map(fn($id) => $id > 0 ? sw_empname($pdo, (int)$id) : 'Admin', $vq->fetchAll(PDO::FETCH_COLUMN))));
        if ($after === 0) {
            $c = sw_chat_row($pdo, $p); $nm = sw_names($pdo, [$p])[$p] ?? (string)$c['name']; $bills = sw_bills($pdo, $p);
            $notes = $pdo->prepare("SELECT emp_name, txt, at FROM st_wa_note WHERE phone = ? ORDER BY id DESC LIMIT 20"); $notes->execute([$p]); $tg = $pdo->prepare("SELECT tag FROM st_wa_tag WHERE phone = ? ORDER BY tag"); $tg->execute([$p]);
            $o += ['phone' => $p, 'name' => $nm, 'status' => (string)$c['status'], 'optout' => (int)$c['optout'], 'assigned_emp' => (int)$c['assigned_emp'], 'assigned_name' => sw_empname($pdo, (int)$c['assigned_emp']), 'bills' => $bills, 'returning' => (int)($bills && $bills[0]['returning'] ? 1 : (count($bills) > 1 ? 1 : 0)),
                'notes' => $notes->fetchAll(), 'tags' => $tg->fetchAll(PDO::FETCH_COLUMN), 'team' => array_map(fn($id) => ['id' => $id, 'name' => sw_empname($pdo, $id)], $cfg['handlers'])];
        }
        json_response($o);
    }
    if ($action === 'st_wa.poll') {
        $need(); $q = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM st_wa_msg"); $q->execute(); json_response(['last_id' => (int)$q->fetchColumn()]);
    }
    if ($action === 'st_wa.send') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $need(); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 4000));
        if ($p === '' || $text === '') json_response(['error' => 'Write the message first.'], 422);
        $c = sw_chat_row($pdo, $p); if ((int)$c['optout'] === 1) json_response(['error' => 'This customer asked us to stop. Do not message them.'], 422);
        [$wo] = sw_window($pdo, $p, $nid); if (!$wo) json_response(['error' => 'The 24-hour reply window is closed. Send an approved template instead.', 'window_closed' => 1], 422);
        [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', ['messaging_product' => 'whatsapp', 'to' => sw_to($p), 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]); $wamid = (string)($d['messages'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err(is_array($d) ? $d : null); wa_log($pdo, 'send', false, 'store · ' . $p . ' · ' . $e); json_response(['error' => $e], 422); }
        sw_log_out($pdo, $wamid, $p, $nid, 'text', $text, '', '', $me); sw_chat_touch($pdo, $p, 'last_out_at', date('Y-m-d H:i:s')); json_response(['ok' => true, 'wamid' => $wamid]);
    }
    if ($action === 'st_wa.tpl_list') { $need(); json_response(['templates' => sw_tpl_rows($pdo, $num)]); }
    if ($action === 'st_wa.tpl_send') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $need(); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $t = wa_tpl_row($pdo, (int)($in['id'] ?? 0)); if ($p === '' || !$t) json_response(['error' => 'Customer or message not found.'], 404);
        $c = sw_chat_row($pdo, $p); $cat = strtolower((string)($t['category'] ?? 'marketing'));
        if ((int)$c['optout'] === 1) json_response(['error' => 'This customer asked us to stop. Do not message them.'], 422);
        if (!(strpos((string)$t['name'], 'tmph_store_') === 0 || in_array((int)$t['id'], $cfg['tpl_ids'], true))) json_response(['error' => 'This message is not one of the Store messages.'], 422);
        if (!sw_tpl_ok($pdo, $num, (int)$t['id'])) json_response(['error' => 'Meta has not approved this message for the Store number yet.'], 422);
        $vars = array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), (array)($in['vars'] ?? [])); $needN = count(wa_tpl_vars((string)$t['body']));
        for ($i = 0; $i < $needN; $i++) if (($vars[$i] ?? '') === '') json_response(['error' => 'Fill the variable {{' . ($i + 1) . '}} first.'], 422);
        $hvar = trim((string)($in['header_var'] ?? '')); $extra = $hvar !== '' ? ['header_var' => $hvar] : [];
        $b = wa_tpl_send_components($pdo, $t, array_slice($vars, 0, $needN), (string)$num['phone_id'], $extra); if (empty($b['ok'])) json_response(['error' => (string)($b['error'] ?? 'Could not build the message.')], 422);
        $pl = ['messaging_product' => 'whatsapp', 'to' => sw_to($p), 'type' => 'template', 'template' => ['name' => $t['name'], 'language' => ['code' => $t['language']]]]; if (!empty($b['components'])) $pl['template']['components'] = $b['components'];
        [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', $pl); $wamid = (string)($d['messages'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err(is_array($d) ? $d : null); wa_log($pdo, 'send', false, 'store · ' . $p . ' · template ' . $t['name'] . ' · ' . $e); json_response(['error' => $e], 422); }
        $shown = function_exists('wa_tpl_shown_text') ? wa_tpl_shown_text($t, $vars, $hvar) : (string)$t['name']; sw_log_out($pdo, $wamid, $p, $nid, 'template', $shown, '', '', $me); sw_chat_touch($pdo, $p, 'last_out_at', date('Y-m-d H:i:s'));
        json_response(['ok' => true, 'wamid' => $wamid]);
    }
    if ($action === 'st_wa.send_media') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $need(); $p = wa_key($_POST['phone'] ?? ''); if ($p === '') json_response(['error' => 'Customer not found.'], 404);
        $c = sw_chat_row($pdo, $p); if ((int)$c['optout'] === 1) json_response(['error' => 'This customer asked us to stop. Do not message them.'], 422);
        [$wo] = sw_window($pdo, $p, $nid); if (!$wo) json_response(['error' => 'The 24-hour reply window is closed. Send an approved template instead.', 'window_closed' => 1], 422);
        $f = $_FILES['file'] ?? null; if (!$f || !isset($f['tmp_name']) || (int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) json_response(['error' => 'The file did not arrive (too big for the server?). Try a smaller one.'], 422);
        $tmp = (string)$f['tmp_name']; $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', basename((string)($f['name'] ?? 'file'))) ?: 'file'; $size = (int)($f['size'] ?? filesize($tmp));
        $det = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $det = (string)finfo_file($fi, $tmp); finfo_close($fi); } } if ($det === '') $det = (string)($f['type'] ?? '');
        $r = wa_media_rules($det, $name); if ($r['kind'] === '') json_response(['error' => $r['error']], 422); if ($size > $r['max']) json_response(['error' => 'This ' . $r['kind'] . ' is too big for WhatsApp (' . round($r['max'] / 1048576) . ' MB at most).'], 422);
        $cap = trim(mb_substr((string)($_POST['caption'] ?? ''), 0, 1000)); ignore_user_abort(true);
        [$uc, $ud] = wa_media_upload((string)$num['phone_id'], $tmp, $r['mime'], $name); $mid = (string)($ud['id'] ?? ''); if ($uc < 200 || $uc >= 300 || $mid === '') json_response(['error' => wa_err(is_array($ud) ? $ud : null, 'Meta did not accept the file.')], 502);
        $obj = ['id' => $mid]; if ($cap !== '' && in_array($r['kind'], ['image', 'video', 'document'], true)) $obj['caption'] = $cap; if ($r['kind'] === 'document') $obj['filename'] = $name;
        [$code, $d] = wa_api('POST', '/' . $num['phone_id'] . '/messages', ['messaging_product' => 'whatsapp', 'to' => sw_to($p), 'type' => $r['kind'], $r['kind'] => $obj]); $wamid = (string)($d['messages'][0]['id'] ?? '');
        if ($code < 200 || $code >= 300 || $wamid === '') json_response(['error' => wa_err(is_array($d) ? $d : null)], 422);
        sw_log_out($pdo, $wamid, $p, $nid, $r['kind'], $cap !== '' ? $cap : ($r['kind'] === 'document' ? $name : ''), $mid, $r['mime'], $me); sw_chat_touch($pdo, $p, 'last_out_at', date('Y-m-d H:i:s')); json_response(['ok' => true, 'kind' => $r['kind']]);
    }
    if ($action === 'st_wa.media_link') {
        $need(); $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? '')); $q = $pdo->prepare("SELECT media_mime FROM st_wa_msg WHERE media_id = ? LIMIT 1"); $q->execute([$id]); $mm = $q->fetch();
        if ($id === '' || !$mm) json_response(['error' => 'File not found.'], 404); $e = time() + 3 * 3600; json_response(['id' => $id, 'e' => $e, 's' => wa_mfile_sig($pdo, $id, $e), 'mime' => (string)$mm['media_mime']]);
    }
    if ($action === 'st_wa.note_add') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $t = trim(mb_substr((string)($in['text'] ?? ''), 0, 1000)); if ($p === '' || $t === '') json_response(['error' => 'Write the note.'], 422);
        $pdo->prepare("INSERT INTO st_wa_note (phone, emp_id, emp_name, txt, at) VALUES (?, ?, ?, ?, ?)")->execute([$p, $me, sw_empname($pdo, $me), $t, date('Y-m-d H:i:s')]); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.tag_set') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['tag'] ?? ''))), 0, 30); if ($p === '' || $t === '') json_response(['error' => 'Write the tag.'], 422);
        if (!empty($in['on'])) $pdo->prepare("INSERT IGNORE INTO st_wa_tag (phone, tag) VALUES (?, ?)")->execute([$p, $t]); else $pdo->prepare("DELETE FROM st_wa_tag WHERE phone = ? AND tag = ?")->execute([$p, $t]); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.assign') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $to = (int)($in['emp_id'] ?? 0); if ($p === '') json_response(['error' => 'No customer.'], 422);
        if ($to > 0 && !in_array($to, $cfg['handlers'], true)) json_response(['error' => 'Only a person from the Store team can take a chat.'], 422);
        $pdo->prepare("INSERT INTO st_wa_chat (phone, assigned_emp, status, updated_at) VALUES (?, ?, 'open', ?) ON DUPLICATE KEY UPDATE assigned_emp = VALUES(assigned_emp), updated_at = VALUES(updated_at)")->execute([$p, $to, date('Y-m-d H:i:s')]); json_response(['ok' => true]);
    }
    if ($action === 'st_wa.status') {
        if (!$post) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $p = wa_key($in['phone'] ?? ''); $s = !empty($in['done']) ? 'done' : 'open'; if ($p === '') json_response(['error' => 'No customer.'], 422);
        $pdo->prepare("INSERT INTO st_wa_chat (phone, status, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)")->execute([$p, $s, date('Y-m-d H:i:s')]); json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}
