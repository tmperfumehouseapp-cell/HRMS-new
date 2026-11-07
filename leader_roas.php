<?php
/**
 * leader_roas.php — Sales → People → Leaders ROAS: the three leaders' daily commission from the day's ROAS.
 * Loaded by api.php only for  lr.*  actions (keep this file in the SAME folder as api.php).
 *
 *   lr.get    GET  date_from, date_to (max 93 days)     → one row per day: ad spend, collection, ROAS, suggested %, each leader's % and pay
 *   lr.rules  POST {cut, base, start, step, leaders[]}   → the rule (Admin only)
 *   lr.set    POST {date, leader, amount, pct?}          → the amount (₹) YOU type for one leader on one day and "Set" (amount null/'' = un-set the day) (Admin only)
 *
 * The page only SUGGESTS. Nothing is paid until an amount is typed and Set:
 *   ROAS below the cut-off (default 2.95)  → suggestion = the fixed base (default ₹1,000) for the day
 *   ROAS at / above the cut-off            → suggestion = start (default 0.30) % + step (default 0.01) for every +0.1 ROAS, of the day's collection
 *                                            (ROAS rounded to 1 decimal, so 2.95–3.04 is the starting row)
 *   Totals count ONLY the amounts that were Set. (Older % entries are kept and shown as Set, amount = collection × %.)
 * ROAS and collection come from the same place as the Detailed report (collection ÷ the ad spend typed on the Orders page).
 */
