<?php
/**
 * dept_repairs.php — Departments add-on: TO FIX (maintenance / repairs) for every office and the store, in one list. Loaded by dept.php (same folder as api.php). PHP 7.4 safe.
 *
 * What it does
 *   - Problems come in three ways:  (1) Offices (office.php): a cleaning problem that comes back (3 different days) or already has a maintenance ticket -> "Reported" here, one row per problem.
 *                                   (2) ANY employee: "Report a problem" with a photo.   (3) The office checker or a boss adds one (same form).
 *   - A boss (or the checker of that office) gives one or many problems to a staff person or to an outside workman, with a date. Claude only SUGGESTS who.
 *   - The person gets ONE My Day item and ONE notification per batch, a reminder every hour (7 AM - 9 PM) until the date, then the boss' Inbox hears about it once.
 *   - Steps: Reported > Given > Doing > Done > Checked. "Done" needs an AFTER photo (Claude compares it with the BEFORE photo), optional bill photo and cost.
 *     Cost goes to Daily money (dp_money_post): company UPI needs the payment screenshot (a repeated screenshot is refused), own pocket lands in Reimbursements.
 *   - Somebody else (the checker / a boss) says "Checked" or sends it back. The person who did the job can never check his own job.
 *   - No penalty rule is made here. Reporting always works; items, notifications and escalation only run once the "supplies" topic is live (same switch as Office supplies).
 * Own tables: dp_fx_jobs, dp_fx_workmen. Reads of_offices / of_zones / of_issues / of_tickets from office.php and keeps of_tickets in step (doing / done) when a job came from a ticket.
 */
if (!defined('DP_LOADED') || !function_exists('dp_get')) { return; }
if (defined('DP_FX_LOADED')) { return; }
define('DP_FX_LOADED', 1);

/* ============================== tables ============================== */
function dp_fx_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_dp_fx_v1_' . substr(md5(__DIR__), 0, 8);
    $have = is_file($flag);
    if ($have) { try { $pdo->query("SELECT 1 FROM dp_fx_jobs LIMIT 1"); $pdo->query("SELECT 1 FROM dp_fx_workmen LIMIT 1"); } catch (Throwable $e) { $have = false; } }
    if ($have) return;
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_fx_workmen (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, phone VARCHAR(30) NULL, trade VARCHAR(60) NULL, note VARCHAR(200) NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_fx_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NULL, office_text VARCHAR(80) NULL, zone_id INT NULL, zone_text VARCHAR(100) NULL,
        title VARCHAR(200) NOT NULL, detail VARCHAR(400) NULL, source VARCHAR(8) NOT NULL DEFAULT 'report', src_key VARCHAR(40) NULL, issue_id INT NULL, ticket_id INT NULL,
        reported_by_emp INT NULL, reported_by VARCHAR(80) NULL, before_photo VARCHAR(160) NULL, status VARCHAR(10) NOT NULL DEFAULT 'reported',
        assigned_emp INT NULL, workman_id INT NULL, follower_emp INT NULL, assigned_by VARCHAR(80) NULL, assigned_by_emp INT NULL, assigned_at DATETIME NULL, due_at DATETIME NULL,
        seen_at DATETIME NULL, started_at DATETIME NULL, done_at DATETIME NULL, done_by_emp INT NULL, after_photo VARCHAR(160) NULL, bill_photo VARCHAR(160) NULL, pay_shot VARCHAR(160) NULL,
        cost DECIMAL(10,2) NULL, paid_by VARCHAR(8) NULL, ai_verdict VARCHAR(12) NULL, ai_note VARCHAR(250) NULL, help_note VARCHAR(250) NULL, reopen_note VARCHAR(250) NULL, cancel_note VARCHAR(250) NULL,
        checked_by VARCHAR(80) NULL, checked_by_emp INT NULL, checked_at DATETIME NULL, escalated TINYINT NOT NULL DEFAULT 0, last_push_at DATETIME NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_fx_src (src_key), INDEX (status), INDEX (office_id, status), INDEX (assigned_emp, status), INDEX (follower_emp, status), INDEX (reported_by_emp)
    )$E");
    @file_put_contents($flag, '1');
}

/* ============================== small helpers ============================== */
function dp_fx_has_of(PDO $pdo) {
    static $r = null; if ($r !== null) return $r;
    try { if (function_exists('of_ensure')) of_ensure($pdo); $pdo->query("SELECT 1 FROM of_offices LIMIT 1"); $pdo->query("SELECT 1 FROM of_issues LIMIT 1"); $pdo->query("SELECT 1 FROM of_tickets LIMIT 1"); $r = true; } catch (Throwable $e) { $r = false; }
    return $r;
}
function dp_fx_offices(PDO $pdo) {
    if (!dp_fx_has_of($pdo)) return [];
    try { return $pdo->query("SELECT id, name, section_id FROM of_offices WHERE active = 1 ORDER BY name")->fetchAll(); } catch (Throwable $e) { return []; }
}
function dp_fx_office_name(PDO $pdo, $id) {
    static $m = null; if ($m === null) { $m = []; foreach (dp_fx_offices($pdo) as $o) $m[(int)$o['id']] = $o['name']; }
    return $m[(int)$id] ?? '';
}
function dp_fx_zone_name(PDO $pdo, $id) {
    if (!$id || !dp_fx_has_of($pdo)) return '';
    try { $st = $pdo->prepare("SELECT name FROM of_zones WHERE id = ?"); $st->execute([(int)$id]); return (string)$st->fetchColumn(); } catch (Throwable $e) { return ''; }
}
function dp_fx_place(PDO $pdo, array $r) {
    $o = $r['office_id'] ? dp_fx_office_name($pdo, $r['office_id']) : (string)$r['office_text'];
    $z = $r['zone_id'] ? dp_fx_zone_name($pdo, $r['zone_id']) : (string)$r['zone_text'];
    return trim($o . ' ' . $z);
}
function dp_fx_workman(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM dp_fx_workmen WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function dp_fx_job(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM dp_fx_jobs WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function dp_fx_live(PDO $pdo) { return dp_live($pdo, 'supplies'); }
function dp_fx_push(PDO $pdo, $emp, $title, $body) { if ($emp && bd_mode() !== 'shadow') { try { bd_push($pdo, (int)$emp, $title, $body); } catch (Throwable $e) {} } }
function dp_fx_num($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }
function dp_fx_due_label($due) { return $due ? date('D j M, g:i A', strtotime($due)) : ''; }
function dp_fx_parse_due($v, $now) {
    $v = trim((string)$v); if ($v === '') { $d = max(0, (int)dp_get('fx_due_days', '2')); return date('Y-m-d', $now + 86400 * $d) . ' 18:00:00'; }
    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v .= ' 18:00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v)) return null;
    $t = strtotime($v); if (!$t || $t < strtotime(date('Y-m-d', $now))) return null;
    return date('Y-m-d H:i:s', $t);
}
function dp_fx_upload($field, $prefix, $required, $why) {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? 4) === 4 || empty($_FILES[$field]['tmp_name'])) {
        if ($required) json_response(['error' => $why], 422);
        return null;
    }
    if ((int)$_FILES[$field]['error'] !== 0) json_response(['error' => 'The picture could not be uploaded. Try a smaller picture.'], 422);
    list($name, $err) = dp_save_image($_FILES[$field]['tmp_name'], $prefix);
    if (!$name) json_response(['error' => $err], 422);
    return $name;
}
function dp_fx_unlink($name) { if ($name) { $f = dp_dir() . basename((string)$name); if (is_file($f)) @unlink($f); } }

