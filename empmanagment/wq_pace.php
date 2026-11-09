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

/** "ok", "hmm", "haan", a lone emoji ... is not an answer: it does not stop the clock and is not counted as a fast reply. Photos, voice notes and documents always count. */
function wq_pace_trivial(string $mtype, string $body): bool
{
    if ($mtype !== '' && $mtype !== 'text') return false;
    $t = mb_strtolower(trim($body)); if ($t === '') return false;
    $w = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t));
    if ($w === '') return true;                                        // only emojis / punctuation
    return in_array($w, ['ok', 'okay', 'okk', 'okkk', 'k', 'kk', 'hmm', 'hmmm', 'hm', 'mm', 'mmm', 'yes', 'yeah', 'yep', 'haan', 'ha', 'han', 'ji', 'hanji', 'haji', 'haan ji', 'han ji', 'ha ji', 'haan haan', 'theek', 'thik', 'thik hai', 'theek hai', 'ok ok', 'ok sir', 'ok mam', 'ok madam', 'ok ji', 'ok thanks', 'thanks', 'thank you', 'ty'], true);
}

/** Speed AND orders together: star = fast + orders fine, ok = slow but orders fine, quality = fast but orders low, red = slow and orders low.
 *  Slow = typical reply over 10 min. Orders low = under 85% of the team's order rate (30 days). Needs 5 replies and 10 queries, else "few". */
