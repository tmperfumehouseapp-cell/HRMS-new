<?php
/**
 * leader_pay.php — "👑 Leader pay": the sales leaders' (Dipti, Ayaz Bhai, Pradeep) pay for the last 30 days, worked out from the day's ROAS.
 * Included from api.php (one line, see the install note) and answers only the actions that start with "leader.".
 *
 *   leader.get        GET  ?days=30            → rule, leaders, one row per day, totals per leader   (Admin / HR)
 *   leader.rule_save  POST {rule…, leaders:[ids]}                                                   (Admin / HR)
 *   leader.adj        POST {employee_id, day, amount, note}   manual ± for one leader on one day; amount 0 = remove (Admin / HR)
 *   leader.mine       GET                      → the logged-in leader's own rows (any login linked to a leader)
 *
 * The rule (every number editable on the screen):
 *   ROAS below the cut-off        → each leader gets the fixed amount (default ₹1,000 / day).
 *   ROAS at or above the cut-off  → pool = slab % × the day's collection, split equally between the leaders;
 *                                   slab % = base % at the base ROAS, + step % for every +step ROAS (never below 0).
 *                                   Nobody gets less than the fixed amount ("minimum per leader").
 *   Sundays have their own cut-off / base ROAS / base % (default cut-off 4.0).
 * Collection, ad spend and ROAS are the Detailed Performance Report's own numbers (report_detail_build), so both pages agree.
 */
