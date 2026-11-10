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

/** Queries of the period per salesperson: n, won (queries that produced an order), orders, revenue, reply-speed buckets. Returns [$perEmp, $teamRatio, $teamTicket]. */
function tb_queries(PDO $pdo, string $from, string $to): array
{
    $qs = [];
    try {
        $q = $pdo->prepare("SELECT q.emp_id, s.first_person_min fpm, s.orders, s.revenue rev FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= ? AND s.opened_at <= ? AND q.emp_id > 0 LIMIT 60000"); $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $e = (int)$r['emp_id']; if (!isset($qs[$e])) $qs[$e] = ['n' => 0, 'won' => 0, 'ord' => 0, 'rev' => 0.0, 'b' => ['fast' => [0, 0], 'mid' => [0, 0], 'slow' => [0, 0], 'none' => [0, 0]]];
            $won = (int)$r['orders'] > 0; $f = $r['fpm']; $k = $f === null ? 'none' : ((float)$f <= 5 ? 'fast' : ((float)$f <= 30 ? 'mid' : 'slow'));
            $qs[$e]['n']++; $qs[$e]['b'][$k][0]++; if ($won) { $qs[$e]['won']++; $qs[$e]['ord'] += (int)$r['orders']; $qs[$e]['rev'] += (float)$r['rev']; $qs[$e]['b'][$k][1]++; }
        }
    } catch (Throwable $e) { }
    $tN = 0; $tW = 0; $tO = 0; $tR = 0.0; foreach ($qs as $a) { $tN += $a['n']; $tW += $a['won']; $tO += $a['ord']; $tR += $a['rev']; }
    return [$qs, $tN > 0 ? $tW / $tN : 0.0, $tO > 0 ? (int)round($tR / $tO) : null];
}

/** Today's punch-in and hours per person: emp => [first punch-in "HH:MM", minutes worked]. */
function tb_today_punch(PDO $pdo): array
{
    $o = []; try { $q = $pdo->prepare("SELECT employee_id, MIN(punch_in) first_in, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? GROUP BY employee_id"); $q->execute([date('Y-m-d 00:00:00')]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['employee_id']] = [substr((string)$r['first_in'], 11, 5), max(0, (int)$r['mins'])]; } catch (Throwable $e) { }
    return $o;
}

/** Rank by query orders in the period and medals: most orders, fastest, biggest ticket, best ratio — among people who have the data. */
function tb_rank(array $out): array
{
    $ids = array_keys($out); usort($ids, function ($x, $y) use ($out) { return [$out[$y]['orders_30d'], $out[$x]['name']] <=> [$out[$x]['orders_30d'], $out[$y]['name']]; });
    $i = 0; foreach ($ids as $id) { $out[$id]['rank'] = ++$i; $out[$id]['medals'] = []; }
    $best = function (string $k, bool $low, int $minQ) use (&$out) { $bi = 0; $bv = null; foreach ($out as $id => $r) { $v = $r[$k] ?? null; if ($v === null || $r['queries_30d'] < $minQ) continue; if ($bv === null || ($low ? $v < $bv : $v > $bv)) { $bv = $v; $bi = $id; } } return ($bv !== null && $bv > 0) ? $bi : 0; };
    $mo = $best('orders_30d', false, 1); if ($mo) $out[$mo]['medals'][] = '🥇 Most orders';
    $ms = $best('speed_median_min_30d', true, 3); if ($ms) $out[$ms]['medals'][] = '⚡ Fastest';
    $mt = $best('ticket_30d', false, 1); if ($mt) $out[$mt]['medals'][] = '💎 Biggest ticket';
    $mr = $best('ratio_pct', false, 3); if ($mr) $out[$mr]['medals'][] = '🎯 Best ratio';
    return $out;
}