function wq_pace_verdict($median, int $replies, $queries, $conv, $teamConv): array
{
    if ($median === null || $replies < 5 || (int)$queries < 10 || $conv === null || $teamConv === null || $teamConv <= 0) return ['key' => 'few', 'label' => 'Not enough data yet', 'why' => 'Needs 5 replies and 10 queries to judge.'];
    $slow = $median > 10; $low = $conv < 0.85 * $teamConv;
    if (!$slow && !$low) return ['key' => 'star', 'label' => '⭐ Fast and winning orders', 'why' => 'Typical reply ' . round($median, 1) . ' min, orders ' . $conv . '% (team ' . round($teamConv, 1) . '%).'];
    if ($slow && !$low) return ['key' => 'ok', 'label' => 'Slow, but orders are fine', 'why' => 'Typical reply ' . round($median, 1) . ' min, but orders ' . $conv . '% (team ' . round($teamConv, 1) . '%). No problem for now.'];
    if (!$slow && $low) return ['key' => 'quality', 'label' => '🟠 Fast, but orders are low', 'why' => 'Replies fast (' . round($median, 1) . ' min) but orders ' . $conv . '% (team ' . round($teamConv, 1) . '%). Check what is being said, not the speed.'];
    return ['key' => 'red', 'label' => '🔴 Slow and orders low', 'why' => 'Typical reply ' . round($median, 1) . ' min and orders ' . $conv . '% (team ' . round($teamConv, 1) . '%). Needs coaching.'];
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
    // Complaint chats: the complaint team answers them, so those answers are NOT the salesperson's pace. A gap is left out when the customer wrote, or we answered, while the chat had an open complaint.
    $cx = [];
    try {
        $cq = $pdo->prepare("SELECT phone, raised_at, status, COALESCE(closed_at, resolved_at, updated_at) done FROM cx_cases WHERE phone <> '' AND raised_at <= ? AND (status IN ('open','reopened') OR COALESCE(closed_at, resolved_at, updated_at) >= ?)");
        $cq->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $scanFrom - 86400)]);
        foreach ($cq->fetchAll() as $x) { $a = strtotime((string)$x['raised_at']); $b = in_array((string)$x['status'], ['open', 'reopened'], true) ? PHP_INT_MAX : (int)strtotime((string)$x['done']); $cx[substr(preg_replace('/\D+/', '', (string)$x['phone']), -10)][] = [$a, $b]; }
    } catch (Throwable $e) { $cx = []; }
    $inCx = function (string $ph, int $t1, int $t2) use (&$cx): bool { foreach ($cx[substr($ph, -10)] ?? [] as $iv) { if (($t1 >= $iv[0] && $t1 <= $iv[1]) || ($t2 >= $iv[0] && $t2 <= $iv[1])) return true; } return false; };
    $cxSkipped = 0;
    $st = $pdo->prepare("SELECT phone, dir, who, emp_id, ts, mtype, LEFT(body, 40) FROM wa_messages WHERE ts >= ? AND dir IN ('in','out') ORDER BY phone, id LIMIT 900000");
    $st->execute([date('Y-m-d H:i:s', $scanFrom)]);
    $curPhone = null; $pend = 0;
    while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
        if ($r[0] !== $curPhone) { $curPhone = $r[0]; $pend = 0; }
        $t = strtotime((string)$r[4]); if (!$t) continue;
        if ($r[1] === 'in') { if ($pend === 0) $pend = $t; continue; }
        if ($pend === 0) continue;                                    // a message we sent on our own, nobody was waiting
        $who = (string)$r[2]; $e = (int)$r[3];
        $isPerson = $who === 'emp' && $e > 0; $isBot = in_array($who, ['bot', 'auto', 'flow'], true);
        if (!$isPerson && !$isBot) { continue; }
        if ($isPerson && wq_pace_trivial((string)$r[5], (string)$r[6])) continue;   // a bare "ok" / "hmm" is not an answer: the customer is still waiting                      // campaigns / system lines neither start nor stop the clock
        $gap = max(0, $t - $pend) / 60.0; $p0 = $pend; $pend = 0;
        if ($cx && $isPerson && $inCx((string)$curPhone, $p0, $t)) { $cxSkipped++; continue; }   // a complaint reply: counted by the complaint team, not here
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
    $teamDay = []; foreach ($dayList as $d) { $all = []; foreach ($emp as $x) if (!empty($x['day'][$d])) $all = array_merge($all, $x['day'][$d]); $teamDay[] = ['day' => $d, 'n' => count($all), 'median' => $all ? round(wq_pace_med($all), 1) : null, 'avg' => $all ? round(array_sum(array_map(function ($v) { return min(120.0, $v); }, $all)) / count($all), 1) : null]; }
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
        $o = $pdo->prepare("SELECT q.emp_id, s.first_person_min fpm, s.orders, s.revenue rev, TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at) closemin FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= ? LIMIT 40000");
        $o->execute([date('Y-m-d H:i:s', $today - 29 * 86400)]);
        $B = ['fast' => [0, 0, []], 'mid' => [0, 0, []], 'slow' => [0, 0, []], 'none' => [0, 0, []]]; $E = []; $cT = [0, 0, 0, 0, 0, 0]; $cE = []; $revSum = 0.0; $ordSum = 0;
        $acc = function (array &$s, float $x, float $y) { $s[0]++; $s[1] += $x; $s[2] += $y; $s[3] += $x * $x; $s[4] += $y * $y; $s[5] += $x * $y; };
        foreach ($o->fetchAll() as $r) {
            $f = $r['fpm']; $k = $f === null ? 'none' : ((float)$f <= 5 ? 'fast' : ((float)$f <= 30 ? 'mid' : 'slow')); $won = (int)$r['orders'] > 0;
            if ($f !== null) { $x = log(1 + max(0.0, (float)$f)); $y = $won ? 1.0 : 0.0; $acc($cT, $x, $y); $ee = (int)$r['emp_id']; if ($ee > 0) { if (!isset($cE[$ee])) $cE[$ee] = [0, 0, 0, 0, 0, 0]; $acc($cE[$ee], $x, $y); } }
            if ($won) { $revSum += (float)$r['rev']; $ordSum += (int)$r['orders']; }
            $B[$k][0]++; if ($won) { $B[$k][1]++; if ($r['closemin'] !== null && (int)$r['closemin'] >= 0) $B[$k][2][] = (int)$r['closemin']; }
            $e = (int)$r['emp_id']; if ($e > 0) { if (!isset($E[$e])) $E[$e] = [0, 0, [], ['fast' => [0, 0], 'mid' => [0, 0], 'slow' => [0, 0], 'none' => [0, 0]]]; $E[$e][0]++; $E[$e][3][$k][0]++; if ($won) { $E[$e][1]++; $E[$e][3][$k][1]++; if ($r['closemin'] !== null && (int)$r['closemin'] >= 0) $E[$e][2][] = (int)$r['closemin']; } }
        }
        $lab = ['fast' => 'Answered in 5 min or less', 'mid' => 'Answered in 5 - 30 min', 'slow' => 'Answered after 30 min', 'none' => 'No person answered'];
        foreach ($B as $k => $v) $ord['buckets'][] = ['key' => $k, 'label' => $lab[$k], 'queries' => $v[0], 'orders' => $v[1], 'conversion' => $v[0] > 0 ? round(100 * $v[1] / $v[0], 1) : null, 'close_median_min' => $v[2] ? (int)round(wq_pace_med($v[2])) : null];
        foreach ($E as $e => $v) { $bk = []; foreach ($v[3] as $kk => $z) $bk[$kk] = ['queries' => $z[0], 'orders' => $z[1], 'conversion' => $z[0] > 0 ? round(100 * $z[1] / $z[0], 1) : null];
            $ord['by_emp'][$e] = ['queries' => $v[0], 'orders' => $v[1], 'conversion' => $v[0] > 0 ? round(100 * $v[1] / $v[0], 1) : null, 'close_median_min' => $v[2] ? (int)round(wq_pace_med($v[2])) : null, 'buckets' => $bk]; }
        $fa = $ord['buckets'][0]; $sl = $ord['buckets'][2];
        $ord['avg_order_value'] = $ordSum > 0 ? round($revSum / $ordSum) : null; $ord['link'] = wq_pace_corr($cT); $ord['link_emp'] = []; foreach ($cE as $ee => $s) { $c = wq_pace_corr($s); if ($c) $ord['link_emp'][$ee] = $c; }
        $ord['verdict'] = ['fast_vs_slow_conversion' => ($fa['conversion'] !== null && $sl['conversion'] !== null && $sl['conversion'] > 0) ? round($fa['conversion'] / $sl['conversion'], 2) : null,
            'fast_vs_slow_close' => ($fa['close_median_min'] && $sl['close_median_min']) ? round($sl['close_median_min'] / $fa['close_median_min'], 2) : null,
            'enough' => $fa['queries'] >= 20 && $sl['queries'] >= 20];
    } catch (Throwable $e) { $ord['error'] = 'not available'; }

    // average minutes from "query opened" to its first order, for orders that closed in this period (shown on the Sales cards)
    $closeCur = [];
    try {
        $cq = $pdo->prepare("SELECT q.emp_id, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at)) av FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id
            WHERE s.first_order_at >= ? AND s.orders > 0 AND TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at) BETWEEN 0 AND 10080 GROUP BY q.emp_id");
        $cq->execute([date('Y-m-d H:i:s', $curFrom)]);
        foreach ($cq->fetchAll() as $r) if ((int)$r['emp_id'] > 0) $closeCur[(int)$r['emp_id']] = ['n' => (int)$r['n'], 'avg' => round((float)$r['av'], 1)];
    } catch (Throwable $e) { }

    // average minutes to close an order, per person and per day (the second line of the Pace page graphs)
    $closeDay = []; $closeTeamDay = [];
    try {
        $dq = $pdo->prepare("SELECT q.emp_id, DATE(s.first_order_at) d, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at)) av FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id
            WHERE s.first_order_at >= ? AND s.orders > 0 AND TIMESTAMPDIFF(MINUTE, s.opened_at, s.first_order_at) BETWEEN 0 AND 10080 GROUP BY q.emp_id, DATE(s.first_order_at)");
        $dq->execute([date('Y-m-d 00:00:00', $today - ($span - 1) * 86400)]);
        foreach ($dq->fetchAll() as $r) { $ee = (int)$r['emp_id']; $dd = (string)$r['d']; $n0 = (int)$r['n']; $a0 = round((float)$r['av'], 1);
            if ($ee > 0) $closeDay[$ee][$dd] = ['n' => $n0, 'avg' => $a0];
            if (!isset($closeTeamDay[$dd])) $closeTeamDay[$dd] = [0, 0.0]; $closeTeamDay[$dd][0] += $n0; $closeTeamDay[$dd][1] += $n0 * (float)$r['av']; }
    } catch (Throwable $e) { }
    $closeSeries = []; foreach ($dayList as $d) $closeSeries[] = ['day' => $d, 'n' => $closeTeamDay[$d][0] ?? 0, 'avg' => !empty($closeTeamDay[$d][0]) ? round($closeTeamDay[$d][1] / $closeTeamDay[$d][0], 1) : null];

    // ---- 5. people
    $ids = array_keys($emp); foreach ($take as $e => $_) $ids[] = $e; foreach ($ord['by_emp'] as $e => $_) $ids[] = $e; foreach ($closeCur as $e => $_) $ids[] = $e;
    if (function_exists('wq_sales_people')) { try { foreach (wq_sales_people($pdo) as $p) $ids[] = (int)$p['employee_id']; } catch (Throwable $e) { } }
    $tq = 0; $to = 0; foreach ($ord['buckets'] as $bk0) { $tq += (int)$bk0['queries']; $to += (int)$bk0['orders']; } $teamConv = $tq >= 30 ? round(100 * $to / $tq, 1) : null;
    $people = [];
    foreach (array_unique($ids) as $e) {
        $e = (int)$e; if ($e <= 0) continue;
        $x = $emp[$e] ?? ['cur' => [], 'prev' => [], 'day' => []];
        $cur = wq_pace_sum($x['cur'], $empNext[$e] ?? 0); $prev = wq_pace_sum($x['prev']);
        $delta = ($cur['median'] !== null && $prev['median'] !== null) ? round($cur['median'] - $prev['median'], 1) : null;   // minus = got faster
        $ser = []; foreach ($dayList as $d) { $g = $x['day'][$d] ?? []; $ser[] = ['day' => $d, 'n' => count($g), 'median' => $g ? round(wq_pace_med($g), 1) : null, 'avg' => $g ? round(array_sum(array_map(function ($v) { return min(120.0, $v); }, $g)) / count($g), 1) : null, 'close' => $closeDay[$e][$d] ?? null]; }
        $tk = $take[$e] ?? ['by' => [], 'all' => [], 'none' => 0]; $tb = [];
        foreach ($tk['by'] as $h => $g) $tb[] = ['how' => $h, 'label' => wq_pace_how_label((string)$h), 'n' => count($g), 'median' => round(wq_pace_med($g), 1), 'avg' => round(array_sum(array_map(function ($v) { return min(120.0, $v); }, $g)) / count($g), 1)];
        $oe = $ord['by_emp'][$e] ?? null; $loss = null;
        if ($oe && !empty($oe['buckets']['slow']['queries'])) {   // slow answers (30+ min) that did not turn into an order, compared with what this person's own fast answers (else the team's) win
            $fb = $oe['buckets']['fast']; $own = $fb['queries'] >= 5 && $fb['conversion'] !== null; $base = $own ? $fb['conversion'] : ($ord['buckets'][0]['conversion'] ?? null); $sl = $oe['buckets']['slow']; $sc = $sl['conversion'] ?? 0;
            if ($base !== null) { $lo = round($sl['queries'] * max(0, $base - $sc) / 100, 1); $loss = ['orders' => $lo, 'revenue' => $ord['avg_order_value'] ? (int)round($lo * $ord['avg_order_value']) : null, 'slow_queries' => $sl['queries'], 'basis' => $own ? 'own' : 'team']; }
        }
        $people[] = ['emp_id' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'pace' => $cur, 'prev' => $prev, 'delta' => $delta, 'series' => $ser,
            'after_take' => ['n' => count($tk['all']), 'median' => $tk['all'] ? round(wq_pace_med($tk['all']), 1) : null, 'no_reply' => $tk['none'], 'by' => $tb],
            'close' => $closeCur[$e] ?? null, 'verdict' => wq_pace_verdict($cur['median'], (int)$cur['n'], $oe['queries'] ?? 0, $oe['conversion'] ?? null, $teamConv), 'loss' => $loss, 'link' => $ord['link_emp'][$e] ?? null, 'orders' => $oe];
    }
    // ranking: fastest median first; people with fewer than 5 readings are listed last (not enough to judge)
    usort($people, function ($a, $b) {
        $ea = $a['pace']['n'] >= 5 ? 0 : 1; $eb = $b['pace']['n'] >= 5 ? 0 : 1; if ($ea !== $eb) return $ea <=> $eb;
        $ma = $a['pace']['median'] ?? 99999; $mb = $b['pace']['median'] ?? 99999; return $ma <=> $mb;
    });
    $rk = 0; foreach ($people as &$p) { $p['rank'] = $p['pace']['n'] >= 5 ? ++$rk : null; } unset($p);

    $takeTeam = []; foreach ($takeAll as $h => $g) $takeTeam[] = ['how' => $h, 'label' => wq_pace_how_label((string)$h), 'n' => count($g), 'median' => round(wq_pace_med($g), 1)];
    return ['ok' => true, 'days' => $days, 'from' => date('Y-m-d', $curFrom), 'to' => date('Y-m-d'), 'built' => date('Y-m-d H:i:s'),
        'complaint_replies_left_out' => $cxSkipped, 'cfg' => wq_pace_cfg($pdo), 'rules' => ['grades' => ['fast' => 3, 'good' => 10, 'slow' => 30], 'cap_min' => 120, 'next_day_hours' => 8, 'min_readings' => 5],
        'team' => ['pace' => wq_pace_sum($team['cur'], $nextDay['cur']), 'prev' => wq_pace_sum($team['prev']), 'series' => $teamDay, 'close_series' => $closeSeries, 'bot' => wq_pace_sum($botG), 'bot_series' => $botSeries],
        'people' => $people, 'after_take_team' => $takeTeam, 'orders' => $ord];
}

