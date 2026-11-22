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
    /** The numbers of the Sales Live cards (what the owner sees: queries = normal + campaign, orders = query orders + campaign orders) are the ONE source (owner 22 Nov: a lock on the top seller while her card said 23% was wrong): the snapshot writes them here;
     *  an automatic lock only works while they are fresh (15 min) and belong to the current sales day. $rows = [emp => [queries, orders]]. */
    function wgate_snapshot_put(PDO $pdo, array $rows): void
    {
        try { $tq = 0; $to = 0; foreach ($rows as $v) { $tq += (int)$v[0]; $to += (int)$v[1]; }
            setting_set($pdo, 'wgate_snap', json_encode(['t' => time(), 'day' => date('Y-m-d H:i:s', wgate_day_start($pdo)), 'rows' => $rows, 'team' => $tq > 0 ? $to / $tq : 0.0]));
            unset($GLOBALS['__wgate_c']); } catch (Throwable $e) { }
    }
    /** [emp => [queries, orders]] of the current sales day from the card numbers, the reference ratio and the hand choices; nothing is blocked automatically when the numbers are stale. */
    function wgate_state(PDO $pdo): array
    {
        $c = $GLOBALS['__wgate_c'] ?? null; if (is_array($c) && $c['t'] > time() - 20) return $c;
        $c = ['t' => time(), 'day' => '', 'emps' => [], 'ref' => 0.2, 'team' => 0.0, 'ovr' => [], 'fresh' => false];
        try { $c['day'] = date('Y-m-d H:i:s', wgate_day_start($pdo)); $j = json_decode((string)setting_get($pdo, 'wgate_snap', ''), true);
            if (is_array($j) && (int)($j['t'] ?? 0) > time() - 900 && ($j['day'] ?? '') === $c['day']) { $c['fresh'] = true; foreach ((array)($j['rows'] ?? []) as $e => $v) $c['emps'][(int)$e] = [(int)$v[0], (int)$v[1]]; $c['team'] = min(0.5, (float)($j['team'] ?? 0)); $c['ref'] = max($c['team'], 0.2); }
            $o = json_decode((string)setting_get($pdo, 'wgate_ovr', ''), true); if (is_array($o)) foreach ($o as $e => $v) if (is_array($v) && ($v[1] ?? '') === $c['day']) $c['ovr'][(int)$e] = (string)$v[0];   // a hand choice counts for this sales day only
        } catch (Throwable $e) { }
        $GLOBALS['__wgate_c'] = $c; return $c;
    }
    /** The lock state of one person: ['blocked' => bool, 'mode' => auto|open|lock, 'q' => queries, 'o' => orders, 'ref' => reference ratio, 'why' => sentence]. */
    function wgate_blocked(PDO $pdo, int $emp): array
    {
        $s = wgate_state($pdo); $v = $s['emps'][$emp] ?? [0, 0]; $n = $v[0]; $o = $v[1]; $mode = $s['ovr'][$emp] ?? 'auto'; $ref = $s['ref'];
        $r = ['blocked' => false, 'mode' => $mode, 'q' => $n, 'o' => $o, 'ref' => round($ref, 3), 'why' => ''];
        if ($mode === 'lock') { $r['blocked'] = true; $r['why'] = '🔒 Locked by the admin. New queries come back when the admin opens the lock.'; return $r; }
        if ($mode === 'open') return $r;
        if ($s['fresh'] && (($n >= 10 && $o === 0) || ($n >= 12 && ($o / $n) < 0.5 * $ref))) { $r['blocked'] = true; $r['why'] = '🔒 You have ' . $n . ' queries today (normal + campaign) with ' . $o . ' order' . ($o === 1 ? '' : 's') . ' — below the team level (' . round(100 * $o / $n) . '% vs ' . round(100 * $ref) . '%). Close the chats you already have; one order opens new queries again.'; }
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
            unset($GLOBALS['__wgate_c']);
            setting_set($pdo, 'wgate_ovr', json_encode($j)); return true; } catch (Throwable $e) { return false; }
    }
}
