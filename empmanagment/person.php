<?php
/**
 * person.php — the ONE person card (name, pay, timing rules, penalties, Claude's summary).
 * Loaded by api.php only for  person.*  actions (keep this file in the SAME folder as api.php).
 * Used by Total Budgeting and by the Boss Engine through assets/js/person_card.js, so both show the very same thing.
 * Nothing is stored here: it reads employees, employee_shifts (timing), attendance and employee_penalties, which every other
 * page already reads and writes. A change made anywhere is therefore the same change everywhere.
 *
 *   person.card          GET  employee_id            → employee, pay, timing, month stats, salary so far, penalties
 *   person.summary       GET  employee_id            → Claude's short summary + what to fix (plain fallback if Claude is not set up)
 *   person.penalty_edit  POST {penalty_id, amount, note}  → change the amount of an applied penalty (Admin / HR)
 *   person.suggest_penalty GET employee_id, date → Claude's SUGGESTION for one day from the punch data (Admin / HR). It saves nothing: a penalty is only ever added by hand with penalties.apply.
 * Adding a penalty and waiving one still use penalties.apply / penalties.waive (the Penalty list uses them too).
 * Timing is saved with bd.b2_punch_set (the Boss Engine "Who punches how" save), so there is one save, not two.
 */
if (!function_exists('person_can')) {
    function person_can(array $auth): bool
    {
        return convo_is_admin($auth) || in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'commission') || user_can($auth, 'penalties');
    }
    function person_load(PDO $pdo, int $id): ?array
    {
        try {
            $st = $pdo->prepare("SELECT e.*, d.department_name, m.full_name AS manager_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id
                                 LEFT JOIN employees m ON m.employee_id = e.reporting_manager_id WHERE e.employee_id = ?");
            $st->execute([$id]);
        } catch (Throwable $x) {
            $st = $pdo->prepare("SELECT e.*, d.department_name, '' AS manager_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?");
            $st->execute([$id]);
        }
        $r = $st->fetch();
        if (!$r) return null;
        unset($r['password'], $r['password_hash']);   // never leave the server
        return $r;
    }
    /** everything the card shows, in one array (also the facts Claude summarises) */
    function person_build(PDO $pdo, array $e): array
    {
        $id = (int)$e['employee_id']; $today = biz_today($pdo); $month = substr($today, 0, 7);
        $monthly = (float)$e['monthly_salary'];
        $sales = stripos((string)($e['department_name'] ?? ''), 'sales') !== false;
        $pay = ['type' => $monthly > 0 ? 'salary' : 'commission', 'monthly' => $monthly, 'per_day' => $monthly > 0 ? round($monthly / 30, 2) : 0.0, 'sales' => $sales];

        // timing: the same rule the punch page, the Boss Engine and the penalty check use
        $map = emp_shift_map($pdo, $today); $s = $map[$id] ?? null; $raw = shift_raw_map($pdo)[$id] ?? null;
        $own = $raw !== null || !empty($s['own']);
        $mode = $s['mode'] ?? 'fixed';
        $timing = ['own' => $own, 'mode' => $mode, 'shift_start' => (string)($s['shift_start'] ?? ''), 'shift_end' => (string)($s['shift_end'] ?? ''), 'late_after' => (int)($s['late_after'] ?? 15),
            'hours_target' => (float)($s['hours_target'] ?? 0), 'hours_full' => (float)($s['hours_full'] ?? 0), 'win_from' => (string)($s['win_from'] ?? ''), 'win_to' => (string)($s['win_to'] ?? ''),
            'fx_start' => (string)($raw['shift_start'] ?? ''), 'fx_end' => (string)($raw['shift_end'] ?? ''), 'fx_late' => isset($raw['late_after']) ? (int)$raw['late_after'] : 15];
        // a commission-based person with no timing set is never checked for lateness (penalties are always assigned by hand)
        $timing['penalised'] = $mode !== 'free' && ($own || $pay['type'] === 'salary');
        if ($pay['type'] === 'commission' && !$own) $timing['label'] = 'Not set: lateness is not checked';
        elseif ($mode === 'free') $timing['label'] = 'No rule: punch is optional, never late';
        elseif ($mode === 'hours') $timing['label'] = rtrim(rtrim(number_format($timing['hours_target'], 2, '.', ''), '0'), '.') . ' hours a day' . ($timing['win_from'] !== '' || $timing['win_to'] !== '' ? ', start ' . ($timing['win_from'] ?: 'any time') . ' to ' . ($timing['win_to'] ?: 'any time') : '');
        else $timing['label'] = 'Strict timing: in ' . $timing['shift_start'] . ($timing['shift_end'] !== '' ? ', out ' . $timing['shift_end'] : '') . ', late after ' . $timing['late_after'] . ' min' . ($own ? '' : ' (default day start)');

        // this month's punches
        $days = []; $late = 0; $short = 0; $present = 0;
        try {
            $st = $pdo->prepare("SELECT punch_date, punch_in, punch_out FROM attendance WHERE employee_id = ? AND punch_date >= ? AND punch_date <= ? AND punch_in IS NOT NULL ORDER BY punch_date DESC");
            $st->execute([$id, $month . '-01', $today]);
            foreach ($st->fetchAll() as $a) {
                $present++; $d = (string)$a['punch_date']; $in = strtotime((string)$a['punch_in']); $out = $a['punch_out'] ? strtotime((string)$a['punch_out']) : 0; $flag = '';
                if ($timing['penalised'] && $mode === 'fixed' && $timing['shift_start'] !== '' && $in > strtotime($d . ' ' . $timing['shift_start'] . ':00') + 60 * $timing['late_after']) { $flag = 'late'; $late++; }
                if ($timing['penalised'] && $mode === 'hours' && $out > $in && $timing['hours_full'] > 0 && ($out - $in) / 3600 + 1e-9 < $timing['hours_full']) { $flag = 'short'; $short++; }
                if (count($days) < 10) $days[] = ['date' => $d, 'in' => date('H:i', $in), 'out' => $out ? date('H:i', $out) : '', 'flag' => $flag];
            }
        } catch (Throwable $x) {}

        // salary so far (people with a fixed salary). Commission-based people see their commission on the Commission page.
        $salary = null;
        if ($monthly > 0) {
            try { $d = salary_calc($pdo, $e, $month, salary_rules_load($pdo)); $salary = ['earned' => $d['earned'], 'penalty_total' => $d['penalty_total'], 'net_pay' => $d['net_pay'], 'present' => $d['counts']['present'] ?? 0, 'absent' => $d['counts']['absent'] ?? 0]; } catch (Throwable $x) {}
        }

        // penalties: this month and last. A penalty is cut from the NEXT day.
        $pens = [];
        try {
            ensure_penalty_tables($pdo);
            $st = $pdo->prepare("SELECT penalty_id, title, category, penalty_date, amount, orig_amount, status, source, deducted_from, note, nth, resolve_note FROM employee_penalties
                                 WHERE employee_id = ? AND penalty_date >= ? ORDER BY penalty_date DESC, penalty_id DESC LIMIT 60");
            $st->execute([$id, date('Y-m-01', strtotime($month . '-01 -1 month'))]);
            foreach ($st->fetchAll() as $p) {
                $cut = date('Y-m-d', strtotime($p['penalty_date'] . ' +1 day'));
                $pens[] = ['id' => (int)$p['penalty_id'], 'title' => (string)$p['title'] . (!empty($p['nth']) ? ' · ' . penalty_ordinal((int)$p['nth']) . ' time' : ''), 'date' => $p['penalty_date'], 'cut_on' => $cut, 'pending' => $p['status'] === 'applied' && $cut > $today,
                    'amount' => (float)$p['amount'], 'orig_amount' => $p['orig_amount'] !== null ? (float)$p['orig_amount'] : null, 'status' => $p['status'], 'source' => $p['source'], 'from' => $p['deducted_from'], 'note' => (string)($p['note'] ?? ''), 'resolve_note' => (string)($p['resolve_note'] ?? '')];
            }
        } catch (Throwable $x) {}
        $penMonth = 0.0; foreach ($pens as $p) if ($p['status'] === 'applied' && substr($p['date'], 0, 7) === $month) $penMonth += $p['amount'];

        // who they are: role (title), login role, joined, contact, manager, photo
        $login = null;
        try { $q = $pdo->prepare("SELECT username, role FROM users WHERE employee_id = ? LIMIT 1"); $q->execute([$id]); $login = $q->fetch() ?: null; } catch (Throwable $x) {}
        $profile = ['role' => (string)($e['designation'] ?? ''), 'login_role' => $login ? (string)$login['role'] : '', 'login_user' => $login ? (string)$login['username'] : '', 'joining_date' => (string)($e['joining_date'] ?? ''),
            'mobile' => (string)($e['mobile'] ?? ''), 'email' => (string)($e['email'] ?? ''), 'employment_type' => (string)($e['employment_type'] ?? ''), 'manager' => (string)($e['manager_name'] ?? ''), 'photo' => (string)($e['photo'] ?? '')];

        // pay of the last two months (and this one): what the Salary page worked out. Commission-based people get their commission from the card (Commission engine).
        $history = [];
        if ($monthly > 0) {
            foreach ([0, 1, 2] as $k) {
                $m = date('Y-m', strtotime($month . '-01 -' . $k . ' month'));
                try { $d = salary_calc($pdo, $e, $m, salary_rules_load($pdo)); $history[] = ['month' => $m, 'earned' => $d['earned'], 'penalty_total' => $d['penalty_total'], 'net_pay' => $d['net_pay'], 'present' => $d['counts']['present'] ?? 0, 'absent' => $d['counts']['absent'] ?? 0]; } catch (Throwable $x) {}
            }
        }

        return ['employee' => ['id' => $id, 'name' => $e['full_name'], 'code' => $e['employee_code'], 'designation' => (string)($e['designation'] ?? ''), 'dept' => (string)($e['department_name'] ?? ''), 'status' => $e['status']], 'profile' => $profile, 'salary_history' => $history,
            'pay' => $pay, 'timing' => $timing, 'month' => $month, 'today' => $today, 'stats' => ['present' => $present, 'late' => $late, 'short' => $short, 'penalties_month' => round($penMonth, 2)],
            'days' => $days, 'salary' => $salary, 'penalties' => $pens];
    }
}

$__pc = (string)($action ?? '');
if ($__pc === 'person.card' || $__pc === 'person.summary') {
    $auth = require_auth();
    if (!person_can($auth)) json_response(['error' => 'You do not have permission to open this.'], 403);
    $e = person_load($pdo, (int)($_GET['employee_id'] ?? 0));
    if (!$e) json_response(['error' => 'Employee not found.'], 404);
    $card = person_build($pdo, $e);
    if ($__pc === 'person.card') {
        $card['can_edit'] = convo_is_admin($auth);   // only Admin changes anything on the card: add / change / waive penalties and timing
        $card['is_admin'] = $card['can_edit'];
        $card['can_docs'] = $card['can_edit'] || in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);   // upload is Admin / HR (employee_documents.upload); approve / decline is Admin only
        $isHr = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
        if (!$isHr && !$card['can_edit']) { $card['salary_history'] = []; $card['salary'] = null; $card['pay']['monthly'] = null; $card['pay']['per_day'] = null; }   // house rule: salary figures are for Admin and HR only
        json_response($card);
    }

    // Claude's summary: what this person is like this month and what to fix. Plain fallback when Claude is not set up.
    $f = $card; unset($f['days']); $f['recent_punches'] = $card['days'];
    $facts = json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fallback = []; $t = $card['timing']; $st = $card['stats']; $pn = $card['pay']['type'] === 'commission' ? 'commission-based' : 'on a fixed salary';
    $sum = $e['full_name'] . ' is ' . $pn . '. ' . $t['label'] . '. This month: ' . $st['present'] . ' days punched in' . ($st['late'] ? ', ' . $st['late'] . ' late' : '') . ($st['short'] ? ', ' . $st['short'] . ' short on hours' : '') . ', penalties ₹' . number_format($st['penalties_month']) . '.';
    if ($card['pay']['type'] === 'commission' && !$t['own']) $fallback[] = 'No timing is set, so lateness cannot be checked. Set timing if this person should be held to it.';
    foreach ($card['penalties'] as $p) if ($p['pending']) { $fallback[] = 'A penalty of ₹' . number_format($p['amount']) . ' (' . $p['title'] . ') is cut on ' . $p['cut_on'] . '.'; break; }
    if ($st['late'] >= 3) $fallback[] = 'Late ' . $st['late'] . ' times this month. Check whether the start time is right for this person.';
    $by = 'auto';
    try {
        $sys = "You write for the owner of a perfume business who reads on a phone. Plain English, short, no jargon. You get one employee's data as JSON (pay type, timing rule, this month's punches, penalties). "
            . "Return: summary = at most 2 short sentences about how this person is doing this month (punctuality, hours, penalties). fixes = 0 to 3 short, specific things the owner should fix or decide about THIS person "
            . "(for example: no timing is set; a penalty looks wrong; late too often; commission-based with timing set means penalties come out of commission). Do not invent numbers.";
        $schema = ['type' => 'OBJECT', 'properties' => ['summary' => ['type' => 'STRING'], 'fixes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['summary', 'fixes']];
        $r = convo_ai($sys, [['text' => "DATA:\n" . $facts]], $schema, false, 600, 'claude');
        if (is_array($r) && !empty($r[0]) && is_array($r[1]) && trim((string)($r[1]['summary'] ?? '')) !== '') { $sum = mb_substr(trim((string)$r[1]['summary']), 0, 400); $fallback = array_slice(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 200); }, array_filter((array)($r[1]['fixes'] ?? []), 'is_string')), 0, 3); $by = 'claude'; }
    } catch (Throwable $x) {}
    json_response(['summary' => $sum, 'fixes' => array_values($fallback), 'by' => $by]);
}
if ($__pc === 'person.suggest_penalty') {
    $auth = require_auth();
    if (!convo_is_admin($auth) && !in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can ask for a suggestion.'], 403);
    $e = person_load($pdo, (int)($_GET['employee_id'] ?? 0));
    if (!$e) json_response(['error' => 'Employee not found.'], 404);
    ensure_penalty_tables($pdo);
    $id = (int)$e['employee_id']; $today = biz_today($pdo); $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) $date = $today;
    $card = person_build($pdo, $e); $t = $card['timing'];
    // the punch of that day
    $punch = null;
    try { $q = $pdo->prepare("SELECT punch_in, punch_out FROM attendance WHERE employee_id = ? AND punch_date = ? LIMIT 1"); $q->execute([$id, $date]); $punch = $q->fetch() ?: null; } catch (Throwable $x) {}
    $in = $punch && $punch['punch_in'] ? strtotime((string)$punch['punch_in']) : 0; $out = $punch && $punch['punch_out'] ? strtotime((string)$punch['punch_out']) : 0;
    $facts = ['date' => $date, 'rule' => $t['label'], 'punched_in' => $in ? date('H:i', $in) : null, 'punched_out' => $out ? date('H:i', $out) : null, 'late_minutes' => 0, 'hours_worked' => null, 'hours_needed' => null, 'on_leave' => false, 'penalised' => (bool)$t['penalised']];
    if ($in && $out > $in) $facts['hours_worked'] = round(($out - $in) / 3600, 2);
    if ($t['penalised'] && $in && $t['mode'] === 'fixed' && $t['shift_start'] !== '') {
        $due = strtotime($date . ' ' . $t['shift_start'] . ':00') + 60 * $t['late_after'];
        if ($in > $due) $facts['late_minutes'] = (int)round(($in - strtotime($date . ' ' . $t['shift_start'] . ':00')) / 60);
    }
    if ($t['penalised'] && $t['mode'] === 'hours') $facts['hours_needed'] = $t['hours_full'] ?: null;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND date_from <= ? AND date_to >= ?"); $q->execute([$id, $date, $date]); $facts['on_leave'] = (int)$q->fetchColumn() > 0; } catch (Throwable $x) {}
    $already = [];
    foreach ($card['penalties'] as $p) if ($p['date'] === $date && $p['status'] === 'applied') $already[] = $p['title'] . ' ₹' . (int)$p['amount'];
    $facts['already_penalised_that_day'] = $already;
    $rules = [];
    try { foreach ($pdo->query("SELECT rule_id, name, amount, category, department FROM penalty_rules WHERE active = 1 ORDER BY name")->fetchAll() as $r) $rules[] = ['rule_id' => (int)$r['rule_id'], 'name' => (string)$r['name'], 'amount' => (float)$r['amount'], 'category' => (string)$r['category']]; } catch (Throwable $x) {}
    // plain read of the day (also the answer when Claude is not set up)
    $issue = null;
    if (!$in) $issue = $t['penalised'] && !$facts['on_leave'] ? 'No punch on ' . $date . '.' : null;
    elseif ($facts['late_minutes'] > 0) $issue = 'Punched in at ' . $facts['punched_in'] . ', ' . $facts['late_minutes'] . ' minutes after the start (' . $t['shift_start'] . ', grace ' . $t['late_after'] . ' min).';
    elseif ($t['penalised'] && $t['mode'] === 'hours' && $out > $in && $t['hours_full'] > 0 && $facts['hours_worked'] + 1e-9 < $t['hours_full']) $issue = 'Worked ' . $facts['hours_worked'] . ' hours, needs ' . $t['hours_full'] . '.';
    $sugg = null; $by = 'rules';
    if ($issue === null) { $reason = !$t['penalised'] ? 'This person has no punch rule, so nothing to suggest.' : ($facts['on_leave'] ? 'On approved leave that day.' : 'Nothing wrong in the punch data for ' . $date . '.'); }
    else {
        $reason = $issue; $pick = null;
        foreach ($rules as $r) { if (!$in ? preg_match('/absent|no.?punch|missed/i', $r['name']) : ($facts['late_minutes'] > 0 ? preg_match('/late/i', $r['name']) : preg_match('/short|hours/i', $r['name']))) { $pick = $r; break; } }
        if ($pick) $sugg = ['rule_id' => $pick['rule_id'], 'title' => $pick['name'], 'amount' => $pick['amount']];
        try {
            $sys = "You help the owner of a perfume business decide a penalty for ONE day of ONE employee. You only SUGGEST: a person decides. You get JSON: the punch facts of that day, the punch rule, penalties already given that day, and the list of saved penalty rules. "
                . "Pick at most one saved rule that fits what the punch data shows (use its exact name and amount) or none. If the data shows nothing wrong, or the person is on leave, or already penalised for it that day, return rule_id 0. "
                . "reason = one short plain sentence for the owner. Do not invent facts.";
            $schema = ['type' => 'OBJECT', 'properties' => ['rule_id' => ['type' => 'INTEGER'], 'reason' => ['type' => 'STRING']], 'required' => ['rule_id', 'reason']];
            $r = convo_ai($sys, [['text' => "DATA:\n" . json_encode(['facts' => $facts, 'rules' => $rules], JSON_UNESCAPED_UNICODE)]], $schema, false, 400, 'claude');
            if (is_array($r) && !empty($r[0]) && is_array($r[1])) {
                $rid = (int)($r[1]['rule_id'] ?? 0); $why = mb_substr(trim((string)($r[1]['reason'] ?? '')), 0, 240); $found = null;
                foreach ($rules as $x) if ($x['rule_id'] === $rid) $found = $x;
                $sugg = $found ? ['rule_id' => $found['rule_id'], 'title' => $found['name'], 'amount' => $found['amount']] : null; if ($why !== '') $reason = $why; $by = 'claude';
            }
        } catch (Throwable $x) {}
        if ($sugg) { try { $q = $pdo->prepare("SELECT * FROM penalty_rules WHERE rule_id = ?"); $q->execute([$sugg['rule_id']]); $rule = $q->fetch(); $step = $rule ? penalty_ladder_pick($pdo, $rule, $id, $date) : null; if ($step && !empty($step['amount'])) { $sugg['amount'] = (float)$step['amount']; $sugg['step'] = (string)($step['label'] ?? ''); } } catch (Throwable $x) {} }
    }
    json_response(['date' => $date, 'facts' => $facts, 'issue' => $issue, 'suggestion' => $sugg, 'reason' => $reason, 'by' => $by, 'saved' => false]);
}
if ($__pc === 'person.penalty_edit') {
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can change a penalty.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    ensure_penalty_tables($pdo);
    $in = json_input(); $pid = (int)($in['penalty_id'] ?? 0); $amt = round((float)($in['amount'] ?? 0), 2);
    if ($amt <= 0 || $amt > 1000000) json_response(['error' => 'Enter an amount more than ₹0.'], 422);
    $st = $pdo->prepare("SELECT * FROM employee_penalties WHERE penalty_id = ?"); $st->execute([$pid]); $p = $st->fetch();
    if (!$p) json_response(['error' => 'Penalty not found.'], 404);
    if ($p['status'] !== 'applied') json_response(['error' => 'This penalty is already ' . $p['status'] . '.'], 409);
    $orig = $p['orig_amount'] !== null ? (float)$p['orig_amount'] : (float)$p['amount'];
    $note = mb_substr(trim((string)($in['note'] ?? $p['note'] ?? '')), 0, 255);
    $pdo->prepare("UPDATE employee_penalties SET amount = ?, orig_amount = ?, reduced = ?, note = ? WHERE penalty_id = ?")->execute([$amt, $orig, $amt < $orig ? 1 : 0, $note, $pid]);
    penalty_notify($pdo, (int)$p['employee_id'], $pid, 'changed', 'Penalty changed', 'Your penalty for ' . $p['title'] . ' is now ' . penalty_inr($amt) . '.');
    json_response(['message' => 'Penalty changed to ' . penalty_inr($amt) . ' and the employee was told.']);
}
json_response(['error' => 'Unknown action.'], 404);