/* ============================== who may do what ============================== */
/** all = Admin / HR / top boss / a boss or checker of the Supplies section.  offices = the offices whose own checker or section boss he is. */
function dp_fx_acc(PDO $pdo, array $auth) {
    $w = bd_who($pdo, $auth); $emp = (int)($w['emp'] ?? 0);
    $supSec = (int)$pdo->query("SELECT id FROM bd_sections WHERE code = 'supplies'")->fetchColumn();
    $all = !empty($w['admin']) || !empty($w['top']) || ($supSec && in_array($supSec, array_map('intval', (array)($w['sections'] ?? [])), true));
    $offs = [];
    foreach (dp_fx_offices($pdo) as $o) { if ($all || ($emp && in_array((int)$o['section_id'], array_map('intval', (array)($w['sections'] ?? [])), true))) $offs[(int)$o['id']] = 1; }
    return ['w' => $w, 'emp' => $emp, 'all' => $all, 'offices' => $offs, 'full' => $all || !empty($offs), 'by' => (string)($auth['username'] ?? 'user')];
}
function dp_fx_manages(array $acc, array $j) { return $acc['all'] || ($j['office_id'] && isset($acc['offices'][(int)$j['office_id']])); }
function dp_fx_is_doer(array $acc, array $j) { return $acc['emp'] && ((int)$j['assigned_emp'] === $acc['emp'] || (int)$j['follower_emp'] === $acc['emp']); }
function dp_fx_can_see(array $acc, array $j) { return dp_fx_manages($acc, $j) || dp_fx_is_doer($acc, $j) || ($acc['emp'] && (int)$j['reported_by_emp'] === $acc['emp']); }
function dp_fx_office_checkers(PDO $pdo, $officeId) {
    if (!$officeId || !dp_fx_has_of($pdo)) return [];
    try {
        $st = $pdo->prepare("SELECT section_id FROM of_offices WHERE id = ?"); $st->execute([(int)$officeId]); $sid = (int)$st->fetchColumn(); if (!$sid) return [];
        $o = [];
        foreach (['checker', 'section_boss'] as $k) { $seat = bd_section_seat($pdo, $sid, $k); if ($seat) foreach (bd_holders($pdo, $seat) as $e) $o[(int)$e] = 1; }
        return array_keys($o);
    } catch (Throwable $e) { return []; }
}
/** who is told about a job: the office checkers; managers of everything are told through the Inbox instead */
function dp_fx_notify_checkers(PDO $pdo, array $j, $title, $body, $skipEmp = 0) {
    foreach (dp_fx_office_checkers($pdo, $j['office_id']) as $e) { if ((int)$e !== (int)$skipEmp) dp_fx_push($pdo, $e, $title, $body); }
}

