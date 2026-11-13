<?php
/**
 * ct_people.php — Sales → People → one person: the last days of every number, for every salesperson.
 * Loaded by api.php only for  cp.*  actions (keep this file in the SAME folder as api.php).
 *
 *   cp.days      GET  ?days=2026-10-22,2026-10-23,…   (up to 10 business days per call)  → one row per person per day
 *   cp.goal_set  POST {prepaid_goal}                   → the Prepaid goal shown on the Live tile (0 = no goal)
 *
 * Every number comes from the same Control Tower snapshot as Live (ct_snapshot), so the two always agree.
 * Closed days are saved (setting cp_d_<day>) so the next open is instant: today = never saved, the last 3 days = 15 minutes, older = 12 hours.
 */
if (!function_exists('cp_day_rows')) {
    /** One business day → [employee_id => compact numbers]. */
    function cp_day_rows(PDO $pdo, string $day): array
    {
        $S = ct_snapshot($pdo, $day);
        $out = [];
        foreach ((array)$S['emps'] as $id => $e) {
            $n = (int)($e['orders'] ?? 0); $q = (int)($e['queries'] ?? 0);
            $off = empty($e['working']) || ($n === 0 && $q === 0 && ($e['arrive'] ?? null) === null);
            $mix = (array)($e['mix'] ?? []); $pre = (array)($e['pre'] ?? []); $sq = (array)($e['slab']['q'] ?? [0, 0, 0, 0]); $sc = (array)($e['slab']['c'] ?? [0, 0, 0, 0]);
            $out[(int)$id] = [
                'off' => $off ? 1 : 0, 'n' => $n, 's' => (int)round((float)($e['sales'] ?? 0)), 't' => ($e['target'] ?? null) !== null ? (int)round((float)$e['target']) : null,
                'p' => ($e['pct'] ?? null) !== null ? (float)$e['pct'] : null, 'qo' => (int)($e['qo'] ?? 0), 'co' => (int)($e['co'] ?? 0), 'q' => $q, 'cq' => (int)($e['cq'] ?? 0),
                'qc' => ($e['conv'] ?? null) !== null ? (float)$e['conv'] : null, 'tk' => (int)round((float)($e['ticket'] ?? 0)),
                'cr' => ($e['code_rate'] ?? null) !== null ? (int)$e['code_rate'] : null, 'mx' => ($mix['pct'] ?? null) !== null ? (float)$mix['pct'] : null, 'pcs' => (int)($mix['pieces'] ?? 0),
                'pn' => (int)($pre['n'] ?? 0), 'pa' => (int)($pre['amt'] ?? 0),
                's7' => (int)$sq[0] + (int)$sc[0], 's6' => (int)$sq[1] + (int)$sc[1], 's28' => (int)$sq[2] + (int)$sc[2], 'so' => (int)$sq[3] + (int)$sc[3],
                'ar' => $e['arrive'] ?? null, 'lt' => ($e['late_min'] ?? null) !== null ? (int)$e['late_min'] : null,
            ];
        }
        return $out;
    }
}

if (!empty($GLOBALS['cp_funcs_only'])) return;   // loaded by team_board.php only for cp_day_rows(): stop here (the actions below — and the closing "Unknown action" — are for cp.* calls only)
$__cp = (string)($action ?? '');
if ($__cp === 'cp.days') {
    $auth = require_auth();
    if (!ct_admin_ok($pdo, $auth)) json_response(['error' => 'People view is for Admin / HR.'], 403);
    if (!ensure_ct_tables($pdo)) json_response(['error' => 'Could not prepare the Control Tower tables.'], 500);
    @set_time_limit(150);
    $today = biz_today($pdo); $lo = date('Y-m-d', strtotime($today . ' -62 day'));
    $want = [];
    foreach (explode(',', (string)($_GET['days'] ?? '')) as $d) { $d = trim($d); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d <= $today && $d >= $lo) $want[$d] = 1; }
    $want = array_slice(array_keys($want), 0, 10);
    if (!$want) json_response(['error' => 'No days asked for.'], 422);
    sort($want);
    $days = [];
    foreach ($want as $d) {
        $ttl = $d === $today ? 0 : ($d >= date('Y-m-d', strtotime($today . ' -3 day')) ? 900 : 43200);
        $hit = null;
        if ($ttl > 0) { $c = json_decode((string)setting_get($pdo, 'cp_d_' . $d, ''), true); if (is_array($c) && !empty($c['rows']) && time() - (int)($c['at'] ?? 0) < $ttl) $hit = $c['rows']; }
        if ($hit === null) {
            try { $hit = cp_day_rows($pdo, $d); } catch (Throwable $e) { $hit = []; }
            if ($ttl > 0 && $hit) setting_set($pdo, 'cp_d_' . $d, json_encode(['at' => time(), 'rows' => $hit]));
        }
        $days[$d] = $hit;
    }
    // the daily rating (⭐ overall) comes from the ratings' own saved days
    $rate = [];
    try { foreach (rating_days($pdo, $want[0], end($want)) as $d => $list) foreach ($list as $id => $x) $rate[$d][(int)$id] = ['rt' => $x['score'] !== null ? round((float)$x['score'], 1) : null]; } catch (Throwable $e) {}
    foreach ($days as $d => &$rows) foreach ($rows as $id => &$r) { $r['rt'] = $rate[$d][$id]['rt'] ?? null; } unset($rows, $r);
    $names = []; $cfg = ct_cfg($pdo);
    foreach (rating_emps($pdo) as $id => $e) $names[(int)$id] = trim((string)$e['full_name']);
    json_response(['today' => $today, 'days' => $days, 'names' => $names,
        'goals' => ['mix' => (float)($cfg['mix_goal'] ?? 85), 'code' => 65, 'q_conv' => (float)($cfg['q_conv_goal'] ?? 23), 'ticket' => array_values((array)($cfg['ticket_steps'] ?? [])), 'prepaid' => (float)setting_get($pdo, 'ct_prepaid_goal', '0'), 'comm' => (int)($cfg['comm_target'] ?? 200)]]);
}
if ($__cp === 'cp.goal_set') {
    $auth = require_auth();
    if (!ct_admin_ok($pdo, $auth) || !convo_is_admin($auth)) json_response(['error' => 'Only Admin can set the goal.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $g = max(0, min(100, (float)($in['prepaid_goal'] ?? 0)));
    setting_set($pdo, 'ct_prepaid_goal', (string)$g);
    json_response(['ok' => true, 'prepaid_goal' => $g]);
}
json_response(['error' => 'Unknown action.'], 404);