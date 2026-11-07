<?php
/**
 * leader_helper.php — "👥 Leaders Helper": the leaders' helpers (Ankit Gandhi, Jatin Jadav, Rudra Kachariya, Naz Madam) on a fixed monthly salary
 * (default ₹21,000), no timing rules. Each day has a checklist of work; the day's payout follows the work that is DONE.
 * Included from api.php (one line) and answers only the actions that start with "lh.".   PHP 7.4 safe.
 *
 *   lh.get          GET  ?month=YYYY-MM   → settings, people, day-by-day rows, totals, the daily checklist template   (Admin / HR / Sales Head)
 *   lh.mine         GET  ?month=YYYY-MM   → the logged-in helper's own rows (read-only)
 *   lh.cfg_save     POST {days, mode, salary}          settings: days in a month (30), mode 'proportional' | 'all', default salary
 *   lh.person_add   POST {employee_id, salary?}        lh.person_save {employee_id, salary, active}      lh.person_del {employee_id}
 *   lh.tpl_save     POST {id?, employee_id (0 = everyone), title, active}      lh.tpl_del {id}          the checklist that is made again EVERY day, by itself
 *   lh.task_add     POST {employee_id, day, title}     lh.task_done {id, done}     lh.task_del {id}
 *   lh.staff        GET  ?q=                           search employees to add
 *   Editing (everything except lh.get / lh.mine / lh.staff): Admin, HR and Sales Head only. The helpers only read.
 *
 * The rule: day amount = salary ÷ days (30).
 *   punch (default) → the whole day amount is written automatically the moment the person punches in (Attendance); no punch = ₹0. The work list is shown next to it.
 *   proportional → day amount × (tasks done ÷ tasks of that day) · all → the whole day amount only when every task is done, else ₹0.
 *   A past day with no tasks at all pays the whole day amount (nothing was given to do). Today pays only for what is done so far.
 * The checklist template makes its tasks for each day by itself (only for days from the day the item was added: old days never change).
 */
