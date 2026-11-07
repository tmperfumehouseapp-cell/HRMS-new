<?php
/**
 * people.php - the PEOPLE section of the Boss Engine (loaded by boss.php; actions are bd.pp_*).
 *
 * One place for everyone: department lanes, role filter, photo, salary / penalties / documents totals, seats, login (ID + password),
 * agreements and the personal record. It REPLACES the old People menu. It is not a second copy of the data:
 *   - people      = the HRMS `employees` table (employees.save is still the only way a person is written there);
 *   - department  = the HRMS `departments` table AND the Boss Engine section linked to it (bd_sections.dept_ids), so a new department
 *                   made here becomes a Boss Engine section with its seats at once (bd_map_departments);
 *   - seats       = the Boss Engine seats (bd_seats / bd_holders). Inbox team, Complaints desks, Dispatch boss ... are all set here;
 *   - login       = the HRMS `users` table (same rules as Users: a password of 6+ characters, an Employee login must be linked to a person);
 *   - salary      = salary_calc() from api.php, the same sum Salary shows. Penalties = its penalty_total. Documents = employee_documents.
 * Salary and penalty figures are for Admin and HR only. Nothing is deleted here except a login (Admin, never your own).
 * Put this file in the SAME folder as boss.php (public_html/empmanagment/). Needs boss.php (Boss Engine). PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

function pp_month($m) { $m = trim((string)$m); return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m'); }
function pp_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS pp_cache (k VARCHAR(40) NOT NULL PRIMARY KEY, v MEDIUMTEXT NOT NULL, at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { error_log('pp_cache: ' . $e->getMessage()); }
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['pp_cache']);
}
function pp_cache_get(PDO $pdo, $k, $ttl) {
    try { $st = $pdo->prepare("SELECT v, at FROM pp_cache WHERE k = ?"); $st->execute([$k]); $r = $st->fetch(); } catch (Throwable $e) { return null; }
    if (!$r || strtotime((string)$r['at']) < time() - $ttl) return null; $d = json_decode((string)$r['v'], true); return is_array($d) ? $d : null;
}
function pp_cache_put(PDO $pdo, $k, array $v) { try { $pdo->prepare("INSERT INTO pp_cache (k, v, at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), at = VALUES(at)")->execute([$k, json_encode($v), date('Y-m-d H:i:s')]); } catch (Throwable $e) {} }
function pp_cache_drop(PDO $pdo) { try { $pdo->exec("DELETE FROM pp_cache"); } catch (Throwable $e) {} }

/** per-person salary figures for a month: [emp_id => ['est' => net pay, 'pen' => penalty total, 'earned' => ...]] (cached 5 minutes: it runs salary_calc for everyone) */
function pp_salary_map(PDO $pdo, $month, $fresh) {
    $key = 'sal:' . $month;
    if (!$fresh && ($c = pp_cache_get($pdo, $key, 300)) !== null) return $c;
    $out = [];
    if (!function_exists('salary_calc') || !function_exists('salary_rules_load')) return $out;
    try {
        $rules = salary_rules_load($pdo);
        $emps = $pdo->query("SELECT employee_id, full_name, employee_code, monthly_salary FROM employees WHERE status != 'ex' AND monthly_salary > 0")->fetchAll();
        foreach ($emps as $e) {
            try { $c = salary_calc($pdo, $e, $month, $rules); $out[(int)$e['employee_id']] = ['est' => (float)$c['net_pay'], 'pen' => (float)$c['penalty_total'], 'earned' => (float)$c['earned']]; }
            catch (Throwable $x) { /* one bad record must not hide everybody else */ }
        }
    } catch (Throwable $e) { error_log('pp_salary_map: ' . $e->getMessage()); }
    pp_cache_put($pdo, $key, $out); return $out;
}

