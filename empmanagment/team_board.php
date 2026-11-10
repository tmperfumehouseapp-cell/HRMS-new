<?php
/**
 * 👥 TEAM BOARD — one row per salesperson with EVERYTHING in one place: today (punch-in, hours, target), the last 30 days (queries, orders, ratio, ticket, speed),
 * behaviour (tone, complaints, late replies, penalties), the daily rating, attendance (days, hours, orders per hour), rank + medals and the Team manager's
 * decision. Built by joining what already exists (wqm_collect in wq_manager.php, coach_day in api.php, attendance) — no second calculation of the same thing.
 *   team.board   GET   admin → {rows:[…], team:{…}, ran_at, mode}
 * Pay and commission are NOT here (they stay in Money, admin only). PHP 7.4 safe.
 */
if (!function_exists('tb_build')) {

function tb_build(PDO $pdo): array
{
    $rows = function_exists('wqm_collect') ? wqm_collect($pdo) : []; $out = [];
    $att = []; $daily = []; $since = date('Y-m-d H:i:s', time() - 30 * 86400); $todayStart = date('Y-m-d 00:00:00');
    try {
        $q = $pdo->prepare("SELECT employee_id, DATE(punch_in) d, MIN(punch_in) first_in, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? GROUP BY employee_id, DATE(punch_in)"); $q->execute([$since]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = (int)$r['employee_id']; $a = &$att[$e]; if (!$a) $a = ['days' => 0, 'mins' => 0, 'today_in' => '', 'today_mins' => 0]; $a['days']++; $a['mins'] += max(0, (int)$r['mins']); $daily[$e][(string)$r['d']] = ['d' => (string)$r['d'], 'in' => substr((string)$r['first_in'], 11, 5), 'h' => round(max(0, (int)$r['mins']) / 60, 1), 'score' => null, 'hit' => null]; if ((string)$r['d'] === date('Y-m-d')) { $a['today_in'] = substr((string)$r['first_in'], 11, 5); $a['today_mins'] = max(0, (int)$r['mins']); } unset($a); }
    } catch (Throwable $e) { }
    try {   // the daily rating (score and "target hit") day by day
        if (function_exists('rating_days')) foreach (rating_days($pdo, date('Y-m-d', time() - 29 * 86400), date('Y-m-d')) as $d => $list) foreach ((array)$list as $e => $x) { $e = (int)$e; if (!isset($daily[$e][$d])) $daily[$e][$d] = ['d' => (string)$d, 'in' => '', 'h' => 0, 'score' => null, 'hit' => null]; $daily[$e][$d]['score'] = isset($x['score']) ? round((float)$x['score'], 1) : null; $daily[$e][$d]['hit'] = !empty($x['has_target']) ? !empty($x['hit']) : null; }
    } catch (Throwable $e) { }
    $cd = []; try { if (function_exists('coach_day') && function_exists('biz_today')) $cd = (array)(coach_day($pdo, biz_today($pdo))['emps'] ?? []); } catch (Throwable $e) { }
    $rp = []; $rdays = [];   // the 10 rating parts (30-day averages) with notes and the "biggest lift"
    try { if (function_exists('rating_days') && function_exists('rating_period')) { $rdays = rating_days($pdo, date('Y-m-d', time() - 29 * 86400), date('Y-m-d')); foreach ($rows as $id => $r0) { $x = rating_period($rdays, $id); if (is_array($x)) $rp[$id] = $x; } } } catch (Throwable $e) { }
    $metrics = []; $mdays = [];   // the metric blocks of the old Person tab (campaign orders, prepaid, codes, mix, discount slabs, late) for the last 7 days
    try { if (function_exists('cp_day_rows')) { $d0 = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); for ($i = 0; $i < 7; $i++) { $d = date('Y-m-d', strtotime($d0 . ' -' . $i . ' day')); $mdays[] = $d; foreach ((array)cp_day_rows($pdo, $d) as $e => $x) $metrics[(int)$e][$d] = $x; } } } catch (Throwable $e) { }
    $rem = []; try { foreach ($pdo->query("SELECT employee_id, from_name, src, body, created_at, seen_at FROM ct_remarks WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "' ORDER BY id DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC) as $x) { $e = (int)$x['employee_id']; if (count($rem[$e] ?? []) < 3) $rem[$e][] = ['by' => (string)$x['from_name'], 'text' => mb_substr((string)$x['body'], 0, 300), 'at' => (string)$x['created_at'], 'seen' => !empty($x['seen_at'])]; } } catch (Throwable $e) { }
    foreach ($rows as $id => $r) {
        $a = $att[$id] ?? ['days' => 0, 'mins' => 0, 'today_in' => '', 'today_mins' => 0]; $c = $cd[$id] ?? [];
        $hrs = round($a['mins'] / 60, 1); $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : [];
        $out[$id] = $r + ['days_30d' => $a['days'], 'hours_30d' => $hrs, 'avg_hours_day' => $a['days'] > 0 ? round($hrs / $a['days'], 1) : null, 'orders_per_hour' => $hrs > 0 ? round($r['orders_30d'] / $hrs, 2) : null,
            'punch_in_today' => $a['today_in'], 'hours_today' => round($a['today_mins'] / 60, 1),
            'target_today' => isset($c['target']) ? (float)$c['target'] : null, 'sales_today' => isset($c['sales']) ? (float)$c['sales'] : null, 'target_pct_today' => $c['pct'] ?? null, 'should_pct_today' => $c['should_pct'] ?? null,
            'level' => (string)($m['level'] ?? ''), 'auto_level' => (string)($m['auto_level'] ?? ''), 'manual' => (string)($m['manual'] ?? ''), 'card' => (string)($m['card'] ?? ''), 'reason' => (string)($m['reason'] ?? ''), 'meet_admin' => !empty($m['meet_admin']), 'approved' => !empty($m['approved']), 'decided_at' => (string)($m['at'] ?? ''),
            'rating_parts' => isset($rp[$id]) ? ['parts' => $rp[$id]['parts'], 'notes' => $rp[$id]['notes'] ?? [], 'days' => $rp[$id]['days'] ?? 0] : null,
            'rating_lift' => (function ($x) { if (!is_array($x)) return null; $w = null; foreach ((array)$x['parts'] as $k => $v) if ($v !== null && $k !== 'consistency' && ($w === null || $v < $x['parts'][$w])) $w = $k; return $w && function_exists('rating_fix_text') ? ['part' => $w, 'text' => rating_fix_text($w)] : null; })($rp[$id] ?? null),
            'metrics' => $metrics[$id] ?? (object)[], 'remarks' => $rem[$id] ?? [],
            'daily' => (function ($l) { krsort($l); return array_values($l); })($daily[$id] ?? []), 'rank' => 0, 'medals' => []];
    }
    // rank by orders (30 days) and medals: most orders, fastest, biggest ticket, best ratio — among people who have the data
    $ids = array_keys($out); usort($ids, function ($x, $y) use ($out) { return [$out[$y]['orders_30d'], $out[$x]['name']] <=> [$out[$x]['orders_30d'], $out[$y]['name']]; });
    $i = 0; foreach ($ids as $id) $out[$id]['rank'] = ++$i;
    $best = function (string $k, bool $low, int $minQ) use (&$out) { $bi = 0; $bv = null; foreach ($out as $id => $r) { $v = $r[$k]; if ($v === null || $r['queries_30d'] < $minQ) continue; if ($bv === null || ($low ? $v < $bv : $v > $bv)) { $bv = $v; $bi = $id; } } return $bv !== null && ($low ? $bv > 0 : $bv > 0) ? $bi : 0; };
    $mo = $best('orders_30d', false, 1); if ($mo) $out[$mo]['medals'][] = '🥇 Most orders';
    $ms = $best('speed_median_min_30d', true, 3); if ($ms) $out[$ms]['medals'][] = '⚡ Fastest';
    $mt = $best('ticket_30d', false, 1); if ($mt) $out[$mt]['medals'][] = '💎 Biggest ticket';
    $mr = $best('ratio_pct', false, 3); if ($mr) $out[$mr]['medals'][] = '🎯 Best ratio';
    return array_values($out);
}

}

$__tbA = (string)($action ?? ($_GET['action'] ?? ''));
if ($__tbA === 'team.board') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !function_exists('wq_tables') || !wq_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    @set_time_limit(60); $__c = json_decode(wq_meta_get($pdo, 'tb:cache', ''), true);   // shared for 90 seconds: the board is heavy and every admin opens the same one
    if (is_array($__c) && (int)($__c['at'] ?? 0) > time() - 90 && empty($_GET['fresh']) && isset($__c['rows'])) $rows = $__c['rows'];
    else { $rows = tb_build($pdo); wq_meta_put($pdo, 'tb:cache', json_encode(['at' => time(), 'rows' => $rows], JSON_UNESCAPED_UNICODE)); }
    $r0 = $rows ? $rows[0] : [];
    json_response(['ok' => true, 'rows' => $rows, 'team' => ['ratio_pct' => $r0['team_ratio_pct'] ?? null, 'ticket' => $r0['team_ticket'] ?? null, 'people' => count($rows), 'at_work' => count(array_filter($rows, function ($x) { return !empty($x['present']); }))],
        'rating_labels' => function_exists('rating_parts') ? rating_parts() : (object)[], 'metric_days' => (function_exists('biz_today') ? array_map(function ($i) use ($pdo) { return date('Y-m-d', strtotime(biz_today($pdo) . ' -' . $i . ' day')); }, range(0, 6)) : []),
        'summary' => wq_meta_get($pdo, 'mgr:summary', ''), 'ran_at' => wq_meta_get($pdo, 'mgr:at', ''), 'mode' => function_exists('wqm_mode') ? wqm_mode($pdo) : 'suggest', 'levels' => function_exists('wqm_levels') ? wqm_levels() : []]);
}