/** The light table: no rating, no day-by-day, no metric grid. */
function tb_light(PDO $pdo, array $per): array
{
    [$from, $to, , , $paceDays] = $per; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    $rows = function_exists('wqm_collect') ? wqm_collect($pdo, ['light' => true]) : []; $out = [];
    [$qs, $teamRatio, $teamTicket] = tb_queries($pdo, $from, $to);
    $sp = []; try { if (function_exists('wq_pace_get')) foreach ((array)(wq_pace_get($pdo, $paceDays)['people'] ?? []) as $p) $sp[(int)$p['emp_id']] = $p; } catch (Throwable $e) { }
    $tin = tb_today_punch($pdo); $cd = []; try { if (function_exists('coach_day')) $cd = (array)(coach_day($pdo, $today)['emps'] ?? []); } catch (Throwable $e) { }
    foreach ($rows as $id => $r) {
        $s = $qs[$id] ?? null; $n = $s ? $s['n'] : 0; $won = $s ? $s['won'] : 0; $c = $cd[$id] ?? []; $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : [];
        $out[$id] = [
            'emp_id' => $id, 'name' => $r['name'], 'present' => $r['present'], 'answering_min_ago' => $r['answering_min_ago'], 'punch_in_today' => $tin[$id][0] ?? '', 'hours_today' => round(($tin[$id][1] ?? 0) / 60, 1),
            'queries_30d' => $n, 'orders_30d' => $won, 'ratio_pct' => $n > 0 ? round(100 * $won / $n, 1) : null, 'team_ratio_pct' => round(100 * $teamRatio, 1), 'team_ticket' => $teamTicket,
            'ticket_30d' => ($s && $s['ord'] > 0) ? (int)round($s['rev'] / $s['ord']) : null, 'sure_behind' => $n > 0 && $teamRatio > 0 && wqm_hi($won, $n) < $teamRatio,
            'speed_median_min_30d' => $sp[$id]['pace']['median'] ?? null, 'tone_score' => $r['tone_score'] ?? null, 'overdue_complaints' => $r['overdue_complaints'], 'pending_answers' => $r['pending_answers'], 'open_chats' => $r['open_chats'],
            'target_today' => isset($c['target']) ? (float)$c['target'] : null, 'sales_today' => isset($c['sales']) ? (float)$c['sales'] : null, 'target_pct_today' => $c['pct'] ?? null, 'should_pct_today' => $c['should_pct'] ?? null,
            'level' => (string)($m['level'] ?? ''), 'meet_admin' => !empty($m['meet_admin']), 'rank' => 0, 'medals' => []];
    }
    return array_values(tb_rank($out));
}

