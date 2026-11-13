<?php
/**
 * wa_drip.php — 🔁 7-DAY DRIP (Campaigns → "7-Day Drip").
 *   A customer who writes to us first (a new query) or replies to a campaign is enrolled automatically: that day is DAY 0 (the normal chat / bot reply),
 *   the next day is DAY 1 ... up to Day 30. For each day the owner picks one approved pre-made message, the time of day, and the number (or "the number they wrote to").
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
        try { if (!$pdo->query("SHOW COLUMNS FROM wa_drip_ev LIKE 'wamid'")->fetch()) $pdo->exec("ALTER TABLE wa_drip_ev ADD COLUMN wamid VARCHAR(80) NOT NULL DEFAULT ''"); } catch (PDOException $e) { }
        $ok = true;
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wd_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wd_cfg', ''), true); if (!is_array($j)) $j = [];
    $days = []; foreach ((array)($j['days'] ?? []) as $d => $x) { $d = (int)$d; if ($d < 1 || $d > 30 || !is_array($x) || (int)($x['tpl_id'] ?? 0) <= 0) continue; $t = preg_match('/^\d{1,2}:\d{2}$/', (string)($x['time'] ?? '')) ? (string)$x['time'] : '11:00'; $days[$d] = ['tpl_id' => (int)$x['tpl_id'], 'time' => $t, 'number_id' => (int)($x['number_id'] ?? 0)]; }
    ksort($days);
    $mode = (string)($j['mode'] ?? (!empty($j['on']) ? 'auto' : 'manual')); if (!in_array($mode, ['off', 'manual', 'auto'], true)) $mode = 'manual';
    return ['mode' => $mode, 'on' => $mode === 'auto' ? 1 : 0, 'days' => $days, 'enter_query' => array_key_exists('enter_query', $j) ? (int)!empty($j['enter_query']) : 1, 'enter_campaign' => array_key_exists('enter_campaign', $j) ? (int)!empty($j['enter_campaign']) : 1, 'enter_community' => !empty($j['enter_community']) ? 1 : 0,
        'pause_person' => array_key_exists('pause_person', $j) ? (int)!empty($j['pause_person']) : 1, 'stop_on_reply' => !empty($j['stop_on_reply']) ? 1 : 0, 'cap' => max(5, min(300, (int)($j['cap'] ?? 40)))];
}
function wd_today(PDO $pdo, int $ts = 0): string { $ts = $ts ?: time(); return function_exists('biz_day_of') ? (string)biz_day_of($pdo, $ts) : date('Y-m-d', $ts); }
/** phone => name, looked up by value (never JOINed: wa_contacts and the drip tables have different text collations). */
function wd_names(PDO $pdo, array $phones): array
{
    $o = []; $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones) return $o;
    $q = $pdo->prepare("SELECT phone, name FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ")"); $q->execute($phones);
    foreach ($q->fetchAll() as $r) $o[(string)$r['phone']] = (string)($r['name'] ?: $r['phone']);
    return $o;
}
function wd_name(array $c): string { $n = function_exists('fu_first_name') ? fu_first_name((string)($c['name'] ?? '')) : ''; return $n !== '' ? $n : 'ji'; }

/** Who is in the drip: customers (new queries / campaign repliers) whose FIRST message to us was inside the last N business days (N = the last day you set). Day 0 = the business day (10 AM → 10 AM) of that first message —
 *  a customer who writes again later keeps the same Day 0; they do not start again. One entry per customer per 30 days. */
