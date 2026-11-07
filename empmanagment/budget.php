<?php
/**
 * budget.php — Sales → Total Budgeting: the numbers YOU type (target, lump sum, fixed expenses).
 * Loaded by api.php only for  budget.*  actions (keep this file in the SAME folder as api.php).
 *
 *   budget.get   GET  month=YYYY-MM          → { month, target, lump, goals:{sales,leader,store,other}, expenses:[{name, amount}] }
 *   budget.save  POST {month, target, lump, goals?, expenses:[{name, amount}]}   → saves them (Admin only)
 *   budget.store_comm GET date_from,date_to  → store commission per person per day, by the same rules as the online engine (no over-target extra)
 *   budget.store_bills GET date_from,date_to  → store bills with who billed each
 *   budget.bill_assign POST {bill_id, employee_id}   → change who a store bill belongs to (Admin)
 *   budget.day_assign POST {employee_id, day, grp 'store'|'retail'|''}  → move one person to the store / retail for ONE day (no online commission that day)
 *   budget.timing GET  → everybody's punch timing (strict / flexible hours / none)
 *   budget.person_save POST {employee_id, grp?, rpct?, sc?, np?, duty?, ok?}   → one person's pay group, retail % of collections, store commission, store POS billing (Admin)
 *   budget.retail GET date_from,date_to      → retail wholesale sales per person per day (billed / paid, returns taken off)
 *   budget.summary POST {facts}              → Claude's few plain lines for the top of the page
 *
 * Fixed expenses (electricity, rent ...) are monthly amounts, kept for every month until you change them.
 * Target and lump sum are kept per month. Everything else on the Total Budgeting page (commission, leaders,
 * salary ÷ 30, penalties) is read from the pages that already exist, so nothing is typed twice.
 */