if (!function_exists('lp_defaults')) {

function lp_defaults(): array
{
    return [
        'fixed' => 1000, 'min_each' => 1000,
        'cut' => 2.95, 'base_roas' => 3.0, 'base_pct' => 0.28, 'step_roas' => 0.1, 'step_pct' => 0.01,
        'sun_cut' => 4.0, 'sun_base_roas' => 4.0, 'sun_base_pct' => 0.28, 'sun_step_roas' => 0.1, 'sun_step_pct' => 0.01,
    ];
}

function ensure_leader_pay_tables(PDO $pdo): bool
{
    static $done = null;
    if ($done !== null) return $done; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) { $done = true; return $done; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leader_pay_adj (
            employee_id INT NOT NULL, adj_date DATE NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, note VARCHAR(200) NOT NULL DEFAULT '',
            by_user VARCHAR(100) NULL, updated_at DATETIME NULL, PRIMARY KEY (employee_id, adj_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['leader_pay_adj']); return $done = true;
    } catch (PDOException $e) { return $done = false; }
}

/** The saved rule with defaults filled in. */
function lp_rule(PDO $pdo): array
{
    $r = lp_defaults();
    $raw = setting_get($pdo, 'leader_pay_rule', '');
    $j = $raw ? json_decode((string)$raw, true) : null;
    if (is_array($j)) foreach ($r as $k => $v) if (isset($j[$k]) && is_numeric($j[$k])) $r[$k] = (float)$j[$k];
    return $r;
}

/** The leaders: the saved employee ids, else everyone whose name contains a word from the Detailed report's "Combined Commission" people. */
function lp_leaders(PDO $pdo): array
{
    $ids = [];
    $raw = setting_get($pdo, 'leader_pay_ids', '');
    $j = $raw ? json_decode((string)$raw, true) : null;
    if (is_array($j)) foreach ($j as $i) if ((int)$i > 0) $ids[] = (int)$i;
    $out = [];
    try {
        $rows = $pdo->query("SELECT employee_id, full_name FROM employees WHERE COALESCE(status, 'approved') <> 'ex'")->fetchAll();
    } catch (PDOException $e) { $rows = []; }
    if ($ids) {
        foreach ($rows as $r) if (in_array((int)$r['employee_id'], $ids, true)) $out[] = ['id' => (int)$r['employee_id'], 'name' => trim((string)$r['full_name'])];
        if ($out) return $out;
    }
    $words = function_exists('report_people') ? report_people($pdo) : ['Pradeep', 'Dipti', 'Ayaz'];
    foreach ($rows as $r) {
        $nm = trim((string)$r['full_name']);
        foreach ($words as $w) if ($w !== '' && mb_stripos($nm, $w) !== false) { $out[] = ['id' => (int)$r['employee_id'], 'name' => $nm]; break; }
    }
    return $out;
}

/** One day's pay per leader from the day's ROAS: [kind, slab %, pool ₹, each ₹]. kind = fixed | slab | nospend. */
function lp_day(array $R, string $date, ?float $roas, float $coll, int $n): array
{
    if ($roas === null) return ['nospend', null, 0.0, 0.0];
    $sun = (int)date('w', strtotime($date)) === 0;
    $cut = $sun ? $R['sun_cut'] : $R['cut'];
    if ($roas < $cut) return ['fixed', null, 0.0, (float)$R['fixed']];
    $b = $sun ? $R['sun_base_roas'] : $R['base_roas']; $bp = $sun ? $R['sun_base_pct'] : $R['base_pct'];
    $sr = $sun ? $R['sun_step_roas'] : $R['step_roas']; $sp = $sun ? $R['sun_step_pct'] : $R['step_pct'];
    $pct = $sr > 0 ? max(0.0, $bp + ($roas - $b) / $sr * $sp) : max(0.0, $bp);
    $pool = $coll * $pct / 100.0;
    $each = $n > 0 ? max((float)$R['min_each'], $pool / $n) : 0.0;
    return ['slab', round($pct, 3), round($pool, 2), round($each, 2)];
}

/** The rows + totals for the leaders over $from..$to (today, if inside, is shown but not counted). */
function lp_build(PDO $pdo, string $from, string $to, string $bearer, ?array $only = null): array
{
    ensure_leader_pay_tables($pdo);
    $R = lp_rule($pdo); $L = lp_leaders($pdo);
    if ($only !== null) $L = array_values(array_filter($L, fn($x) => in_array($x['id'], $only, true)));
    $rep = report_detail_build($pdo, $from, $to, '', $bearer);
    if (isset($rep['error'])) return ['error' => $rep['error']];
    $adj = [];
    try {
        $q = $pdo->prepare("SELECT employee_id, adj_date, amount, note FROM leader_pay_adj WHERE adj_date BETWEEN ? AND ?"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $a) $adj[(int)$a['employee_id']][substr((string)$a['adj_date'], 0, 10)] = ['amount' => (float)$a['amount'], 'note' => (string)$a['note']];
    } catch (PDOException $e) {}
    $today = biz_today($pdo); $n = count($L);
    $tot = []; foreach ($L as $l) $tot[$l['id']] = ['base' => 0.0, 'adj' => 0.0, 'total' => 0.0, 'fixed_days' => 0, 'slab_days' => 0];
    $rows = [];
    foreach ((array)$rep['rows'] as $r) {
        $d = (string)$r['date']; $v = (array)$r['v'];
        $coll = (float)($v['coll'] ?? 0); $ad = isset($v['ad']) && $v['ad'] !== null ? (float)$v['ad'] : null; $roas = ($ad !== null && $ad > 0) ? $coll / $ad : null;
        [$kind, $pct, $pool, $each] = lp_day($R, $d, $roas, $coll, $n);
        $partial = $d >= $today;
        $per = [];
        foreach ($L as $l) {
            $id = $l['id']; $a = $adj[$id][$d] ?? null; $ax = $a ? $a['amount'] : 0.0;
            $per[$id] = ['base' => $each, 'adj' => $ax, 'note' => $a ? $a['note'] : '', 'total' => round($each + $ax, 2)];
            if (!$partial) { $t = &$tot[$id]; $t['base'] += $each; $t['adj'] += $ax; $t['total'] += $each + $ax; if ($kind === 'fixed') $t['fixed_days']++; if ($kind === 'slab') $t['slab_days']++; unset($t); }
        }
        $rows[] = ['date' => $d, 'dow' => date('D', strtotime($d)), 'sunday' => (int)date('w', strtotime($d)) === 0, 'coll' => round($coll), 'ad' => $ad === null ? null : round($ad),
            'roas' => $roas === null ? null : round($roas, 2), 'kind' => $kind, 'pct' => $pct, 'pool' => $pool, 'per' => $per, 'partial' => $partial];
    }
    foreach ($tot as &$t) foreach (['base', 'adj', 'total'] as $k) $t[$k] = round($t[$k], 2); unset($t);
    return ['rule' => $R, 'leaders' => $L, 'rows' => array_reverse($rows), 'totals' => $tot, 'from' => $from, 'to' => $to, 'today' => $today, 'warnings' => $rep['warnings'] ?? []];
}

function lp_range(PDO $pdo): array
{
    $days = max(7, min(93, (int)($_GET['days'] ?? 30)));
    $to = biz_today($pdo);
    return [date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' day')), $to];
}