function wd_enroll(PDO $pdo, array $cfg): int
{
    $src = []; if ($cfg['enter_query']) $src[] = 'query'; if ($cfg['enter_campaign']) $src[] = 'campaign'; if (!empty($cfg['enter_community'])) $src[] = 'community'; if (!$src) return 0;
    $maxDay = $cfg['days'] ? max(array_keys($cfg['days'])) : 7; $today = wd_today($pdo); $oldest = date('Y-m-d', strtotime($today) - $maxDay * 86400); $n = 0;
    $have = []; $h = $pdo->prepare("SELECT phone FROM wa_drip_enr WHERE anchor >= ?"); $h->execute([date('Y-m-d', strtotime($today) - 31 * 86400)]); foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $p0) $have[(string)$p0] = 1;   // (no JOIN across tables: their text collations differ)
    $q = $pdo->prepare("SELECT c.phone, c.source FROM wa_contacts c WHERE c.source IN ('" . implode("','", $src) . "') AND c.last_in_at >= ? AND c.optout = 0 AND c.camp_optout = 0 LIMIT 60000"); $q->execute([$oldest . ' 00:00:00']);
    $cand = []; foreach ($q->fetchAll() as $r) if (!isset($have[(string)$r['phone']])) $cand[(string)$r['phone']] = (string)$r['source'];
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_drip_enr (phone, anchor, src, enrolled_at) VALUES (?, ?, ?, ?)");
    foreach (array_chunk(array_keys($cand), 500) as $ch) {
        $f = $pdo->prepare("SELECT phone, MIN(ts) t FROM wa_messages WHERE dir = 'in' AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ") GROUP BY phone"); $f->execute($ch);
        foreach ($f->fetchAll() as $r) { $anchor = wd_today($pdo, (int)strtotime((string)$r['t'])); if ($anchor < $oldest || $anchor > $today) continue; $ins->execute([$r['phone'], $anchor, $cand[(string)$r['phone']], date('Y-m-d H:i:s')]); $n += (int)$ins->rowCount(); }
    }
    return $n;
}
/** Why this customer must get nothing now ('' = fine). */
function wd_block(PDO $pdo, array $c, array $cfg, string $anchor): string
{
    if ((int)($c['optout'] ?? 0) === 1 || (int)($c['camp_optout'] ?? 0) === 1) return 'opted out';
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return 'complaint';
    try { $q = $pdo->prepare("SELECT 1 FROM wa_cust WHERE phone = ? AND n > 0 AND last_order >= ? LIMIT 1"); $q->execute([$c['phone'], $anchor . ' 00:00:00']); if ($q->fetchColumn()) return 'ordered'; } catch (Throwable $e) { }   // an order since they came in (earlier orders do not stop the drip)
    if ($cfg['pause_person']) { try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'emp' AND ts >= ? LIMIT 1"); $q->execute([$c['phone'], date('Y-m-d H:i:s', time() - 12 * 3600)]); if ($q->fetchColumn()) return 'person chatting'; } catch (Throwable $e) { } }
    if ($cfg['stop_on_reply']) { try { $q = $pdo->prepare("SELECT MAX(at) FROM wa_drip_ev WHERE phone = ? AND anchor = ? AND status = 'sent'"); $q->execute([$c['phone'], $anchor]); $last = (string)$q->fetchColumn(); if ($last !== '' && !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > strtotime($last)) return 'replied'; } catch (Throwable $e) { } }
    return '';
}
/** Why not one number can send this message right now, number by number (so "no room" never hides a Meta payment problem or an unapproved message). */
function wd_why_none(PDO $pdo, array $t): string
{
    $out = []; $caps = []; try { $caps = wa_number_caps($pdo, wa_camp_health($pdo)); } catch (Throwable $e) { }
    try {
        foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) {
            $id = (int)$n['id']; $lab = (string)($n['label'] !== '' ? $n['label'] : ($n['display'] ?? 'Number ' . $id)); $c = $caps[$id] ?? null; $why = '';
            if (!in_array(wa_waba_of($n), (array)($t['ok_wabas'] ?? []), true)) $why = 'this message is not approved on its WhatsApp account';
            else { $pb = (string)setting_get($pdo, 'wa_payblock_' . $id, ''); if ($pb !== '' && strtotime($pb) > time() - 3 * 3600) $why = 'Meta payment problem (it is paused until ' . date('H:i', strtotime($pb) + 3 * 3600) . ')';
                elseif (!$c) $why = 'not connected / switched off for campaigns'; elseif ((int)$c['left'] <= 0) $why = ((string)($c['why'] ?? '') !== '' ? (string)$c['why'] : 'no room left today'); }
            if ($why !== '') $out[] = $lab . ': ' . $why;
        }
    } catch (Throwable $e) { }
    return $out ? implode(' · ', $out) : 'no number is available';
}
/** Send one drip message. [ok, reason|'']. */
function wd_send(PDO $pdo, array $c, array $step): array
{
    $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$step['tpl_id'], true, 0) : null; if (!$t) return [false, 'The message is not approved by Meta (or its photo is missing).'];
    $nv = (int)($t['nvars'] ?? 0); if ($nv > 1) return [false, 'This message has ' . $nv . ' blanks — pick one with only a name blank.'];
    $ctx = wa_camp_ctx($pdo, $t, 2); $nid = 0;
    if (empty($ctx['nums'])) return [false, 'No number has room now (' . wd_why_none($pdo, $t) . ')'];
    if ((int)$step['number_id'] > 0) { if (!isset($ctx['nums'][(int)$step['number_id']])) return [false, 'The chosen number has no room or cannot send this message now.']; $nid = (int)$step['number_id']; }
    else { [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid) return [false, 'No number has room now (' . $why . ').']; }
    [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $nv ? [wd_name($c)] : [], 'followup');
    return $ok ? [true, '', $nid, (string)$res] : [false, (string)$res, $nid];
}
/** The "Send today's drip now" job: started by the button, worked through in batches by the page (every few seconds) or the engine tick, so the screen can be closed. */
function wd_job(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wd_job', ''), true); return is_array($j) ? $j : ['status' => 'none']; }
function wd_job_put(PDO $pdo, array $j): void { setting_set($pdo, 'wd_job', json_encode($j)); }
/** One batch of the running job (a lock keeps two callers from sending the same customer). Returns the job. */
function wd_job_step(PDO $pdo): array
{
    $j = wd_job($pdo); if (($j['status'] ?? '') !== 'running') return $j;
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('wd_job', 0)")->fetchColumn(); } catch (Throwable $e) { }
    if ($got !== 1) return $j;   // another caller is already working on it
    try {
        @set_time_limit(100); $j = wd_job($pdo); if (($j['status'] ?? '') !== 'running') return $j;
        $r = wd_tick($pdo, ['now' => 1, 'cap' => 60, 'job' => 1, 'skip_enrol' => !empty($j['enrolled'])]); $j['enrolled'] = 1;
        $j['sent'] = (int)($j['sent'] ?? 0) + (int)($r['sent'] ?? 0); $j['skipped'] = (int)($j['skipped'] ?? 0) + (int)($r['skipped'] ?? 0); $j['failed'] = (int)($j['failed'] ?? 0) + (int)($r['failed'] ?? 0); $j['at'] = date('Y-m-d H:i:s');
        if (!empty($r['why'])) $j['why'] = (string)$r['why'];
        if (empty($r['more'])) { $j['status'] = !empty($r['why']) ? 'stopped' : 'done'; $j['finished'] = date('Y-m-d H:i:s'); }
        wd_job_put($pdo, $j);
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wd_job')"); } catch (Throwable $e) { } }
    return $j;
}
function wd_tick(PDO $pdo, array $opt = []): array
{
    $o = ['entered' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
    if (!wd_tables($pdo)) return $o; $cfg = wd_cfg($pdo); if (!$cfg['days']) return $o;
    if (empty($opt['now']) && (wd_job($pdo)['status'] ?? '') === 'running') { wd_job_step($pdo); return $o; }   // a Send-now job is waiting: the engine works on it
    if (empty($opt['now']) && $cfg['mode'] !== 'auto') return $o;   // manual mode: only the Send button runs it
    if (empty($opt['now']) && (!function_exists('wa_lock') || !wa_lock($pdo, 'wdrun', 50))) return $o;
    if (empty($opt['skip_enrol'])) { try { $o['entered'] = wd_enroll($pdo, $cfg); } catch (Throwable $e) { } }
    $today = wd_today($pdo); $nowMin = !empty($opt['now']) ? 1439 : (int)date('G') * 60 + (int)date('i'); $sent = 0; if (!empty($opt['now'])) $cfg['cap'] = max(1, (int)($opt['cap'] ?? 150));
    $e = $pdo->prepare("SELECT phone, anchor FROM wa_drip_enr WHERE anchor <= ? AND anchor >= ? AND left_why = ''"); $e->execute([$today, date('Y-m-d', strtotime($today) - 31 * 86400)]);
    $ev = $pdo->prepare("INSERT IGNORE INTO wa_drip_ev (phone, anchor, day, tpl_id, number_id, status, why, at, wamid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
            $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], 0, 'skipped', $why, date('Y-m-d H:i:s'), '']); $o['skipped']++; continue;
        }
        $res = wd_send($pdo, $c, $step);
        if ($res[0]) { $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], (int)($res[2] ?? 0), 'sent', '', date('Y-m-d H:i:s'), (string)($res[3] ?? '')]); $o['sent']++; $sent++; }
        elseif (stripos((string)$res[1], 'No number has room') === 0 || stripos((string)$res[1], 'The chosen number has no room') === 0) { $o['why'] = (string)$res[1]; break; }   // OUR 'no room' answer: try again on the next tick, nothing is written
        elseif (stripos((string)$res[1], 'not approved by Meta') !== false) { $o['why'] = (string)$res[1] . ' (Day ' . $day . ')'; $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], 0, 'failed', mb_substr((string)$res[1], 0, 190), date('Y-m-d H:i:s'), '']); $o['failed']++; }
        else { $ev->execute([$r['phone'], $r['anchor'], $day, $step['tpl_id'], (int)($res[2] ?? 0), 'failed', mb_substr((string)$res[1], 0, 190), date('Y-m-d H:i:s'), '']); $o['failed']++; }
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
            $log = []; $evr = $pdo->query("SELECT phone, day, tpl_id, status, why, at FROM wa_drip_ev WHERE at >= '" . date('Y-m-d 00:00:00') . "' ORDER BY id DESC LIMIT 40")->fetchAll(); $nm = wd_names($pdo, array_column($evr, 'phone'));
            foreach ($evr as $r) $log[] = ['name' => $nm[(string)$r['phone']] ?? (string)$r['phone'], 'day' => (int)$r['day'], 'tpl_id' => (int)$r['tpl_id'], 'status' => $r['status'], 'why' => $r['why'], 'at' => substr((string)$r['at'], 11, 5)];
            $nums = []; try { foreach ($pdo->query("SELECT id, label FROM wa_numbers ORDER BY id")->fetchAll() as $n) $nums[] = ['id' => (int)$n['id'], 'label' => (string)$n['label']]; } catch (Throwable $e) { }
            json_response(['ok' => true, 'cfg' => $cfg, 'per_day' => $per, 'entered' => $ent, 'in_today' => $in0, 'left' => $left, 'log' => $log, 'numbers' => $nums]);
        }
        case 'wa.drip_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $x = (array)($in['cfg'] ?? []);
            $days = []; foreach ((array)($x['days'] ?? []) as $d => $s) if (is_array($s) && (int)($s['tpl_id'] ?? 0) > 0) $days[(int)$d] = ['tpl_id' => (int)$s['tpl_id'], 'time' => (string)($s['time'] ?? '11:00'), 'number_id' => (int)($s['number_id'] ?? 0)];
            setting_set($pdo, 'wd_cfg', json_encode(['mode' => in_array((string)($x['mode'] ?? ''), ['off', 'manual', 'auto'], true) ? (string)$x['mode'] : 'manual', 'on' => (string)($x['mode'] ?? '') === 'auto' ? 1 : 0, 'days' => $days, 'enter_query' => !empty($x['enter_query']) ? 1 : 0, 'enter_campaign' => !empty($x['enter_campaign']) ? 1 : 0, 'enter_community' => !empty($x['enter_community']) ? 1 : 0, 'pause_person' => !empty($x['pause_person']) ? 1 : 0, 'stop_on_reply' => !empty($x['stop_on_reply']) ? 1 : 0, 'cap' => (int)($x['cap'] ?? 40)]));
            json_response(['ok' => true, 'cfg' => wd_cfg($pdo)]);
        }
        case 'wa.drip_backfill': {   // customers of the last 7 days (queries + campaign repliers) join now, on the day THEY came in (so each one is on the right day)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wd_cfg($pdo); $n = wd_enroll($pdo, $cfg, 7 * 24 + 6);
            $today = wd_today($pdo); $due = []; $q = $pdo->prepare("SELECT anchor, COUNT(*) n FROM wa_drip_enr WHERE anchor <= ? AND anchor >= ? AND left_why = '' GROUP BY anchor"); $q->execute([$today, date('Y-m-d', strtotime($today) - 31 * 86400)]);
            foreach ($q->fetchAll() as $r) { $d = (int)round((strtotime($today) - strtotime((string)$r['anchor'])) / 86400); $due[$d] = ($due[$d] ?? 0) + (int)$r['n']; }
            json_response(['ok' => true, 'enrolled' => $n, 'by_day' => $due]);
        }
        case 'wa.drip_run': {   // send today's day-message to everybody who is due, NOW (ignores the send time); call again while "more" is 1
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wd_cfg($pdo); if (!$cfg['days']) json_response(['error' => 'Pick a message for at least one day and Save first.'], 422);
            @set_time_limit(120); $r = wd_tick($pdo, ['now' => 1, 'cap' => 60]); json_response(['ok' => true] + $r);
        }
        case 'wa.drip_stats': {   // the drip as a campaign: totals + one line per day (sent to Meta, delivered, read, replied, failed, left out)
            $cfg = wd_cfg($pdo); $since = date('Y-m-d H:i:s', time() - 30 * 86400);
            $rows = $pdo->prepare("SELECT phone, day, status, why, at, wamid FROM wa_drip_ev WHERE at >= ?"); $rows->execute([$since]); $rows = $rows->fetchAll();
            $ids = []; foreach ($rows as $r) if ($r['status'] === 'sent' && $r['wamid'] !== '') $ids[] = (string)$r['wamid'];
            $st = []; foreach (array_chunk($ids, 800) as $ch) { $q = $pdo->prepare("SELECT wamid, status FROM wa_messages WHERE wamid IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $m) $st[(string)$m['wamid']] = (string)$m['status']; }
            $phones = array_values(array_unique(array_column(array_filter($rows, fn($r) => $r['status'] === 'sent'), 'phone'))); $inb = [];
            foreach (array_chunk($phones, 800) as $ch) { $q = $pdo->prepare("SELECT phone, ts FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute(array_merge([$since], $ch)); foreach ($q->fetchAll() as $m) $inb[(string)$m['phone']][] = strtotime((string)$m['ts']); }
            $blank = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0, 'skipped' => 0]; $tot = $blank; $per = []; $first = null; $last = null;
            foreach ($rows as $r) {
                $d = (int)$r['day']; if (!isset($per[$d])) $per[$d] = $blank; $at = strtotime((string)$r['at']); $first = $first === null ? $at : min($first, $at); $last = $last === null ? $at : max($last, $at);
                if ($r['status'] === 'skipped') { $per[$d]['skipped']++; $tot['skipped']++; continue; }
                if ($r['status'] === 'failed') { $per[$d]['failed']++; $tot['failed']++; continue; }
                $ms = $st[(string)$r['wamid']] ?? 'sent'; if ($ms === 'failed') { $per[$d]['failed']++; $tot['failed']++; continue; }
                $per[$d]['sent']++; $tot['sent']++; if ($ms === 'delivered' || $ms === 'read') { $per[$d]['delivered']++; $tot['delivered']++; } if ($ms === 'read') { $per[$d]['read']++; $tot['read']++; }
                foreach (($inb[(string)$r['phone']] ?? []) as $t) if ($t > $at && $t <= $at + 2 * 86400) { $per[$d]['replied']++; $tot['replied']++; break; }
            }
            ksort($per); $days = []; foreach ($per as $d => $x) { $tn = ''; if (isset($cfg['days'][$d]) && function_exists('wa_tpl_row')) { $tr = wa_tpl_row($pdo, (int)$cfg['days'][$d]['tpl_id']); $tn = $tr ? str_replace('_', ' ', (string)$tr['name']) : ''; } $days[] = ['day' => $d, 'tpl' => $tn] + $x; }
            json_response(['ok' => true, 'job' => wd_job($pdo), 'on' => $cfg['mode'] !== 'off' ? 1 : 0, 'mode' => $cfg['mode'], 'days_set' => count($cfg['days']), 'total' => $tot, 'days' => $days, 'first' => $first ? date('Y-m-d H:i:s', $first) : '', 'last' => $last ? date('Y-m-d H:i:s', $last) : '']);
        }
        case 'wa.drip_preview': {   // today's send: who gets which day's message right now, counted by business day (10 AM → 10 AM)
            $cfg = wd_cfg($pdo); if (!$cfg['days']) json_response(['ok' => true, 'rows' => [], 'total' => ['customers' => 0, 'will' => 0, 'left' => 0, 'cost' => 0], 'today' => wd_today($pdo)]);
            @set_time_limit(120); $entered = 0; try { $entered = wd_enroll($pdo, $cfg); } catch (Throwable $e) { }
            $today = wd_today($pdo); $R = function_exists('wab_rates') ? wab_rates($pdo) : ['marketing' => 1.09]; $price = (float)$R['marketing']; $rows = []; $tt = ['customers' => 0, 'will' => 0, 'left' => 0, 'already' => 0];
            $since12 = date('Y-m-d H:i:s', time() - 12 * 3600);
            foreach ($cfg['days'] as $d => $step) {
                $anchor = date('Y-m-d', strtotime($today) - $d * 86400);
                $q = $pdo->prepare("SELECT phone, src FROM wa_drip_enr WHERE anchor = ? AND left_why = ''"); $q->execute([$anchor]); $ph = []; $bySrc = []; foreach ($q->fetchAll() as $r0) { $ph[] = (string)$r0['phone']; $bySrc[(string)$r0['src']] = ($bySrc[(string)$r0['src']] ?? 0) + 1; }
                $done = []; $dq = $pdo->prepare("SELECT phone, status, at FROM wa_drip_ev WHERE anchor = ? AND day = ?"); $dq->execute([$anchor, $d]); $lastAt = ''; foreach ($dq->fetchAll() as $r) { $done[(string)$r['phone']] = (string)$r['status']; if ($r['status'] === 'sent' && (string)$r['at'] > $lastAt) $lastAt = (string)$r['at']; }
                $left = ['ordered' => 0, 'opted out' => 0, 'complaint' => 0, 'person chatting' => 0]; $will = 0; $already = 0; $info = []; $ord = []; $chat = [];
                foreach (array_chunk($ph, 600) as $ch) {
                    $in = implode(',', array_fill(0, count($ch), '?'));
                    $c = $pdo->prepare("SELECT phone, optout, cx_id, tag FROM wa_contacts WHERE phone IN ($in)"); $c->execute($ch); foreach ($c->fetchAll() as $r) $info[(string)$r['phone']] = $r;
                    try { $o = $pdo->prepare("SELECT phone FROM wa_cust WHERE n > 0 AND last_order >= ? AND phone IN ($in)"); $o->execute(array_merge([$anchor . ' 00:00:00'], $ch)); foreach ($o->fetchAll(PDO::FETCH_COLUMN) as $x) $ord[(string)$x] = 1; } catch (Throwable $e) { }
                    if ($cfg['pause_person']) { try { $m = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE dir = 'out' AND who = 'emp' AND ts >= ? AND phone IN ($in)"); $m->execute(array_merge([$since12], $ch)); foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $x) $chat[(string)$x] = 1; } catch (Throwable $e) { } }
                }
                foreach ($ph as $p0) {
                    if (isset($done[$p0])) { if ($done[$p0] === 'sent') $already++; continue; }
                    $c = $info[$p0] ?? null; if (!$c) continue;
                    if ((int)$c['optout'] === 1) $left['opted out']++; elseif ((int)$c['cx_id'] > 0 || (string)$c['tag'] === 'complaint') $left['complaint']++; elseif (isset($ord[$p0])) $left['ordered']++; elseif (isset($chat[$p0])) $left['person chatting']++; else $will++;
                }
                $tr = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, (int)$step['tpl_id']) : null; $body = $tr ? trim(preg_replace('/\s+/u', ' ', str_replace(['{{1}}'], ['Rahul'], (string)$tr['body']))) : '';
                $lf = array_sum($left); $rows[] = ['day' => $d, 'anchor' => $anchor, 'tpl_id' => (int)$step['tpl_id'], 'tpl' => $tr ? str_replace('_', ' ', (string)$tr['name']) : '', 'preview' => mb_substr($body, 0, 90), 'customers' => count($ph), 'by_src' => $bySrc, 'left' => $left, 'left_n' => $lf, 'already' => $already, 'will' => $will, 'sent_at' => $lastAt !== '' ? substr($lastAt, 11, 5) : ''];
                $tt['customers'] += count($ph); $tt['will'] += $will; $tt['left'] += $lf; $tt['already'] += $already;
            }
            $tt['cost'] = round($tt['will'] * $price); $tt['price'] = $price;
            json_response(['ok' => true, 'today' => $today, 'entered' => $entered, 'rows' => $rows, 'total' => $tt, 'mode' => $cfg['mode']]);
        }
        case 'wa.drip_send': {   // start the job and return at once — the screen can be closed
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $cfg = wd_cfg($pdo); if (!$cfg['days']) json_response(['error' => 'Pick a message for at least one day and Save first.'], 422);
            $j = wd_job($pdo); if (($j['status'] ?? '') === 'running' && strtotime((string)($j['at'] ?? $j['started'] ?? '')) > time() - 600) json_response(['ok' => true, 'job' => $j, 'already' => 1]);
            $j = ['status' => 'running', 'started' => date('Y-m-d H:i:s'), 'at' => date('Y-m-d H:i:s'), 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'why' => '']; wd_job_put($pdo, $j); json_response(['ok' => true, 'job' => $j]);
        }
        case 'wa.drip_step': { json_response(['ok' => true, 'job' => wd_job_step($pdo)]); }
        case 'wa.drip_stop': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $j = wd_job($pdo); if (($j['status'] ?? '') === 'running') { $j['status'] = 'stopped'; $j['finished'] = date('Y-m-d H:i:s'); $j['why'] = 'You stopped it.'; wd_job_put($pdo, $j); } json_response(['ok' => true, 'job' => $j]); }
        case 'wa.drip_test': {   // send one day's message to a number you type (your own phone)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $k = function_exists('wa_key') ? wa_key($in['phone'] ?? '') : ''; $c = $k !== '' ? wa_contact($pdo, $k) : null;
            if (!$c) json_response(['error' => 'That number is not a chat yet — write to the business number from it first.'], 404);
            $cfg = wd_cfg($pdo); $day = (int)($in['day'] ?? 1); $step = $cfg['days'][$day] ?? null; if (!$step) json_response(['error' => 'Save the day first.'], 422);
            $r = wd_send($pdo, $c, $step); if (!$r[0]) json_response(['error' => $r[1]], 422); json_response(['ok' => true]);
        }
        case 'wa.drip_log': {
            $rows = []; $evr = $pdo->query("SELECT phone, day, status, why, at FROM wa_drip_ev ORDER BY id DESC LIMIT 200")->fetchAll(); $nm = wd_names($pdo, array_column($evr, 'phone'));
            foreach ($evr as $r) $rows[] = ['name' => $nm[(string)$r['phone']] ?? (string)$r['phone'], 'phone' => $r['phone'], 'day' => (int)$r['day'], 'status' => $r['status'], 'why' => $r['why'], 'at' => $r['at']];
            json_response(['ok' => true, 'rows' => $rows]);
        }
    }
}