if (!function_exists('lr_rules')) {
    function lr_rules(PDO $pdo): array
    {
        $d = ['cut' => 2.95, 'base' => 1000.0, 'start' => 0.30, 'step' => 0.01, 'leaders' => ['Dipti Madam', 'Ayazba', 'Pradeep']];
        $j = json_decode((string)setting_get($pdo, 'lr_rules', ''), true);
        if (!is_array($j)) return $d;
        foreach (['cut', 'base', 'start', 'step'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) $d[$k] = (float)$j[$k];
        if (!empty($j['leaders']) && is_array($j['leaders'])) { $l = []; foreach ($j['leaders'] as $n) { $n = trim((string)$n); if ($n !== '' && !in_array($n, $l, true)) $l[] = mb_substr($n, 0, 60); } if ($l) $d['leaders'] = array_slice($l, 0, 8); }
        return $d;
    }
    function lr_table(PDO $pdo): bool
    {
        static $ok = null; if ($ok !== null) return $ok;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leader_roas_manual (rep_date DATE NOT NULL, leader VARCHAR(60) NOT NULL, pct DECIMAL(7,3) NOT NULL, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL, PRIMARY KEY (rep_date, leader)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $have = []; foreach ($pdo->query("SHOW COLUMNS FROM leader_roas_manual")->fetchAll() as $c) $have[$c['Field']] = 1;
            if (empty($have['amount'])) $pdo->exec("ALTER TABLE leader_roas_manual ADD COLUMN amount DECIMAL(12,2) NULL");
            $ok = true;
        } catch (PDOException $e) { $ok = false; }
        return $ok;
    }
    /** ROAS rounded to 1 decimal, halves up (2.95 → 3.0). */
    function lr_r1(float $x): float { return floor($x * 10 + 0.5 + 1e-9) / 10; }
    /** The suggested % for a ROAS (null when the ROAS is below the cut-off). */
    function lr_pct(array $R, float $roas): ?float
    {
        if ($roas + 1e-9 < $R['cut']) return null;
        $steps = max(0, (int)round((lr_r1($roas) - lr_r1($R['cut'])) * 10));
        return round($R['start'] + $steps * $R['step'], 3);
    }
}

$__lr = (string)($action ?? '');
if ($__lr === 'lr.get') {
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $bearer = get_bearer_token(); if (!$bearer) json_response(['error' => 'Please log in again.'], 401);
    $today = biz_today($pdo);
    $from = trim((string)($_GET['date_from'] ?? substr($today, 0, 8) . '01')); $to = trim((string)($_GET['date_to'] ?? $today));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) json_response(['error' => 'Dates must be YYYY-MM-DD.'], 422);
    if ($from > $to) json_response(['error' => 'From date must be before To date.'], 422);
    if ((int)round((strtotime($to) - strtotime($from)) / 86400) + 1 > 93) json_response(['error' => 'Pick at most 93 days.'], 422);
    @set_time_limit(150);
    $R = lr_rules($pdo); $L = $R['leaders'];
    $rep = report_detail_build($pdo, $from, $to, '', $bearer);
    if (isset($rep['error'])) json_response(['error' => $rep['error']], 502);
    $man = [];
    if (lr_table($pdo)) {
        try { $st = $pdo->prepare("SELECT rep_date, leader, pct, amount, updated_by, updated_at FROM leader_roas_manual WHERE rep_date BETWEEN ? AND ?"); $st->execute([$from, $to]); foreach ($st->fetchAll() as $r) $man[substr((string)$r['rep_date'], 0, 10)][(string)$r['leader']] = $r; } catch (PDOException $e) {}
    }
    $adAll = [];      // days where the "All orders" ad-spend box was typed (otherwise the ad spend is the sum of the panels)
    try { $st = $pdo->prepare("SELECT spend_date FROM panel_ad_spend WHERE panel = 'all' AND spend_date BETWEEN ? AND ?"); $st->execute([$from, $to]); foreach ($st->fetchAll() as $r) $adAll[substr((string)$r['spend_date'], 0, 10)] = true; } catch (PDOException $e) {}
    $rows = []; $tot = array_fill_keys($L, 0.0); $notSet = 0;
    foreach ((array)($rep['rows'] ?? []) as $r) {
        $day = (string)$r['date']; $v = (array)$r['v'];
        $coll = (float)($v['coll'] ?? 0); $ad = isset($v['ad']) && $v['ad'] !== null ? (float)$v['ad'] : null;
        $roas = ($ad !== null && $ad > 0) ? $coll / $ad : null;
        $status = $roas === null ? 'none' : ($roas + 1e-9 >= $R['cut'] ? 'ok' : 'below');
        $sug = $roas === null ? null : lr_pct($R, $roas);
        $sugAmt = $status === 'ok' ? round($coll * $sug / 100, 0) : ($status === 'below' ? round($R['base'], 0) : null);
        $set = [];
        foreach ($L as $n) {
            $m = $man[$day][$n] ?? null;
            if ($m === null) { if ($sugAmt !== null && $day < $today) $notSet++; continue; }
            $legacy = $m['amount'] === null;
            $amt = $legacy ? round($coll * (float)$m['pct'] / 100, 0) : round((float)$m['amount'], 0);
            $set[$n] = ['amount' => $amt, 'pct' => $coll > 0 ? round($amt / $coll * 100, 3) : null, 'by' => (string)($m['updated_by'] ?? ''), 'legacy' => $legacy];
            $tot[$n] += $amt;
        }
        $rows[] = ['date' => $day, 'dow' => date('D', strtotime($day)), 'coll' => round($coll, 0), 'ad' => $ad === null ? null : round($ad, 0), 'ad_all' => isset($adAll[$day]), 'roas' => $roas === null ? null : round($roas, 2), 'status' => $status,
            'sug' => $sug, 'sug_kind' => $status === 'ok' ? 'pct' : ($status === 'below' ? 'base' : null), 'sug_amt' => $sugAmt, 'set' => (object)$set];
    }
    foreach ($tot as $k => $x) $tot[$k] = round($x, 0);
    json_response(['from' => $from, 'to' => $to, 'today' => $today, 'rules' => $R, 'rows' => array_reverse($rows), 'totals' => (object)$tot, 'not_set' => $notSet, 'can_edit' => convo_is_admin($auth)]);
}
if ($__lr === 'lr.rules') {
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can change the rule.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $R = lr_rules($pdo);
    foreach (['cut' => [0, 50], 'base' => [0, 1000000], 'start' => [0, 100], 'step' => [0, 10]] as $k => $rg) if (isset($in[$k]) && is_numeric($in[$k])) $R[$k] = max($rg[0], min($rg[1], (float)$in[$k]));
    if (isset($in['leaders']) && is_array($in['leaders'])) { $l = []; foreach ($in['leaders'] as $n) { $n = trim((string)$n); if ($n !== '' && !in_array($n, $l, true)) $l[] = mb_substr($n, 0, 60); } if ($l) $R['leaders'] = array_slice($l, 0, 8); }
    setting_set($pdo, 'lr_rules', json_encode($R));
    json_response(['ok' => true, 'rules' => $R]);
}
if ($__lr === 'lr.set') {
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can set a commission.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    if (!lr_table($pdo)) json_response(['error' => 'Could not prepare the table.'], 500);
    $in = json_input(); $day = trim((string)($in['date'] ?? '')); $who = trim((string)($in['leader'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) json_response(['error' => 'Bad date.'], 422);
    if (!in_array($who, lr_rules($pdo)['leaders'], true)) json_response(['error' => 'Unknown leader.'], 422);
    $a = $in['amount'] ?? null;
    if ($a === null || $a === '') { $pdo->prepare("DELETE FROM leader_roas_manual WHERE rep_date = ? AND leader = ?")->execute([$day, $who]); json_response(['ok' => true, 'cleared' => true]); }
    if (!is_numeric($a) || (float)$a < 0 || (float)$a > 10000000) json_response(['error' => 'Type the amount in rupees (₹).'], 422);
    $p = isset($in['pct']) && is_numeric($in['pct']) ? max(0, min(100, (float)$in['pct'])) : 0.0;      // only a note of the % this amount was (the amount is what counts)
    $pdo->prepare("INSERT INTO leader_roas_manual (rep_date, leader, pct, amount, updated_by, updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE pct = VALUES(pct), amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = NOW()")
        ->execute([$day, $who, round($p, 3), round((float)$a, 2), (string)($auth['username'] ?? $auth['name'] ?? '')]);
    json_response(['ok' => true]);
}
json_response(['error' => 'Unknown action.'], 404);