function lp_is_admin(array $auth): bool
{
    return in_array(strtolower((string)($auth['role'] ?? '')), ['admin', 'hr'], true);
}

}   // function_exists guard

$__lp = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__lp, 'leader.') === 0) {
    $auth = require_auth();
    if (!ensure_leader_pay_tables($pdo)) json_response(['error' => 'Could not prepare the Leader pay table.'], 500);
    @set_time_limit(150);
    switch ($__lp) {
        case 'leader.get': {
            if (!lp_is_admin($auth)) json_response(['error' => 'Leader pay is for Admin / HR.'], 403);
            $bearer = get_bearer_token(); if (!$bearer) json_response(['error' => 'Please log in again.'], 401);
            [$from, $to] = lp_range($pdo);
            $r = lp_build($pdo, $from, $to, $bearer);
            if (isset($r['error'])) json_response(['error' => $r['error']], 502);
            try { $r['staff'] = $pdo->query("SELECT employee_id AS id, full_name AS name FROM employees WHERE COALESCE(status, 'approved') <> 'ex' ORDER BY full_name")->fetchAll(); } catch (PDOException $e) { $r['staff'] = []; }
            json_response($r);
        }
        case 'leader.mine': {
            $me = (int)($auth['employee_id'] ?? 0);
            $bearer = get_bearer_token(); if (!$bearer) json_response(['error' => 'Please log in again.'], 401);
            $ids = array_column(lp_leaders($pdo), 'id');
            if ($me <= 0 || !in_array($me, $ids, true)) json_response(['leader' => false]);
            // the numbers come from the report build, which needs the Orders module — read them with the saved admin-side build for this one person
            [$from, $to] = lp_range($pdo);
            $r = lp_build($pdo, $from, $to, $bearer, [$me]);
            if (isset($r['error'])) json_response(['error' => $r['error']], 502);
            $r['leader'] = true; json_response($r);
        }
        case 'leader.rule_save': {
            if (!lp_is_admin($auth)) json_response(['error' => 'Admin / HR only.'], 403);
            $in = json_input(); $R = lp_defaults(); $out = [];
            foreach ($R as $k => $def) {
                $v = isset($in[$k]) && is_numeric($in[$k]) ? (float)$in[$k] : $def;
                if ($v < 0) json_response(['error' => 'Numbers cannot be negative (' . $k . ').'], 422);
                $out[$k] = $v;
            }
            setting_set($pdo, 'leader_pay_rule', json_encode($out));
            if (isset($in['leaders']) && is_array($in['leaders'])) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $in['leaders']), fn($x) => $x > 0)));
                if (count($ids) > 10) json_response(['error' => 'At most 10 leaders.'], 422);
                setting_set($pdo, 'leader_pay_ids', json_encode($ids));
            }
            json_response(['ok' => true, 'message' => 'Saved.']);
        }
        case 'leader.adj': {
            if (!lp_is_admin($auth)) json_response(['error' => 'Admin / HR only.'], 403);
            $in = json_input(); $emp = (int)($in['employee_id'] ?? 0); $day = trim((string)($in['day'] ?? ''));
            if ($emp <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) json_response(['error' => 'Pick a leader and a day.'], 422);
            if (!in_array($emp, array_column(lp_leaders($pdo), 'id'), true)) json_response(['error' => 'That person is not a leader here.'], 422);
            $amt = round((float)($in['amount'] ?? 0), 2); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200);
            if (abs($amt) > 1000000) json_response(['error' => 'That amount looks wrong.'], 422);
            if ($amt == 0.0) { $pdo->prepare("DELETE FROM leader_pay_adj WHERE employee_id = ? AND adj_date = ?")->execute([$emp, $day]); json_response(['ok' => true, 'message' => 'Manual entry removed.']); }
            $pdo->prepare("INSERT INTO leader_pay_adj (employee_id, adj_date, amount, note, by_user, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE amount = VALUES(amount), note = VALUES(note), by_user = VALUES(by_user), updated_at = NOW()")
                ->execute([$emp, $day, $amt, $note, (string)($auth['username'] ?? '')]);
            json_response(['ok' => true, 'message' => 'Saved.']);
        }
        default: json_response(['error' => 'Unknown leader action.'], 404);
    }
}