<?php
/**
 * wq_market.php — 🏪 OPEN MARKET AUTO-ASSIGN (Sales → Team performance → Auto assign). Loaded by wa_more.php right after wq_manager.php. PHP 7.4 safe.
 *
 * Owner's idea (12 Nov): every query (Meta, campaign, community, returning, anything) goes by itself to the person most likely to turn it into a
 * high-value order RIGHT NOW. Better sellers get more queries; nobody is ruled by a fixed number. No query is left waiting. Manual giving is off
 * while it runs (admins keep an emergency move).
 *
 * The brain (no fixed rules — every limit is learned from the own data and recalculated every few minutes; the owner's numbers are only day-one starts):
 *   score       revenue per query compared with what the TEAM makes from the same kind of queries (type + returning customer), blended with the team
 *               average when there are few queries (so a newcomer starts at average and their real result takes over as data grows). Ticket size is
 *               inside it (revenue, after discount). Cancelled / voided / refunded orders do not count; placed orders count at once.
 *   today       today's own result moves the score a little; "orders on the way" (customer asked to pay, payment link sent) count as hope.
 *   capacity    learned per person: how many queries in the last 45 min they can hold before their own order rate drops. New people start at 6.
 *   hold        a person who is CLEARLY (statistically sure, not one bad hour) below the team gets no new queries until they place an order today
 *               or have one on the way. The first queries (trial) are never judged.
 *   rising star a share of plain queries goes to people whose last 7 days beat their month, and to people on trial, so the top does not take everything.
 *   VIP         returning big-spenders go to the best ticket-size seller present. A returning buyer goes back to the person who sold them before.
 *   move-back   (OFF by default since 12 Nov — setting wqx_move_on) a chat with no proper answer from its holder (or a holder who punched out / is on a break) goes back to the market after a timer that is
 *               learned from how fast the chance of an order drops with waiting; shorter for ready-to-pay, longer when everyone is busy; at most 2 moves.
 *   never left  when nobody has room, the best present person still gets it and the admins get a push.
 *
 * Start / Stop (setting wqx_mode): off (default, nothing happens) | live (assigns). 'shadow' (decide + log only) is kept for tests, not shown.
 * While it runs it is the ONE engine: the Team manager is switched off, manual giving is for admins only (emergency), both put back on Stop.
 * Present = punched in, or work-from-home mark, or the admin's "working" switch (leave / holiday / week-off / "not working" = away).
 * Heavy maths runs in the background at most every 5 minutes (one copy at a time) and is stored; the assigning pass only reads the stored copy.
 *
 * Giving by hand: OFF for everyone while it runs. The admin tells Claude instead (wq.mkt_ask): Claude assigns the chat and keeps the concern as a rule.
 * Bot first: a new chat goes to a person only when 🔴 ready to pay (after a 45 s open race where anybody may Accept it), 🟠 interested, VIP / returning,
 * the customer answers the bot 2-3 times, or the bot is silent. Plain chats stay with the bot and use nobody's room — but never forever: after 10 min with the bot only
 * (wqx_sweep_sec) every chat is shared out evenly for a person's follow-up, so no query is left unassigned (as with manual giving).
 * Effort: a cold customer (never wrote again) the salesperson still followed up (2+ proper messages) is left out of their ratio; eager
 * customers (3+ messages) carry a higher expectation. Follow-ups: who went quiet on whom is in the coach line and pushed every 15 min.
 * Picking a chat and not answering it = it goes back to the market (🟠) after the timer.
 * Line + gate (12 Nov): people replying to customers in the last 3 min come first (then 10 min, then quiet). A person with a customer waiting
 * 3+ min (wqx_gate_sec) for their answer, or 2 waiting at once, gets nothing new until they reply — the next person in line gets it.
 * Stop / start a person (admin or Sales Head): wq.mkt_stop POST {emp_id, why, mins (minutes · -1 rest of today · 0 until started), handover} ·
 * wq.mkt_start POST {emp_id}. Stopped = no new query whatever the score; ends by itself at the chosen time.
 * Actions: wq.mkt_ask POST {text} · wq.mkt_hint_del POST {id} · wq.mkt_get (admin board) · wq.mkt_mode POST {mode} · wq.mkt_cfg POST {break_max, explore} · wq.mkt_recalc POST · wq.mkt_me (salesperson)
 *          wq.mkt_break POST {on: 1|0} (salesperson)
 * Tables: wqx_log (every decision), wqx_break (breaks).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wqx_mode')) {

// ------------------------------------------------------------------------------------------------------------------ settings
function wqx_mode(PDO $pdo): string { $m = (string)setting_get($pdo, 'wqx_mode', 'off'); return in_array($m, ['off', 'shadow', 'live'], true) ? $m : 'off'; }
function wqx_cfg(PDO $pdo): array
{
    $g = function ($k, $d, $lo, $hi) use ($pdo) { $v = setting_get($pdo, $k, ''); return ($v === '' || $v === null || !is_numeric($v)) ? $d : max($lo, min($hi, (int)$v)); };
    return ['break_max' => $g('wqx_break_max', 60, 0, 240), 'explore' => $g('wqx_explore', 15, 0, 40), 'trial' => $g('wqx_trial', 6, 3, 30), 'start_cap' => $g('wqx_start_cap', 6, 2, 30), 'start_timer' => $g('wqx_start_timer', 120, 30, 600)];
}
function wqx_now(): int { return defined('WQ_NOW') ? (int)WQ_NOW : time(); }
function wqx_dt(int $t = 0): string { return date('Y-m-d H:i:s', $t > 0 ? $t : wqx_now()); }
function wqx_ts($s): int { if ($s === null || $s === '') return 0; $t = strtotime((string)$s); return $t ? (int)$t : 0; }
function wqx_p10($p): string { $d = preg_replace('/\D+/', '', (string)$p); return strlen($d) >= 10 ? substr($d, -10) : ''; }
function wqx_in(array $a): string { return implode(',', array_fill(0, max(1, count($a)), '?')); }
function wqx_meta(PDO $pdo, string $k, string $d = ''): string { return function_exists('wq_meta_get') ? wq_meta_get($pdo, $k, $d) : (string)setting_get($pdo, 'wqx_' . $k, $d); }
function wqx_meta_put(PDO $pdo, string $k, string $v): void { if (function_exists('wq_meta_put')) wq_meta_put($pdo, $k, $v); else setting_set($pdo, 'wqx_' . $k, $v); }

function wqx_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $pdo->exec("CREATE TABLE IF NOT EXISTS wqx_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, in_at DATETIME NULL, kind VARCHAR(12) NOT NULL, from_emp INT NOT NULL DEFAULT 0, to_emp INT NOT NULL DEFAULT 0,
            why VARCHAR(255) NOT NULL DEFAULT '', applied TINYINT NOT NULL DEFAULT 0, mode VARCHAR(8) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY k_at (at), KEY k_phone (phone, at), KEY k_to (to_emp, at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wqx_break (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NULL, KEY k_emp (emp_id, start_at)) $E");
        if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
        return $ok = true;
    } catch (Throwable $e) { error_log('wqx_tables: ' . $e->getMessage()); return $ok = false; }
}

// ------------------------------------------------------------------------------------------------------------------ small pieces
/** Upper end of the 90 % range of a ratio (o of n). Same maths as the Team manager. */
function wqx_hi(int $o, int $n): float
{
    if ($n <= 0) return 1.0; $z = 1.645; $p = $o / $n; $d = 1 + $z * $z / $n; $c = $p + $z * $z / (2 * $n);
    $a = $z * sqrt(max(0.0, $p * (1 - $p) / $n + $z * $z / (4 * $n * $n))); return min(1.0, ($c + $a) / $d);
}
/** First index in sorted $a whose value is >= $v. */
function wqx_lower(array $a, int $v): int { $lo = 0; $hi = count($a); while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($a[$m] < $v) $lo = $m + 1; else $hi = $m; } return $lo; }
function wqx_sales(PDO $pdo): array
{
    try { return array_map('intval', $pdo->query("SELECT e.employee_id FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
}
/** Who is working now: punched in (office), or marked work-from-home today, or switched "working" by the admin. Leave / holiday / week-off marks and
 *  the admin's "not working" switch take a person out (unless they punched in anyway). Same rule as the Control Tower. [emp => 1] */
function wqx_present(PDO $pdo): array
{
    $o = []; try { foreach ($pdo->query("SELECT DISTINCT employee_id FROM attendance WHERE punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= '" . wqx_dt(wqx_now() - 20 * 3600) . "'")->fetchAll(PDO::FETCH_COLUMN) as $e) $o[(int)$e] = 1; } catch (Throwable $e) { }
    $day = function_exists('biz_today') ? (string)biz_today($pdo) : date('Y-m-d', wqx_now());
    $wm = json_decode((string)setting_get($pdo, 'ct_wm_' . $day, ''), true); if (!is_array($wm)) $wm = [];
    $mk = []; if (function_exists('day_marks_between')) { try { $mk = day_marks_between($pdo, $day, $day); } catch (Throwable $e) { } }
    foreach (wqx_sales($pdo) as $e) {
        if (isset($o[$e])) continue; $w = (string)($wm[$e] ?? $wm[(string)$e] ?? ''); $m = (string)($mk[$day . '|' . $e] ?? '');
        if ($w === 'off' || in_array($m, ['leave', 'holiday', 'week_off'], true)) continue;
        if ($m === 'wfh' || $w === 'on') $o[$e] = 1;
    }
    return $o;
}
/** Today's targets of the sales people [emp => rupees], from the Control Tower's own day maths. Kept 30 minutes (it is heavy). */
function wqx_targets(PDO $pdo): array
{
    $j = json_decode(wqx_meta($pdo, 'wqx:tg', ''), true); if (is_array($j) && (int)($j['at'] ?? 0) > wqx_now() - 1800) return (array)$j['t'];
    $t = []; if (!function_exists('coach_day') || !function_exists('biz_today')) return $t;
    try { $d = coach_day($pdo, (string)biz_today($pdo), false); foreach ((array)($d['emps'] ?? []) as $id => $m) $t[(int)$id] = (float)($m['target'] ?? 0); } catch (Throwable $e) { return $t; }
    wqx_meta_put($pdo, 'wqx:tg', json_encode(['at' => wqx_now(), 't' => $t])); return $t;
}
/** 🔔 While the market runs: (1) someone answering customers without a punch-in (or work-from-home mark) is told to punch in — without it they get no
 *  queries and their target / commission does not count; (2) someone working with no target today is told to ask the admin, and the admins are told whom
 *  to give a target. Each reminder at most every 2-3 hours. */
function wqx_nudges(PDO $pdo): array
{
    $now = wqx_now(); $sent = 0; if (!function_exists('ct_push')) return ['sent' => 0];
    $sales = array_flip(wqx_sales($pdo)); $present = wqx_present($pdo); $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $due = function (string $k, int $gap) use ($pdo, $now) { $t = (int)wqx_meta($pdo, $k, '0'); if ($t > $now - $gap) return false; wqx_meta_put($pdo, $k, (string)$now); return true; };
    try { foreach ($pdo->query("SELECT DISTINCT emp_id FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND ts >= '" . wqx_dt($now - 1200) . "'")->fetchAll(PDO::FETCH_COLUMN) as $e) {
        $e = (int)$e; if (!isset($sales[$e]) || isset($present[$e])) continue;
        if ($due('wqx:n:punch:' . $e, 7200)) { try { ct_push($pdo, [$e], '⏰ Punch in now', 'You are answering customers but you have not punched in (office or work from home). Without a punch-in you get no new queries and your target and commission do not count.', '/attendance.html', 'wqx-punch-' . $e); $sent++; } catch (Throwable $x) { } }
    } } catch (Throwable $e) { }
    // 📣 aggressive follow-ups: every 15 min each person at work gets the names of the customers who went quiet on them (we answered, no reply 5 min+, no order)
    try { $q = $pdo->prepare("SELECT c.assigned_emp e, c.name, c.phone FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.assigned_emp > 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag NOT IN ('complaint','order_status'))
            AND c.last_out_at IS NOT NULL AND c.last_out_at > c.last_in_at AND c.last_out_at <= ? AND c.last_in_at >= ? ORDER BY c.last_in_at DESC LIMIT 500"); $q->execute([wqx_dt($now - 300), wqx_dt($now - 20 * 3600)]); $byE = [];
        foreach ($q->fetchAll() as $r) $byE[(int)$r['e']][] = trim((string)$r['name']) !== '' ? trim((string)$r['name']) : '+91 ' . $r['phone'];
        foreach ($byE as $e => $ns) { if (!isset($present[$e]) || !$due('wqx:n:fu:' . $e, 900)) continue; try { ct_push($pdo, [$e], '📣 Follow up ' . count($ns) . ' customer' . (count($ns) === 1 ? '' : 's'), implode(', ', array_slice($ns, 0, 6)) . (count($ns) > 6 ? ' +' . (count($ns) - 6) . ' more' : '') . ' went quiet. Send a photo, an offer or a question now.', '/my_chats.html', 'wqx-fu-' . $e); $sent++; } catch (Throwable $x) { } }
    } catch (Throwable $e) { }
    $tg = wqx_targets($pdo); $miss = [];
    if ($tg) foreach ($present as $e => $_) { if (!isset($sales[$e]) || (float)($tg[$e] ?? 0) > 0) continue; $miss[] = $e;
        if ($due('wqx:n:tg:' . $e, 10800)) { try { ct_push($pdo, [$e], '🎯 No target for today', 'Your target for today is not set. Ask the admin to set it now — without a target your commission is not counted in full.', '/my_app.html', 'wqx-tg-' . $e); $sent++; } catch (Throwable $x) { } } }
    if ($miss && function_exists('wq_admin_ids') && $due('wqx:n:tgadm', 10800)) { try { ct_push($pdo, wq_admin_ids($pdo), '🎯 Set targets', 'Working today with no target: ' . implode(', ', array_map(function ($e) use ($names) { return (string)($names[$e] ?? ('#' . $e)); }, $miss)) . '. Set them in Sales → Team performance.', '/sales.html#people/team', 'wqx-tgadm'); $sent++; } catch (Throwable $x) { } }
    return ['sent' => $sent, 'no_target' => count($miss)];
}
/** ⏸ Stopped by the admin / Sales Head (lunch, half day, leave …): [emp => [why, until ts (0 = until started again), by, at]]. Ended stops drop out. */
function wqx_stops(PDO $pdo): array
{
    if (isset($GLOBALS['wqx_stops_memo'])) return $GLOBALS['wqx_stops_memo'];
    $j = json_decode(wqx_meta($pdo, 'wqx:stops', '{}'), true); $o = [];
    if (is_array($j)) foreach ($j as $e => $x) { if (!is_array($x)) continue; $u = (int)($x['until'] ?? 0); if ($u === 0 || $u > wqx_now()) $o[(int)$e] = $x; }
    return $GLOBALS['wqx_stops_memo'] = $o;
}
function wqx_stops_put(PDO $pdo, array $o): void { unset($GLOBALS['wqx_stops_memo']); wqx_meta_put($pdo, 'wqx:stops', json_encode((object)$o, JSON_UNESCAPED_UNICODE)); }
/** Today's start (the business day of the panel when it has one). */
function wqx_day_start(PDO $pdo): int
{
    if (function_exists('wq_day_start') && function_exists('wq_day_of')) { try { return (int)wq_day_start($pdo, wq_day_of($pdo, wqx_now())); } catch (Throwable $e) { } }
    return (int)strtotime(date('Y-m-d 00:00:00', wqx_now()));
}
/** Open breaks: [emp => start ts]; minutes of break used today: [emp => min]. */
function wqx_breaks(PDO $pdo): array
{
    $open = []; $used = []; $d0 = wqx_day_start($pdo); $now = wqx_now();
    try { $q = $pdo->prepare("SELECT emp_id, start_at, end_at FROM wqx_break WHERE start_at >= ? OR end_at IS NULL"); $q->execute([wqx_dt($d0 - 86400)]);
        foreach ($q->fetchAll() as $r) { $e = (int)$r['emp_id']; $s = wqx_ts($r['start_at']); $en = $r['end_at'] ? wqx_ts($r['end_at']) : $now; if ($r['end_at'] === null) $open[$e] = $s; $from = max($s, $d0); if ($en > $from) $used[$e] = ($used[$e] ?? 0) + ($en - $from) / 60; } } catch (Throwable $e) { }
    return ['open' => $open, 'used' => array_map(function ($m) { return (int)round($m); }, $used)];
}
/** Ends breaks that ran past the daily limit (called by the pass). */
function wqx_break_enforce(PDO $pdo, array $cfg): void
{
    $b = wqx_breaks($pdo);
    foreach ($b['open'] as $e => $s) if (($b['used'][$e] ?? 0) >= $cfg['break_max']) { try { $pdo->prepare("UPDATE wqx_break SET end_at = ? WHERE emp_id = ? AND end_at IS NULL")->execute([wqx_dt(), $e]); } catch (Throwable $x) { } }
}

// ------------------------------------------------------------------------------------------------------------------ the learned profile (heavy, background)
/** Builds everything the assigning pass needs, from the last 30 days (60 for the timer). Stored in wq_meta 'wqx:prof'. */
function wqx_profile_build(PDO $pdo): array
{
    $now = wqx_now(); $cfg = wqx_cfg($pdo); $since = $now - 30 * 86400; $attr = 7 * 86400;
    if (function_exists('wq_cfg')) { try { $attr = (int)wq_cfg($pdo)['wq_attr_days'] * 86400; } catch (Throwable $e) { } }
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $sales = wqx_sales($pdo); $isSales = array_flip($sales);

    // cancelled / voided / refunded orders (they never count): phone10 => [[ts, total], …]
    $cx = [];
    try { $q = $pdo->prepare("SELECT phone, total, created_at FROM shop_orders WHERE created_at >= ? AND ((tags IS NOT NULL AND tags LIKE '%cancel%') OR LOWER(financial_status) IN ('voided','refunded'))"); $q->execute([wqx_dt($since - 2 * 86400)]);
        foreach ($q->fetchAll() as $r) { $p = wqx_p10($r['phone']); if ($p !== '') $cx[$p][] = [wqx_ts($r['created_at']), (float)$r['total']]; } } catch (Throwable $e) { }

    // every query of the last 30 days with its holder, type, result
    $rows = [];
    try {
        $q = $pdo->prepare("SELECT q.id, q.phone, q.opened_at, q.emp_id, COALESCE(t.type, '') type, COALESCE(s.orders, 0) orders, COALESCE(s.revenue, 0) revenue, s.first_person_min, s.first_order_at, COALESCE(s.person_n, 0) person_n
            FROM wa_queries q LEFT JOIN wq_qstat s ON s.query_id = q.id LEFT JOIN wq_qtype t ON t.query_id = q.id WHERE q.opened_at >= ? ORDER BY q.opened_at LIMIT 60000");
        $q->execute([wqx_dt($since)]); $rows = $q->fetchAll();
    } catch (Throwable $e) { error_log('wqx_profile rows: ' . $e->getMessage()); }

    // returning customers: an order before the query opened (wq_ord is the copy of shop orders by phone10)
    $firstOrd = []; $phones = [];
    foreach ($rows as $r) { $p = wqx_p10($r['phone']); if ($p !== '') $phones[$p] = 1; }
    if ($phones) { foreach (array_chunk(array_keys($phones), 500) as $ch) { try { $q = $pdo->prepare("SELECT phone, MIN(created_at) f FROM wq_ord WHERE phone IN (" . wqx_in($ch) . ") GROUP BY phone"); $q->execute($ch); foreach ($q->fetchAll() as $r) $firstOrd[(string)$r['phone']] = wqx_ts($r['f']); } catch (Throwable $e) { } } }

    // how eager each customer was: their own messages in the period (per number; most numbers have one query)
    $inN = [];
    $raw = []; foreach ($rows as $r) $raw[(string)$r['phone']] = 1;
    if ($raw) { foreach (array_chunk(array_keys($raw), 400) as $fk) {
        try { $q = $pdo->prepare("SELECT phone, COUNT(*) n FROM wa_messages WHERE dir = 'in' AND ts >= ? AND phone IN (" . wqx_in($fk) . ") GROUP BY phone"); $q->execute(array_merge([wqx_dt($since)], $fk)); foreach ($q->fetchAll() as $r) $inN[(string)$r['phone']] = (int)$r['n']; } catch (Throwable $e) { } } }
    // recent orders (to spot "where is my parcel" chats: a buyer of the last 15 days who writes again and orders nothing is not a sales query)
    $recent = [];
    if ($phones) { foreach (array_chunk(array_keys($phones), 500) as $ch) { try { $q = $pdo->prepare("SELECT phone, created_at FROM wq_ord WHERE created_at >= ? AND phone IN (" . wqx_in($ch) . ")"); $q->execute(array_merge([wqx_dt($since - 15 * 86400)], $ch)); foreach ($q->fetchAll() as $r) $recent[(string)$r['phone']][] = wqx_ts($r['created_at']); } catch (Throwable $e) { } } }
    // per query: clean result + bucket (type|returning) + load at the moment (the holder's other queries opened in the 45 min before)
    $Q = []; $byEmpTimes = []; $skipped = 0; $effort = [];
    foreach ($rows as $r) {
        $p = wqx_p10($r['phone']); $o = wqx_ts($r['opened_at']); $ord = (int)$r['orders']; $rev = (float)$r['revenue'];
        if ($ord === 0 && isset($recent[$p])) { $st0 = false; foreach ($recent[$p] as $t0) if ($t0 < $o && $t0 >= $o - 15 * 86400) { $st0 = true; break; } if ($st0) { $skipped++; continue; } }   // order-status chat: left out of everyone's ratio
        if ($ord > 0 && isset($cx[$p])) foreach ($cx[$p] as $c) if ($c[0] >= $o && $c[0] <= $o + $attr) { $ord = max(0, $ord - 1); $rev = max(0.0, $rev - $c[1]); }
        $type = (string)$r['type']; if ($type === '' || $type === 'unclear') $type = 'other';
        $ret = isset($firstOrd[$p]) && $firstOrd[$p] < $o ? 1 : 0;
        $e = (int)$r['emp_id']; $cin = (int)($inN[(string)$r['phone']] ?? 0); $eager = $cin >= 3 ? 1 : 0;
        // 💪 effort counts: the customer never wrote again after the first message, no order, but the salesperson tried (2+ proper messages, follow-ups)
        //    → a cold customer, not the salesperson's fault: left out of their ratio. No effort on a cold customer stays IN (it is theirs).
        if ($ord === 0 && $cin <= 1 && (int)$r['person_n'] >= 2) { $effort[$e] = ($effort[$e] ?? 0) + 1; continue; }
        $Q[] = ['e' => $e, 'o' => $o, 'ord' => $ord > 0 ? 1 : 0, 'rev' => $rev, 'b' => $type . '|' . $ret . '|' . $eager, 'fp' => $r['first_person_min'] === null ? null : (float)$r['first_person_min'], 'oa' => wqx_ts($r['first_order_at'])];
        if ($e > 0) $byEmpTimes[$e][] = $o;
    }
    // team per bucket
    $B = []; $tN = 0; $tO = 0; $tR = 0.0;
    foreach ($Q as $x) { if ($x['e'] <= 0) continue; $b = $x['b']; $B[$b]['n'] = ($B[$b]['n'] ?? 0) + 1; $B[$b]['r'] = ($B[$b]['r'] ?? 0) + $x['rev']; $B[$b]['o'] = ($B[$b]['o'] ?? 0) + $x['ord']; $tN++; $tO += $x['ord']; $tR += $x['rev']; }
    $tRPQ = $tN > 0 ? $tR / $tN : 0.0;
    $rpq = function ($b) use ($B, $tRPQ) { if (!isset($B[$b]) || $B[$b]['n'] < 15) return $tRPQ; return ($B[$b]['r'] + 15 * $tRPQ) / ($B[$b]['n'] + 15); };   // small buckets lean on the team average
    $teamRatio = $tN > 0 ? $tO / $tN : 0.0; $teamTicket = $tO > 0 ? $tR / $tO : 0.0;

    // per person
    $P = []; $d0 = wqx_day_start($pdo); $d7 = $now - 7 * 86400; $K = 8.0;
    foreach ($Q as $x) {
        $e = $x['e']; if ($e <= 0 || !isset($isSales[$e])) continue; $exp = $rpq($x['b']);
        if (!isset($P[$e])) $P[$e] = ['n' => 0, 'o' => 0, 'rev' => 0.0, 'exp' => 0.0, 'n7' => 0, 'rev7' => 0.0, 'exp7' => 0.0, 'nT' => 0, 'oT' => 0, 'revT' => 0.0, 'expT' => 0.0, 'loads' => []];
        $p = &$P[$e]; $p['n']++; $p['o'] += $x['ord']; $p['rev'] += $x['rev']; $p['exp'] += $exp;
        if ($x['o'] >= $d7) { $p['n7']++; $p['rev7'] += $x['rev']; $p['exp7'] += $exp; }
        if ($x['o'] >= $d0) { $p['nT']++; $p['revT'] += $x['rev']; $p['expT'] += $exp; }
        if ($x['oa'] >= $d0) $p['oT']++;
        // load at the moment this query came: their other queries opened in the 45 min before
        $ld = wqx_lower($byEmpTimes[$e], $x['o']) - wqx_lower($byEmpTimes[$e], $x['o'] - 2700);   // sorted times: binary search, not a loop over everything
        $p['loads'][] = [$ld, $x['ord']];
        unset($p);
    }
    $out = []; $bandHi = [];
    foreach ($sales as $e) {
        $p = $P[$e] ?? ['n' => 0, 'o' => 0, 'rev' => 0.0, 'exp' => 0.0, 'n7' => 0, 'rev7' => 0.0, 'exp7' => 0.0, 'nT' => 0, 'oT' => 0, 'revT' => 0.0, 'expT' => 0.0, 'loads' => []];
        $base = $K * max(1.0, $tRPQ);
        $idx = ($p['rev'] + $base) / ($p['exp'] + $base);                    // 1.0 = team average for the same kind of queries
        $idx7 = $p['n7'] >= 5 ? ($p['rev7'] + $base) / ($p['exp7'] + $base) : null;
        $idxT = $p['nT'] >= 3 ? max(0.8, min(1.2, ($p['revT'] + $base / 2) / ($p['expT'] + $base / 2))) : 1.0;
        $ticket = $p['o'] > 0 ? $p['rev'] / $p['o'] : null;
        // capacity: load buckets 0-2 / 3-5 / 6-8 / 9-12 / 13-20 / 21+; the highest bucket (from the bottom) whose order rate stays ≥ 80 % of the best one with data
        $bk = [[0, 2], [3, 5], [6, 8], [9, 12], [13, 20], [21, 40]]; $st = [];
        foreach ($p['loads'] as $l) foreach ($bk as $i => $b) if ($l[0] >= $b[0] && $l[0] <= $b[1]) { $st[$i]['n'] = ($st[$i]['n'] ?? 0) + 1; $st[$i]['o'] = ($st[$i]['o'] ?? 0) + $l[1]; }
        $best = 0.0; foreach ($st as $i => $s) if ($s['n'] >= 8) $best = max($best, $s['o'] / $s['n']);
        $cap = $cfg['start_cap']; $capWhy = 'start value (not enough data yet)'; $lastOk = -1;
        if ($best > 0) { foreach ($bk as $i => $b) { $s = $st[$i] ?? null; if (!$s || $s['n'] < 8) break; if ($s['o'] / $s['n'] >= 0.8 * $best) $lastOk = $i; else break; } }
        if ($lastOk >= 0) { $cap = $bk[$lastOk][1] + 1; $nxt = $st[$lastOk + 1] ?? null; if (!$nxt || $nxt['n'] < 8) { $cap += 2; $capWhy = 'learned: holds ' . ($bk[$lastOk][1] + 1) . '+ at once with no drop, room to grow'; } else $capWhy = 'learned: order rate drops above ' . ($bk[$lastOk][1] + 1) . ' at once'; }
        $cap = max(3, min(40, (int)$cap));
        $hi = wqx_hi($p['o'], $p['n']);
        $out[$e] = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'n' => $p['n'], 'orders' => $p['o'], 'rev' => round($p['rev']), 'idx' => round($idx, 3), 'idx7' => $idx7 === null ? null : round($idx7, 3), 'idx_today' => round($idxT, 3),
            'ratio' => $p['n'] > 0 ? round(100 * $p['o'] / $p['n'], 1) : null, 'ticket' => $ticket === null ? null : (int)round($ticket), 'n_today' => $p['nT'], 'orders_today' => $p['oT'],
            'cold_tried' => (int)($effort[$e] ?? 0), 'cap' => $cap, 'cap_why' => $capWhy, 'trial' => $p['n'] < $cfg['trial'], 'rising' => $idx7 !== null && $idx7 >= 1.15 * $idx && $idx7 >= 0.9, 'sure_behind' => $p['n'] >= $cfg['trial'] && $tN >= 60 && $hi < $teamRatio];
    }
    // the move-back timer: how the order rate falls with the first person's reply time (60 days, team)
    $tb = [[0, 1], [1, 2], [2, 3], [3, 5], [5, 10], [10, 99999]]; $ts = [];
    try { $q = $pdo->prepare("SELECT first_person_min m, orders FROM wq_qstat WHERE opened_at >= ? AND first_person_min IS NOT NULL LIMIT 80000"); $q->execute([wqx_dt($now - 60 * 86400)]);
        foreach ($q->fetchAll() as $r) { $m = (float)$r['m']; foreach ($tb as $i => $b) if ($m >= $b[0] && $m < $b[1]) { $ts[$i]['n'] = ($ts[$i]['n'] ?? 0) + 1; $ts[$i]['o'] = ($ts[$i]['o'] ?? 0) + ((int)$r['orders'] > 0 ? 1 : 0); break; } } } catch (Throwable $e) { }
    $timer = $cfg['start_timer']; $timerWhy = 'start value (not enough data yet)'; $curve = [];
    foreach ($tb as $i => $b) $curve[] = ['from' => $b[0], 'to' => $b[1] >= 99999 ? null : $b[1], 'n' => (int)($ts[$i]['n'] ?? 0), 'pct' => !empty($ts[$i]['n']) ? round(100 * $ts[$i]['o'] / $ts[$i]['n'], 1) : null];
    if (!empty($ts[0]['n']) && $ts[0]['n'] >= 30) {
        $r0 = $ts[0]['o'] / $ts[0]['n']; $hit = null;
        foreach ($tb as $i => $b) { if ($i === 0) continue; $s = $ts[$i] ?? null; if (!$s || $s['n'] < 30) break; if ($s['o'] / $s['n'] < 0.8 * $r0) { $hit = $b[0]; break; } }
        if ($hit !== null) { $timer = (int)round($hit * 60); $timerWhy = 'learned: replies after ' . $hit . ' min win clearly fewer orders'; }
        else { $timer = 300; $timerWhy = 'learned: waiting up to 5 min barely changes orders'; }
    }
    $timer = max(60, min(600, $timer));
    // VIP line: a returning customer whose last-year spend is at least 2 × the team ticket
    $vipLine = $teamTicket > 0 ? (int)round(2 * $teamTicket) : 0;
    // reply speed today (the pace module's own numbers, kept by it)
    $tSpeed = null; if (function_exists('wq_pace_get')) { try { $pg = wq_pace_get($pdo, 1); $tSpeed = $pg['team']['pace']['median'] ?? null; foreach ((array)($pg['people'] ?? []) as $pp) { $i = (int)($pp['emp_id'] ?? 0); if (isset($out[$i]) && (int)($pp['pace']['n'] ?? 0) >= 3) $out[$i]['speed_today'] = $pp['pace']['median']; } } catch (Throwable $e) { } }
    $prof = ['at' => $now, 'people' => $out, 'team_speed' => $tSpeed, 'team' => ['n' => $tN, 'orders' => $tO, 'ratio' => $tN > 0 ? round(100 * $teamRatio, 1) : null, 'ticket' => (int)round($teamTicket), 'rpq' => round($tRPQ, 1), 'stable' => $tN >= 60, 'status_chats_left_out' => $skipped],
        'timer' => $timer, 'timer_why' => $timerWhy, 'curve' => $curve, 'vip_line' => $vipLine];
    wqx_meta_put($pdo, 'wqx:prof', json_encode($prof, JSON_UNESCAPED_UNICODE));
    return $prof;
}
/** The stored profile; rebuilt in the background when older than 5 minutes (one copy at a time). $build = false never builds (webhook / screens). */
function wqx_profile(PDO $pdo, bool $build = true, bool $force = false): array
{
    $j = json_decode(wqx_meta($pdo, 'wqx:prof', ''), true); $fresh = is_array($j) && (int)($j['at'] ?? 0) > wqx_now() - 300;
    if (($fresh && !$force) || !$build) return is_array($j) ? $j : ['people' => [], 'team' => [], 'timer' => wqx_cfg($pdo)['start_timer']];
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('wqx_prof', 0)")->fetchColumn(); } catch (Throwable $e) { }
    if ($got !== 1) return is_array($j) ? $j : ['people' => [], 'team' => [], 'timer' => wqx_cfg($pdo)['start_timer']];
    try { return wqx_profile_build($pdo); } catch (Throwable $e) { error_log('wqx_profile: ' . $e->getMessage()); return is_array($j) ? $j : ['people' => [], 'team' => [], 'timer' => wqx_cfg($pdo)['start_timer']]; }
    finally { try { $pdo->query("SELECT RELEASE_LOCK('wqx_prof')"); } catch (Throwable $e) { } }
}

// ------------------------------------------------------------------------------------------------------------------ live state (cheap, every pass)
/** Who can take a query now: present, in sales, not on a break. With live load, pipeline, last answer and hold. */
function wqx_live(PDO $pdo, array $prof): array
{
    $now = wqx_now(); $present = wqx_present($pdo); $br = wqx_breaks($pdo); $L = [];
    $load = []; try { $q = $pdo->prepare("SELECT assigned_emp e, COUNT(*) n FROM wa_contacts WHERE status = 'open' AND assigned_emp > 0 AND (cx_id IS NULL OR cx_id = 0) AND last_in_at >= ? GROUP BY assigned_emp"); $q->execute([wqx_dt($now - 2700)]); foreach ($q->fetchAll() as $r) $load[(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }
    $pipe = []; try { $q = $pdo->prepare("SELECT assigned_emp e, COUNT(*) n FROM wa_contacts WHERE status = 'open' AND assigned_emp > 0 AND (hot = 1 OR (pay_at IS NOT NULL AND pay_at >= ?)) GROUP BY assigned_emp"); $q->execute([wqx_dt($now - 86400)]); foreach ($q->fetchAll() as $r) $pipe[(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }
    $ans = []; try { foreach ($pdo->query("SELECT emp_id, MAX(ts) t FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND ts >= '" . wqx_dt($now - 1800) . "' GROUP BY emp_id")->fetchAll() as $r) $ans[(int)$r['emp_id']] = (int)round(($now - wqx_ts($r['t'])) / 60); } catch (Throwable $e) { }
    $cx = []; try { foreach ($pdo->query("SELECT owner_emp, COUNT(*) n FROM cx_cases WHERE status IN ('open','reopened') AND owner_emp > 0 AND ((due_at IS NOT NULL AND due_at < NOW()) OR (first_reply_at IS NULL AND reply_due IS NOT NULL AND reply_due < NOW())) GROUP BY owner_emp")->fetchAll() as $r) $cx[(int)$r['owner_emp']] = (int)$r['n']; } catch (Throwable $e) { }   // overdue complaints: solve them first
    $fu = []; try { $q = $pdo->prepare("SELECT c.assigned_emp e, COUNT(*) n FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.assigned_emp > 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag NOT IN ('complaint','order_status'))
            AND c.last_out_at IS NOT NULL AND c.last_out_at > c.last_in_at AND c.last_out_at <= ? AND c.last_in_at >= ? GROUP BY c.assigned_emp"); $q->execute([wqx_dt($now - 300), wqx_dt($now - 20 * 3600)]); foreach ($q->fetchAll() as $r) $fu[(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }   // 📣 we answered, the customer went quiet 5 min+ (inside the window): follow up
    // ⏸ the gate (owner, 12 Nov): a person with a customer waiting 3+ min for their first proper answer, or 2 customers waiting at all, gets NO new chat until they reply
    $gateSec = max(60, min(900, (int)setting_get($pdo, 'wqx_gate_sec', '180'))); $un = []; $hs = function_exists('wq_human_sql') ? wq_human_sql('h', 'c', 'last_in_at') : "h.dir = 'out' AND h.emp_id > 0 AND h.ts >= c.last_in_at";
    try { $q = $pdo->prepare("SELECT c.assigned_emp e, COUNT(*) n, MIN(c.last_in_at) o FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.assigned_emp > 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag <> 'complaint')
            AND c.last_in_at >= ? AND NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND $hs) GROUP BY c.assigned_emp"); $q->execute([wqx_dt($now - 2 * 3600)]);
        foreach ($q->fetchAll() as $r) $un[(int)$r['e']] = [(int)$r['n'], $now - wqx_ts($r['o'])]; } catch (Throwable $e) { }
    $stops = wqx_stops($pdo);
    $lost = []; try { $q = $pdo->prepare("SELECT from_emp e, COUNT(*) n FROM wqx_log WHERE kind = 'move' AND applied = 1 AND from_emp > 0 AND at >= ? GROUP BY from_emp"); $q->execute([wqx_dt(wqx_day_start($pdo))]); foreach ($q->fetchAll() as $r) $lost[(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }   // chats taken away today for not answering
    foreach ((array)($prof['people'] ?? []) as $e => $p) {
        $e = (int)$e; $on = !empty($present[$e]); $brk = isset($br['open'][$e]); $stp = $stops[$e] ?? null;
        $held = !empty($p['sure_behind']) && (int)($p['orders_today'] ?? 0) === 0 && (int)($pipe[$e] ?? 0) === 0;
        $act = isset($ans[$e]) ? ($ans[$e] <= 3 ? 1.6 : ($ans[$e] <= 10 ? 1.0 : 0.6)) : 0.35;   // replying to customers in the last 3 min = first in line; quiet = last
        $uw = $un[$e] ?? [0, 0]; $gate = $uw[0] >= 2 || ($uw[0] >= 1 && $uw[1] >= $gateSec);
        $ld = (int)($load[$e] ?? 0); $cap = max(1, (int)($p['cap'] ?? 6));
        $score = (float)($p['idx'] ?? 1) * (float)($p['idx_today'] ?? 1) * $act * (1 - 0.5 * min(1.0, $ld / $cap)) * max(0.6, pow(0.9, (int)($lost[$e] ?? 0)));   // every chat lost today for not answering costs 10 % of the place in the queue
        $L[$e] = ['emp_id' => $e, 'name' => (string)($p['name'] ?? ''), 'present' => $on, 'break' => $brk, 'break_used' => (int)($br['used'][$e] ?? 0), 'load' => $ld, 'cap' => $cap, 'full' => $ld >= $cap, 'pipe' => (int)($pipe[$e] ?? 0),
            'answered_min_ago' => $ans[$e] ?? null, 'waiting' => $uw[0], 'waiting_sec' => $uw[1], 'gate' => $gate, 'held' => $held || !empty($cx[$e]), 'held_why' => !empty($cx[$e]) ? 'complaints' : ($held ? 'orders' : ''), 'overdue_cx' => (int)($cx[$e] ?? 0), 'lost_today' => (int)($lost[$e] ?? 0), 'followups' => (int)($fu[$e] ?? 0), 'score' => round($score, 3), 'stopped' => $stp ? ['why' => (string)($stp['why'] ?? ''), 'until' => (int)($stp['until'] ?? 0)] : null, 'can' => $on && !$brk && !$stp];
    }
    return $L;
}

// ------------------------------------------------------------------------------------------------------------------ choosing a person
function wqx_vip(PDO $pdo, string $phone, int $line): bool
{
    if ($line <= 0) return false; $p = wqx_p10($phone); if ($p === '') return false;
    try { $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) s FROM wq_ord WHERE phone = ? AND created_at >= ?"); $q->execute([$p, wqx_dt(wqx_now() - 365 * 86400)]); $r = $q->fetch(); return $r && ((float)$r['s'] >= $line || (int)$r['n'] >= 3); } catch (Throwable $e) { return false; }
}
/** The salesperson whose query last turned into an order for this customer (wa_queries.emp_id = the closer). */
function wqx_last_seller(PDO $pdo, string $phone): int
{
    try { $q = $pdo->prepare("SELECT q.emp_id FROM wa_queries q JOIN wq_qstat s ON s.query_id = q.id WHERE q.phone = ? AND s.orders > 0 AND q.emp_id > 0 ORDER BY q.id DESC LIMIT 1"); $q->execute([$phone]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
}
/** The red / orange of the Needs-you-now list (the bot's reading of the customer's words — the SAME colours the screens show). [phone => red|orange] */
function wqx_need_levels(PDO $pdo): array
{
    static $m = null; if ($m !== null) return $m; $m = [];
    if (function_exists('wq_needs')) { try { foreach (wq_needs($pdo, 0, false) as $x) $m[(string)$x['phone']] = (string)$x['level']; } catch (Throwable $e) { } }
    return $m;
}
/** Heat of a chat: red (ready to pay) | orange (interested) | '' */
function wqx_heat(PDO $pdo, array $c): string
{
    $lv = wqx_need_levels($pdo)[(string)$c['phone']] ?? ''; if ($lv === 'red') return 'red';
    if ((int)($c['hot'] ?? 0) === 1 || (string)($c['tag'] ?? '') === 'buy') return 'red';
    if ($lv === 'orange') return 'orange';
    if ((int)($c['hot'] ?? 0) === 1 || (string)($c['tag'] ?? '') === 'buy') return 'red';
    if ((string)($c['tag'] ?? '') === 'price') return 'orange';
    try { $q = $pdo->prepare("SELECT tag FROM wa_ctags WHERE phone = ? AND tag IN ('hot','orange') LIMIT 2"); $q->execute([(string)$c['phone']]); $t = $q->fetchAll(PDO::FETCH_COLUMN); if (in_array('hot', $t, true)) return 'red'; if ($t) return 'orange'; } catch (Throwable $e) { }
    return '';
}
/** Picks the person for one chat. Returns [emp, kind, why] or [0, '', why]. $L = live state (load is updated by the caller after each pick). */
function wqx_pick(PDO $pdo, array $c, array $L, array $prof, array $cfg, int $except, string $heat): array
{
    $el = array_filter($L, function ($p) use ($except) { return $p['can'] && (int)$p['emp_id'] !== $except; });
    if (!$el) return [0, '', 'nobody present (or everyone on a break)'];
    $P = (array)($prof['people'] ?? []); $phone = (string)$c['phone'];
    $open = array_filter($el, function ($p) { return !$p['held'] && !$p['full'] && empty($p['gate']); });
    $byScore = function (array $a) { uasort($a, function ($x, $y) { return [$y['score'], $x['load']] <=> [$x['score'], $y['load']]; }); return $a; };
    // the owner's own wishes, told to Claude (a kind of customer → a person, a person who must not get chats …)
    foreach (wqx_hints_for($pdo, $c) as $h) {
        if ((int)$h['avoid'] > 0) { unset($open[(int)$h['avoid']]); unset($el[(int)$h['avoid']]); }
        if ((int)$h['prefer'] > 0 && isset($open[(int)$h['prefer']])) return [(int)$h['prefer'], 'claude', 'owner\'s rule (' . $h['why'] . ') → ' . $open[(int)$h['prefer']]['name']];
    }
    if (!$el) return [0, '', 'nobody allowed by the owner\'s rules is at work'];
    if (!$open) {   // nobody has room or everyone is held back: never leave the query — the best present person, held ones last. A STOPPED person (customer waiting for their reply) never gets one:
        $el = array_filter($el, function ($p) { return empty($p['gate']); });   // then the chat waits with the bot (🔴 still pops up for everyone) and goes the moment someone replies
        if (!$el) return [0, '', '⏸ everyone at work has a customer waiting for their reply — this chat waits until someone answers'];
        $all = $el; uasort($all, function ($x, $y) { return [(int)$x['held'], (int)!empty($x['gate']), $x['load'] / max(1, $x['cap']), $y['score']] <=> [(int)$y['held'], (int)!empty($y['gate']), $y['load'] / max(1, $y['cap']), $x['score']]; }); $f = reset($all);   // spread the extra load: the least loaded (for their own limit) first
        return [(int)$f['emp_id'], 'overflow', 'everyone is full or held — ' . $f['name'] . ' has the most room left; admins alerted'];
    }
    // a returning buyer goes back to the person who sold them before
    $ls = wqx_last_seller($pdo, $phone); if ($ls > 0 && isset($open[$ls])) return [$ls, 'returning', 'returning customer → ' . $open[$ls]['name'] . ' sold to them before'];
    // VIP → best ticket size present (then score)
    if (wqx_vip($pdo, $phone, (int)($prof['vip_line'] ?? 0))) {
        $v = $open; uasort($v, function ($x, $y) use ($P) { return [(int)($P[$y['emp_id']]['ticket'] ?? 0), $y['score']] <=> [(int)($P[$x['emp_id']]['ticket'] ?? 0), $x['score']]; }); $f = reset($v);
        return [(int)$f['emp_id'], 'vip', 'VIP customer → best ticket size present (' . $f['name'] . ', ₹' . (int)($P[$f['emp_id']]['ticket'] ?? 0) . ')'];
    }
    // rising stars / trial: a share of plain (not red) queries, picked the same way every time for this chat
    if ($heat !== 'red' && $cfg['explore'] > 0 && (crc32($phone . '|' . substr((string)($c['last_in_at'] ?? ''), 0, 13)) % 100) < $cfg['explore']) {
        $r = array_filter($open, function ($p) use ($P) { $x = $P[$p['emp_id']] ?? []; return !empty($x['rising']) || !empty($x['trial']); });
        if ($r) { $r = $byScore($r); $f = reset($r); $x = $P[$f['emp_id']] ?? []; return [(int)$f['emp_id'], 'chance', (!empty($x['trial']) ? 'new person on trial' : 'rising star (last 7 days beat their month)') . ' → ' . $f['name']]; }
    }
    $open = $byScore($open); $f = reset($open); $x = $P[$f['emp_id']] ?? [];
    return [(int)$f['emp_id'], 'best', 'best score now: ' . $f['name'] . ' (sells ' . round(100 * (float)($x['idx'] ?? 1)) . '% of team for such queries, ' . $f['load'] . '/' . $f['cap'] . ' live)'];
}
/** Seconds a chat may wait for a proper answer before it goes back to the market. */
function wqx_timer(array $prof, string $heat, ?array $holder = null, int $freeOthers = 1): int
{
    $t = (int)($prof['timer'] ?? 120);
    if ($heat === 'red') $t = (int)round($t * 0.5); elseif ($heat === 'orange') $t = (int)round($t * 0.75);
    if ($holder && $holder['present'] && !$holder['break'] && $holder['answered_min_ago'] !== null && $holder['answered_min_ago'] <= 5) $t = (int)round($t * 1.2);   // busy answering others right now
    if ($freeOthers <= 0) $t *= 2;   // nobody else has room: moving it does not help
    if ($holder && (!$holder['present'] || $holder['break'])) $t = min($t, 120);   // the holder is gone: do not wait long
    return max(30, min(600, $t));
}
function wqx_logged(PDO $pdo, string $phone, string $inAt, string $kind): bool
{
    try { $q = $pdo->prepare("SELECT 1 FROM wqx_log WHERE phone = ? AND in_at <=> ? AND kind = ? AND at >= ? LIMIT 1"); $q->execute([$phone, $inAt === '' ? null : $inAt, $kind, wqx_dt(wqx_now() - 86400)]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
function wqx_log(PDO $pdo, string $phone, string $inAt, string $kind, int $from, int $to, string $why, bool $applied, string $mode): void
{
    try { $pdo->prepare("INSERT INTO wqx_log (phone, in_at, kind, from_emp, to_emp, why, applied, mode, at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$phone, $inAt === '' ? null : $inAt, mb_substr($kind, 0, 12), $from, $to, mb_substr($why, 0, 255), $applied ? 1 : 0, $mode, wqx_dt()]); } catch (Throwable $e) { }
    if (mt_rand(1, 400) === 1) { try { $pdo->exec("DELETE FROM wqx_log WHERE at < '" . wqx_dt(wqx_now() - 60 * 86400) . "'"); } catch (Throwable $e) { } }
}

// ------------------------------------------------------------------------------------------------------------------ one line of coaching (WhatsApp and Team performance say the SAME thing)
/** The ONE most important thing for this person right now, short, in English. [text, tone: red|amber|green|grey] */
function wqx_coach(?array $l = null, ?array $p = null, array $prof = [], bool $onBreak = false, int $brUsed = 0, int $brMax = 60): array
{
    if (!$l) return ['', 'grey'];
    $tm = (array)($prof['team'] ?? []); $tTk = (int)($tm['ticket'] ?? 0); $tSp = $prof['team_speed'] ?? null;
    if (!empty($l['stopped'])) { $u = (int)$l['stopped']['until']; return ['⏸ Queries stopped by admin — ' . ($l['stopped']['why'] !== '' ? $l['stopped']['why'] : 'paused') . ($u > 0 ? ' · till ' . date('g:i a', $u) : ''), 'grey']; }
    if ($onBreak) return ['☕ On a break — no new queries (' . $brUsed . '/' . $brMax . ' min)', 'grey'];
    if (empty($l['present'])) return ['⏰ Punch in (office or work from home) — no punch-in, no queries, no commission', 'red'];
    if ((string)($l['held_why'] ?? '') === 'complaints') return ['🔴 Solve your ' . (int)$l['overdue_cx'] . ' overdue complaint(s) first — then queries come', 'red'];
    if ((string)($l['held_why'] ?? '') === 'orders') return ['⛔ Queries paused — your ratio is below the team. Close 1 order to restart', 'red'];
    if (!empty($l['gate'])) return ['⏸ ' . (int)$l['waiting'] . ' customer(s) waiting for your reply — no new queries until you answer', 'red'];
    if ((int)($l['lost_today'] ?? 0) > 0) return ['⚠ ' . (int)$l['lost_today'] . ' chat(s) lost today for no reply — their order & commission went to others. Stay on your chats', 'red'];
    if ((int)($l['followups'] ?? 0) >= 3) return ['📣 ' . (int)$l['followups'] . ' customers went quiet — follow up now (photo, offer, question)', 'amber'];
    if (!empty($l['full'])) return ['⏳ ' . (int)$l['load'] . ' live chats — answer them first, then new queries come', 'amber'];
    $sp = $p['speed_today'] ?? null; if ($sp !== null && $tSp !== null && (float)$sp > max(3.0, 1.5 * (float)$tSp)) return ['⚡ Reply faster — you ' . round((float)$sp, 1) . ' min, team ' . round((float)$tSp, 1) . ' min', 'amber'];
    $tk = $p['ticket'] ?? null; if ($tk !== null && $tTk > 0 && (int)($p['orders'] ?? 0) >= 3 && (float)$tk < 0.85 * $tTk) return ['🛍 Offer sets / combos / bigger size — your ticket ₹' . (int)$tk . ', team ₹' . $tTk . '. No heavy discounts', 'amber'];
    if ((int)($l['followups'] ?? 0) > 0) return ['📣 ' . (int)$l['followups'] . ' customer(s) went quiet — follow up now', 'amber'];
    if ($l['answered_min_ago'] === null && (int)($l['load'] ?? 0) === 0 && (float)($p['idx'] ?? 1) >= 1.0) return ['🙋 You sell well — open new queries the moment they come', 'green'];
    if (!empty($p['trial'])) return ['🌱 New here — reply fast, offer sets; orders bring you more queries', 'green'];
    if ((float)($p['idx'] ?? 1) >= 1.15) return ['🚀 Top seller — more queries come to you. Keep the ticket big', 'green'];
    return ['✅ Queries on — fast replies + big tickets = more queries', 'green'];
}

// ------------------------------------------------------------------------------------------------------------------ "tell Claude" — the only way to give a chat by hand
/** Standing rules Claude made from the admin's words: [{id, kind: all|phone|words, value, prefer, avoid, why, until, by, at}] */
function wqx_hints(PDO $pdo): array
{
    $j = json_decode(wqx_meta($pdo, 'wqx:hints', '[]'), true); if (!is_array($j)) return [];
    return array_values(array_filter($j, function ($h) { return is_array($h) && wqx_ts($h['until'] ?? '') > wqx_now(); }));
}
function wqx_hints_put(PDO $pdo, array $h): void { wqx_meta_put($pdo, 'wqx:hints', json_encode(array_slice(array_values($h), -60), JSON_UNESCAPED_UNICODE)); }
/** The rules that fit this chat (the customer's number, or words the customer wrote in the last day). */
function wqx_hints_for(PDO $pdo, array $c): array
{
    static $memo = null; if ($memo === null) $memo = wqx_hints($pdo); if (!$memo) return [];
    $p10 = wqx_p10($c['phone'] ?? ''); $txt = null; $o = [];
    foreach ($memo as $h) {
        if ($h['kind'] === 'all') { $o[] = $h; continue; }
        if ($h['kind'] === 'phone') { if ($p10 !== '' && wqx_p10($h['value']) === $p10) $o[] = $h; continue; }
        if ($h['kind'] !== 'words') continue;
        if ($txt === null) { $txt = ''; try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ? ORDER BY id DESC LIMIT 8"); $q->execute([(string)$c['phone'], wqx_dt(wqx_now() - 86400)]); $txt = mb_strtolower(implode(' ', $q->fetchAll(PDO::FETCH_COLUMN))); } catch (Throwable $e) { } }
        foreach (preg_split('/\s*,\s*/u', mb_strtolower((string)$h['value'])) as $w) if ($w !== '' && mb_strpos($txt, $w) !== false) { $o[] = $h; break; }
    }
    return $o;
}
/** The admin writes what they want in plain words ("give 98xxxx to Riya, he is a dealer", "Marathi customers to Sunil", "Karan is in training today, no
 *  queries to him"). Claude understands the CONCERN behind it and turns it into (a) an assignment now, if one chat is meant, and (b) a standing rule that
 *  keeps that concern for future chats. Returns ['reply', 'assigned' => [phone, emp], 'rule' => …]. */
function wqx_ask(PDO $pdo, string $text, int $by): array
{
    if (!function_exists('convo_ai')) return ['error' => 'Claude is not available on this server.'];
    $prof = wqx_profile($pdo, false); $L = wqx_live($pdo, $prof); $ppl = [];
    foreach ((array)($prof['people'] ?? []) as $e => $p) { $l = $L[(int)$e] ?? []; $ppl[] = ['emp_id' => (int)$e, 'name' => (string)$p['name'], 'at_work' => !empty($l['present']), 'on_break' => !empty($l['break']), 'live_chats' => (int)($l['load'] ?? 0), 'order_ratio_pct' => $p['ratio'], 'ticket_rs' => $p['ticket']]; }
    $chat = ''; $ph = ''; if (preg_match('/(\d[\d\s\-]{8,}\d)/', $text, $m)) $ph = wqx_p10($m[1]);
    if ($ph !== '') { try { $q = $pdo->prepare("SELECT dir, body FROM wa_messages WHERE phone = ? ORDER BY id DESC LIMIT 8"); $q->execute([$ph]); foreach (array_reverse($q->fetchAll()) as $r) $chat .= ($r['dir'] === 'in' ? 'CUSTOMER: ' : 'SHOP: ') . mb_substr(preg_replace('/\d{6,}/', '#', (string)$r['body']), 0, 200) . "\n"; } catch (Throwable $e) { } }
    $sys = "You run the automatic query assignment of TM Perfume House's WhatsApp sales team (perfume shop, India; people write in Gujarati, Hindi, English or a mix). Chats are never given by hand: when the owner wants something, he tells you, and you must understand the CONCERN behind his words, not only the one chat. "
        . "Return: assign_emp_id = the salesperson to give ONE specific chat to right now (only if he clearly means one chat and names or describes a person; else 0); phone = that chat's 10-digit number or ''. "
        . "rule = a standing rule that keeps his concern for future chats when his words imply one (a kind of customer, a language, a product, a person who must not get chats today …): kind 'all' (every chat — e.g. a person who must get no chats today, value '*'), 'phone' (this customer always) or 'words' (comma-separated words the CUSTOMER would write, in the scripts they would use, e.g. 'dealer,wholesale,bulk' or 'marathi,मराठी'), prefer_emp_id (or 0), avoid_emp_id (or 0), days (1-30; 1 for 'today'), why (short English). rule.kind = 'none' when no lasting concern. "
        . "Use only emp_id values from PEOPLE. reply = 1-2 short lines in simple English telling him exactly what you did and what will happen next time.";
    $schema = ['type' => 'OBJECT', 'properties' => ['assign_emp_id' => ['type' => 'INTEGER'], 'phone' => ['type' => 'STRING'], 'reply' => ['type' => 'STRING'],
        'rule' => ['type' => 'OBJECT', 'properties' => ['kind' => ['type' => 'STRING', 'enum' => ['none', 'all', 'phone', 'words']], 'value' => ['type' => 'STRING'], 'prefer_emp_id' => ['type' => 'INTEGER'], 'avoid_emp_id' => ['type' => 'INTEGER'], 'days' => ['type' => 'INTEGER'], 'why' => ['type' => 'STRING']], 'required' => ['kind', 'value', 'prefer_emp_id', 'avoid_emp_id', 'days', 'why']]],
        'required' => ['assign_emp_id', 'phone', 'reply', 'rule']];
    $txt = "OWNER SAYS: " . mb_substr($text, 0, 800) . "\nPEOPLE: " . json_encode($ppl, JSON_UNESCAPED_UNICODE) . ($chat !== '' ? "\nTHE CHAT (+91 " . $ph . "):\n" . $chat : '');
    try { [$ok, $v, $why] = convo_ai($sys, [['text' => $txt]], $schema, false, 1200, 'claude'); } catch (Throwable $e) { return ['error' => 'Claude could not answer: ' . $e->getMessage()]; }
    if (!$ok || !is_array($v)) return ['error' => 'Claude could not answer' . (!empty($why) ? ': ' . $why : '.')];
    $ids = []; foreach ($ppl as $x) $ids[$x['emp_id']] = $x['name']; $out = ['reply' => trim((string)($v['reply'] ?? ''))];
    $r = (array)($v['rule'] ?? []); $k = (string)($r['kind'] ?? 'none');
    if ($k === 'all') $r['value'] = '*';
    if (in_array($k, ['all', 'phone', 'words'], true) && trim((string)($r['value'] ?? '')) !== '' && (isset($ids[(int)($r['prefer_emp_id'] ?? 0)]) || isset($ids[(int)($r['avoid_emp_id'] ?? 0)]))) {
        $h = wqx_hints($pdo); $rule = ['id' => substr(md5(microtime() . mt_rand()), 0, 8), 'kind' => $k, 'value' => mb_substr(trim((string)$r['value']), 0, 200), 'prefer' => isset($ids[(int)$r['prefer_emp_id']]) ? (int)$r['prefer_emp_id'] : 0, 'avoid' => isset($ids[(int)$r['avoid_emp_id']]) ? (int)$r['avoid_emp_id'] : 0,
            'why' => mb_substr(trim((string)($r['why'] ?? '')), 0, 160), 'said' => mb_substr($text, 0, 200), 'until' => wqx_dt(wqx_now() + max(1, min(30, (int)($r['days'] ?? 1))) * 86400), 'by' => $by, 'at' => wqx_dt()];
        $h[] = $rule; wqx_hints_put($pdo, $h); $out['rule'] = $rule;
    }
    $to = (int)($v['assign_emp_id'] ?? 0); $p2 = wqx_p10((string)($v['phone'] ?? '')) ?: $ph;
    if ($to > 0 && isset($ids[$to]) && $p2 !== '') {
        $c = null; try { $q = $pdo->prepare("SELECT phone, name, last_in_at, assigned_emp FROM wa_contacts WHERE phone = ?"); $q->execute([$p2]); $c = $q->fetch() ?: null; } catch (Throwable $e) { }
        if ($c) { wqx_log($pdo, $p2, (string)($c['last_in_at'] ?? ''), 'claude', (int)$c['assigned_emp'], $to, 'told to Claude: ' . mb_substr($text, 0, 200), true, wqx_mode($pdo)); if (function_exists('wa_assign_to')) wa_assign_to($pdo, $p2, $to, $by, 'manual'); $out['assigned'] = ['phone' => $p2, 'to' => $ids[$to]]; }
        else $out['reply'] .= ' (No chat found for +91 ' . $p2 . '.)';
    }
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ the pass
/** One assigning pass: new queries nobody holds, and chats whose holder did not answer in time. Light: reads the stored profile only. */
function wqx_run(PDO $pdo, bool $allowBuild = true): array
{
    $mode = wqx_mode($pdo); if ($mode === 'off') return ['off' => true];
    if (!wqx_tables($pdo)) return ['error' => 'storage'];
    $now = wqx_now(); $last = (int)wqx_meta($pdo, 'wqx:run_at', '0'); if ($last > $now - 20) return ['skipped' => 'recent'];
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('wqx_run', 0)")->fetchColumn(); } catch (Throwable $e) { } if ($got !== 1) return ['skipped' => 'busy'];
    $res = ['new' => 0, 'moved' => 0, 'mode' => $mode];
    try {
        wqx_meta_put($pdo, 'wqx:run_at', (string)$now);
        $cfg = wqx_cfg($pdo); wqx_break_enforce($pdo, $cfg);
        $prof = wqx_profile($pdo, $allowBuild); $L = wqx_live($pdo, $prof); $live = $mode === 'live';
        if ($live && $allowBuild && (int)wqx_meta($pdo, 'wqx:nudge_at', '0') < $now - 300) { wqx_meta_put($pdo, 'wqx:nudge_at', (string)$now); try { $res['nudges'] = wqx_nudges($pdo); } catch (Throwable $e) { } }
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        $notBiz = "NOT EXISTS (SELECT 1 FROM wa_ctags g WHERE g.phone = c.phone AND g.tag = 'business_autoreply')";
        $humanSql = function_exists('wq_human_sql') ? wq_human_sql('h', 'c', 'last_in_at') : "h.dir = 'out' AND h.emp_id > 0 AND h.ts >= c.last_in_at";
        $give = function (array $c, int $to, string $kind, string $why, int $from) use ($pdo, &$L, $live, $mode, &$res, $names) {
            $in = (string)($c['last_in_at'] ?? ''); if (wqx_logged($pdo, (string)$c['phone'], $in, $kind)) return;
            wqx_log($pdo, (string)$c['phone'], $in, $kind, $from, $to, $why, $live, $mode);
            if (isset($L[$to])) { $L[$to]['waiting'] = (int)($L[$to]['waiting'] ?? 0) + 1; if ($L[$to]['waiting'] >= 2) $L[$to]['gate'] = true; $L[$to]['load']++; $L[$to]['full'] = $L[$to]['load'] >= $L[$to]['cap']; $L[$to]['score'] = round($L[$to]['score'] * (1 - 0.5 / max(1, $L[$to]['cap'])), 3); }
            if (!$live) return;
            try {
                wa_assign_to($pdo, (string)$c['phone'], $to, 0, 'auto');
                if ($from > 0 && function_exists('wa_event')) wa_event($pdo, (string)$c['phone'], '🏪 Open market: ' . ($names[$from] ?? 'the holder') . ' did not answer in time — given to ' . ($names[$to] ?? 'someone'));
                if ($from > 0) {   // no mercy, but a clear lesson: who got it, and that the order and the commission are theirs now
                    $cn = ($c['name'] ?? '') !== '' ? (string)$c['name'] : '+91 ' . $c['phone'];
                    if (function_exists('wa_notice_add')) { try { wa_notice_add($pdo, $from, $to, (string)$c['phone'], (string)($c['name'] ?? ''), 'moved'); } catch (Throwable $e) { } }
                    if (function_exists('ct_push')) { try { ct_push($pdo, [$from], '⚠ Chat gone — no reply', $cn . ' got no answer in time and went to ' . ($names[$to] ?? 'someone') . '. The order and commission are theirs now. Stay on your chats.', '/my_chats.html', 'wqx-mv-' . $c['phone']); } catch (Throwable $e) { } }
                }
                if ($kind === 'overflow' && function_exists('ct_push') && function_exists('wq_admin_ids') && (int)wqx_meta($pdo, 'wqx:of_at', '0') < wqx_now() - 900 && (wqx_meta_put($pdo, 'wqx:of_at', (string)wqx_now()) || true)) { try { ct_push($pdo, wq_admin_ids($pdo), '🏪 Everyone is full', 'A new query went to ' . ($names[$to] ?? 'someone') . ' above their limit. More people may be needed now.', '/sales.html#people/team', 'wqx-of'); } catch (Throwable $e) { } }   // at most every 15 minutes
            } catch (Throwable $e) { wqx_log($pdo, (string)$c['phone'], (string)($c['last_in_at'] ?? ''), 'error', $from, $to, mb_substr($e->getMessage(), 0, 200), false, $mode); }
        };
        // A) new: nobody holds it, the customer is waiting, inside the 24-hour window
        $st = $pdo->prepare("SELECT c.phone, c.name, c.last_in_at, c.last_out_at, c.hot, c.tag, c.assigned_emp FROM wa_contacts c WHERE c.assigned_emp = 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag <> 'complaint')
            AND c.status = 'open' AND c.optout = 0 AND c.last_in_at >= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at OR NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND $humanSql)) AND $notBiz ORDER BY c.hot DESC, c.last_in_at LIMIT 40");
        $st->execute([wqx_dt($now - 86400 + 600)]);
        // most valuable first, so the best sellers' room goes to them: ready to pay → VIP → returning buyer → interested → the longest wait
        // 🤖 the BOT talks first. A chat goes to a person when: 🔴 ready to pay (after a short open race — anybody may Accept it first) · 🟠 interested ·
        //    VIP or returning buyer (at once) · the customer answers the bot 2-3 times (3+ messages, no person yet) · the bot is silent (no answer within a minute).
        $race = 45; $todo = []; $sweep = max(300, min(3600, (int)setting_get($pdo, 'wqx_sweep_sec', '600')));   // 🧹 every query reaches a person: a chat the bot alone has had for 10 min (setting) is given for a follow-up
        $botQ = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('bot','auto','flow') AND ts >= ?");
        $inQ = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= GREATEST(?, COALESCE((SELECT MAX(p.ts) FROM wa_messages p WHERE p.phone = ? AND p.dir = 'out' AND p.emp_id > 0), '1970-01-01'))");   // customer messages since a PERSON last answered
        foreach ($st->fetchAll() as $c) {
            $ph = (string)$c['phone']; $age = $now - wqx_ts($c['last_in_at']); $h0 = wqx_heat($pdo, $c); $v0 = wqx_vip($pdo, $ph, (int)($prof['vip_line'] ?? 0)); $r0 = !$v0 && wqx_last_seller($pdo, $ph) > 0;
            $why0 = '';
            if ($h0 === 'red') { if ($age < $race) continue; $why0 = '🔴 ready to pay, nobody accepted in ' . $race . ' s'; }
            elseif ($v0) $why0 = '⭐ VIP';
            elseif ($r0) $why0 = '🔁 returning buyer';
            elseif ($h0 === 'orange') $why0 = '🟠 interested';
            else {
                $bot = 0; try { $botQ->execute([$ph, wqx_dt($now - 86400)]); $bot = wqx_ts($botQ->fetchColumn()); } catch (Throwable $e) { }
                $nIn = 0; try { $inQ->execute([$ph, wqx_dt($now - 86400), $ph]); $nIn = (int)$inQ->fetchColumn(); } catch (Throwable $e) { }
                if ($bot < wqx_ts($c['last_in_at']) && $age >= 60) $why0 = '🤖 the bot did not answer';
                elseif ($age >= $sweep) { $why0 = '🧹 no query is left: ' . (int)round($age / 60) . ' min with the bot only — a person follows up'; $c['_sweep'] = 1; }
                elseif ($nIn >= 3 && $bot > 0) $why0 = '💬 customer is talking to the bot (' . $nIn . ' messages) — a person takes over';
                else continue;   // the bot is handling it: no person's room is used
            }
            $c['_heat'] = $h0; $c['_why'] = $why0; $c['_rank'] = ($h0 === 'red' ? 0 : ($v0 ? 1 : ($r0 ? 2 : ($h0 === 'orange' ? 3 : 4)))); $todo[] = $c;
        }
        usort($todo, function ($a, $b) { return [$a['_rank'], (string)$a['last_in_at']] <=> [$b['_rank'], (string)$b['last_in_at']]; });
        foreach ($todo as $c) {
            $heat = (string)$c['_heat'];
            if (!empty($c['_sweep'])) {   // cold follow-ups are shared out evenly, so nobody's ratio carries more of them than anybody else's
                $skip = []; foreach (wqx_hints_for($pdo, $c) as $h0) if ((int)$h0['avoid'] > 0) $skip[(int)$h0['avoid']] = 1;
                $ok = array_filter($L, function ($p) use ($skip) { return $p['can'] && !$p['held'] && empty($p['gate']) && !isset($skip[(int)$p['emp_id']]); }); if (!$ok) $ok = array_filter($L, function ($p) use ($skip) { return $p['can'] && empty($p['gate']) && !isset($skip[(int)$p['emp_id']]); });
                if ($ok) { uasort($ok, function ($x, $y) { return [$x['load'] / max(1, $x['cap']), $y['score']] <=> [$y['load'] / max(1, $y['cap']), $x['score']]; }); $f = reset($ok); [$to, $kind, $why] = [(int)$f['emp_id'], 'sweep', 'shared out evenly → ' . $f['name']]; }
                else [$to, $kind, $why] = [0, '', '⏸ nobody free — everyone at work is on a break or has a customer waiting for their reply'];
            } else [$to, $kind, $why] = wqx_pick($pdo, $c, $L, $prof, $cfg, 0, $heat);
            if ($to <= 0) { if (!wqx_logged($pdo, (string)$c['phone'], (string)$c['last_in_at'], 'none')) wqx_log($pdo, (string)$c['phone'], (string)$c['last_in_at'], 'none', 0, 0, $why, false, $mode);
                if ($live && strpos($why, '⏸') === 0 && function_exists('ct_push') && function_exists('wq_admin_ids') && (int)wqx_meta($pdo, 'wqx:busy_at', '0') < $now - 900) { wqx_meta_put($pdo, 'wqx:busy_at', (string)$now); try { ct_push($pdo, wq_admin_ids($pdo), '⏸ Queries waiting', 'Everyone at work has customers waiting for their reply, so new queries are waiting. Ask the team to reply.', '/sales.html#people/team', 'wqx-busy'); } catch (Throwable $e) { } }
                continue; }
            $give($c, $to, $kind, $c['_why'] . ' → ' . $why, 0); $res['new']++;
        }
        // B) waiting too long with its holder: no proper answer since the customer's last message.
        //    OFF by the owner's wish (12 Nov): a chat a salesperson already holds is NEVER taken away by the engine for now — only Requests (nobody holds it)
        //    are given automatically. Setting wqx_move_on = 1 turns the move-back on again. (A colleague can still Take over by hand.)
        if ((string)setting_get($pdo, 'wqx_move_on', '0') !== '1') { $res['moves'] = 'off'; return $res; }
        $st = $pdo->prepare("SELECT c.phone, c.name, c.last_in_at, c.assigned_emp, c.assigned_at, c.hot, c.tag FROM wa_contacts c WHERE c.assigned_emp > 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag <> 'complaint')
            AND c.status = 'open' AND c.optout = 0 AND c.last_in_at >= ? AND c.last_in_at <= ? AND NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND $humanSql) AND $notBiz ORDER BY c.last_in_at LIMIT 60");
        $st->execute([wqx_dt($now - 86400 + 600), wqx_dt($now - 30)]);
        $free = count(array_filter($L, function ($p) { return $p['can'] && !$p['held'] && !$p['full']; }));
        foreach ($st->fetchAll() as $c) {
            $ph = (string)$c['phone']; $from = (int)$c['assigned_emp']; $h = $L[$from] ?? null; $heat = wqx_heat($pdo, $c);
            $wait = $now - max(wqx_ts($c['last_in_at']), wqx_ts($c['assigned_at'])); $t = wqx_timer($prof, $heat, $h, $free - (($h && $h['can'] && !$h['full']) ? 1 : 0));
            if ($wait < $t) continue;
            if (function_exists('wq_meta_get') && wqx_ts(wq_meta_get($pdo, 'typing:' . $ph, '')) > $now - 90) continue;   // the holder is writing right now
            try { $q = $pdo->prepare("SELECT body, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$ph]); $m = $q->fetch(); if ($m && function_exists('wq_pace_trivial') && wq_pace_trivial((string)$m['mtype'], (string)$m['body'])) continue; } catch (Throwable $e) { }   // only "ok / thanks": nothing to answer
            try { $q = $pdo->prepare("SELECT COUNT(*) FROM wqx_log WHERE phone = ? AND kind IN ('move','gone') AND applied = 1 AND at >= ?"); $q->execute([$ph, wqx_dt($now - 86400)]); if ((int)$q->fetchColumn() >= 2) continue; } catch (Throwable $e) { }   // no ping-pong: at most 2 moves a day
            $kind = (!$h || !$h['present'] || $h['break']) ? 'gone' : 'move';
            [$to, $k2, $why] = wqx_pick($pdo, $c, $L, $prof, $cfg, $from, $heat);
            if ($to <= 0) continue;
            $give($c, $to, $kind, ($kind === 'gone' ? ($h && $h['break'] ? 'holder on a break' : 'holder punched out') : 'no proper answer for ' . (int)round($wait / 60) . ' min (limit ' . round($t / 60, 1) . ')') . ' → ' . $why, $from); $res['moved']++;
        }
    } catch (Throwable $e) { error_log('wqx_run: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 160); }
    finally { try { $pdo->query("SELECT RELEASE_LOCK('wqx_run')"); } catch (Throwable $e) { } }
    return $res;
}
/** May this person Accept a 🔴 chat in the open race? Not on a break, not paused, not with overdue complaints, at work. '' = yes, else the reason. */
function wqx_can_race(PDO $pdo, int $emp): string
{
    $prof = wqx_profile($pdo, false); $L = wqx_live($pdo, $prof); $l = $L[$emp] ?? null; if (!$l) return '';   // not in sales: the old rules decide
    if (!$l['present']) return 'Punch in (office or work from home) to take queries.';
    if ($l['break']) return 'You are on a break.';
    if ($l['held_why'] === 'complaints') return 'Solve your overdue complaints first.';
    if ($l['held_why'] === 'orders') return 'Queries are paused for you — close 1 order first.';
    return '';
}
/** Called from wa_tick (every engine run and from the webhook): the webhook never builds the profile. */
function wqx_tick(PDO $pdo): array { return wqx_run($pdo, !defined('WA_IN_WEBHOOK')); }

/** Start / Test / Stop. Live: only admins may give chats by hand (the old rights are kept and put back on Stop); the Team manager stops deciding. */
function wqx_set_mode(PDO $pdo, string $m, int $by): void
{
    $old = wqx_mode($pdo); if ($m === $old) return;
    if ($m === 'live' && $old !== 'live') {
        wqx_meta_put($pdo, 'wqx:saved', json_encode(['wqm_mode' => (string)setting_get($pdo, 'wqm_mode', 'suggest'), 'wa_manual_assign' => (string)setting_get($pdo, 'wa_manual_assign', '1')]));
        setting_set($pdo, 'wa_manual_assign', '1');   // the old routing robots (auto-assign, hot chats, flows) stay quiet: only this engine gives chats
        // hand-giving rights are NOT changed: the router lets only the admin panel and Sales Heads give chats while this runs (wa_boss_ok in wa.php)
        setting_set($pdo, 'wqm_mode', 'off');   // ONE engine: the Team manager (and its waves) stop deciding while the market runs
    }
    if ($old === 'live' && $m !== 'live') {
        $s = json_decode(wqx_meta($pdo, 'wqx:saved', ''), true);
        if (is_array($s)) { if (array_key_exists('wa_as_mode', $s)) { setting_set($pdo, 'wa_as_mode', (string)$s['wa_as_mode']); setting_set($pdo, 'wa_as_allow', (string)($s['wa_as_allow'] ?? '[]')); } setting_set($pdo, 'wqm_mode', (string)($s['wqm_mode'] ?? 'suggest')); setting_set($pdo, 'wa_manual_assign', (string)($s['wa_manual_assign'] ?? '1')); }
    }
    setting_set($pdo, 'wqx_mode', $m);
    wqx_log($pdo, '', '', 'mode', 0, $by, $old . ' → ' . $m, true, $m);
}

}   // function_exists guard

// one-time repair (12 Nov): an earlier version set "giving chats: admins only" on Start — the owner's own rights come back (Sales Heads give chats again)
try { if (wqx_mode($pdo) === 'live' && wqx_meta($pdo, 'wqx:as_fixed', '') !== '1') { $sv = json_decode(wqx_meta($pdo, 'wqx:saved', ''), true);
    if (is_array($sv) && array_key_exists('wa_as_mode', $sv)) { setting_set($pdo, 'wa_as_mode', (string)$sv['wa_as_mode']); setting_set($pdo, 'wa_as_allow', (string)($sv['wa_as_allow'] ?? '[]')); unset($sv['wa_as_mode'], $sv['wa_as_allow']); wqx_meta_put($pdo, 'wqx:saved', json_encode($sv)); }
    wqx_meta_put($pdo, 'wqx:as_fixed', '1'); } } catch (Throwable $e) { }

// ---------------------------------------------------------------------------------------------------------------- actions
$__wqx = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wqx, ['wq.mkt_get', 'wq.mkt_mode', 'wq.mkt_cfg', 'wq.mkt_recalc', 'wq.mkt_me', 'wq.mkt_break', 'wq.mkt_ask', 'wq.mkt_hint_del', 'wq.mkt_stop', 'wq.mkt_start'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!wqx_tables($pdo)) json_response(['error' => 'Storage for Auto assign is not available.'], 500);
    $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    $cfg = wqx_cfg($pdo);
    if ($__wqx === 'wq.mkt_me') {
        $prof = wqx_profile($pdo, false); $L = wqx_live($pdo, $prof); $x = $L[$me] ?? null; $b = wqx_breaks($pdo);
        $cc = wqx_coach($x, $prof['people'][$me] ?? ($prof['people'][(string)$me] ?? null), $prof, isset($b['open'][$me]), (int)($b['used'][$me] ?? 0), $cfg['break_max']);
        json_response(['ok' => true, 'mode' => wqx_mode($pdo), 'on_break' => isset($b['open'][$me]), 'break_used' => (int)($b['used'][$me] ?? 0), 'break_max' => $cfg['break_max'], 'me' => $x, 'coach' => $cc[0], 'tone' => $cc[1]]);
    }
    if ($__wqx === 'wq.mkt_break') {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0) json_response(['error' => 'Only a logged-in employee can take a break.'], 422);
        $in = json_input(); $b = wqx_breaks($pdo); $on = !empty($in['on']);
        if ($on) {
            if (isset($b['open'][$me])) json_response(['ok' => true, 'on_break' => true]);
            if ((int)($b['used'][$me] ?? 0) >= $cfg['break_max']) json_response(['error' => 'You have used today\'s ' . $cfg['break_max'] . ' minutes of break.'], 409);
            $pdo->prepare("INSERT INTO wqx_break (emp_id, start_at) VALUES (?, ?)")->execute([$me, wqx_dt()]);
        } else $pdo->prepare("UPDATE wqx_break SET end_at = ? WHERE emp_id = ? AND end_at IS NULL")->execute([wqx_dt(), $me]);
        json_response(['ok' => true, 'on_break' => $on]);
    }
    if (!$adm && function_exists('wa_boss_ok') && wa_boss_ok($pdo, $auth)) $adm = true;   // Sales Heads use the panel too (stop / start people)
    if (!$adm) json_response(['error' => 'Only Admin, HR, the control tower or a Sales Head can open Auto assign.'], 403);
    if ($__wqx === 'wq.mkt_get') {
        if ($isPost) json_response(['error' => 'Method not allowed.'], 405);
        $prof = wqx_profile($pdo, false); if (empty($prof['people'])) $prof = wqx_profile($pdo, true, true);
        $L = wqx_live($pdo, $prof); $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        $bk = wqx_breaks($pdo); $rows = []; $hints = wqx_hints($pdo); $rk = []; $i0 = 0;
        $order = $L; uasort($order, function ($x, $y) { return $y['score'] <=> $x['score']; }); foreach ($order as $e => $x) if ($x['can'] && !$x['held'] && !$x['full'] && empty($x['gate'])) $rk[(int)$e] = ++$i0;
        foreach ((array)$prof['people'] as $e => $p) { $e = (int)$e; $l = $L[$e] ?? null; $cc = wqx_coach($l, $p, $prof, isset($bk['open'][$e]), (int)($bk['used'][$e] ?? 0), $cfg['break_max']);
            $nogo = ''; foreach ($hints as $h) if ((int)$h['avoid'] === $e && $h['kind'] === 'all') $nogo = 'a rule told to Claude: ' . $h['why'];
            $why = !$l ? 'not in the list' : (!empty($l['stopped']) ? '⏸ stopped by admin — ' . ($l['stopped']['why'] !== '' ? $l['stopped']['why'] : 'paused') . ((int)$l['stopped']['until'] > 0 ? ' · till ' . date('D g:i a', (int)$l['stopped']['until']) : ' · until started again') : (!$l['present'] ? '⛔ not at work — no punch-in today and no work-from-home mark' : ($l['break'] ? '☕ on a break' : ($nogo !== '' ? '⛔ ' . $nogo : ($l['held_why'] === 'complaints' ? '⛔ ' . $l['overdue_cx'] . ' overdue complaint(s) first' : ($l['held_why'] === 'orders' ? '⛔ paused — ratio clearly below the team and no order today (1 order restarts)' : (!empty($l['gate']) ? '⏸ stopped — ' . (int)$l['waiting'] . ' customer(s) waiting for their reply (' . (int)round($l['waiting_sec'] / 60) . ' min); opens when they answer' : ($l['full'] ? '⏳ full — ' . $l['load'] . ' live chats, own limit ' . $l['cap'] : '✅ gets chats — #' . ($rk[$e] ?? '?') . ' in line (' . ($l['answered_min_ago'] === null ? 'quiet today' : 'replied ' . $l['answered_min_ago'] . ' min ago') . ', score ' . $l['score'] . ')'))))))));
            $rows[] = $p + ['live' => $l, 'coach' => $cc[0], 'tone' => $cc[1], 'why_chats' => $why, 'line' => $rk[$e] ?? null]; }
        $outside = []; try { $q = $pdo->prepare("SELECT DISTINCT m.emp_id FROM wa_messages m WHERE m.dir = 'out' AND m.emp_id > 0 AND m.ts >= ?"); $q->execute([wqx_dt(wqx_now() - 3 * 86400)]); $sl = array_flip(wqx_sales($pdo)); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) if (!isset($sl[(int)$e])) $outside[] = (string)($names[(int)$e] ?? ('#' . $e)); } catch (Throwable $e) { }
        usort($rows, function ($a, $b) { return [(int)!empty($b['live']['can']), (float)($b['live']['score'] ?? 0)] <=> [(int)!empty($a['live']['can']), (float)($a['live']['score'] ?? 0)]; });
        $log = []; try { foreach ($pdo->query("SELECT l.*, c.name cname FROM wqx_log l LEFT JOIN wa_contacts c ON c.phone = l.phone ORDER BY l.id DESC LIMIT 150")->fetchAll() as $r) $log[] = ['at' => $r['at'], 'phone' => $r['phone'], 'name' => (string)($r['cname'] ?? ''), 'kind' => $r['kind'], 'from' => (string)($names[(int)$r['from_emp']] ?? ''), 'to' => (string)($names[(int)$r['to_emp']] ?? ''), 'why' => $r['why'], 'applied' => (int)$r['applied'] === 1, 'mode' => $r['mode']]; } catch (Throwable $e) { }
        $sum = ['given' => 0, 'moved' => 0, 'none' => 0, 'with_bot' => 0]; try { $sum['with_bot'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = 0 AND status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND last_in_at >= '" . wqx_dt(wqx_now() - 86400) . "'")->fetchColumn(); } catch (Throwable $e) { } try { $q = $pdo->prepare("SELECT kind, COUNT(*) n FROM wqx_log WHERE at >= ? GROUP BY kind"); $q->execute([wqx_dt(wqx_day_start($pdo))]); foreach ($q->fetchAll() as $r) { $k = (string)$r['kind']; if (in_array($k, ['move', 'gone'], true)) $sum['moved'] += (int)$r['n']; elseif ($k === 'none') $sum['none'] += (int)$r['n']; elseif (!in_array($k, ['mode', 'error', 'stop', 'start', 'handover'], true)) $sum['given'] += (int)$r['n']; } } catch (Throwable $e) { }
        json_response(['ok' => true, 'mode' => wqx_mode($pdo), 'cfg' => $cfg, 'team' => $prof['team'] ?? [], 'timer' => $prof['timer'] ?? null, 'timer_why' => $prof['timer_why'] ?? '', 'curve' => $prof['curve'] ?? [], 'vip_line' => $prof['vip_line'] ?? 0,
            'built_at' => isset($prof['at']) ? wqx_dt((int)$prof['at']) : null, 'people' => $rows, 'log' => $log, 'today' => $sum, 'not_sales' => $outside, 'rules' => array_map(function ($h) use ($names) { return $h + ['prefer_name' => (string)($names[(int)$h['prefer']] ?? ''), 'avoid_name' => (string)($names[(int)$h['avoid']] ?? '')]; }, wqx_hints($pdo))]);
    }
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input();
    if ($__wqx === 'wq.mkt_mode') { $m = (string)($in['mode'] ?? ''); if (!in_array($m, ['off', 'shadow', 'live'], true)) json_response(['error' => 'Mode must be off, shadow or live.'], 422); wqx_set_mode($pdo, $m, $me); json_response(['ok' => true, 'mode' => wqx_mode($pdo)]); }
    if ($__wqx === 'wq.mkt_cfg') {
        foreach (['break_max' => 'wqx_break_max', 'explore' => 'wqx_explore', 'trial' => 'wqx_trial', 'start_cap' => 'wqx_start_cap', 'start_timer' => 'wqx_start_timer'] as $k => $s) if (array_key_exists($k, $in) && is_numeric($in[$k])) setting_set($pdo, $s, (string)(int)$in[$k]);
        json_response(['ok' => true, 'cfg' => wqx_cfg($pdo)]);
    }
    if ($__wqx === 'wq.mkt_ask') { @set_time_limit(90); $t = trim((string)($in['text'] ?? '')); if ($t === '') json_response(['error' => 'Write what you want Claude to do.'], 422); $r = wqx_ask($pdo, $t, $me); if (isset($r['error'])) json_response($r, 502); json_response(['ok' => true] + $r); }
    if ($__wqx === 'wq.mkt_stop') {   // ⏸ no new queries for this person: reason + how long; optionally hand their waiting customers to the best free person now
        $e = (int)($in['emp_id'] ?? 0); if ($e <= 0) json_response(['error' => 'Pick a salesperson.'], 422);
        $mins = (int)($in['mins'] ?? 0); $why = mb_substr(trim((string)($in['why'] ?? '')), 0, 40);
        $until = $mins > 0 ? wqx_now() + min(24 * 60, $mins) * 60 : ($mins < 0 ? wqx_day_start($pdo) + 86400 : 0);   // -1 = rest of today · 0 = until started again
        $st = wqx_stops($pdo); $st[$e] = ['why' => $why, 'until' => $until, 'by' => $me, 'at' => wqx_now()]; wqx_stops_put($pdo, $st);
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        wqx_log($pdo, '', '', 'stop', $me, $e, ($names[$e] ?? '#' . $e) . ' stopped' . ($why !== '' ? ' — ' . $why : '') . ($until > 0 ? ' till ' . wqx_dt($until) : ' until started again'), true, wqx_mode($pdo));
        $moved = 0;
        if (!empty($in['handover'])) {   // his customers waiting for a reply go to the best free person, once, now
            $prof = wqx_profile($pdo, false); $L = wqx_live($pdo, $prof); $cfg = wqx_cfg($pdo); $now = wqx_now();
            $hs = function_exists('wq_human_sql') ? wq_human_sql('h', 'c', 'last_in_at') : "h.dir = 'out' AND h.emp_id > 0 AND h.ts >= c.last_in_at";
            $q = $pdo->prepare("SELECT c.phone, c.name, c.last_in_at, c.hot, c.tag, c.assigned_emp FROM wa_contacts c WHERE c.assigned_emp = ? AND c.status = 'open' AND c.optout = 0 AND (c.cx_id IS NULL OR c.cx_id = 0)
                AND c.last_in_at >= ? AND NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND $hs) ORDER BY c.last_in_at LIMIT 60"); $q->execute([$e, wqx_dt($now - 86400)]);
            foreach ($q->fetchAll() as $c) {
                [$to, $k, $w] = wqx_pick($pdo, $c, $L, $prof, $cfg, $e, wqx_heat($pdo, $c)); if ($to <= 0) continue;
                try { wa_assign_to($pdo, (string)$c['phone'], $to, $me, 'manual'); wqx_log($pdo, (string)$c['phone'], (string)$c['last_in_at'], 'handover', $e, $to, 'stopped person\'s waiting customer → ' . $w, true, wqx_mode($pdo)); $moved++;
                    if (isset($L[$to])) { $L[$to]['load']++; $L[$to]['waiting'] = (int)($L[$to]['waiting'] ?? 0) + 1; if ($L[$to]['waiting'] >= 2) $L[$to]['gate'] = true; } } catch (Throwable $x) { }
            }
        }
        if (function_exists('ct_push')) { try { ct_push($pdo, [$e], '⏸ Queries stopped', 'No new queries for now' . ($why !== '' ? ' — ' . $why : '') . ($until > 0 ? ', till ' . date('g:i a', $until) : '') . '.', '/my_chats.html', 'wqx-stop-' . $e); } catch (Throwable $x) { } }
        json_response(['ok' => true, 'moved' => $moved]);
    }
    if ($__wqx === 'wq.mkt_start') {
        $e = (int)($in['emp_id'] ?? 0); $st = wqx_stops($pdo); unset($st[$e]); wqx_stops_put($pdo, $st);
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        wqx_log($pdo, '', '', 'start', $me, $e, ($names[$e] ?? '#' . $e) . ' started again', true, wqx_mode($pdo));
        if (function_exists('ct_push')) { try { ct_push($pdo, [$e], '▶ Queries on', 'Queries are coming to you again.', '/my_chats.html', 'wqx-stop-' . $e); } catch (Throwable $x) { } }
        json_response(['ok' => true]);
    }
    if ($__wqx === 'wq.mkt_hint_del') { $id = (string)($in['id'] ?? ''); wqx_hints_put($pdo, array_filter(wqx_hints($pdo), function ($h) use ($id) { return (string)$h['id'] !== $id; })); json_response(['ok' => true]); }
    if ($__wqx === 'wq.mkt_recalc') { @set_time_limit(120); $p = wqx_profile($pdo, true, true); json_response(['ok' => true, 'people' => count((array)($p['people'] ?? []))]); }
}