if (!function_exists('budget_cfg_from')) {
    function budget_cfg_from(array $rows): array
    {
        $exp = [];
        foreach ($rows as $x) {
            $n = trim((string)($x['name'] ?? '')); $a = $x['amount'] ?? 0;
            if ($n !== '' && is_numeric($a)) $exp[] = ['name' => mb_substr($n, 0, 60), 'amount' => round(max(0, min(100000000, (float)$a)), 2)];
        }
        return array_slice($exp, 0, 40);
    }
}
if (!function_exists('budget_retail_clean')) {
    /** the retail wholesale rule: commission % of collections, the whole-month collection target, the add-on if it is reached and the minus if it is not (flat ₹ or % of that month's commission) */
    function budget_retail_clean(array $rt): array
    {
        $ty = function ($v) { return ((string)$v === 'pct') ? 'pct' : 'flat'; };
        $n = function ($v, $max) { return is_numeric($v) ? round(max(0, min($max, (float)$v)), 2) : 0.0; };
        $bt = $ty($rt['bonus_type'] ?? 'flat'); $mt = $ty($rt['minus_type'] ?? 'flat');
        return ['pct' => $n($rt['pct'] ?? 0, 50), 'basis' => (($rt['basis'] ?? '') === 'billed') ? 'billed' : 'paid', 'target' => $n($rt['target'] ?? 0, 1000000000),
            'bonus_type' => $bt, 'bonus_v' => $n($rt['bonus_v'] ?? 0, $bt === 'pct' ? 500 : 100000000), 'minus_type' => $mt, 'minus_v' => $n($rt['minus_v'] ?? 0, $mt === 'pct' ? 500 : 100000000)];
    }
}
if (!function_exists('budget_cfg')) {
    function budget_cfg(PDO $pdo): array
    {
        $j = json_decode((string)setting_get($pdo, 'budget_cfg', ''), true);
        if (!is_array($j)) $j = [];
        $rt = is_array($j['retail'] ?? null) ? $j['retail'] : [];
        $lm = [];   // leader name (as written on the Leaders rule, e.g. "Ayaz Bhai") => the employee it is
        foreach ((array)($j['leader_map'] ?? []) as $ln => $eid) if (trim((string)$ln) !== '' && (int)$eid > 0) $lm[mb_substr(trim((string)$ln), 0, 60)] = (int)$eid;
        $sl = [];   // ex-employees whose pending salary was paid / settled: employee id => when, by whom, how much
        foreach ((array)($j['settled'] ?? []) as $sid => $v) if ((int)$sid > 0 && is_array($v)) $sl[(string)(int)$sid] = ['at' => mb_substr((string)($v['at'] ?? ''), 0, 19), 'by' => mb_substr((string)($v['by'] ?? ''), 0, 60), 'amount' => round((float)($v['amount'] ?? 0), 2)];
        $dy = [];   // one-day moves: date => [employee id => 'store' | 'retail']; only the last 120 days are kept
        foreach ((array)($j['days'] ?? []) as $dd => $mm) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dd) && (string)$dd >= date('Y-m-d', strtotime('-120 days')) && is_array($mm)) foreach ($mm as $eid => $gg) if ((int)$eid > 0 && in_array($gg, ['store', 'retail'], true)) $dy[(string)$dd][(string)(int)$eid] = $gg;
        $cl = [];   // closed months: month => when / by whom / a snapshot of the totals at that moment
        foreach ((array)($j['closed'] ?? []) as $cm => $cv) if (preg_match('/^\d{4}-\d{2}$/', (string)$cm) && is_array($cv)) $cl[(string)$cm] = ['at' => mb_substr((string)($cv['at'] ?? ''), 0, 19), 'by' => mb_substr((string)($cv['by'] ?? ''), 0, 60), 'snapshot' => is_array($cv['snapshot'] ?? null) ? $cv['snapshot'] : null];
        $pp = [];   // per person: pay group (sales / retail / store / other), own retail % of collections, store commission on / off
        foreach ((array)($j['people'] ?? []) as $pid => $v) {
            if (!is_array($v) || (int)$pid <= 0) continue;
            $g = (string)($v['grp'] ?? ''); $g = in_array($g, ['sales', 'retail', 'store', 'other'], true) ? $g : '';
            $rp = $v['rpct'] ?? null; $rp = ($rp === null || $rp === '' || !is_numeric($rp)) ? null : round(max(0, min(50, (float)$rp)), 2);
            $rtg = $v['rtarget'] ?? null; $rtg = ($rtg === null || $rtg === '' || !is_numeric($rtg)) ? null : round(max(0, min(1000000000, (float)$rtg)), 2);   // this person's own monthly collection target (blank = the shared one)
            $sc = array_key_exists('sc', $v) ? (empty($v['sc']) ? 0 : 1) : 1;
            $okf = empty($v['ok']) ? 0 : 1; $npf = empty($v['np']) ? 0 : 1;   // np = "no pay": on nobody's payroll, counts as nothing
            $hist = [];   // dated pay setup: from this day on (the first entry counts from the start); earlier days keep the earlier entry
            foreach ((array)($v['hist'] ?? []) as $h) {
                if (!is_array($h) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['from'] ?? ''))) continue;
                $hg = (string)($h['grp'] ?? ''); $hg = in_array($hg, ['sales', 'retail', 'store', 'other'], true) ? $hg : '';
                $hr = $h['rpct'] ?? null; $hr = ($hr === null || $hr === '' || !is_numeric($hr)) ? null : round(max(0, min(50, (float)$hr)), 2);
                $ht = $h['rtarget'] ?? null; $ht = ($ht === null || $ht === '' || !is_numeric($ht)) ? null : round(max(0, min(1000000000, (float)$ht)), 2);
                $hist[(string)$h['from']] = ['from' => (string)$h['from'], 'grp' => $hg, 'np' => empty($h['np']) ? 0 : 1, 'monthly' => round(max(0, min(10000000, (float)($h['monthly'] ?? 0))), 2), 'rpct' => $hr, 'rtarget' => $ht, 'sc' => array_key_exists('sc', $h) ? (empty($h['sc']) ? 0 : 1) : 1];
            }
            ksort($hist); $hist = array_values($hist);
            if ($g !== '' || $rp !== null || $rtg !== null || $sc === 0 || $okf || $npf || $hist) $pp[(string)(int)$pid] = ['hist' => $hist, 'grp' => $g, 'rpct' => $rp, 'rtarget' => $rtg, 'sc' => $sc, 'np' => $npf, 'ok' => $okf, 'ok_by' => $okf ? mb_substr((string)($v['ok_by'] ?? ''), 0, 60) : '', 'ok_at' => $okf ? mb_substr((string)($v['ok_at'] ?? ''), 0, 19) : ''];
        }
        return ['expenses' => budget_cfg_from((array)($j['expenses'] ?? [])), 'months' => is_array($j['months'] ?? null) ? $j['months'] : [],
            'retail' => budget_retail_clean($rt), 'people' => $pp, 'leader_map' => $lm, 'settled' => $sl, 'days' => $dy, 'closed' => $cl];
    }
}