if (!function_exists('lh_cfg')) {

function lh_cfg(PDO $pdo): array
{
    $d = ['salary' => 21000, 'days' => 30, 'mode' => 'punch'];
    $j = json_decode((string)setting_get($pdo, 'lh_cfg', ''), true); if (!is_array($j)) return $d;
    return ['salary' => isset($j['salary']) && is_numeric($j['salary']) ? max(0, (float)$j['salary']) : $d['salary'], 'days' => isset($j['days']) && (int)$j['days'] > 0 ? min(31, (int)$j['days']) : $d['days'],
        'mode' => in_array(($j['mode'] ?? ''), ['punch', 'all', 'proportional'], true) ? $j['mode'] : $d['mode']];
}
function lh_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lh_people (employee_id INT NOT NULL PRIMARY KEY, salary DECIMAL(10,2) NOT NULL DEFAULT 21000, active TINYINT NOT NULL DEFAULT 1, added_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lh_tpl (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL DEFAULT 0, title VARCHAR(160) NOT NULL, from_day DATE NOT NULL, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lh_tasks (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, day DATE NOT NULL, title VARCHAR(160) NOT NULL, tpl_id INT NULL, done TINYINT NOT NULL DEFAULT 0, done_at DATETIME NULL, done_by INT NOT NULL DEFAULT 0, created_by INT NOT NULL DEFAULT 0,
            UNIQUE KEY u_tpl (employee_id, day, tpl_id), KEY k_day (day, employee_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE lh_people ADD COLUMN note VARCHAR(120) NOT NULL DEFAULT ''"); } catch (Throwable $e) { }   // who the person helps (shown under the name)
        return $ok = true;
    } catch (Throwable $e) { error_log('lh_tables: ' . $e->getMessage()); return $ok = false; }
}
/** Admin / HR, or a Sales Head (from the Department / Designation): the people who may change things. */
function lh_can_edit(PDO $pdo, array $auth): bool
{
    if (in_array(strtolower((string)($auth['role'] ?? '')), ['admin', 'hr'], true)) return true;
    $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0) return false;
    try {
        $q = $pdo->prepare("SELECT d.department_name, e.designation FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $q->execute([$me]); $r = $q->fetch();
        return $r && function_exists('sales_role_kind') && sales_role_kind((string)$r['department_name'], (string)$r['designation']) === 'head';
    } catch (Throwable $e) { return false; }
}
/** One-time fix for installs that were already seeded: Nazima Karodiya is not a helper (taken out), and the three helpers get their "who they help" line. */
function lh_migrate(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'lh_fix1', '') === '1') return; setting_set($pdo, 'lh_fix1', '1');
    try {
        $pdo->exec("UPDATE lh_people p JOIN employees e ON e.employee_id = p.employee_id SET p.active = 0 WHERE e.full_name LIKE '%Nazima%'");
        foreach ([['Ankit', 'Gandhi', "Dipti Madam's work"], ['Jatin', 'Jadav', 'Campaign · helps Pradeep Chauhan'], ['Rudra', 'Kachar', 'Query check · helps Ayaz Bhai']] as [$a, $b, $note])
            $pdo->prepare("UPDATE lh_people p JOIN employees e ON e.employee_id = p.employee_id SET p.note = ? WHERE e.full_name LIKE ? AND e.full_name LIKE ? AND p.note = ''")->execute([$note, '%' . $a . '%', '%' . $b . '%']);
    } catch (Throwable $e) { error_log('lh_migrate: ' . $e->getMessage()); }
}
/** Once, when nobody is added yet: find the people by name. Returns the names that were not found. */
function lh_seed(PDO $pdo): array
{
    if ((string)setting_get($pdo, 'lh_seeded', '') === '1') return [];
    setting_set($pdo, 'lh_seeded', '1'); $cfg = lh_cfg($pdo); $miss = [];
    foreach ([['Ankit', 'Gandhi', "Dipti Madam's work"], ['Jatin', 'Jadav', 'Campaign · helps Pradeep Chauhan'], ['Rudra', 'Kachar', 'Query check · helps Ayaz Bhai']] as [$a, $b, $note]) {
        try {
            $q = $pdo->prepare("SELECT employee_id FROM employees WHERE COALESCE(status, 'approved') <> 'ex' AND full_name LIKE ? AND full_name LIKE ? ORDER BY employee_id LIMIT 1"); $q->execute(['%' . $a . '%', '%' . $b . '%']); $id = (int)$q->fetchColumn();
            if ($id > 0) $pdo->prepare("INSERT IGNORE INTO lh_people (employee_id, salary, active, added_at, note) VALUES (?, ?, 1, ?, ?)")->execute([$id, $cfg['salary'], date('Y-m-d H:i:s'), $note]); else $miss[] = trim($a . ' ' . $b);
        } catch (Throwable $e) { $miss[] = $a; }
    }
    return $miss;
}
function lh_people(PDO $pdo, bool $onlyActive = true): array
{
    $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    $out = []; foreach ($pdo->query("SELECT employee_id, salary, active, note FROM lh_people ORDER BY added_at, employee_id")->fetchAll() as $r) {
        if ($onlyActive && (int)$r['active'] !== 1) continue; $id = (int)$r['employee_id'];
        $out[] = ['id' => $id, 'name' => (string)($names[$id] ?? ('#' . $id)), 'salary' => (float)$r['salary'], 'active' => (int)$r['active'] === 1, 'note' => (string)($r['note'] ?? '')];
    }
    return $out;
}
/** The day's checklist made from the template (only days from the item's own start day; never twice). */
function lh_ensure_day(PDO $pdo, int $emp, string $day): void
{
    try {
        $q = $pdo->prepare("SELECT id, title FROM lh_tpl WHERE active = 1 AND (employee_id = 0 OR employee_id = ?) AND from_day <= ? ORDER BY sort_no, id"); $q->execute([$emp, $day]);
        $ins = $pdo->prepare("INSERT IGNORE INTO lh_tasks (employee_id, day, title, tpl_id) VALUES (?, ?, ?, ?)");
        foreach ($q->fetchAll() as $t) $ins->execute([$emp, $day, $t['title'], (int)$t['id']]);
    } catch (Throwable $e) { }
}
function lh_day_pay(array $cfg, float $salary, int $done, int $total, bool $partial, bool $punched = false): array
{
    $day = $cfg['days'] > 0 ? $salary / $cfg['days'] : 0;
    if ($cfg['mode'] === 'punch') return [$punched ? round($day, 2) : 0.0, $day];   // the day's salary is written the moment the person punches in
    if ($total === 0) return [$partial ? 0.0 : round($day, 2), $day];
    $amt = $cfg['mode'] === 'all' ? ($done >= $total ? $day : 0) : $day * $done / $total;
    return [round($amt, 2), $day];
}
function lh_month(PDO $pdo, string $month, array $only = []): array
{
    $cfg = lh_cfg($pdo); $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $first = $month . '-01'; $last = date('Y-m-t', strtotime($first));
    $people = lh_people($pdo); if ($only) $people = array_values(array_filter($people, fn($p) => in_array($p['id'], $only, true)));
    $to = min($last, $today); $days = [];
    for ($d = strtotime($first); date('Y-m-d', $d) <= $to; $d = strtotime('+1 day', $d)) $days[] = date('Y-m-d', $d);
    foreach ($people as $p) foreach ($days as $d) lh_ensure_day($pdo, $p['id'], $d);
    $punch = []; if ($days && $people) {   // the punch-in of each helper for each day (Attendance)
        try {
            $in = implode(',', array_map('intval', array_column($people, 'id')));
            foreach ($pdo->query("SELECT employee_id, punch_date, punch_in FROM attendance WHERE punch_in IS NOT NULL AND punch_date BETWEEN '" . $first . "' AND '" . $to . "' AND employee_id IN ($in)")->fetchAll() as $a) $punch[(int)$a['employee_id']][substr((string)$a['punch_date'], 0, 10)] = (string)$a['punch_in'];
        } catch (Throwable $e) { }
    }
    $tasks = []; if ($days && $people) {
        $q = $pdo->prepare("SELECT id, employee_id, day, title, done, done_at FROM lh_tasks WHERE day BETWEEN ? AND ? ORDER BY id"); $q->execute([$first, $to]);
        foreach ($q->fetchAll() as $t) $tasks[(int)$t['employee_id']][(string)$t['day']][] = ['id' => (int)$t['id'], 'title' => (string)$t['title'], 'done' => (int)$t['done'] === 1, 'at' => (string)($t['done_at'] ?? '')];
    }
    $tot = []; foreach ($people as $p) $tot[$p['id']] = ['earned' => 0.0, 'missed' => 0.0, 'done' => 0, 'tasks' => 0];
    $rows = [];
    foreach ($days as $d) {
        $per = []; $partial = $d >= $today;
        foreach ($people as $p) {
            $tl = $tasks[$p['id']][$d] ?? []; $n = count($tl); $dn = count(array_filter($tl, fn($t) => $t['done']));
            $pi = $punch[$p['id']][$d] ?? ''; [$amt, $full] = lh_day_pay($cfg, (float)$p['salary'], $dn, $n, $partial, $pi !== '');
            $per[$p['id']] = ['tasks' => $tl, 'done' => $dn, 'total' => $n, 'amount' => $amt, 'full' => round($full, 2), 'punched' => $pi !== '', 'punch_in' => $pi !== '' ? substr($pi, 11, 5) : ''];
            $t = &$tot[$p['id']]; $t['earned'] += $amt; $t['done'] += $dn; $t['tasks'] += $n; if (!$partial) $t['missed'] += max(0, $full - $amt); unset($t);
        }
        $rows[] = ['date' => $d, 'dow' => date('D', strtotime($d)), 'partial' => $partial, 'per' => $per];
    }
    foreach ($tot as &$t) { $t['earned'] = round($t['earned'], 2); $t['missed'] = round($t['missed'], 2); } unset($t);
    return ['cfg' => $cfg, 'people' => $people, 'rows' => array_reverse($rows), 'totals' => $tot, 'month' => $month, 'today' => $today];
}
function lh_month_arg(): string { $m = (string)($_GET['month'] ?? ''); return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : date('Y-m'); }

}   // function_exists guard

