<?php
/**
 * feedback.php — the FEEDBACK page (Dispatch → Feedback).
 *
 *   A parcel is delivered → after a few days the customer gets a pre-made WhatsApp message asking for feedback (and a follow-up if there is no reply).
 *   A feedback screenshot the customer sends on WhatsApp is saved on the order (and one added by hand too). A reward (a small free perfume) and a complaint are one tap away.
 *
 *   Tables (new, only created here): fb_orders (one row per delivered order), fb_proofs (screenshots), fb_events (the history of an order).
 *   Nothing is sent until Admin switches "Send messages automatically" on AND picks the approved messages (Meta must approve them first).
 *   The WhatsApp engine calls fb_tick() once a round (see wa_more.php); the page calls fb.* (fb_route).
 *
 *   Actions: fb.list · fb.get · fb.settings_get · fb.settings_save · fb.send_now · fb.followup · fb.proof_add · fb.proof_file · fb.reward · fb.cx_done · fb.skip
 */
if (!function_exists('fb_tables')) {

function fb_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fb_orders (id INT AUTO_INCREMENT PRIMARY KEY, order_name VARCHAR(40) NOT NULL, store_key VARCHAR(20) NOT NULL DEFAULT '', customer VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL DEFAULT '',
            delivered_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'wait', msg_at DATETIME NULL, msg_wamid VARCHAR(120) NOT NULL DEFAULT '', msg_err VARCHAR(190) NOT NULL DEFAULT '', fu_at DATETIME NULL, fu_wamid VARCHAR(120) NOT NULL DEFAULT '',
            replied_at DATETIME NULL, last_in_id BIGINT NOT NULL DEFAULT 0, place VARCHAR(200) NOT NULL DEFAULT '', reward_order VARCHAR(40) NOT NULL DEFAULT '', reward_note VARCHAR(190) NOT NULL DEFAULT '', cx_code VARCHAR(30) NOT NULL DEFAULT '',
            skip_why VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, UNIQUE KEY uq_fb_order (order_name), KEY idx_fb_status (status), KEY idx_fb_phone (phone), KEY idx_fb_del (delivered_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fb_proofs (id INT AUTO_INCREMENT PRIMARY KEY, order_name VARCHAR(40) NOT NULL, path VARCHAR(160) NOT NULL, mime VARCHAR(40) NOT NULL DEFAULT '', src VARCHAR(10) NOT NULL DEFAULT 'manual', media_id VARCHAR(60) NULL,
            place VARCHAR(200) NOT NULL DEFAULT '', by_name VARCHAR(80) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY idx_fbp_order (order_name), UNIQUE KEY uq_fbp_media (order_name, media_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fb_events (id INT AUTO_INCREMENT PRIMARY KEY, order_name VARCHAR(40) NOT NULL, at DATETIME NOT NULL, txt VARCHAR(300) NOT NULL DEFAULT '', by_name VARCHAR(80) NOT NULL DEFAULT '', KEY idx_fbe_order (order_name, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ((string)setting_get($pdo, 'fb_place_w', '') !== '1') {      // the places of an older copy of the table were 60 characters: several places need more room (once)
            try { $pdo->exec("ALTER TABLE fb_orders MODIFY place VARCHAR(200) NOT NULL DEFAULT ''"); $pdo->exec("ALTER TABLE fb_proofs MODIFY place VARCHAR(200) NOT NULL DEFAULT ''"); setting_set($pdo, 'fb_place_w', '1'); } catch (Throwable $e) { error_log('fb place width: ' . $e->getMessage()); }
        }
        return $ok = true;
    } catch (Throwable $e) { error_log('fb_tables: ' . $e->getMessage()); return $ok = false; }
}

/** The review links (Google, Instagram …) made once and reused: [{name, url, msg}]. msg is the message with {name} {order} {link} filled in for each customer. */
function fb_links(PDO $pdo): array
{
    $out = []; $j = json_decode((string)setting_get($pdo, 'fb_links2', ''), true);
    if (is_array($j)) { foreach ($j as $l) if (is_array($l) && preg_match('~^https?://\S+$~i', (string)($l['url'] ?? ''))) $out[] = ['name' => mb_substr(trim((string)($l['name'] ?? '')), 0, 40) ?: 'Link', 'url' => mb_substr((string)$l['url'], 0, 400), 'msg' => mb_substr((string)($l['msg'] ?? ''), 0, 600)]; return array_slice($out, 0, 12); }
    foreach (preg_split('/\r?\n/', (string)setting_get($pdo, 'fb_links', '')) as $ln) { $p = array_map('trim', explode('|', $ln, 2)); if (count($p) === 2 && preg_match('~^https?://~i', $p[1])) $out[] = ['name' => mb_substr($p[0], 0, 40) ?: 'Link', 'url' => mb_substr($p[1], 0, 400), 'msg' => '']; }   // the first version kept them as lines
    return array_slice($out, 0, 12);
}
function fb_links_put(PDO $pdo, array $links): array
{
    $ls = []; foreach ($links as $l) { if (!is_array($l)) continue; $u = trim((string)($l['url'] ?? '')); if (!preg_match('~^https?://\S+$~i', $u)) continue; $n = mb_substr(trim(str_replace(["\n", '|'], ' ', (string)($l['name'] ?? ''))), 0, 40);
        $ls[] = ['name' => $n !== '' ? $n : 'Link', 'url' => mb_substr($u, 0, 400), 'msg' => mb_substr(trim((string)($l['msg'] ?? '')), 0, 600)]; }
    $ls = array_slice($ls, 0, 12); setting_set($pdo, 'fb_links2', json_encode($ls, JSON_UNESCAPED_UNICODE)); return $ls;
}

function fb_cfg(PDO $pdo): array
{
    $places = array_values(array_filter(array_map('trim', explode('|', (string)setting_get($pdo, 'fb_places', 'Google review|Instagram|Facebook|Shopify review')))));
    return ['on' => (string)setting_get($pdo, 'fb_on', '0') === '1', 'wait' => max(1, min(30, (int)setting_get($pdo, 'fb_wait', '5'))), 'fu' => max(1, min(30, (int)setting_get($pdo, 'fb_fu', '3'))),
        'tpl' => (int)setting_get($pdo, 'fb_tpl', '0'), 'tpl_fu' => (int)setting_get($pdo, 'fb_tpl_fu', '0'), 'places' => $places ?: ['Google review'], 'links' => fb_links($pdo), 'reward' => (string)setting_get($pdo, 'fb_reward', '10 ML perfume')];
}

/** One or several places ("Google review", ["Google review", "Instagram"]) → "Google review, Instagram" (clean, no repeats, at most 200 characters). */
function fb_place_str($v): string
{
    $list = is_array($v) ? $v : preg_split('/\s*[,|]\s*/u', (string)$v); $out = [];
    foreach ($list as $x) { $x = mb_substr(trim(str_replace(['|', ','], ' ', (string)$x)), 0, 40); if ($x !== '' && !in_array($x, $out, true)) $out[] = $x; }
    return mb_substr(implode(', ', $out), 0, 200);
}

function fb_log(PDO $pdo, string $order, string $txt, string $by = ''): void
{
    try { $pdo->prepare("INSERT INTO fb_events (order_name, at, txt, by_name) VALUES (?, ?, ?, ?)")->execute([$order, date('Y-m-d H:i:s'), mb_substr($txt, 0, 300), mb_substr($by, 0, 80)]); } catch (Throwable $e) { }
}

/** Delivered parcels of the last 60 days → one row each in fb_orders (a row is never changed by this). At most every 10 minutes from the engine. */
function fb_enroll(PDO $pdo, bool $force = false): int
{
    if (!fb_tables($pdo)) return 0;
    $last = (int)setting_get($pdo, 'fb_enrol_at', '0'); if (!$force && $last > time() - 600) return 0; setting_set($pdo, 'fb_enrol_at', (string)time());
    $n = 0;
    try {
        $q = $pdo->prepare("SELECT order_name, store_key, customer, phone, COALESCE(delivered_at, sr_at, tracked_at, created_at) AS dat FROM dp_dsp_parcels
            WHERE order_name <> '' AND (status = 'delivered' OR delivered_at IS NOT NULL) AND COALESCE(delivered_at, sr_at, tracked_at, created_at) >= ? LIMIT 3000");
        $q->execute([date('Y-m-d H:i:s', time() - 60 * 86400)]);
        $ins = $pdo->prepare("INSERT IGNORE INTO fb_orders (order_name, store_key, customer, phone, delivered_at, status, created_at) VALUES (?, ?, ?, ?, ?, 'wait', ?)");
        foreach ($q->fetchAll() as $r) {
            $key = function_exists('wa_key') ? wa_key((string)$r['phone']) : substr(preg_replace('/\D+/', '', (string)$r['phone']), -10);
            $ins->execute([mb_substr((string)$r['order_name'], 0, 40), mb_substr((string)$r['store_key'], 0, 20), mb_substr((string)$r['customer'], 0, 120), $key, (string)$r['dat'], date('Y-m-d H:i:s')]);
            $n += $ins->rowCount();
        }
    } catch (Throwable $e) { error_log('fb_enroll: ' . $e->getMessage()); }
    return $n;
}

/** The text a customer gets (the approved message with the name filled in) — for the page's preview. */
function fb_text(PDO $pdo, int $tplId, string $name): string
{
    if ($tplId <= 0 || !function_exists('wa_camp_tpl')) return '';
    try { $t = wa_camp_tpl($pdo, $tplId, true, 0); if (!$t) return ''; return trim(preg_replace('/\{\{\s*1\s*\}\}/', $name !== '' ? $name : 'ji', (string)($t['body'] ?? ''))); } catch (Throwable $e) { return ''; }
}

/** Send one message of the order: kind = 'msg' (feedback) or 'fu' (follow-up). [ok, error] */
function fb_send(PDO $pdo, array $row, string $kind, string $by = ''): array
{
    $cfg = fb_cfg($pdo); $tpl = $kind === 'fu' ? $cfg['tpl_fu'] : $cfg['tpl'];
    if ($tpl <= 0) return [false, 'Pick the approved ' . ($kind === 'fu' ? 'follow-up' : 'feedback') . ' message in the settings first.'];
    if (!function_exists('wd_send') || !function_exists('wa_ensure_contact')) return [false, 'The WhatsApp sender is not available.'];
    $key = (string)$row['phone']; if (strlen($key) < 10) return [false, 'This order has no phone number.'];
    $c = wa_ensure_contact($pdo, $key, (string)$row['customer']); if (!$c) return [false, 'Could not open the chat of this customer.'];
    if ((int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) return [false, 'The customer asked to stop messages.'];
    $r = wd_send($pdo, $c, ['tpl_id' => $tpl, 'number_id' => 0]);
    if (empty($r[0])) return [false, (string)$r[1]];
    $now = date('Y-m-d H:i:s');
    if ($kind === 'fu') $pdo->prepare("UPDATE fb_orders SET fu_at = ?, fu_wamid = ? WHERE id = ?")->execute([$now, (string)$r[3], (int)$row['id']]);
    else $pdo->prepare("UPDATE fb_orders SET msg_at = ?, msg_wamid = ?, msg_err = '', status = CASE WHEN status IN ('wait') THEN 'sent' ELSE status END WHERE id = ?")->execute([$now, (string)$r[3], (int)$row['id']]);
    fb_log($pdo, (string)$row['order_name'], ($kind === 'fu' ? 'Follow-up message sent (WhatsApp)' : 'Feedback message sent (WhatsApp)'), $by !== '' ? $by : 'automatic');
    return [true, ''];
}

/** A picture the customer sent on WhatsApp → saved on the order. */
function fb_save_wa_media(PDO $pdo, array $row, string $mediaId, string $mime): bool
{
    if ($mediaId === '' || !function_exists('wa_mcache_get')) return false;
    [$path, $mm, $err] = wa_mcache_get($pdo, $mediaId); if ($path === '' || !is_file($path)) return false;
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][strtolower($mm ?: $mime)] ?? 'jpg';
    $dir = __DIR__ . '/uploads/feedback/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'fb_' . preg_replace('/[^0-9A-Za-z]/', '', (string)$row['order_name']) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!@copy($path, $dir . $name)) return false;
    try { $pdo->prepare("INSERT INTO fb_proofs (order_name, path, mime, src, media_id, created_at) VALUES (?, ?, ?, 'whatsapp', ?, ?)")->execute([(string)$row['order_name'], 'uploads/feedback/' . $name, $mm ?: $mime, $mediaId, date('Y-m-d H:i:s')]); }
    catch (Throwable $e) { @unlink($dir . $name); return false; }   // the same picture twice
    return true;
}

/** The engine's round: enrol, (if switched on) send what is due, read the customers' answers, keep their screenshots. Small and time-limited. */
function fb_tick(PDO $pdo): array
{
    $o = ['enrolled' => 0, 'sent' => 0, 'fu' => 0, 'proofs' => 0, 'replied' => 0];
    if (!fb_tables($pdo)) return $o;
    $t0 = microtime(true); $cfg = fb_cfg($pdo); $now = time();
    $o['enrolled'] = fb_enroll($pdo);
    // 1. what is due (only when Admin switched it on; only 10 AM – 8 PM India time; at most 15 a round)
    $hr = (int)gmdate('G', $now + 19800);
    if ($cfg['on'] && $cfg['tpl'] > 0 && $hr >= 10 && $hr < 20) {
        $q = $pdo->prepare("SELECT * FROM fb_orders WHERE status = 'wait' AND msg_at IS NULL AND delivered_at <= ? AND phone <> '' AND order_name NOT LIKE 'TEST-%' ORDER BY delivered_at ASC LIMIT 15"); $q->execute([date('Y-m-d H:i:s', $now - $cfg['wait'] * 86400)]);
        foreach ($q->fetchAll() as $r) {
            if (microtime(true) - $t0 > 12) break;
            [$ok, $why] = fb_send($pdo, $r, 'msg');
            if ($ok) $o['sent']++; else { $pdo->prepare("UPDATE fb_orders SET msg_err = ?, status = CASE WHEN ? LIKE '%stop messages%' THEN 'skip' ELSE status END, skip_why = CASE WHEN ? LIKE '%stop messages%' THEN 'opted out' ELSE skip_why END WHERE id = ?")->execute([mb_substr($why, 0, 190), $why, $why, (int)$r['id']]); }
        }
        if ($cfg['tpl_fu'] > 0) {
            $q = $pdo->prepare("SELECT * FROM fb_orders WHERE status = 'sent' AND fu_at IS NULL AND replied_at IS NULL AND msg_at IS NOT NULL AND msg_at <= ? AND order_name NOT LIKE 'TEST-%' ORDER BY msg_at ASC LIMIT 10"); $q->execute([date('Y-m-d H:i:s', $now - $cfg['fu'] * 86400)]);
            foreach ($q->fetchAll() as $r) { if (microtime(true) - $t0 > 12) break; [$ok] = fb_send($pdo, $r, 'fu'); if ($ok) $o['fu']++; else $pdo->prepare("UPDATE fb_orders SET fu_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]); }   // a follow-up that cannot be sent is not tried every minute
        }
    }
    // 2. the customers' answers after our message: a reply, and the pictures (screenshots) they sent
    $q = $pdo->prepare("SELECT * FROM fb_orders WHERE msg_at IS NOT NULL AND msg_at >= ? AND status IN ('sent', 'replied', 'proof') ORDER BY id DESC LIMIT 40"); $q->execute([date('Y-m-d H:i:s', $now - 45 * 86400)]);
    $mq = $pdo->prepare("SELECT id, mtype, media_id, media_mime, ts FROM wa_messages WHERE phone = ? AND dir = 'in' AND id > ? AND ts > ? ORDER BY id ASC LIMIT 20");
    foreach ($q->fetchAll() as $r) {
        if (microtime(true) - $t0 > 12) break;
        $mq->execute([(string)$r['phone'], (int)$r['last_in_id'], (string)$r['msg_at']]); $msgs = $mq->fetchAll(); if (!$msgs) continue;
        $maxId = (int)$r['last_in_id']; $st = (string)$r['status']; $got = 0;
        foreach ($msgs as $m) {
            $maxId = max($maxId, (int)$m['id']);
            if ($st === 'sent') { $st = 'replied'; $pdo->prepare("UPDATE fb_orders SET replied_at = ? WHERE id = ?")->execute([(string)$m['ts'], (int)$r['id']]); fb_log($pdo, (string)$r['order_name'], 'The customer replied on WhatsApp', 'automatic'); $o['replied']++; }
            if ((string)$m['mtype'] === 'image' && (string)$m['media_id'] !== '' && fb_save_wa_media($pdo, $r, (string)$m['media_id'], (string)$m['media_mime'])) { $got++; $o['proofs']++; if ($st !== 'proof') $st = 'proof'; }
        }
        if ($got) fb_log($pdo, (string)$r['order_name'], 'The customer sent ' . $got . ' screenshot' . ($got > 1 ? 's' : '') . ' → saved on ' . $r['order_name'], 'automatic');
        $pdo->prepare("UPDATE fb_orders SET last_in_id = ?, status = ? WHERE id = ?")->execute([$maxId, $st, (int)$r['id']]);
    }
    return $o;
}

function fb_can(PDO $pdo, array $auth): bool
{
    if (function_exists('user_can') && user_can($auth, 'feedback')) return true;      // the 'Feedback' tick on the Roles page
    if (!function_exists('sku_can') && is_file(__DIR__ . '/sku_sheet.php')) require_once __DIR__ . '/sku_sheet.php';
    return function_exists('sku_can') ? sku_can($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
}

/** Why WhatsApp (Meta) could not deliver a message, in plain words (the code is Meta's own). */
function fb_why(int $code, string $text): string
{
    $m = [131026 => 'The message could not be delivered: this number is not on WhatsApp, has not accepted the new WhatsApp terms, or is using an old WhatsApp version.', 131049 => 'Meta held this message: this customer already got many promotional messages from businesses. Meta decides this; it is not charged and is not your mistake. Try another day.',
        131042 => 'Meta could not charge your WhatsApp business account (a payment problem in Meta Business). Fix the payment method in Meta Business Manager.', 131047 => 'More than 24 hours since the customer wrote: only an approved pre-made message can be sent, not a typed one.',
        131056 => 'Too many messages to this same customer from this number in a short time. Wait and try again.', 131048 => 'Meta limited this number for sending too many unwanted messages (spam rate).', 131051 => 'The message type is not supported.', 132000 => 'The pre-made message has a different number of blanks than the page sends.',
        132001 => 'This pre-made message does not exist or is not approved in this language.', 132005 => 'The text after filling the blanks is too long.', 132007 => 'The pre-made message breaks a WhatsApp content rule.', 132012 => 'A blank has the wrong format.', 132015 => 'This pre-made message was paused by Meta (low quality).', 132016 => 'This pre-made message was disabled by Meta (low quality for a long time).',
        130472 => 'Meta did not deliver this message (a Meta test group).', 133010 => 'This WhatsApp number is not registered.', 368 => 'Meta blocked this account for a policy reason.', 190 => 'The WhatsApp connection (token) expired. Reconnect it in WhatsApp → Setup.'];
    if (isset($m[$code])) return $m[$code] . ' (code ' . $code . ')';
    return ($text !== '' ? $text : 'Meta did not say why.') . ($code ? ' (code ' . $code . ')' : '');
}

function fb_row_out(array $r): array
{
    $seen = (string)($r['msg_status'] ?? '') === 'read';
    return ['id' => (int)$r['id'], 'order' => (string)$r['order_name'], 'customer' => (string)$r['customer'], 'phone' => (string)$r['phone'], 'delivered' => substr((string)$r['delivered_at'], 0, 16), 'store' => (string)($r['store_key'] ?? ''), 'last_call' => (string)($r['last_call'] ?? ''), 'status' => (string)$r['status'], 'test' => strpos((string)$r['order_name'], 'TEST-') === 0,
        'msg_at' => (string)($r['msg_at'] ?? ''), 'fu_at' => (string)($r['fu_at'] ?? ''), 'seen' => $seen, 'msg_state' => (string)($r['msg_status'] ?? ''), 'msg_why' => (string)($r['msg_status'] ?? '') === 'failed' ? fb_why((int)($r['msg_code'] ?? 0), (string)($r['msg_errtext'] ?? '')) : '', 'msg_err' => (string)$r['msg_err'], 'place' => (string)$r['place'],
        'reward_order' => (string)$r['reward_order'], 'reward_note' => (string)$r['reward_note'], 'cx_code' => (string)$r['cx_code'], 'skip_why' => (string)$r['skip_why'], 'proofs' => (int)($r['proofs'] ?? 0)];
}

function fb_route(PDO $pdo, string $action): void
{
    $auth = require_auth(); $by = (string)($auth['username'] ?? $auth['name'] ?? ''); $role = (string)($auth['role'] ?? '');
    if ($action === 'fb.access') json_response(['ok' => fb_can($pdo, $auth)]);
    if (!fb_can($pdo, $auth)) json_response(['error' => 'The Feedback page is for Admin, HR, Dispatch, the Admin department, Sales Heads and roles with the Orders page.'], 403);
    if (!fb_tables($pdo)) json_response(['error' => 'The Feedback storage could not be set up.'], 500);
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $cfg = fb_cfg($pdo);
    $get = function (string $order) use ($pdo) { $q = $pdo->prepare("SELECT f.*, m.status AS msg_status, m.err AS msg_errtext, m.err_code AS msg_code, (SELECT e.txt FROM fb_events e WHERE e.order_name = f.order_name AND e.txt LIKE 'Call:%' ORDER BY e.id DESC LIMIT 1) AS last_call, (SELECT COUNT(*) FROM fb_proofs p WHERE p.order_name = f.order_name) AS proofs FROM fb_orders f LEFT JOIN wa_messages m ON m.wamid = f.msg_wamid COLLATE utf8mb4_unicode_ci AND f.msg_wamid <> '' WHERE f.order_name = ?"); $q->execute([$order]); return $q->fetch(); };

    if ($action === 'fb.list') {
        fb_enroll($pdo, false);
        $tab = (string)($_GET['tab'] ?? 'all'); $qs = trim((string)($_GET['q'] ?? '')); $qs = ltrim($qs, '#');
        $w = []; $p = [];
        if ($qs !== '') { $like = '%' . $qs . '%'; $w[] = "(f.order_name LIKE ? OR f.customer LIKE ? OR f.phone LIKE ?)"; array_push($p, $like, $like, $like); }
        elseif ($tab === 'unseen') $w[] = "f.msg_at IS NOT NULL AND COALESCE(m.status, '') <> 'read'";
        elseif (in_array($tab, ['wait', 'sent', 'replied', 'proof', 'rew', 'cmp', 'skip'], true)) { $w[] = "f.status = ?"; $p[] = $tab; }
        $sql = "SELECT f.*, m.status AS msg_status, m.err AS msg_errtext, m.err_code AS msg_code, (SELECT e.txt FROM fb_events e WHERE e.order_name = f.order_name AND e.txt LIKE 'Call:%' ORDER BY e.id DESC LIMIT 1) AS last_call, (SELECT COUNT(*) FROM fb_proofs pp WHERE pp.order_name = f.order_name) AS proofs FROM fb_orders f LEFT JOIN wa_messages m ON m.wamid = f.msg_wamid COLLATE utf8mb4_unicode_ci AND f.msg_wamid <> ''" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY f.delivered_at DESC LIMIT 300";
        $st = $pdo->prepare($sql); $st->execute($p); $rows = array_map('fb_row_out', $st->fetchAll());
        $c = ['all' => 0, 'wait' => 0, 'sent' => 0, 'replied' => 0, 'proof' => 0, 'rew' => 0, 'cmp' => 0, 'skip' => 0];
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM fb_orders WHERE order_name NOT LIKE 'TEST-%' GROUP BY status")->fetchAll() as $r) { $c[(string)$r['status']] = (int)$r['n']; $c['all'] += (int)$r['n']; }
        $sent = (int)$pdo->query("SELECT COUNT(*) FROM fb_orders WHERE msg_at IS NOT NULL AND order_name NOT LIKE 'TEST-%'")->fetchColumn();
        $seen = (int)$pdo->query("SELECT COUNT(*) FROM fb_orders f JOIN wa_messages m ON m.wamid = f.msg_wamid COLLATE utf8mb4_unicode_ci AND f.msg_wamid <> '' WHERE m.status = 'read' AND f.order_name NOT LIKE 'TEST-%'")->fetchColumn();
        $proofs = (int)$pdo->query("SELECT COUNT(DISTINCT order_name) FROM fb_proofs WHERE order_name NOT LIKE 'TEST-%'")->fetchColumn();
        json_response(['rows' => $rows, 'counts' => $c + ['sent_total' => $sent, 'seen' => $seen, 'unseen' => max(0, $sent - $seen), 'proof_orders' => $proofs], 'cfg' => $cfg, 'can_edit' => in_array($role, ['Admin', 'HR'], true)]);
    }
    if ($action === 'fb.get') {
        $o = trim((string)($_GET['order'] ?? '')); $r = $get($o); if (!$r) json_response(['error' => 'Order not found.'], 404);
        $pr = $pdo->prepare("SELECT id, src, place, by_name, created_at FROM fb_proofs WHERE order_name = ? ORDER BY id DESC"); $pr->execute([$o]);
        $ev = $pdo->prepare("SELECT at, txt, by_name FROM fb_events WHERE order_name = ? ORDER BY id ASC"); $ev->execute([$o]);
        $nm = trim(explode(' ', trim((string)$r['customer']))[0] ?? '');
        json_response(['row' => fb_row_out($r), 'proofs' => $pr->fetchAll(), 'events' => $ev->fetchAll(), 'text' => fb_text($pdo, $cfg['tpl'], $nm), 'text_fu' => fb_text($pdo, $cfg['tpl_fu'], $nm), 'cfg' => $cfg]);
    }
    if ($action === 'fb.settings_save') {
        if (!$post || !in_array($role, ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin / HR can change the settings.'], 403);
        $in = json_input();
        setting_set($pdo, 'fb_on', !empty($in['on']) ? '1' : '0'); setting_set($pdo, 'fb_wait', (string)max(1, min(30, (int)($in['wait'] ?? 5)))); setting_set($pdo, 'fb_fu', (string)max(1, min(30, (int)($in['fu'] ?? 3))));
        setting_set($pdo, 'fb_tpl', (string)max(0, (int)($in['tpl'] ?? 0))); setting_set($pdo, 'fb_tpl_fu', (string)max(0, (int)($in['tpl_fu'] ?? 0)));
        $pl = array_values(array_filter(array_map(fn($x) => mb_substr(trim(str_replace('|', ' ', (string)$x)), 0, 40), (array)($in['places'] ?? [])))); if ($pl) setting_set($pdo, 'fb_places', implode('|', array_slice($pl, 0, 12)));
        $rw = mb_substr(trim((string)($in['reward'] ?? '')), 0, 60); if ($rw !== '') setting_set($pdo, 'fb_reward', $rw);
        json_response(['ok' => true, 'cfg' => fb_cfg($pdo)]);
    }
    if ($action === 'fb.proof_data') {      // the picture as data inside a normal answer (works everywhere the other answers work; the page shows it directly)
        $q = $pdo->prepare("SELECT path, mime FROM fb_proofs WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]); $r = $q->fetch(); $abs = $r ? __DIR__ . '/' . $r['path'] : '';
        if (!$r || strpos((string)$r['path'], 'uploads/feedback/') !== 0 || strpos((string)$r['path'], '..') !== false || !is_file($abs)) json_response(['error' => 'The picture file is not on the server (' . ($r ? $r['path'] : 'no such proof') . ').'], 404);
        $mime = (string)$r['mime'] !== '' ? (string)$r['mime'] : 'image/jpeg'; $raw = @file_get_contents($abs); if ($raw === false || $raw === '') json_response(['error' => 'The picture file is empty or cannot be read.'], 500);
        json_response(['src' => 'data:' . $mime . ';base64,' . base64_encode($raw)]);
    }
    if (!$post && $action !== 'fb.proof_file') json_response(['error' => 'Unknown action.'], 404);
    if ($action === 'fb.proof_file') {   // the picture, streamed with the login token (never a public link)
        $q = $pdo->prepare("SELECT path, mime FROM fb_proofs WHERE id = ?"); $q->execute([(int)($_GET['id'] ?? 0)]); $r = $q->fetch(); $abs = $r ? __DIR__ . '/' . $r['path'] : '';
        if (!$r || strpos((string)$r['path'], 'uploads/feedback/') !== 0 || strpos((string)$r['path'], '..') !== false || !is_file($abs)) json_response(['error' => 'No picture.'], 404);
        header('Content-Type: ' . ((string)$r['mime'] !== '' ? $r['mime'] : 'image/jpeg')); header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . filesize($abs)); readfile($abs); exit;
    }
    if ($action === 'fb.place_add') {      // a new place for the list ("Trustpilot"): kept, so it is there next time too
        $in = json_input(); $new = fb_place_str($in['place'] ?? ''); if ($new === '') json_response(['error' => 'Type the name of the place.'], 422);
        $list = $cfg['places']; foreach (array_filter(array_map('trim', explode(',', $new))) as $n) if (!in_array($n, $list, true)) $list[] = $n;
        setting_set($pdo, 'fb_places', implode('|', array_slice($list, 0, 20))); json_response(['ok' => true, 'places' => array_slice($list, 0, 20)]);
    }
    if ($action === 'fb.test_add') {      // 🧪 a test on one customer you choose (your own number first): a TEST order is made; the automatic engine never sends for it, only the buttons do
        $in = json_input(); $key = function_exists('wa_key') ? wa_key((string)($in['phone'] ?? '')) : substr(preg_replace('/\D+/', '', (string)($in['phone'] ?? '')), -10);
        if (strlen($key) < 10) json_response(['error' => 'Type the 10-digit mobile number of the customer to test on.'], 422);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120); $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO fb_orders (order_name, customer, phone, delivered_at, status, created_at) VALUES (?, ?, ?, ?, 'wait', ?)")->execute(['TEST-' . bin2hex(random_bytes(3)), $name, $key, $now, $now]);
        $id = (int)$pdo->lastInsertId(); $no = 'TEST-' . $id; $pdo->prepare("UPDATE fb_orders SET order_name = ? WHERE id = ?")->execute([$no, $id]);
        fb_log($pdo, $no, 'Test order made for ' . ($name !== '' ? $name . ' ' : '') . $key . ' (nothing automatic is sent for a test)', $by);
        json_response(['ok' => true, 'order' => $no]);
    }
    if ($action === 'fb.proof_delete') {      // 🗑 remove one proof picture (file and row); the order goes back to the state it had without it
        $in = json_input(); $q = $pdo->prepare("SELECT p.id, p.path, p.order_name, f.id AS fid, f.msg_at, f.replied_at, f.status FROM fb_proofs p JOIN fb_orders f ON f.order_name = p.order_name WHERE p.id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch();
        if (!$r) json_response(['error' => 'This proof is already removed.'], 404);
        $abs = __DIR__ . '/' . $r['path']; if (strpos((string)$r['path'], 'uploads/feedback/') === 0 && strpos((string)$r['path'], '..') === false && is_file($abs)) @unlink($abs);
        $pdo->prepare("DELETE FROM fb_proofs WHERE id = ?")->execute([(int)$r['id']]);
        $left = $pdo->prepare("SELECT COUNT(*) FROM fb_proofs WHERE order_name = ?"); $left->execute([$r['order_name']]);
        if ((int)$left->fetchColumn() === 0 && (string)$r['status'] === 'proof') { $back = $r['replied_at'] ? 'replied' : ($r['msg_at'] ? 'sent' : 'wait'); $pdo->prepare("UPDATE fb_orders SET status = ? WHERE id = ?")->execute([$back, (int)$r['fid']]); }
        fb_log($pdo, (string)$r['order_name'], 'A proof picture was removed', $by);
        json_response(['ok' => true]);
    }
    if ($action === 'fb.links_save') {      // the review links (add / edit / delete): made once, one tap to send
        $in = json_input(); $ls = fb_links_put($pdo, (array)($in['links'] ?? [])); json_response(['ok' => true, 'links' => $ls]);
    }
    // the actions on one order
    $in = $_SERVER['CONTENT_TYPE'] ?? ''; $in = stripos((string)$in, 'application/json') !== false ? json_input() : $_POST;
    $order = trim((string)($in['order'] ?? '')); $r = $get($order); if (!$r) json_response(['error' => 'Order not found.'], 404);
    if ($action === 'fb.send_now' || $action === 'fb.followup') {
        [$ok, $why] = fb_send($pdo, $r, $action === 'fb.followup' ? 'fu' : 'msg', $by); if (!$ok) json_response(['error' => $why], 422);
        json_response(['ok' => true, 'message' => 'Message sent.']);
    }
    if ($action === 'fb.proof_add') {
        if (empty($_FILES['proof'])) json_response(['error' => 'Choose the screenshot first.'], 422); $f = $_FILES['proof'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(['error' => 'The upload failed (error ' . (int)($f['error'] ?? -1) . ').'], 422);
        if ((int)$f['size'] > 12 * 1024 * 1024) json_response(['error' => 'The picture is too big (max 12 MB).'], 422);
        $info = @getimagesize($f['tmp_name']); $mime = (string)($info['mime'] ?? ''); $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? '';
        if ($ext === '') json_response(['error' => 'Only a JPG, PNG or WebP picture can be added.'], 422);
        $dir = __DIR__ . '/uploads/feedback/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'fb_' . preg_replace('/[^0-9A-Za-z]/', '', $order) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . $name)) json_response(['error' => 'The server could not save the picture (the uploads folder is not writable).'], 500);
        $place = fb_place_str($in['place'] ?? '');
        $pdo->prepare("INSERT INTO fb_proofs (order_name, path, mime, src, place, by_name, created_at) VALUES (?, ?, ?, 'manual', ?, ?, ?)")->execute([$order, 'uploads/feedback/' . $name, $mime, $place, $by, date('Y-m-d H:i:s')]);
        $pdo->prepare("UPDATE fb_orders SET status = CASE WHEN status IN ('rew', 'cmp') THEN status ELSE 'proof' END, place = CASE WHEN ? <> '' THEN ? ELSE place END WHERE id = ?")->execute([$place, $place, (int)$r['id']]);
        fb_log($pdo, $order, 'Screenshot added by hand' . ($place !== '' ? ' (' . $place . ')' : '') . ' → attached to ' . $order, $by);
        json_response(['ok' => true]);
    }
    if ($action === 'fb.reward') {
        $gift = mb_substr(trim((string)($in['gift'] ?? $cfg['reward'])), 0, 80); $ro = mb_substr(trim((string)($in['reward_order'] ?? '')), 0, 40); $place = fb_place_str($in['place'] ?? '');
        $pdo->prepare("UPDATE fb_orders SET status = 'rew', reward_note = ?, reward_order = ?, place = CASE WHEN ? <> '' THEN ? ELSE place END WHERE id = ?")->execute([$gift, $ro, $place, $place, (int)$r['id']]);
        fb_log($pdo, $order, 'Reward given: ' . $gift . ($ro !== '' ? ' · order ' . $ro : '') . ($place !== '' ? ' · feedback on ' . $place : ''), $by);
        json_response(['ok' => true]);
    }
    if ($action === 'fb.cx_done') {   // the complaint itself is raised by the page through bd.cx_raise (the Complaints page); this keeps the link and the history
        $code = mb_substr(trim((string)($in['code'] ?? '')), 0, 30); $why = mb_substr(trim((string)($in['why'] ?? '')), 0, 200);
        $pdo->prepare("UPDATE fb_orders SET status = 'cmp', cx_code = ? WHERE id = ?")->execute([$code, (int)$r['id']]);
        fb_log($pdo, $order, 'Complaint ' . ($code !== '' ? $code . ' ' : '') . 'opened in the Complaints page' . ($why !== '' ? ': ' . $why : ''), $by);
        json_response(['ok' => true]);
    }
    if ($action === 'fb.test_delete') {
        if (strpos($order, 'TEST-') !== 0) json_response(['error' => 'Only a test order can be deleted.'], 422);
        $pr = $pdo->prepare("SELECT path FROM fb_proofs WHERE order_name = ?"); $pr->execute([$order]);
        foreach ($pr->fetchAll() as $x) { $abs = __DIR__ . '/' . $x['path']; if (strpos((string)$x['path'], 'uploads/feedback/') === 0 && strpos((string)$x['path'], '..') === false && is_file($abs)) @unlink($abs); }
        $pdo->prepare("DELETE FROM fb_proofs WHERE order_name = ?")->execute([$order]); $pdo->prepare("DELETE FROM fb_events WHERE order_name = ?")->execute([$order]); $pdo->prepare("DELETE FROM fb_orders WHERE id = ?")->execute([(int)$r['id']]);
        json_response(['ok' => true]);
    }
    if ($action === 'fb.call') {      // 📞 the result of a phone call to the customer, kept in the history (and shown under the status)
        $L = ['answered_will' => 'Answered — will give feedback', 'answered_given' => 'Answered — feedback already given', 'answered_issue' => 'Answered — has a problem', 'no_answer' => 'No answer', 'busy' => 'Busy / switched off', 'call_later' => 'Asked to call later', 'wrong_number' => 'Wrong number', 'refused' => 'Does not want to give feedback'];
        $k = (string)($in['result'] ?? ''); if (!isset($L[$k])) json_response(['error' => 'Pick what happened on the call.'], 422); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200);
        fb_log($pdo, $order, 'Call: ' . $L[$k] . ($note !== '' ? ' — ' . $note : ''), $by); json_response(['ok' => true]);
    }
    if ($action === 'fb.send_link') {      // 🔗 a review link to the customer on WhatsApp (a typed message: it goes within WhatsApp's 24-hour window; later use an approved pre-made message)
        $url = trim((string)($in['url'] ?? '')); if (!preg_match('~^https?://\S+$~i', $url)) json_response(['error' => 'Pick or paste a link that starts with https://'], 422);
        $text = trim((string)($in['text'] ?? '')); if ($text === '') $text = 'Thank you for your order ' . $order . '. Please share your feedback here: ' . $url; elseif (stripos($text, $url) === false) $text .= "\n" . $url;
        if (!function_exists('wa_send_text') || !function_exists('wa_ensure_contact')) json_response(['error' => 'The WhatsApp sender is not available.'], 500);
        $c = wa_ensure_contact($pdo, (string)$r['phone'], (string)$r['customer']); if (!$c) json_response(['error' => 'Could not open the chat of this customer.'], 422);
        $res = wa_send_text($pdo, $c, mb_substr($text, 0, 900), 'emp', (int)($auth['employee_id'] ?? 0)); if (empty($res[0])) json_response(['error' => (string)$res[1]], 422);
        $nm = ''; foreach (fb_links($pdo) as $l) if ($l['url'] === $url) $nm = $l['name'];
        fb_log($pdo, $order, 'Review link sent on WhatsApp' . ($nm !== '' ? ' (' . $nm . ')' : ''), $by); json_response(['ok' => true]);
    }
    if ($action === 'fb.skip') {
        $why = mb_substr(trim((string)($in['why'] ?? 'no feedback needed')), 0, 80);
        $pdo->prepare("UPDATE fb_orders SET status = 'skip', skip_why = ? WHERE id = ?")->execute([$why, (int)$r['id']]); fb_log($pdo, $order, 'Left out: ' . $why, $by);
        json_response(['ok' => true]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}

}