/** Two Admins saving at the same moment must not overwrite each other: every write takes a short database lock first (released when the request ends). */
if (!function_exists('budget_lock')) {
    function budget_lock(PDO $pdo): void
    {
        static $held = false; if ($held) return;
        try { $ok = $pdo->query("SELECT GET_LOCK('hrms_budget_cfg', 8)")->fetchColumn(); if ((int)$ok === 1) { $held = true; register_shutdown_function(function () use ($pdo) { try { $pdo->query("SELECT RELEASE_LOCK('hrms_budget_cfg')"); } catch (Throwable $e) {} }); } } catch (Throwable $e) { error_log('budget_lock: ' . $e->getMessage()); }
    }
    /** an append-only activity log of the budget settings (who changed what, when): the last 600 entries */
    function budget_audit(PDO $pdo, array $auth, string $what, int $empId = 0): void
    {
        try { $j = json_decode((string)setting_get($pdo, 'budget_audit', ''), true); if (!is_array($j)) $j = [];
            $j[] = ['at' => date('Y-m-d H:i:s'), 'by' => (string)($auth['username'] ?? ''), 'emp' => $empId, 'what' => mb_substr($what, 0, 220)];
            setting_set($pdo, 'budget_audit', json_encode(array_slice($j, -600), JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { error_log('budget_audit: ' . $e->getMessage()); }
    }
    /** the last day of the latest closed month ('' when no month is closed): nothing that starts on or before it may change */
    function budget_closed_through(array $c): string
    {
        $m = ''; foreach ((array)($c['closed'] ?? []) as $k => $v) if (preg_match('/^\d{4}-\d{2}$/', (string)$k) && (string)$k > $m) $m = (string)$k;
        return $m === '' ? '' : date('Y-m-t', strtotime($m . '-01'));
    }
}
$__bg = (string)($action ?? '');
if ($__bg === 'budget.get') {
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    $c = budget_cfg($pdo); $m = $c['months'][$month] ?? [];
    $duty = []; try { if (function_exists('ensure_st_tables')) ensure_st_tables($pdo); $duty = array_map('intval', $pdo->query("SELECT emp_id FROM st_duty")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    $gl = []; foreach (['sales', 'leader', 'helper', 'retail', 'store', 'other'] as $gk) $gl[$gk] = (float)(($m['goals'] ?? [])[$gk] ?? 0);
    json_response(['month' => $month, 'target' => (float)($m['target'] ?? 0), 'lump' => (float)($m['lump'] ?? 0), 'goals' => $gl, 'retail' => $c['retail'], 'people' => (object)$c['people'], 'leader_map' => (object)$c['leader_map'], 'settled' => (object)$c['settled'], 'days' => (object)$c['days'], 'closed' => (object)$c['closed'], 'duty' => $duty, 'expenses' => $c['expenses'], 'can_edit' => convo_is_admin($auth)]);
}
if ($__bg === 'budget.save') {
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can change the budget.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input();
    $month = trim((string)($in['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    budget_lock($pdo); $c = budget_cfg($pdo);
    if (budget_closed_through($c) >= $month . '-01') json_response(['error' => 'This month is closed. Open it first to change its target or goals.'], 409);
    foreach (['target', 'lump'] as $k) {
        $v = $in[$k] ?? 0;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000000) json_response(['error' => 'Type ' . $k . ' as an amount in rupees.'], 422);
        $c['months'][$month][$k] = round((float)$v, 2);
    }
    if (isset($in['goals']) && is_array($in['goals'])) {   // monthly goals per line: sales team, leaders, store salaries, other salaries
        foreach (['sales', 'leader', 'helper', 'retail', 'store', 'other'] as $gk) {
            $v = $in['goals'][$gk] ?? 0;
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000000) json_response(['error' => 'Type the ' . $gk . ' goal as an amount in rupees.'], 422);
            $c['months'][$month]['goals'][$gk] = round((float)$v, 2);
        }
    }
    if (isset($in['retail']) && is_array($in['retail'])) {   // the retail wholesale rule: % of collections, whole-month target, add-on if reached, minus if not
        $v = $in['retail']['pct'] ?? 0;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 50) json_response(['error' => 'The retail commission is a percentage from 0 to 50.'], 422);
        foreach (['target', 'bonus_v', 'minus_v'] as $rk) { $x = $in['retail'][$rk] ?? 0; if ($x !== '' && $x !== null && (!is_numeric($x) || (float)$x < 0)) json_response(['error' => 'Type the retail target, add-on and minus as amounts (0 or more).'], 422); }
        $c['retail'] = budget_retail_clean($in['retail']);
    }
    if (isset($in['expenses']) && is_array($in['expenses'])) {
        $c['expenses'] = budget_cfg_from($in['expenses']);
    }
    setting_set($pdo, 'budget_cfg', json_encode($c));
    budget_audit($pdo, $auth, 'Saved the budget targets, goals, retail rules and fixed expenses for ' . $month);
    json_response(['ok' => true]);
}
if ($__bg === 'budget.store_comm') {
    // Store commission per person per day by the SAME rules as the online sales commission engine (Commission → Result & rules):
    // the price-by-ML chart, an offer that matches the bill, and the order-code rule. The only difference: a store has no sales limit,
    // so no over-target extra and no boost.
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $from = (string)($_GET['date_from'] ?? ''); $to = (string)($_GET['date_to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || (strtotime($to) - strtotime($from)) > 62 * 86400) json_response(['error' => 'date_from and date_to must be YYYY-MM-DD, at most 62 days apart.'], 422);
    try { if (is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php'; } catch (Throwable $e) { error_log('budget.store_comm boss: ' . $e->getMessage()); }
    if (!function_exists('cm_chart') || !function_exists('cm_pct') || !function_exists('cm_offers') || !function_exists('cm_offer_match') || !function_exists('cm_code_need')) json_response(['error' => 'The commission engine is not available on this server.'], 500);
    try { $r = budget_store_calc($pdo, $from, $to); } catch (Throwable $e) { error_log('budget.store_comm: ' . $e->getMessage()); json_response(['from' => $from, 'to' => $to, 'people' => [], 'note' => 'The store tables are not there yet.']); }
    $by = [];
    foreach ($r['bills'] as $b) {
        $e = $b['emp_id']; if (!isset($by[$e])) $by[$e] = ['employee_id' => $e, 'name' => $b['name'], 'sales' => 0.0, 'commission' => 0.0, 'bills' => 0, 'days' => []];
        if (!isset($by[$e]['days'][$b['day']])) $by[$e]['days'][$b['day']] = ['day' => $b['day'], 'sales' => 0.0, 'commission' => 0.0, 'bills' => 0];
        $by[$e]['days'][$b['day']]['sales'] += $b['total']; $by[$e]['days'][$b['day']]['commission'] += $b['com']; $by[$e]['days'][$b['day']]['bills']++;
        $by[$e]['sales'] += $b['total']; $by[$e]['commission'] += $b['com']; $by[$e]['bills']++;
    }
    foreach ($by as &$x) { $x['sales'] = round($x['sales'], 2); $x['commission'] = round($x['commission'], 2); $x['perday'] = array_values(array_map(function ($d) { $d['sales'] = round($d['sales'], 2); $d['commission'] = round($d['commission'], 2); return $d; }, $x['days'])); unset($x['days']); } unset($x);
    json_response(['from' => $from, 'to' => $to, 'people' => array_values($by), 'no_ml_bills' => $r['no_ml'], 'unpriced_bills' => $r['unpriced']]);
}
if ($__bg === 'budget.store_bills') {
    // Every store bill of the range (all days) with who billed it and the commission it pays, newest first. Admin can change the person (budget.bill_assign).
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $from = (string)($_GET['date_from'] ?? ''); $to = (string)($_GET['date_to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || (strtotime($to) - strtotime($from)) > 62 * 86400) json_response(['error' => 'date_from and date_to must be YYYY-MM-DD, at most 62 days apart.'], 422);
    try { if (is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php'; } catch (Throwable $e) {}
    $engine = function_exists('cm_chart') && function_exists('cm_pct') && function_exists('cm_offers') && function_exists('cm_offer_match') && function_exists('cm_code_need');
    $rows = [];
    try {
        if ($engine) { $r = budget_store_calc($pdo, $from, $to); foreach (array_reverse($r['bills']) as $b) $rows[] = $b; }
        else {   // no engine here: still list the bills so the person can be fixed; no commission shown
            if (function_exists('ensure_st_tables')) ensure_st_tables($pdo);
            $q = $pdo->prepare("SELECT b.bill_id, b.bill_no, b.biz_date, b.created_at, b.emp_id, b.emp_name, b.order_code, b.total, b.total_ml, e.employee_id AS real_id FROM st_bills b LEFT JOIN employees e ON e.employee_id = b.emp_id AND e.status != 'ex' WHERE b.status = 'ok' AND b.biz_date BETWEEN ? AND ? ORDER BY b.bill_id DESC");
            $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $b) $rows[] = ['bill_id' => (int)$b['bill_id'], 'bill_no' => (string)$b['bill_no'], 'day' => (string)$b['biz_date'], 'time' => substr((string)$b['created_at'], 11, 5), 'employee_id' => $b['real_id'] ? (int)$b['emp_id'] : 0, 'name' => (string)$b['emp_name'], 'code' => trim((string)$b['order_code']) !== '', 'total' => (float)$b['total'], 'ml' => (int)$b['total_ml'], 'pct' => null, 'rule' => '', 'reduced' => false, 'com' => null];
        }
    } catch (Throwable $e) { error_log('budget.store_bills: ' . $e->getMessage()); }
    $out = []; foreach (array_slice($rows, 0, 3000) as $b) $out[] = ['bill_id' => $b['bill_id'], 'bill_no' => $b['bill_no'], 'day' => $b['day'], 'time' => $b['time'], 'employee_id' => $b['employee_id'], 'name' => $b['name'], 'code' => $b['code'], 'total' => $b['total'], 'ml' => $b['ml'], 'pct' => $b['pct'], 'rule' => $b['rule'], 'reduced' => $b['reduced'], 'com' => $b['com']];
    json_response(['from' => $from, 'to' => $to, 'bills' => $out, 'engine' => $engine]);
}
if ($__bg === 'budget.bill_assign') {
    // Change who a store bill belongs to. The commission of that bill moves to the new person at once (it is worked out from the bill).
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can change who billed.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $bid = (int)($in['bill_id'] ?? 0); $eid = (int)($in['employee_id'] ?? 0);
    $e = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND status != 'ex'"); $e->execute([$eid]); $nm = $e->fetchColumn();
    if (!$eid || $nm === false) json_response(['error' => 'Choose an employee.'], 422);
    $b = $pdo->prepare("SELECT bill_id, biz_date, bill_no FROM st_bills WHERE bill_id = ? AND status = 'ok'"); $b->execute([$bid]); $brow = $b->fetch();
    if (!$brow) json_response(['error' => 'Bill not found.'], 404);
    if (budget_closed_through(budget_cfg($pdo)) >= (string)$brow['biz_date']) json_response(['error' => 'That bill is in a closed month. Open the month first.'], 409);
    $pdo->prepare("UPDATE st_bills SET emp_id = ?, emp_name = ? WHERE bill_id = ?")->execute([$eid, (string)$nm, $bid]);
    budget_audit($pdo, $auth, 'Store bill ' . $brow['bill_no'] . ' now belongs to ' . $nm, $eid);
    json_response(['ok' => true, 'employee_id' => $eid, 'name' => (string)$nm]);
}
if ($__bg === 'budget.close_month' || $__bg === 'budget.open_month') {
    // Close a finished month: its pay setups, day moves, bills and goals can no longer change, and a snapshot of its totals is kept. Open it again (Admin, logged) to correct something.
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can close or open a month.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $month = trim((string)($in['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    budget_lock($pdo); $c = budget_cfg($pdo);
    if ($__bg === 'budget.close_month') {
        if ($month >= date('Y-m')) json_response(['error' => 'The month is not over yet. Close it after its last day.'], 409);
        $snap = is_array($in['snapshot'] ?? null) ? $in['snapshot'] : null; if ($snap && strlen(json_encode($snap)) > 30000) $snap = null;
        $c['closed'][$month] = ['at' => date('Y-m-d H:i:s'), 'by' => (string)($auth['username'] ?? ''), 'snapshot' => $snap];
        budget_audit($pdo, $auth, 'Closed the month ' . $month);
    } else { unset($c['closed'][$month]); budget_audit($pdo, $auth, 'Opened the month ' . $month . ' again'); }
    setting_set($pdo, 'budget_cfg', json_encode($c));
    json_response(['ok' => true]);
}
if ($__bg === 'budget.audit') {
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $j = json_decode((string)setting_get($pdo, 'budget_audit', ''), true); if (!is_array($j)) $j = [];
    $eid = (int)($_GET['employee_id'] ?? 0); if ($eid) $j = array_values(array_filter($j, function ($x) use ($eid) { return (int)($x['emp'] ?? 0) === $eid; }));
    json_response(['log' => array_slice(array_reverse($j), 0, $eid ? 12 : 60)]);
}
if ($__bg === 'budget.helpers') {
    // The Leaders Helper people and what each earned per day (their payout follows the work done): a line of its own in the budget, instead of a salary on the card.
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $month = trim((string)($_GET['month'] ?? date('Y-m'))); if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    $out = [];
    try {
        // the Leaders Helper code is only loaded for lh.* requests: load it here too, otherwise this line of the budget stays 0
        if (!function_exists('lh_month') && is_file(__DIR__ . '/leader_helper.php')) { require_once __DIR__ . '/leader_helper.php'; }
        if (function_exists('lh_tables') && lh_tables($pdo)) { if (function_exists('lh_migrate')) lh_migrate($pdo); if (function_exists('lh_seed')) lh_seed($pdo); }
        if (function_exists('lh_month')) { $m = lh_month($pdo, $month);
            foreach ((array)($m['people'] ?? []) as $pe) $out[(int)$pe['id']] = ['employee_id' => (int)$pe['id'], 'name' => (string)($pe['name'] ?? ''), 'salary' => (float)($pe['salary'] ?? 0), 'perday' => [], 'earned' => (float)(($m['totals'][$pe['id']]['earned'] ?? 0))];
            foreach ((array)($m['rows'] ?? []) as $row) foreach ((array)($row['per'] ?? []) as $hid => $pp) if (isset($out[(int)$hid])) $out[(int)$hid]['perday'][(string)$row['date']] = round((float)($pp['amount'] ?? 0), 2); }
    } catch (Throwable $e) { error_log('budget.helpers: ' . $e->getMessage()); }
    json_response(['helpers' => array_values($out)]);
}
if ($__bg === 'budget.salary_days') {
    // What the Salary page actually pays for each day (attendance, leave, absent days), for everybody with a fixed salary: lets Total Budgeting use the SAME day amounts instead of a flat salary ÷ 30.
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $month = trim((string)($_GET['month'] ?? date('Y-m'))); if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    @set_time_limit(60); $out = []; $flat = 0.0; $earned = 0.0;
    try {
        if (!function_exists('salary_calc') || !function_exists('salary_rules_load')) json_response(['error' => 'The Salary calculation is not available on this server.'], 500);
        $rules = salary_rules_load($pdo);
        foreach ($pdo->query("SELECT employee_id, full_name, employee_code, monthly_salary FROM employees WHERE status != 'ex' AND monthly_salary > 0")->fetchAll() as $e) {
            $r = salary_calc($pdo, $e, $month, $rules); $days = [];
            foreach ((array)($r['days'] ?? []) as $dy) $days[(string)$dy['date']] = round((float)($dy['amount'] ?? 0), 2);
            $out[(int)$e['employee_id']] = ['days' => $days, 'earned' => round((float)($r['earned'] ?? 0), 2), 'penalties' => round((float)($r['penalty_total'] ?? 0), 2), 'monthly' => (float)$e['monthly_salary']];
            $flat += (float)$e['monthly_salary']; $earned += (float)($r['earned'] ?? 0);
        }
    } catch (Throwable $e) { error_log('budget.salary_days: ' . $e->getMessage()); json_response(['error' => 'Could not read the Salary page: ' . $e->getMessage()], 500); }
    json_response(['month' => $month, 'people' => (object)$out, 'flat_total' => round($flat, 2), 'earned_total' => round($earned, 2)]);
}
if ($__bg === 'budget.timing') {
    // Everybody's punch timing (Boss Engine "who punches how"): strict (in / out times), flexible hours, or no timing. Only people who have a timing row are listed; the rest are "not set".
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $out = [];
    try {
        $day = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
        foreach (emp_shift_map($pdo, $day) as $id => $r) $out[(int)$id] = ['mode' => (string)($r['mode'] ?? 'fixed'), 'own' => (int)($r['own'] ?? 0), 'start' => (string)($r['shift_start'] ?? ''), 'end' => (string)($r['shift_end'] ?? ''), 'late' => (int)($r['late_after'] ?? 15),
            'hours' => (float)($r['hours_target'] ?? 0), 'hours_full' => (float)($r['hours_full'] ?? 0), 'win_from' => (string)($r['win_from'] ?? ''), 'win_to' => (string)($r['win_to'] ?? '')];
    } catch (Throwable $e) { error_log('budget.timing: ' . $e->getMessage()); }
    json_response(['timing' => (object)$out]);
}
if ($__bg === 'budget.day_assign') {
    // Move a person from online to the store (or retail) for ONE day. That day: no online commission and no target, store commission counts, and in the store POS they can bill. The next day they are back. Admin only.
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can move somebody to the store for a day.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $eid = (int)($in['employee_id'] ?? 0); $day = (string)($in['day'] ?? ''); $g = (string)($in['grp'] ?? '');
    if (!$eid) json_response(['error' => 'Choose an employee.'], 422);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day < date('Y-m-d', strtotime('-45 days')) || $day > date('Y-m-d', strtotime('+2 days'))) json_response(['error' => 'Pick a day from the last 45 days up to tomorrow.'], 422);
    if ($g !== '' && !in_array($g, ['store', 'retail'], true)) json_response(['error' => 'Only the store or retail wholesale.'], 422);
    budget_lock($pdo); $c = budget_cfg($pdo);
    if (budget_closed_through($c) >= $day) json_response(['error' => 'That day is in a closed month. Open the month first.'], 409);
    if ($g === '') { unset($c['days'][$day][(string)$eid]); if (empty($c['days'][$day])) unset($c['days'][$day]); } else $c['days'][$day][(string)$eid] = $g;
    setting_set($pdo, 'budget_cfg', json_encode($c));
    budget_audit($pdo, $auth, ($g === '' ? 'Took back the move to the store on ' : 'Moved to the store only on ') . $day, $eid);
    json_response(['ok' => true]);
}
if ($__bg === 'budget.reset_review') {
    // Fresh start: everybody goes back to red (not checked). Their pay setup stays as the suggestion; each person is confirmed again one by one. Admin only.
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can restart the review.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    budget_lock($pdo); $c = budget_cfg($pdo); $n = 0;
    foreach ($c['people'] as $pid => &$pp) { if (!empty($pp['ok'])) { $pp['ok'] = 0; $pp['ok_by'] = ''; $pp['ok_at'] = ''; $n++; } } unset($pp);
    setting_set($pdo, 'budget_cfg', json_encode($c));
    budget_audit($pdo, $auth, 'Restarted the review: everyone back to red (' . $n . ' were green)');
    json_response(['ok' => true, 'reset' => $n]);
}
if ($__bg === 'budget.settle') {
    // Pending salary of an ex-employee: mark it settled (paid) or pending again. Admin only. The amount is what the page showed when you pressed it.
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can settle a pending salary.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $id = (int)($in['employee_id'] ?? 0); if (!$id) json_response(['error' => 'Choose an employee.'], 422);
    budget_lock($pdo); $c = budget_cfg($pdo);
    if (!empty($in['settled'])) $c['settled'][(string)$id] = ['at' => date('Y-m-d H:i:s'), 'by' => (string)($auth['username'] ?? ''), 'amount' => round((float)($in['amount'] ?? 0), 2)]; else unset($c['settled'][(string)$id]);
    setting_set($pdo, 'budget_cfg', json_encode($c));
    budget_audit($pdo, $auth, !empty($in['settled']) ? 'Marked the pending salary settled (₹' . round((float)($in['amount'] ?? 0)) . ')' : 'Put the pending salary back to pending', $id);
    json_response(['ok' => true]);
}
if ($__bg === 'budget.leader_link') {
    // Tell the budget which employee a leader name on the Leaders rule is ("Ayaz Bhai" = Ayaz Ansari). employee_id 0 removes the link. A linked person is a leader only:
    // no fixed salary, no salesperson commission; they are paid by the leader commission. Admin only.
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can link a leader.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 60); $eid = (int)($in['employee_id'] ?? 0);
    if ($name === '') json_response(['error' => 'Which leader?'], 422);
    if ($eid) { $ex = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ? AND status != 'ex'"); $ex->execute([$eid]); if (!(int)$ex->fetchColumn()) json_response(['error' => 'Choose an employee.'], 422); }
    budget_lock($pdo); $c = budget_cfg($pdo);
    if ($eid) $c['leader_map'][$name] = $eid; else unset($c['leader_map'][$name]);
    setting_set($pdo, 'budget_cfg', json_encode($c));
    budget_audit($pdo, $auth, $eid ? 'Linked the leader “' . $name . '” to this employee' : 'Unlinked the leader “' . $name . '”', $eid);
    json_response(['ok' => true]);
}
if ($__bg === 'budget.person_save') {
    // One person's pay setup: which pay group (online sales / retail wholesale / store / other), their own retail % of collections, store commission on or off,
    // and whether they appear in the store POS "Billed by" list (st_duty). Only Admin.
    $auth = require_auth();
    if (!convo_is_admin($auth) && !(function_exists('user_can') && user_can($auth, 'commission'))) json_response(['error' => 'Only Admin, HR or a role with the Commission tick (Sales Head) can change the pay setup.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $id = (int)($in['employee_id'] ?? 0);
    $ex = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?"); $ex->execute([$id]);
    if (!$id || !(int)$ex->fetchColumn()) json_response(['error' => 'Choose an employee.'], 422);
    budget_lock($pdo); $c = budget_cfg($pdo); $closedTo = budget_closed_through($c); $p = $c['people'][(string)$id] ?? ['grp' => '', 'rpct' => null, 'sc' => 1, 'ok' => 0, 'np' => 0];
    if (array_key_exists('grp', $in)) { $g = (string)$in['grp']; if ($g !== '' && !in_array($g, ['sales', 'retail', 'store', 'other'], true)) json_response(['error' => 'Unknown pay group.'], 422); $p['grp'] = $g; }
    if (array_key_exists('rpct', $in)) { $v = $in['rpct']; if ($v === '' || $v === null) $p['rpct'] = null; elseif (!is_numeric($v) || (float)$v < 0 || (float)$v > 50) json_response(['error' => 'The retail commission is a percentage from 0 to 50.'], 422); else $p['rpct'] = round((float)$v, 2); }
    if (array_key_exists('rtarget', $in)) { $v = $in['rtarget']; if ($v === '' || $v === null) $p['rtarget'] = null; elseif (!is_numeric($v) || (float)$v < 0) json_response(['error' => 'Type the monthly target as an amount in rupees.'], 422); else $p['rtarget'] = round((float)$v, 2); }
    if (array_key_exists('sc', $in)) $p['sc'] = empty($in['sc']) ? 0 : 1;
    if (array_key_exists('np', $in)) $p['np'] = empty($in['np']) ? 0 : 1;
    if (array_key_exists('ok', $in)) { $p['ok'] = empty($in['ok']) ? 0 : 1; $p['ok_by'] = $p['ok'] ? (string)($auth['username'] ?? '') : ''; $p['ok_at'] = $p['ok'] ? date('Y-m-d H:i:s') : ''; }   // green = Admin has set and checked this person's pay rules and timing
    // Dated history. from = '' / 'start': the setup counts from the very start (all days so far are recounted with it). A date: it counts from that day, earlier days stay as they were.
    $from = array_key_exists('from', $in) ? trim((string)$in['from']) : null;
    if ($from !== null && (array_key_exists('monthly', $in) || array_key_exists('grp', $in) || array_key_exists('np', $in) || array_key_exists('rpct', $in) || array_key_exists('sc', $in) || array_key_exists('rtarget', $in))) {
        $entry = ['from' => '2000-01-01', 'grp' => (string)($p['grp'] ?? ''), 'np' => empty($p['np']) ? 0 : 1, 'monthly' => round(max(0, (float)($in['monthly'] ?? 0)), 2), 'rpct' => $p['rpct'] ?? null, 'rtarget' => $p['rtarget'] ?? null, 'sc' => (int)($p['sc'] ?? 1)];
        if ($closedTo !== '' && ($from === '' || $from === 'start')) json_response(['error' => 'A month is closed, so a setup cannot count from the start. Choose a date after ' . $closedTo . ', or open the month first.'], 409);
        if ($closedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $from <= $closedTo) json_response(['error' => 'That date is in a closed month (closed up to ' . $closedTo . '). Pick a later date, or open the month first.'], 409);
        if ($from === '' || $from === 'start') { $p['hist'] = [$entry]; }
        else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || $from < '2000-01-01' || $from > date('Y-m-d', strtotime('+2 days'))) json_response(['error' => 'Pick the day it starts from (not far in the future).'], 422);
            $h = (array)($p['hist'] ?? []);
            if (!$h) { $pv = is_array($in['prev'] ?? null) ? $in['prev'] : []; $h[] = ['from' => '2000-01-01', 'grp' => (string)($pv['grp'] ?? ''), 'np' => empty($pv['np']) ? 0 : 1, 'monthly' => round(max(0, (float)($pv['monthly'] ?? 0)), 2), 'rpct' => (isset($pv['rpct']) && $pv['rpct'] !== '' && is_numeric($pv['rpct'])) ? round((float)$pv['rpct'], 2) : null, 'rtarget' => (isset($pv['rtarget']) && $pv['rtarget'] !== '' && is_numeric($pv['rtarget'])) ? round((float)$pv['rtarget'], 2) : null, 'sc' => array_key_exists('sc', $pv) ? (empty($pv['sc']) ? 0 : 1) : 1]; }
            $entry['from'] = $from; $h = array_values(array_filter($h, function ($x) use ($from) { return ($x['from'] ?? '') !== $from; })); $h[] = $entry; usort($h, function ($a, $b) { return strcmp((string)$a['from'], (string)$b['from']); });
            $p['hist'] = $h;
        }
    }
    $c['people'][(string)$id] = $p;
    if (($p['grp'] ?? '') === '' && $p['rpct'] === null && ($p['rtarget'] ?? null) === null && (int)($p['sc'] ?? 1) === 1 && empty($p['ok']) && empty($p['np']) && empty($p['hist'])) unset($c['people'][(string)$id]);
    setting_set($pdo, 'budget_cfg', json_encode($c));
    $duty = null;
    try {
        if (function_exists('ensure_st_tables')) ensure_st_tables($pdo);
        $want = array_key_exists('duty', $in) ? !empty($in['duty']) : (($p['grp'] ?? '') === 'store' && array_key_exists('grp', $in) ? true : null);   // assigned to the store = can bill there; leaving the store never switches billing off by itself
        if ($want === true) { $pdo->prepare("INSERT IGNORE INTO st_duty (emp_id, added_at) VALUES (?, ?)")->execute([$id, date('Y-m-d H:i:s')]); $duty = true; }
        elseif ($want === false) { $pdo->prepare("DELETE FROM st_duty WHERE emp_id = ?")->execute([$id]); $duty = false; }
    } catch (Throwable $e) { error_log('budget.person_save duty: ' . $e->getMessage()); }
    $what = []; if (array_key_exists('grp', $in)) $what[] = 'group ' . ((string)($p['grp'] ?? '') ?: 'auto'); if (!empty($p['np'])) $what[] = 'no pay'; elseif (array_key_exists('monthly', $in)) $what[] = 'salary ₹' . round((float)$in['monthly']); if (array_key_exists('rpct', $in) && $p['rpct'] !== null) $what[] = 'retail ' . $p['rpct'] . '%'; if (array_key_exists('ok', $in)) $what[] = !empty($p['ok']) ? 'marked checked' : 'marked not checked'; if (isset($from) && $from !== null) $what[] = ($from === '' || $from === 'start') ? 'counts from the start' : 'counts from ' . $from;
    budget_audit($pdo, $auth, 'Pay setup: ' . implode(', ', $what), $id);
    json_response(['ok' => true, 'duty' => $duty]);
}
if ($__bg === 'budget.retail') {
    // Retail wholesale sales of each person, per day: delivered sale orders (billed amount, and what was paid) minus returns, by the person who created the order.
    // The commission % is applied on the page (so you see it change as you type); this only reports the sales.
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $from = (string)($_GET['date_from'] ?? ''); $to = (string)($_GET['date_to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || (strtotime($to) - strtotime($from)) > 62 * 86400) json_response(['error' => 'date_from and date_to must be YYYY-MM-DD, at most 62 days apart.'], 422);
    $by = [];
    try {
        $q = $pdo->prepare("SELECT o.created_by, e.full_name, o.order_date, o.order_type, o.billed_total, o.paid_amount FROM retail_orders o LEFT JOIN employees e ON e.employee_id = o.created_by
            WHERE o.created_by IS NOT NULL AND o.order_date BETWEEN ? AND ? AND ((o.order_type = 'sale' AND o.status = 'delivered') OR o.order_type = 'return')");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $r) {
            $id = (int)$r['created_by']; $d = (string)$r['order_date']; $sg = $r['order_type'] === 'return' ? -1 : 1;
            if (!isset($by[$id])) $by[$id] = ['employee_id' => $id, 'name' => (string)($r['full_name'] ?? ''), 'billed' => 0.0, 'paid' => 0.0, 'perday' => []];
            if (!isset($by[$id]['perday'][$d])) $by[$id]['perday'][$d] = ['day' => $d, 'billed' => 0.0, 'paid' => 0.0];
            $b = $sg * (float)$r['billed_total']; $p = $r['order_type'] === 'return' ? -(float)$r['billed_total'] : (float)$r['paid_amount'];
            $by[$id]['perday'][$d]['billed'] += $b; $by[$id]['perday'][$d]['paid'] += $p; $by[$id]['billed'] += $b; $by[$id]['paid'] += $p;
        }
    } catch (Throwable $e) { error_log('budget.retail: ' . $e->getMessage()); json_response(['from' => $from, 'to' => $to, 'people' => [], 'note' => 'The retail tables are not there yet.']); }
    foreach ($by as &$x) { $x['billed'] = round($x['billed'], 2); $x['paid'] = round($x['paid'], 2); $x['perday'] = array_values(array_map(function ($d) { $d['billed'] = round($d['billed'], 2); $d['paid'] = round($d['paid'], 2); return $d; }, $x['perday'])); } unset($x);
    json_response(['from' => $from, 'to' => $to, 'people' => array_values($by)]);
}
if ($__bg === 'budget.summary') {
    // Claude's few plain lines on top of Total Budgeting. The page works out the numbers and the problems; Claude only words them.
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $facts = json_encode($in['facts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($facts === false || strlen($facts) > 6000) json_response(['error' => 'Too much data.'], 422);
    $sys = "You write for the owner of a perfume business who reads on a phone. Plain English, short, no jargon, no markdown. You get this month's sales-team budget numbers as JSON (rupees). "
        . "Write summary = at most 3 short sentences: how the month is going against the target, the one or two things that matter most to fix, and what to do first. Use the numbers given, never invent any. If problems is empty, say it looks fine.";
    $schema = ['type' => 'OBJECT', 'properties' => ['summary' => ['type' => 'STRING']], 'required' => ['summary']];
    $sum = '';
    try { $r = convo_ai($sys, [['text' => "DATA:\n" . $facts]], $schema, false, 400, 'claude'); if (is_array($r) && !empty($r[0]) && is_array($r[1])) $sum = mb_substr(trim((string)($r[1]['summary'] ?? '')), 0, 500); } catch (Throwable $e) {}
    json_response(['summary' => $sum]);
}
json_response(['error' => 'Unknown action.'], 404);
