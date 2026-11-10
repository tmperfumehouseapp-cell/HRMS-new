<?php
/**
 * 👥 TEAM BOARD — every salesperson in one place, for a period you choose (today / last 7 days / this month / last month / last 30 days / own dates).
 * Built by joining what already exists (wqm_collect in wq_manager.php, coach_day / rating_days / cp_day_rows in api.php, attendance, wq_pace_get) — no second calculation.
 *
 * Two parts, so the table is fast and the heavy detail is only worked out for the person you open:
 *   team.board   GET  admin  ?range=today|week|month|last_month|d30|custom [&from&to] [&fresh=1]  → the light table (about 25 numbers a person). Kept ready: the tick rebuilds it every 3 minutes.
 *   team.person  GET  admin  ?emp=ID&range=…                                                       → everything of ONE person (rating parts, metric grid, day by day, speed buckets, remarks …)
 * "Query orders" = queries that produced an order (the same rule as the Pace tab). Pay and commission are NOT here (they stay in Money, admin only). PHP 7.4 safe.
 */
if (!function_exists('tb_range')) {

/** The period the admin asked for: [from, to, label, key, pace_days (1 / 7 / 30, the nearest window wq_pace_get knows)]. */
function tb_range(PDO $pdo, string $range, string $f, string $t): array
{
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $ok = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
    if ($range === 'custom' && $ok($f) && $ok($t)) { if ($f > $t) { $x = $f; $f = $t; $t = $x; } if (strtotime($t) - strtotime($f) > 92 * 86400) $f = date('Y-m-d', strtotime($t . ' -92 day')); $n = (int)round((strtotime($t) - strtotime($f)) / 86400) + 1; return [$f, $t, $f . ' → ' . $t, 'custom', $n <= 1 ? 1 : ($n <= 10 ? 7 : 30)]; }
    if ($range === 'week') return [date('Y-m-d', strtotime($today . ' -6 day')), $today, 'Last 7 days', 'week', 7];
    if ($range === 'month') return [substr($today, 0, 8) . '01', $today, 'This month', 'month', 30];
    if ($range === 'last_month') { $a = date('Y-m-01', strtotime(substr($today, 0, 8) . '01 -1 month')); return [$a, date('Y-m-t', strtotime($a)), 'Last month', 'last_month', 30]; }
    if ($range === 'd30') return [date('Y-m-d', strtotime($today . ' -29 day')), $today, 'Last 30 days', 'd30', 30];
    return [$today, $today, 'Today', 'today', 1];
}

/** The business days of a period (the day timing decides where a day starts: 12:00 to 12:00 and so on), at most 93. */
function tb_days(string $from, string $to): array
{
    $d = []; for ($i = 0; $i < 93; $i++) { $x = date('Y-m-d', strtotime($from . ' +' . $i . ' day')); if ($x > $to) break; $d[] = $x; } return $d;
}

/** One business day of every salesperson, from the SAME calculation the Sales → Live section uses (ct_snapshot → cp_day_rows). Past days are cached like Live's Person view was (15 min for the last 3 days, 12 h older). */
function tb_cp_day(PDO $pdo, string $day, string $today): array
{
    if (!function_exists('cp_day_rows')) return [];
    try {
        if ($day === $today) return (array)cp_day_rows($pdo, $day);
        $ttl = $day >= date('Y-m-d', strtotime($today . ' -3 day')) ? 900 : 43200;
        $c = json_decode((string)setting_get($pdo, 'cp_d_' . $day, ''), true); if (is_array($c) && !empty($c['rows']) && time() - (int)($c['at'] ?? 0) < $ttl) return $c['rows'];
        $r = (array)cp_day_rows($pdo, $day); if ($r) setting_set($pdo, 'cp_d_' . $day, json_encode(['at' => time(), 'rows' => $r])); return $r;
    } catch (Throwable $e) { return []; }
}

/** Totals of the period per salesperson, from Live's own day rows. Returns [$perEmp, $rowsByDay, $teamRatio (query orders / queries), $teamTicket (₹ / orders)]. */
function tb_agg(PDO $pdo, array $days, string $today): array
{
    $per = []; $byDay = []; $tq = 0; $tqo = 0; $tn = 0; $ts = 0;
    foreach ($days as $d) {
        $r = tb_cp_day($pdo, $d, $today); $byDay[$d] = $r;
        foreach ($r as $e => $x) {
            if (!empty($x['off'])) continue;
            if (!isset($per[$e])) $per[$e] = ['n' => 0, 's' => 0, 'q' => 0, 'qo' => 0, 'co' => 0, 'cq' => 0, 'days' => 0, 'late' => 0];
            $per[$e]['n'] += (int)$x['n']; $per[$e]['s'] += (int)$x['s']; $per[$e]['q'] += (int)$x['q']; $per[$e]['qo'] += (int)$x['qo']; $per[$e]['co'] += (int)$x['co']; $per[$e]['cq'] += (int)$x['cq']; $per[$e]['days']++; if ((int)($x['lt'] ?? 0) > 0) $per[$e]['late']++;
            $tq += (int)$x['q']; $tqo += (int)$x['qo']; $tn += (int)$x['n']; $ts += (int)$x['s'];
        }
    }
    return [$per, $byDay, $tq > 0 ? $tqo / $tq : 0.0, $tn > 0 ? (int)round($ts / $tn) : null];
}

/** Reply speed → orders: queries of the period (the business-day window) from the WhatsApp query stats, per salesperson, in buckets. WhatsApp-only, so it is shown as "WhatsApp queries". */
function tb_buckets(PDO $pdo, string $from, string $to): array
{
    $qs = [];
    try {
        [$a, $b] = function_exists('biz_window') ? biz_window($pdo, $from, $to) : [$from . ' 00:00:00', $to . ' 23:59:59'];
        $q = $pdo->prepare("SELECT q.emp_id, s.first_person_min fpm, s.orders FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= ? AND s.opened_at <= ? AND q.emp_id > 0 LIMIT 60000"); $q->execute([$a, $b]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = (int)$r['emp_id']; if (!isset($qs[$e])) $qs[$e] = ['fast' => [0, 0], 'mid' => [0, 0], 'slow' => [0, 0], 'none' => [0, 0]];
            $f = $r['fpm']; $k = $f === null ? 'none' : ((float)$f <= 5 ? 'fast' : ((float)$f <= 30 ? 'mid' : 'slow')); $qs[$e][$k][0]++; if ((int)$r['orders'] > 0) $qs[$e][$k][1]++;
        }
    } catch (Throwable $e) { }
    return $qs;
}

/** Today's hours per person: emp => [first punch-in "HH:MM", minutes worked] (calendar day, only used for the hours). */
function tb_today_punch(PDO $pdo): array
{
    $o = []; $st = date('Y-m-d 00:00:00'); try { if (function_exists('biz_window') && function_exists('biz_today')) $st = biz_window($pdo, biz_today($pdo), biz_today($pdo))[0]; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT employee_id, MIN(punch_in) first_in, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? GROUP BY employee_id"); $q->execute([$st]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['employee_id']] = [substr((string)$r['first_in'], 11, 5), max(0, (int)$r['mins'])]; } catch (Throwable $e) { }
    return $o;
}

/** Rank by all orders in the period and medals: most orders, fastest, biggest ticket, best ratio — among people who have the data. */
function tb_rank(array $out): array
{
    $ids = array_keys($out); usort($ids, function ($x, $y) use ($out) { return [$out[$y]['all_orders'], $out[$x]['name']] <=> [$out[$x]['all_orders'], $out[$y]['name']]; });
    $i = 0; foreach ($ids as $id) { $out[$id]['rank'] = ++$i; $out[$id]['medals'] = []; }
    $best = function (string $k, bool $low, int $minQ) use (&$out) { $bi = 0; $bv = null; foreach ($out as $id => $r) { $v = $r[$k] ?? null; if ($v === null || $r['queries_30d'] < $minQ) continue; if ($bv === null || ($low ? $v < $bv : $v > $bv)) { $bv = $v; $bi = $id; } } return ($bv !== null && $bv > 0) ? $bi : 0; };
    $mo = $best('all_orders', false, 0); if ($mo) $out[$mo]['medals'][] = '🥇 Most orders';
    $ms = $best('speed_median_min_30d', true, 3); if ($ms) $out[$ms]['medals'][] = '⚡ Fastest';
    $mt = $best('ticket_30d', false, 0); if ($mt) $out[$mt]['medals'][] = '💎 Biggest ticket';
    $mr = $best('ratio_pct', false, 3); if ($mr) $out[$mr]['medals'][] = '🎯 Best ratio';
    return $out;
}

/** The light table. Orders, query orders, queries, ticket, target, punch-in and late come from the SAME day rows as Sales → Live (one definition, one day timing); the WhatsApp parts (speed, tone, complaints, Claude's level) come from the Team manager signals. */
function tb_light(PDO $pdo, array $per): array
{
    [$from, $to, , , $paceDays] = $per; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    $rows = function_exists('wqm_collect') ? wqm_collect($pdo, ['light' => true]) : []; $out = [];
    $days = tb_days($from, $to); $nd = max(1, count($days));
    [$A, $byDay, $teamRatio, $teamTicket] = tb_agg($pdo, $days, $today);
    [$P] = tb_agg($pdo, tb_days(date('Y-m-d', strtotime($from . ' -' . $nd . ' day')), date('Y-m-d', strtotime($from . ' -1 day'))), $today);   // the same length just before: for the ▲▼
    $tr = $byDay[$today] ?? tb_cp_day($pdo, $today, $today); $snap = []; try { if (function_exists('ct_snapshot')) $snap = (array)(ct_snapshot($pdo, $today)['emps'] ?? []); } catch (Throwable $e) { }
    $sp = []; try { if (function_exists('wq_pace_get')) foreach ((array)(wq_pace_get($pdo, $paceDays)['people'] ?? []) as $p) $sp[(int)$p['emp_id']] = $p; } catch (Throwable $e) { }
    $tin = tb_today_punch($pdo);
    $ratio = function ($a) { return ($a && $a['q'] > 0) ? round(100 * $a['qo'] / $a['q'], 1) : null; }; $tick = function ($a) { return ($a && $a['n'] > 0) ? (int)round($a['s'] / $a['n']) : null; };
    foreach ($rows as $id => $r) {
        $a = $A[$id] ?? null; $pv = $P[$id] ?? null; $t = $tr[$id] ?? []; $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : [];
        $q = $a ? $a['q'] : 0; $qo = $a ? $a['qo'] : 0;
        $out[$id] = [
            'emp_id' => $id, 'name' => $r['name'], 'present' => $r['present'], 'answering_min_ago' => $r['answering_min_ago'], 'punch_in_today' => isset($t['ar']) && $t['ar'] !== null ? (string)$t['ar'] : ($tin[$id][0] ?? ''), 'late_min_today' => $t['lt'] ?? null, 'hours_today' => round(($tin[$id][1] ?? 0) / 60, 1),
            'all_orders' => $a ? $a['n'] : 0, 'sales_period' => $a ? $a['s'] : 0, 'campaign_orders' => $a ? $a['co'] : 0, 'queries_30d' => $q, 'orders_30d' => $qo, 'ratio_pct' => $ratio($a), 'team_ratio_pct' => round(100 * $teamRatio, 1), 'team_ticket' => $teamTicket, 'ticket_30d' => $tick($a),
            'sure_behind' => $q > 0 && $teamRatio > 0 && wqm_hi($qo, $q) < $teamRatio, 'days_worked' => $a ? $a['days'] : 0, 'late_days' => $a ? $a['late'] : 0,
            'prev_all_orders' => $pv ? $pv['n'] : null, 'prev_orders' => $pv ? $pv['qo'] : null, 'prev_ratio_pct' => $ratio($pv), 'prev_ticket' => $tick($pv),
            'speed_median_min_30d' => $sp[$id]['pace']['median'] ?? null, 'tone_score' => $r['tone_score'] ?? null, 'overdue_complaints' => $r['overdue_complaints'], 'pending_answers' => $r['pending_answers'], 'open_chats' => $r['open_chats'],
            'target_today' => isset($t['t']) ? (float)$t['t'] : null, 'sales_today' => isset($t['s']) ? (float)$t['s'] : null, 'target_pct_today' => $t['p'] ?? null, 'should_pct_today' => $snap[$id]['should_pct'] ?? null,
            'level' => (string)($m['level'] ?? ''), 'meet_admin' => !empty($m['meet_admin']), 'rank' => 0, 'medals' => []];
    }
    return array_values(tb_rank($out));
}

/** Everything about the people for the period: the light table plus the heavy details. Used for the popup of ONE person and cached for everybody. */
function tb_full(PDO $pdo, array $per): array
{
    [$from, $to, , , ] = $per; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    $light = []; foreach (tb_light($pdo, $per) as $x) $light[(int)$x['emp_id']] = $x;
    $wq = function_exists('wqm_collect') ? wqm_collect($pdo, ['no_rating' => true]) : []; $out = [];   // the rating below is the PERIOD's, worked out once here
    $days = tb_days($from, $to); $byDay = []; foreach ($days as $d) $byDay[$d] = tb_cp_day($pdo, $d, $today);   // cached; the same rows the light table just used
    $bk = tb_buckets($pdo, $from, $to);
    [$a0, $b0] = function_exists('biz_window') ? biz_window($pdo, $from, $to) : [$from . ' 00:00:00', $to . ' 23:59:59'];
    $att = []; $hrs = [];
    try {
        $q = $pdo->prepare("SELECT employee_id, DATE(punch_in) d, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? AND punch_in <= ? GROUP BY employee_id, DATE(punch_in)"); $q->execute([$a0, $b0]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = (int)$r['employee_id']; $hrs[$e][(string)$r['d']] = max(0, (int)$r['mins']); if (!isset($att[$e])) $att[$e] = 0; $att[$e] += max(0, (int)$r['mins']); }
    } catch (Throwable $e) { }
    $rp = []; $score = [];
    try { if (function_exists('rating_days')) { $rdays = rating_days($pdo, $from, $to);
        foreach ($rdays as $d => $list) foreach ((array)$list as $e => $x) $score[(int)$e][$d] = isset($x['score']) ? round((float)$x['score'], 1) : null;
        if (function_exists('rating_period')) foreach ($wq as $id => $r0) { $x = rating_period($rdays, $id); if (is_array($x)) $rp[$id] = $x; } } } catch (Throwable $e) { }
    $mdays = array_slice(array_reverse($days), 0, 14);   // the last days of the period, newest first
    $rem = []; try { foreach ($pdo->query("SELECT employee_id, from_name, src, body, created_at, seen_at FROM ct_remarks WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' ORDER BY id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC) as $x) { $e = (int)$x['employee_id']; if (count($rem[$e] ?? []) < 3) $rem[$e][] = ['by' => (string)$x['from_name'], 'text' => mb_substr((string)$x['body'], 0, 300), 'at' => (string)$x['created_at'], 'seen' => !empty($x['seen_at'])]; } } catch (Throwable $e) { }
    foreach ($light as $id => $L) {
        $r = $wq[$id] ?? []; $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : []; $bb = []; foreach (($bk[$id] ?? []) as $k => $z) $bb[$k] = ['queries' => $z[0], 'orders' => $z[1], 'conversion' => $z[0] > 0 ? round(100 * $z[1] / $z[0], 1) : null];
        $daily = []; $metrics = [];
        foreach ($days as $d) { $x = $byDay[$d][$id] ?? null; if ($x && empty($x['off'])) $daily[] = ['d' => $d, 'in' => isset($x['ar']) ? (string)$x['ar'] : '', 'late' => $x['lt'] ?? null, 'h' => round(($hrs[$id][$d] ?? 0) / 60, 1), 'orders' => (int)$x['n'], 'sales' => (int)$x['s'], 'target' => $x['t'], 'pct' => $x['p'], 'qo' => (int)$x['qo'], 'q' => (int)$x['q'], 'score' => $score[$id][$d] ?? null, 'hit' => ($x['t'] ?? 0) > 0 && $x['p'] !== null ? ($x['p'] >= 100) : null]; }
        foreach ($mdays as $d) if (isset($byDay[$d][$id])) $metrics[$d] = $byDay[$d][$id];
        $hh = round(($att[$id] ?? 0) / 60, 1); $nw = max(1, (int)$L['days_worked']);
        $out[$id] = [
            'days_30d' => $L['days_worked'], 'hours_30d' => $hh, 'avg_hours_day' => $L['days_worked'] > 0 ? round($hh / $nw, 1) : null, 'orders_per_hour' => $hh > 0 ? round($L['all_orders'] / $hh, 2) : null,
            'speed_buckets' => $bb ?: null, 'rating_30d' => isset($rp[$id]['score']) ? round((float)$rp[$id]['score'], 1) : null,
            'auto_level' => (string)($m['auto_level'] ?? ''), 'manual' => (string)($m['manual'] ?? ''), 'card' => (string)($m['card'] ?? ''), 'reason' => (string)($m['reason'] ?? ''), 'approved' => !empty($m['approved']), 'decided_at' => (string)($m['at'] ?? ''),
            'rating_parts' => isset($rp[$id]) ? ['parts' => $rp[$id]['parts'], 'notes' => $rp[$id]['notes'] ?? [], 'days' => $rp[$id]['days'] ?? 0] : null,
            'rating_lift' => (function ($x) { if (!is_array($x)) return null; $w = null; foreach ((array)$x['parts'] as $k => $v) if ($v !== null && $k !== 'consistency' && ($w === null || $v < $x['parts'][$w])) $w = $k; return $w && function_exists('rating_fix_text') ? ['part' => $w, 'text' => rating_fix_text($w)] : null; })($rp[$id] ?? null),
            'metrics' => $metrics ?: (object)[], 'remarks' => $rem[$id] ?? [], 'daily' => array_reverse($daily)] + $L + $r;   // my period values first, then the light row, then the manager's 30-day signals (the first one of a key wins)
    }
    return ['rows' => array_values($out), 'metric_days' => $mdays];
}

function tb_cache_get(PDO $pdo, string $k, int $ttl)
{
    $c = json_decode(wq_meta_get($pdo, $k, ''), true); return (is_array($c) && isset($c['d']) && (int)($c['at'] ?? 0) > time() - $ttl) ? $c : null;
}
function tb_cache_put(PDO $pdo, string $k, $d): void { wq_meta_put($pdo, $k, json_encode(['at' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE)); }
function tb_ck(string $kind, array $per): string { return 'tb:' . $kind . ':' . md5($per[0] . '|' . $per[1]); }

/** The light table of a period, from the cache when it is fresh (2 minutes), else built now. */
function tb_light_cached(PDO $pdo, array $per, bool $force): array
{
    $k = tb_ck('l', $per); $c = $force ? null : tb_cache_get($pdo, $k, 120); if ($c) return ['rows' => $c['d'], 'at' => (int)$c['at']];
    $rows = tb_light($pdo, $per); tb_cache_put($pdo, $k, $rows); return ['rows' => $rows, 'at' => time()];
}
function tb_full_cached(PDO $pdo, array $per, bool $force): array
{
    $k = tb_ck('f', $per); $c = $force ? null : tb_cache_get($pdo, $k, 120); if ($c) return $c['d'];
    $b = tb_full($pdo, $per); tb_cache_put($pdo, $k, $b); return $b;
}

/** Called from the engine tick: keep today's and this month's table ready, so nobody waits for it. */
function tb_tick(PDO $pdo): array
{
    $last = strtotime(wq_meta_get($pdo, 'tb:tick_at', '')) ?: 0; if ($last > time() - 150) return ['skipped' => 'recent'];
    wq_meta_put($pdo, 'tb:tick_at', date('Y-m-d H:i:s')); $n = 0; @set_time_limit(120);   // the first month build works out every past business day once (then each is cached)
    foreach (['today', 'month'] as $r) { try { tb_light_cached($pdo, tb_range($pdo, $r, '', ''), true); $n++; } catch (Throwable $e) { error_log('tb_tick: ' . $e->getMessage()); } }
    try { tb_full_cached($pdo, tb_range($pdo, 'today', '', ''), true); $n++; } catch (Throwable $e) { error_log('tb_tick full: ' . $e->getMessage()); }   // today's details too, so opening a person is instant
    return ['built' => $n];
}

}

$__tbA = (string)($action ?? ($_GET['action'] ?? ''));
if ($__tbA === 'team.me') {   // 🙋 MY summary: only the logged-in salesperson's own numbers (never anybody else's), from the same cached table
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if ($me <= 0 || !function_exists('wa_tables') || !wa_tables($pdo) || !function_exists('wq_tables') || !wq_tables($pdo)) json_response(['ok' => true, 'none' => true]);
    $keys = ['all_orders', 'prev_all_orders', 'sales_period', 'queries_30d', 'orders_30d', 'ratio_pct', 'team_ratio_pct', 'ticket_30d', 'team_ticket', 'speed_median_min_30d', 'target_today', 'target_pct_today', 'should_pct_today', 'sales_today', 'rank', 'medals', 'prev_orders', 'prev_ratio_pct', 'prev_ticket', 'present', 'punch_in_today'];
    $o = ['ok' => true, 'none' => true, 'periods' => []];
    foreach (['today', 'month'] as $r) {
        $L = tb_light_cached($pdo, tb_range($pdo, $r, '', ''), false); $mine = null; foreach ($L['rows'] as $x) if ((int)$x['emp_id'] === $me) { $mine = $x; break; }
        if ($mine) { $o['none'] = false; $row = []; foreach ($keys as $k) $row[$k] = $mine[$k] ?? null; $row['people'] = count($L['rows']); $o['periods'][$r] = $row; }
    }
    $m = function_exists('wqm_rec') ? wqm_rec($pdo, $me) : []; $o['card'] = (function_exists('wqm_mode') && wqm_mode($pdo) === 'apply' && !empty($m['card'])) ? ['text' => (string)$m['card'], 'level' => (string)($m['level'] ?? '')] : null;
    json_response($o);
}
if ($__tbA === 'team.board' || $__tbA === 'team.person') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !function_exists('wq_tables') || !wq_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    @set_time_limit(90); $per = tb_range($pdo, (string)($_GET['range'] ?? 'today'), (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')); $force = !empty($_GET['fresh']);
    $perOut = ['key' => $per[3], 'from' => $per[0], 'to' => $per[1], 'label' => $per[2]]; $todayS = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    if ($__tbA === 'team.person') {
        $emp = (int)($_GET['emp'] ?? 0); $B = tb_full_cached($pdo, $per, $force); $row = null; foreach ($B['rows'] as $x) if ((int)$x['emp_id'] === $emp) { $row = $x; break; }
        if (!$row) json_response(['error' => 'Person not found.'], 404);
        json_response(['ok' => true, 'row' => $row, 'period' => $perOut, 'today' => $todayS, 'rating_labels' => function_exists('rating_parts') ? rating_parts() : (object)[], 'metric_days' => $B['metric_days'] ?? [], 'levels' => function_exists('wqm_levels') ? wqm_levels() : []]);
    }
    $L = tb_light_cached($pdo, $per, $force); $rows = $L['rows']; $r0 = $rows ? $rows[0] : [];
    json_response(['ok' => true, 'rows' => $rows, 'period' => $perOut, 'today' => $todayS, 'built_at' => date('Y-m-d H:i:s', $L['at']), 'age_s' => max(0, time() - $L['at']),
        'team' => ['ratio_pct' => $r0['team_ratio_pct'] ?? null, 'ticket' => $r0['team_ticket'] ?? null, 'people' => count($rows), 'at_work' => count(array_filter($rows, function ($x) { return !empty($x['present']); }))],
        'summary' => wq_meta_get($pdo, 'mgr:summary', ''), 'ran_at' => wq_meta_get($pdo, 'mgr:at', ''), 'mode' => function_exists('wqm_mode') ? wqm_mode($pdo) : 'suggest', 'levels' => function_exists('wqm_levels') ? wqm_levels() : []]);
}