/** Everything about the people for the period (the old "full" build). Heavy: used for the popup of ONE person and cached for everybody. */
function tb_full(PDO $pdo, array $per): array
{
    [$from, $to, $label, $key, $paceDays] = $per; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    $rows = function_exists('wqm_collect') ? wqm_collect($pdo, ['no_rating' => true]) : []; $out = [];   // the rating below is the PERIOD's, worked out once here
    [$qs, $teamRatio, $teamTicket] = tb_queries($pdo, $from, $to);
    $sp = []; try { if (function_exists('wq_pace_get')) foreach ((array)(wq_pace_get($pdo, $paceDays)['people'] ?? []) as $p) $sp[(int)$p['emp_id']] = $p; } catch (Throwable $e) { }
    $f0 = $from . ' 00:00:00'; $t1 = $to . ' 23:59:59';
    $att = []; $daily = [];
    try {
        $q = $pdo->prepare("SELECT employee_id, DATE(punch_in) d, MIN(punch_in) first_in, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? AND punch_in <= ? GROUP BY employee_id, DATE(punch_in)"); $q->execute([$f0, $t1]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = (int)$r['employee_id']; if (!isset($att[$e])) $att[$e] = ['days' => 0, 'mins' => 0]; $att[$e]['days']++; $att[$e]['mins'] += max(0, (int)$r['mins']); $daily[$e][(string)$r['d']] = ['d' => (string)$r['d'], 'in' => substr((string)$r['first_in'], 11, 5), 'h' => round(max(0, (int)$r['mins']) / 60, 1), 'score' => null, 'hit' => null]; }
    } catch (Throwable $e) { }
    $tin = tb_today_punch($pdo);
    $rp = [];
    try { if (function_exists('rating_days')) { $rdays = rating_days($pdo, $from, $to);
        foreach ($rdays as $d => $list) foreach ((array)$list as $e => $x) { $e = (int)$e; if (!isset($daily[$e][$d])) $daily[$e][$d] = ['d' => (string)$d, 'in' => '', 'h' => 0, 'score' => null, 'hit' => null]; $daily[$e][$d]['score'] = isset($x['score']) ? round((float)$x['score'], 1) : null; $daily[$e][$d]['hit'] = !empty($x['has_target']) ? !empty($x['hit']) : null; }
        if (function_exists('rating_period')) foreach ($rows as $id => $r0) { $x = rating_period($rdays, $id); if (is_array($x)) $rp[$id] = $x; } } } catch (Throwable $e) { }
    $metrics = []; $mdays = [];   // the metric blocks of the old Person tab: the last days of the period, at most 14
    try { if (function_exists('cp_day_rows')) { $n = min(14, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1); for ($i = 0; $i < $n; $i++) { $d = date('Y-m-d', strtotime($to . ' -' . $i . ' day')); $mdays[] = $d; foreach ((array)cp_day_rows($pdo, $d) as $e => $x) $metrics[(int)$e][$d] = $x; } } } catch (Throwable $e) { }
    $cd = []; try { if (function_exists('coach_day')) $cd = (array)(coach_day($pdo, $today)['emps'] ?? []); } catch (Throwable $e) { }
    $rem = []; try { foreach ($pdo->query("SELECT employee_id, from_name, src, body, created_at, seen_at FROM ct_remarks WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' ORDER BY id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC) as $x) { $e = (int)$x['employee_id']; if (count($rem[$e] ?? []) < 3) $rem[$e][] = ['by' => (string)$x['from_name'], 'text' => mb_substr((string)$x['body'], 0, 300), 'at' => (string)$x['created_at'], 'seen' => !empty($x['seen_at'])]; } } catch (Throwable $e) { }
    foreach ($rows as $id => $r) {
        $a = $att[$id] ?? ['days' => 0, 'mins' => 0]; $c = $cd[$id] ?? []; $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : []; $s = $qs[$id] ?? null; $p = $sp[$id] ?? null;
        $n = $s ? $s['n'] : 0; $won = $s ? $s['won'] : 0; $hrs = round($a['mins'] / 60, 1);
        $bk = []; if ($s) foreach ($s['b'] as $k => $z) $bk[$k] = ['queries' => $z[0], 'orders' => $z[1], 'conversion' => $z[0] > 0 ? round(100 * $z[1] / $z[0], 1) : null];
        // the period's numbers replace the 30-day ones of the Team manager signals (same keys, so the manager and the board agree)
        $r['queries_30d'] = $n; $r['orders_30d'] = $won; $r['ratio_pct'] = $n > 0 ? round(100 * $won / $n, 1) : null; $r['team_ratio_pct'] = round(100 * $teamRatio, 1); $r['team_ticket'] = $teamTicket;
        $r['ticket_30d'] = ($s && $s['ord'] > 0) ? (int)round($s['rev'] / $s['ord']) : null; $r['sure_behind'] = $n > 0 && $teamRatio > 0 && wqm_hi($won, $n) < $teamRatio;
        $r['speed_median_min_30d'] = $p['pace']['median'] ?? null; $r['speed_buckets'] = $bk ?: null;
        $r['rating_30d'] = isset($rp[$id]['score']) ? round((float)$rp[$id]['score'], 1) : null;
        $out[$id] = $r + ['emp_id' => $id, 'days_30d' => $a['days'], 'hours_30d' => $hrs, 'avg_hours_day' => $a['days'] > 0 ? round($hrs / $a['days'], 1) : null, 'orders_per_hour' => $hrs > 0 ? round($won / $hrs, 2) : null,
            'punch_in_today' => $tin[$id][0] ?? '', 'hours_today' => round(($tin[$id][1] ?? 0) / 60, 1),
            'target_today' => isset($c['target']) ? (float)$c['target'] : null, 'sales_today' => isset($c['sales']) ? (float)$c['sales'] : null, 'target_pct_today' => $c['pct'] ?? null, 'should_pct_today' => $c['should_pct'] ?? null,
            'level' => (string)($m['level'] ?? ''), 'auto_level' => (string)($m['auto_level'] ?? ''), 'manual' => (string)($m['manual'] ?? ''), 'card' => (string)($m['card'] ?? ''), 'reason' => (string)($m['reason'] ?? ''), 'meet_admin' => !empty($m['meet_admin']), 'approved' => !empty($m['approved']), 'decided_at' => (string)($m['at'] ?? ''),
            'rating_parts' => isset($rp[$id]) ? ['parts' => $rp[$id]['parts'], 'notes' => $rp[$id]['notes'] ?? [], 'days' => $rp[$id]['days'] ?? 0] : null,
            'rating_lift' => (function ($x) { if (!is_array($x)) return null; $w = null; foreach ((array)$x['parts'] as $k => $v) if ($v !== null && $k !== 'consistency' && ($w === null || $v < $x['parts'][$w])) $w = $k; return $w && function_exists('rating_fix_text') ? ['part' => $w, 'text' => rating_fix_text($w)] : null; })($rp[$id] ?? null),
            'metrics' => $metrics[$id] ?? (object)[], 'remarks' => $rem[$id] ?? [],
            'daily' => (function ($l) { krsort($l); return array_values($l); })($daily[$id] ?? []), 'rank' => 0, 'medals' => []];
    }
    return ['rows' => array_values(tb_rank($out)), 'metric_days' => $mdays];
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
    wq_meta_put($pdo, 'tb:tick_at', date('Y-m-d H:i:s')); $n = 0;
    foreach (['today', 'month'] as $r) { try { tb_light_cached($pdo, tb_range($pdo, $r, '', ''), true); $n++; } catch (Throwable $e) { error_log('tb_tick: ' . $e->getMessage()); } }
    return ['built' => $n];
}

}

$__tbA = (string)($action ?? ($_GET['action'] ?? ''));
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
