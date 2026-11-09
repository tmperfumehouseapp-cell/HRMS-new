<?php
/**
 * wq_pace.php — ⚡ REPLY PACE of every salesperson. Loaded by wa_more.php right after wq_rescue_more.php. PHP 7.4 safe.
 * Creates only one small cache table (wq_pace_cache). Reads wa_messages, wa_assign_log, wa_queries, wq_qstat; changes nothing in them.
 *
 * What "pace" means
 *   Every time a customer writes and nobody has answered yet, a clock starts (the FIRST unanswered customer message). The next message we send stops it.
 *   That gap, in minutes, is one "reply pace" reading. It is credited to whoever sent the answer:
 *     person  = a message typed / sent by a salesperson (who 'emp', with their employee id)   -> counts for that salesperson
 *     bot     = the auto-bot, holding message or a flow (who 'bot' / 'auto' / 'flow')         -> counted for the team, never for a person
 *   Gaps of 8 hours or more are the shop being closed (night / next day): they are counted as "next day" and left out of the averages.
 *   Averages use a gap capped at 120 minutes so one forgotten chat cannot ruin a whole week.
 *   Grades by median: Fast <= 3 min, Good <= 10, Slow <= 30, Very slow > 30.
 *
 * Also: "after taking a query" (grab / rescue / take over / given): minutes from the moment the chat became theirs to their first message.
 *       "does fast reply close faster?": queries grouped by first-person-reply speed -> orders %, and time to the first order.
 *
 * Action: wq.pace  GET  days=1|7|30  [emp_id]  - admin: everyone; a salesperson: only their own numbers + the team median (no names of others).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wq_pace_calc')) {

function wq_pace_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wq_pace_cache (k VARCHAR(40) NOT NULL PRIMARY KEY, js MEDIUMTEXT NULL, at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
function wq_pace_grade($median): string
{
    if ($median === null) return 'none';
    return $median <= 3 ? 'fast' : ($median <= 10 ? 'good' : ($median <= 30 ? 'slow' : 'veryslow'));
}
function wq_pace_med(array $a)
{
    if (!$a) return null; sort($a); $n = count($a);
    return $n % 2 ? (float)$a[intdiv($n, 2)] : ((float)$a[$n / 2 - 1] + (float)$a[$n / 2]) / 2;
}
/** One summary block from a list of gaps (minutes). */
function wq_pace_sum(array $g, int $nextDay = 0): array
{
    $n = count($g); if ($n === 0) return ['n' => 0, 'median' => null, 'avg' => null, 'fast_pct' => null, 'slow_pct' => null, 'next_day' => $nextDay, 'grade' => 'none'];
    $s = 0.0; $f = 0; $sl = 0; foreach ($g as $x) { $s += min(120.0, $x); if ($x <= 5) $f++; if ($x > 30) $sl++; }
    $m = wq_pace_med($g);
    return ['n' => $n, 'median' => round($m, 1), 'avg' => round($s / $n, 1), 'fast_pct' => (int)round(100 * $f / $n), 'slow_pct' => (int)round(100 * $sl / $n), 'next_day' => $nextDay, 'grade' => wq_pace_grade($m)];
}
function wq_pace_how_label(string $how): string
{
    $m = ['grab' => 'Late-reply pickup', 'rescue' => 'Rescue query', 'takeover' => 'Taken over', 'manual' => 'Given by a manager', 'auto' => 'Given automatically', 'transfer' => 'Transferred'];
    return $m[$how] ?? ($how === '' ? 'Other' : ucfirst($how));
}

