<?php
/* 🛍 WEBSITE ORDER CONFIRMATION (owner, 23 Nov 2026) — direct website orders only.
 *
 * 95% of the customers come through WhatsApp, get a checkout link and are confirmed by the existing order flow from THEIR OWN number: nothing here touches them.
 * A customer who orders on the website by himself (never wrote to us, we never wrote to him) would get nothing. For him this file sends the order-received message
 * (template tmph_order_received: first name + order number) from ONE number only: the main number (the one whose digits end in the saved last 4, default 0275), and
 * puts him in the customer list.
 *
 * It reads the shop like the Website orders page does (settings tms_api_base / tms_api_key, GET /orders, read-only) about once a minute.
 * Safe by default: OFF until Admin sets a start time and switches it on; only orders placed at or after that time; one order = one message (table wa_site_conf,
 * the order number is the key; a row is claimed BEFORE sending, so a crash or a second request can never send twice); never sent from another number.
 *
 * Settings: wa_sc_on (0/1) · wa_sc_from (start time) · wa_sc_num (last 4 digits of the main number) · wa_sc_skip_src (orders whose shop "source" is in this comma list are chat-link orders: skipped).
 * Actions (Admin / HR): wa.sc_get · wa.sc_set.                                                                                                   PHP 7.4 safe. */

