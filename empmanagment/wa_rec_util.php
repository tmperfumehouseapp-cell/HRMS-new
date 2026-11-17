<?php
/**
 * wa_rec_util.php — 🔁➡️💸 MODULE B: a marketing message that FAILED gets a UTILITY follow-up (owner, 17 Nov 2026).
 *
 * THE IDEA IN PLAIN WORDS
 *  - Module A (wa_mix / wa_camp) already sends marketing when a utility message cannot go. This is the other way round.
 *  - A person whose MARKETING campaign message failed gets one of the approved utility follow-up messages (the same pool the Utility campaign uses: wa_util_pick),
 *    but ONLY if they qualify for utility — they asked about a perfume on WhatsApp in the last 30 days, or ordered inside the order window (Meta's rule for utility).
 *    Everyone else is left alone ("no enquiry / order").
 *  - Which failures: 131049 (Meta held it: this customer got too many marketing messages) and 131050 (the customer stopped marketing messages) are marketing-only blocks,
 *    utility is not subject to them → tried after wa_ru_wait_min (default 10) minutes. Any other failure that another number / wording could fix goes to utility only AFTER
 *    Module A's recovery (wa_rec) used its tries on that person. NEVER: not on WhatsApp, own number, test group, spam flag, a wrong request.
 *  - Never to: opted out, wrote back since, delivered another way today, already got a utility follow-up for this item, already got one in the last 24 hours (any item),
 *    items whose failed message was itself a utility one (variant U).
 *  - One utility message per item. Every attempt is written to wa_ru_log (who, which number, which message, when). "Recovered" only ever means Meta said delivered / read.
 *  - When the utility message is delivered, the campaign's own later retry of that person is cancelled (no double message).
 *  - Brakes: quiet hours, an hourly pool, a pause for an hour when 60%+ of the utility messages fail, 6 hours when Meta flags spam (131048).
 *  - Nothing is sent until at least one utility follow-up message is approved at Meta (Utility campaign → make the 6 follow-ups).
 * Settings: wa_ru_on 1 · wa_ru_hourly 3000 · wa_ru_wait_min 45 · wa_ru_ord_days 60 · wa_ru_pause_until (set by itself) · wa_ru_cursor (set by itself)
 * Routes (wa_camp.php): wa.ru_status GET · wa.ru_run POST · wa.ru_set POST {on, hourly?, wait_min?, ord_days?}
 * PHP 7.4 safe.
 */