/** All the numbers for a period. days = 1 (today) | 7 | 30. */
function wq_pace_calc(PDO $pdo, int $days): array
{
    $days = in_array($days, [1, 7, 30], true) ? $days : 7;
    $today = strtotime(date('Y-m-d 00:00:00')); $span = max($days, 7);   // the daily graph always shows at least 7 days
    $curFrom = $days === 1 ? $today : $today - ($days - 1) * 86400; $prevFrom = $curFrom - $days * 86400;
    $scanFrom = min($prevFrom, $today - ($span - 1) * 86400);
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];

    // ---- 1. every answer gap, one pass over the messages, chat by chat
    $emp = []; $botG = []; $botDay = []; $team = ['cur' => [], 'prev' => []]; $nextDay = ['cur' => 0, 'prev' => 0]; $empNext = [];
    $st = $pdo->prepare("SELECT phone, dir, who, emp_id, ts FROM wa_messages WHERE ts >= ? AND dir IN ('in','out') ORDER BY phone, id LIMIT 900000");
    $st->execute([date('Y-m-d H:i:s', $scanFrom)]);
    $curPhone = null; $pend = 0;
    while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
        if ($r[0] !== $curPhone) { $curPhone = $r[0]; $pend = 0; }
        $t = strtotime((string)$r[4]); if (!$t) continue;
        if ($r[1] === 'in') { if ($pend === 0) $pend = $t; continue; }
        if ($pend === 0) continue;                                    // a message we sent on our own, nobody was waiting
        $who = (string)$r[2]; $e = (int)$r[3];
        $isPerson = $who === 'emp' && $e > 0; $isBot = in_array($who, ['bot', 'auto', 'flow'], true);
        if (!$isPerson && !$isBot) { continue; }                      // campaigns / system lines neither start nor stop the clock
        $gap = max(0, $t - $pend) / 60.0; $pend = 0;
        $per = $t >= $curFrom ? 'cur' : ($t >= $prevFrom && $t < $curFrom ? 'prev' : '');
        $inSeries = $t >= $today - ($span - 1) * 86400;
        if ($gap >= 480) { if ($isPerson && $per !== '') { $nextDay[$per]++; if ($per === 'cur') $empNext[$e] = ($empNext[$e] ?? 0) + 1; } continue; }
        $day = date('Y-m-d', $t);
        if ($isBot) { if ($per === 'cur') $botG[] = $gap; if ($inSeries) $botDay[$day][] = $gap; continue; }
        if (!isset($emp[$e])) $emp[$e] = ['cur' => [], 'prev' => [], 'day' => []];
        if ($per !== '') { $emp[$e][$per][] = $gap; $team[$per][] = $gap; }
        if ($inSeries) $emp[$e]['day'][$day][] = $gap;
    }

    // ---- 2. days of the graph
    $dayList = []; for ($i = $span - 1; $i >= 0; $i--) $dayList[] = date('Y-m-d', $today - $i * 86400);
    $teamDay = []; foreach ($dayList as $d) { $all = []; foreach ($emp as $x) if (!empty($x['day'][$d])) $all = array_merge($all, $x['day'][$d]); $teamDay[] = ['day' => $d, 'n' => count($all), 'median' => $all ? round(wq_pace_med($all), 1) : null]; }
    $botSeries = []; foreach ($dayList as $d) $botSeries[] = ['day' => $d, 'n' => count($botDay[$d] ?? []), 'median' => !empty($botDay[$d]) ? round(wq_pace_med($botDay[$d]), 1) : null];

    // ---- 3. after taking a query (grab / rescue / take over / given)
    $take = []; $takeAll = [];
    try {
        $q = $pdo->prepare("SELECT l.to_emp, l.how, l.at, (SELECT MIN(m.ts) FROM wa_messages m WHERE m.phone = l.phone AND m.dir = 'out' AND m.who = 'emp' AND m.emp_id = l.to_emp AND m.ts >= l.at) fr
            FROM wa_assign_log l WHERE l.at >= ? AND l.to_emp > 0 ORDER BY l.id DESC LIMIT 6000");
        $q->execute([date('Y-m-d H:i:s', $curFrom)]);
        foreach ($q->fetchAll() as $r) {
            $e = (int)$r['to_emp']; $h = (string)$r['how']; $a = strtotime((string)$r['at']); if (!$a) continue;
            if (!isset($take[$e])) $take[$e] = ['by' => [], 'all' => [], 'none' => 0];
            if (empty($r['fr'])) { if ($a < time() - 7200) $take[$e]['none']++; continue; }
            $m = max(0, (strtotime((string)$r['fr']) - $a) / 60.0); if ($m >= 480) continue;
            $take[$e]['by'][$h][] = $m; $take[$e]['all'][] = $m; $takeAll[$h][] = $m;
        }
    } catch (Throwable $e) { }

    // ---- 4. does a fast first reply close the order faster?  (always the last 30 days, so there is enough to compare)
    $ord = ['buckets' => [], 'by_emp' => [], 'since' => date('Y-m-d', $today - 29 * 86400)];
    try {
        $o = $pdo->prepare("SELECT q.emp_id, s.first_person_min fpm, s.orders, TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at) closemin FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= ? LIMIT 40000");
        $o->execute([date('Y-m-d H:i:s', $today - 29 * 86400)]);
        $B = ['fast' => [0, 0, []], 'mid' => [0, 0, []], 'slow' => [0, 0, []], 'none' => [0, 0, []]]; $E = [];
        foreach ($o->fetchAll() as $r) {
            $f = $r['fpm']; $k = $f === null ? 'none' : ((float)$f <= 5 ? 'fast' : ((float)$f <= 30 ? 'mid' : 'slow')); $won = (int)$r['orders'] > 0;
            $B[$k][0]++; if ($won) { $B[$k][1]++; if ($r['closemin'] !== null && (int)$r['closemin'] >= 0) $B[$k][2][] = (int)$r['closemin']; }
            $e = (int)$r['emp_id']; if ($e > 0) { if (!isset($E[$e])) $E[$e] = [0, 0, [], ['fast' => [0, 0], 'mid' => [0, 0], 'slow' => [0, 0], 'none' => [0, 0]]]; $E[$e][0]++; $E[$e][3][$k][0]++; if ($won) { $E[$e][1]++; $E[$e][3][$k][1]++; if ($r['closemin'] !== null && (int)$r['closemin'] >= 0) $E[$e][2][] = (int)$r['closemin']; } }
        }
        $lab = ['fast' => 'Answered in 5 min or less', 'mid' => 'Answered in 5 - 30 min', 'slow' => 'Answered after 30 min', 'none' => 'No person answered'];
        foreach ($B as $k => $v) $ord['buckets'][] = ['key' => $k, 'label' => $lab[$k], 'queries' => $v[0], 'orders' => $v[1], 'conversion' => $v[0] > 0 ? round(100 * $v[1] / $v[0], 1) : null, 'close_median_min' => $v[2] ? (int)round(wq_pace_med($v[2])) : null];
        foreach ($E as $e => $v) { $bk = []; foreach ($v[3] as $kk => $z) $bk[$kk] = ['queries' => $z[0], 'orders' => $z[1], 'conversion' => $z[0] > 0 ? round(100 * $z[1] / $z[0], 1) : null];
            $ord['by_emp'][$e] = ['queries' => $v[0], 'orders' => $v[1], 'conversion' => $v[0] > 0 ? round(100 * $v[1] / $v[0], 1) : null, 'close_median_min' => $v[2] ? (int)round(wq_pace_med($v[2])) : null, 'buckets' => $bk]; }
        $fa = $ord['buckets'][0]; $sl = $ord['buckets'][2];
        $ord['verdict'] = ['fast_vs_slow_conversion' => ($fa['conversion'] !== null && $sl['conversion'] !== null && $sl['conversion'] > 0) ? round($fa['conversion'] / $sl['conversion'], 2) : null,
            'fast_vs_slow_close' => ($fa['close_median_min'] && $sl['close_median_min']) ? round($sl['close_median_min'] / $fa['close_median_min'], 2) : null,
            'enough' => $fa['queries'] >= 20 && $sl['queries'] >= 20];
    } catch (Throwable $e) { $ord['error'] = 'not available'; }

    // ---- 5. people
    $ids = array_keys($emp); foreach ($take as $e => $_) $ids[] = $e; foreach ($ord['by_emp'] as $e => $_) $ids[] = $e;
    if (function_exists('wq_sales_people')) { try { foreach (wq_sales_people($pdo) as $p) $ids[] = (int)$p['employee_id']; } catch (Throwable $e) { } }
    $people = [];
    foreach (array_unique($ids) as $e) {
        $e = (int)$e; if ($e <= 0) continue;
        $x = $emp[$e] ?? ['cur' => [], 'prev' => [], 'day' => []];
        $cur = wq_pace_sum($x['cur'], $empNext[$e] ?? 0); $prev = wq_pace_sum($x['prev']);
        $delta = ($cur['median'] !== null && $prev['median'] !== null) ? round($cur['median'] - $prev['median'], 1) : null;   // minus = got faster
        $ser = []; foreach ($dayList as $d) { $g = $x['day'][$d] ?? []; $ser[] = ['day' => $d, 'n' => count($g), 'median' => $g ? round(wq_pace_med($g), 1) : null]; }
        $tk = $take[$e] ?? ['by' => [], 'all' => [], 'none' => 0]; $tb = [];
        foreach ($tk['by'] as $h => $g) $tb[] = ['how' => $h, 'label' => wq_pace_how_label((string)$h), 'n' => count($g), 'median' => round(wq_pace_med($g), 1), 'avg' => round(array_sum(array_map(function ($v) { return min(120.0, $v); }, $g)) / count($g), 1)];
        $oe = $ord['by_emp'][$e] ?? null;
        $people[] = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'pace' => $cur, 'prev' => $prev, 'delta' => $delta, 'series' => $ser,
            'after_take' => ['n' => count($tk['all']), 'median' => $tk['all'] ? round(wq_pace_med($tk['all']), 1) : null, 'no_reply' => $tk['none'], 'by' => $tb],
            'orders' => $oe];
    }
    // ranking: fastest median first; people with fewer than 5 readings are listed last (not enough to judge)
    usort($people, function ($a, $b) {
        $ea = $a['pace']['n'] >= 5 ? 0 : 1; $eb = $b['pace']['n'] >= 5 ? 0 : 1; if ($ea !== $eb) return $ea <=> $eb;
        $ma = $a['pace']['median'] ?? 99999; $mb = $b['pace']['median'] ?? 99999; return $ma <=> $mb;
    });
    $rk = 0; foreach ($people as &$p) { $p['rank'] = $p['pace']['n'] >= 5 ? ++$rk : null; } unset($p);

    $takeTeam = []; foreach ($takeAll as $h => $g) $takeTeam[] = ['how' => $h, 'label' => wq_pace_how_label((string)$h), 'n' => count($g), 'median' => round(wq_pace_med($g), 1)];
    return ['ok' => true, 'days' => $days, 'from' => date('Y-m-d', $curFrom), 'to' => date('Y-m-d'), 'built' => date('Y-m-d H:i:s'),
        'rules' => ['grades' => ['fast' => 3, 'good' => 10, 'slow' => 30], 'cap_min' => 120, 'next_day_hours' => 8, 'min_readings' => 5],
        'team' => ['pace' => wq_pace_sum($team['cur'], $nextDay['cur']), 'prev' => wq_pace_sum($team['prev']), 'series' => $teamDay, 'bot' => wq_pace_sum($botG), 'bot_series' => $botSeries],
        'people' => $people, 'after_take_team' => $takeTeam, 'orders' => $ord];
}

