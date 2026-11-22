<?php
/** wag_gate.php — the query lock (owner 22 Nov): who may not take NEW queries any more today, counted over the CURRENT SALES DAY (the day start of the Sales page, not midnight, no overnight) with the normal AND the campaign queries together.
 *  Rule: 10+ queries and no order, or 12+ queries and a ratio under half of the team's (reference at least 20%). An admin can lock / unlock a person by hand (the lock on his Sales Live card): the choice holds for the current sales day only.
 *  Only functions (no side effects), PHP 7.4 safe; loaded by wa_growth.php (the gate) and api.php (the Sales Live snapshot). */
if (!function_exists('wgate_state')) {
    /** The start of the current sales day (timestamp): today's start time if it has passed, else yesterday's (same table as the Sales page: day_start_for). */
    function wgate_day_start(PDO $pdo): int
    {
        $now = time();
        foreach ([date('Y-m-d', $now), date('Y-m-d', $now - 86400)] as $d) { $t = function_exists('day_start_for') ? (string)day_start_for($pdo, $d) : '10:00'; $ts = (int)strtotime($d . ' ' . $t); if ($ts > 0 && $ts <= $now) return $ts; }
        return (int)strtotime(date('Y-m-d 00:00:00'));
    }
    /** [emp => [queries, orders]] of the current sales day (normal + campaign, community not counted) and the team's 7-day ratio; cached 20 s. */
    function wgate_state(PDO $pdo): array
    {
        static $c = null; if ($c !== null && $c['t'] > time() - 20) return $c;
        $c = ['t' => time(), 'day' => '', 'emps' => [], 'ref' => 0.2, 'team' => 0.0, 'ovr' => []];
        try { $f = wgate_day_start($pdo); $c['day'] = date('Y-m-d H:i:s', $f); $f7 = date('Y-m-d H:i:s', $f - 6 * 86400);
            $sql = "SELECT q.emp_id, COUNT(*) n, SUM(CASE WHEN COALESCE(s.orders, 0) > 0 THEN 1 ELSE 0 END) o FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.emp_id > 0 AND COALESCE(t.type, 'unclear') NOT IN ('community') GROUP BY q.emp_id";
            $q = $pdo->prepare($sql); $q->execute([date('Y-m-d H:i:s', $f)]); foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $c['emps'][(int)$r[0]] = [(int)$r[1], (int)$r[2]];
            $q->execute([$f7]); $tq = 0; $to = 0; foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) { $tq += (int)$r[1]; $to += (int)$r[2]; }
            $c['team'] = $tq >= 40 && $to > 0 ? min(0.5, $to / $tq) : 0.0; $c['ref'] = max($c['team'], 0.2);
            $j = json_decode((string)setting_get($pdo, 'wgate_ovr', ''), true); if (is_array($j)) foreach ($j as $e => $v) if (is_array($v) && ($v[1] ?? '') === $c['day']) $c['ovr'][(int)$e] = (string)$v[0];   // a hand choice counts for this sales day only
        } catch (Throwable $e) { }
        return $c;
    }
    /** The lock state of one person: ['blocked' => bool, 'mode' => auto|open|lock, 'q' => queries, 'o' => orders, 'ref' => reference ratio, 'why' => sentence]. */
    function wgate_blocked(PDO $pdo, int $emp): array
    {
        $s = wgate_state($pdo); $v = $s['emps'][$emp] ?? [0, 0]; $n = $v[0]; $o = $v[1]; $mode = $s['ovr'][$emp] ?? 'auto'; $ref = $s['ref'];
        $r = ['blocked' => false, 'mode' => $mode, 'q' => $n, 'o' => $o, 'ref' => round($ref, 3), 'why' => ''];
        if ($mode === 'lock') { $r['blocked'] = true; $r['why'] = '🔒 Locked by the admin. New queries come back when the admin opens the lock.'; return $r; }
        if ($mode === 'open') return $r;
        if (($n >= 10 && $o === 0) || ($n >= 12 && ($o / $n) < 0.5 * $ref)) { $r['blocked'] = true; $r['why'] = '🔒 You have ' . $n . ' queries today (normal + campaign) with ' . $o . ' order' . ($o === 1 ? '' : 's') . ' — below the team level (' . round(100 * $o / $n) . '% vs ' . round(100 * $ref) . '%). Close the chats you already have; one order opens new queries again.'; }
        return $r;
    }
    /** What the Sales Live card needs: [blocked, mode, q, o]. */
    function wgate_card(PDO $pdo, int $emp): array { $g = wgate_blocked($pdo, $emp); return ['blocked' => $g['blocked'], 'mode' => $g['mode'], 'q' => $g['q'], 'o' => $g['o']]; }
    /** Admin: mode = lock (no new queries) · open (the automatic lock is lifted today) · auto (back to the rule). */
    function wgate_set(PDO $pdo, int $emp, string $mode): bool
    {
        if ($emp <= 0 || !in_array($mode, ['lock', 'open', 'auto'], true)) return false;
        try { $s = wgate_state($pdo); $j = json_decode((string)setting_get($pdo, 'wgate_ovr', ''), true); if (!is_array($j)) $j = [];
            foreach ($j as $e => $v) if (!is_array($v) || ($v[1] ?? '') !== $s['day']) unset($j[$e]);   // yesterday's choices are dropped
            if ($mode === 'auto') unset($j[$emp]); else $j[$emp] = [$mode, $s['day']];
            setting_set($pdo, 'wgate_ovr', json_encode($j)); return true; } catch (Throwable $e) { return false; }
    }
}
