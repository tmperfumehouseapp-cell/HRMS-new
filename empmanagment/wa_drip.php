<?php
/**
 * wa_drip.php — 🔁 7-DAY DRIP (Campaigns → "7-Day Drip").
 *   A customer who writes to us first (a new query) or replies to a campaign is enrolled automatically: that day is DAY 0 (the normal chat / bot reply),
 *   the next day is DAY 1 ... up to Day 10. For each day the owner picks one approved pre-made message, the time of day, and the number (or "the number they wrote to").
 *   Each message goes out once, by itself, at that time. A customer who ORDERS, opts out, has a complaint, or whose chat a salesperson is working (last 12 h) gets nothing more that day.
 *   Setting wd_cfg (JSON) · tables wa_drip_enr (who entered, when) and wa_drip_ev (what went out / why not). A customer is enrolled once in 30 days.
 *   Actions: wa.drip_get · wa.drip_save · wa.drip_test · wa.drip_log. The background part (wd_tick) is called by the engine tick in wa_more.php. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wd_tables')) {

function wd_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_drip_enr (phone VARCHAR(12) NOT NULL, anchor DATE NOT NULL, src VARCHAR(10) NOT NULL DEFAULT '', enrolled_at DATETIME NOT NULL, left_why VARCHAR(40) NOT NULL DEFAULT '', PRIMARY KEY (phone, anchor), KEY k_anchor (anchor)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_drip_ev (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, anchor DATE NOT NULL, day INT NOT NULL, tpl_id INT NOT NULL DEFAULT 0, number_id INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'sent', why VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, UNIQUE KEY uq (phone, anchor, day), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wd_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (!is_array($j)) $j = [];
    $days = []; foreach ((array)($j['days'] ?? []) as $d => $x) { $d = (int)$d; if ($d < 1 || $d > 10 || !is_array($x) || (int)($x['tpl_id'] ?? 0) <= 0) continue; $t = preg_match('/^\d{1,2}:\d{2}$/', (string)($x['time'] ?? '')) ? (string)$x['time'] : '11:00'; $days[$d] = ['tpl_id' => (int)$x['tpl_id'], 'time' => $t, 'number_id' => (int)($x['number_id'] ?? 0)]; }
    ksort($days);
    return ['on' => !empty($j['on']) ? 1 : 0, 'days' => $days, 'enter_query' => array_key_exists('enter_query', $j) ? (int)!empty($j['enter_query']) : 1, 'enter_campaign' => array_key_exists('enter_campaign', $j) ? (int)!empty($j['enter_campaign']) : 1,
        'pause_person' => array_key_exists('pause_person', $j) ? (int)!empty($j['pause_person']) : 1, 'stop_on_reply' => !empty($j['stop_on_reply']) ? 1 : 0, 'cap' => max(5, min(300, (int)($j['cap'] ?? 40)))];
}
function wd_today(PDO $pdo, int $ts = 0): string { $ts = $ts ?: time(); return function_exists('biz_day_of') ? (string)biz_day_of($pdo, $ts) : date('Y-m-d', $ts); }
function wd_name(array $c): string { $n = function_exists('fu_first_name') ? fu_first_name((string)($c['name'] ?? '')) : ''; return $n !== '' ? $n : 'ji'; }

/** Who enters today: new queries and campaign repliers that wrote since yesterday and are not enrolled in the last 30 days. */
function wd_enroll(PDO $pdo, array $cfg, int $hours = 36): int
{
    $src = []; if ($cfg['enter_query']) $src[] = 'query'; if ($cfg['enter_campaign']) $src[] = 'campaign'; if (!$src) return 0;
    $since = date('Y-m-d H:i:s', time() - $hours * 3600); $n = 0;
    $q = $pdo->prepare("SELECT c.phone, c.source, c.last_in_at FROM wa_contacts c WHERE c.source IN ('" . implode("','", $src) . "') AND c.last_in_at >= ? AND c.optout = 0 AND NOT EXISTS (SELECT 1 FROM wa_drip_enr e WHERE e.phone = c.phone AND e.anchor >= ?) LIMIT 20000");
    $q->execute([$since, date('Y-m-d', time() - 30 * 86400)]);
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_drip_enr (phone, anchor, src, enrolled_at) VALUES (?, ?, ?, ?)");
    foreach ($q->fetchAll() as $r) { $ins->execute([$r['phone'], wd_today($pdo, (int)strtotime((string)$r['last_in_at'])), (string)$r['source'], date('Y-m-d H:i:s')]); $n += (int)$ins->rowCount(); }
    return $n;
}
/** Why this customer must get nothing now ('' = fine). */
function wd_block(PDO $pdo, array $c, array $cfg, string $anchor): string
{
    if ((int)($c['optout'] ?? 0) === 1) return 'opted out';
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return 'complaint';
    try { $q = $pdo->prepare("SELECT 1 FROM wa_cust WHERE phone = ? AND n > 0 LIMIT 1"); $q->execute([$c['phone']]); if ($q->fetchColumn()) return 'ordered'; } catch (Throwable $e) { }
    if ($cfg['pause_person']) { try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'emp' AND ts >= ? LIMIT 1"); $q->execute([$c['phone'], date('Y-m-d H:i:s', time() - 12 * 3600)]); if ($q->fetchColumn()) return 'person chatting'; } catch (Throwable $e) { } }
    if ($cfg['stop_on_reply']) { try { $q = $pdo->prepare("SELECT MAX(at) FROM wa_drip_ev WHERE phone = ? AND anchor = ? AND status = 'sent'"); $q->execute([$c['phone'], $anchor]); $last = (string)$q->fetchColumn(); if ($last !== '' && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > strtotime($last)) return 'replied'; } catch (Throwable $e) { } }
    return '';
}
/** Send one drip message. [ok, reason|'']. */
function wd_send(PDO $pdo, array $c, array $step): array
{
    $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$step['tpl_id'], true, 0) : null; if (!$t) return [false, 'The message is not approved by Meta (or its photo is missing).'];
    $nv = (int)($t['nvars'] ?? 0); if ($nv > 1) return [false, 'This message has ' . $nv . ' blanks — pick one with only a name blank.'];
    $ctx = wa_camp_ctx($pdo, $t, 2); $nid = 0;
    if ((int)$step['number_id'] > 0) { if (!isset($ctx['nums'][(int)$step['number_id']])) return [false, 'The chosen number has no room or cannot send this message now.']; $nid = (int)$step['number_id']; }
    else { [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid) return [false, 'No number has room now (' . $why . ').']; }
    [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $nv ? [wd_name($c)] : [], 'followup');
    return $ok ? [true, '', $nid] : [false, (string)$res, $nid];
}
function wd_tick(PDO $pdo, array $opt = []): array
{
    $o = ['entered' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
    if (!wd_tables($pdo)) return $o; $cfg = wd_cfg($pdo); if (!$cfg['on'] || !$cfg['days']) return $o;
    if (empty($opt['now']) && (!function_exists('wa_lock') || !wa_lock($pdo, 'wdrun', 50))) return $o;
    try { $o['entered'] = wd_enroll($pdo, $cfg); } catch (Throwable $e) { }
    $today = wd_today($pdo); $nowMin = !empty($opt['now']) ? 1439 : (int)date('G') * 60 + (int)date('i'); $sent = 0; if (!empty($opt['now'])) $cfg['cap'] = max(1, (int)($opt['cap'] ?? 150));
    $e = $pdo->prepare("SELECT phone, anchor FROM wa_drip_enr WHERE anchor <= ? AND anchor >= ? AND left_why = ''"); $e->execute([$today, date('Y-m-d', strtotime($today) - 10 * 86400)]);
    $ev = $pdo->prepare("INSERT IGNORE INTO wa_drip_ev (phone, anchor, day, tpl_id, number_id, status, why, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($e->fetchAll() as $r) {
        if ($sent >= $cfg['cap']) break;
        $day = (int)round((strtotime($today) - strtotime((string)$r['anchor'])) / 86400); if (!isset($cfg['days'][$day])) continue; $step = $cfg['days'][$day];
        [$hh, $mm] = array_map('intval', explode(':', $step['time'])); if ($nowMin < $hh * 60 + $mm) continue;   // not time yet
        $d = $pdo->prepare("SELECT status FROM wa_drip_ev WHERE phone = ? AND anchor = ? AND day = ?"); $d->execute([$r['phone'], $r['anchor'], $day]); if ($d->fetchColumn() !== false) continue;   // already done / skipped
        $c = function_exists('wa_contact') ? wa_contact($pdo, (string)$r['phone']) : null; if (!$c) continue;
        $why = wd_block($pdo, $c, $cfg, (string)$r['anchor']);
        if ($why !== '') {
            if ($why === 'person chatting') { if ($nowMin < 22 * 60 || !empty($opt['now'])) continue; }   // try again later today; give up at night
            if (in_array($why, ['ordered', 'opted out', 'complaint', 'replied'], true)) $pdo->prepare("UPDATE wa_drip_enr SET left_why = ? WHERE phone = ? AND anchor = ?")->execute([$why, $r['phone'], $r['anchor']]);
            $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], 0, 'skipped', $why, date('Y-m-d H:i:s')]); $o['skipped']++; continue;
        }
        $res = wd_send($pdo, $c, $step);
        if ($res[0]) { $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], (int)($res[2] ?? 0), 'sent', '', date('Y-m-d H:i:s')]); $o['sent']++; $sent++; }
        elseif (stripos((string)$res[1], 'room') !== false || stripos((string)$res[1], 'hour') !== false) { break; }   // no room: try again on the next tick, nothing is written
        else { $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], (int)($res[2] ?? 0), 'failed', mb_substr((string)$res[1], 0, 190), date('Y-m-d H:i:s')]); $o['failed']++; }
    }
    $o['more'] = $sent >= $cfg['cap'] ? 1 : 0; return $o;
}

}