/** Targets (minutes): target = the goal for a first answer, warn = the salesperson is nudged, alert = admin is told. */
function wq_pace_cfg(PDO $pdo): array
{
    $g = function (string $k, int $d, int $lo, int $hi) use ($pdo) { $v = (int)setting_get($pdo, $k, (string)$d); return $v >= $lo && $v <= $hi ? $v : $d; };
    $t = $g('wq_pace_target', 5, 1, 120); $w = max($t + 1, $g('wq_pace_warn', 10, 2, 240)); $a = max($w + 1, $g('wq_pace_alert', 20, 3, 480));
    return ['target' => $t, 'warn' => $w, 'alert' => $a, 'on' => (string)setting_get($pdo, 'wq_pace_alerts', '1') !== '0'];
}
/** Every open chat that is given to a person and whose customer is still waiting: [phone, name, emp, in_at (first unanswered message), mins]. Chats waiting 8 h+ are the night, left out. */
function wq_pace_waiting_rows(PDO $pdo): array
{
    $now = time(); $old = date('Y-m-d H:i:s', $now - 86400); $o = [];
    $base = "SELECT phone, name, assigned_emp, last_out_at FROM wa_contacts WHERE assigned_emp > 0 AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at)";
    // chats with an open complaint belong to the complaint team (cx_id is added by the complaints module; without it only the tag is checked)
    foreach ([" AND (cx_id IS NULL OR cx_id = 0) AND (tag IS NULL OR tag <> 'complaint') ORDER BY last_in_at LIMIT 1500", " AND (tag IS NULL OR tag <> 'complaint') ORDER BY last_in_at LIMIT 1500", " ORDER BY last_in_at LIMIT 1500"] as $tail) {
        try { $q = $pdo->prepare($base . $tail); $q->execute([$old]); break; } catch (Throwable $e) { $q = null; }
    }
    if (!$q) return [];
    $first = $pdo->prepare("SELECT MIN(ts) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ? AND ts >= ?");
    foreach ($q->fetchAll() as $c) {
        $first->execute([$c['phone'], (string)($c['last_out_at'] ?: '2000-01-01 00:00:00'), $old]); $in = (string)$first->fetchColumn(); $t = $in !== '' ? strtotime($in) : 0; if (!$t) continue;
        $m = max(0, (int)floor(($now - $t) / 60)); if ($m >= 480) continue;
        $o[] = ['phone' => (string)$c['phone'], 'name' => (string)$c['name'], 'emp' => (int)$c['assigned_emp'], 'in_at' => $in, 'mins' => $m];
    }
    return $o;
}
function wq_pace_waiting(PDO $pdo): array
{
    $cfg = wq_pace_cfg($pdo); $by = [];
    foreach (wq_pace_waiting_rows($pdo) as $r) {
        $e = $r['emp']; if (!isset($by[$e])) $by[$e] = ['n' => 0, 'longest' => 0, 'over' => 0, 'items' => []];
        $by[$e]['n']++; $by[$e]['longest'] = max($by[$e]['longest'], $r['mins']); if ($r['mins'] >= $cfg['target']) $by[$e]['over']++;
        $by[$e]['items'][] = ['phone' => $r['phone'], 'name' => $r['name'], 'mins' => $r['mins']];
    }
    foreach ($by as &$x) { usort($x['items'], function ($a, $b) { return $b['mins'] <=> $a['mins']; }); $x['items'] = array_slice($x['items'], 0, 12); } unset($x);
    return ['ok' => true, 'cfg' => $cfg, 'by_emp' => $by, 'at' => date('H:i:s')];
}
function wq_pace_nudge(PDO $pdo, int $emp, int $by): array
{
    if ($emp <= 0) return ['error' => 'Choose a person.'];
    $k = 'wq_pace_nudge_' . $emp; if ((int)setting_get($pdo, $k, '0') > time() - 120) return ['error' => 'Already reminded a moment ago - wait 2 minutes.'];
    $n = 0; $long = 0; foreach (wq_pace_waiting_rows($pdo) as $r) if ($r['emp'] === $emp) { $n++; $long = max($long, $r['mins']); }
    if ($n === 0) return ['error' => 'Nobody is waiting for this person right now.'];
    setting_set($pdo, $k, (string)time());
    $sent = function_exists('ct_push') ? ct_push($pdo, [$emp], '⏰ ' . $n . ' customer' . ($n > 1 ? 's are' : ' is') . ' waiting', 'Longest wait ' . $long . ' min. Please reply now.', '/my_chats.html?tab=chats', 'wq-pace-nudge') : 0;
    return ['ok' => true, 'waiting' => $n, 'longest' => $long, 'sent' => $sent];
}
/** Runs with the WhatsApp tick: a waiting customer reaches the warn limit -> the salesperson is nudged once; reaches the alert limit -> admins are told once. */
function wq_pace_tick(PDO $pdo): array
{
    $o = ['warn' => 0, 'alert' => 0]; $cfg = wq_pace_cfg($pdo); if (!$cfg['on'] || !wq_pace_tables($pdo)) return $o;
    if ((int)setting_get($pdo, 'wq_pace_tick_at', '0') > time() - 50) return $o; setting_set($pdo, 'wq_pace_tick_at', (string)time());
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wq_pace_alert (phone VARCHAR(12) NOT NULL, in_at DATETIME NOT NULL, lvl TINYINT NOT NULL, at DATETIME NULL, PRIMARY KEY (phone, in_at, lvl)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { return $o; }
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $ins = $pdo->prepare("INSERT IGNORE INTO wq_pace_alert (phone, in_at, lvl, at) VALUES (?,?,?,?)"); $sent = 0;
    $onShift = []; $shift = null; try { $shift = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ?"); } catch (Throwable $e) { $shift = null; }
    foreach (wq_pace_waiting_rows($pdo) as $r) {
        if ($sent >= 30) break;
        if ($shift) { if (!isset($onShift[$r['emp']])) { try { $shift->execute([$r['emp'], date('Y-m-d H:i:s', time() - 20 * 3600)]); $onShift[$r['emp']] = (int)$shift->fetchColumn() > 0; } catch (Throwable $e) { $onShift[$r['emp']] = true; } } if (!$onShift[$r['emp']]) continue; }   // not punched in: the chat is not theirs to answer right now
        if ($r['mins'] >= $cfg['alert']) { $ins->execute([$r['phone'], $r['in_at'], 2, date('Y-m-d H:i:s')]); if ($ins->rowCount() && function_exists('ct_push') && function_exists('wq_admin_ids')) { ct_push($pdo, wq_admin_ids($pdo), '🚨 ' . ($names[$r['emp']] ?? 'Someone') . ' - customer waiting ' . $r['mins'] . ' min', ($r['name'] !== '' ? $r['name'] : $r['phone']) . ' has not been answered.', '/sales.html', 'wq-pace-alert-' . $r['phone']); $o['alert']++; $sent++; } }
        if ($r['mins'] >= $cfg['warn']) { $ins->execute([$r['phone'], $r['in_at'], 1, date('Y-m-d H:i:s')]); if ($ins->rowCount() && function_exists('ct_push')) { ct_push($pdo, [$r['emp']], '⏰ Customer waiting ' . $r['mins'] . ' min', ($r['name'] !== '' ? $r['name'] : $r['phone']) . ' is waiting for your reply. Please answer now.', '/my_chats.html?tab=chats', 'wq-pace-warn-' . $r['phone']); $o['warn']++; $sent++; } }
    }
    try { $pdo->prepare("DELETE FROM wq_pace_alert WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]); } catch (Throwable $e) { }
    return $o;
}
function wq_pace_corr(array $s): ?array
{
    if ($s[0] < 15) return null; [$n, $sx, $sy, $sxx, $syy, $sxy] = $s;
    $dx = $n * $sxx - $sx * $sx; $dy = $n * $syy - $sy * $sy; if ($dx <= 0 || $dy <= 0) return null;
    $r = ($n * $sxy - $sx * $sy) / sqrt($dx * $dy); $k = -$r;   // k > 0: the faster the first reply, the more orders
    $lab = $k >= 0.25 ? 'strong' : ($k >= 0.1 ? 'some' : ($k > -0.1 ? 'none' : 'reverse'));
    return ['r' => round($k, 2), 'n' => (int)$n, 'label' => $lab];
}

/** Cached for 2 minutes per period (the scan reads a lot of messages). */
function wq_pace_get(PDO $pdo, int $days): array
{
    $k = 'pace_' . $days;
    if (wq_pace_tables($pdo)) {
        try { $q = $pdo->prepare("SELECT js, at FROM wq_pace_cache WHERE k = ?"); $q->execute([$k]); $r = $q->fetch();
            if ($r && $r['js'] && strtotime((string)$r['at']) > time() - ($days === 30 ? 600 : 120)) { $j = json_decode((string)$r['js'], true); if (is_array($j)) return $j; } } catch (Throwable $e) { }
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
    unset($d['people'], $d['orders']['by_emp'], $d['orders']['link_emp']);
    return $d + ['mine' => $mine, 'ranked' => $total, 'self' => true];
}

// ------------------------------------------------------------------------------------------------------------------ router
$__wqp = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wqp, ['wq.pace_waiting', 'wq.pace_nudge', 'wq.pace_cfg'], true)) {
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    if (!function_exists('wa_is_admin') || !wa_is_admin($pdo, $auth)) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403);
    try {
        if ($__wqp === 'wq.pace_waiting') { if ($post) json_response(['error' => 'Method not allowed.'], 405); json_response(wq_pace_waiting($pdo)); }
        $in = $post ? json_input() : [];
        if ($__wqp === 'wq.pace_nudge') { if (!$post) json_response(['error' => 'Method not allowed.'], 405); $r = wq_pace_nudge($pdo, (int)($in['emp_id'] ?? 0), $me); json_response($r, isset($r['error']) ? 409 : 200); }
        if (!$post) json_response(['ok' => true, 'cfg' => wq_pace_cfg($pdo)]);
        foreach (['target' => 'wq_pace_target', 'warn' => 'wq_pace_warn', 'alert' => 'wq_pace_alert'] as $k => $sk) if (isset($in[$k])) setting_set($pdo, $sk, (string)max(1, min(480, (int)$in[$k])));
        if (isset($in['on'])) setting_set($pdo, 'wq_pace_alerts', !empty($in['on']) ? '1' : '0');
        try { $pdo->exec("DELETE FROM wq_pace_cache"); } catch (Throwable $e) { }
        json_response(['ok' => true, 'cfg' => wq_pace_cfg($pdo)]);
    } catch (Throwable $e) { error_log('wq_pace: ' . $e->getMessage()); json_response(['error' => 'Pace: ' . mb_substr($e->getMessage(), 0, 200)], 500); }
}
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