/** Cached for 2 minutes per period (the scan reads a lot of messages). */
function wq_pace_get(PDO $pdo, int $days): array
{
    $k = 'pace_' . $days;
    if (wq_pace_tables($pdo)) {
        try { $q = $pdo->prepare("SELECT js, at FROM wq_pace_cache WHERE k = ?"); $q->execute([$k]); $r = $q->fetch();
            if ($r && $r['js'] && strtotime((string)$r['at']) > time() - 120) { $j = json_decode((string)$r['js'], true); if (is_array($j)) return $j; } } catch (Throwable $e) { }
    }
    $d = wq_pace_calc($pdo, $days);
    if (wq_pace_tables($pdo)) { try { $pdo->prepare("REPLACE INTO wq_pace_cache (k, js, at) VALUES (?,?,?)")->execute([$k, json_encode($d, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]); } catch (Throwable $e) { } }
    return $d;
}
/** A salesperson sees their own line, the team median and their own rank - never other people's names or numbers. */
function wq_pace_for_self(array $d, int $me): array
{
    $mine = null; $total = 0;
    foreach ($d['people'] as $p) { if ($p['rank'] !== null) $total++; if ((int)$p['emp_id'] === $me) $mine = $p; }
    unset($d['people'], $d['orders']['by_emp']);
    return $d + ['mine' => $mine, 'ranked' => $total, 'self' => true];
}

// ------------------------------------------------------------------------------------------------------------------ router
$__wqp = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wqp === 'wq.pace') {
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') json_response(['error' => 'Method not allowed.'], 405);
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    try {
        $dd = (int)($_GET['days'] ?? 7); $d = wq_pace_get($pdo, $dd);
        $adm = function_exists('wa_is_admin') && wa_is_admin($pdo, $auth);
        json_response($adm ? $d + ['self' => false] : wq_pace_for_self($d, $me));
    } catch (Throwable $e) { error_log('wq_pace: ' . $e->getMessage()); json_response(['error' => 'Pace: ' . mb_substr($e->getMessage(), 0, 200)], 500); }
}
}