if (!function_exists('wsc_tables')) {

function wsc_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_site_conf (order_no VARCHAR(40) NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL DEFAULT '', state VARCHAR(10) NOT NULL DEFAULT 'sending', why VARCHAR(160) NOT NULL DEFAULT '',
            source VARCHAR(40) NOT NULL DEFAULT '', tries INT NOT NULL DEFAULT 0, next_try DATETIME NULL, placed_at DATETIME NULL, created_at DATETIME NULL, sent_at DATETIME NULL, wamid VARCHAR(80) NOT NULL DEFAULT '',
            KEY k_state (state, next_try)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function wsc_cfg(PDO $pdo): array
{
    $from = trim((string)setting_get($pdo, 'wa_sc_from', ''));
    return ['on' => (string)setting_get($pdo, 'wa_sc_on', '0') === '1', 'from' => $from, 'from_ts' => $from !== '' ? (int)strtotime($from) : 0,
        'num' => preg_replace('/\D+/', '', (string)setting_get($pdo, 'wa_sc_num', '0275')) ?: '0275', 'skip_src' => trim((string)setting_get($pdo, 'wa_sc_skip_src', ''))];
}
/** The 10 digits of a customer's phone ('' when there are not 10). */
function wsc_phone(array $o): string
{
    $cu = is_array($o['customer'] ?? null) ? $o['customer'] : []; $sh = is_array($o['shipping_address'] ?? null) ? $o['shipping_address'] : [];
    $d = preg_replace('/\D+/', '', (string)(($cu['phone'] ?? '') ?: ($sh['phone'] ?? ''))); $d = strlen($d) > 10 ? substr($d, -10) : $d;
    return strlen($d) === 10 ? $d : '';
}
/** What to do with one shop order. PURE (no database): $chatted = HRMS has any message with this phone, $now = timestamp.
 *  Returns ['send'] or ['skip', reason]. */
function wsc_decide(array $o, bool $chatted, array $cfg): array
{
    $no = trim((string)($o['number'] ?? '')); if ($no === '') return ['skip', 'no order number'];
    $placed = (int)strtotime((string)($o['placed_at'] ?? '')); if ($placed <= 0) return ['skip', 'no order time'];
    if ($cfg['from_ts'] <= 0 || $placed < $cfg['from_ts']) return ['skip', 'placed before the start time'];
    if (wsc_phone($o) === '') return ['skip', 'no valid phone number'];
    $src = strtolower(trim((string)($o['source'] ?? '')));
    if ($cfg['skip_src'] !== '') foreach (array_filter(array_map('trim', explode(',', strtolower($cfg['skip_src'])))) as $s) if ($s !== '' && $src === $s) return ['skip', 'chat-link order (source "' . $src . '") — the normal flow confirms it'];
    if ($chatted) return ['skip', 'the customer has a chat in HRMS — the normal flow confirms it'];
    return ['send'];
}
/** GET /orders of the shop (read-only), newest pages first is not needed: [items, error]. */
function wsc_fetch(PDO $pdo, int $fromTs): array
{
    $base = rtrim(trim((string)setting_get($pdo, 'tms_api_base', 'https://test.tmperfumehouse.store/api/v1')), '/'); $key = trim((string)setting_get($pdo, 'tms_api_key', ''));
    if ($key === '') return [[], 'the shop key is not saved yet (Website orders → Connection)'];
    if (!preg_match('#^https://[a-z0-9.\-]+(:\d+)?/#i', $base . '/')) return [[], 'the shop address must start with https://'];
    if (!function_exists('curl_init')) return [[], 'PHP curl is not switched on'];
    $out = [];
    for ($p = 1; $p <= 5; $p++) {
        $ch = curl_init($base . '/orders?' . http_build_query(['page' => $p, 'limit' => 100, 'since' => date('Y-m-d', $fromTs)]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json']]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = $raw === false ? curl_error($ch) : ''; curl_close($ch);
        if ($err !== '') return [[], 'the shop could not be reached: ' . mb_substr($err, 0, 100)];
        $j = json_decode((string)$raw, true);
        if ($code === 401 || $code === 403) return [[], 'the shop refused the key (HTTP ' . $code . ')'];
        if (!is_array($j) || empty($j['ok'])) return [[], 'the shop answered with an error' . ($code ? ' (HTTP ' . $code . ')' : '')];
        foreach ((array)($j['data']['items'] ?? []) as $o) if (is_array($o)) $out[] = $o;
        if (empty($j['data']['has_more'])) break;
    }
    return [$out, ''];
}
/** The id of the ONE number whose digits end in $last4 (0 when none or more than one). */
function wsc_main_number(PDO $pdo, string $last4): int
{
    try {
        $q = $pdo->prepare("SELECT id FROM wa_numbers WHERE RIGHT(display, 4) = ? AND retired = 0"); $q->execute([$last4]); $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : 0;
    } catch (Throwable $e) { return 0; }
}
function wsc_chatted(PDO $pdo, string $phone): bool
{
    try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? LIMIT 1"); $q->execute([$phone]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return true; }   // cannot tell → treat as a customer WITH a chat (never risk a second message)
}

/** The background step (called by wa_tick). Returns counts. */
function wa_site_conf_tick(PDO $pdo): array
{
    $res = ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
    $cfg = wsc_cfg($pdo); if (!$cfg['on']) return [];
    if ($cfg['from_ts'] <= 0) return ['note' => 'no start time set'];
    if (!wsc_tables($pdo)) return ['note' => 'table missing'];
    if ((int)setting_get($pdo, 'wa_sc_tick_at', '0') > time() - 50) return ['note' => 'throttled'];
    try { $got = (int)$pdo->query("SELECT GET_LOCK('wa_site_conf', 0)")->fetchColumn(); } catch (Throwable $e) { $got = 0; } if ($got !== 1) return ['note' => 'busy'];
    try {
        setting_set($pdo, 'wa_sc_tick_at', (string)time());
        $mainId = wsc_main_number($pdo, $cfg['num']);
        if ($mainId <= 0) { wa_log($pdo, 'orders', false, 'Website order confirmation: the main number (ending ' . $cfg['num'] . ') was not found, or more than one number ends with it — nothing is sent.'); return ['note' => 'main number not found']; }
        [$items, $err] = wsc_fetch($pdo, $cfg['from_ts']);
        if ($err !== '') { wa_log($pdo, 'orders', false, 'Website order confirmation: ' . $err); return ['note' => $err]; }
        $now = date('Y-m-d H:i:s'); $budget = 25;
        foreach ($items as $o) {
            $no = mb_substr(trim((string)($o['number'] ?? '')), 0, 40); if ($no === '') continue;
            $q = $pdo->prepare("SELECT state, tries, next_try FROM wa_site_conf WHERE order_no = ?"); $q->execute([$no]); $row = $q->fetch();
            if ($row && !($row['state'] === 'retry' && ($row['next_try'] === null || strtotime((string)$row['next_try']) <= time()))) continue;   // already handled (sent / skipped / failed / being sent)
            $phone = wsc_phone($o); $placed = (int)strtotime((string)($o['placed_at'] ?? '')); $src = mb_substr((string)($o['source'] ?? ''), 0, 40);
            $dec = wsc_decide($o, $phone !== '' && wsc_chatted($pdo, $phone), $cfg);
            if (!$row) {   // claim the order BEFORE doing anything: the key is the order number
                $ins = $pdo->prepare("INSERT IGNORE INTO wa_site_conf (order_no, phone, state, why, source, tries, placed_at, created_at) VALUES (?, ?, 'sending', '', ?, 0, ?, ?)");
                $ins->execute([$no, $phone, $src, $placed > 0 ? date('Y-m-d H:i:s', $placed) : null, $now]); if ($ins->rowCount() < 1) continue;
            } else { $cl = $pdo->prepare("UPDATE wa_site_conf SET state = 'sending' WHERE order_no = ? AND state = 'retry'"); $cl->execute([$no]); if ($cl->rowCount() < 1) continue; }
            if ($dec[0] === 'skip') { $pdo->prepare("UPDATE wa_site_conf SET state = 'skipped', why = ? WHERE order_no = ?")->execute([mb_substr($dec[1], 0, 160), $no]); $res['skipped']++; continue; }
            if ($budget-- <= 0) { $pdo->prepare("UPDATE wa_site_conf SET state = 'retry', why = 'waiting for the next run', next_try = ? WHERE order_no = ?")->execute([date('Y-m-d H:i:s', time() + 60), $no]); $res['retry']++; continue; }
            [$ok, $why, $wamid] = wsc_send($pdo, $o, $phone, $no, $mainId);
            if ($ok) { $pdo->prepare("UPDATE wa_site_conf SET state = 'sent', why = '', sent_at = ?, wamid = ? WHERE order_no = ?")->execute([date('Y-m-d H:i:s'), mb_substr((string)$wamid, 0, 80), $no]); $res['sent']++; continue; }
            $tries = (int)($row['tries'] ?? 0) + 1;
            if ($tries >= 5) { $pdo->prepare("UPDATE wa_site_conf SET state = 'failed', why = ?, tries = ? WHERE order_no = ?")->execute([mb_substr($why, 0, 160), $tries, $no]); $res['failed']++; wa_log($pdo, 'orders', false, 'Website order ' . $no . ': confirmation NOT sent after 5 tries — ' . mb_substr($why, 0, 140)); }
            else { $pdo->prepare("UPDATE wa_site_conf SET state = 'retry', why = ?, tries = ?, next_try = ? WHERE order_no = ?")->execute([mb_substr($why, 0, 160), $tries, date('Y-m-d H:i:s', time() + min(900, 120 * $tries)), $no]); $res['retry']++; }
        }
        if ($res['sent'] || $res['failed']) wa_log($pdo, 'orders', $res['failed'] === 0, 'Website order confirmation: ' . $res['sent'] . ' sent from the main number, ' . $res['failed'] . ' failed, ' . $res['skipped'] . ' skipped (chat orders), ' . $res['retry'] . ' to try again');
    } catch (Throwable $e) { wa_log($pdo, 'orders', false, 'Website order confirmation error: ' . $e->getMessage()); $res['note'] = 'error'; }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_site_conf')"); } catch (Throwable $e) { }
    return $res;
}
/** Sends the message to ONE customer from the main number only. [ok, why, wamid] */
function wsc_send(PDO $pdo, array $o, string $phone, string $orderNo, int $mainId): array
{
    try {
        foreach (['wo_tpl_for', 'wa_camp_ctx', 'wa_send_tpl_one', 'wa_ensure_contact', 'wo_first_name'] as $f) if (!function_exists($f)) return [false, 'the WhatsApp module is not loaded (' . $f . ')', ''];
        $cu = is_array($o['customer'] ?? null) ? $o['customer'] : []; $sh = is_array($o['shipping_address'] ?? null) ? $o['shipping_address'] : [];
        $full = (string)(($cu['name'] ?? '') ?: ($sh['name'] ?? '')); $c = wa_ensure_contact($pdo, $phone, $full);   // a new customer is put in the customer list
        if (!$c) return [false, 'the customer could not be saved', ''];
        if ((int)($c['optout'] ?? 0) === 1) return [false, 'the customer opted out', ''];
        $t = wo_tpl_for($pdo, 'received'); if (!$t) return [false, 'the order-received template is not approved yet', ''];
        $ctx = wa_camp_ctx($pdo, $t, 99, [$mainId]);
        if (!empty($ctx['fallback']) || empty($ctx['nums'][$mainId])) return [false, 'the main number cannot send now: ' . mb_substr(implode(' · ', (array)($ctx['skip'] ?? [])), 0, 120), ''];
        $vars = array_slice([wo_first_name($full), $orderNo], 0, max(1, (int)$t['nvars']));
        $r = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$mainId], $vars, 'order');
        return [!empty($r[0]), is_string($r[1] ?? null) && empty($r[0]) ? (string)$r[1] : '', !empty($r[0]) && is_string($r[1] ?? null) ? (string)$r[1] : ''];
    } catch (Throwable $e) { return [false, 'error: ' . mb_substr($e->getMessage(), 0, 120), '']; }
}
/** For the screen: the settings, the main number it found, counts and the last rows. */
function wsc_status(PDO $pdo): array
{
    $cfg = wsc_cfg($pdo); $o = ['on' => $cfg['on'], 'from' => $cfg['from'], 'num' => $cfg['num'], 'skip_src' => $cfg['skip_src'], 'main_id' => 0, 'main_label' => '', 'counts' => [], 'rows' => []];
    $mid = wsc_main_number($pdo, $cfg['num']); $o['main_id'] = $mid;
    try { if ($mid) { $q = $pdo->prepare("SELECT label, display FROM wa_numbers WHERE id = ?"); $q->execute([$mid]); $r = $q->fetch(); $o['main_label'] = trim((string)($r['label'] ?? '') ?: (string)($r['display'] ?? '')); }
        if (wsc_tables($pdo)) { foreach ($pdo->query("SELECT state, COUNT(*) n FROM wa_site_conf WHERE created_at >= '" . date('Y-m-d 00:00:00') . "' GROUP BY state")->fetchAll() as $r) $o['counts'][(string)$r['state']] = (int)$r['n'];
            $o['rows'] = $pdo->query("SELECT order_no, phone, state, why, source, tries, placed_at, sent_at FROM wa_site_conf ORDER BY created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC); } } catch (Throwable $e) { }
    return $o;
}
}