/** the Boss Engine's own task numbers per person (b2_runs = one row per task per day, b2_pens = penalties it asked about / charged). Read-only. */
function pp_day() { return function_exists('b2_day') ? b2_day() : date('Y-m-d'); }
function pp_task_map(PDO $pdo) {
    $out = []; $day = pp_day(); $now = function_exists('bd_now') ? bd_now() : date('Y-m-d H:i:s');
    $z = function () { return ['today' => 0, 'done' => 0, 'review' => 0, 'late' => 0, 'missed_today' => 0, 'missed7' => 0, 'fu' => 0, 'ask' => 0, 'ask_amt' => 0.0, 'charged' => 0.0]; };
    try {
        $st = $pdo->prepare("SELECT r.owner_emp e, i.kind k, r.status s, r.run_date d, r.due_at due FROM b2_runs r JOIN b2_items i ON i.id = r.item_id
            WHERE r.owner_emp IS NOT NULL AND r.status <> 'excused' AND (r.run_date >= DATE_SUB(?, INTERVAL 6 DAY) OR r.status IN ('open','review'))");
        $st->execute([$day]);
        foreach ($st->fetchAll() as $r) {
            $e = (int)$r['e']; if (!isset($out[$e])) $out[$e] = $z();
            $isToday = $r['d'] === $day;
            if ($isToday) { $out[$e]['today']++; if ($r['s'] === 'done') $out[$e]['done']++; if ($r['s'] === 'review') $out[$e]['review']++; if ($r['s'] === 'missed') $out[$e]['missed_today']++; }
            if ($r['s'] === 'open' && (string)$r['due'] < $now) $out[$e]['late']++;
            if ($r['s'] === 'missed' && $r['d'] >= date('Y-m-d', strtotime($day . ' -6 days'))) $out[$e]['missed7']++;
            if ($r['k'] === 'followup' && in_array($r['s'], ['open', 'review'], true)) $out[$e]['fu']++;
        }
    } catch (Throwable $e) { /* the Boss Engine tables are not there yet: People simply shows no task numbers */ }
    try {
        foreach ($pdo->query("SELECT emp_id, COUNT(*) c, SUM(amount) a FROM b2_pens WHERE state = 'ask' GROUP BY emp_id")->fetchAll() as $r) { $e = (int)$r['emp_id']; if (!isset($out[$e])) $out[$e] = $z(); $out[$e]['ask'] = (int)$r['c']; $out[$e]['ask_amt'] = (float)$r['a']; }
    } catch (Throwable $e) {}
    return $out;
}
/** the seats you can tick for a person. Personal seats (code p.*) are the Boss Engine's own one-person seats that hold a person's tasks: they are never listed or changed here. */
function pp_seat_rows(PDO $pdo) {
    $o = [];
    try {
        $secName = []; $secDept = [];
        foreach ($pdo->query("SELECT id, name, dept_ids FROM bd_sections")->fetchAll() as $s) $secName[(int)$s['id']] = (string)$s['name'];
        foreach ($pdo->query("SELECT * FROM bd_seats WHERE active = 1 AND code NOT LIKE 'p.%' ORDER BY section_id, FIELD(kind,'boss','deputy','section_boss','checker','worker'), id")->fetchAll() as $s) {
            $o[] = ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'kind' => (string)$s['kind'], 'code' => (string)$s['code'], 'section_id' => (int)$s['section_id'], 'section' => $secName[(int)$s['section_id']] ?? 'Whole company', 'holders' => bd_holders($pdo, (int)$s['id'])];
        }
    } catch (Throwable $e) { error_log('pp_seat_rows: ' . $e->getMessage()); }
    return $o;
}
function pp_need_admin(array $auth) { if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'People is for Admin and HR.'], 403); }
function pp_need_real_admin(array $auth) { if ((string)($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Only Admin can create or change logins.'], 403); }
function pp_log(PDO $pdo, $by, $what, $emp = 0) { if (function_exists('bd_log')) { try { bd_log('people', (int)$emp, (string)$what, (string)$by); } catch (Throwable $e) {} } }

/* ============================== Titles: CEO, CFO, Packer ... (one list; the person's title IS employees.designation) ============================== */
function pp_titles_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS pp_titles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, salary DECIMAL(12,2) NOT NULL DEFAULT 0, mode VARCHAR(8) NOT NULL DEFAULT 'fixed', shift_start VARCHAR(5) NOT NULL DEFAULT '', shift_end VARCHAR(5) NOT NULL DEFAULT '', hours DECIMAL(4,1) NOT NULL DEFAULT 8, late_after INT NOT NULL DEFAULT 15, sort_no INT NOT NULL DEFAULT 100, note VARCHAR(255) NOT NULL DEFAULT '', UNIQUE KEY nm (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { error_log('pp_titles: ' . $e->getMessage()); }
}
function pp_title_clean(array $in) {
    $mode = in_array(($in['mode'] ?? 'fixed'), ['fixed', 'hours', 'free'], true) ? $in['mode'] : 'fixed';
    $t = fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : '';
    return ['name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 80), 'salary' => max(0, min(100000000, (float)($in['salary'] ?? 0))), 'mode' => $mode,
        'shift_start' => $t($in['shift_start'] ?? ''), 'shift_end' => $t($in['shift_end'] ?? ''), 'hours' => max(1, min(16, (float)($in['hours'] ?? 8))), 'late_after' => max(0, min(240, (int)($in['late_after'] ?? 15))),
        'sort_no' => (int)($in['sort_no'] ?? 100), 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 255)];
}
function pp_title_save(PDO $pdo, array $c, $id = 0) {
    if ($c['name'] === '') return 0;
    if (!$id) { $q = $pdo->prepare("SELECT id FROM pp_titles WHERE LOWER(name) = LOWER(?)"); $q->execute([$c['name']]); $id = (int)$q->fetchColumn(); }
    if ($id) { $pdo->prepare("UPDATE pp_titles SET name=?, salary=?, mode=?, shift_start=?, shift_end=?, hours=?, late_after=?, sort_no=?, note=? WHERE id=?")->execute([$c['name'], $c['salary'], $c['mode'], $c['shift_start'], $c['shift_end'], $c['hours'], $c['late_after'], $c['sort_no'], $c['note'], $id]); return $id; }
    $pdo->prepare("INSERT INTO pp_titles (name, salary, mode, shift_start, shift_end, hours, late_after, sort_no, note) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$c['name'], $c['salary'], $c['mode'], $c['shift_start'], $c['shift_end'], $c['hours'], $c['late_after'], $c['sort_no'], $c['note']]);
    return (int)$pdo->lastInsertId();
}
/** give one person a title and (optionally) the title's pay and working time. Returns a plain sentence. */
function pp_title_apply(PDO $pdo, $empId, $titleName, $applyPay, $by) {
    pp_titles_ensure($pdo);
    $titleName = mb_substr(trim((string)$titleName), 0, 80); $empId = (int)$empId;
    if ($empId <= 0 || $titleName === '') return 'Choose a person and a title.';
    $nm = bd_emp_name($pdo, $empId);
    $q = $pdo->prepare("SELECT * FROM pp_titles WHERE LOWER(name) = LOWER(?)"); $q->execute([$titleName]); $t = $q->fetch();
    if ($t) $titleName = (string)$t['name'];   // the title's own spelling
    $pdo->prepare("UPDATE employees SET designation = ? WHERE employee_id = ?")->execute([$titleName, $empId]);
    $did = [];
    if ($t && $applyPay) {
        if ((float)$t['salary'] > 0) { $pdo->prepare("UPDATE employees SET monthly_salary = ? WHERE employee_id = ?")->execute([$t['salary'], $empId]); $did[] = 'salary ' . number_format((float)$t['salary']); }
        if (function_exists('ensure_shift_tables') && function_exists('shift_set_mode') && ensure_shift_tables($pdo)) {
            $ex = $pdo->prepare("SELECT 1 FROM employee_shifts WHERE employee_id = ?"); $ex->execute([$empId]);
            if ($t['mode'] === 'fixed' && $t['shift_start'] !== '' && $t['shift_end'] !== '') {
                if ($ex->fetch()) $pdo->prepare("UPDATE employee_shifts SET shift_start=?, shift_end=?, late_after=?, updated_at=? WHERE employee_id=?")->execute([$t['shift_start'], $t['shift_end'], $t['late_after'], date('Y-m-d H:i:s'), $empId]);
                else $pdo->prepare("INSERT INTO employee_shifts (employee_id, shift_start, shift_end, late_after, is_store, updated_at) VALUES (?,?,?,?,0,?)")->execute([$empId, $t['shift_start'], $t['shift_end'], $t['late_after'], date('Y-m-d H:i:s')]);
                shift_set_mode($pdo, $empId, 'fixed'); $did[] = 'shift ' . $t['shift_start'] . '-' . $t['shift_end'];
            } elseif ($t['mode'] === 'hours') {
                if (!$ex->fetch()) $pdo->prepare("INSERT INTO employee_shifts (employee_id, shift_start, shift_end, late_after, is_store, updated_at) VALUES (?,'','',15,0,?)")->execute([$empId, date('Y-m-d H:i:s')]);
                $e = shift_set_mode($pdo, $empId, 'hours', (float)$t['hours']); if ($e === '') $did[] = (float)$t['hours'] . ' hours a day, any time';
            } elseif ($t['mode'] === 'free') {
                if (!$ex->fetch()) $pdo->prepare("INSERT INTO employee_shifts (employee_id, shift_start, shift_end, late_after, is_store, updated_at) VALUES (?,'','',15,0,?)")->execute([$empId, date('Y-m-d H:i:s')]);
                if (shift_set_mode($pdo, $empId, 'free') === '') $did[] = 'no punch rule';
            }
        }
    }
    pp_log($pdo, $by, $nm . ' is now ' . $titleName . ($did ? ' (' . implode(', ', $did) . ')' : ''), $empId); pp_cache_drop($pdo);
    return $nm . ' is now ' . $titleName . ($did ? ': ' . implode(', ', $did) . '.' : ($t ? '.' : '. (Title not set up yet: add its pay and timing in People → Titles.)'));
}

function pp_contracts() {
    return [
        'people.html' => ['section' => 'hr', 'title' => 'People', 'topic' => 'hr',
            'counts' => 'Every person by department: salary, penalties, documents, seats and logins. The department list is the Boss Engine section list; the seats are the Boss Engine seats.',
            'overdue' => 'People with documents still missing; people without a login or without a seat they need; empty seats of a section.',
            'owners' => 'The HR & Admin seats ("hr.boss", "hr.checker", "hr.worker"). Admin and HR open People; only Admin creates or changes logins.'],
    ];
}

/** the Boss Engine tick hook: nobody is told anything here, it only keeps the salary cache from going stale across midnight */
function pp_tick(PDO $pdo, $now) { try { pp_ensure($pdo); $pdo->exec("DELETE FROM pp_cache WHERE at < DATE_SUB(NOW(), INTERVAL 2 DAY)"); } catch (Throwable $e) {} return []; }

function pp_dispatch(PDO $pdo, $action, array $auth, array $w, $by, array $in, $isPost) {
    if (strpos((string)$action, 'bd.pp_') !== 0) return;
    pp_ensure($pdo); pp_need_admin($auth);
    $post = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $isAdmin = (string)($auth['role'] ?? '') === 'Admin';
    $month = pp_month($_GET['month'] ?? ($in['month'] ?? ''));

    switch ($action) {
        case 'bd.pp_boot': {
            $depts = []; $secOf = [];
            foreach ($pdo->query("SELECT id, code, name, dept_ids FROM bd_sections WHERE active = 1")->fetchAll() as $s) foreach (array_filter(explode(',', (string)$s['dept_ids'])) as $d) $secOf[(int)$d] = ['id' => (int)$s['id'], 'name' => (string)$s['name'], 'code' => (string)$s['code']];
            foreach ($pdo->query("SELECT d.department_id AS id, d.department_name AS name, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id AND e.status != 'ex') AS n FROM departments d ORDER BY d.department_name")->fetchAll() as $d) {
                $depts[] = ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'count' => (int)$d['n'], 'section_id' => $secOf[(int)$d['id']]['id'] ?? 0, 'section' => $secOf[(int)$d['id']]['name'] ?? '', 'section_code' => $secOf[(int)$d['id']]['code'] ?? ''];
            }
            $roles = [];
            try { foreach ($pdo->query("SELECT role, COUNT(*) c FROM users GROUP BY role ORDER BY role")->fetchAll() as $r) $roles[] = ['name' => (string)$r['role'], 'count' => (int)$r['c']]; } catch (Throwable $e) {}
            $allRoles = [];
            try { foreach ($pdo->query("SELECT role_name FROM roles ORDER BY is_system DESC, role_name")->fetchAll() as $r) $allRoles[] = (string)$r['role_name']; } catch (Throwable $e) {}
            foreach (['Admin', 'HR', 'Employee'] as $r) if (!in_array($r, $allRoles, true)) $allRoles[] = $r;
            $types = []; try { foreach ($pdo->query("SELECT document_type_id AS id, document_name AS name, is_required AS req FROM document_types ORDER BY document_name")->fetchAll() as $t) $types[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'required' => (int)$t['req'] === 1]; } catch (Throwable $e) {}
            $managers = []; try { foreach ($pdo->query("SELECT employee_id AS id, full_name AS name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll() as $m) $managers[] = ['id' => (int)$m['id'], 'name' => (string)$m['name']]; } catch (Throwable $e) {}
            json_response(['ok' => true, 'me' => ['admin' => $isAdmin, 'name' => (string)($auth['username'] ?? '')], 'month' => $month, 'departments' => $depts, 'roles' => $roles, 'all_roles' => $allRoles, 'seats' => pp_seat_rows($pdo), 'doc_types' => $types, 'managers' => $managers]);
        }

        case 'bd.pp_overview': {
            $ex = ((string)($_GET['status'] ?? '')) === 'ex';
            $fresh = !empty($_GET['refresh']);
            $rows = $pdo->query("SELECT e.*,
                    (SELECT COUNT(*) FROM document_types dt WHERE NOT EXISTS (SELECT 1 FROM employee_documents ed WHERE ed.employee_id = e.employee_id AND ed.document_type_id = dt.document_type_id)) AS docs_missing,
                    (SELECT COUNT(*) FROM employee_documents ed2 WHERE ed2.employee_id = e.employee_id AND ed2.status = 'pending') AS docs_waiting
                FROM employees e WHERE e.status " . ($ex ? "= 'ex'" : "!= 'ex'") . " ORDER BY e.employee_code")->fetchAll();
            $logins = []; foreach ($pdo->query("SELECT user_id, username, role, employee_id FROM users WHERE employee_id IS NOT NULL")->fetchAll() as $u) $logins[(int)$u['employee_id']] = $u;
            $sal = $ex ? [] : pp_salary_map($pdo, $month, $fresh);
            $tm = $ex ? [] : pp_task_map($pdo);
            $seatBy = []; foreach (pp_seat_rows($pdo) as $s) foreach ($s['holders'] as $h) $seatBy[(int)$h][] = ['id' => $s['id'], 'title' => $s['title'], 'section' => $s['section']];
            $people = []; $T = ['people' => 0, 'monthly' => 0.0, 'est' => 0.0, 'pen' => 0.0, 'docs_missing' => 0, 'docs_people' => 0, 'docs_waiting' => 0, 'no_login' => 0, 'tasks' => 0, 'done' => 0, 'late' => 0, 'fu' => 0, 'ask' => 0, 'ask_amt' => 0.0];
            foreach ($rows as $r) {
                $id = (int)$r['employee_id']; $u = $logins[$id] ?? null; $s = $sal[$id] ?? null;
                $p = ['id' => $id, 'code' => (string)$r['employee_code'], 'name' => (string)$r['full_name'], 'mobile' => (string)($r['mobile'] ?? ''), 'designation' => (string)($r['designation'] ?? ''), 'photo' => (string)($r['photo'] ?? ''), 'dept_id' => (int)($r['department_id'] ?? 0),
                    'status' => (string)($r['status'] ?? ''), 'joined' => (string)($r['joining_date'] ?? ''), 'monthly' => (float)($r['monthly_salary'] ?? 0), 'est' => $s ? $s['est'] : null, 'pen' => $s ? $s['pen'] : 0.0,
                    'docs_missing' => (int)$r['docs_missing'], 'docs_waiting' => (int)$r['docs_waiting'], 'login' => $u ? ['user_id' => (int)$u['user_id'], 'username' => (string)$u['username'], 'role' => (string)$u['role']] : null, 'seats' => $seatBy[$id] ?? [], 't' => $tm[$id] ?? ['today' => 0, 'done' => 0, 'review' => 0, 'late' => 0, 'missed_today' => 0, 'missed7' => 0, 'fu' => 0, 'ask' => 0, 'ask_amt' => 0.0, 'charged' => 0.0]];
                $people[] = $p;
                $T['tasks'] += $p['t']['today']; $T['done'] += $p['t']['done']; $T['late'] += $p['t']['late']; $T['fu'] += $p['t']['fu']; $T['ask'] += $p['t']['ask']; $T['ask_amt'] += $p['t']['ask_amt'];
                $T['people']++; $T['monthly'] += $p['monthly']; $T['est'] += (float)($p['est'] ?? 0); $T['pen'] += $p['pen']; $T['docs_missing'] += $p['docs_missing']; if ($p['docs_missing'] > 0) $T['docs_people']++; $T['docs_waiting'] += $p['docs_waiting']; if (!$u) $T['no_login']++;
            }
            $empty = 0; foreach (pp_seat_rows($pdo) as $s) if (!$s['holders'] && in_array($s['kind'], ['boss', 'section_boss', 'worker'], true)) $empty++;
            $T['empty_seats'] = $empty; foreach (['monthly', 'est', 'pen', 'ask_amt'] as $k) $T[$k] = round($T[$k], 2);
            json_response(['ok' => true, 'month' => $month, 'people' => $people, 'totals' => $T, 'cached' => !$fresh, 'ex' => $ex]);
        }

        case 'bd.pp_person': {
            $id = (int)($_GET['id'] ?? 0); if (!$id) json_response(['error' => 'Choose a person.'], 422);
            try { $st = $pdo->prepare("SELECT e.*, d.department_name, m.full_name AS manager_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id LEFT JOIN employees m ON m.employee_id = e.reporting_manager_id WHERE e.employee_id = ?"); $st->execute([$id]); }
            catch (Throwable $x) { $st = $pdo->prepare("SELECT e.*, d.department_name, '' AS manager_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $st->execute([$id]); }
            $e = $st->fetch();
            if (!$e) json_response(['error' => 'Person not found.'], 404);
            $lg = $pdo->prepare("SELECT user_id, username, role FROM users WHERE employee_id = ? LIMIT 1"); $lg->execute([$id]); $login = $lg->fetch() ?: null;
            $docs = [];
            $ds = $pdo->prepare("SELECT dt.document_type_id AS tid, dt.document_name AS name, dt.is_required AS req, ed.employee_document_id AS did, ed.document_file AS file, ed.status, ed.document_number AS num, ed.expiry_date AS expiry, ed.uploaded_at AS at
                FROM document_types dt LEFT JOIN employee_documents ed ON ed.document_type_id = dt.document_type_id AND ed.employee_id = ? ORDER BY dt.document_name, ed.uploaded_at DESC");
            try { $ds->execute([$id]); $seen = [];
                foreach ($ds->fetchAll() as $d) { if (isset($seen[$d['tid']])) continue; $seen[$d['tid']] = 1; $docs[] = ['type_id' => (int)$d['tid'], 'name' => (string)$d['name'], 'required' => (int)$d['req'] === 1, 'doc_id' => $d['did'] ? (int)$d['did'] : 0, 'file' => (string)($d['file'] ?? ''), 'status' => (string)($d['status'] ?? ''), 'number' => (string)($d['num'] ?? ''), 'expiry' => (string)($d['expiry'] ?? '')]; }
            } catch (Throwable $x) { /* column names differ: the Documents tab then shows nothing instead of failing the whole page */ error_log('pp_person docs: ' . $x->getMessage()); }
            $sal = null;
            if (function_exists('salary_calc') && (float)$e['monthly_salary'] > 0) {
                try { $c = salary_calc($pdo, ['employee_id' => $id, 'full_name' => $e['full_name'], 'employee_code' => $e['employee_code'], 'monthly_salary' => $e['monthly_salary']], $month, salary_rules_load($pdo)); unset($c['days']); $sal = $c; } catch (Throwable $x) { error_log('pp_person sal: ' . $x->getMessage()); }
            }
            $agr = [];
            try { $as = $pdo->prepare("SELECT a.agreement_id AS id, t.template_name AS name, a.status, a.created_at AS at FROM agreements a JOIN agreement_templates t ON t.template_id = a.template_id WHERE a.employee_id = ? ORDER BY a.created_at DESC LIMIT 20"); $as->execute([$id]); foreach ($as->fetchAll() as $a) $agr[] = ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'status' => (string)$a['status'], 'at' => (string)$a['at']]; } catch (Throwable $x) {}
            $att = ['present' => 0, 'late' => 0];
            try { $q = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_date BETWEEN ? AND ? AND punch_in IS NOT NULL"); $q->execute([$id, $month . '-01', date('Y-m-t', strtotime($month . '-01'))]); $att['present'] = (int)$q->fetchColumn(); } catch (Throwable $x) {}
            $leave = [];
            try { $q = $pdo->prepare("SELECT leave_type, date_from, date_to, status FROM leave_requests WHERE employee_id = ? ORDER BY date_from DESC LIMIT 6"); $q->execute([$id]); $leave = $q->fetchAll(); } catch (Throwable $x) {}
            $seats = []; foreach (pp_seat_rows($pdo) as $s) if (in_array($id, $s['holders'], true)) $seats[] = $s['id'];
            $tasks = []; $pens = []; $day = pp_day();
            try {
                $q = $pdo->prepare("SELECT r.id, i.title, i.kind, r.status, r.run_date, r.due_at, i.section_code, i.pen_amount, i.proof FROM b2_runs r JOIN b2_items i ON i.id = r.item_id
                    WHERE r.owner_emp = ? AND r.status <> 'excused' AND (r.run_date = ? OR r.status IN ('open','review')) ORDER BY FIELD(r.status,'open','review','missed','done'), r.due_at LIMIT 60");
                $q->execute([$id, $day]); $nowS = function_exists('bd_now') ? bd_now() : date('Y-m-d H:i:s');
                foreach ($q->fetchAll() as $r) $tasks[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'], 'date' => (string)$r['run_date'], 'due' => (string)$r['due_at'], 'late' => $r['status'] === 'open' && (string)$r['due_at'] < $nowS, 'section' => (string)$r['section_code'], 'pen' => (float)$r['pen_amount']];
            } catch (Throwable $x) {}
            try {
                $q = $pdo->prepare("SELECT p.amount, p.reason, p.state, p.created_at, i.title FROM b2_pens p LEFT JOIN b2_items i ON i.id = p.item_id WHERE p.emp_id = ? ORDER BY p.id DESC LIMIT 12"); $q->execute([$id]);
                foreach ($q->fetchAll() as $r) $pens[] = ['amount' => (float)$r['amount'], 'reason' => (string)($r['reason'] ?: $r['title']), 'state' => (string)$r['state'], 'at' => (string)$r['created_at']];
            } catch (Throwable $x) {}
            $tm = pp_task_map($pdo); $tstat = $tm[$id] ?? null;
            unset($e['password'], $e['password_hash']);
            json_response(['ok' => true, 'tasks' => $tasks, 'task_pens' => $pens, 'task_stat' => $tstat, 'month' => $month, 'person' => $e, 'login' => $login, 'documents' => $docs, 'salary' => $sal, 'agreements' => $agr, 'attendance' => $att, 'leave' => $leave, 'seat_ids' => $seats]);
        }

        case 'bd.pp_titles': {
            pp_titles_ensure($pdo);
            $titles = $pdo->query("SELECT * FROM pp_titles ORDER BY sort_no, name")->fetchAll();
            $people = $pdo->query("SELECT e.employee_id id, e.full_name name, e.designation title, e.monthly_salary salary, e.photo, d.department_name dept FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' ORDER BY e.full_name")->fetchAll();
            $by = []; $orig = []; foreach ($people as $p) { $k = mb_strtolower(trim((string)$p['title'])); $orig[$k] = trim((string)$p['title']); $by[$k][] = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'photo' => (string)($p['photo'] ?? ''), 'salary' => $isAdmin ? (float)$p['salary'] : null, 'dept' => (string)$p['dept']]; }
            $out = []; $seen = [];
            foreach ($titles as $t) { $k = mb_strtolower($t['name']); $seen[$k] = 1; $out[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'salary' => $isAdmin ? (float)$t['salary'] : null, 'mode' => $t['mode'], 'shift_start' => $t['shift_start'], 'shift_end' => $t['shift_end'], 'hours' => (float)$t['hours'], 'late_after' => (int)$t['late_after'], 'sort_no' => (int)$t['sort_no'], 'note' => $t['note'], 'defined' => true, 'people' => $by[$k] ?? []]; }
            foreach ($by as $k => $list) { if ($k === '' || isset($seen[$k])) continue; $out[] = ['id' => 0, 'name' => $orig[$k], 'salary' => 0, 'mode' => 'fixed', 'shift_start' => '', 'shift_end' => '', 'hours' => 8, 'late_after' => 15, 'sort_no' => 999, 'note' => '', 'defined' => false, 'people' => $list]; }
            json_response(['ok' => true, 'titles' => $out, 'no_title' => $by[''] ?? []]);
        }

        case 'bd.pp_title_save': {
            $post(); pp_titles_ensure($pdo); if (!$isAdmin) json_response(['error' => 'Only Admin sets pay and timing.'], 403);
            $c = pp_title_clean($in); if ($c['name'] === '') json_response(['error' => 'Give the title a name.'], 422);
            $id = pp_title_save($pdo, $c, (int)($in['id'] ?? 0));
            pp_log($pdo, $by, 'title ' . $c['name'] . ' saved'); json_response(['ok' => true, 'id' => $id, 'message' => 'Saved.']);
        }

        case 'bd.pp_title_delete': {
            $post(); pp_titles_ensure($pdo); if (!$isAdmin) json_response(['error' => 'Only Admin.'], 403);
            $pdo->prepare("DELETE FROM pp_titles WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true, 'message' => 'Title removed. People keep the name; only its pay and timing defaults are gone.']);
        }

        case 'bd.pp_title_assign': {
            $post(); if (!$isAdmin) json_response(['error' => 'Only Admin.'], 403);
            $ids = array_values(array_filter(array_map('intval', (array)($in['emp_ids'] ?? [])))); $msgs = [];
            foreach ($ids as $i) $msgs[] = pp_title_apply($pdo, $i, (string)($in['title'] ?? ''), !empty($in['apply']), $by);
            json_response(['ok' => true, 'message' => $msgs ? implode(' ', $msgs) : 'Choose at least one person.']);
        }

        case 'bd.pp_docs_watch': {
            $days = max(1, min(365, (int)($_GET['days'] ?? 30))); $rows = [];
            try {
                $q = $pdo->prepare("SELECT ed.employee_document_id id, ed.employee_id emp, e.full_name name, e.photo, dt.document_type_id tid, dt.document_name doc, ed.expiry_date exp, DATEDIFF(ed.expiry_date, CURDATE()) left_days
                    FROM employee_documents ed JOIN employees e ON e.employee_id = ed.employee_id AND e.status != 'ex' JOIN document_types dt ON dt.document_type_id = ed.document_type_id
                    WHERE ed.expiry_date IS NOT NULL AND ed.expiry_date <> '0000-00-00' AND ed.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY ed.expiry_date");
                $q->execute([$days]); $rows = $q->fetchAll();
            } catch (Throwable $x) { error_log('pp_docs_watch: ' . $x->getMessage()); }
            $noDate = [];
            try { foreach ($pdo->query("SELECT ed.employee_id emp, e.full_name name, dt.document_type_id tid, dt.document_name doc FROM employee_documents ed JOIN employees e ON e.employee_id = ed.employee_id AND e.status != 'ex' JOIN document_types dt ON dt.document_type_id = ed.document_type_id WHERE (ed.expiry_date IS NULL OR ed.expiry_date = '0000-00-00') AND LOWER(dt.document_name) REGEXP 'licen|insur|passport|visa|permit'")->fetchAll() as $r) $noDate[] = $r; } catch (Throwable $x) {}
            $types = []; try { $types = $pdo->query("SELECT document_type_id id, document_name name FROM document_types ORDER BY document_name")->fetchAll(); } catch (Throwable $x) {}
            json_response(['ok' => true, 'expiring' => $rows, 'no_end_date' => $noDate, 'types' => $types, 'days' => $days]);
        }

        case 'bd.pp_doc_expiry': {
            $post(); $tid = (int)($in['type_id'] ?? 0); $date = trim((string)($in['expiry_date'] ?? ''));
            if (!$tid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_response(['error' => 'Choose a document type and an end date.'], 422);
            $ids = array_values(array_filter(array_map('intval', (array)($in['emp_ids'] ?? [])))); $sql = "UPDATE employee_documents SET expiry_date = ? WHERE document_type_id = ?"; $par = [$date, $tid];
            if ($ids) { $sql .= " AND employee_id IN (" . implode(',', $ids) . ")"; } else { $sql .= " AND (expiry_date IS NULL OR expiry_date = '0000-00-00')"; }
            $st = $pdo->prepare($sql); $st->execute($par); pp_log($pdo, $by, 'end date ' . $date . ' set on ' . $st->rowCount() . ' documents (type ' . $tid . ')');
            json_response(['ok' => true, 'message' => 'End date set on ' . $st->rowCount() . ' document' . ($st->rowCount() === 1 ? '' : 's') . '.']);
        }

        case 'bd.pp_move': {
            $post(); $emp = (int)($in['emp_id'] ?? 0); $dep = (int)($in['dept_id'] ?? 0);
            $d = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?"); $d->execute([$dep]); $dn = $d->fetchColumn();
            if (!$emp || $dn === false) json_response(['error' => 'Choose a person and a department.'], 422);
            $pdo->prepare("UPDATE employees SET department_id = ? WHERE employee_id = ?")->execute([$dep, $emp]);
            pp_log($pdo, $by, 'moved ' . bd_emp_name($pdo, $emp) . ' to ' . $dn, $emp); pp_cache_drop($pdo);
            json_response(['ok' => true, 'message' => bd_emp_name($pdo, $emp) . ' is now in ' . $dn . '.']);
        }

        case 'bd.pp_dept_save': {
            $post(); $name = trim((string)($in['name'] ?? '')); $id = (int)($in['id'] ?? 0); if ($name === '') json_response(['error' => 'Give the department a name.'], 422);
            $dupq = $pdo->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(?) AND department_id <> ?"); $dupq->execute([$name, $id]);
            if ($dupq->fetchColumn()) json_response(['error' => 'A department with that name already exists.'], 409);
            try {
                if ($id) { $pdo->prepare("UPDATE departments SET department_name = ? WHERE department_id = ?")->execute([mb_substr($name, 0, 120), $id]); json_response(['ok' => true, 'id' => $id, 'message' => 'Renamed.']); }
                $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)")->execute([mb_substr($name, 0, 120)]); $nid = (int)$pdo->lastInsertId();
            } catch (PDOException $e) { json_response(['error' => 'A department with that name already exists.'], 409); }
            $sec = 0; if (function_exists('bd_map_departments')) { try { bd_map_departments($pdo, true); $secs = bd_dept_section_map($pdo); $sec = $secs[$nid] ?? 0; } catch (Throwable $e) { error_log('pp_dept_save map: ' . $e->getMessage()); } }
            pp_log($pdo, $by, 'created department ' . $name, 0);
            json_response(['ok' => true, 'id' => $nid, 'section_id' => $sec, 'message' => 'Department added' . ($sec ? ' and linked to the Boss Engine (its seats are ready).' : '.')]);
        }

        case 'bd.pp_dept_delete': {
            $post(); $id = (int)($in['id'] ?? 0); $to = (int)($in['move_to'] ?? 0); if (!$id) json_response(['error' => 'Choose a department.'], 422);
            $n = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE department_id = ?"); $n->execute([$id]); $cnt = (int)$n->fetchColumn(); $moved = 0;
            if ($cnt > 0) {
                if (!$to || $to === $id) json_response(['error' => $cnt . ' people are still in this department. Choose where they move to, then delete.', 'people' => $cnt], 409);
                $d = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?"); $d->execute([$to]); if ($d->fetchColumn() === false) json_response(['error' => 'The department you chose to move them to does not exist.'], 422);
                $pdo->prepare("UPDATE employees SET department_id = ? WHERE department_id = ?")->execute([$to, $id]); $moved = $cnt;
            }
            try { $pdo->prepare("DELETE FROM departments WHERE department_id = ?")->execute([$id]); } catch (PDOException $e) { json_response(['error' => 'This department is still in use.'], 409); }
            try {   // the Boss Engine section keeps its seats and tasks; it just stops pointing at a department that is gone
                foreach ($pdo->query("SELECT id, dept_ids FROM bd_sections WHERE dept_ids IS NOT NULL AND dept_ids <> ''")->fetchAll() as $sx) {
                    $ids = array_values(array_filter(explode(',', (string)$sx['dept_ids']), function ($v) use ($id) { return (int)$v !== $id && $v !== ''; }));
                    if (count($ids) !== count(array_filter(explode(',', (string)$sx['dept_ids']), 'strlen'))) $pdo->prepare("UPDATE bd_sections SET dept_ids = ? WHERE id = ?")->execute([$ids ? implode(',', $ids) : null, (int)$sx['id']]);
                }
            } catch (Throwable $e) {}
            pp_log($pdo, $by, 'deleted department #' . $id . ($moved ? ' (' . $moved . ' people moved)' : ''), 0); pp_cache_drop($pdo);
            json_response(['ok' => true, 'moved' => $moved, 'message' => 'Department deleted.' . ($moved ? ' ' . $moved . ' people were moved.' : '')]);
        }

        case 'bd.pp_pen_dept': {   // penalties by department for a month, for the Boss Penalties tab and People
            $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); $rows = [];
            try {
                $dn = []; foreach ($pdo->query("SELECT department_id id, department_name n FROM departments")->fetchAll() as $d) $dn[(int)$d['id']] = (string)$d['n'];
                $by0 = function ($id) use (&$rows, $dn) { if (!isset($rows[$id])) $rows[$id] = ['dept_id' => $id, 'name' => $id ? ($dn[$id] ?? 'Department') : 'No department', 'people' => 0, 'applied' => 0.0, 'n' => 0, 'boss' => 0.0, 'ask' => 0, 'ask_amt' => 0.0]; };
                foreach ($pdo->query("SELECT COALESCE(department_id,0) d, COUNT(*) c FROM employees WHERE status <> 'ex' GROUP BY COALESCE(department_id,0)")->fetchAll() as $r) { $by0((int)$r['d']); $rows[(int)$r['d']]['people'] = (int)$r['c']; }
                $q = $pdo->prepare("SELECT COALESCE(e.department_id,0) d, COUNT(*) c, SUM(p.amount) a, SUM(CASE WHEN p.source = 'boss' OR p.auto_key LIKE 'b2:%' THEN p.amount ELSE 0 END) b
                    FROM employee_penalties p JOIN employees e ON e.employee_id = p.employee_id WHERE p.status = 'applied' AND p.penalty_date BETWEEN ? AND ? GROUP BY COALESCE(e.department_id,0)");
                $q->execute([$from, $to]); foreach ($q->fetchAll() as $r) { $by0((int)$r['d']); $rows[(int)$r['d']]['applied'] = round((float)$r['a'], 2); $rows[(int)$r['d']]['n'] = (int)$r['c']; $rows[(int)$r['d']]['boss'] = round((float)$r['b'], 2); }
                foreach ($pdo->query("SELECT COALESCE(e.department_id,0) d, COUNT(*) c, SUM(p.amount) a FROM b2_pens p JOIN employees e ON e.employee_id = p.emp_id WHERE p.state = 'ask' GROUP BY COALESCE(e.department_id,0)")->fetchAll() as $r) { $by0((int)$r['d']); $rows[(int)$r['d']]['ask'] = (int)$r['c']; $rows[(int)$r['d']]['ask_amt'] = round((float)$r['a'], 2); }
            } catch (Throwable $e) { error_log('pp_pen_dept: ' . $e->getMessage()); }
            $list = array_values($rows); usort($list, function ($a, $b) { return $b['applied'] <=> $a['applied'] ?: strcmp($a['name'], $b['name']); });
            json_response(['ok' => true, 'month' => $month, 'rows' => $list, 'total' => round(array_sum(array_column($list, 'applied')), 2), 'ask_total' => round(array_sum(array_column($list, 'ask_amt')), 2)]);
        }

        case 'bd.pp_seats_set': {
            $post(); $emp = (int)($in['emp_id'] ?? 0); $want = array_values(array_unique(array_filter(array_map('intval', (array)($in['seat_ids'] ?? []))))); if (!$emp) json_response(['error' => 'Choose a person.'], 422);
            $changed = 0;
            foreach (pp_seat_rows($pdo) as $s) {
                $has = in_array($emp, $s['holders'], true); $should = in_array($s['id'], $want, true);
                if ($has === $should) continue;
                $list = $s['holders']; if ($should) $list[] = $emp; else $list = array_values(array_diff($list, [$emp]));
                bd_set_holders($pdo, $s['id'], $list); $changed++;
            }
            pp_log($pdo, $by, 'seats of ' . bd_emp_name($pdo, $emp) . ' changed (' . $changed . ')', $emp);
            json_response(['ok' => true, 'changed' => $changed, 'message' => $changed ? 'Seats saved. The Inbox, Complaints and Boss Engine use them at once.' : 'Nothing changed.']);
        }

        case 'bd.pp_login_save': {
            $post(); pp_need_real_admin($auth);
            $emp = (int)($in['emp_id'] ?? 0); $username = trim((string)($in['username'] ?? '')); $password = (string)($in['password'] ?? ''); $role = trim((string)($in['role'] ?? ''));
            if (!$emp) json_response(['error' => 'Choose a person.'], 422); if ($username === '') json_response(['error' => 'Write a username.'], 422);
            $okRole = in_array($role, ['Admin', 'HR', 'Employee'], true);
            if (!$okRole) { try { $rc = $pdo->prepare("SELECT 1 FROM roles WHERE role_name = ?"); $rc->execute([$role]); $okRole = (bool)$rc->fetchColumn(); } catch (Throwable $e) {} }
            if (!$okRole) json_response(['error' => 'Unknown role.'], 422);
            $cur = $pdo->prepare("SELECT user_id, role FROM users WHERE employee_id = ? LIMIT 1"); $cur->execute([$emp]); $c = $cur->fetch();
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id <> ?"); $dup->execute([$username, $c ? (int)$c['user_id'] : 0]); if ($dup->fetchColumn()) json_response(['error' => 'That username is already taken.'], 409);
            if ($c) {
                if ($c['role'] === 'Admin' && $role !== 'Admin' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn() <= 1) json_response(['error' => 'This is the only Admin login; it cannot be changed to another role.'], 409);
                if ($password !== '') { if (mb_strlen($password) < 6) json_response(['error' => 'Password must be at least 6 characters.'], 422);
                    $pdo->prepare("UPDATE users SET username = ?, role = ?, password_hash = ? WHERE user_id = ?")->execute([$username, $role, password_hash($password, PASSWORD_BCRYPT), (int)$c['user_id']]); $msg = 'Login updated and the password changed.'; }
                else { $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE user_id = ?")->execute([$username, $role, (int)$c['user_id']]); $msg = 'Login updated.'; }
                pp_log($pdo, $by, 'login of ' . bd_emp_name($pdo, $emp) . ' updated', $emp); json_response(['ok' => true, 'message' => $msg]);
            }
            if (mb_strlen($password) < 6) json_response(['error' => 'Password must be at least 6 characters.'], 422);
            $pdo->prepare("INSERT INTO users (username, password_hash, role, employee_id) VALUES (?, ?, ?, ?)")->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $emp]);
            pp_log($pdo, $by, 'login created for ' . bd_emp_name($pdo, $emp), $emp); json_response(['ok' => true, 'message' => 'Login created. Give them the username and the password.']);
        }

        case 'bd.pp_login_remove': {
            $post(); pp_need_real_admin($auth); $emp = (int)($in['emp_id'] ?? 0);
            $cur = $pdo->prepare("SELECT user_id, role FROM users WHERE employee_id = ? LIMIT 1"); $cur->execute([$emp]); $c = $cur->fetch(); if (!$c) json_response(['error' => 'This person has no login.'], 404);
            if ((int)$c['user_id'] === (int)($auth['user_id'] ?? 0)) json_response(['error' => 'You cannot remove the login you are using.'], 422);
            if ($c['role'] === 'Admin' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn() <= 1) json_response(['error' => 'This is the only Admin login.'], 409);
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([(int)$c['user_id']]); pp_log($pdo, $by, 'login of ' . bd_emp_name($pdo, $emp) . ' removed', $emp);
            json_response(['ok' => true, 'message' => 'Login removed. The person stays in People.']);
        }

        case 'bd.pp_doc_file': {   // a document file, only for Admin / HR (the uploads folder is otherwise public by path)
            $did = (int)($_GET['id'] ?? 0); $st = $pdo->prepare("SELECT document_file FROM employee_documents WHERE employee_document_id = ?"); $st->execute([$did]); $f = (string)$st->fetchColumn();
            if ($f === '' || strpos($f, '..') !== false || strpos($f, 'uploads/') !== 0) json_response(['error' => 'File not found.'], 404);
            $p = __DIR__ . '/' . $f; if (!is_file($p)) json_response(['error' => 'File not found.'], 404);
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION)); $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($p)); header('Cache-Control: private, max-age=300'); readfile($p); exit;
        }

        default: json_response(['error' => 'Unknown people action.'], 404);
    }
}