// ---------------------------------------------------------------------------------------------------------------------- the screen's actions
$__wd = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wd, 'wa.drip_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can set up the drip.'], 403);
    if (!wd_tables($pdo)) json_response(['error' => 'Drip storage is not available.'], 500);
    switch ($__wd) {
        case 'wa.drip_get': {
            $cfg = wd_cfg($pdo); $today = wd_today($pdo); $from = date('Y-m-d', strtotime($today) - 7 * 86400);
            $per = []; $q = $pdo->prepare("SELECT day, status, COUNT(*) n FROM wa_drip_ev WHERE at >= ? GROUP BY day, status"); $q->execute([date('Y-m-d 00:00:00', strtotime($today) - 7 * 86400)]); foreach ($q->fetchAll() as $r) $per[(int)$r['day']][(string)$r['status']] = (int)$r['n'];
            $ent = (int)$pdo->query("SELECT COUNT(*) FROM wa_drip_enr WHERE anchor >= '$from'")->fetchColumn(); $left = []; foreach ($pdo->query("SELECT left_why, COUNT(*) n FROM wa_drip_enr WHERE anchor >= '$from' AND left_why <> '' GROUP BY left_why")->fetchAll() as $r) $left[(string)$r['left_why']] = (int)$r['n'];
            $in0 = (int)$pdo->query("SELECT COUNT(*) FROM wa_drip_enr WHERE anchor = '$today'")->fetchColumn();
            $log = []; foreach ($pdo->query("SELECT e.phone, e.day, e.tpl_id, e.status, e.why, e.at, c.name FROM wa_drip_ev e LEFT JOIN wa_contacts c ON c.phone = e.phone WHERE e.at >= '" . date('Y-m-d 00:00:00') . "' ORDER BY e.id DESC LIMIT 40")->fetchAll() as $r) $log[] = ['name' => (string)($r['name'] ?: $r['phone']), 'day' => (int)$r['day'], 'tpl_id' => (int)$r['tpl_id'], 'status' => $r['status'], 'why' => $r['why'], 'at' => substr((string)$r['at'], 11, 5)];
            $nums = []; try { foreach ($pdo->query("SELECT id, label FROM wa_numbers ORDER BY id")->fetchAll() as $n) $nums[] = ['id' => (int)$n['id'], 'label' => (string)$n['label']]; } catch (Throwable $e) { }
            json_response(['ok' => true, 'cfg' => $cfg, 'per_day' => $per, 'entered' => $ent, 'in_today' => $in0, 'left' => $left, 'log' => $log, 'numbers' => $nums]);
        }
        case 'wa.drip_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $x = (array)($in['cfg'] ?? []);
            $days = []; foreach ((array)($x['days'] ?? []) as $d => $s) if (is_array($s) && (int)($s['tpl_id'] ?? 0) > 0) $days[(int)$d] = ['tpl_id' => (int)$s['tpl_id'], 'time' => (string)($s['time'] ?? '11:00'), 'number_id' => (int)($s['number_id'] ?? 0)];
            setting_set($pdo, 'wd_cfg', json_encode(['on' => !empty($x['on']) ? 1 : 0, 'days' => $days, 'enter_query' => !empty($x['enter_query']) ? 1 : 0, 'enter_campaign' => !empty($x['enter_campaign']) ? 1 : 0, 'pause_person' => !empty($x['pause_person']) ? 1 : 0, 'stop_on_reply' => !empty($x['stop_on_reply']) ? 1 : 0, 'cap' => (int)($x['cap'] ?? 40)]));
            json_response(['ok' => true, 'cfg' => wd_cfg($pdo)]);
        }
        case 'wa.drip_backfill': {   // customers of the last 7 days (queries + campaign repliers) join now, on the day THEY came in (so each one is on the right day)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wd_cfg($pdo); $n = wd_enroll($pdo, $cfg, 7 * 24 + 6);
            $today = wd_today($pdo); $due = []; $q = $pdo->prepare("SELECT anchor, COUNT(*) n FROM wa_drip_enr WHERE anchor <= ? AND anchor >= ? AND left_why = '' GROUP BY anchor"); $q->execute([$today, date('Y-m-d', strtotime($today) - 10 * 86400)]);
            foreach ($q->fetchAll() as $r) { $d = (int)round((strtotime($today) - strtotime((string)$r['anchor'])) / 86400); $due[$d] = ($due[$d] ?? 0) + (int)$r['n']; }
            json_response(['ok' => true, 'enrolled' => $n, 'by_day' => $due]);
        }
        case 'wa.drip_run': {   // send today's day-message to everybody who is due, NOW (ignores the send time); call again while "more" is 1
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wd_cfg($pdo); if (!$cfg['days']) json_response(['error' => 'Pick a message for at least one day and Save first.'], 422);
            @set_time_limit(120); $r = wd_tick($pdo, ['now' => 1, 'cap' => 60]); json_response(['ok' => true] + $r);
        }
        case 'wa.drip_test': {   // send one day's message to a number you type (your own phone)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $k = function_exists('wa_key') ? wa_key($in['phone'] ?? '') : ''; $c = $k !== '' ? wa_contact($pdo, $k) : null;
            if (!$c) json_response(['error' => 'That number is not a chat yet — write to the business number from it first.'], 404);
            $cfg = wd_cfg($pdo); $day = (int)($in['day'] ?? 1); $step = $cfg['days'][$day] ?? null; if (!$step) json_response(['error' => 'Save the day first.'], 422);
            $r = wd_send($pdo, $c, $step); if (!$r[0]) json_response(['error' => $r[1]], 422); json_response(['ok' => true]);
        }
        case 'wa.drip_log': {
            $rows = []; foreach ($pdo->query("SELECT e.phone, e.day, e.status, e.why, e.at, c.name FROM wa_drip_ev e LEFT JOIN wa_contacts c ON c.phone = e.phone ORDER BY e.id DESC LIMIT 200")->fetchAll() as $r) $rows[] = ['name' => (string)($r['name'] ?: $r['phone']), 'phone' => $r['phone'], 'day' => (int)$r['day'], 'status' => $r['status'], 'why' => $r['why'], 'at' => $r['at']];
            json_response(['ok' => true, 'rows' => $rows]);
        }
    }
}
