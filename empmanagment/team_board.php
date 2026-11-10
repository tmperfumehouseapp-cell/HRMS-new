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
    $att = []; $since = date('Y-m-d H:i:s', time() - 30 * 86400); $todayStart = date('Y-m-d 00:00:00');
    try {
        $q = $pdo->prepare("SELECT employee_id, DATE(punch_in) d, MIN(punch_in) first_in, SUM(LEAST(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, NOW())), 960)) mins FROM attendance WHERE punch_in IS NOT NULL AND punch_in >= ? GROUP BY employee_id, DATE(punch_in)"); $q->execute([$since]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $e = (int)$r['employee_id']; $a = &$att[$e]; if (!$a) $a = ['days' => 0, 'mins' => 0, 'today_in' => '', 'today_mins' => 0]; $a['days']++; $a['mins'] += max(0, (int)$r['mins']); if ((string)$r['d'] === date('Y-m-d')) { $a['today_in'] = substr((string)$r['first_in'], 11, 5); $a['today_mins'] = max(0, (int)$r['mins']); } unset($a); }
    } catch (Throwable $e) { }
    $cd = []; try { if (function_exists('coach_day') && function_exists('biz_today')) $cd = (array)(coach_day($pdo, biz_today($pdo))['emps'] ?? []); } catch (Throwable $e) { }
    foreach ($rows as $id => $r) {
        $a = $att[$id] ?? ['days' => 0, 'mins' => 0, 'today_in' => '', 'today_mins' => 0]; $c = $cd[$id] ?? [];
        $hrs = round($a['mins'] / 60, 1); $m = function_exists('wqm_rec') ? wqm_rec($pdo, $id) : [];
        $out[$id] = $r + ['days_30d' => $a['days'], 'hours_30d' => $hrs, 'avg_hours_day' => $a['days'] > 0 ? round($hrs / $a['days'], 1) : null, 'orders_per_hour' => $hrs > 0 ? round($r['orders_30d'] / $hrs, 2) : null,
            'punch_in_today' => $a['today_in'], 'hours_today' => round($a['today_mins'] / 60, 1),
            'target_today' => isset($c['target']) ? (float)$c['target'] : null, 'sales_today' => isset($c['sales']) ? (float)$c['sales'] : null, 'target_pct_today' => $c['pct'] ?? null, 'should_pct_today' => $c['should_pct'] ?? null,
            'level' => (string)($m['level'] ?? ''), 'auto_level' => (string)($m['auto_level'] ?? ''), 'manual' => (string)($m['manual'] ?? ''), 'card' => (string)($m['card'] ?? ''), 'reason' => (string)($m['reason'] ?? ''), 'meet_admin' => !empty($m['meet_admin']), 'approved' => !empty($m['approved']), 'decided_at' => (string)($m['at'] ?? ''),
            'rank' => 0, 'medals' => []];
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
        'summary' => wq_meta_get($pdo, 'mgr:summary', ''), 'ran_at' => wq_meta_get($pdo, 'mgr:at', ''), 'mode' => function_exists('wqm_mode') ? wqm_mode($pdo) : 'suggest', 'levels' => function_exists('wqm_levels') ? wqm_levels() : []]);
}