/* ============================== problems from Offices (cleaning photos / tickets) ============================== */
function dp_fx_sync_issues(PDO $pdo) {
    if (!dp_fx_has_of($pdo)) return 0;
    $n = 0; $now = bd_now();
    try {
        $q = $pdo->query("SELECT i.*, z.name AS zname FROM of_issues i LEFT JOIN of_zones z ON z.id = i.zone_id WHERE i.status = 'ticket' OR (i.status = 'open' AND i.days >= 3)");
        foreach ($q->fetchAll() as $i) {
            $ex = $pdo->prepare("SELECT id FROM dp_fx_jobs WHERE src_key = ?"); $ex->execute(['issue:' . $i['id']]); if ($ex->fetchColumn()) continue;
            $detail = 'Came back on ' . (int)$i['days'] . ' different days (' . (int)$i['hits'] . ' times) in the cleaning photos.';
            $pdo->prepare("INSERT IGNORE INTO dp_fx_jobs (office_id, zone_id, title, detail, source, src_key, issue_id, ticket_id, reported_by, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([(int)$i['office_id'], (int)$i['zone_id'], mb_substr((string)$i['remark'], 0, 200), $detail, 'issue', 'issue:' . $i['id'], (int)$i['id'], $i['ticket_id'] ?: null, 'Cleaning photos', 'reported', $i['first_at'] ?: $now]);
            $n++;
        }
        // a ticket without an issue (not made by the cleaning photos)
        $q = $pdo->query("SELECT * FROM of_tickets WHERE issue_id IS NULL AND status != 'done'");
        foreach ($q->fetchAll() as $t) {
            $ex = $pdo->prepare("SELECT id FROM dp_fx_jobs WHERE src_key = ?"); $ex->execute(['ticket:' . $t['id']]); if ($ex->fetchColumn()) continue;
            $pdo->prepare("INSERT IGNORE INTO dp_fx_jobs (office_id, zone_id, title, detail, source, src_key, ticket_id, reported_by, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([(int)$t['office_id'], $t['zone_id'] ?: null, mb_substr((string)$t['title'], 0, 200), $t['detail'], 'issue', 'ticket:' . $t['id'], (int)$t['id'], (string)($t['created_by'] ?: 'Offices'), 'reported', $t['created_at'] ?: $now]);
            $n++;
        }
        // a ticket was made in Offices for an issue we already list
        $pdo->exec("UPDATE dp_fx_jobs j JOIN of_issues i ON i.id = j.issue_id SET j.ticket_id = i.ticket_id WHERE j.source = 'issue' AND j.ticket_id IS NULL AND i.ticket_id IS NOT NULL");
        // closed in Offices while still open here
        $open = $pdo->query("SELECT * FROM dp_fx_jobs WHERE source = 'issue' AND status IN ('reported','given','doing')")->fetchAll();
        foreach ($open as $j) {
            $tk = null; if ($j['ticket_id']) { $s = $pdo->prepare("SELECT * FROM of_tickets WHERE id = ?"); $s->execute([(int)$j['ticket_id']]); $tk = $s->fetch() ?: null; }
            $iss = null; if ($j['issue_id']) { $s = $pdo->prepare("SELECT status FROM of_issues WHERE id = ?"); $s->execute([(int)$j['issue_id']]); $iss = $s->fetchColumn(); }
            if ($tk && $tk['status'] === 'done') { $pdo->prepare("UPDATE dp_fx_jobs SET status = 'done', done_at = ?, ai_verdict = 'not checked', ai_note = ? WHERE id = ? AND status IN ('reported','given','doing')")->execute([$tk['done_at'] ?: $now, 'Ticket closed in Offices. Please look at it and press Checked.', $j['id']]); }
            elseif ($iss === 'fixed' && $j['status'] === 'reported') { $pdo->prepare("UPDATE dp_fx_jobs SET status = 'cancelled', cancel_note = ? WHERE id = ?")->execute(['Marked fixed in Offices (cleaning photos pass again).', $j['id']]); }
        }
    } catch (Throwable $e) { error_log('Repairs sync: ' . $e->getMessage()); }
    return $n;
}
/** keep the Offices ticket in step: a job that came from (or is given for) a cleaning problem has a ticket */
function dp_fx_ticket_sync(PDO $pdo, array $j, $status, $by) {
    if (!dp_fx_has_of($pdo)) return;
    try {
        if (!$j['ticket_id'] && $j['issue_id'] && $status === 'make') {
            $pdo->prepare("INSERT INTO of_tickets (office_id, zone_id, issue_id, title, detail, created_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([(int)$j['office_id'], $j['zone_id'] ?: null, (int)$j['issue_id'], mb_substr($j['title'], 0, 200), mb_substr((string)$j['detail'], 0, 400), $by, bd_now()]);
            $tid = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE of_issues SET status = 'ticket', ticket_id = ? WHERE id = ? AND status = 'open'")->execute([$tid, (int)$j['issue_id']]);
            $pdo->prepare("UPDATE dp_fx_jobs SET ticket_id = ? WHERE id = ?")->execute([$tid, (int)$j['id']]);
            return;
        }
        if (!$j['ticket_id']) return;
        if ($status === 'doing') {
            $who = $j['workman_id'] ? ((dp_fx_workman($pdo, $j['workman_id']) ?: ['name' => ''])['name']) : ($j['assigned_emp'] ? bd_emp_name($pdo, $j['assigned_emp']) : '');
            $pdo->prepare("UPDATE of_tickets SET status = 'doing', assigned_to = ? WHERE id = ? AND status != 'done'")->execute([mb_substr($who, 0, 100) ?: null, (int)$j['ticket_id']]);
        } elseif ($status === 'done') {
            $pdo->prepare("UPDATE of_tickets SET status = 'done', done_at = ? WHERE id = ?")->execute([bd_now(), (int)$j['ticket_id']]);
            if ($j['issue_id']) $pdo->prepare("UPDATE of_issues SET status = 'fixed' WHERE id = ?")->execute([(int)$j['issue_id']]);
        }
    } catch (Throwable $e) { error_log('Repairs ticket sync: ' . $e->getMessage()); }
}

/* ============================== My Day items ============================== */
function dp_fx_places_text(PDO $pdo, array $jobs) {
    $p = []; foreach ($jobs as $j) { $t = dp_fx_place($pdo, $j); $p[$t !== '' ? $t : $j['title']] = 1; }
    return implode(', ', array_keys($p));
}
/** one open "Fix N things" item per person (and one "Check that <workman> comes" item per follower); finished ones are closed */
function dp_fx_sync_items(PDO $pdo, $now) {
    $today = date('Y-m-d', $now); $made = 0;
    $rows = $pdo->query("SELECT * FROM dp_fx_jobs WHERE status IN ('given','doing') ORDER BY due_at, id")->fetchAll();
    $maps = ['own' => [], 'fol' => []];
    foreach ($rows as $r) { if ($r['assigned_emp']) $maps['own'][(int)$r['assigned_emp']][] = $r; elseif ($r['workman_id'] && $r['follower_emp']) $maps['fol'][(int)$r['follower_emp']][] = $r; }
    foreach ($maps as $type => $map) {
        foreach ($map as $emp => $jobs) {
            $n = count($jobs); $places = dp_fx_places_text($pdo, $jobs);
            if ($type === 'own') { $title = 'Fix ' . $n . ' thing' . ($n === 1 ? '' : 's') . ': ' . $places; }
            else { $names = []; foreach ($jobs as $j) { $w = dp_fx_workman($pdo, $j['workman_id']); if ($w) $names[$w['name']] = 1; } $title = 'Check that ' . implode(' and ', array_keys($names) ?: ['the workman']) . ' comes: ' . $places; }
            $title = mb_substr($title, 0, 200); $due = null; foreach ($jobs as $j) { if ($j['due_at'] && (!$due || $j['due_at'] < $due)) $due = $j['due_at']; }
            $payload = ['kind' => 'fix', 'type' => $type, 'job_ids' => array_map(function ($j) { return (int)$j['id']; }, $jobs), 'page' => 'dept_fix.html?me=1'];
            $detail = $due ? 'By ' . dp_fx_due_label($due) : 'Open "To fix" for the details';
            $st = $pdo->prepare("SELECT id FROM dp_items WHERE kind = 'fix' AND owner_emp = ? AND ref_key LIKE ? AND status = 'open' ORDER BY id LIMIT 1"); $st->execute([(int)$emp, 'fx:' . $emp . ':' . $type . ':%']); $iid = (int)$st->fetchColumn();
            if ($iid) { $pdo->prepare("UPDATE dp_items SET title = ?, detail = ?, due_at = ?, payload = ?, qty = ?, task_date = ? WHERE id = ?")->execute([$title, $detail, $due, bd_json($payload), $n, $today, $iid]); }
            else {
                $k = 'fx:' . $emp . ':' . $type . ':' . $today; $try = 1;
                while (true) { $c = $pdo->prepare("SELECT 1 FROM dp_items WHERE ref_key = ?"); $c->execute([$k]); if (!$c->fetchColumn()) break; $try++; $k = 'fx:' . $emp . ':' . $type . ':' . $today . ':' . $try; }
                dp_item_make($pdo, 'fix', null, $k, null, $title, $detail, $today, $due, $payload, (int)$emp, $n); $made++;
            }
        }
    }
    foreach ($pdo->query("SELECT id, owner_emp, ref_key FROM dp_items WHERE kind = 'fix' AND status = 'open'")->fetchAll() as $it) {
        $p = explode(':', (string)$it['ref_key']); $type = $p[2] ?? 'own';
        if (!isset($maps[$type][(int)$it['owner_emp']])) dp_item_done($pdo, $it['id']);
    }
    return $made;
}

/* ============================== views ============================== */
function dp_fx_step($s) { return ['reported' => 0, 'given' => 1, 'doing' => 2, 'done' => 3, 'checked' => 4, 'cancelled' => 0][$s] ?? 0; }
function dp_fx_view(PDO $pdo, array $r, array $acc, $now = null) {
    $now = $now ?: time(); $open = in_array($r['status'], ['reported', 'given', 'doing'], true);
    $age = max(0, (int)floor(($now - strtotime($r['created_at'])) / 86400));
    $wk = $r['workman_id'] ? dp_fx_workman($pdo, $r['workman_id']) : null;
    $given = null;
    if ($r['assigned_emp']) $given = ['type' => 'staff', 'id' => (int)$r['assigned_emp'], 'name' => bd_emp_name($pdo, $r['assigned_emp']), 'phone' => ''];
    elseif ($wk) $given = ['type' => 'workman', 'id' => (int)$wk['id'], 'name' => $wk['name'], 'phone' => (string)$wk['phone'], 'trade' => (string)$wk['trade']];
    $mgr = dp_fx_manages($acc, $r); $doer = dp_fx_is_doer($acc, $r);
    $isDone = $acc['emp'] && (int)$r['done_by_emp'] === $acc['emp'];
    $overdue = $open && (($r['due_at'] && strtotime($r['due_at']) < $now) || (!$r['due_at'] && $age >= max(1, (int)dp_get('fx_escalate_days', '3'))));
    $files = [];
    foreach (['before' => 'before_photo', 'after' => 'after_photo', 'bill' => 'bill_photo', 'pay' => 'pay_shot'] as $k => $col) if (!empty($r[$col])) $files[$k] = true;
    return ['id' => (int)$r['id'], 'title' => $r['title'], 'detail' => (string)$r['detail'], 'office_id' => $r['office_id'] ? (int)$r['office_id'] : null, 'office' => $r['office_id'] ? dp_fx_office_name($pdo, $r['office_id']) : (string)$r['office_text'],
        'zone' => $r['zone_id'] ? dp_fx_zone_name($pdo, $r['zone_id']) : (string)$r['zone_text'], 'place' => dp_fx_place($pdo, $r), 'source' => $r['source'], 'status' => $r['status'], 'step' => dp_fx_step($r['status']),
        'days' => $age, 'overdue' => (bool)$overdue, 'due_at' => $r['due_at'], 'due_label' => dp_fx_due_label($r['due_at']), 'given' => $given,
        'follower' => $r['follower_emp'] ? bd_emp_name($pdo, $r['follower_emp']) : '', 'role' => ($acc['emp'] && (int)$r['assigned_emp'] === $acc['emp']) ? 'doer' : (($acc['emp'] && (int)$r['follower_emp'] === $acc['emp']) ? 'follower' : (($acc['emp'] && (int)$r['reported_by_emp'] === $acc['emp']) ? 'reporter' : 'boss')), 'assigned_by' => (string)$r['assigned_by'], 'seen' => !empty($r['seen_at']), 'started_at' => $r['started_at'],
        'reported_by' => (string)$r['reported_by'], 'created_at' => $r['created_at'], 'done_at' => $r['done_at'], 'cost' => $r['cost'] !== null ? (float)$r['cost'] : null, 'paid_by' => (string)$r['paid_by'],
        'ai_verdict' => (string)$r['ai_verdict'], 'ai_note' => (string)$r['ai_note'], 'help_note' => (string)$r['help_note'], 'reopen_note' => (string)$r['reopen_note'], 'cancel_note' => (string)$r['cancel_note'],
        'checked_by' => (string)$r['checked_by'], 'checked_at' => $r['checked_at'], 'files' => $files, 'escalated' => (bool)$r['escalated'], 'from_place' => (bool)preg_match('/^pl(fix|eq):/', (string)($r['src_key'] ?? '')),   // made by a registered place (wizard or daily check)
        'can' => ['assign' => $mgr && $open, 'start' => ($doer || $mgr) && $r['status'] === 'given', 'help' => $doer && in_array($r['status'], ['given', 'doing'], true),
                  'done' => ($doer || $mgr) && in_array($r['status'], ['given', 'doing'], true), 'check' => $mgr && $r['status'] === 'done' && !$doer && !$isDone,
                  'reopen' => $mgr && in_array($r['status'], ['done', 'checked'], true), 'cancel' => $mgr && $open]];
}
function dp_fx_stats(PDO $pdo, array $acc, $now) {
    $where = ''; $args = [];
    if (!$acc['all']) { $ids = array_keys($acc['offices']); if (!$ids) return null; $where = ' AND office_id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    $esc = max(1, (int)dp_get('fx_escalate_days', '3'));
    $open = $pdo->query("SELECT * FROM dp_fx_jobs WHERE status IN ('reported','given','doing')" . $where)->fetchAll();
    $over = 0; $un = 0;
    foreach ($open as $r) { if ($r['status'] === 'reported') $un++; $age = (int)floor(($now - strtotime($r['created_at'])) / 86400); if (($r['due_at'] && strtotime($r['due_at']) < $now) || (!$r['due_at'] && $age >= $esc)) $over++; }
    $m = $pdo->prepare("SELECT COUNT(*) FROM dp_fx_jobs WHERE status IN ('done','checked') AND done_at >= ?" . $where); $m->execute([date('Y-m-01 00:00:00', $now)]);
    return ['to_fix' => count($open), 'unassigned' => $un, 'overdue' => $over, 'done_month' => (int)$m->fetchColumn()];
}
function dp_fx_offices_view(PDO $pdo, array $acc) {
    $o = [];
    foreach (dp_fx_offices($pdo) as $of) {
        $z = []; try { $s = $pdo->prepare("SELECT id, name FROM of_zones WHERE office_id = ? AND status = 'approved' ORDER BY name"); $s->execute([(int)$of['id']]); foreach ($s->fetchAll() as $r) $z[] = ['id' => (int)$r['id'], 'name' => $r['name']]; } catch (Throwable $e) {}
        $c = $pdo->prepare("SELECT COUNT(*) FROM dp_fx_jobs WHERE office_id = ? AND status IN ('reported','given','doing')"); $c->execute([(int)$of['id']]);
        $o[] = ['id' => (int)$of['id'], 'name' => $of['name'], 'zones' => $z, 'open' => (int)$c->fetchColumn(), 'manage' => $acc['all'] || isset($acc['offices'][(int)$of['id']])];
    }
    return $o;
}

/* ============================== Claude suggests who (never assigns) ============================== */
function dp_fx_trade_words() {
    return ['electrician' => 'light tube bulb switch fan wire socket power electric led', 'plumber' => 'tap leak pipe flush basin washroom toilet drain water tank', 'ac' => 'ac cooling air conditioner compressor',
            'carpenter' => 'door lock hinge table chair shelf cupboard drawer wood', 'painter' => 'paint wall crack seepage damp', 'glass' => 'glass film window mirror'];
}
function dp_fx_trade_of($text) {
    $t = ' ' . mb_strtolower((string)$text) . ' '; $best = ''; $bs = 0;
    foreach (dp_fx_trade_words() as $trade => $words) { $s = 0; foreach (explode(' ', $words) as $w) { if ($w !== '' && strpos($t, ' ' . $w) !== false) $s++; } if ($s > $bs) { $bs = $s; $best = $trade; } }
    return $best;
}
function dp_fx_suggest(PDO $pdo, array $jobs) {
    $trades = []; $office = 0; foreach ($jobs as $j) { $t = dp_fx_trade_of($j['title'] . ' ' . $j['detail']); if ($t) $trades[$t] = 1; if (!$office && $j['office_id']) $office = (int)$j['office_id']; }
    $trade = count($trades) === 1 ? key($trades) : ''; // mixed problems: no single trade to suggest
    $wm = [];
    foreach ($pdo->query("SELECT * FROM dp_fx_workmen WHERE active = 1 ORDER BY name")->fetchAll() as $w) {
        $score = 0; $why = [];
        if ($trade && $w['trade'] && stripos((string)$w['trade'], $trade) !== false) { $score += 50; $why[] = 'does ' . $trade . ' work'; }
        $c = $pdo->prepare("SELECT COUNT(*) c, SUM(office_id = ?) o FROM dp_fx_jobs WHERE workman_id = ? AND status IN ('done','checked')"); $c->execute([$office, (int)$w['id']]); $x = $c->fetch();
        if ((int)$x['c']) { $score += min(20, (int)$x['c'] * 4); $why[] = (int)$x['c'] . ' job' . ((int)$x['c'] === 1 ? '' : 's') . ' done before'; }
        if ((int)$x['o']) { $score += 15; $why[] = 'worked at this office'; }
        if ($score > 0) $wm[] = ['id' => (int)$w['id'], 'name' => $w['name'], 'phone' => (string)$w['phone'], 'trade' => (string)$w['trade'], 'score' => $score, 'line' => ucfirst(implode(', ', $why))];
    }
    usort($wm, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $staff = [];
    $st = $pdo->prepare("SELECT assigned_emp, COUNT(*) c, SUM(status = 'checked') ok FROM dp_fx_jobs WHERE assigned_emp IS NOT NULL AND status IN ('done','checked') GROUP BY assigned_emp ORDER BY c DESC LIMIT 3"); $st->execute();
    foreach ($st->fetchAll() as $r) { $staff[] = ['id' => (int)$r['assigned_emp'], 'name' => bd_emp_name($pdo, $r['assigned_emp']), 'line' => (int)$r['c'] . ' repair job' . ((int)$r['c'] === 1 ? '' : 's') . ' done, ' . (int)$r['ok'] . ' checked OK']; }
    if (count($staff) < 3 && $office && dp_fx_has_of($pdo)) {
        foreach (dp_fx_office_checkers($pdo, $office) as $e) { if (count($staff) >= 3) break; $dup = false; foreach ($staff as $s) if ($s['id'] === (int)$e) $dup = true; if (!$dup) $staff[] = ['id' => (int)$e, 'name' => bd_emp_name($pdo, $e), 'line' => 'Checks this office']; }
    }
    return ['trade' => $trade, 'workmen' => array_slice($wm, 0, 3), 'staff' => $staff];
}

/* ============================== the tick: items, hourly reminders, escalation ============================== */
function dp_repairs_tick(PDO $pdo, $now) {
    dp_fx_ensure($pdo);
    $o = ['live' => dp_fx_live($pdo)];
    $o['synced'] = dp_fx_sync_issues($pdo);
    if (!$o['live']) return $o;
    $o['items'] = dp_fx_sync_items($pdo, $now);
    // hourly reminder until the date (7 AM - 9 PM)
    $rep = (int)dp_get('wk_repeat_min', '60'); $o['reminders'] = 0;
    $hr = (int)date('G', $now);
    if ($rep > 0 && $hr >= 7 && $hr < 21) {
        $cut = date('Y-m-d H:i:s', $now - 60 * $rep); $per = [];
        $q = $pdo->prepare("SELECT * FROM dp_fx_jobs WHERE status IN ('given','doing') AND (due_at IS NULL OR due_at > ?) AND (last_push_at IS NULL OR last_push_at <= ?)"); $q->execute([date('Y-m-d H:i:s', $now), $cut]);
        foreach ($q->fetchAll() as $j) { $e = $j['assigned_emp'] ?: $j['follower_emp']; if ($e) $per[(int)$e][] = $j; }
        foreach ($per as $e => $jobs) {
            $n = count($jobs); dp_fx_push($pdo, $e, $n . ' thing' . ($n === 1 ? '' : 's') . ' still to fix', mb_substr(dp_fx_places_text($pdo, $jobs), 0, 140));
            foreach ($jobs as $j) $pdo->prepare("UPDATE dp_fx_jobs SET last_push_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now), $j['id']]);
            $o['reminders']++;
        }
    }
    // escalation: once per job, when the date has passed or it has been open too long
    $esc = max(1, (int)dp_get('fx_escalate_days', '3')); $o['escalated'] = 0;
    $q = $pdo->prepare("SELECT * FROM dp_fx_jobs WHERE status IN ('reported','given','doing') AND escalated = 0 AND ((due_at IS NOT NULL AND due_at <= ?) OR created_at <= ?)");
    $q->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now - 86400 * $esc)]);
    foreach ($q->fetchAll() as $j) {
        $place = dp_fx_place($pdo, $j) ?: 'Office';
        $who = $j['assigned_emp'] ? bd_emp_name($pdo, $j['assigned_emp']) : ($j['workman_id'] ? ((dp_fx_workman($pdo, $j['workman_id']) ?: ['name' => 'the workman'])['name']) : '');
        $why = $who === '' ? 'Nobody was given this problem yet.' : 'Given to ' . $who . ($j['due_at'] ? ', it was due ' . dp_fx_due_label($j['due_at']) : '') . ' and it is still not done.';
        bd_inbox('dp_note', 'Still not fixed: ' . $j['title'] . ' (' . $place . ')', $why . ' It has been open ' . max(1, (int)floor(($now - strtotime($j['created_at'])) / 86400)) . ' day(s). Open Boss > Departments > To fix to give it to somebody or follow it up.', ['job_id' => (int)$j['id'], 'page' => 'dept_fix.html'], null, null, 'fx_esc:' . $j['id']);
        if ($j['assigned_by_emp']) dp_fx_push($pdo, $j['assigned_by_emp'], 'Not fixed yet: ' . $j['title'], $place . ($who ? ' · ' . $who : ''));
        $pdo->prepare("UPDATE dp_fx_jobs SET escalated = 1 WHERE id = ?")->execute([$j['id']]); $o['escalated']++;
    }
    return $o;
}

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'dp.fx_') === 0) {
    dp_ensure($pdo); dp_fx_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $auth = require_auth(); $acc = dp_fx_acc($pdo, $auth); $by = $acc['by']; $emp = $acc['emp'];
    $in = $isPost ? (!empty($_POST) ? $_POST : json_input()) : [];
    if (!is_array($in)) $in = [];
    $now = time();
    dp_tick_after($pdo);
    $needFull = function () use ($acc) { if (!$acc['full']) json_response(['error' => 'The To fix list is for the bosses and the office checkers. You can report a problem and follow your own jobs.'], 403); };
    $getJob = function ($id) use ($pdo, $acc) { $j = dp_fx_job($pdo, (int)$id); if (!$j || !dp_fx_can_see($acc, $j)) json_response(['error' => 'Problem not found.'], 404); return $j; };
    $needLive = function () use ($pdo) { if (!dp_fx_live($pdo)) json_response(['error' => 'Repairs are not live yet. Answer Claude\'s questions in Boss > Questions (Office supplies), then press "This is right, make it live".'], 409); };

    switch ($action) {

    case 'dp.fx_access': {
        dp_fx_sync_issues($pdo);
        $mine = 0; if ($emp) { $c = $pdo->prepare("SELECT COUNT(*) FROM dp_fx_jobs WHERE (assigned_emp = ? OR follower_emp = ?) AND status IN ('given','doing')"); $c->execute([$emp, $emp]); $mine = (int)$c->fetchColumn(); }
        json_response(['emp' => $emp, 'full' => $acc['full'], 'all' => $acc['all'], 'can_assign' => $acc['full'], 'admin' => !empty($acc['w']['admin']), 'offices' => dp_fx_offices_view($pdo, $acc), 'employees' => $acc['full'] ? array_map(function ($e) { return ['id' => (int)$e['id'], 'name' => $e['name'], 'dept' => (string)($e['dept'] ?? '')]; }, bd_employees($pdo)) : [], 'my_open' => $mine, 'live' => dp_fx_live($pdo),
            'settings' => ['wk_repeat_min' => (int)dp_get('wk_repeat_min', '60'), 'fx_escalate_days' => (int)dp_get('fx_escalate_days', '3'), 'fx_ai' => dp_get('fx_ai', '1') === '1', 'fx_due_days' => (int)dp_get('fx_due_days', '2')]]);
    }

    case 'dp.fx_list': {
        dp_fx_sync_issues($pdo);
        $mine = !empty($_GET['mine']); $tab = (string)($_GET['tab'] ?? 'open'); $of = (int)($_GET['office'] ?? 0);
        if (!$mine) $needFull();
        $w = []; $a = [];
        if ($mine) { $w[] = '(reported_by_emp = ? OR assigned_emp = ? OR follower_emp = ?)'; $a = [$emp, $emp, $emp]; if (!$emp) $w = ['1 = 0']; }
        elseif (!$acc['all']) { $ids = array_keys($acc['offices']); $w[] = 'office_id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
        if ($of) { $w[] = 'office_id = ?'; $a[] = $of; }
        if ($tab === 'done') { $w[] = "status IN ('done','checked')"; $w[] = 'done_at >= ?'; $a[] = date('Y-m-d H:i:s', $now - 86400 * 60); }
        else $w[] = "status IN ('reported','given','doing')";
        $st = $pdo->prepare("SELECT * FROM dp_fx_jobs WHERE " . implode(' AND ', $w) . ($tab === 'done' ? " ORDER BY done_at DESC, id DESC" : " ORDER BY FIELD(status,'reported','given','doing'), created_at, id") . " LIMIT 300"); $st->execute($a);
        $jobs = []; foreach ($st->fetchAll() as $r) $jobs[] = dp_fx_view($pdo, $r, $acc, $now);
        json_response(['jobs' => $jobs, 'stats' => $mine ? null : dp_fx_stats($pdo, $acc, $now), 'full' => $acc['full'], 'live' => dp_fx_live($pdo), 'now' => date('Y-m-d H:i:s', $now)]);
    }

    case 'dp.fx_overview': {
        $needFull(); dp_fx_sync_issues($pdo);
        json_response(['stats' => dp_fx_stats($pdo, $acc, $now), 'offices' => dp_fx_offices_view($pdo, $acc), 'live' => dp_fx_live($pdo)]);
    }

    case 'dp.fx_get': {
        $j = $getJob($_GET['id'] ?? 0);
        if ($acc['emp'] && dp_fx_is_doer($acc, $j) && !$j['seen_at']) { $pdo->prepare("UPDATE dp_fx_jobs SET seen_at = ? WHERE id = ?")->execute([bd_now(), $j['id']]); $j['seen_at'] = bd_now(); }
        json_response(['job' => dp_fx_view($pdo, $j, $acc, $now)]);
    }

    case 'dp.fx_report': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200); if (mb_strlen($title) < 3) json_response(['error' => 'Write what is wrong, in a few words.'], 422);
        $oid = (int)($in['office_id'] ?? 0); $otext = mb_substr(trim((string)($in['office_text'] ?? '')), 0, 80); $offs = dp_fx_offices($pdo);
        if ($oid) { $ok = false; foreach ($offs as $o) if ((int)$o['id'] === $oid) $ok = true; if (!$ok) json_response(['error' => 'Choose an office from the list.'], 422); }
        elseif ($offs) json_response(['error' => 'Choose the office or the store.'], 422);
        elseif ($otext === '') json_response(['error' => 'Write where it is (office or store).'], 422);
        $zid = (int)($in['zone_id'] ?? 0); $ztext = mb_substr(trim((string)($in['zone_text'] ?? '')), 0, 100);
        if ($zid) { $z = $pdo->prepare("SELECT office_id FROM of_zones WHERE id = ?"); try { $z->execute([$zid]); $zo = $z->fetchColumn(); } catch (Throwable $e) { $zo = false; } if (!$zo || (int)$zo !== $oid) json_response(['error' => 'That place does not belong to this office.'], 422); }
        $c = $pdo->prepare("SELECT COUNT(*) FROM dp_fx_jobs WHERE reported_by_emp = ? AND created_at >= ?"); $c->execute([$emp ?: -1, date('Y-m-d 00:00:00', $now)]); if ($emp && (int)$c->fetchColumn() >= 15) json_response(['error' => 'You have already reported 15 problems today. Ask your boss to add more.'], 429);
        $photo = dp_fx_upload('photo', 'fx', true, 'Add a photo of the problem. It helps the person who fixes it.');
        $detail = mb_substr(trim((string)($in['detail'] ?? '')), 0, 400);
        $manual = dp_fx_manages($acc, ['office_id' => $oid]) && $acc['full'];
        $pdo->prepare("INSERT INTO dp_fx_jobs (office_id, office_text, zone_id, zone_text, title, detail, source, reported_by_emp, reported_by, before_photo, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$oid ?: null, $oid ? null : ($otext ?: null), $zid ?: null, $zid ? null : ($ztext ?: null), $title, $detail ?: null, $manual ? 'manual' : 'report', $emp ?: null, $emp ? bd_emp_name($pdo, $emp) : $by, $photo, 'reported', bd_now()]);
        $id = (int)$pdo->lastInsertId(); $j = dp_fx_job($pdo, $id);
        bd_log('repairs', 'fx' . $id, 'problem reported: ' . $title . ' (' . dp_fx_place($pdo, $j) . ')', $by);
        if (dp_fx_live($pdo)) dp_fx_notify_checkers($pdo, $j, 'New problem: ' . dp_fx_place($pdo, $j), $title, $emp);
        json_response(['ok' => true, 'id' => $id, 'message' => 'Reported. The office checker has been told.']);
    }

    case 'dp.fx_suggest': {
        $needFull(); $ids = array_values(array_filter(array_map('intval', (array)($in['job_ids'] ?? ($isPost ? [] : explode(',', (string)($_GET['ids'] ?? '')))))));
        if (!$ids) json_response(['error' => 'Choose at least one problem.'], 422);
        $jobs = []; foreach ($ids as $i) { $j = dp_fx_job($pdo, $i); if ($j && dp_fx_manages($acc, $j)) $jobs[] = $j; }
        if (!$jobs) json_response(['error' => 'Problem not found.'], 404);
        json_response(dp_fx_suggest($pdo, $jobs));
    }

    case 'dp.fx_assign': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['job_ids'] ?? [])))));
        if (!$ids) json_response(['error' => 'Choose at least one problem.'], 422);
        $e1 = (int)($in['emp_id'] ?? 0); $w1 = (int)($in['workman_id'] ?? 0);
        if (($e1 ? 1 : 0) + ($w1 ? 1 : 0) !== 1) json_response(['error' => 'Choose a staff person OR a workman.'], 422);
        if ($e1 && !bd_emp_active(bd_emp($pdo, $e1))) json_response(['error' => 'That person is not active.'], 422);
        $wk = null; if ($w1) { $wk = dp_fx_workman($pdo, $w1); if (!$wk || !(int)$wk['active']) json_response(['error' => 'That workman is not in the list.'], 422); }
        $due = dp_fx_parse_due($in['due_at'] ?? '', $now); if ($due === null) json_response(['error' => 'Choose a date that is today or later.'], 422);
        $fol = (int)($in['follower_emp'] ?? 0); if ($fol && !bd_emp_active(bd_emp($pdo, $fol))) json_response(['error' => 'The person who follows up is not active.'], 422);
        $jobs = []; $old = [];
        foreach ($ids as $i) {
            $j = dp_fx_job($pdo, $i); if (!$j) json_response(['error' => 'Problem #' . $i . ' not found.'], 404);
            if (!dp_fx_manages($acc, $j)) json_response(['error' => 'You can give work only for the office you check (' . ($j['office_id'] ? dp_fx_office_name($pdo, $j['office_id']) : 'this one') . ' is not yours).'], 403);
            if (!in_array($j['status'], ['reported', 'given', 'doing'], true)) json_response(['error' => '"' . $j['title'] . '" is already ' . $j['status'] . '.'], 409);
            $jobs[] = $j;
        }
        foreach ($jobs as $j) {
            $f = 0;
            if ($w1) { $f = $fol ?: ($emp ?: 0); if (!$f) { $ck = dp_fx_office_checkers($pdo, $j['office_id']); $f = $ck ? (int)$ck[0] : 0; } }
            $pdo->prepare("UPDATE dp_fx_jobs SET status = ?, assigned_emp = ?, workman_id = ?, follower_emp = ?, assigned_by = ?, assigned_by_emp = ?, assigned_at = ?, due_at = ?, seen_at = NULL, last_push_at = ?, escalated = 0 WHERE id = ?")
                ->execute([$j['status'] === 'doing' ? 'doing' : 'given', $e1 ?: null, $w1 ?: null, $f ?: null, $by, $emp ?: null, bd_now(), $due, date('Y-m-d H:i:s', $now), $j['id']]);
            $j2 = dp_fx_job($pdo, $j['id']); dp_fx_ticket_sync($pdo, $j2, 'make', $by);
            bd_log('repairs', 'fx' . $j['id'], 'given to ' . ($e1 ? bd_emp_name($pdo, $e1) : $wk['name'] . ' (workman)') . ' by ' . $by . ', due ' . $due, $by);
            if ($j['assigned_emp'] && (int)$j['assigned_emp'] !== $e1) $old[(int)$j['assigned_emp']] = 1;
        }
        $live = dp_fx_live($pdo); $notified = 0;
        if ($live) {
            dp_fx_sync_items($pdo, $now);
            $jobs2 = []; foreach ($ids as $i) $jobs2[] = dp_fx_job($pdo, $i);
            $n = count($jobs2); $places = dp_fx_places_text($pdo, $jobs2);
            if ($e1) { dp_fx_push($pdo, $e1, $n . ' thing' . ($n === 1 ? '' : 's') . ' to fix', mb_substr($places, 0, 130) . ' · by ' . dp_fx_due_label($due)); $notified++; }
            else {
                $per = []; foreach ($jobs2 as $j) { if ($j['follower_emp']) $per[(int)$j['follower_emp']][] = $j; }
                foreach ($per as $f => $js) { if ($f === $emp) continue; dp_fx_push($pdo, $f, 'Check that ' . $wk['name'] . ' comes', mb_substr(dp_fx_places_text($pdo, $js), 0, 120) . ' · by ' . dp_fx_due_label($due)); $notified++; }
            }
        }
        json_response(['ok' => true, 'count' => count($jobs), 'notified' => $notified, 'live' => $live, 'message' => count($jobs) . ' problem' . (count($jobs) === 1 ? '' : 's') . ' given to ' . ($e1 ? bd_emp_name($pdo, $e1) : $wk['name']) . '.' . ($live ? '' : ' Nothing is sent yet: Repairs are not live.')]);
    }

    case 'dp.fx_unassign': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['job_ids'] ?? [])))));
        if (!$ids) json_response(['error' => 'Choose at least one problem.'], 422); $n = 0;
        foreach ($ids as $i) {
            $j = dp_fx_job($pdo, $i); if (!$j || !dp_fx_manages($acc, $j)) json_response(['error' => 'Problem not found.'], 404);
            if (!in_array($j['status'], ['given', 'doing'], true)) continue;
            $pdo->prepare("UPDATE dp_fx_jobs SET status = 'reported', assigned_emp = NULL, workman_id = NULL, follower_emp = NULL, due_at = NULL, assigned_at = NULL, seen_at = NULL, escalated = 0 WHERE id = ?")->execute([$j['id']]); $n++;
        }
        if (dp_fx_live($pdo)) dp_fx_sync_items($pdo, $now);
        json_response(['ok' => true, 'count' => $n]);
    }

    case 'dp.fx_start': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needLive();
        $j = $getJob($in['id'] ?? 0); $v = dp_fx_view($pdo, $j, $acc, $now); if (!$v['can']['start'] && $j['status'] !== 'doing') json_response(['error' => 'Only the person it was given to (or a boss) can start it.'], 403);
        if ($j['status'] === 'given') { $pdo->prepare("UPDATE dp_fx_jobs SET status = 'doing', started_at = ?, seen_at = COALESCE(seen_at, ?) WHERE id = ?")->execute([bd_now(), bd_now(), $j['id']]); dp_fx_ticket_sync($pdo, dp_fx_job($pdo, $j['id']), 'doing', $by); }
        json_response(['ok' => true, 'message' => 'Started.']);
    }

    case 'dp.fx_need_help': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needLive();
        $j = $getJob($in['id'] ?? 0); if (!dp_fx_is_doer($acc, $j) && !dp_fx_manages($acc, $j)) json_response(['error' => 'Only the person it was given to can ask for help.'], 403);
        if (!in_array($j['status'], ['given', 'doing'], true)) json_response(['error' => 'This problem is ' . $j['status'] . '.'], 409);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 250); if (mb_strlen($note) < 3) json_response(['error' => 'Write what you need (a workman, a part, a key ...).'], 422);
        $pdo->prepare("UPDATE dp_fx_jobs SET help_note = ? WHERE id = ?")->execute([$note, $j['id']]);
        $place = dp_fx_place($pdo, $j) ?: 'Office';
        bd_inbox('dp_note', 'Needs help: ' . $j['title'] . ' (' . $place . ')', ($emp ? bd_emp_name($pdo, $emp) : $by) . ' says: ' . $note, ['job_id' => (int)$j['id'], 'page' => 'dept_fix.html'], null, null, 'fx_help:' . $j['id'] . ':' . date('Ymd', $now));
        if ($j['assigned_by_emp'] && (int)$j['assigned_by_emp'] !== $emp) dp_fx_push($pdo, $j['assigned_by_emp'], 'Needs help: ' . $j['title'], mb_substr($note, 0, 120));
        json_response(['ok' => true, 'message' => 'Sent to the boss.']);
    }

    case 'dp.fx_done': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needLive();
        $j = $getJob($_POST['id'] ?? ($in['id'] ?? 0));
        if (!dp_fx_is_doer($acc, $j) && !dp_fx_manages($acc, $j)) json_response(['error' => 'Only the person it was given to (or a boss) can mark it done.'], 403);
        if (!in_array($j['status'], ['given', 'doing'], true)) json_response(['error' => ($j['status'] === 'reported' ? 'Nobody was given this yet.' : 'This problem is already ' . $j['status'] . '.')], 409);
        $cost = isset($_POST['cost']) && $_POST['cost'] !== '' ? round((float)$_POST['cost'], 2) : null;
        if ($cost !== null && ($cost < 0 || $cost > 1000000)) json_response(['error' => 'Enter the cost in rupees (0 if it was free).'], 422);
        $paid = (string)($_POST['paid_by'] ?? ''); if ($cost > 0 && !in_array($paid, ['upi', 'pocket', 'cash', 'workman'], true)) json_response(['error' => 'Choose how the cost was paid: Company UPI, Own pocket, Cash or Workman paid directly.'], 422);
        if (!($cost > 0)) $paid = '';
        $after = dp_fx_upload('after', 'fxa', true, 'Add the AFTER photo: it must show that it is fixed.');
        $bill = dp_fx_upload('bill', 'fxb', false, ''); $shot = dp_fx_upload('pay_shot', 'fxp', false, '');
        $drop = function () use ($after, $bill, $shot) { dp_fx_unlink($after); dp_fx_unlink($bill); dp_fx_unlink($shot); };
        if ($paid === 'upi' && !$shot) { $drop(); json_response(['error' => 'A Company UPI payment needs the payment screenshot.'], 422); }
        // the same payment screenshot twice (any person, 60 days)
        $ph = null; $pf = null;
        if ($shot) {
            $ph = function_exists('bd_ahash') ? bd_ahash(dp_dir() . $shot) : null;
            $pf = (function_exists('bd_fhash') && function_exists('dp_fhash_ready') && dp_fhash_ready($pdo)) ? bd_fhash(dp_dir() . $shot) : null;
            if ($pf) {   // fine fingerprint: payment pages all look alike at 8x8, so only an (almost) identical picture counts
                $q = $pdo->prepare("SELECT fhash FROM dp_proof_hash WHERE fhash IS NOT NULL AND created_at >= ?"); $q->execute([date('Y-m-d H:i:s', $now - 86400 * 60)]);
                foreach ($q->fetchAll() as $r) { if (bd_fdist($pf, $r['fhash']) <= 3) { $drop(); json_response(['error' => 'This payment screenshot looks the same as an older one. Take a new screenshot of this payment.'], 409); } }
            }
        }
        // Claude looks at before and after
        $verdict = 'not checked'; $note = '';
        if (dp_get('fx_ai', '1') === '1' && function_exists('convo_ai') && function_exists('bd_b64_small') && $j['before_photo'] && is_file(dp_dir() . $j['before_photo'])) {
            $b1 = bd_b64_small(dp_dir() . $j['before_photo']); $b2 = bd_b64_small(dp_dir() . $after);
            if ($b1 && $b2) {
                $sys = "You check a repair at a small company. Photo 1 is BEFORE (the problem), photo 2 is AFTER. fixed = true only if photo 2 clearly shows the problem is gone or repaired. same_place = true if both photos show the same place or object. "
                    . "reason = ONE short plain sentence telling the person what to fix or retake. Be fair: a normal phone photo that shows the repair passes.";
                $schema = ['type' => 'OBJECT', 'properties' => ['fixed' => ['type' => 'BOOLEAN'], 'same_place' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['fixed', 'same_place']];
                try { $r = convo_ai($sys, [['text' => 'Problem: ' . $j['title'] . ' at ' . dp_fx_place($pdo, $j) . '. BEFORE:'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $b1]], ['text' => 'AFTER:'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $b2]]], $schema, false, 300); } catch (Throwable $e) { $r = null; }
                if (is_array($r) && !empty($r[0]) && is_array($r[1])) {
                    $v = $r[1]; $note = mb_substr(trim((string)($v['reason'] ?? '')), 0, 240);
                    if (empty($v['fixed']) || empty($v['same_place'])) { $drop(); json_response(['error' => ($note !== '' ? $note : (empty($v['same_place']) ? 'The after photo does not show the same place. Take it again from the same spot.' : 'It does not look fixed yet in the photo. Fix it, or take a clearer photo.')), 'retake' => true], 422); }
                    $verdict = 'ok';
                }
            }
        }
        $pdo->prepare("UPDATE dp_fx_jobs SET status = 'done', done_at = ?, done_by_emp = ?, after_photo = ?, bill_photo = ?, pay_shot = ?, cost = ?, paid_by = ?, ai_verdict = ?, ai_note = ?, help_note = NULL, seen_at = COALESCE(seen_at, ?), started_at = COALESCE(started_at, ?) WHERE id = ?")
            ->execute([bd_now(), $emp ?: null, $after, $bill, $shot, $cost, $paid ?: null, $verdict, $note ?: null, bd_now(), bd_now(), $j['id']]);
        if ($pf) { $pdo->prepare("INSERT INTO dp_proof_hash (emp_id, rule_id, ahash, fhash, created_at) VALUES (?,?,?,?,?)")->execute([$emp ?: 0, 0, $ph ?: str_repeat('0', 64), $pf, bd_now()]); }
        elseif ($ph) { $pdo->prepare("INSERT INTO dp_proof_hash (emp_id, rule_id, ahash, created_at) VALUES (?,?,?,?)")->execute([$emp ?: 0, 0, $ph, bd_now()]); }
        $warn = '';
        if ($cost > 0 && $paid !== 'workman' && function_exists('dp_money_post')) {
            $acct = $j['workman_id'] ? ((dp_fx_workman($pdo, $j['workman_id']) ?: ['name' => ''])['name']) : ''; if ($acct === '') $acct = 'Repairs - ' . (dp_fx_office_name($pdo, $j['office_id']) ?: 'store');
            $method = $paid === 'cash' ? 'cash' : 'upi';
            dp_money_post($pdo, 'out', $cost, $acct, 'Repair: ' . $j['title'] . ' (' . dp_fx_place($pdo, $j) . ')', ['method' => $method, 'ref_key' => 'fx:' . $j['id'], 'kind' => 'vendor', 'by_emp' => $emp, 'pocket' => $paid === 'pocket', 'proof_file' => $shot ?: '']);
        }
        if ($paid === 'cash') { $warn = function_exists('dp_money_warning') ? dp_money_warning('cash', 'out', 'vendor') : 'Please avoid cash; pay by UPI.'; bd_inbox('dp_note', 'Cash used for a repair: ' . $j['title'], ($emp ? bd_emp_name($pdo, $emp) : $by) . ' paid ' . dp_inr($cost) . ' in cash.', ['job_id' => (int)$j['id']], null, null, 'fx_cash:' . $j['id']); }
        if ($paid === 'pocket') bd_inbox('dp_note', ($emp ? bd_emp_name($pdo, $emp) : $by) . ' paid ' . dp_inr($cost) . ' from his pocket for a repair', $j['title'] . '. Pay it back by UPI in Departments > Daily money > Reimbursements.', ['job_id' => (int)$j['id']], null, null, 'fx_pocket:' . $j['id']);
        bd_log('repairs', 'fx' . $j['id'], 'done' . ($cost > 0 ? ' cost ' . dp_inr($cost) . ' (' . $paid . ')' : ''), $by);
        $j2 = dp_fx_job($pdo, $j['id']);
        if ($j2['follower_emp'] && (int)$j2['follower_emp'] !== $emp) dp_fx_push($pdo, $j2['follower_emp'], 'Done: ' . $j['title'], 'Please look at it and press Checked.');
        dp_fx_notify_checkers($pdo, $j2, 'Please check: ' . $j['title'], dp_fx_place($pdo, $j2), $emp);
        dp_fx_sync_items($pdo, $now);
        json_response(['ok' => true, 'warning' => $warn, 'ai' => $verdict, 'message' => 'Done. The checker will look at it.' . ($warn ? ' ' . $warn : '')]);
    }

    case 'dp.fx_check': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $j = dp_fx_job($pdo, (int)($in['id'] ?? 0)); if (!$j || !dp_fx_manages($acc, $j)) json_response(['error' => 'Problem not found.'], 404);
        if ($j['status'] !== 'done') json_response(['error' => 'Only a problem marked Done can be checked.'], 409);
        if ($emp && ((int)$j['done_by_emp'] === $emp || (int)$j['assigned_emp'] === $emp || (int)$j['follower_emp'] === $emp)) json_response(['error' => 'You cannot check a job that was given to you or that you did. Somebody else must check it.'], 403);
        $pdo->prepare("UPDATE dp_fx_jobs SET status = 'checked', checked_by = ?, checked_by_emp = ?, checked_at = ? WHERE id = ?")->execute([$by, $emp ?: null, bd_now(), $j['id']]);
        dp_fx_ticket_sync($pdo, $j, 'done', $by);
        bd_log('repairs', 'fx' . $j['id'], 'checked by ' . $by, $by);
        if ($j['assigned_emp']) dp_fx_push($pdo, $j['assigned_emp'], 'Checked: ' . $j['title'], 'Thank you.');
        json_response(['ok' => true, 'message' => 'Checked.']);
    }

    case 'dp.fx_reopen': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $j = dp_fx_job($pdo, (int)($in['id'] ?? 0)); if (!$j || !dp_fx_manages($acc, $j)) json_response(['error' => 'Problem not found.'], 404);
        if (!in_array($j['status'], ['done', 'checked'], true)) json_response(['error' => 'Only a Done problem can be sent back.'], 409);
        $why = mb_substr(trim((string)($in['reason'] ?? '')), 0, 250); if (mb_strlen($why) < 3) json_response(['error' => 'Write what is still wrong. The person sees it.'], 422);
        $pdo->prepare("UPDATE dp_fx_jobs SET status = 'doing', reopen_note = ?, done_at = NULL, after_photo = NULL, ai_verdict = NULL, ai_note = NULL, checked_by = NULL, checked_by_emp = NULL, checked_at = NULL, escalated = 0, last_push_at = ? WHERE id = ?")->execute([$why, date('Y-m-d H:i:s', $now), $j['id']]);
        dp_fx_ticket_sync($pdo, dp_fx_job($pdo, $j['id']), 'doing', $by);
        if (dp_fx_live($pdo)) { dp_fx_sync_items($pdo, $now); dp_fx_push($pdo, $j['assigned_emp'] ?: $j['follower_emp'], 'Sent back: ' . $j['title'], $why); }
        bd_log('repairs', 'fx' . $j['id'], 'sent back: ' . $why, $by);
        json_response(['ok' => true, 'message' => 'Sent back to the person.']);
    }

    case 'dp.fx_cancel': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $j = dp_fx_job($pdo, (int)($in['id'] ?? 0)); if (!$j || !dp_fx_manages($acc, $j)) json_response(['error' => 'Problem not found.'], 404);
        if (!$acc['all'] && $j['status'] !== 'reported') json_response(['error' => 'Only a boss can cancel a problem that was already given.'], 403);
        if (!in_array($j['status'], ['reported', 'given', 'doing'], true)) json_response(['error' => 'This problem is already ' . $j['status'] . '.'], 409);
        $pdo->prepare("UPDATE dp_fx_jobs SET status = 'cancelled', cancel_note = ? WHERE id = ?")->execute([mb_substr(trim((string)($in['reason'] ?? '')), 0, 250) ?: 'Cancelled', $j['id']]);
        if ($j['source'] === 'issue' && $j['issue_id']) { try { $pdo->prepare("UPDATE of_issues SET status = 'fixed' WHERE id = ? AND status = 'open'")->execute([(int)$j['issue_id']]); } catch (Throwable $e) {} }
        if (dp_fx_live($pdo)) dp_fx_sync_items($pdo, $now);
        json_response(['ok' => true, 'message' => 'Cancelled.']);
    }

    case 'dp.fx_workman_list': {
        $needFull();
        $o = [];
        foreach ($pdo->query("SELECT * FROM dp_fx_workmen ORDER BY active DESC, name")->fetchAll() as $w) {
            $c = $pdo->prepare("SELECT SUM(status IN ('done','checked')) d, SUM(status IN ('given','doing')) o, MAX(done_at) last, SUM(IFNULL(cost,0)) cost FROM dp_fx_jobs WHERE workman_id = ?"); $c->execute([(int)$w['id']]); $x = $c->fetch();
            $o[] = ['id' => (int)$w['id'], 'name' => $w['name'], 'phone' => (string)$w['phone'], 'trade' => (string)$w['trade'], 'note' => (string)$w['note'], 'active' => (int)$w['active'], 'done' => (int)$x['d'], 'open' => (int)$x['o'], 'last' => $x['last'], 'cost' => (float)$x['cost']];
        }
        json_response(['workmen' => $o]);
    }
    case 'dp.fx_workman_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needFull();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); if (mb_strlen($name) < 2) json_response(['error' => 'Write the workman\'s name.'], 422);
        $phone = mb_substr(preg_replace('/[^0-9+ ]/', '', (string)($in['phone'] ?? '')), 0, 30); $trade = mb_substr(trim((string)($in['trade'] ?? '')), 0, 60); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200);
        $id = (int)($in['id'] ?? 0); $active = isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : 1;
        if ($id) { if (!dp_fx_workman($pdo, $id)) json_response(['error' => 'Workman not found.'], 404); $pdo->prepare("UPDATE dp_fx_workmen SET name = ?, phone = ?, trade = ?, note = ?, active = ? WHERE id = ?")->execute([$name, $phone ?: null, $trade ?: null, $note ?: null, $active, $id]); }
        else { $pdo->prepare("INSERT INTO dp_fx_workmen (name, phone, trade, note, active, created_at) VALUES (?,?,?,?,?,?)")->execute([$name, $phone ?: null, $trade ?: null, $note ?: null, 1, bd_now()]); $id = (int)$pdo->lastInsertId(); }
        json_response(['ok' => true, 'id' => $id]);
    }

    case 'dp.fx_settings': {
        if (!$acc['all']) json_response(['error' => 'Only the bosses can change this.'], 403);
        if ($isPost) {
            if (isset($in['wk_repeat_min'])) dp_put('wk_repeat_min', (string)max(0, min(720, (int)$in['wk_repeat_min'])));
            if (isset($in['fx_escalate_days'])) dp_put('fx_escalate_days', (string)max(1, min(60, (int)$in['fx_escalate_days'])));
            if (isset($in['fx_due_days'])) dp_put('fx_due_days', (string)max(0, min(60, (int)$in['fx_due_days'])));
            if (isset($in['fx_ai'])) dp_put('fx_ai', !empty($in['fx_ai']) && $in['fx_ai'] !== '0' ? '1' : '0');
        }
        json_response(['wk_repeat_min' => (int)dp_get('wk_repeat_min', '60'), 'fx_escalate_days' => (int)dp_get('fx_escalate_days', '3'), 'fx_due_days' => (int)dp_get('fx_due_days', '2'), 'fx_ai' => dp_get('fx_ai', '1') === '1']);
    }

    case 'dp.fx_file': {
        $j = $getJob($_GET['id'] ?? 0); $k = (string)($_GET['kind'] ?? 'before');
        $col = ['before' => 'before_photo', 'after' => 'after_photo', 'bill' => 'bill_photo', 'pay' => 'pay_shot'][$k] ?? '';
        if ($col === '' || empty($j[$col])) json_response(['error' => 'File not found.'], 404);
        if ($k === 'pay' && !dp_fx_manages($acc, $j) && (int)$j['done_by_emp'] !== $emp) json_response(['error' => 'Not allowed.'], 403);
        dp_file_out($j[$col]);
    }

    default: json_response(['error' => 'Unknown action: ' . $action], 404);
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Repairs: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}
