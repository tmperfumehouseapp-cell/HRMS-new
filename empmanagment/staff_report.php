<?php
/**
 * staff_report.php — 📈 Staff progress report (Boss and Admin, one click): every complaint-team member with
 *   · attendance: punch in / punch out per day, late minutes against their shift, hours, days present
 *   · leave: approved / pending leave days and day marks
 *   · complaint work: tickets got, solved, open now, open > 2 days, first reply time, happy %, promises missed
 *   · a score 0–100 → 🟢 Good · 🟡 Average · 🔴 Weak, with the reasons in plain words
 * Action: bd.staff_report  GET {days: 1 | 7 | 30, emp?}   (complaints boss / checker / top boss / deputy, or Admin)
 * Loaded at the bottom of complaints_more.php; the screen is staff_progress.html (Boss → Staff progress).
 */
if (!function_exists('sr_report')) {

function sr_people(PDO $pdo, string $from): array
{
    $ids = function_exists('cx_team_ids') ? cx_team_ids($pdo) : [];
    try { $q = $pdo->prepare("SELECT DISTINCT owner_emp FROM cx_cases WHERE owner_emp > 0 AND (raised_at >= ? OR status IN ('open','reopened'))"); $q->execute([$from]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) $ids[] = (int)$e; } catch (Throwable $e) { }
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

function sr_report(PDO $pdo, int $days, int $only = 0): array
{
    $days = in_array($days, [1, 7, 30], true) ? $days : 7;
    $today = date('Y-m-d'); $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')); $fromTs = $from . ' 00:00:00'; $now = time();
    $ids = sr_people($pdo, $fromTs); if ($only) $ids = array_values(array_intersect($ids, [$only]));
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $shift = function_exists('emp_shift_map') ? emp_shift_map($pdo, $today) : [];
    $dayStart = function_exists('day_start_for') ? day_start_for($pdo, $today) : '10:00';
    $out = [];
    if (!$ids) return ['days' => $days, 'from' => $from, 'to' => $today, 'people' => []];
    $in = implode(',', $ids);

    // ⏱ punches: first in / last out per person per day
    $punch = [];
    try { foreach ($pdo->query("SELECT employee_id e, DATE(punch_in) d, MIN(punch_in) i, MAX(punch_out) o, SUM(TIMESTAMPDIFF(MINUTE, punch_in, COALESCE(punch_out, IF(DATE(punch_in) = CURDATE(), NOW(), punch_in)))) m
                                     FROM attendance WHERE employee_id IN ($in) AND punch_in >= " . $pdo->quote($fromTs) . " GROUP BY employee_id, DATE(punch_in)")->fetchAll() as $r) $punch[(int)$r['e']][(string)$r['d']] = $r; } catch (Throwable $e) { }
    // 🏖 leave: approved / pending requests overlapping the period, and day marks
    $leave = [];
    try { foreach ($pdo->query("SELECT employee_id e, leave_type t, date_from f, date_to tt, status s FROM leave_requests WHERE employee_id IN ($in) AND status IN ('approved','pending') AND date_to >= " . $pdo->quote($from) . " AND date_from <= " . $pdo->quote($today))->fetchAll() as $r)
        for ($d = max($from, (string)$r['f']); $d <= min($today, (string)$r['tt']); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $leave[(int)$r['e']][$d] = ['type' => (string)$r['t'], 'status' => (string)$r['s']]; } catch (Throwable $e) { }
    try { if (function_exists('day_marks_between')) foreach (day_marks_between($pdo, $from, $today) as $m) { $e = (int)($m['employee_id'] ?? 0); $mk = (string)($m['mark'] ?? ''); $d = (string)($m['mark_date'] ?? ''); if ($e && $mk !== '' && $d !== '' && in_array($e, $ids, true) && !isset($leave[$e][$d])) $leave[$e][$d] = ['type' => $mk, 'status' => 'mark']; } } catch (Throwable $e) { }
    // 🎫 tickets
    $tk = [];
    try { foreach ($pdo->query("SELECT owner_emp e, raised_at, first_reply_at, resolved_at, status, happy, COALESCE(promise_state, '') ps FROM cx_cases WHERE owner_emp IN ($in) AND (raised_at >= " . $pdo->quote($fromTs) . " OR resolved_at >= " . $pdo->quote($fromTs) . " OR status IN ('open','reopened')) AND (res_note NOT LIKE 'Merged into%' OR res_note IS NULL)")->fetchAll() as $r) $tk[(int)$r['e']][] = $r; } catch (Throwable $e) { }

    foreach ($ids as $id) {
        $sh = $shift[$id]['shift_start'] ?? $dayStart; $lateAfter = (int)($shift[$id]['late_after'] ?? 15);
        $daysOut = []; $present = 0; $lateN = 0; $lateMin = 0; $mins = 0; $inSum = 0; $inN = 0; $outSum = 0; $outN = 0; $leaveN = 0.0; $absent = 0;
        for ($d = $from; $d <= $today; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $p = $punch[$id][$d] ?? null; $lv = $leave[$id][$d] ?? null; $row = ['date' => $d, 'in' => '', 'out' => '', 'hours' => 0, 'late' => 0, 'leave' => $lv ? $lv['type'] . ($lv['status'] === 'pending' ? ' (asked)' : '') : '', 'got' => 0, 'solved' => 0];
            if ($p) { $present++; $i = strtotime((string)$p['i']); $row['in'] = date('H:i', $i); $inSum += (int)date('G', $i) * 60 + (int)date('i', $i); $inN++;
                if (!empty($p['o'])) { $o = strtotime((string)$p['o']); $row['out'] = date('H:i', $o); $outSum += (int)date('G', $o) * 60 + (int)date('i', $o); $outN++; }
                $row['hours'] = round(max(0, (int)$p['m']) / 60, 1); $mins += max(0, (int)$p['m']);
                $lm = (int)floor(($i - strtotime($d . ' ' . $sh . ':00')) / 60); if ($lm > $lateAfter) { $row['late'] = $lm; $lateN++; $lateMin += $lm; } }
            elseif ($lv) { $leaveN += in_array($lv['type'], ['half_leave', 'half'], true) ? 0.5 : 1; }
            elseif ($d < $today && (int)date('N', strtotime($d)) !== 7) $absent++;   // a past working day with no punch and no leave
            $daysOut[$d] = $row;
        }
        $got = 0; $solved = 0; $openN = 0; $open2 = 0; $frS = 0; $frN = 0; $happy = 0; $unhappy = 0; $missed = 0; $lateReply = 0;
        foreach ($tk[$id] ?? [] as $r) { $rd = substr((string)$r['raised_at'], 0, 10); $sd = substr((string)($r['resolved_at'] ?? ''), 0, 10); $open = in_array($r['status'], ['open', 'reopened'], true);
            if ($rd >= $from) { $got++; if (isset($daysOut[$rd])) $daysOut[$rd]['got']++; if (!empty($r['first_reply_at'])) { $fr = strtotime((string)$r['first_reply_at']) - strtotime((string)$r['raised_at']); $frS += max(0, $fr); $frN++; if ($fr > 3600) $lateReply++; } }
            if (!$open && $sd >= $from) { $solved++; if (isset($daysOut[$sd])) $daysOut[$sd]['solved']++; if ((string)$r['happy'] === '1') $happy++; elseif ((string)$r['happy'] === '0') $unhappy++; }
            if ($open) { $openN++; if (strtotime((string)$r['raised_at']) < $now - 2 * 86400) $open2++; }
            if ((string)$r['ps'] === 'missed') $missed++; }
        $fr = $frN ? (int)round($frS / $frN / 60) : null; $hp = ($happy + $unhappy) ? (int)round($happy * 100 / ($happy + $unhappy)) : null;
        // 📊 score (0–100): solving 30 · first reply 25 · nothing old left open 15 · happy customers 15 · on time at work 15
        $why = []; $s = 0;
        $sr = ($got + $openN) ? min(1, $solved / max(1, $got)) : 1; $s += 30 * $sr; if ($got >= 3 && $sr < 0.6) $why[] = 'solves only ' . $solved . ' of ' . $got . ' tickets';
        $fs = $fr === null ? 1 : ($fr <= 15 ? 1 : ($fr <= 60 ? 0.7 : ($fr <= 180 ? 0.4 : 0.1))); $s += 25 * $fs; if ($fr !== null && $fr > 60) $why[] = 'first reply takes ' . ($fr >= 120 ? round($fr / 60, 1) . ' h' : $fr . ' min') . ' on average';
        $s += 15 * ($open2 === 0 ? 1 : ($open2 <= 2 ? 0.5 : 0)); if ($open2) $why[] = $open2 . ' ticket' . ($open2 > 1 ? 's' : '') . ' open more than 2 days';
        $s += 15 * ($hp === null ? 1 : $hp / 100); if ($hp !== null && $hp < 70) $why[] = 'only ' . $hp . '% of customers happy';
        $work = max(1, $present + $absent); $s += 15 * max(0, 1 - ($lateN + 2 * $absent) / $work); if ($lateN) $why[] = 'late ' . $lateN . ' day' . ($lateN > 1 ? 's' : '') . ' (' . $lateMin . ' min)'; if ($absent) $why[] = $absent . ' day' . ($absent > 1 ? 's' : '') . ' absent without leave';
        if ($missed) { $s -= 5 * $missed; $why[] = $missed . ' promise' . ($missed > 1 ? 's' : '') . ' missed'; }
        $score = max(0, min(100, (int)round($s)));
        $good = []; if ($got && $sr >= 0.8) $good[] = 'solves most tickets'; if ($fr !== null && $fr <= 15) $good[] = 'replies fast'; if ($hp !== null && $hp >= 85) $good[] = 'customers happy'; if (!$lateN && !$absent && $present) $good[] = 'always on time';
        $t = $daysOut[$today]; $todayTxt = $t['in'] !== '' ? ($t['out'] !== '' ? 'Out at ' . $t['out'] . ' (in ' . $t['in'] . ')' : 'In since ' . $t['in'] . ($t['late'] ? ' · ' . $t['late'] . ' min late' : '')) : ($t['leave'] !== '' ? 'On leave (' . $t['leave'] . ')' : 'Not punched in');
        $out[] = ['id' => $id, 'name' => $names[$id] ?? ('#' . $id), 'today' => $todayTxt, 'today_state' => $t['in'] !== '' ? ($t['out'] !== '' ? 'out' : 'in') : ($t['leave'] !== '' ? 'leave' : 'none'), 'shift' => $sh,
            'present' => $present, 'absent' => $absent, 'leave_days' => $leaveN, 'late_days' => $lateN, 'late_min' => $lateMin, 'hours' => round($mins / 60, 1),
            'avg_in' => $inN ? sprintf('%02d:%02d', intdiv((int)round($inSum / $inN), 60), (int)round($inSum / $inN) % 60) : '', 'avg_out' => $outN ? sprintf('%02d:%02d', intdiv((int)round($outSum / $outN), 60), (int)round($outSum / $outN) % 60) : '',
            'got' => $got, 'solved' => $solved, 'open' => $openN, 'open2d' => $open2, 'first_reply' => $fr, 'happy_pct' => $hp, 'missed' => $missed, 'late_replies' => $lateReply,
            'score' => $score, 'label' => $score >= 75 ? 'good' : ($score >= 50 ? 'average' : 'weak'), 'why' => $why, 'good_points' => $good, 'daily' => array_values(array_reverse($daysOut))];
    }
    usort($out, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return ['days' => $days, 'from' => $from, 'to' => $today, 'people' => $out];
}

}
