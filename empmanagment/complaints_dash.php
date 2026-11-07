<?php
/**
 * complaints_dash.php — 📊 the Complaints dashboard (Complaints → Dashboard). One call: bd.cx_dash?p=today|yesterday|week|month|last_month|30|90|custom&from=&to=
 *   Cards: registered · solved · still open · open more than 2 days · late now · happy % · opened again · first reply · time to solve (+ the period before)
 *   By department (Customer Care / Tracking) · by problem · by team member (handled, solved, open, open > 2 days, first reply, time to solve, happy)
 *   Messages to customers (ticket messages, progress messages, with photo, waiting for Meta, "All good" / "Still a problem" answers)
 *   Money (refunds paid, store credit given, replacements / resends sent, other cost) · repeat complainers · per day (registered vs solved).
 * Loaded at the bottom of complaints_more.php; the case is in cx2_dispatch. PHP 7.4 safe.
 */
if (!function_exists('cx9_period')) {

/** [from, to (exclusive), label, previous from, previous to] for a period key. */
function cx9_period(string $p, string $f = '', string $t = ''): array
{
    $d0 = strtotime(date('Y-m-d')); $day = 86400;
    switch ($p) {
        case 'today': $a = $d0; $b = $d0 + $day; $l = 'Today'; break;
        case 'yesterday': $a = $d0 - $day; $b = $d0; $l = 'Yesterday'; break;
        case 'week': $a = $d0 - 6 * $day; $b = $d0 + $day; $l = 'Last 7 days'; break;
        case 'last_month': $a = strtotime(date('Y-m-01', strtotime('first day of last month'))); $b = strtotime(date('Y-m-01')); $l = date('F Y', $a); break;
        case '30': $a = $d0 - 29 * $day; $b = $d0 + $day; $l = 'Last 30 days'; break;
        case '90': $a = $d0 - 89 * $day; $b = $d0 + $day; $l = 'Last 90 days'; break;
        case 'custom':
            $a = strtotime($f) ?: $d0; $b = (strtotime($t) ?: $d0) + $day; if ($b <= $a) $b = $a + $day; if ($b - $a > 400 * $day) $a = $b - 400 * $day;
            $l = date('j M', $a) . ' – ' . date('j M Y', $b - $day); break;
        default: $a = strtotime(date('Y-m-01')); $b = $d0 + $day; $l = 'This month (' . date('F') . ')'; $p = 'month';
    }
    $len = $b - $a; return [date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b), $l, date('Y-m-d H:i:s', $a - $len), date('Y-m-d H:i:s', $a), $p];
}
function cx9_span($mins): string
{
    if ($mins === null || $mins === '') return '—'; $m = (int)round((float)$mins);
    if ($m < 60) return $m . ' min'; if ($m < 1440) return round($m / 60, 1) . ' h'; $dd = round($m / 1440, 1); return $dd . ($dd == 1 ? ' day' : ' days');
}
function cx9_one(PDO $pdo, string $sql, array $a) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; } }
function cx9_all(PDO $pdo, string $sql, array $a) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } }

function cx9_dash(PDO $pdo, string $p, string $f = '', string $t = ''): array
{
    [$a, $b, $label, $pa, $pb, $p] = cx9_period($p, $f, $t); $now = date('Y-m-d H:i:s'); $old = date('Y-m-d H:i:s', time() - 2 * 86400);
    $P = cx_problems(); $names = cx_names($pdo); $open = "status IN ('open','reopened')";
    $card = function ($x, $y) use ($pdo, $open) {
        $r = cx9_one($pdo, "SELECT COUNT(*) reg, SUM(reopen_n > 0) reop, AVG(IF(first_reply_at IS NULL, NULL, TIMESTAMPDIFF(MINUTE, raised_at, first_reply_at))) frm FROM cx_cases WHERE raised_at >= ? AND raised_at < ?", [$x, $y]);
        $s = cx9_one($pdo, "SELECT COUNT(*) sol, AVG(TIMESTAMPDIFF(MINUTE, raised_at, resolved_at)) fix, SUM(happy = 1) hp, SUM(happy = 0) uh FROM cx_cases WHERE resolved_at >= ? AND resolved_at < ?", [$x, $y]);
        return ['registered' => (int)($r['reg'] ?? 0), 'solved' => (int)($s['sol'] ?? 0), 'reopened' => (int)($r['reop'] ?? 0), 'first_reply_min' => isset($r['frm']) ? (float)$r['frm'] : null,
            'fix_min' => isset($s['fix']) && $s['fix'] !== null ? (float)$s['fix'] : null, 'happy' => (int)($s['hp'] ?? 0), 'unhappy' => (int)($s['uh'] ?? 0)];
    };
    $cur = $card($a, $b); $prev = $card($pa, $pb);
    $nowR = cx9_one($pdo, "SELECT SUM($open) o, SUM($open AND raised_at < ?) o2, SUM($open AND due_at IS NOT NULL AND due_at < ?) late, SUM($open AND (first_reply_at IS NULL)) noreply FROM cx_cases", [$old, $now]);
    $cards = $cur + ['open_now' => (int)($nowR['o'] ?? 0), 'open_2d' => (int)($nowR['o2'] ?? 0), 'late_now' => (int)($nowR['late'] ?? 0), 'no_reply_now' => (int)($nowR['noreply'] ?? 0),
        'first_reply' => cx9_span($cur['first_reply_min']), 'fix' => cx9_span($cur['fix_min']), 'happy_pct' => ($cur['happy'] + $cur['unhappy']) > 0 ? (int)round(100 * $cur['happy'] / ($cur['happy'] + $cur['unhappy'])) : null];
    // by department / problem / person: registered in the period, solved in the period, open now
    $grp = function (string $col) use ($pdo, $a, $b, $open, $old) {
        $o = [];
        foreach (cx9_all($pdo, "SELECT $col k, COUNT(*) n, SUM($open) op, AVG(IF(first_reply_at IS NULL, NULL, TIMESTAMPDIFF(MINUTE, raised_at, first_reply_at))) fr FROM cx_cases WHERE raised_at >= ? AND raised_at < ? GROUP BY k", [$a, $b]) as $x) $o[(string)$x['k']] = ['reg' => (int)$x['n'], 'fr' => $x['fr']] + ($o[(string)$x['k']] ?? []);
        foreach (cx9_all($pdo, "SELECT $col k, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, raised_at, resolved_at)) fix, SUM(happy = 1) hp, SUM(happy = 0) uh, COALESCE(SUM(res_cost + IF(refund_ok = 1, refund_amt, 0)), 0) cost FROM cx_cases WHERE resolved_at >= ? AND resolved_at < ? GROUP BY k", [$a, $b]) as $x) $o[(string)$x['k']] = ['sol' => (int)$x['n'], 'fix' => $x['fix'], 'hp' => (int)$x['hp'], 'uh' => (int)$x['uh'], 'cost' => (float)$x['cost']] + ($o[(string)$x['k']] ?? []);
        foreach (cx9_all($pdo, "SELECT $col k, SUM($open) op, SUM($open AND raised_at < ?) o2 FROM cx_cases WHERE $open GROUP BY k", [$old]) as $x) $o[(string)$x['k']] = ['open' => (int)$x['op'], 'open2' => (int)$x['o2']] + ($o[(string)$x['k']] ?? []);
        $out = []; foreach ($o as $k => $x) $out[$k] = ['reg' => (int)($x['reg'] ?? 0), 'sol' => (int)($x['sol'] ?? 0), 'open' => (int)($x['open'] ?? 0), 'open2' => (int)($x['open2'] ?? 0), 'first_reply' => cx9_span($x['fr'] ?? null), 'fix' => cx9_span($x['fix'] ?? null),
            'happy' => (int)($x['hp'] ?? 0), 'unhappy' => (int)($x['uh'] ?? 0), 'cost' => (float)($x['cost'] ?? 0)];
        uasort($out, fn($p, $q) => ($q['reg'] + $q['open']) <=> ($p['reg'] + $p['open'])); return $out;
    };
    $dept = []; foreach ($grp("IF(dept = '', IF(desk = 'tracking', 'tracking', 'complaint'), dept)") as $k => $x) $dept[] = ['k' => $k, 'label' => $k === 'tracking' ? '📍 Tracking desk' : '🛠 Customer Care (complaints)'] + $x;
    $prob = []; foreach ($grp('problem') as $k => $x) $prob[] = ['k' => $k, 'label' => trim(($P[$k][1] ?? '') . ' ' . ($P[$k][0] ?? $k))] + $x;
    $team = []; foreach ($grp('owner_emp') as $k => $x) $team[] = ['k' => (int)$k, 'label' => (int)$k > 0 ? ($names[(int)$k] ?? 'Employee #' . $k) : 'Nobody yet'] + $x;
    // messages to customers in the period (from the ticket timelines)
    $ev = cx9_one($pdo, "SELECT SUM(text LIKE '📨 Customer told%' COLLATE utf8mb4_bin) tk, SUM(text LIKE '📨 Customer told%with photo%' COLLATE utf8mb4_bin) tkp, SUM(text LIKE '📨 %' COLLATE utf8mb4_bin AND text NOT LIKE '📨 Customer told%' COLLATE utf8mb4_bin) pr, SUM(text LIKE '📨 %' COLLATE utf8mb4_bin AND text NOT LIKE '📨 Customer told%' COLLATE utf8mb4_bin AND text LIKE '%with photo%' COLLATE utf8mb4_bin) prp,
        SUM(text LIKE '%sent the pre-made message%' COLLATE utf8mb4_bin) man, SUM(text LIKE '😊 The customer pressed%' COLLATE utf8mb4_bin) ok, SUM(text LIKE '%Still a problem%' COLLATE utf8mb4_bin) bad FROM cx_events WHERE at >= ? AND at < ?", [$a, $b]);
    $wait = cx9_one($pdo, "SELECT SUM(tmsg = 'wait') w FROM cx_cases WHERE $open", []);
    $q7 = json_decode((string)cx_state_get($pdo, 'cx7_queue', '[]'), true); $q8 = json_decode((string)cx_state_get($pdo, 'cx8_retry', '[]'), true);
    $msgs = ['ticket' => (int)($ev['tk'] ?? 0), 'ticket_photo' => (int)($ev['tkp'] ?? 0), 'progress' => (int)($ev['pr'] ?? 0), 'progress_photo' => (int)($ev['prp'] ?? 0), 'manual' => (int)($ev['man'] ?? 0),
        'all_good' => (int)($ev['ok'] ?? 0), 'still_problem' => (int)($ev['bad'] ?? 0), 'waiting' => (int)($wait['w'] ?? 0) + (is_array($q7) ? count($q7) : 0) + (is_array($q8) ? count($q8) : 0)];
    // money
    $ref = cx9_one($pdo, "SELECT COALESCE(SUM(IF(refund_ok = 1, refund_amt, 0)), 0) r, SUM(refund_ok = 1 AND refund_amt > 0) rn, COALESCE(SUM(res_cost), 0) c FROM cx_cases WHERE resolved_at >= ? AND resolved_at < ?", [$a, $b]);
    $cr = cx9_one($pdo, "SELECT COALESCE(SUM(amount), 0) s, COUNT(*) n FROM cx_credit WHERE kind = 'issue' AND status = 'ok' AND created_at >= ? AND created_at < ?", [$a, $b]);
    $rp = cx9_one($pdo, "SELECT SUM(kind IN ('replacement','resend')) n, SUM(kind IN ('replacement','resend') AND status IN ('shipped','delivered')) sh, SUM(kind = 'pickup') pk FROM cx_links WHERE created_at >= ? AND created_at < ?", [$a, $b]);
    $money = ['refunds' => (float)($ref['r'] ?? 0), 'refunds_n' => (int)($ref['rn'] ?? 0), 'credit' => (float)($cr['s'] ?? 0), 'credit_n' => (int)($cr['n'] ?? 0), 'other_cost' => (float)($ref['c'] ?? 0),
        'replacements' => (int)($rp['n'] ?? 0), 'replacements_sent' => (int)($rp['sh'] ?? 0), 'pickups' => (int)($rp['pk'] ?? 0)];
    $money['total'] = $money['refunds'] + $money['credit'] + $money['other_cost'];
    // repeat complainers (2+ tickets in the period, at least 90 days looked at)
    $ra = min($a, date('Y-m-d H:i:s', time() - 90 * 86400)); $rep = [];
    foreach (cx9_all($pdo, "SELECT phone, MAX(cust_name) nm, COUNT(*) n, MAX(raised_at) last FROM cx_cases WHERE phone <> '' AND raised_at >= ? GROUP BY phone HAVING n >= 2 ORDER BY n DESC, last DESC LIMIT 10", [$ra]) as $x) $rep[] = ['phone' => $x['phone'], 'name' => $x['nm'] ?: '+91 ' . $x['phone'], 'n' => (int)$x['n'], 'last' => substr((string)$x['last'], 0, 10)];
    // per day: registered vs solved (up to 62 days shown)
    $days = []; $ds = max(strtotime($a), strtotime($b) - 62 * 86400);
    for ($d = $ds; $d < strtotime($b); $d += 86400) $days[date('Y-m-d', $d)] = ['d' => date('Y-m-d', $d), 'reg' => 0, 'sol' => 0];
    foreach (cx9_all($pdo, "SELECT DATE(raised_at) d, COUNT(*) n FROM cx_cases WHERE raised_at >= ? AND raised_at < ? GROUP BY DATE(raised_at)", [date('Y-m-d H:i:s', $ds), $b]) as $x) if (isset($days[$x['d']])) $days[$x['d']]['reg'] = (int)$x['n'];
    foreach (cx9_all($pdo, "SELECT DATE(resolved_at) d, COUNT(*) n FROM cx_cases WHERE resolved_at >= ? AND resolved_at < ? GROUP BY DATE(resolved_at)", [date('Y-m-d H:i:s', $ds), $b]) as $x) if (isset($days[$x['d']])) $days[$x['d']]['sol'] = (int)$x['n'];
    return ['period' => $p, 'label' => $label, 'from' => substr($a, 0, 10), 'to' => date('Y-m-d', strtotime($b) - 86400), 'cards' => $cards, 'prev' => $prev, 'dept' => $dept, 'problems' => $prob, 'team' => $team,
        'messages' => $msgs, 'money' => $money, 'repeat' => $rep, 'days' => array_values($days)];
}

}