if (!function_exists('wa_ru_tick')) {

/** Failures only marketing suffers from: utility is not held or blocked by them. */
if (!defined('WA_RU_MARKETING_ONLY')) { define('WA_RU_MARKETING_ONLY', [131049, 131050]); }

function wa_ru_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ru_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, item_id BIGINT NOT NULL, phone VARCHAR(20) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT '',
            number_id INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, wamid VARCHAR(120) NOT NULL DEFAULT '', ok TINYINT NOT NULL DEFAULT 1, err_code INT NOT NULL DEFAULT 0, first_code INT NOT NULL DEFAULT 0,
            err VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, UNIQUE KEY u_item (item_id), KEY k_camp (camp_id, at), KEY k_at (at), KEY k_wamid (wamid), KEY k_phone (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $c = (string)$pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_ru_log'")->fetchColumn(); if ($c !== '' && $c !== 'utf8mb4_unicode_ci') $pdo->exec("ALTER TABLE wa_ru_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e2) { }   // same collation as wa_messages, or the joins fail (error 1267)
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function wa_ru_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    return ['on' => $g('wa_ru_on', '1') !== '0', 'hourly' => max(10, min(20000, (int)$g('wa_ru_hourly', '3000'))), 'wait_min' => max(5, min(1440, (int)$g('wa_ru_wait_min', '10'))),
            'ord_days' => in_array((int)$g('wa_ru_ord_days', '60'), [30, 60, 90, 120], true) ? (int)$g('wa_ru_ord_days', '60') : 60, 'pause_until' => (int)$g('wa_ru_pause_until', '0')];
}
/** Can utility fix this failure? 'now' = marketing-only block · 'after_rec' = a generic failure, utility only once Module A used its tries · 'never'. */
function wa_ru_class(int $ec): string
{
    if (in_array($ec, WA_RU_MARKETING_ONLY, true)) return 'now';
    if (function_exists('wa_rec_class') && wa_rec_class($ec) === 'never') return 'never';
    return 'after_rec';
}
/** The utility message + the numbers that may send it (cached by the caller per message). [$tpl, $ctx] or [null, null]. */
function wa_ru_tpl_ctx(PDO $pdo, array $t): array
{
    $ok = array_map('strval', (array)($t['ok_wabas'] ?? [])); if (!$ok) return [null, null];
    $by = []; foreach ($ok as $wb) $by[$wb] = $t; $base = $t; $base['by_waba'] = $by; $base['ok_wabas'] = $ok;
    return [$base, wa_camp_ctx($pdo, $base, 99, [], 0, true)];
}
function wa_ru_pool(PDO $pdo): array
{
    if (!function_exists('wa_util_pool_usable') && is_file(__DIR__ . '/wa_util.php')) { try { require_once __DIR__ . '/wa_util.php'; } catch (Throwable $e) { } }
    return function_exists('wa_util_pool_usable') ? wa_util_pool_usable($pdo) : [];
}

/** One round (≤ 40 s) of the utility follow-up for failed marketing messages. */
function wa_ru_tick(PDO $pdo): array
{
    $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'looked' => 0, 'note' => '', 'why' => []];
    if (!wa_ru_tables($pdo)) { $res['note'] = 'no table'; return $res; }
    $cfg = wa_ru_cfg($pdo); if (!$cfg['on']) { $res['note'] = 'off'; return $res; } if ($cfg['pause_until'] > time()) { $res['note'] = 'paused after a Meta warning'; return $res; }
    if (!function_exists('wa_lock') || !wa_lock($pdo, 'ru', 50)) { $res['note'] = 'busy'; return $res; }
    $t0 = microtime(true); $nowTs = wa_camp_now(); $now = date('Y-m-d H:i:s', $nowTs); $why = [];
    try {
        if (!wa_camp_in_window($pdo, $nowTs)) { $res['note'] = 'quiet hours'; return $res; }
        $pool = wa_ru_pool($pdo); if (!$pool) { $res['note'] = 'no approved utility follow-up message yet'; return $res; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log WHERE at >= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - 3600)]); $left = $cfg['hourly'] - (int)$q->fetchColumn(); if ($left <= 0) { $res['note'] = 'this hour\'s pool is used'; return $res; }
        // a round that mostly fails pauses itself for an hour
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(m.status = 'failed') AS f FROM wa_ru_log r JOIN wa_messages m ON m.wamid = r.wamid WHERE r.at >= ? AND r.wamid <> ''"); $q->execute([date('Y-m-d H:i:s', $nowTs - 7200)]); $hr = $q->fetch();
        if ((int)$hr['n'] >= 60 && (int)$hr['f'] / max(1, (int)$hr['n']) >= 0.6) { setting_set($pdo, 'wa_ru_pause_until', (string)($nowTs + 3600)); wa_log($pdo, 'campaign', false, 'Utility follow-up paused for an hour: ' . (int)$hr['f'] . ' of ' . (int)$hr['n'] . ' failed'); $res['note'] = 'paused: too many failures'; return $res; }
        // somebody got the utility message → the campaign's own retry of that person must not send a second one
        try { $pdo->exec("UPDATE wa_camp_items i JOIN wa_ru_log r ON r.item_id = i.id JOIN wa_messages m ON m.wamid = r.wamid SET i.status = 'skipped' WHERE (i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND r.ok = 1 AND r.wamid <> '' AND m.status IN ('delivered', 'read')"); } catch (Throwable $e) { }
        $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $cursor = (int)setting_get($pdo, 'wa_ru_cursor', '0'); $lim = 600; $recOn = function_exists('wa_rec_cfg') ? !empty(wa_rec_cfg($pdo)['on']) : false; $recTries = function_exists('wa_rec_cfg') ? max(1, (int)wa_rec_cfg($pdo)['max_attempts'] - 1) : 2;
        $mo = implode(',', WA_RU_MARKETING_ONLY);
        $q = $pdo->prepare("SELECT i.id, i.camp_id, i.phone, i.name, i.err_code, i.status, i.sent_at FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id
            WHERE (i.status = 'failed' OR ((i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND i.err_code IN ($mo))) AND COALESCE(i.variant, 'M') <> 'U' AND COALESCE(c.extra, '') NOT LIKE '%\"kind\":\"util\"%'
              AND (c.started_at >= ? OR i.sent_at >= ?) AND i.id > ? AND NOT EXISTS (SELECT 1 FROM wa_ru_log l WHERE l.item_id = i.id) ORDER BY i.id LIMIT " . $lim);
        $q->execute([$from, $from, $cursor]); $items = $q->fetchAll();
        if (!$items) { if ($cursor > 0) setting_set($pdo, 'wa_ru_cursor', '0'); $res['note'] = 'nothing to follow up'; return $res; }
        $res['looked'] = count($items); $in = implode(',', array_map(fn($x) => (int)$x['id'], $items));
        $phones = array_values(array_unique(array_map(fn($x) => (string)$x['phone'], $items))); $pin = implode(',', array_map(fn($p) => $pdo->quote($p), $phones));
        $ct = []; foreach ($pdo->query("SELECT phone, wa_id, name, optout, camp_optout, last_in_at, last_number_id FROM wa_contacts WHERE phone IN ($pin)")->fetchAll() as $r) $ct[(string)$r['phone']] = $r;
        $got = []; $q3 = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE phone IN ($pin) AND dir = 'out' AND mtype = 'template' AND status IN ('delivered', 'read') AND ts >= ?"); $q3->execute([date('Y-m-d H:i:s', strtotime($from) - 86400)]); foreach ($q3->fetchAll(PDO::FETCH_COLUMN) as $p) $got[(string)$p] = 1;
        $recent = []; $q4 = $pdo->prepare("SELECT DISTINCT phone FROM wa_ru_log WHERE at >= ?"); $q4->execute([date('Y-m-d H:i:s', $nowTs - 86400)]); foreach ($q4->fetchAll(PDO::FETCH_COLUMN) as $p) $recent[(string)$p] = 1;
        $rec = []; if (function_exists('wa_rec_tables') && wa_rec_tables($pdo)) { foreach ($pdo->query("SELECT item_id, COUNT(*) AS n, MAX(at) AS last FROM wa_rec_log WHERE item_id IN ($in) GROUP BY item_id")->fetchAll() as $r) $rec[(int)$r['item_id']] = $r; }
        $cache = []; $seen = []; $rot = 0; $lastId = $cursor; $broke = false;
        $sk = function (string $k) use (&$res, &$why) { $res['skipped']++; $why[$k] = ($why[$k] ?? 0) + 1; };
        foreach ($items as $it) {
            if (microtime(true) - $t0 > 38 || $left <= 0) { $broke = true; break; }
            $ph = (string)$it['phone']; $iid = (int)$it['id']; $lastId = $iid; $ec = (int)$it['err_code']; $cls = wa_ru_class($ec);
            if ($cls === 'never') { $sk('Meta will not deliver this one'); continue; }
            if (isset($recent[$ph]) || isset($seen[$ph])) { $sk('already got a utility message in the last 24 hours'); continue; }
            if (isset($got[$ph])) { $sk('delivered another way today'); continue; }
            $c = $ct[$ph] ?? null; if (!$c) { $sk('no contact record'); continue; } if ((int)$c['optout'] === 1) { $sk('opted out'); continue; }
            if (!empty($c['last_in_at']) && !empty($it['sent_at']) && strtotime((string)$c['last_in_at']) > strtotime((string)$it['sent_at'])) { $sk('wrote back'); continue; }
            $failedAt = !empty($it['sent_at']) ? strtotime((string)$it['sent_at']) : 0;
            if ($cls === 'now') { if ($failedAt > $nowTs - $cfg['wait_min'] * 60) { $sk('waiting a little first'); continue; } }
            else {   // a generic failure: Module A (recovery) goes first
                $r = $rec[$iid] ?? null; $n = $r ? (int)$r['n'] : 0;
                if ($recOn && $n < $recTries) { $sk('recovery from another number goes first'); continue; }
                if ($r && strtotime((string)$r['last']) > $nowTs - 3600) { $sk('recovery tried in the last hour'); continue; }
            }
            $rot++; $pk = wa_util_pick($pdo, $pool, ['phone' => $ph, 'name' => (string)($c['name'] ?? $it['name'] ?? ''), 'optout' => 0, 'last_in_at' => $c['last_in_at'] ?? null], $cfg['ord_days'], $nowTs, $rot);
            if (!$pk) { $sk('not for utility: no enquiry in the last 30 days and no order in the last ' . $cfg['ord_days'] . ' days'); continue; }
            $tid = (int)$pk['t']['id']; if (!array_key_exists($tid, $cache)) $cache[$tid] = wa_ru_tpl_ctx($pdo, $pk['t']);
            [$tUse, $ctx] = $cache[$tid]; if (!$tUse || !$ctx) { $sk('utility message not approved on a number with room'); continue; }
            $cand = array_filter($ctx['nums'], fn($x) => $x['left'] > 0); if (!$cand || $ctx['port_left'] <= 0) { $sk('no number with room'); continue; }
            $hi = array_filter($cand, fn($x) => ($x['q'] ?? '') === 'GREEN'); $md = array_filter($cand, fn($x) => ($x['q'] ?? '') === 'YELLOW');   // best delivery first: High (green) quality numbers, then Medium; a Low / unknown number never sends these
            $own = (int)($c['last_number_id'] ?? 0); $cand = ($own > 0 && (isset($hi[$own]) || isset($md[$own]))) ? [$own => ($hi[$own] ?? $md[$own])] : ($hi ?: $md);   // the number this customer already chats with (if it is High / Medium and has room) keeps the conversation in one place; else the best High number, then Medium
            if (!$cand) { $sk('no High / Medium quality number with room'); continue; }
            uasort($cand, fn($x, $y) => ($x['used'] / max(1, $x['cap'])) <=> ($y['used'] / max(1, $y['cap']))); $num = reset($cand); $nid = (int)$num['id']; $tSend = $num['tm'] ?? $tUse;
            $seen[$ph] = 1; [$ok, $rr, $ecs] = wa_send_tpl_one($pdo, ['phone' => $ph, 'wa_id' => (string)($c['wa_id'] ?? ''), 'camp_optout' => (int)($c['camp_optout'] ?? 0)], $tSend, $num, $pk['vars'], 'recover');
            try { $pdo->prepare("INSERT IGNORE INTO wa_ru_log (camp_id, item_id, phone, kind, number_id, tpl_id, wamid, ok, err_code, first_code, err, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([(int)$it['camp_id'], $iid, $ph, (string)$pk['kind'], $nid, (int)$tSend['id'], $ok ? (string)$rr : '', $ok ? 1 : 0, $ok ? 0 : (int)$ecs, $ec, $ok ? '' : mb_substr((string)$rr, 0, 190), $now]); } catch (Throwable $e) { }
            $ctx['nums'][$nid]['left']--; $ctx['nums'][$nid]['used']++; $ctx['port_left']--; $cache[$tid][1] = $ctx; $left--;
            if ($ok) $res['sent']++; else { $res['failed']++;
                if ((int)$ecs === 131048) { setting_set($pdo, 'wa_ru_pause_until', (string)($nowTs + 6 * 3600)); wa_log($pdo, 'campaign', false, 'Utility follow-up paused for 6 hours: Meta flagged messages as spam (131048)'); $broke = true; break; }
                if (in_array((int)$ecs, [130429, 131056], true)) { $broke = true; break; } }
        }
        setting_set($pdo, 'wa_ru_cursor', (string)(count($items) >= $lim || $broke ? $lastId : 0));   // a lap that read the whole list starts again from the first person
        arsort($why); $res['why'] = array_slice($why, 0, 6, true);
        if ($res['sent'] > 0) wa_log($pdo, 'campaign', true, 'Utility follow-up for failed marketing: ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed, ' . $res['skipped'] . ' left out');
    } catch (Throwable $e) { wa_log($pdo, 'campaign', false, 'Utility follow-up error: ' . $e->getMessage()); $res['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 140); }
    try { setting_set($pdo, 'wa_ru_last', json_encode(['at' => time(), 'note' => $res['note'], 'sent' => $res['sent'], 'failed' => $res['failed'], 'skipped' => $res['skipped'], 'looked' => $res['looked'], 'why' => $res['why']])); } catch (Throwable $e) { }
    return $res;
}

/** Everything the status line / card shows: settings, the last round, and how it did in a time range (default: the business day). */
function wa_ru_status(PDO $pdo, string $from = '', string $to = ''): array
{
    $cfg = wa_ru_cfg($pdo); $last = null; try { $l = json_decode((string)setting_get($pdo, 'wa_ru_last', ''), true); if (is_array($l)) $last = $l; } catch (Throwable $e) { }
    $from = $from !== '' ? $from : (function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00')); $to = $to !== '' ? $to : date('Y-m-d H:i:s', time() + 60);
    $o = ['tried' => 0, 'delivered' => 0, 'failed' => 0, 'waiting' => 0, 'by_kind' => ['ask' => 0, 'order' => 0]];
    if (wa_ru_tables($pdo)) {
        try { $q = $pdo->prepare("SELECT r.kind, COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.at >= ? AND r.at < ? GROUP BY r.kind"); $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $r) { $o['tried'] += (int)$r['n']; $o['delivered'] += (int)$r['d']; $o['failed'] += (int)$r['f']; if (isset($o['by_kind'][(string)$r['kind']])) $o['by_kind'][(string)$r['kind']] = (int)$r['n']; } } catch (Throwable $e) { }
        $o['waiting'] = max(0, $o['tried'] - $o['delivered'] - $o['failed']);
    }
    return ['on' => $cfg['on'], 'paused' => $cfg['pause_until'] > time(), 'hourly' => $cfg['hourly'], 'wait_min' => $cfg['wait_min'], 'ord_days' => $cfg['ord_days'], 'pool' => count(wa_ru_pool($pdo)), 'range' => $o, 'last' => $last, 'now' => time()];
}

}   // function_exists guard