$__lh = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__lh, 'lh.') === 0) {
    $auth = require_auth();
    if (!lh_tables($pdo)) json_response(['error' => 'Could not prepare the Leaders Helper tables.'], 500);
    lh_migrate($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $me = (int)($auth['employee_id'] ?? 0); $edit = lh_can_edit($pdo, $auth);
    $needEdit = function () use ($edit, $isPost) { if (!$edit) json_response(['error' => 'Only the Admin, HR or the Sales Head can change this.'], 403); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    switch ($__lh) {
        case 'lh.get': {
            if (!$edit) json_response(['error' => 'Leaders Helper is for the Admin, HR and the Sales Head.'], 403);
            $miss = lh_seed($pdo); $r = lh_month($pdo, lh_month_arg()); $r['can_edit'] = true; $r['missing'] = $miss;
            $q = $pdo->query("SELECT id, employee_id, title, active, from_day FROM lh_tpl ORDER BY sort_no, id"); $r['tpl'] = array_map(fn($t) => ['id' => (int)$t['id'], 'employee_id' => (int)$t['employee_id'], 'title' => (string)$t['title'], 'active' => (int)$t['active'] === 1, 'from' => (string)$t['from_day']], $q->fetchAll());
            $r['inactive'] = array_values(array_filter(lh_people($pdo, false), fn($p) => !$p['active']));
            json_response($r);
        }
        case 'lh.mine': {
            $mine = array_values(array_filter(lh_people($pdo), fn($p) => $p['id'] === $me));
            if ($me <= 0 || !$mine) json_response(['helper' => false]);
            $r = lh_month($pdo, lh_month_arg(), [$me]); $r['helper'] = true; $r['can_edit'] = false; json_response($r);
        }
        case 'lh.staff': {
            if (!$edit) json_response(['error' => 'Not allowed.'], 403); $q = '%' . trim((string)($_GET['q'] ?? '')) . '%';
            $st = $pdo->prepare("SELECT employee_id AS id, full_name AS name FROM employees WHERE COALESCE(status, 'approved') <> 'ex' AND full_name LIKE ? AND employee_id NOT IN (SELECT employee_id FROM lh_people WHERE active = 1) ORDER BY full_name LIMIT 30"); $st->execute([$q]);
            json_response(['staff' => $st->fetchAll()]);
        }
        case 'lh.cfg_save': {
            $needEdit(); $in = json_input(); $c = lh_cfg($pdo);
            if (isset($in['days'])) { $d = (int)$in['days']; if ($d < 20 || $d > 31) json_response(['error' => 'Days in a month: 20 to 31.'], 422); $c['days'] = $d; }
            if (isset($in['mode'])) $c['mode'] = in_array($in['mode'], ['punch', 'all', 'proportional'], true) ? $in['mode'] : 'punch';
            if (isset($in['salary'])) { if (!is_numeric($in['salary']) || (float)$in['salary'] < 0) json_response(['error' => 'Salary must be a number.'], 422); $c['salary'] = (float)$in['salary']; }
            setting_set($pdo, 'lh_cfg', json_encode($c)); json_response(['ok' => true, 'cfg' => $c]);
        }
        case 'lh.person_add': {
            $needEdit(); $in = json_input(); $id = (int)($in['employee_id'] ?? 0); $c = lh_cfg($pdo); $sal = isset($in['salary']) && is_numeric($in['salary']) ? (float)$in['salary'] : $c['salary'];
            $n = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?"); $n->execute([$id]); if (!(int)$n->fetchColumn()) json_response(['error' => 'Person not found.'], 404);
            $pdo->prepare("INSERT INTO lh_people (employee_id, salary, active, added_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE active = 1, salary = VALUES(salary)")->execute([$id, $sal, date('Y-m-d H:i:s')]); json_response(['ok' => true]);
        }
        case 'lh.person_save': {
            $needEdit(); $in = json_input(); $id = (int)($in['employee_id'] ?? 0); $sal = $in['salary'] ?? null; if ($sal !== null && $sal !== '' && (!is_numeric($sal) || (float)$sal < 0)) json_response(['error' => 'Salary must be a number.'], 422);
            if ($sal !== null && $sal !== '') $pdo->prepare("UPDATE lh_people SET salary = ? WHERE employee_id = ?")->execute([(float)$sal, $id]);
            if (array_key_exists('note', $in)) $pdo->prepare("UPDATE lh_people SET note = ? WHERE employee_id = ?")->execute([trim(mb_substr((string)$in['note'], 0, 120)), $id]);
            if (isset($in['active'])) $pdo->prepare("UPDATE lh_people SET active = ? WHERE employee_id = ?")->execute([!empty($in['active']) ? 1 : 0, $id]);
            json_response(['ok' => true]);
        }
        case 'lh.person_del': { $needEdit(); $pdo->prepare("UPDATE lh_people SET active = 0 WHERE employee_id = ?")->execute([(int)(json_input()['employee_id'] ?? 0)]); json_response(['ok' => true]); }
        case 'lh.tpl_save': {
            $needEdit(); $in = json_input(); $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 160)); if ($title === '') json_response(['error' => 'Write the task.'], 422); $emp = max(0, (int)($in['employee_id'] ?? 0)); $id = (int)($in['id'] ?? 0);
            if ($id > 0) $pdo->prepare("UPDATE lh_tpl SET title = ?, employee_id = ?, active = ? WHERE id = ?")->execute([$title, $emp, array_key_exists('active', $in) && empty($in['active']) ? 0 : 1, $id]);
            else $pdo->prepare("INSERT INTO lh_tpl (employee_id, title, from_day, active, sort_no) VALUES (?, ?, ?, 1, ?)")->execute([$emp, $title, function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'), (int)$pdo->query("SELECT COALESCE(MAX(sort_no), 0) + 1 FROM lh_tpl")->fetchColumn()]);
            json_response(['ok' => true]);
        }
        case 'lh.tpl_del': { $needEdit(); $pdo->prepare("UPDATE lh_tpl SET active = 0 WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); json_response(['ok' => true]); }   // old days keep what was made
        case 'lh.task_add': {
            $needEdit(); $in = json_input(); $emp = (int)($in['employee_id'] ?? 0); $day = (string)($in['day'] ?? ''); $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 160));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $title === '' || $emp <= 0) json_response(['error' => 'Choose the person, the day and write the task.'], 422);
            $pdo->prepare("INSERT INTO lh_tasks (employee_id, day, title, created_by) VALUES (?, ?, ?, ?)")->execute([$emp, $day, $title, $me]); json_response(['ok' => true]);
        }
        case 'lh.task_done': {
            $needEdit(); $in = json_input(); $on = !empty($in['done']);
            $pdo->prepare("UPDATE lh_tasks SET done = ?, done_at = ?, done_by = ? WHERE id = ?")->execute([$on ? 1 : 0, $on ? date('Y-m-d H:i:s') : null, $on ? $me : 0, (int)($in['id'] ?? 0)]); json_response(['ok' => true]);
        }
        case 'lh.task_del': { $needEdit(); $pdo->prepare("DELETE FROM lh_tasks WHERE id = ?")->execute([(int)(json_input()['id'] ?? 0)]); json_response(['ok' => true]); }
        default: json_response(['error' => 'Unknown action.'], 404);
    }
}
