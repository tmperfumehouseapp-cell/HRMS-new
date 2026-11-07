<?php
/**
 * boss.php — the Boss Engine for the HRMS (loaded by api.php for every action that starts with "bd.").
 *
 * Claude designs the duties; this code runs them; a person approves money.
 *   sections -> seats (work responsibility) -> holders (people, with backups) -> duties -> daily tasks -> proof -> reminders -> missed -> penalty (approval) -> inbox
 * Starts in SHADOW mode: it only watches and shows what WOULD happen. Nobody sees tasks and nobody is fined until the Admin switches it on.
 *
 * Put this file in the SAME folder as api.php. It creates its own tables (bd_*) the first time it runs. PHP 7.4 safe.
 * It only READS the HRMS tables (employees, departments, attendance, day_marks, leave_requests, users) and gives penalties through the HRMS penalty engine.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('BD_LOADED')) { return; }
define('BD_LOADED', 1);

/* ============================== small helpers ============================== */
function bd_now() { return date('Y-m-d H:i:s'); }
function bd_today() { return date('Y-m-d'); }
function bd_dir() { return __DIR__ . '/uploads/boss/'; }
function bd_inr($v) { return '₹' . number_format((float)$v, 0, '.', ','); }
function bd_json($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function bd_unjson($s, $d = []) { $v = json_decode((string)$s, true); return is_array($v) ? $v : $d; }
function bd_slug($s) { $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', (string)$s), '_')); return $s === '' ? 'x' : substr($s, 0, 40); }
function bd_in($ids) { $ids = array_values(array_unique(array_map('intval', $ids))); return $ids ? implode(',', $ids) : '0'; }
function bd_tokens($s) {
    $s = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string)$s));
    $stop = ['the', 'a', 'an', 'of', 'to', 'and', 'in', 'on', 'for', 'is', 'are', 'by', 'all', 'daily', 'every', 'check', 'your', 'at', 'with'];
    $o = [];
    foreach (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) { if (strlen($w) > 2 && !in_array($w, $stop, true)) $o[$w] = 1; }
    return array_keys($o);
}
function bd_sim($a, $b) {
    $x = bd_tokens($a); $y = bd_tokens($b);
    if (!$x || !$y) return 0;
    $i = count(array_intersect($x, $y)); $u = count(array_unique(array_merge($x, $y)));
    return $u ? $i / $u : 0;
}

/* ============================== settings ============================== */
function bd_default_settings() {
    return [
        'mode' => 'shadow',            // shadow = watch only | soft = staff see tasks, penalties only simulated | live = everything on
        'ai_gate' => '1',              // photo duties are checked by the AI before they are accepted
        'max_retakes' => '3',          // after this many AI refusals a human checker decides (the person is never stuck)
        'task_cap' => '8',             // most tasks one person should get in a day (more = warning in Collisions)
        'pen_cap_day' => '300',        // most money one person can lose to the Boss Engine in a day (Rs)
        'pen_cap_month' => '1500',     // ... and in a month. Confirm wage-deduction limits with your CA.
        'remind_min' => '30',          // first reminder this many minutes before the deadline
        'away_after_min' => '90',      // not punched in this long after shift start = treated as away today (work moves to the backup)
        'digest_hour' => '21',         // the daily summary goes to the top boss after this hour
        'top_boss' => '', 'deputy' => '',
        'pages_dir' => (function () { $f = __DIR__ . '/boss_conf.php'; if (is_file($f)) { $c = @include $f; if (is_array($c) && !empty($c['pages_dir'])) return (string)$c['pages_dir']; } foreach ([dirname(__DIR__), __DIR__] as $d) { if (is_file($d . '/assets/js/layout.js')) return $d; } return ''; })(),   // the folder with the pages: found by itself (the folder above api.php, or the same folder); can be changed in Boss Setup
        'tick_key' => '',
        'last_tick' => '', 'last_scan' => '', 'last_digest' => '', 'installed_at' => '',
    ];
}
function bd_cfg($k = null) {
    global $pdo; static $c = null;
    if ($c === null) {
        $c = bd_default_settings();
        try { foreach ($pdo->query("SELECT skey, svalue FROM bd_settings")->fetchAll() as $r) $c[$r['skey']] = $r['svalue']; } catch (Throwable $e) {}
    }
    if ($k === null) return $c;
    return $c[$k] ?? '';
}
function bd_set($k, $v) {
    global $pdo;
    $pdo->prepare("INSERT INTO bd_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]);
    bd_cfg(); // warm
    $GLOBALS['__bd_cfg_dirty'] = 1;
}
function bd_cfg_fresh($k) {
    global $pdo;
    try { $st = $pdo->prepare("SELECT svalue FROM bd_settings WHERE skey = ?"); $st->execute([$k]); $v = $st->fetchColumn(); if ($v !== false) return $v; } catch (Throwable $e) {}
    $d = bd_default_settings(); return $d[$k] ?? '';
}
function bd_mode() { $m = bd_cfg_fresh('mode'); return in_array($m, ['shadow', 'soft', 'live'], true) ? $m : 'shadow'; }

/* ============================== tables ============================== */
function bd_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    $need = ['bd_settings', 'bd_sections', 'bd_seats', 'bd_holders', 'bd_duties', 'bd_tasks', 'bd_inbox', 'bd_log', 'bd_manifest', 'bd_interview', 'bd_seasons', 'bd_photos'];
    $have = true;
    foreach ($need as $t) { try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable $e) { $have = false; break; } }
    if (!$have) {
        $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_settings (skey VARCHAR(40) PRIMARY KEY, svalue TEXT NULL)$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_sections (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
            dept_ids VARCHAR(200) NULL, source VARCHAR(12) NOT NULL DEFAULT 'manual', about VARCHAR(255) NULL,
            pages TEXT NULL, state VARCHAR(12) NOT NULL DEFAULT 'new', active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_seats (
            id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NULL, code VARCHAR(60) NOT NULL UNIQUE, title VARCHAR(120) NOT NULL,
            kind VARCHAR(14) NOT NULL DEFAULT 'worker', need_people TINYINT NOT NULL DEFAULT 1, active TINYINT NOT NULL DEFAULT 1,
            needs_perm VARCHAR(200) NULL, created_at DATETIME NOT NULL, INDEX (section_id)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_holders (
            id INT AUTO_INCREMENT PRIMARY KEY, seat_id INT NOT NULL, emp_id INT NOT NULL, rank_no TINYINT NOT NULL DEFAULT 1,
            since DATE NOT NULL, UNIQUE KEY u_seat_emp (seat_id, emp_id), INDEX (emp_id)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_duties (
            id INT AUTO_INCREMENT PRIMARY KEY, lib_key VARCHAR(60) NULL, section_id INT NOT NULL, seat_id INT NULL,
            title VARCHAR(160) NOT NULL, why VARCHAR(255) NULL,
            freq VARCHAR(10) NOT NULL DEFAULT 'daily', dow VARCHAR(20) NULL, dom TINYINT NULL, on_date DATE NULL, season_from DATE NULL, season_to DATE NULL,
            start_time CHAR(5) NULL, due_time CHAR(5) NOT NULL DEFAULT '18:00', grace_min SMALLINT NOT NULL DEFAULT 15,
            proof VARCHAR(10) NOT NULL DEFAULT 'tick', adapter VARCHAR(20) NULL, photo_prompt VARCHAR(500) NULL, ref_photo VARCHAR(160) NULL,
            target_qty INT NULL, cmp VARCHAR(4) NOT NULL DEFAULT 'min', unit VARCHAR(30) NULL, assign_mode VARCHAR(10) NOT NULL DEFAULT 'first',
            checker_seat_id INT NULL, pen_amount DECIMAL(10,2) NOT NULL DEFAULT 0, pen_mode VARCHAR(8) NOT NULL DEFAULT 'approve', pen_remark VARCHAR(255) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'proposed', source VARCHAR(12) NOT NULL DEFAULT 'manual', version INT NOT NULL DEFAULT 1,
            created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, INDEX (section_id), INDEX (status), INDEX (lib_key)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY, duty_id INT NOT NULL, section_id INT NOT NULL, task_date DATE NOT NULL, slot INT NOT NULL DEFAULT 0,
            owner_emp INT NULL, orig_emp INT NULL, cover_why VARCHAR(120) NULL, start_at DATETIME NULL, due_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open', done_at DATETIME NULL, proof_path VARCHAR(160) NULL, proof_value INT NULL, proof_note VARCHAR(255) NULL,
            ai_state VARCHAR(8) NOT NULL DEFAULT '', ai_reason VARCHAR(255) NULL, attempts TINYINT NOT NULL DEFAULT 0,
            check_state VARCHAR(8) NOT NULL DEFAULT '', checked_by INT NULL,
            pen_state VARCHAR(8) NOT NULL DEFAULT 'none', pen_amount DECIMAL(10,2) NOT NULL DEFAULT 0, pen_id INT NULL, pen_remark VARCHAR(255) NULL,
            reminded TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            UNIQUE KEY u_task (duty_id, task_date, slot), INDEX (task_date, status), INDEX (owner_emp, task_date), INDEX (section_id, task_date)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NULL, payload TEXT NULL,
            section_id INT NULL, to_emp INT NULL, ref_key VARCHAR(80) NULL, status VARCHAR(10) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL, resolved_at DATETIME NULL, resolved_by VARCHAR(80) NULL, UNIQUE KEY u_ref (ref_key), INDEX (status, created_at)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, actor VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL, ref VARCHAR(40) NULL, detail VARCHAR(400) NULL, INDEX (at), INDEX (kind))$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_manifest (path VARCHAR(160) PRIMARY KEY, sig VARCHAR(40) NOT NULL, kind VARCHAR(8) NOT NULL, section_code VARCHAR(40) NULL, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL)$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_interview (id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, answers TEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'asking', follow_ups TEXT NULL, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, INDEX (section_id))$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_seasons (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL, event_date DATE NOT NULL, lead_days SMALLINT NOT NULL DEFAULT 14, raised TINYINT NOT NULL DEFAULT 0, UNIQUE KEY u_s (code, event_date))$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bd_photos (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, duty_id INT NOT NULL, emp_id INT NOT NULL, path VARCHAR(160) NOT NULL, phash CHAR(64) NULL, verdict VARCHAR(8) NOT NULL, reason VARCHAR(255) NULL, created_at DATETIME NOT NULL, INDEX (task_id), INDEX (duty_id, created_at))$E");
    }
    $d = bd_dir();
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && !is_file($d . '.htaccess')) {
        @file_put_contents($d . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($d . 'index.html', '');
    }
    if (bd_cfg_fresh('tick_key') === '') { try { bd_set('tick_key', bin2hex(random_bytes(8))); } catch (Throwable $e) { bd_set('tick_key', substr(md5(uniqid('', true)), 0, 16)); } }
    $c = (int)$pdo->query("SELECT COUNT(*) FROM bd_sections")->fetchColumn();
    if ($c === 0) bd_seed_sections($pdo);
    $c = (int)$pdo->query("SELECT COUNT(*) FROM bd_seasons")->fetchColumn();
    if ($c === 0) bd_seed_seasons($pdo);
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['bd_settings', 'bd_sections', 'bd_seats', 'bd_holders', 'bd_duties', 'bd_tasks', 'bd_inbox', 'bd_log', 'bd_manifest', 'bd_interview', 'bd_seasons', 'bd_photos']);
}

function bd_log($kind, $ref, $detail, $actor = 'Boss Engine') {
    global $pdo;
    try { $pdo->prepare("INSERT INTO bd_log (at, actor, kind, ref, detail) VALUES (?,?,?,?,?)")->execute([bd_now(), mb_substr($actor, 0, 80), $kind, mb_substr((string)$ref, 0, 40), mb_substr((string)$detail, 0, 400)]); } catch (Throwable $e) {}
}

/* ============================== people & access ============================== */
function bd_emp(PDO $pdo, $id) {
    static $cache = [];
    $id = (int)$id; if ($id <= 0) return null;
    if (!array_key_exists($id, $cache)) {
        try {
            $st = $pdo->prepare("SELECT e.employee_id, e.full_name, e.employee_code, e.status, e.department_id, e.designation, e.monthly_salary, d.department_name
                FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?");
            $st->execute([$id]); $cache[$id] = $st->fetch() ?: null;
        } catch (Throwable $e) {
            try { $st = $pdo->prepare("SELECT employee_id, full_name, employee_code, status, department_id FROM employees WHERE employee_id = ?"); $st->execute([$id]); $r = $st->fetch(); if ($r) { $r['department_name'] = ''; $r['designation'] = ''; $r['monthly_salary'] = 0; } $cache[$id] = $r ?: null; } catch (Throwable $e2) { $cache[$id] = null; }
        }
    }
    return $cache[$id];
}
function bd_emp_name($pdo, $id) { $e = bd_emp($pdo, $id); return $e ? (string)$e['full_name'] : ($id ? 'Employee #' . (int)$id : '—'); }
function bd_emp_active($e) { return $e && strtolower((string)($e['status'] ?? '')) !== 'ex'; }
function bd_employees(PDO $pdo) {
    try { return $pdo->query("SELECT e.employee_id AS id, e.full_name AS name, e.employee_code AS code, e.department_id, d.department_name AS dept, e.designation FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE (e.status IS NULL OR e.status != 'ex') ORDER BY e.full_name")->fetchAll(); }
    catch (Throwable $e) { try { return $pdo->query("SELECT employee_id AS id, full_name AS name, employee_code AS code, department_id, '' AS dept, '' AS designation FROM employees WHERE (status IS NULL OR status != 'ex') ORDER BY full_name")->fetchAll(); } catch (Throwable $e2) { return []; } }
}

/** who is asking: ['admin'=>bool, 'emp'=>int, 'sections'=>[ids they lead or check], 'top'=>bool] */
function bd_who(PDO $pdo, array $auth) {
    $role = (string)($auth['role'] ?? '');
    $emp = (int)($auth['employee_id'] ?? 0);
    $admin = in_array($role, ['Admin', 'HR'], true);
    $secs = []; $top = false;
    if ($emp) {
        try {
            $st = $pdo->prepare("SELECT s.section_id, s.kind FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE h.emp_id = ? AND s.active = 1 AND s.kind IN ('boss','deputy','section_boss','checker')");
            $st->execute([$emp]);
            foreach ($st->fetchAll() as $r) { if (in_array($r['kind'], ['boss', 'deputy'], true)) $top = true; elseif ($r['section_id']) $secs[(int)$r['section_id']] = 1; }
        } catch (Throwable $e) {}
        if ((int)bd_cfg_fresh('top_boss') === $emp || (int)bd_cfg_fresh('deputy') === $emp) $top = true;
    }
    return ['admin' => $admin, 'role' => $role, 'emp' => $emp, 'top' => $top, 'sections' => array_keys($secs), 'manager' => $admin || $top || !empty($secs)];
}
function bd_need_manager(PDO $pdo, array $auth) {
    $w = bd_who($pdo, $auth);
    if (!$w['manager']) json_response(['error' => 'The Boss Engine management pages are for Admin, HR and section bosses.'], 403);
    return $w;
}
/** Boss Engine actions that belong to a PAGE people can be given on the Roles page: action prefix => the ticks that open it. */
function bd_page_perms(string $action): array {
    static $map = [
        'bd.b2_cm_' => ['commission', 'commission_rules'],      // Sales → Team → Commission → Result & rules (commission_new.html): view, recount, chart, offers, boosts
        'bd.b2_punch_set' => ['commission'],                    // Total Budgeting → person card → Set pay, salary and timing: the timing save (a Sales Head has the Commission tick)
    ];
    foreach ($map as $prefix => $perms) if (strpos($action, $prefix) === 0) return $perms;
    return [];
}
function bd_need_admin(array $auth) {
    if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) return;
    // A role Admin gave the page's tick to (e.g. Sales Head → Commission) gets everything on that page, not only the menu entry.
    global $action;
    foreach (bd_page_perms((string)$action) as $perm) if (function_exists('user_can') && user_can($auth, $perm)) return;
    json_response(['error' => 'Only Admin or HR can do this.'], 403);
}
function bd_can_section(array $w, $sectionId) {
    return $w['admin'] || $w['top'] || in_array((int)$sectionId, $w['sections'], true);
}

/* away today? leave / holiday / week off marks, approved leave, or not punched in long after the shift began */
function bd_away(PDO $pdo, $empId, $date, $nowTs = null) {
    static $marks = []; static $shift = [];
    $empId = (int)$empId;
    $e = bd_emp($pdo, $empId);
    if (!bd_emp_active($e)) return 'left the company';
    if (!isset($marks[$date])) {
        $marks[$date] = function_exists('day_marks_between') ? day_marks_between($pdo, $date, $date) : [];
    }
    $m = $marks[$date][$date . '|' . $empId] ?? '';
    if (in_array($m, ['leave', 'holiday', 'week_off'], true)) return $m === 'leave' ? 'on leave' : ($m === 'holiday' ? 'holiday' : 'week off');
    try {
        $st = $pdo->prepare("SELECT 1 FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND leave_type IN ('leave','half_leave') AND date_from <= ? AND date_to >= ? LIMIT 1");
        $st->execute([$empId, $date, $date]);
        if ($st->fetchColumn()) return 'on leave';
    } catch (Throwable $e2) {}
    if ($date === bd_today()) {
        $ts = $nowTs ?: time();
        if (!isset($shift[$date]) && function_exists('emp_shift_map')) { try { $shift[$date] = emp_shift_map($pdo, $date); } catch (Throwable $e3) { $shift[$date] = []; } }
        $ss = $shift[$date][$empId]['shift_start'] ?? '10:00';
        if (!preg_match('/^\d{2}:\d{2}$/', (string)$ss)) $ss = '10:00';
        // Punch rule: "no rule" people are never away; "hours only" people have no set start, so they only count as away
        // after their latest punch-in time (and never when no latest time is set).
        $pm = $shift[$date][$empId]['mode'] ?? 'fixed';
        if ($pm === 'free') return '';
        if ($pm === 'hours') {
            $wt = (string)($shift[$date][$empId]['win_to'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $wt)) return '';
            $ss = $wt;
        }
        $limit = strtotime($date . ' ' . $ss) + 60 * max(15, (int)bd_cfg('away_after_min'));
        if ($ts > $limit) {
            try {
                // only people who normally punch in can be "not in": someone who never punches (owner, field staff) is never moved around
                static $usesPunch = [];
                if (!isset($usesPunch[$empId])) {
                    $u = $pdo->prepare("SELECT 1 FROM attendance WHERE employee_id = ? AND punch_date BETWEEN ? AND ? AND punch_in IS NOT NULL LIMIT 1");
                    $u->execute([$empId, date('Y-m-d', strtotime($date . ' -14 days')), date('Y-m-d', strtotime($date . ' -1 day'))]);
                    $usesPunch[$empId] = (bool)$u->fetchColumn();
                }
                if ($usesPunch[$empId]) {
                    $st = $pdo->prepare("SELECT 1 FROM attendance WHERE employee_id = ? AND punch_date = ? AND punch_in IS NOT NULL LIMIT 1");
                    $st->execute([$empId, $date]);
                    if (!$st->fetchColumn()) return 'not in today';
                }
            } catch (Throwable $e4) {}
        }
    }
    return '';
}

/* ============================== sections, seats, holders ============================== */
function bd_section_defs() {
    // code => [name, about, department-name words, page patterns]
    return [
        'store'     => ['Retail Store', 'The shop: opening, cleaning, display, stock, cash, parcels at the counter', ['store', 'retail', 'shop', 'pos'], ['retail_*.html', 'store_*.html', 'my_store_*.html', 'flyer*.html', 'rs_*.html', 'retail_*.php', 'flyers.php']],
        'care'      => ['Customer Care', 'Chats, complaints, reviews and call-backs', ['customer', 'care', 'support'], ['whatsapp*.html', 'my_chats.html', 'convo*.html', 'reviews*.html', 'convo*.php']],
        'complaints' => ['Complaints', 'Every customer problem: care, exchange, resend, reverse pickup, refund', ['complaint', 'resolution'], ['complaints*.html', 'my_complaints.html']],
        'sales'     => ['Sales', 'Enquiries, orders, targets and COD confirmation', ['sales'], ['sales*.html', 'my_sales.html', 'orders.html', 'order_*.html', 'my_orders.html', 'commission*.html', 'ct_*.php', 'leader_*.php', 'order_*.php']],
        'dispatch'  => ['Parcels & Dispatch', 'Packing, courier hand-over, NDR and returns', ['dispatch', 'parcel', 'logistic', 'packing', 'courier'], ['dispatch*.html', 'parcel*.html', 'shiprocket*.html', 'rto*.html']],
        'hr'        => ['HR & Admin', 'Attendance, leave, salary, penalties, documents', ['hr', 'admin', 'human'], ['attendance*.html', 'leave*.html', 'salary.html', 'penalties.html', 'employees.html', 'agreements.html', 'offices.html', 'approvals.html']],
        'accounts'  => ['Accounts & Cash', 'Cash book, petty cash, GST, dues', ['account', 'finance', 'cash'], ['finance_*.html', 'petty*.html', 'cash*.html', 'expense*.html', 'account*.html', 'gst*.html']],
        'marketing' => ['Marketing & Flyers', 'Posts, ads, posters, flyers, festivals', ['market', 'brand', 'creative', 'design', 'social'], ['marketing*.html', 'ads*.html', 'poster*.html', 'creative*.html']],
    ];
}
function bd_seed_sections(PDO $pdo) {
    $now = bd_now();
    foreach (bd_section_defs() as $code => $d) {
        $pdo->prepare("INSERT IGNORE INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$code, $d[0], $d[1], bd_json($d[3]), 'seed', 'new', $now]);
    }
    bd_map_departments($pdo, false);
    foreach ($pdo->query("SELECT id, code, name FROM bd_sections")->fetchAll() as $s) bd_make_seats($pdo, (int)$s['id'], $s['code'], $s['name']);
    $pdo->prepare("INSERT IGNORE INTO bd_seats (section_id, code, title, kind, created_at) VALUES (NULL, 'top.boss', 'Top Boss', 'boss', ?)")->execute([$now]);
    $pdo->prepare("INSERT IGNORE INTO bd_seats (section_id, code, title, kind, created_at) VALUES (NULL, 'top.deputy', 'Second Boss (deputy)', 'deputy', ?)")->execute([$now]);
}
function bd_make_seats(PDO $pdo, $sid, $code, $name) {
    $now = bd_now();
    $defs = [['boss', $name . ' boss', 'section_boss'], ['checker', $name . ' checker', 'checker'], ['worker', $name . ' team', 'worker']];
    foreach ($defs as $d) {
        $pdo->prepare("INSERT IGNORE INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([$sid, $code . '.' . $d[0], $d[1], $d[2], $now]);
    }
}
function bd_seat_id(PDO $pdo, $code) {
    $st = $pdo->prepare("SELECT id FROM bd_seats WHERE code = ?"); $st->execute([$code]); $v = $st->fetchColumn(); return $v ? (int)$v : 0;
}
function bd_section_seat(PDO $pdo, $sectionId, $kind) {
    $st = $pdo->prepare("SELECT id FROM bd_seats WHERE section_id = ? AND kind = ? AND active = 1 ORDER BY id LIMIT 1");
    $st->execute([(int)$sectionId, $kind]); $v = $st->fetchColumn(); return $v ? (int)$v : 0;
}
/** link HRMS departments to sections; unknown departments become new sections (source 'dept'). Returns the new section ids. */
function bd_map_departments(PDO $pdo, $report = true) {
    $new = [];
    try { $depts = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_id")->fetchAll(); } catch (Throwable $e) { return $new; }
    $defs = bd_section_defs();
    $secs = []; foreach ($pdo->query("SELECT id, code, dept_ids FROM bd_sections")->fetchAll() as $s) $secs[$s['code']] = $s;
    $linked = [];
    foreach ($secs as $s) foreach (array_filter(explode(',', (string)$s['dept_ids'])) as $d) $linked[(int)$d] = $s['code'];
    foreach ($depts as $d) {
        $did = (int)$d['department_id']; if (isset($linked[$did])) continue;
        $name = strtolower((string)$d['department_name']); $code = '';
        $words = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($defs as $c => $def) { foreach ($def[2] as $term) { foreach ($words as $wd) { if (strlen($term) <= 3 ? $wd === $term : strpos($wd, $term) === 0) { $code = $c; break 3; } } } }
        if ($code === '') {
            $code = 'd_' . bd_slug($d['department_name']);
            if (!isset($secs[$code])) {
                $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at, dept_ids) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$code, (string)$d['department_name'], 'Created from the department list', '[]', 'dept', 'new', bd_now(), (string)$did]);
                $sid = (int)$pdo->lastInsertId();
                bd_make_seats($pdo, $sid, $code, (string)$d['department_name']);
                $secs[$code] = ['id' => $sid, 'code' => $code, 'dept_ids' => (string)$did];
                $new[] = $sid;
                if ($report) bd_inbox('new_section', 'New section found: ' . $d['department_name'], 'A new department was added to the HRMS. Tell me what this team does and I will draft its duties, seats and checks.', ['section_id' => $sid], $sid, null, 'newsec:' . $sid);
                continue;
            }
        }
        $cur = (string)($secs[$code]['dept_ids'] ?? '');
        $ids = array_filter(explode(',', $cur)); $ids[] = $did;
        $pdo->prepare("UPDATE bd_sections SET dept_ids = ? WHERE code = ?")->execute([implode(',', array_unique($ids)), $code]);
        $secs[$code]['dept_ids'] = implode(',', $ids);
    }
    return $new;
}
function bd_dept_section_map(PDO $pdo) {
    $m = [];
    foreach ($pdo->query("SELECT id, dept_ids FROM bd_sections WHERE active = 1")->fetchAll() as $s) foreach (array_filter(explode(',', (string)$s['dept_ids'])) as $d) $m[(int)$d] = (int)$s['id'];
    return $m;
}
function bd_holders(PDO $pdo, $seatId) {
    $st = $pdo->prepare("SELECT h.emp_id, h.rank_no FROM bd_holders h WHERE h.seat_id = ? ORDER BY h.rank_no, h.id");
    $st->execute([(int)$seatId]); $o = [];
    foreach ($st->fetchAll() as $r) { $e = bd_emp($pdo, $r['emp_id']); if (bd_emp_active($e)) $o[] = (int)$r['emp_id']; }
    return $o;
}
function bd_set_holders(PDO $pdo, $seatId, array $emps) {
    $emps = array_values(array_unique(array_filter(array_map('intval', $emps))));
    $pdo->prepare("DELETE FROM bd_holders WHERE seat_id = ?")->execute([(int)$seatId]);
    $i = 1;
    foreach ($emps as $e) { $pdo->prepare("INSERT INTO bd_holders (seat_id, emp_id, rank_no, since) VALUES (?,?,?,?)")->execute([(int)$seatId, $e, min(9, $i), bd_today()]); $i++; }
}
function bd_seat_row(PDO $pdo, $seatId) {
    $st = $pdo->prepare("SELECT * FROM bd_seats WHERE id = ?"); $st->execute([(int)$seatId]); return $st->fetch() ?: null;
}

/* ============================== who does a duty today (with absence fallback) ============================== */
function bd_task_load(PDO $pdo, $empId, $date) {
    static $cache = [];
    $k = $date . '|' . $empId;
    if (!isset($cache[$k])) { $st = $pdo->prepare("SELECT COUNT(*) FROM bd_tasks WHERE owner_emp = ? AND task_date = ? AND status IN ('open','done')"); $st->execute([(int)$empId, $date]); $cache[$k] = (int)$st->fetchColumn(); }
    return $cache[$k] + (int)($GLOBALS['__bd_planned'][$k] ?? 0);
}
function bd_note_planned($empId, $date) { $k = $date . '|' . $empId; $GLOBALS['__bd_planned'][$k] = (int)($GLOBALS['__bd_planned'][$k] ?? 0) + 1; }

/** first available person above/next to a seat: other holders of the section team, section boss, deputy, top boss. */
function bd_fallback(PDO $pdo, $duty, $date, array $skip) {
    $chain = [];
    $sid = (int)$duty['section_id'];
    foreach (['worker', 'checker', 'section_boss'] as $kind) {
        $seat = bd_section_seat($pdo, $sid, $kind);
        if ($seat) $chain[] = ['seat' => $seat, 'label' => $kind];
    }
    $chain[] = ['seat' => bd_seat_id($pdo, 'top.deputy'), 'label' => 'deputy'];
    $chain[] = ['seat' => bd_seat_id($pdo, 'top.boss'), 'label' => 'top boss'];
    foreach ($chain as $c) {
        if (!$c['seat']) continue;
        foreach (bd_holders($pdo, $c['seat']) as $e) {
            if (in_array($e, $skip, true)) continue;
            if (bd_away($pdo, $e, $date) === '') return [$e, $c['label']];
        }
    }
    $tb = (int)bd_cfg_fresh('top_boss'); if ($tb && !in_array($tb, $skip, true) && bd_away($pdo, $tb, $date) === '') return [$tb, 'top boss'];
    return [0, ''];
}
/** returns list of [slot, owner|null, orig|null, why]. */
function bd_resolve(PDO $pdo, $duty, $date) {
    $seat = (int)$duty['seat_id'];
    if (!$seat) $seat = bd_section_seat($pdo, (int)$duty['section_id'], 'worker');
    $holders = $seat ? bd_holders($pdo, $seat) : [];
    $mode = (string)$duty['assign_mode'];
    $out = [];
    if (!$holders) {
        list($fb, $lab) = bd_fallback($pdo, $duty, $date, []);
        if ($fb) return [[0, $fb, null, 'nobody holds this seat yet - covered by the ' . $lab]];
        return [[0, null, null, 'nobody can do this']];
    }
    if ($mode === 'all') {
        foreach ($holders as $h) {
            $aw = bd_away($pdo, $h, $date);
            if ($aw === '') { $out[] = [$h, $h, null, '']; continue; }
            list($fb, $lab) = bd_fallback($pdo, $duty, $date, $holders);
            $out[] = [$h, $fb ?: null, $h, bd_emp_name($pdo, $h) . ' is ' . $aw . ($fb ? ' - covered by the ' . $lab : ' - nobody free')];
        }
        return $out;
    }
    $free = []; $awayWhy = [];
    foreach ($holders as $h) { $aw = bd_away($pdo, $h, $date); if ($aw === '') $free[] = $h; else $awayWhy[$h] = $aw; }
    if ($free) {
        if ($mode === 'rotate' && count($free) > 1) {
            $day = (int)date('z', strtotime($date)) + (int)$duty['id'];
            $pick = $free[$day % count($free)];
            $under = array_values(array_filter($free, function ($e) use ($pdo, $date) { return bd_task_load($pdo, $e, $date) < (int)bd_cfg('task_cap'); }));
            if ($under && !in_array($pick, $under, true)) $pick = $under[$day % count($under)];
        } else {
            $pick = $free[0];
            if (bd_task_load($pdo, $pick, $date) >= (int)bd_cfg('task_cap')) { foreach ($free as $f) { if (bd_task_load($pdo, $f, $date) < (int)bd_cfg('task_cap')) { $pick = $f; break; } } }
        }
        $first = $holders[0];
        $why = ($pick !== $first && isset($awayWhy[$first])) ? bd_emp_name($pdo, $first) . ' is ' . $awayWhy[$first] . ' - backup took it' : '';
        return [[0, $pick, $pick !== $first && isset($awayWhy[$first]) ? $first : null, $why]];
    }
    list($fb, $lab) = bd_fallback($pdo, $duty, $date, $holders);
    $first = $holders[0];
    return [[0, $fb ?: null, $first, bd_emp_name($pdo, $first) . ' is ' . ($awayWhy[$first] ?? 'away') . ($fb ? ' - covered by the ' . $lab : ' - nobody free')]];
}

/* ============================== which duties fall on a date ============================== */
function bd_is_due(array $d, $date) {
    $ts = strtotime($date); $n = (int)date('N', $ts); $f = (string)$d['freq'];
    $dow = array_filter(array_map('intval', explode(',', (string)($d['dow'] ?? ''))));
    if ($f === 'once') return (string)$d['on_date'] === $date;
    if ($f === 'season') {
        if (!$d['season_from'] || !$d['season_to'] || $date < $d['season_from'] || $date > $d['season_to']) return false;
        return !$dow || in_array($n, $dow, true);
    }
    if ($f === 'daily') return !$dow || in_array($n, $dow, true);
    if ($f === 'weekly') return $dow ? in_array($n, $dow, true) : $n === 1;
    if ($f === 'monthly') { $dom = max(1, min(31, (int)($d['dom'] ?: 1))); $last = (int)date('t', $ts); return (int)date('j', $ts) === min($dom, $last); }
    return false;
}

/* ============================== inbox ============================== */
function bd_inbox($kind, $title, $body, array $payload, $sectionId = null, $toEmp = null, $refKey = null) {
    global $pdo;
    try {
        if ($refKey !== null) {
            $st = $pdo->prepare("SELECT id, status FROM bd_inbox WHERE ref_key = ?"); $st->execute([$refKey]); $r = $st->fetch();
            if ($r) return (int)$r['id'];
        }
        $pdo->prepare("INSERT INTO bd_inbox (kind, title, body, payload, section_id, to_emp, ref_key, created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$kind, mb_substr($title, 0, 200), $body, bd_json($payload), $sectionId, $toEmp, $refKey, bd_now()]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return 0; }
}

/* ============================== library of duties (Claude's starting set; the boss approves each) ============================== */
// key => section, seat kind, title, why, freq, dow, dom, due, grace, proof, adapter, photo prompt, target, cmp, unit, assign, pen Rs, pen remark
function bd_library() {
    $P = function ($sec, $seat, $title, $why, $freq, $dow, $dom, $due, $grace, $proof, $adapter, $prompt, $target, $cmp, $unit, $assign, $pen, $rem) {
        return ['section' => $sec, 'seat' => $seat, 'title' => $title, 'why' => $why, 'freq' => $freq, 'dow' => $dow, 'dom' => $dom, 'due' => $due, 'grace' => $grace, 'proof' => $proof, 'adapter' => $adapter, 'prompt' => $prompt, 'target' => $target, 'cmp' => $cmp, 'unit' => $unit, 'assign' => $assign, 'pen' => $pen, 'remark' => $rem];
    };
    return [
        'store_open'      => $P('store', 'worker', 'Open the shop: lights, AC, billing screen on', 'Customers walk in at opening time.', 'daily', '', null, '10:30', 15, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'store_counter'   => $P('store', 'worker', 'Clean the billing counter and glass - photo', 'A clean counter is the first thing a customer sees.', 'daily', '', null, '11:00', 15, 'photo', '', 'The billing counter and display glass. The counter must be clear of clutter and packaging waste, the glass free of dust and fingerprints.', null, 'min', '', 'first', 100, 'Counter was not clean by 11 AM.'),
        'store_floor'     => $P('store', 'worker', 'Sweep and mop the shop floor - photo', 'Dust on the floor spoils the brand.', 'daily', '', null, '11:00', 15, 'photo', '', 'The shop floor. It must look swept and mopped: no dust, paper, stains or footprints.', null, 'min', '', 'first', 100, 'Floor was not clean by 11 AM.'),
        'store_shelf'     => $P('store', 'worker', 'Tidy the shelves and testers - photo', 'Bottles in neat rows sell better.', 'daily', '', null, '12:00', 15, 'photo', '', 'The perfume shelves. Bottles in neat rows with labels facing front, testers clean and in place, no gaps or fallen bottles.', null, 'min', '', 'first', 0, ''),
        'store_pantry'    => $P('store', 'worker', 'Clean the pantry - photo', 'Staff hygiene and no smell in the shop.', 'daily', '', null, '16:00', 20, 'photo', '', 'The pantry: platform, sink and floor clean, no used cups or food waste lying around, dustbin not overflowing.', null, 'min', '', 'rotate', 100, 'Pantry was not clean.'),
        'store_order'     => $P('store', 'worker', 'Check low stock and place the order', 'No sale should be lost because a bottle ran out.', 'daily', '', null, '12:30', 30, 'tick', '', '', null, 'min', '', 'first', 100, 'Daily stock-order check was not done.'),
        'store_count'     => $P('store', 'worker', 'Weekly stock count - enter the mismatches found', 'Stock in the system must match the shelf.', 'weekly', '6', null, '18:00', 30, 'count', '', '', null, 'any', 'mismatches', 'first', 0, ''),
        'store_rjd'       => $P('store', 'worker', 'RJD parcels: none older than 3 days (enter how many are older)', 'RJD parcels must go out within 3 days.', 'daily', '', null, '17:00', 30, 'count', '', '', 0, 'max', 'parcels older than 3 days', 'first', 100, 'RJD parcel check not done.'),
        'store_recv'      => $P('store', 'worker', 'Receive and scan incoming parcels (enter how many are still unscanned)', 'Incoming parcels are received and scanned within 1 day.', 'daily', '', null, '19:00', 30, 'count', '', '', 0, 'max', 'parcels not scanned', 'first', 0, ''),
        'store_flyers'    => $P('store', 'worker', 'Hand out 40 flyers (photo proof from the Flyers page)', 'Flyers bring walk-in customers.', 'daily', '', null, '19:00', 30, 'auto', 'flyers', '', 40, 'min', 'flyers', 'rotate', 100, 'Flyer target was not completed.'),
        'store_cash'      => $P('store', 'worker', 'Close the cash register - photo of the count', 'Cash must match the bills every day.', 'daily', '', null, '21:30', 30, 'photo', '', 'The cash count: notes counted and arranged, with the count slip or notebook entry clearly readable.', null, 'min', '', 'first', 200, 'Cash was not closed and counted.'),
        'store_close'     => $P('store', 'worker', 'Close the shop: lights, AC, shutter - photo', 'Nothing left on, shop locked.', 'daily', '', null, '22:00', 30, 'photo', '', 'The shop front after closing: shutter down and locked, lights off.', null, 'min', '', 'first', 0, ''),
        'store_poster'    => $P('store', 'worker', 'Window poster is current and clean - photo', 'The poster must match the current offer or season.', 'monthly', '', 1, '12:00', 60, 'photo', '', 'The shop window / poster area: a clean, undamaged, current poster.', null, 'min', '', 'first', 0, ''),
        'store_review'    => $P('store', 'section_boss', 'Weekly review: cleaning photos, missed tasks, penalties', 'The boss looks at the week once.', 'weekly', '7', null, '20:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'care_reply'      => $P('care', 'worker', 'Reply to every pending chat (enter how many are still unanswered)', 'No customer should wait.', 'daily', '', null, '20:00', 30, 'count', '', '', 0, 'max', 'unanswered chats', 'first', 0, ''),
        'care_complaint'  => $P('care', 'worker', 'Call back every open complaint (enter how many you called)', 'A complaint left alone becomes a bad review.', 'daily', '', null, '18:00', 30, 'count', '', '', null, 'any', 'complaints called', 'first', 0, ''),
        'care_review'     => $P('care', 'worker', 'Ask delivered customers for a review (enter how many you messaged)', 'Reviews bring new customers.', 'daily', '', null, '17:00', 30, 'count', '', '', 10, 'min', 'review requests', 'first', 0, ''),
        'care_weekly'     => $P('care', 'section_boss', 'Weekly: top 5 complaint reasons written down', 'Fix the cause, not the complaint.', 'weekly', '1', null, '12:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'sales_follow'    => $P('sales', 'worker', 'Follow up open enquiries (enter how many)', 'Most sales come from the second call.', 'daily', '', null, '19:00', 30, 'count', '', '', 20, 'min', 'follow-ups', 'all', 0, ''),
        'sales_target'    => $P('sales', 'worker', 'Check today\'s target against what is done', 'Know the gap before the day ends.', 'daily', '', null, '16:00', 30, 'tick', '', '', null, 'min', '', 'all', 0, ''),
        'sales_cod'       => $P('sales', 'worker', 'Confirm COD orders by call (enter how many)', 'Fewer returns.', 'daily', '', null, '15:00', 30, 'count', '', '', null, 'any', 'COD calls', 'first', 0, ''),
        'sales_week'      => $P('sales', 'section_boss', 'Weekly sales review with the team', 'Keep targets honest.', 'weekly', '1', null, '11:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'disp_pack'       => $P('dispatch', 'worker', 'Pack and hand over all orders (enter how many are left unpacked)', 'Every order leaves the same day.', 'daily', '', null, '14:00', 30, 'count', '', '', 0, 'max', 'orders unpacked', 'first', 100, 'Orders were left unpacked.'),
        'disp_ndr'        => $P('dispatch', 'worker', 'Follow up failed-delivery (NDR) parcels (enter how many)', 'A failed delivery turns into a return if nobody calls.', 'daily', '', null, '16:00', 30, 'count', '', '', null, 'any', 'NDR parcels', 'first', 0, ''),
        'disp_rto'        => $P('dispatch', 'worker', 'Receive returned (RTO) parcels and add them to stock', 'Returned stock must come back to the shelf.', 'weekly', '1', null, '12:00', 60, 'count', '', '', null, 'any', 'RTO parcels', 'first', 0, ''),
        'disp_clean'      => $P('dispatch', 'worker', 'Packing area tidy - photo', 'A tidy packing area makes fewer mistakes.', 'daily', '', null, '19:00', 30, 'photo', '', 'The packing table and floor: tidy, no loose tape, boxes stacked, no waste lying around.', null, 'min', '', 'first', 0, ''),
        'hr_attend'       => $P('hr', 'worker', 'Review today\'s attendance: missing punches and late marks', 'Fix attendance the same day.', 'daily', '', null, '11:30', 30, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'hr_leave'        => $P('hr', 'worker', 'Clear pending leave requests (enter how many are still pending)', 'Staff should not wait for an answer.', 'daily', '', null, '12:00', 60, 'count', '', '', 0, 'max', 'pending leaves', 'first', 0, ''),
        'hr_pen'          => $P('hr', 'section_boss', 'Decide the penalties waiting in the Boss Inbox (enter how many are waiting)', 'A penalty decided late loses its meaning.', 'daily', '', null, '18:00', 60, 'count', '', '', 0, 'max', 'penalties waiting', 'first', 0, ''),
        'hr_pay'          => $P('hr', 'worker', 'Prepare salary: attendance, penalties and advances checked', 'Salary on time, without mistakes.', 'monthly', '', 25, '12:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'acc_cash'        => $P('accounts', 'worker', 'Enter yesterday\'s cash and bank entries', 'The books are never more than one day behind.', 'daily', '', null, '12:00', 60, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'acc_petty'       => $P('accounts', 'worker', 'Petty cash count matches the book (enter the difference in Rs)', 'Small leaks add up.', 'weekly', '6', null, '18:00', 60, 'count', '', '', 0, 'max', 'Rs difference', 'first', 0, ''),
        'acc_gst'         => $P('accounts', 'worker', 'GST data ready for filing', 'No late fee.', 'monthly', '', 8, '17:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'acc_dues'        => $P('accounts', 'worker', 'Follow up pending shop dues (enter how many you called)', 'Money in the market comes home.', 'weekly', '1', null, '17:00', 60, 'count', '', '', null, 'any', 'dues called', 'first', 0, ''),
        'mkt_flyer'       => $P('marketing', 'worker', 'Flyers: assign today\'s area and check yesterday\'s photos', 'Flyers only work when someone is sent to a new area.', 'daily', '', null, '10:30', 60, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'mkt_post'        => $P('marketing', 'worker', 'Post today\'s story or reel (enter how many posts)', 'Stay visible every day.', 'daily', '', null, '19:00', 60, 'count', '', '', 1, 'min', 'posts', 'first', 0, ''),
        'mkt_ads'         => $P('marketing', 'worker', 'Review ad spend against sales', 'Stop money going to ads that do not sell.', 'weekly', '1', null, '15:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
        'mkt_season'      => $P('marketing', 'worker', 'Plan next month\'s festival poster and offer', 'Posters must be ready before the festival.', 'monthly', '', 25, '17:00', 120, 'tick', '', '', null, 'min', '', 'first', 0, ''),
    ];
}
function bd_install_library(PDO $pdo, array $sectionCodes, $by) {
    $lib = bd_library(); $made = 0; $now = bd_now();
    $secs = []; foreach ($pdo->query("SELECT id, code FROM bd_sections")->fetchAll() as $s) $secs[$s['code']] = (int)$s['id'];
    foreach ($lib as $key => $d) {
        if ($sectionCodes && !in_array($d['section'], $sectionCodes, true)) continue;
        $sid = $secs[$d['section']] ?? 0; if (!$sid) continue;
        $st = $pdo->prepare("SELECT id FROM bd_duties WHERE lib_key = ? AND section_id = ?"); $st->execute([$key, $sid]);
        if ($st->fetchColumn()) continue;
        $seat = bd_section_seat($pdo, $sid, $d['seat']);
        $pdo->prepare("INSERT INTO bd_duties (lib_key, section_id, seat_id, title, why, freq, dow, dom, start_time, due_time, grace_min, proof, adapter, photo_prompt, target_qty, cmp, unit, assign_mode, pen_amount, pen_mode, pen_remark, status, source, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,'approve',?, 'proposed','library',?,?)")
            ->execute([$key, $sid, $seat ?: null, $d['title'], $d['why'], $d['freq'], $d['dow'] !== '' ? $d['dow'] : null, $d['dom'], $d['due'], $d['grace'], $d['proof'], $d['adapter'] !== '' ? $d['adapter'] : null, $d['prompt'] !== '' ? $d['prompt'] : null, $d['target'], $d['cmp'], $d['unit'] !== '' ? $d['unit'] : null, $d['assign'], $d['pen'], $d['remark'] !== '' ? $d['remark'] : null, $by, $now]);
        $made++;
    }
    return $made;
}

/* ============================== festivals ============================== */
function bd_seed_seasons(PDO $pdo) {
    // Dates to check once a year: Diwali and Holi move every year. Edit them on the Setup page.
    $rows = [['diwali', 'Diwali', '2026-11-08', 21], ['christmas', 'Christmas', '2026-12-25', 14], ['newyear', 'New Year', '2027-01-01', 10], ['valentine', "Valentine's Day", '2027-02-14', 14], ['holi', 'Holi', '2027-03-04', 12]];
    foreach ($rows as $r) $pdo->prepare("INSERT IGNORE INTO bd_seasons (code, name, event_date, lead_days) VALUES (?,?,?,?)")->execute($r);
}
/** the one-time duties a festival needs. offset = days before the event (negative = after). */
function bd_season_plan($code, $name) {
    $plans = [
        'diwali' => [
            ['store', 'Put up the Diwali poster and gift-set display - photo', 14, 'photo', 'The shop window / display with a Diwali poster and a gift-set or combo display. Clean and neatly arranged.'],
            ['store', 'Check gift-set and combo stock for Diwali week', 18, 'tick', ''],
            ['marketing', 'Diwali offer post is live on Instagram and WhatsApp', 14, 'tick', ''],
            ['store', 'Take down the Diwali poster and put up the Christmas poster - photo', -9, 'photo', 'The shop window / poster area now showing a Christmas poster (no Diwali poster left).'],
        ],
        'christmas' => [
            ['store', 'Christmas display and poster up - photo', 12, 'photo', 'The shop window / display with a Christmas poster or gift display, neat and clean.'],
            ['marketing', 'Christmas offer post is live', 12, 'tick', ''],
            ['store', 'Take down Christmas poster, put up the New Year poster - photo', -3, 'photo', 'The shop window / poster area showing a New Year poster (no Christmas poster left).'],
        ],
        'newyear' => [['marketing', 'New Year offer post is live', 5, 'tick', ''], ['store', 'Take down the New Year poster and put up the regular offer poster - photo', -7, 'photo', 'The shop window poster area with a clean, current regular offer poster.']],
        'valentine' => [['store', "Valentine's gift display and poster - photo", 10, 'photo', "The shop display with a Valentine's poster and a gift-set display, neat and clean."], ['marketing', "Valentine's offer post is live", 10, 'tick', ''], ['store', "Take down the Valentine's poster - photo", -2, 'photo', 'The shop poster area with a clean, current regular poster.']],
        'holi' => [['store', 'Holi poster and offer display - photo', 7, 'photo', 'The shop window / display with a Holi poster, neat and clean.'], ['marketing', 'Holi offer post is live', 7, 'tick', ''], ['store', 'Take down the Holi poster - photo', -2, 'photo', 'The shop poster area with a clean, current regular poster.']],
    ];
    return $plans[$code] ?? [['marketing', $name . ' offer post is live', 7, 'tick', ''], ['store', $name . ' poster / display up - photo', 7, 'photo', 'The shop window / display with a ' . $name . ' poster, neat and clean.']];
}

/* ============================== daily tasks ============================== */
function bd_generate(PDO $pdo, $date) {
    $made = 0;
    $duties = $pdo->query("SELECT * FROM bd_duties WHERE status = 'live' ORDER BY id")->fetchAll();
    foreach ($duties as $d) {
        if (!bd_is_due($d, $date)) continue;
        $due = $date . ' ' . (preg_match('/^\d{2}:\d{2}$/', (string)$d['due_time']) ? $d['due_time'] : '18:00') . ':00';
        $start = preg_match('/^\d{2}:\d{2}$/', (string)$d['start_time']) ? $date . ' ' . $d['start_time'] . ':00' : null;
        foreach (bd_resolve($pdo, $d, $date) as $r) {
            list($slot, $owner, $orig, $why) = $r;
            $tooLate = ($date === bd_today()) && (time() > strtotime($due) + 60 * (int)$d['grace_min']);   // a duty switched on after its time is not counted as missed
            if ($tooLate) { $why = 'switched on after its time'; }
            $st = $pdo->prepare("INSERT IGNORE INTO bd_tasks (duty_id, section_id, task_date, slot, owner_emp, orig_emp, cover_why, start_at, due_at, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([(int)$d['id'], (int)$d['section_id'], $date, (int)$slot, $owner ?: null, $orig ?: null, $why !== '' ? mb_substr($why, 0, 120) : null, $start, $due, $tooLate ? 'excused' : ($owner ? 'open' : 'unowned'), bd_now()]);
            if ($st->rowCount() > 0 && !$tooLate) {
                $made++;
                $tid = (int)$pdo->lastInsertId();
                if ($owner) bd_note_planned($owner, $date);
                else bd_inbox('uncovered', 'Nobody can do: ' . $d['title'], 'Today nobody in the seat is free and nobody above them is free either. Please give this to someone.', ['task_id' => $tid, 'duty_id' => (int)$d['id']], (int)$d['section_id'], null, 'unc:' . $tid);
                if ($why !== '') bd_log('cover', $tid, $why);
            }
        }
    }
    return $made;
}
/** re-check today's open tasks: if the owner became away (leave, not in), the work moves to the next person. */
function bd_reassign(PDO $pdo, $date) {
    $moved = 0;
    $rows = $pdo->prepare("SELECT t.*, d.seat_id, d.assign_mode, d.title, d.id AS did, d.section_id AS dsec FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.status IN ('open','unowned') AND t.proof_path IS NULL");
    $rows->execute([$date]);
    foreach ($rows->fetchAll() as $t) {
        $owner = (int)$t['owner_emp'];
        if ($owner && bd_away($pdo, $owner, $date) === '') continue;
        $duty = $pdo->prepare("SELECT * FROM bd_duties WHERE id = ?"); $duty->execute([(int)$t['duty_id']]); $duty = $duty->fetch(); if (!$duty) continue;
        $hit = null;
        foreach (bd_resolve($pdo, $duty, $date) as $r) { if ((int)$r[0] === (int)$t['slot']) { $hit = $r; break; } }
        if (!$hit) continue;
        $new = (int)$hit[1];
        if ($new === 0) {
            if ($owner) {   // everyone who could take it is away: it becomes the boss's problem, loudly
                $pdo->prepare("UPDATE bd_tasks SET owner_emp = NULL, orig_emp = COALESCE(orig_emp, ?), status = 'unowned', cover_why = ? WHERE id = ?")->execute([$owner, mb_substr(bd_emp_name($pdo, $owner) . ' is away and nobody is free', 0, 120), (int)$t['id']]);
                bd_inbox('uncovered', 'Nobody can do: ' . $t['title'], bd_emp_name($pdo, $owner) . ' is away and nobody above or beside them is free. Please give this to someone.', ['task_id' => (int)$t['id'], 'duty_id' => (int)$t['duty_id']], (int)$t['section_id'], null, 'unc:' . $t['id']);
                $moved++;
            }
            continue;
        }
        if ($new === $owner) continue;
        $orig = $t['orig_emp'] ?: ($owner ?: null);
        $pdo->prepare("UPDATE bd_tasks SET owner_emp = ?, orig_emp = ?, cover_why = ?, status = 'open', reminded = 0 WHERE id = ?")->execute([$new, $orig, mb_substr($hit[3] !== '' ? $hit[3] : bd_emp_name($pdo, $owner) . ' is away', 0, 120), (int)$t['id']]);
        bd_log('cover', $t['id'], bd_emp_name($pdo, $owner) . ' -> ' . bd_emp_name($pdo, $new) . ': ' . $t['title']);
        $moved++;
        bd_push($pdo, $new, 'You are covering a duty', $t['title'] . ' (' . ($hit[3] !== '' ? $hit[3] : 'a colleague is away') . ')');
    }
    return $moved;
}

/* ============================== phone notices ============================== */
function bd_push(PDO $pdo, $empId, $title, $body) {
    if (bd_mode() === 'shadow') return;                       // shadow mode never disturbs anyone
    if (!function_exists('push_send_employee') || !$empId) return;
    try { push_send_employee($pdo, (int)$empId, ['title' => $title, 'body' => mb_substr($body, 0, 160), 'url' => 'my_day.html', 'tag' => 'hrms-bd']); } catch (Throwable $e) {}
}
function bd_remind(PDO $pdo, $now) {
    if (bd_mode() === 'shadow') return 0;
    $n = 0; $win = max(5, (int)bd_cfg('remind_min'));
    $st = $pdo->prepare("SELECT t.id, t.owner_emp, t.due_at, t.reminded, d.title FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.status = 'open' AND t.owner_emp IS NOT NULL AND t.reminded < 2");
    $st->execute([date('Y-m-d', $now)]);
    foreach ($st->fetchAll() as $t) {
        $mins = (strtotime($t['due_at']) - $now) / 60;
        if ($mins <= 0 && (int)$t['reminded'] < 2) { bd_push($pdo, $t['owner_emp'], 'Overdue: ' . $t['title'], 'It was due at ' . date('g:i A', strtotime($t['due_at'])) . '. Please do it now.'); $pdo->prepare("UPDATE bd_tasks SET reminded = 2 WHERE id = ?")->execute([$t['id']]); $n++; }
        elseif ($mins <= $win && (int)$t['reminded'] < 1) { bd_push($pdo, $t['owner_emp'], 'Due soon: ' . $t['title'], 'Due at ' . date('g:i A', strtotime($t['due_at'])) . '.'); $pdo->prepare("UPDATE bd_tasks SET reminded = 1 WHERE id = ?")->execute([$t['id']]); $n++; }
    }
    return $n;
}

/* ============================== automatic proof (the HRMS already knows) ============================== */
function bd_auto_check(PDO $pdo, $date) {
    $n = 0;
    $st = $pdo->prepare("SELECT t.id, t.owner_emp, d.adapter, d.target_qty FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.status = 'open' AND d.proof = 'auto' AND t.owner_emp IS NOT NULL");
    $st->execute([$date]);
    foreach ($st->fetchAll() as $t) {
        $val = null;
        try {
            if ($t['adapter'] === 'flyers') {
                $q = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ? AND (flag IS NULL OR flag_ok = 1)");
                $q->execute([(int)$t['owner_emp'], $date . ' 00:00:00']); $val = (int)$q->fetchColumn();
            } elseif ($t['adapter'] === 'punch') {
                $q = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_date = ? AND punch_in IS NOT NULL");
                $q->execute([(int)$t['owner_emp'], $date]); $val = (int)$q->fetchColumn();
            }
        } catch (Throwable $e) { $val = null; }
        if ($val === null) continue;
        $pdo->prepare("UPDATE bd_tasks SET proof_value = ? WHERE id = ?")->execute([$val, $t['id']]);
        if ($val >= max(1, (int)$t['target_qty'])) { $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = ?, proof_note = 'checked by the system' WHERE id = ?")->execute([bd_now(), $t['id']]); $n++; }
    }
    return $n;
}

/* ============================== missed duties and penalties ============================== */
function bd_month_total(PDO $pdo, $empId, $from, $to) {
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id = ? AND source = 'boss' AND status = 'applied' AND penalty_date >= ? AND penalty_date <= ?"); $q->execute([(int)$empId, $from, $to]); return (float)$q->fetchColumn(); } catch (Throwable $e) { return 0.0; }
}
/** give the money penalty through the HRMS penalty engine. Returns [ok, message]. */
function bd_apply_penalty(PDO $pdo, $taskId, $by) {
    $q = $pdo->prepare("SELECT t.*, d.title, d.pen_remark FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.id = ?"); $q->execute([(int)$taskId]); $t = $q->fetch();
    if (!$t) return [false, 'Task not found.'];
    if (!in_array($t['pen_state'], ['pending', 'shadow', 'appealed'], true)) return [false, 'This penalty is already ' . $t['pen_state'] . '.'];
    if (!function_exists('penalty_create')) return [false, 'The HRMS penalty engine was not found.'];
    $emp = function_exists('penalty_employee') ? penalty_employee($pdo, (int)$t['owner_emp']) : bd_emp($pdo, $t['owner_emp']);
    if (!$emp) return [false, 'Employee not found.'];
    $amt = (float)$t['pen_amount'];
    $day = date('Y-m-d'); $m1 = date('Y-m-01'); $m2 = date('Y-m-t');
    $left = min((float)bd_cfg('pen_cap_day') - bd_month_total($pdo, $t['owner_emp'], $day, $day), (float)bd_cfg('pen_cap_month') - bd_month_total($pdo, $t['owner_emp'], $m1, $m2));
    if ($left <= 0) { $pdo->prepare("UPDATE bd_tasks SET pen_state = 'capped' WHERE id = ?")->execute([$taskId]); bd_log('penalty', $taskId, 'not applied: cap reached', $by); return [true, 'Not applied: ' . bd_emp_name($pdo, $t['owner_emp']) . ' has reached the Boss Engine penalty limit (change it on the Setup page).']; }
    $amt = min($amt, $left);
    $note = trim(($t['pen_remark'] ?: ('Missed: ' . $t['title'])) . ' (' . $t['task_date'] . ')');
    $pid = penalty_create($pdo, $emp, null, 'Duty missed: ' . $t['title'], $t['task_date'], $amt, 'boss', $note, $by, 'bd:' . $taskId, true);
    $pdo->prepare("UPDATE bd_tasks SET pen_state = 'applied', pen_id = ?, pen_amount = ? WHERE id = ?")->execute([$pid ?: null, $amt, $taskId]);
    bd_log('penalty', $taskId, 'applied ' . bd_inr($amt) . ' to ' . $emp['full_name'], $by);
    return [true, 'Penalty of ' . bd_inr($amt) . ' given to ' . $emp['full_name'] . '.'];
}
function bd_miss(PDO $pdo, $now) {
    $n = 0; $mode = bd_mode();
    $st = $pdo->prepare("SELECT t.*, d.title, d.grace_min, d.pen_amount AS dpen, d.pen_mode, d.pen_remark AS dremark FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.status IN ('open','unowned') AND t.due_at < ? AND t.task_date >= ?");
    $st->execute([date('Y-m-d H:i:s', $now), date('Y-m-d', $now - 3 * 86400)]);
    foreach ($st->fetchAll() as $t) {
        if (strtotime($t['due_at']) + 60 * (int)$t['grace_min'] > $now) continue;
        $pdo->prepare("UPDATE bd_tasks SET status = 'missed' WHERE id = ? AND status IN ('open','unowned')")->execute([$t['id']]);
        $n++; bd_log('missed', $t['id'], $t['title'] . ' - ' . bd_emp_name($pdo, $t['owner_emp']));
        $amt = (float)$t['dpen'];
        if ($amt > 0 && $t['owner_emp']) {
            $rem = $t['dremark'] ?: ('Missed: ' . $t['title']);
            if ($mode === 'live') {
                if ($t['pen_mode'] === 'auto') {
                    $pdo->prepare("UPDATE bd_tasks SET pen_state = 'pending', pen_amount = ?, pen_remark = ? WHERE id = ?")->execute([$amt, mb_substr($rem, 0, 255), $t['id']]);
                    bd_apply_penalty($pdo, $t['id'], 'Boss Engine');
                } else {
                    $pdo->prepare("UPDATE bd_tasks SET pen_state = 'pending', pen_amount = ?, pen_remark = ? WHERE id = ?")->execute([$amt, mb_substr($rem, 0, 255), $t['id']]);
                    bd_inbox('penalty', bd_emp_name($pdo, $t['owner_emp']) . ' missed: ' . $t['title'], 'Penalty ' . bd_inr($amt) . '. ' . $rem, ['task_id' => (int)$t['id']], (int)$t['section_id'], null, 'pen:' . $t['id']);
                }
            } else {
                $pdo->prepare("UPDATE bd_tasks SET pen_state = 'shadow', pen_amount = ?, pen_remark = ? WHERE id = ?")->execute([$amt, mb_substr($rem, 0, 255), $t['id']]);
            }
        }
        if ($t['owner_emp'] && $mode !== 'shadow') bd_push($pdo, $t['owner_emp'], 'Missed: ' . $t['title'], 'This duty was not done in time.' . ($amt > 0 && $mode === 'live' ? ' A penalty may follow.' : ''));
    }
    return $n;
}

/* ============================== photo gate ============================== */
function bd_ahash($path) {
    if (!function_exists('imagecreatefromstring')) return null;
    $data = @file_get_contents($path); if ($data === false) return null;
    $im = @imagecreatefromstring($data); if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im); $t = imagecreatetruecolor(8, 8);
    imagecopyresampled($t, $im, 0, 0, 0, 0, 8, 8, $w, max(1, $h));
    $vals = []; $sum = 0;
    for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) { $c = imagecolorat($t, $x, $y); $g = ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3; $vals[] = $g; $sum += $g; }
    $avg = $sum / 64; $b = ''; foreach ($vals as $v) $b .= ($v >= $avg ? '1' : '0');
    imagedestroy($im); imagedestroy($t); return $b;
}
function bd_hamming($a, $b) { if (strlen($a) !== strlen($b)) return 64; $d = 0; for ($i = 0, $n = strlen($a); $i < $n; $i++) if ($a[$i] !== $b[$i]) $d++; return $d; }
/**
 * FINE fingerprint of a picture: a 33x32 brightness grid, 1024 left-to-right steps, returned as 256 hex characters.
 * The 8x8 bd_ahash above only sees the big shapes, so two different phone screenshots of the SAME app screen (same reel, same payment page,
 * same dashboard) come out identical. Use bd_fhash + bd_fdist when a screenshot is compared with other screenshots.
 * Same picture saved again / sent through WhatsApp: about 0-10 of 1024 steps differ. A different reel or page: 100+.
 */
function bd_fhash($path) {
    if (!function_exists('imagecreatefromstring')) return null;
    $data = @file_get_contents($path); if ($data === false) return null;
    $im = @imagecreatefromstring($data); if (!$im) return null;
    $w = imagesx($im); $h = max(1, imagesy($im)); $W = 33; $H = 32;
    $m = imagecreatetruecolor($W * 8, $H * 8); imagecopyresampled($m, $im, 0, 0, 0, 0, $W * 8, $H * 8, $w, $h);
    $t = imagecreatetruecolor($W, $H); imagecopyresampled($t, $m, 0, 0, 0, 0, $W, $H, $W * 8, $H * 8);
    $bits = '';
    for ($y = 0; $y < $H; $y++) {
        $prev = null;
        for ($x = 0; $x < $W; $x++) {
            $c = imagecolorat($t, $x, $y); $g = (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
            if ($prev !== null) $bits .= ($prev > $g + 3) ? '1' : '0';
            $prev = $g;
        }
    }
    imagedestroy($im); imagedestroy($m); imagedestroy($t);
    $hex = ''; for ($i = 0; $i < 1024; $i += 4) $hex .= dechex(bindec(substr($bits, $i, 4)));
    return $hex;
}
/** how many of the 1024 steps differ between two bd_fhash values (1024 = not comparable) */
function bd_fdist($a, $b) {
    if (!is_string($a) || !is_string($b) || strlen($a) !== 256 || strlen($b) !== 256) return 1024;
    $x = hex2bin($a) ^ hex2bin($b); if ($x === false) return 1024;
    $d = 0; foreach (count_chars($x, 1) as $byte => $n) $d += $n * substr_count(decbin($byte), '1');
    return $d;
}
function bd_b64_small($path) {
    $data = @file_get_contents($path); if ($data === false) return null;
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($data);
        if ($im) {
            $w = imagesx($im); $h = imagesy($im); $m = max($w, $h);
            if ($m > 1024) { $s = 1024 / $m; $t = imagecreatetruecolor((int)($w * $s), (int)($h * $s)); imagecopyresampled($t, $im, 0, 0, 0, 0, (int)($w * $s), (int)($h * $s), $w, $h); imagedestroy($im); $im = $t; }
            ob_start(); imagejpeg($im, null, 82); $data = ob_get_clean(); imagedestroy($im);
        }
    }
    return base64_encode($data);
}
/** [state, reason]: state = pass | fail | skip (AI not available - a person decides). */
function bd_photo_judge(PDO $pdo, array $duty, $absPath) {
    if (bd_cfg_fresh('ai_gate') !== '1') return ['skip', 'AI check is switched off.'];
    if (!function_exists('convo_ai')) return ['skip', 'The AI helper is not available.'];
    $img = bd_b64_small($absPath); if (!$img) return ['skip', 'Could not read the photo.'];
    $parts = [['text' => "DUTY: " . $duty['title'] . "\nWHAT THE PHOTO MUST SHOW: " . ($duty['photo_prompt'] ?: $duty['title']) . "\n\nThe employee's photo:"], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]];
    if (!empty($duty['ref_photo']) && is_file(bd_dir() . basename($duty['ref_photo']))) {
        $ref = bd_b64_small(bd_dir() . basename($duty['ref_photo']));
        if ($ref) { $parts[] = ['text' => "\nThis is the EXAMPLE of how it should look when done right:"]; $parts[] = ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $ref]]; }
    }
    $sys = "You are the strict but fair supervisor of a perfume shop. An employee sends ONE photo as proof that a duty is done. "
        . "Say ok=true only if the photo clearly shows the right place AND the work looks properly done (clean, tidy, in order, as described). "
        . "Say ok=false if the photo is of the wrong place, a screen or a screenshot, too dark or blurry to judge, a close-up that hides the area, or the place looks dirty, untidy or unfinished. "
        . "Ignore normal lighting differences. In 'reason' write ONE short plain-English sentence telling the employee exactly what to fix or retake. In 'issues' list short problems (can be empty).";
    $schema = ['type' => 'OBJECT', 'properties' => ['ok' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING'], 'issues' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['ok', 'reason']];
    try { $r = convo_ai($sys, $parts, $schema, false, 600); } catch (Throwable $e) { return ['skip', 'The AI check failed.']; }
    if (empty($r[0]) || !is_array($r[1]) || !array_key_exists('ok', $r[1])) return ['skip', 'The AI could not check this photo.'];
    $v = $r[1];
    return [!empty($v['ok']) ? 'pass' : 'fail', mb_substr(trim((string)($v['reason'] ?? '')), 0, 240)];
}

/* ============================== interview bot ============================== */
function bd_questions($name) {
    return [
        ['about', 'In one line: what does ' . $name . ' do for the business?', 'Example: sells perfumes to walk-in customers and handles the shop.'],
        ['daily', 'What must be done EVERY day? One task per line, with the time if it has one.', 'Example: Clean the counter by 11am (photo)'],
        ['weekly', 'What must be done every WEEK? Write the day too.', 'Example: Stock count every Saturday 6pm'],
        ['monthly', 'What must be done once a MONTH? Write the date.', 'Example: Change the window poster on the 1st'],
        ['photo', 'Which tasks need a photo as proof? What should the photo show?', 'Example: Pantry - clean platform and sink, no waste'],
        ['count', 'Which tasks have a number to reach?', 'Example: 40 flyers a day, 20 follow-up calls'],
        ['checker', 'Who checks this work, and by when?', 'Example: Store manager, same day by 9pm'],
        ['worst', 'What are the worst mistakes here, and what should each one cost?', 'Example: Cash not matched - Rs 200; counter dirty - Rs 100'],
        ['season', 'Does anything change with festivals or seasons?', 'Example: Diwali and Christmas posters'],
        ['hours', 'When does this team start and finish work?', 'Example: 10am to 10pm'],
    ];
}
function bd_parse_time($s, $default) {
    if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $s, $m)) { $h = (int)$m[1] % 12; if (strtolower($m[3]) === 'pm') $h += 12; return sprintf('%02d:%02d', $h, (int)($m[2] ?? 0)); }
    if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $s, $m)) return sprintf('%02d:%s', (int)$m[1], $m[2]);
    return $default;
}
function bd_parse_dow($s) {
    $map = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $out = []; foreach ($map as $k => $v) if (preg_match('/\b' . $k . '[a-z]*/i', $s)) $out[] = $v;
    return implode(',', $out);
}
/** no-AI draft: reads the lines the boss typed. */
function bd_rule_draft(array $ans) {
    $duties = [];
    $lines = function ($k) use ($ans) { return array_values(array_filter(array_map('trim', preg_split('/\r?\n|;/', (string)($ans[$k] ?? ''))), function ($x) { return strlen($x) > 3; })); };
    $add = function ($line, $freq) use (&$duties, $ans) {
        $clean = trim(preg_replace('/\s+/', ' ', preg_replace('/^[\-\*\d\.\)\s]+/', '', $line)));
        $photo = (bool)preg_match('/photo|picture|pic\b/i', $line) || (bool)preg_match('/clean|mop|sweep|tidy|display|shelf|pantry|poster/i', $line) && preg_match('/photo/i', (string)($ans['photo'] ?? '') . $line);
        $num = null; $unit = null;
        if (preg_match('/\b(\d{1,4})\s+([a-z][a-z \-]{2,24})/i', $line, $m) && !preg_match('/am|pm/i', $m[2])) { $num = (int)$m[1]; $unit = trim($m[2]); }
        $d = ['title' => mb_substr($clean, 0, 150), 'why' => '', 'freq' => $freq, 'dow' => $freq === 'weekly' ? (bd_parse_dow($line) ?: '6') : '', 'dom' => null,
            'due_time' => bd_parse_time($line, $freq === 'daily' ? '18:00' : '17:00'), 'proof' => $photo ? 'photo' : ($num ? 'count' : 'tick'), 'photo_prompt' => $photo ? $clean : '',
            'target_qty' => $num, 'cmp' => 'min', 'unit' => $unit, 'pen_amount' => 0, 'pen_remark' => ''];
        if ($freq === 'monthly') { $d['dom'] = preg_match('/\b(\d{1,2})(st|nd|rd|th)?\b/', $line, $m) ? max(1, min(28, (int)$m[1])) : 1; }
        $duties[] = $d;
    };
    foreach ($lines('daily') as $l) $add($l, 'daily');
    foreach ($lines('weekly') as $l) $add($l, 'weekly');
    foreach ($lines('monthly') as $l) $add($l, 'monthly');
    foreach ($lines('worst') as $l) {
        if (!preg_match('/(?:rs\.?|₹|inr)?\s*(\d{2,5})/i', $l, $m)) continue;
        $best = -1; $bs = 0.12; foreach ($duties as $i => $d) { $s = bd_sim($l, $d['title']); if ($s > $bs) { $bs = $s; $best = $i; } }
        if ($best >= 0) { $duties[$best]['pen_amount'] = (int)$m[1]; $duties[$best]['pen_remark'] = mb_substr(trim($l), 0, 200); }
    }
    return ['duties' => $duties, 'follow_ups' => [], 'summary' => count($duties) . ' duties read from your answers (no AI used).'];
}
function bd_ai_draft(PDO $pdo, $sectionName, array $ans) {
    if (!function_exists('convo_ai')) return null;
    $text = "SECTION: $sectionName\n";
    foreach (bd_questions($sectionName) as $q) $text .= "\nQ: " . $q[1] . "\nA: " . trim((string)($ans[$q[0]] ?? '')) . "\n";
    $sys = "You help the owner of a small perfume business (shops, online orders, a small team in India) turn a manager's answers into a clean duty list for ONE section. "
        . "Write short duties in plain English that a shop assistant understands. Use proof 'photo' only for physical tidiness/cleanliness/display work, 'count' when there is a number to reach or report, otherwise 'tick'. "
        . "For count duties set cmp: 'min' = must reach the number, 'max' = the number must be 0 or small (like pending items), 'any' = just report it. "
        . "freq is daily, weekly or monthly. For weekly give dow as ISO weekday numbers (1=Mon..7=Sun, comma separated). For monthly give dom (1-28). due_time is 24h HH:MM. "
        . "pen_amount is a rupee amount ONLY if the manager said a mistake should cost money; never invent penalties. Do not invent duties the manager did not mention, but split combined sentences into separate duties. "
        . "If something important is unclear (who checks, a time, a number), add up to 3 short follow_ups questions.";
    $duty = ['type' => 'OBJECT', 'properties' => [
        'title' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'], 'freq' => ['type' => 'STRING', 'enum' => ['daily', 'weekly', 'monthly']], 'dow' => ['type' => 'STRING'], 'dom' => ['type' => 'INTEGER'],
        'due_time' => ['type' => 'STRING'], 'proof' => ['type' => 'STRING', 'enum' => ['tick', 'photo', 'count']], 'photo_prompt' => ['type' => 'STRING'], 'target_qty' => ['type' => 'INTEGER'],
        'cmp' => ['type' => 'STRING', 'enum' => ['min', 'max', 'any']], 'unit' => ['type' => 'STRING'], 'pen_amount' => ['type' => 'NUMBER'], 'pen_remark' => ['type' => 'STRING']], 'required' => ['title', 'freq', 'due_time', 'proof']];
    $schema = ['type' => 'OBJECT', 'properties' => ['duties' => ['type' => 'ARRAY', 'items' => $duty], 'follow_ups' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'summary' => ['type' => 'STRING']], 'required' => ['duties']];
    try { $r = convo_ai($sys, [['text' => $text]], $schema, false, 6000, 'claude'); } catch (Throwable $e) { return null; }
    if (empty($r[0]) || !is_array($r[1]) || empty($r[1]['duties'])) return null;
    return $r[1];
}
function bd_clean_duty(array $d) {
    $freq = in_array($d['freq'] ?? '', ['daily', 'weekly', 'monthly', 'once', 'season'], true) ? $d['freq'] : 'daily';
    $dow = preg_replace('/[^1-7,]/', '', (string)($d['dow'] ?? ''));
    $proof = in_array($d['proof'] ?? '', ['tick', 'photo', 'count', 'auto'], true) ? $d['proof'] : 'tick';
    $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($d['due_time'] ?? '')) ? $d['due_time'] : '18:00';
    $cmp = in_array($d['cmp'] ?? '', ['min', 'max', 'any'], true) ? $d['cmp'] : 'min';
    return ['title' => mb_substr(trim((string)($d['title'] ?? 'Untitled')), 0, 160), 'why' => mb_substr(trim((string)($d['why'] ?? '')), 0, 250), 'freq' => $freq,
        'dow' => $dow, 'dom' => ($freq === 'monthly') ? max(1, min(28, (int)($d['dom'] ?? 1))) : null, 'due_time' => $time, 'proof' => $proof,
        'photo_prompt' => mb_substr(trim((string)($d['photo_prompt'] ?? '')), 0, 480), 'target_qty' => isset($d['target_qty']) && $d['target_qty'] !== '' ? max(0, (int)$d['target_qty']) : null,
        'cmp' => $cmp, 'unit' => mb_substr(trim((string)($d['unit'] ?? '')), 0, 30), 'pen_amount' => max(0, min(5000, (float)($d['pen_amount'] ?? 0))), 'pen_remark' => mb_substr(trim((string)($d['pen_remark'] ?? '')), 0, 250)];
}
function bd_save_duty(PDO $pdo, $sectionId, $seatId, array $c, $source, $status, $by, $id = 0) {
    $now = bd_now();
    if ($id) {
        $pdo->prepare("UPDATE bd_duties SET section_id=?, seat_id=?, title=?, why=?, freq=?, dow=?, dom=?, due_time=?, proof=?, photo_prompt=?, target_qty=?, cmp=?, unit=?, pen_amount=?, pen_remark=?, version=version+1, updated_at=? WHERE id=?")
            ->execute([$sectionId, $seatId ?: null, $c['title'], $c['why'], $c['freq'], $c['dow'] !== '' ? $c['dow'] : null, $c['dom'], $c['due_time'], $c['proof'], $c['photo_prompt'] !== '' ? $c['photo_prompt'] : null, $c['target_qty'], $c['cmp'], $c['unit'] !== '' ? $c['unit'] : null, $c['pen_amount'], $c['pen_remark'] !== '' ? $c['pen_remark'] : null, $now, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO bd_duties (section_id, seat_id, title, why, freq, dow, dom, due_time, proof, photo_prompt, target_qty, cmp, unit, pen_amount, pen_mode, pen_remark, status, source, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'approve', ?,?,?,?,?)")
        ->execute([$sectionId, $seatId ?: null, $c['title'], $c['why'], $c['freq'], $c['dow'] !== '' ? $c['dow'] : null, $c['dom'], $c['due_time'], $c['proof'], $c['photo_prompt'] !== '' ? $c['photo_prompt'] : null, $c['target_qty'], $c['cmp'], $c['unit'] !== '' ? $c['unit'] : null, $c['pen_amount'], $c['pen_remark'] !== '' ? $c['pen_remark'] : null, $status, $source, $by, $now]);
    return (int)$pdo->lastInsertId();
}

/* ============================== collisions ============================== */
function bd_collisions(PDO $pdo) {
    $out = [];
    $duties = $pdo->query("SELECT d.*, s.name AS sec_name FROM bd_duties d JOIN bd_sections s ON s.id = d.section_id WHERE d.status IN ('live','proposed')")->fetchAll();
    $n = count($duties);
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) {
        $a = $duties[$i]; $b = $duties[$j];
        $sim = bd_sim($a['title'], $b['title']);
        $same = (int)$a['section_id'] === (int)$b['section_id'];
        if ($sim >= ($same ? 0.6 : 0.7) && $a['freq'] === $b['freq']) {
            $out[] = ['kind' => 'duplicate', 'level' => $same ? 'warn' : 'bad', 'key' => 'dup:' . min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']),
                'title' => $same ? 'Two duties look the same' : 'Two sections own the same work',
                'detail' => '"' . $a['title'] . '" (' . $a['sec_name'] . ') and "' . $b['title'] . '" (' . $b['sec_name'] . ') look like the same work. Keep one.', 'duties' => [(int)$a['id'], (int)$b['id']]];
        }
    }
    $load = [];
    foreach ($duties as $d) {
        $seat = (int)$d['seat_id'] ?: bd_section_seat($pdo, (int)$d['section_id'], 'worker');
        if (!$seat) { $out[] = ['kind' => 'orphan', 'level' => 'bad', 'key' => 'orph:' . $d['id'], 'title' => 'No seat for a duty', 'detail' => '"' . $d['title'] . '" has no seat. Nobody will be given it.', 'duties' => [(int)$d['id']]]; continue; }
        $h = bd_holders($pdo, $seat);
        if (!$h) { if ($d['status'] === 'live') $out[] = ['kind' => 'orphan', 'level' => 'bad', 'key' => 'orph:' . $d['id'], 'title' => 'Seat is empty', 'detail' => '"' . $d['title'] . '" belongs to a seat nobody holds. It will go to the boss every day until you fill the seat.', 'duties' => [(int)$d['id']]]; continue; }
        if ($d['freq'] === 'daily' && $d['assign_mode'] !== 'rotate') { $load[$h[0]] = ($load[$h[0]] ?? 0) + 1; }
        if ($d['assign_mode'] === 'all') foreach ($h as $x) $load[$x] = ($load[$x] ?? 0) + 1;
    }
    $cap = (int)bd_cfg('task_cap');
    foreach ($load as $emp => $c) if ($c > $cap) $out[] = ['kind' => 'overload', 'level' => 'warn', 'key' => 'load:' . $emp . ':' . date('Y-m'), 'title' => bd_emp_name($pdo, $emp) . ' has too many daily duties', 'detail' => $c . ' daily duties are set on ' . bd_emp_name($pdo, $emp) . ' (limit ' . $cap . '). Share some with a backup.', 'duties' => []];
    // time clash: same seat, 3+ photo duties inside 15 minutes
    $by = [];
    foreach ($duties as $d) if ($d['proof'] === 'photo' && $d['freq'] === 'daily') { $by[(int)($d['seat_id'] ?: 0) . ':' . (int)$d['section_id']][] = $d; }
    foreach ($by as $k => $list) {
        usort($list, function ($x, $y) { return strcmp($x['due_time'], $y['due_time']); });
        for ($i = 0; $i + 2 < count($list); $i++) {
            $t0 = strtotime('2000-01-01 ' . $list[$i]['due_time']); $t2 = strtotime('2000-01-01 ' . $list[$i + 2]['due_time']);
            if ($t2 - $t0 <= 900) { $out[] = ['kind' => 'timeclash', 'level' => 'note', 'key' => 'tc:' . $k . ':' . $list[$i]['id'], 'title' => 'Three photo duties due together', 'detail' => 'Three photo duties are due within 15 minutes of each other (' . $list[$i]['due_time'] . '). The person needs time to do all three.', 'duties' => [(int)$list[$i]['id']]]; break; }
        }
    }
    // does the HRMS already fine for this?
    try {
        $rules = $pdo->query("SELECT name FROM penalty_rules")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($duties as $d) if ((float)$d['pen_amount'] > 0) foreach ($rules as $rn) if (bd_sim($d['title'], $rn) >= 0.34) { $out[] = ['kind' => 'penalty_overlap', 'level' => 'warn', 'key' => 'po:' . $d['id'], 'title' => 'The HRMS already has a similar penalty', 'detail' => '"' . $d['title'] . '" has a penalty here, and the Penalties page already has "' . $rn . '". The person could be fined twice.', 'duties' => [(int)$d['id']]]; break; }
    } catch (Throwable $e) {}
    // chain of command
    if (!(int)bd_cfg_fresh('top_boss')) $out[] = ['kind' => 'chain', 'level' => 'bad', 'key' => 'chain:top', 'title' => 'No top boss chosen', 'detail' => 'Choose the top boss on the Setup page. Work nobody can do goes to this person.', 'duties' => []];
    foreach ($pdo->query("SELECT s.id, s.name FROM bd_sections s WHERE s.active = 1 AND EXISTS (SELECT 1 FROM bd_duties d WHERE d.section_id = s.id AND d.status = 'live')")->fetchAll() as $s) {
        $sb = bd_section_seat($pdo, (int)$s['id'], 'section_boss');
        if (!$sb || !bd_holders($pdo, $sb)) $out[] = ['kind' => 'chain', 'level' => 'warn', 'key' => 'chain:' . $s['id'], 'title' => $s['name'] . ' has no boss', 'detail' => 'Penalties and problems in ' . $s['name'] . ' go to you until you name a section boss.', 'duties' => []];
    }
    return $out;
}

/* ============================== scanner: finds new or changed sections ============================== */
function bd_page_section(PDO $pdo, $file) {
    foreach ($pdo->query("SELECT id, code, name, pages FROM bd_sections WHERE active = 1")->fetchAll() as $s) {
        foreach (bd_unjson($s['pages']) as $pat) if (@fnmatch($pat, $file)) return $s;
    }
    return null;
}
function bd_scan(PDO $pdo, $by = 'Boss Engine') {
    $res = ['new_sections' => 0, 'changed' => 0, 'joiners' => 0, 'collisions' => 0, 'seasons' => 0, 'baseline' => false];
    $res['new_sections'] = count(bd_map_departments($pdo, true));
    // files
    $dirs = [];
    $pd = bd_cfg_fresh('pages_dir'); if ($pd && is_dir($pd)) $dirs[] = [$pd, 'page', '*.html']; 
    $dirs[] = [__DIR__, 'module', '*.php'];
    $res['baseline'] = false;
    $seen = [];
    foreach ($dirs as $dd) {
        $first = (int)$pdo->query("SELECT COUNT(*) FROM bd_manifest WHERE kind = " . $pdo->quote($dd[1]))->fetchColumn() === 0;   // the first time we look at pages (or modules) we only take a baseline
        if ($first) $res['baseline'] = true;
        foreach ((array)@glob(rtrim($dd[0], '/') . '/' . $dd[2]) as $f) {
            $base = basename($f);
            if (preg_match('/^(boss_|bd_|boss\.php|api\.php|config|db\.|vendor|my_day\.html|index\.html|login)/i', $base) || preg_match('/(_patch|_install|_installer|dbtest|\.pre|test|backup)/i', $base)) continue;
            $key = $dd[1] . ':' . $base; if (isset($seen[$key])) continue; $seen[$key] = 1;
            $sig = substr(md5(filesize($f) . ':' . filemtime($f)), 0, 16);
            $st = $pdo->prepare("SELECT sig FROM bd_manifest WHERE path = ?"); $st->execute([$key]); $old = $st->fetchColumn();
            $sec = bd_page_section($pdo, $base);
            if ($old === false) {
                $pdo->prepare("INSERT INTO bd_manifest (path, sig, kind, section_code, first_seen, last_seen) VALUES (?,?,?,?,?,?)")->execute([$key, $sig, $dd[1], $sec ? $sec['code'] : null, bd_now(), bd_now()]);
                if (!$first) {
                    $res['changed']++;
                    if ($sec) bd_inbox('changed', 'New page in ' . $sec['name'] . ': ' . $base, 'A new file appeared. If it adds new work, tell me and I will add the duties and seats.', ['file' => $base, 'section_id' => (int)$sec['id'], 'new' => true], (int)$sec['id'], null, 'new:' . $key . ':' . $sig);
                    else bd_inbox('changed', ($dd[1] === 'page' ? 'New page: ' : 'New module: ') . $base, 'This file is not part of any section yet. Is it new work? Tell me which section owns it.', ['file' => $base, 'new' => true], null, null, 'new:' . $key . ':' . $sig);
                }
            } elseif ($old !== $sig) {
                $pdo->prepare("UPDATE bd_manifest SET sig = ?, last_seen = ?, section_code = ? WHERE path = ?")->execute([$sig, bd_now(), $sec ? $sec['code'] : null, $key]);
                $res['changed']++;
                bd_inbox('changed', ($sec ? $sec['name'] : 'A module') . ' changed: ' . $base, 'This file was updated. Check that the section\'s duties and roles still fit.', ['file' => $base, 'section_id' => $sec ? (int)$sec['id'] : null], $sec ? (int)$sec['id'] : null, null, 'chg:' . $key . ':' . $sig);
            } else {
                $pdo->prepare("UPDATE bd_manifest SET last_seen = ? WHERE path = ?")->execute([bd_now(), $key]);
            }
        }
    }
    if ($res['baseline']) bd_log('scan', '', 'Baseline taken: ' . count($seen) . ' files. Only changes after this are reported.', $by);
    // new joiners without a seat (only once the chain exists)
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM bd_holders")->fetchColumn() > 0) {
            $q = $pdo->query("SELECT e.employee_id, e.full_name FROM employees e WHERE (e.status IS NULL OR e.status != 'ex') AND e.joining_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND NOT EXISTS (SELECT 1 FROM bd_holders h WHERE h.emp_id = e.employee_id)");
            foreach ($q->fetchAll() as $e) {
                $secs = bd_dept_section_map($pdo); $emp = bd_emp($pdo, $e['employee_id']);
                bd_inbox('new_joiner', $e['full_name'] . ' joined and has no seat', 'Choose where ' . $e['full_name'] . ' fits so duties reach them.', ['emp_id' => (int)$e['employee_id']], $secs[(int)($emp['department_id'] ?? 0)] ?? null, null, 'join:' . $e['employee_id']);
                $res['joiners']++;
            }
        }
    } catch (Throwable $e) { /* no joining_date column: skip */ }
    // seats nobody holds in sections that have live duties are reported through the collisions list
    foreach (bd_collisions($pdo) as $c) {
        if ($c['level'] === 'note') continue;
        $id = bd_inbox('collision', $c['title'], $c['detail'], ['key' => $c['key'], 'duties' => $c['duties']], null, null, 'col:' . $c['key']);
        if ($id) $res['collisions']++;
    }
    // festivals
    $today = bd_today();
    foreach ($pdo->query("SELECT * FROM bd_seasons WHERE raised = 0")->fetchAll() as $s) {
        $from = date('Y-m-d', strtotime($s['event_date'] . ' -' . (int)$s['lead_days'] . ' days'));
        if ($today >= $from && $today <= $s['event_date']) {
            $plan = [];
            foreach (bd_season_plan($s['code'], $s['name']) as $p) $plan[] = ['section' => $p[0], 'title' => $p[1], 'date' => max($today, date('Y-m-d', strtotime($s['event_date'] . ' ' . (-$p[2] >= 0 ? '+' : '') . (-$p[2]) . ' days'))), 'proof' => $p[3], 'prompt' => $p[4]];
            bd_inbox('season', $s['name'] . ' is on ' . date('j M', strtotime($s['event_date'])) . ' - ' . count($plan) . ' one-time duties ready', 'Approve and each duty is given to the right seat on the right day (poster up, stock check, post live, poster down).', ['season_id' => (int)$s['id'], 'plan' => $plan], null, null, 'season:' . $s['id']);
            $pdo->prepare("UPDATE bd_seasons SET raised = 1 WHERE id = ?")->execute([$s['id']]); $res['seasons']++;
        }
    }
    foreach ($pdo->query("SELECT code, name, MAX(event_date) AS last FROM bd_seasons GROUP BY code, name HAVING MAX(event_date) < CURDATE()")->fetchAll() as $s) {
        bd_inbox('note', 'Add next year\'s date for ' . $s['name'], 'The last ' . $s['name'] . ' date has passed. Add the new date on the Setup page so its posters are planned.', [], null, null, 'noseason:' . $s['code'] . ':' . date('Y'));
    }
    bd_set('last_scan', bd_now());
    return $res;
}

/* ============================== new joiner / new role matcher ============================== */
function bd_match(PDO $pdo, $empId) {
    $e = bd_emp($pdo, $empId); if (!$e) return null;
    $map = bd_dept_section_map($pdo); $sid = $map[(int)($e['department_id'] ?? 0)] ?? 0;
    $opts = [];
    $desig = trim((string)($e['designation'] ?? ''));
    $q = $pdo->prepare("SELECT seat_id FROM bd_holders WHERE emp_id = ?"); $q->execute([(int)$empId]); foreach ($q->fetchAll() as $r) $mine[(int)$r['seat_id']] = 1;
    $secs = $pdo->query("SELECT id, name, code FROM bd_sections WHERE active = 1")->fetchAll();
    foreach ($secs as $s) {
        $score = ((int)$s['id'] === $sid) ? 10 : 0;
        $seats = $pdo->prepare("SELECT id, title, kind FROM bd_seats WHERE section_id = ? AND active = 1 AND kind IN ('worker','checker') ORDER BY kind DESC, id"); $seats->execute([(int)$s['id']]);
        foreach ($seats->fetchAll() as $seat) {
            if (isset($mine[(int)$seat['id']])) continue;
            $sc = $score + ($desig !== '' ? 5 * bd_sim($desig, $seat['title']) : 0) - ($seat['kind'] === 'checker' ? 3 : 0);
            if ($sc <= 0 && $score === 0) continue;
            $h = bd_holders($pdo, (int)$seat['id']);
            $duties = (int)$pdo->query("SELECT COUNT(*) FROM bd_duties WHERE section_id = " . (int)$s['id'] . " AND status = 'live'")->fetchColumn();
            $opts[] = ['type' => 'join', 'seat_id' => (int)$seat['id'], 'section_id' => (int)$s['id'], 'section' => $s['name'], 'title' => $seat['title'], 'holders' => count($h), 'duties' => $duties,
                'score' => $sc + (count($h) === 0 ? 2 : 0) + (count($h) === 1 ? 1 : 0), 'why' => count($h) === 0 ? 'Nobody holds this seat yet.' : (count($h) === 1 ? 'Only one person holds it - a backup helps when they are away.' : count($h) . ' people already hold it.')];
        }
    }
    usort($opts, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $opts = array_slice($opts, 0, 4);
    $best = $opts ? $opts[0]['score'] : 0;
    $newTitle = $desig !== '' ? $desig : 'New role';
    $need = (!$opts || $best < 9 || ($desig !== '' && bd_sim($desig, $opts[0]['title']) < 0.15 && $opts[0]['holders'] > 0 && !preg_match('/team|staff|executive|associate/i', $desig)));
    $res = ['emp' => ['id' => (int)$empId, 'name' => $e['full_name'], 'dept' => $e['department_name'], 'designation' => $desig], 'section_id' => $sid, 'options' => $opts, 'suggest_new' => null];
    if ($need) {
        $res['suggest_new'] = ['type' => 'new', 'section_id' => $sid ?: ($secs ? (int)$secs[0]['id'] : 0), 'title' => $newTitle, 'why' => 'No existing seat fits "' . $newTitle . '". I can create this seat and give it the section\'s basic duties. Menu access (permissions) is not changed automatically: grant it on the Roles page.'];
    }
    return $res;
}

/* ============================== daily summary ============================== */
function bd_today_numbers(PDO $pdo, $date) {
    $r = $pdo->prepare("SELECT status, COUNT(*) c FROM bd_tasks WHERE task_date = ? GROUP BY status"); $r->execute([$date]);
    $o = ['open' => 0, 'done' => 0, 'missed' => 0, 'unowned' => 0, 'excused' => 0];
    foreach ($r->fetchAll() as $x) $o[$x['status']] = (int)$x['c'];
    $o['total'] = array_sum($o) - $o['excused'];
    $p = $pdo->prepare("SELECT COALESCE(SUM(pen_amount),0) FROM bd_tasks WHERE task_date = ? AND pen_state IN ('shadow','pending')"); $p->execute([$date]); $o['pen_would'] = (float)$p->fetchColumn();
    $p = $pdo->prepare("SELECT COALESCE(SUM(pen_amount),0) FROM bd_tasks WHERE task_date = ? AND pen_state = 'applied'"); $p->execute([$date]); $o['pen_given'] = (float)$p->fetchColumn();
    $p = $pdo->prepare("SELECT COUNT(*) FROM bd_tasks WHERE task_date = ? AND cover_why IS NOT NULL AND cover_why != ''"); $p->execute([$date]); $o['covered'] = (int)$p->fetchColumn();
    return $o;
}
function bd_digest(PDO $pdo, $now) {
    $date = date('Y-m-d', $now);
    if ((int)date('G', $now) < (int)bd_cfg('digest_hour') || bd_cfg_fresh('last_digest') === $date) return false;
    $n = bd_today_numbers($pdo, $date);
    if ($n['total'] === 0) { bd_set('last_digest', $date); return false; }
    $txt = $n['done'] . ' of ' . $n['total'] . ' duties done, ' . $n['missed'] . ' missed' . ($n['covered'] ? ', ' . $n['covered'] . ' covered for absent staff' : '') . ($n['pen_would'] > 0 ? '. Penalties waiting: ' . bd_inr($n['pen_would']) : '') . '.';
    bd_inbox('digest', 'Today: ' . $n['done'] . '/' . $n['total'] . ' done', $txt, $n, null, null, 'dig:' . $date);
    if (bd_mode() !== 'shadow') {
        $to = [];
        foreach (['top.boss', 'top.deputy'] as $c) foreach (bd_holders($pdo, bd_seat_id($pdo, $c)) as $e) $to[$e] = 1;
        $tb = (int)bd_cfg_fresh('top_boss'); if ($tb) $to[$tb] = 1;
        foreach (array_keys($to) as $e) bd_push($pdo, $e, 'Boss Engine - today', $txt);
    }
    bd_set('last_digest', $date);
    return true;
}

/* ============================== the tick ============================== */
function bd_tick(PDO $pdo, $force = false) {
    $res = ['ran' => false];
    $got = true;
    try { $got = (bool)$pdo->query("SELECT GET_LOCK('bd_tick', 0)")->fetchColumn(); } catch (Throwable $e) {}
    if (!$got) return $res;
    try {
        $last = strtotime((string)bd_cfg_fresh('last_tick') ?: '2000-01-01');
        if (!$force && time() - $last < 90) return $res;
        bd_set('last_tick', bd_now());
        $now = time(); $today = date('Y-m-d', $now);
        $res = ['ran' => true, 'mode' => bd_mode()];
        $pdo->prepare("UPDATE bd_tasks SET status = 'excused' WHERE status IN ('open','unowned') AND task_date >= ? AND duty_id IN (SELECT id FROM bd_duties WHERE status != 'live')")->execute([$today]);
        $res['made'] = bd_generate($pdo, $today);
        $res['moved'] = bd_reassign($pdo, $today);
        $res['auto'] = bd_auto_check($pdo, $today);
        $res['reminded'] = bd_remind($pdo, $now);
        $res['missed'] = bd_miss($pdo, $now);
        if (function_exists('b2_tick')) { try { $res['engine2'] = b2_tick($pdo, $now, $force); } catch (Throwable $beErr) { error_log('Boss Engine 2: ' . $beErr->getMessage()); } }
        if (function_exists('cx_tick')) { try { $res['complaints'] = cx_tick($pdo, $now); } catch (Throwable $cxErr) { error_log('Complaints: ' . $cxErr->getMessage()); } }
        if (function_exists('pp_tick')) { try { $res['people'] = pp_tick($pdo, $now); } catch (Throwable $ppErr) { error_log('People: ' . $ppErr->getMessage()); } }
        if (function_exists('sx_tick')) { try { $res['inbox'] = sx_tick($pdo, $now); } catch (Throwable $sxErr) { error_log('Inbox: ' . $sxErr->getMessage()); } }
        bd_digest($pdo, $now);
        $ls = strtotime((string)bd_cfg_fresh('last_scan') ?: '2000-01-01');
        if ($force || $now - $ls > 6 * 3600) $res['scan'] = bd_scan($pdo);
    } catch (Throwable $e) {
        error_log('Boss Engine tick: ' . $e->getMessage());
        $res['error'] = mb_substr($e->getMessage(), 0, 200);
    }
    try { $pdo->query("SELECT RELEASE_LOCK('bd_tick')"); } catch (Throwable $e) {}
    return $res;
}

/* ============================== the endpoints ============================== */
function bd_scope_sql(array $w, $col = 'section_id') {
    if ($w['admin'] || $w['top']) return '1=1';
    return $w['sections'] ? "$col IN (" . bd_in($w['sections']) . ')' : '1=0';
}
function bd_duty_row(PDO $pdo, $id) { $st = $pdo->prepare("SELECT d.*, s.name AS sec_name FROM bd_duties d JOIN bd_sections s ON s.id = d.section_id WHERE d.id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function bd_duty_view(PDO $pdo, array $d) {
    $seat = (int)$d['seat_id'] ?: bd_section_seat($pdo, (int)$d['section_id'], 'worker');
    $names = []; foreach ($seat ? bd_holders($pdo, $seat) : [] as $e) $names[] = bd_emp_name($pdo, $e);
    $sr = $seat ? bd_seat_row($pdo, $seat) : null;
    return ['id' => (int)$d['id'], 'section_id' => (int)$d['section_id'], 'section' => $d['sec_name'] ?? '', 'seat_id' => $seat, 'seat' => $sr ? $sr['title'] : '', 'holders' => $names, 'title' => $d['title'], 'why' => $d['why'],
        'freq' => $d['freq'], 'dow' => $d['dow'], 'dom' => $d['dom'] !== null ? (int)$d['dom'] : null, 'on_date' => $d['on_date'], 'due_time' => $d['due_time'], 'grace_min' => (int)$d['grace_min'], 'proof' => $d['proof'], 'adapter' => $d['adapter'],
        'photo_prompt' => $d['photo_prompt'], 'has_ref' => !empty($d['ref_photo']), 'target_qty' => $d['target_qty'] !== null ? (int)$d['target_qty'] : null, 'cmp' => $d['cmp'], 'unit' => $d['unit'], 'assign_mode' => $d['assign_mode'],
        'pen_amount' => (float)$d['pen_amount'], 'pen_mode' => $d['pen_mode'], 'pen_remark' => $d['pen_remark'], 'status' => $d['status'], 'source' => $d['source'], 'version' => (int)$d['version']];
}
function bd_task_view(PDO $pdo, array $t) {
    return ['id' => (int)$t['id'], 'duty_id' => (int)$t['duty_id'], 'title' => $t['title'], 'why' => $t['why'] ?? '', 'section' => $t['sec_name'] ?? '', 'section_id' => (int)$t['section_id'], 'date' => $t['task_date'],
        'due_at' => $t['due_at'], 'due_label' => date('g:i A', strtotime($t['due_at'])), 'status' => $t['status'], 'owner_id' => $t['owner_emp'] ? (int)$t['owner_emp'] : null, 'owner' => $t['owner_emp'] ? bd_emp_name($pdo, $t['owner_emp']) : '',
        'orig' => $t['orig_emp'] ? bd_emp_name($pdo, $t['orig_emp']) : '', 'cover_why' => $t['cover_why'], 'proof' => $t['proof'], 'photo_prompt' => $t['photo_prompt'], 'target_qty' => $t['target_qty'] !== null ? (int)$t['target_qty'] : null, 'cmp' => $t['cmp'],
        'unit' => $t['unit'], 'proof_value' => $t['proof_value'] !== null ? (int)$t['proof_value'] : null, 'has_photo' => !empty($t['proof_path']), 'ai_state' => $t['ai_state'], 'ai_reason' => $t['ai_reason'], 'attempts' => (int)$t['attempts'],
        'check_state' => $t['check_state'], 'pen_state' => $t['pen_state'], 'pen_amount' => (float)$t['pen_amount'], 'pen_remark' => $t['pen_remark'], 'done_at' => $t['done_at'], 'pen_default' => (float)($t['d_pen'] ?? 0)];
}
function bd_tasks_q(PDO $pdo, $where, array $args) {
    $st = $pdo->prepare("SELECT t.*, d.title, d.why, d.proof, d.photo_prompt, d.target_qty, d.cmp, d.unit, d.pen_amount AS d_pen, s.name AS sec_name FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id JOIN bd_sections s ON s.id = t.section_id WHERE $where ORDER BY t.due_at, t.id");
    $st->execute($args); $o = []; foreach ($st->fetchAll() as $t) $o[] = bd_task_view($pdo, $t); return $o;
}
function bd_save_photo_file($tmp, $taskId) {
    $info = @getimagesize($tmp); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return [null, 'That file is not a photo.'];
    $name = 'p_' . (int)$taskId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg'; $dest = bd_dir() . $name;
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($im) { $w = imagesx($im); $h = imagesy($im); $m = max($w, $h); if ($m > 1600) { $s = 1600 / $m; $t = imagecreatetruecolor((int)($w * $s), (int)($h * $s)); imagecopyresampled($t, $im, 0, 0, 0, 0, (int)($w * $s), (int)($h * $s), $w, $h); imagedestroy($im); $im = $t; } imagejpeg($im, $dest, 85); imagedestroy($im); if (is_file($dest)) return [$name, '']; }
    }
    if (@move_uploaded_file($tmp, $dest) || @copy($tmp, $dest)) return [$name, ''];
    return [null, 'Could not save the photo on the server.'];
}
function bd_find_task_for(PDO $pdo, $taskId) { $r = bd_tasks_q($pdo, 't.id = ?', [(int)$taskId]); return $r ? $r[0] : null; }

function bd_tick_after(PDO $pdo) {
    static $set = false; if ($set) return; $set = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { bd_ensure($pdo); bd_tick($pdo, false); } catch (Throwable $e) { error_log('Boss Engine: ' . $e->getMessage()); }
    });
}

if (!function_exists('brain_ensure') && is_file(__DIR__ . '/brain.php')) { require_once __DIR__ . '/brain.php'; }   // the company brain (one Claude door, scopes)
if (is_file(__DIR__ . '/boss2.php')) { require_once __DIR__ . '/boss2.php'; }   // Boss Engine 2
if (is_file(__DIR__ . '/complaints.php')) { require_once __DIR__ . '/complaints.php'; }   // Complaints department (bd.cx_* actions, desks, seats)
if (is_file(__DIR__ . '/social.php')) { require_once __DIR__ . '/social.php'; }   // Inbox hub: Instagram, Facebook, email, reviews (bd.sx_* actions)
if (is_file(__DIR__ . '/people.php')) { require_once __DIR__ . '/people.php'; }   // People section: bd.pp_* actions

/** The Boss Engine menu numbers. Used by bd.badge and by bd.menu_ctx. */
function bd_badge_data(PDO $pdo, array $w): array
{
        $my = 0; if ($w['emp'] && bd_mode() !== 'shadow') { $q = $pdo->prepare("SELECT COUNT(*) FROM bd_tasks WHERE owner_emp = ? AND task_date = ? AND status = 'open'"); $q->execute([$w['emp'], bd_today()]); $my = (int)$q->fetchColumn(); }
        $has = 0; if ($w['emp']) { $q = $pdo->prepare("SELECT COUNT(*) FROM bd_tasks WHERE owner_emp = ? AND task_date >= ?"); $q->execute([$w['emp'], date('Y-m-d', strtotime('-14 days'))]); $has = (int)$q->fetchColumn(); }
        $inbox = $w['manager'] ? (int)$pdo->query("SELECT COUNT(*) FROM bd_inbox WHERE status = 'open' AND kind NOT IN ('digest','note') AND " . bd_scope_sql($w))->fetchColumn() : 0;
        // Departments add-on (dept.php): its open items count too
        $dpOpen = 0; $dpHas = false;
        if (function_exists('dp_badge_open') && $w['emp']) { $dpOpen = bd_mode() !== 'shadow' ? dp_badge_open($pdo, $w['emp']) : 0; $dpHas = dp_has_items($pdo, $w['emp']); }
        $my += $dpOpen; if ($dpHas) $has += 1;
        $beN = 0; if (function_exists('b2_badge_boss') && $w['manager']) { $beN = (int)b2_badge_boss($pdo, $w); }
        $b2Mine = false; if (function_exists('b2_badge_my') && $w['emp']) { $b2 = b2_badge_my($pdo, $w['emp']); $my += (int)$b2[0]; if (!empty($b2[1])) $has += 1; $b2Mine = (int)$b2[0] > 0 || !empty($b2[1]); }
        // piggy-back: keep the engine ticking even without a cron job
        bd_tick_after($pdo);
    return ['show' => ($has > 0 || $w['manager']) && ($w['manager'] || bd_mode() !== 'shadow' || $b2Mine), 'manager' => $w['manager'], 'admin' => $w['admin'], 'my_open' => $my, 'inbox' => $inbox, 'be' => $beN, 'mode' => bd_mode(), 'has_tasks' => $has > 0 && (bd_mode() !== 'shadow' || $b2Mine)];
}

try {
if (strpos((string)$action, 'bd.') === 0) {
    bd_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($action === 'bd.tick') {
        $key = (string)($_GET['key'] ?? '');
        if ($key !== '' && hash_equals((string)bd_cfg_fresh('tick_key'), $key)) { json_response(['ok' => true, 'result' => bd_tick($pdo, !empty($_GET['force']))]); }
        $auth = require_auth(); bd_need_admin($auth);
        json_response(['ok' => true, 'result' => bd_tick($pdo, true)]);
    }

    if ($action === 'bd.sx_hook' && function_exists('sx_hook')) { sx_hook($pdo); }   // Meta calls this itself (no login); the signature is checked inside

    $auth = require_auth();
    $w = bd_who($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];

    if (strpos((string)$action, 'bd.b2_') === 0 && function_exists('b2_dispatch')) { b2_dispatch($pdo, (string)$action, $auth, $w, $by, $in, $isPost); }
    if (strpos((string)$action, 'bd.cx_') === 0 && function_exists('cx_dispatch')) { cx_dispatch($pdo, (string)$action, $auth, $w, $by, $in, $isPost); }   // Complaints department
    if (strpos((string)$action, 'bd.sx_') === 0 && function_exists('sx_dispatch')) { sx_dispatch($pdo, (string)$action, $auth, $w, $by, $in, $isPost); }   // Inbox hub
    if (strpos((string)$action, 'bd.pp_') === 0 && function_exists('pp_dispatch')) { pp_dispatch($pdo, (string)$action, $auth, $w, $by, $in, $isPost); }   // People section (people.php)
    if (strpos((string)$action, 'bd.br_') === 0 && function_exists('br_dispatch')) { br_dispatch($pdo, (string)$action, $auth, $w, $by, $in, $isPost); }   // the company brain (brain.php): page view, teaching, scopes, leak test

    switch ($action) {

    /* ---------- everyone ---------- */
    case 'bd.badge': {
        json_response(bd_badge_data($pdo, $w));
    }

    // ONE call for everything the sidebar asks when a page opens (flags, Boss, Flyers, Complaints, Inbox): the same answers as the five
    // separate calls, from one request and one database connection. A part that fails is null, exactly like a failed separate call.
    case 'bd.menu_ctx': {
        if (is_file(__DIR__ . '/flyers.php')) { try { require_once __DIR__ . '/flyers.php'; } catch (Throwable $e) { } }
        $cap = function (callable $f) { $GLOBALS['__jr_cap'] = true; $r = null; try { $f(); } catch (JrCapture $c) { $r = ((int)$c->httpCode >= 400) ? null : $c->data; } catch (Throwable $e) { $r = null; } $GLOBALS['__jr_cap'] = false; return $r; };
        $out = [
            'flags' => $cap(function () use ($pdo, $auth) { json_response(me_flags_data($pdo, $auth)); }),
            'bd' => $cap(function () use ($pdo, $w) { json_response(bd_badge_data($pdo, $w)); }),
            'fl' => function_exists('fl_badge_data') ? $cap(function () use ($pdo, $auth) { json_response(fl_badge_data($pdo, $auth)); }) : null,
            'cx' => function_exists('cx_dispatch') ? $cap(function () use ($pdo, $auth, $w, $by) { cx_dispatch($pdo, 'bd.cx_badge', $auth, $w, $by, [], false); }) : null,
            'sx' => function_exists('sx_dispatch') ? $cap(function () use ($pdo, $auth, $w, $by) { sx_dispatch($pdo, 'bd.sx_badge', $auth, $w, $by, [], false); }) : null,
        ];
        json_response($out);
    }

    case 'bd.my_day': {
        if (!$w['emp']) json_response(['error' => 'This login is not linked to an employee.'], 400);
        $mode = bd_mode(); $today = bd_today();
        if ($mode === 'shadow') json_response(['mode' => $mode, 'tasks' => [], 'week' => ['done' => 0, 'missed' => 0], 'penalties' => [], 'name' => bd_emp_name($pdo, $w['emp'])]);
        $tasks = bd_tasks_q($pdo, 't.owner_emp = ? AND t.task_date = ? AND t.status != \'excused\'', [$w['emp'], $today]);
        $q = $pdo->prepare("SELECT status, COUNT(*) c FROM bd_tasks WHERE owner_emp = ? AND task_date BETWEEN ? AND ? AND task_date < ? GROUP BY status");
        $q->execute([$w['emp'], date('Y-m-d', strtotime('-6 days')), $today, $today]); $wk = ['done' => 0, 'missed' => 0]; foreach ($q->fetchAll() as $r) if (isset($wk[$r['status']])) $wk[$r['status']] = (int)$r['c'];
        $pen = bd_tasks_q($pdo, "t.owner_emp = ? AND t.pen_state IN ('pending','applied','appealed','waived') AND t.task_date >= ?", [$w['emp'], date('Y-m-d', strtotime('-30 days'))]);
        $out = []; foreach ($pen as $p) $out[] = ['task_id' => $p['id'], 'title' => $p['title'], 'date' => $p['date'], 'amount' => $p['pen_amount'], 'state' => $p['pen_state'], 'remark' => $p['pen_remark']];
        $cap = (int)bd_cfg('task_cap');
        json_response(['mode' => $mode, 'tasks' => $tasks, 'week' => $wk, 'penalties' => $out, 'name' => bd_emp_name($pdo, $w['emp']), 'max_retakes' => (int)bd_cfg('max_retakes'), 'cap' => $cap]);
    }

    case 'bd.task_submit': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$w['emp']) json_response(['error' => 'This login is not linked to an employee.'], 400);
        $tid = (int)($_POST['task_id'] ?? ($in['task_id'] ?? 0));
        $t = bd_find_task_for($pdo, $tid);
        if (!$t) json_response(['error' => 'Task not found.'], 404);
        if ((int)$t['owner_id'] !== $w['emp'] && !$w['admin']) json_response(['error' => 'This duty belongs to ' . ($t['owner'] ?: 'someone else') . '.'], 403);
        if (bd_mode() === 'shadow' && !$w['admin']) json_response(['error' => 'The Boss Engine is in test mode. Duties are not live yet.'], 409);
        if ($t['date'] !== bd_today()) json_response(['error' => 'Only today\'s duties can be done.'], 409);
        if ($t['status'] === 'done' && $t['check_state'] !== 'rejected') json_response(['error' => 'This duty is already done.'], 409);
        if (!in_array($t['status'], ['open', 'missed', 'done'], true)) json_response(['error' => 'This duty cannot be done now (' . $t['status'] . ').'], 409);
        $late = $t['status'] === 'missed';
        $now = bd_now(); $note = mb_substr(trim((string)($_POST['note'] ?? ($in['note'] ?? ''))), 0, 250);
        $finish = function ($extra = []) use ($pdo, $tid, $now, $late, $note, $t) {
            $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = ?, proof_note = ?, cover_why = cover_why WHERE id = ?")->execute([$now, $note !== '' ? $note : ($late ? 'done late' : null), $tid]);
            bd_log('done', $tid, $t['title'] . ($late ? ' (late)' : ''), 'employee');
        };
        if ($t['proof'] === 'tick') { $finish(); json_response(['ok' => true, 'message' => 'Done. Thank you.']); }
        if ($t['proof'] === 'auto') json_response(['error' => 'This duty is checked by the system. Just do the work (for example take the flyer photos).'], 409);
        if ($t['proof'] === 'count') {
            if (!isset($in['value']) && !isset($_POST['value'])) json_response(['error' => 'Enter the number.'], 422);
            $val = (int)($_POST['value'] ?? $in['value']); if ($val < 0 || $val > 100000) json_response(['error' => 'Enter a sensible number.'], 422);
            $pdo->prepare("UPDATE bd_tasks SET proof_value = ? WHERE id = ?")->execute([$val, $tid]);
            $tg = $t['target_qty']; $cmp = $t['cmp'];
            if ($cmp === 'min' && $tg !== null && $val < $tg) json_response(['ok' => true, 'done' => false, 'message' => 'Saved: ' . $val . ' of ' . $tg . ($t['unit'] ? ' ' . $t['unit'] : '') . '. Enter the number again when you reach ' . $tg . '.']);
            $finish();
            if ($cmp === 'max' && $tg !== null && $val > $tg) {
                bd_inbox('problem', $t['title'] . ': ' . $val . ' ' . ($t['unit'] ?: ''), bd_emp_name($pdo, $w['emp']) . ' reported ' . $val . ' ' . ($t['unit'] ?: '') . ' (should be ' . $tg . ' or less).', ['task_id' => $tid], (int)$t['section_id'], null, 'prob:' . $tid);
                json_response(['ok' => true, 'done' => true, 'message' => 'Saved. Your boss has been told about the ' . $val . '.']);
            }
            json_response(['ok' => true, 'done' => true, 'message' => 'Done. Thank you.']);
        }
        // photo
        if (empty($_FILES['photo']) || (int)$_FILES['photo']['error'] !== UPLOAD_ERR_OK) json_response(['error' => 'Take the photo first.'], 422);
        list($fn, $err) = bd_save_photo_file($_FILES['photo']['tmp_name'], $tid);
        if (!$fn) json_response(['error' => $err], 422);
        $abs = bd_dir() . $fn; $hash = bd_ahash($abs);
        $duty = bd_duty_row($pdo, $t['duty_id']);
        $att = (int)$t['attempts'] + 1;
        if ($hash) {
            $q = $pdo->prepare("SELECT phash FROM bd_photos WHERE duty_id = ? AND created_at >= ? AND phash IS NOT NULL AND verdict IN ('pass','manual') ORDER BY id DESC LIMIT 60"); $q->execute([(int)$t['duty_id'], date('Y-m-d', strtotime('-14 days'))]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $old) if (bd_hamming($hash, $old) <= 3) {
                $pdo->prepare("INSERT INTO bd_photos (task_id, duty_id, emp_id, path, phash, verdict, reason, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$tid, (int)$t['duty_id'], $w['emp'], $fn, $hash, 'fail', 'same as an older photo', $now]);
                $pdo->prepare("UPDATE bd_tasks SET attempts = ? WHERE id = ?")->execute([$att, $tid]);
                json_response(['ok' => false, 'retake' => true, 'reason' => 'This looks like an older photo. Take a NEW photo right now.', 'attempts' => $att, 'left' => max(0, (int)bd_cfg('max_retakes') - $att)]);
            }
        }
        list($state, $why) = bd_photo_judge($pdo, $duty, $abs);
        $max = max(1, (int)bd_cfg('max_retakes'));
        $log = function ($verdict, $reason) use ($pdo, $tid, $t, $w, $fn, $hash, $now) { $pdo->prepare("INSERT INTO bd_photos (task_id, duty_id, emp_id, path, phash, verdict, reason, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$tid, (int)$t['duty_id'], $w['emp'], $fn, $hash, $verdict, mb_substr($reason, 0, 250), $now]); };
        if ($state === 'pass') {
            $log('pass', $why);
            $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = ?, proof_path = ?, ai_state = 'pass', ai_reason = ?, attempts = ?, check_state = '' WHERE id = ?")->execute([$now, $fn, $why, $att, $tid]);
            bd_log('done', $tid, $t['title'] . ' (photo passed)', 'employee');
            json_response(['ok' => true, 'done' => true, 'message' => 'Photo accepted. Thank you.']);
        }
        if ($state === 'fail' && $att < $max) {
            $log('fail', $why);
            $pdo->prepare("UPDATE bd_tasks SET attempts = ?, ai_state = 'fail', ai_reason = ? WHERE id = ?")->execute([$att, $why, $tid]);
            json_response(['ok' => false, 'retake' => true, 'reason' => $why !== '' ? $why : 'The photo does not show the work done properly.', 'attempts' => $att, 'left' => $max - $att]);
        }
        // AI unavailable, or it refused too many times: a person decides, the employee is never stuck
        $log($state === 'fail' ? 'manual' : 'skip', $why);
        $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = ?, proof_path = ?, ai_state = ?, ai_reason = ?, attempts = ?, check_state = 'pending' WHERE id = ?")->execute([$now, $fn, $state === 'fail' ? 'manual' : 'skip', $why, $att, $tid]);
        bd_inbox('ai_review', 'Check this photo: ' . $t['title'], ($state === 'fail' ? 'The AI refused ' . $att . ' times (' . $why . '). ' : 'The AI check was not available. ') . bd_emp_name($pdo, $w['emp']) . ' needs a yes or no.', ['task_id' => $tid], (int)$t['section_id'], null, 'rev:' . $tid . ':' . $att);
        json_response(['ok' => true, 'done' => true, 'review' => true, 'message' => 'Sent to your checker to look at. You do not need to do anything more.']);
    }

    case 'bd.task_appeal': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $t = bd_find_task_for($pdo, (int)($in['task_id'] ?? 0));
        if (!$t || (int)$t['owner_id'] !== $w['emp']) json_response(['error' => 'Not found.'], 404);
        if (!in_array($t['pen_state'], ['pending', 'applied', 'shadow'], true)) json_response(['error' => 'There is nothing to appeal on this duty.'], 409);
        $txt = mb_substr(trim((string)($in['text'] ?? '')), 0, 400); if ($txt === '') json_response(['error' => 'Write why this is not fair.'], 422);
        $was = $t['pen_state'];
        $pdo->prepare("UPDATE bd_tasks SET pen_state = ?, proof_note = CONCAT(COALESCE(proof_note,''), ' | appeal: ', ?) WHERE id = ?")->execute([$was === 'shadow' ? 'shadow' : 'appealed', $txt, $t['id']]);
        bd_inbox('appeal', bd_emp_name($pdo, $w['emp']) . ' appeals: ' . $t['title'], $txt, ['task_id' => $t['id'], 'was' => $was], (int)$t['section_id'], null, 'app:' . $t['id']);
        bd_log('appeal', $t['id'], $txt, $by);
        json_response(['ok' => true, 'message' => 'Your appeal was sent to your boss.']);
    }

    case 'bd.photo': {
        $t = bd_find_task_for($pdo, (int)($_GET['task_id'] ?? 0)); if (!$t) json_response(['error' => 'Not found'], 404);
        if ((int)$t['owner_id'] !== $w['emp'] && !(bd_can_section($w, $t['section_id']) && $w['manager'])) json_response(['error' => 'Not found'], 404);
        $st = $pdo->prepare("SELECT proof_path FROM bd_tasks WHERE id = ?"); $st->execute([$t['id']]); $p = basename((string)$st->fetchColumn());
        if ($p === '' || !is_file(bd_dir() . $p)) json_response(['error' => 'No photo'], 404);
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize(bd_dir() . $p)); readfile(bd_dir() . $p); exit;
    }
    case 'bd.ref_photo_get': {
        $d = bd_duty_row($pdo, (int)($_GET['duty_id'] ?? 0)); $p = $d ? basename((string)$d['ref_photo']) : '';
        if ($p === '' || !is_file(bd_dir() . $p)) json_response(['error' => 'No photo'], 404);
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile(bd_dir() . $p); exit;
    }
    }  // end switch 1 (everyone)

    /* ================= managers from here ================= */
    $w = bd_need_manager($pdo, $auth);
    switch ($action) {

    case 'bd.board': {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : bd_today();
        $scope = bd_scope_sql($w, 't.section_id');
        $secs = [];
        $q = $pdo->prepare("SELECT s.id, s.name, s.code, s.state, SUM(t.status='done') done, SUM(t.status='open') open_, SUM(t.status='missed') missed, SUM(t.status='unowned') unowned, COUNT(t.id) total, COALESCE(SUM(CASE WHEN t.pen_state IN ('shadow','pending') THEN t.pen_amount ELSE 0 END),0) pen
            FROM bd_sections s LEFT JOIN bd_tasks t ON t.section_id = s.id AND t.task_date = ? AND t.status != 'excused' WHERE s.active = 1 AND " . bd_scope_sql($w, 's.id') . " GROUP BY s.id ORDER BY s.name");
        $q->execute([$date]);
        foreach ($q->fetchAll() as $s) {
            $live = (int)$pdo->query("SELECT COUNT(*) FROM bd_duties WHERE section_id = " . (int)$s['id'] . " AND status = 'live'")->fetchColumn();
            $prop = (int)$pdo->query("SELECT COUNT(*) FROM bd_duties WHERE section_id = " . (int)$s['id'] . " AND status = 'proposed'")->fetchColumn();
            $bs = bd_section_seat($pdo, (int)$s['id'], 'section_boss'); $bn = []; foreach ($bs ? bd_holders($pdo, $bs) : [] as $e) $bn[] = bd_emp_name($pdo, $e);
            $secs[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'code' => $s['code'], 'done' => (int)$s['done'], 'open' => (int)$s['open_'], 'missed' => (int)$s['missed'], 'unowned' => (int)$s['unowned'], 'total' => (int)$s['total'], 'pen' => (float)$s['pen'], 'live_duties' => $live, 'proposed' => $prop, 'boss' => $bn];
        }
        $n = bd_today_numbers($pdo, $date);
        $problems = bd_tasks_q($pdo, "t.task_date = ? AND t.status != 'excused' AND (t.status IN ('missed','unowned') OR t.cover_why IS NOT NULL OR t.pen_state IN ('pending','shadow','appealed')) AND " . $scope, [$date]);
        $log = $pdo->query("SELECT at, actor, kind, detail FROM bd_log ORDER BY id DESC LIMIT 12")->fetchAll();
        $inbox = (int)$pdo->query("SELECT COUNT(*) FROM bd_inbox WHERE status = 'open' AND kind NOT IN ('digest','note') AND " . bd_scope_sql($w))->fetchColumn();
        $setup = ['top_boss' => (int)bd_cfg_fresh('top_boss') > 0, 'duties_live' => (int)$pdo->query("SELECT COUNT(*) FROM bd_duties WHERE status = 'live'")->fetchColumn(), 'duties_proposed' => (int)$pdo->query("SELECT COUNT(*) FROM bd_duties WHERE status = 'proposed'")->fetchColumn(), 'duties_total' => (int)$pdo->query("SELECT COUNT(*) FROM bd_duties")->fetchColumn()];
        json_response(['date' => $date, 'mode' => bd_mode(), 'numbers' => $n, 'sections' => $secs, 'problems' => $problems, 'log' => $log, 'inbox' => $inbox, 'setup' => $setup, 'admin' => $w['admin'], 'last_tick' => bd_cfg_fresh('last_tick'), 'name' => $w['emp'] ? bd_emp_name($pdo, $w['emp']) : $by]);
    }

    case 'bd.tasks': {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : bd_today();
        json_response(['date' => $date, 'tasks' => bd_tasks_q($pdo, 't.task_date = ? AND ' . bd_scope_sql($w, 't.section_id'), [$date])]);
    }

    case 'bd.task_photo_view': { json_response(['error' => 'Use bd.photo'], 400); }

    case 'bd.inbox': {
        $status = ($_GET['status'] ?? 'open') === 'all' ? '1=1' : "status = 'open'";
        $rows = $pdo->query("SELECT i.*, s.name AS sec_name FROM bd_inbox i LEFT JOIN bd_sections s ON s.id = i.section_id WHERE $status AND " . bd_scope_sql($w, 'i.section_id') . " ORDER BY FIELD(i.kind,'penalty','dp_pen','appeal','dp_appeal','ai_review','uncovered','collision','new_section','new_joiner','season','problem','changed','digest','note'), i.id DESC LIMIT 200")->fetchAll();
        $o = [];
        foreach ($rows as $r) {
            $p = bd_unjson($r['payload']); $extra = [];
            if (isset($p['task_id'])) { $tv = bd_find_task_for($pdo, (int)$p['task_id']); if ($tv) $extra['task'] = $tv; }
            if ($r['kind'] === 'new_joiner' && isset($p['emp_id'])) $extra['match'] = bd_match($pdo, (int)$p['emp_id']);
            $o[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => $r['body'], 'payload' => $p, 'section_id' => $r['section_id'] ? (int)$r['section_id'] : null, 'section' => $r['sec_name'], 'status' => $r['status'], 'at' => $r['created_at'], 'extra' => $extra];
        }
        json_response(['items' => $o, 'mode' => bd_mode()]);
    }

    case 'bd.inbox_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $st = $pdo->prepare("SELECT * FROM bd_inbox WHERE id = ?"); $st->execute([$id]); $it = $st->fetch();
        if (!$it) json_response(['error' => 'Not found.'], 404);
        if ($it['section_id'] && !bd_can_section($w, $it['section_id'])) json_response(['error' => 'This item belongs to another section.'], 403);
        if ($it['status'] !== 'open') json_response(['error' => 'Already ' . $it['status'] . '.'], 409);
        if (strpos((string)$it['kind'], 'dp_') === 0) { if (!function_exists('dp_inbox_act') && is_file(__DIR__ . '/dept.php')) { require_once __DIR__ . '/dept.php'; } if (function_exists('dp_inbox_act')) { dp_inbox_act($pdo, $it, $act, $w, $by); } }
        $p = bd_unjson($it['payload']); $msg = 'Done.'; $close = 'done';
        $task = isset($p['task_id']) ? bd_find_task_for($pdo, (int)$p['task_id']) : null;
        if ($act === 'dismiss') { $close = 'dismissed'; $msg = 'Dismissed.'; }
        elseif ($it['kind'] === 'penalty') {
            if (!$task) json_response(['error' => 'Task is gone.'], 404);
            if ($task['owner_id'] && $task['owner_id'] === $w['emp'] && !$w['admin']) json_response(['error' => 'You cannot decide your own penalty.'], 403);
            if ($act === 'approve') {
                if (bd_mode() !== 'live') json_response(['error' => 'The Boss Engine is not in LIVE mode, so no money is taken yet.'], 409);
                list($ok, $m) = bd_apply_penalty($pdo, $task['id'], $by); if (!$ok) json_response(['error' => $m], 409); $msg = $m;
            } elseif ($act === 'reject') { $pdo->prepare("UPDATE bd_tasks SET pen_state = 'waived' WHERE id = ?")->execute([$task['id']]); $msg = 'No penalty. Closed.'; bd_log('penalty', $task['id'], 'waived before applying', $by); }
            else json_response(['error' => 'Choose approve or reject.'], 422);
        } elseif ($it['kind'] === 'appeal') {
            if (!$task) json_response(['error' => 'Task is gone.'], 404);
            if ($task['owner_id'] && $task['owner_id'] === $w['emp'] && !$w['admin']) json_response(['error' => 'You cannot decide your own appeal.'], 403);
            if ($act === 'approve') {
                if (!empty($task['pen_id'])) {
                    $pdo->prepare("UPDATE employee_penalties SET status = 'waived', resolved_by = ?, resolved_at = ?, resolve_note = 'Appeal accepted (Boss Engine)' WHERE penalty_id = ? AND status = 'applied'")->execute([$by, bd_now(), $task['pen_id']]);
                    if (function_exists('penalty_notify')) { try { penalty_notify($pdo, (int)$task['owner_id'], (int)$task['pen_id'], 'waived', 'Penalty waived', 'Your appeal for "' . $task['title'] . '" was accepted. The penalty is cancelled.'); } catch (Throwable $e) {} }
                }
                $pdo->prepare("UPDATE bd_tasks SET pen_state = 'waived' WHERE id = ?")->execute([$task['id']]); $msg = 'Appeal accepted. Penalty cancelled.';
                bd_push($pdo, $task['owner_id'], 'Appeal accepted', 'Your appeal for "' . $task['title'] . '" was accepted.');
            } elseif ($act === 'reject') {
                $back = !empty($task['pen_id']) ? 'applied' : (bd_mode() === 'live' ? 'pending' : 'shadow');
                $pdo->prepare("UPDATE bd_tasks SET pen_state = ? WHERE id = ?")->execute([$back, $task['id']]); $msg = 'Appeal declined.';
                bd_push($pdo, $task['owner_id'], 'Appeal declined', 'Your appeal for "' . $task['title'] . '" was not accepted.');
                if ($back === 'pending') bd_inbox('penalty', bd_emp_name($pdo, $task['owner_id']) . ' missed: ' . $task['title'], 'Appeal declined. Penalty ' . bd_inr($task['pen_amount']), ['task_id' => $task['id']], (int)$task['section_id'], null, 'pen2:' . $task['id']);
            } else json_response(['error' => 'Choose approve or reject.'], 422);
        } elseif ($it['kind'] === 'ai_review') {
            if (!$task) json_response(['error' => 'Task is gone.'], 404);
            if ($act === 'approve') { $pdo->prepare("UPDATE bd_tasks SET check_state = 'ok', checked_by = ? WHERE id = ?")->execute([$w['emp'] ?: null, $task['id']]); $msg = 'Photo accepted.'; }
            elseif ($act === 'reject') {
                $pdo->prepare("UPDATE bd_tasks SET check_state = 'rejected', status = 'open', attempts = 0, proof_path = NULL, checked_by = ? WHERE id = ?")->execute([$w['emp'] ?: null, $task['id']]); $msg = 'Sent back. The person must take a new photo.';
                bd_push($pdo, $task['owner_id'], 'Photo not accepted', '"' . $task['title'] . '" needs a new photo.');
            } else json_response(['error' => 'Choose approve or reject.'], 422);
        } elseif ($it['kind'] === 'uncovered') {
            $emp = (int)($in['emp_id'] ?? 0); if (!$emp || !$task) json_response(['error' => 'Choose a person.'], 422);
            if (bd_away($pdo, $emp, $task['date']) !== '') json_response(['error' => bd_emp_name($pdo, $emp) . ' is away today.'], 409);
            $pdo->prepare("UPDATE bd_tasks SET owner_emp = ?, status = 'open', cover_why = ? WHERE id = ?")->execute([$emp, 'given by ' . $by, $task['id']]); $msg = 'Given to ' . bd_emp_name($pdo, $emp) . '.';
            bd_push($pdo, $emp, 'New duty for you', $task['title']);
        } elseif ($it['kind'] === 'season') {
            $made = 0; $secs = []; foreach ($pdo->query("SELECT id, code FROM bd_sections")->fetchAll() as $s) $secs[$s['code']] = (int)$s['id'];
            foreach (($p['plan'] ?? []) as $pl) {
                $sid = $secs[$pl['section']] ?? 0; if (!$sid) continue;
                $c = bd_clean_duty(['title' => $pl['title'], 'freq' => 'once', 'due_time' => '12:00', 'proof' => $pl['proof'], 'photo_prompt' => $pl['prompt'], 'why' => 'Festival plan']);
                $did = bd_save_duty($pdo, $sid, bd_section_seat($pdo, $sid, 'worker'), $c, 'season', 'live', $by);
                $pdo->prepare("UPDATE bd_duties SET on_date = ?, grace_min = 240 WHERE id = ?")->execute([$pl['date'], $did]); $made++;
            }
            $msg = $made . ' one-time duties created.';
        } elseif ($it['kind'] === 'proposal') {
            foreach (($p['duty_ids'] ?? []) as $did) $pdo->prepare("UPDATE bd_duties SET status = 'live' WHERE id = ? AND status = 'proposed'")->execute([(int)$did]);
            $msg = 'Duties are live.';
        } else { $close = 'done'; }
        $pdo->prepare("UPDATE bd_inbox SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?")->execute([$close, bd_now(), $by, $id]);
        json_response(['ok' => true, 'message' => $msg]);
    }

    case 'bd.sections': {
        $o = [];
        foreach ($pdo->query("SELECT * FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) {
            if (!bd_can_section($w, $s['id'])) continue;
            $seats = [];
            foreach ($pdo->query("SELECT * FROM bd_seats WHERE section_id = " . (int)$s['id'] . " AND active = 1 ORDER BY FIELD(kind,'section_boss','checker','worker'), id")->fetchAll() as $seat) {
                $hs = []; foreach (bd_holders($pdo, (int)$seat['id']) as $e) $hs[] = ['id' => $e, 'name' => bd_emp_name($pdo, $e)];
                $seats[] = ['id' => (int)$seat['id'], 'title' => $seat['title'], 'kind' => $seat['kind'], 'holders' => $hs, 'needs_perm' => $seat['needs_perm']];
            }
            $c = $pdo->query("SELECT SUM(status='live') l, SUM(status='proposed') p FROM bd_duties WHERE section_id = " . (int)$s['id'])->fetch();
            $o[] = ['id' => (int)$s['id'], 'code' => $s['code'], 'name' => $s['name'], 'about' => $s['about'], 'source' => $s['source'], 'state' => $s['state'], 'seats' => $seats, 'live' => (int)$c['l'], 'proposed' => (int)$c['p'], 'dept_ids' => $s['dept_ids']];
        }
        json_response(['sections' => $o]);
    }

    case 'bd.duties': {
        $where = ['d.status != \'retired\'', bd_scope_sql($w, 'd.section_id')]; $args = [];
        if (!empty($_GET['section_id'])) { $where[] = 'd.section_id = ?'; $args[] = (int)$_GET['section_id']; }
        if (!empty($_GET['status'])) { $where[] = 'd.status = ?'; $args[] = (string)$_GET['status']; }
        $st = $pdo->prepare("SELECT d.*, s.name AS sec_name FROM bd_duties d JOIN bd_sections s ON s.id = d.section_id WHERE " . implode(' AND ', $where) . " ORDER BY s.name, d.due_time, d.id");
        $st->execute($args); $o = []; foreach ($st->fetchAll() as $d) $o[] = bd_duty_view($pdo, $d);
        json_response(['duties' => $o, 'mode' => bd_mode()]);
    }

    case 'bd.duty_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $id = (int)($in['id'] ?? 0); $sid = (int)($in['section_id'] ?? 0);
        if (!$sid || !bd_can_section($w, $sid)) json_response(['error' => 'Choose one of your sections.'], 403);
        $c = bd_clean_duty($in); if ($c['title'] === '' || $c['title'] === 'Untitled') json_response(['error' => 'Give the duty a name.'], 422);
        if ($c['freq'] === 'weekly' && $c['dow'] === '') $c['dow'] = '1';
        $seat = (int)($in['seat_id'] ?? 0);
        if ($seat) { $sr = bd_seat_row($pdo, $seat); if (!$sr || ((int)$sr['section_id'] !== $sid && $sr['section_id'] !== null)) json_response(['error' => 'That seat is in another section.'], 422); }
        if ($id) { $old = bd_duty_row($pdo, $id); if (!$old || !bd_can_section($w, $old['section_id'])) json_response(['error' => 'Not found.'], 404); }
        $newId = bd_save_duty($pdo, $sid, $seat, $c, $id ? ($old['source']) : 'manual', 'proposed', $by, $id);
        $extra = [];
        if (isset($in['grace_min'])) { $extra['grace_min'] = max(0, min(600, (int)$in['grace_min'])); }
        $am = in_array($in['assign_mode'] ?? '', ['first', 'rotate', 'all'], true) ? $in['assign_mode'] : null; if ($am) $extra['assign_mode'] = $am;
        $pm = in_array($in['pen_mode'] ?? '', ['approve', 'auto'], true) ? $in['pen_mode'] : null; if ($pm) $extra['pen_mode'] = $pm;
        if ($c['freq'] === 'once') $extra['on_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['on_date'] ?? '')) ? $in['on_date'] : bd_today();
        if (($in['adapter'] ?? '') !== '' && in_array($in['adapter'], ['flyers', 'punch'], true) && $c['proof'] === 'auto') $extra['adapter'] = $in['adapter'];
        foreach ($extra as $k => $v) $pdo->prepare("UPDATE bd_duties SET $k = ? WHERE id = ?")->execute([$v, $newId]);
        if ($id) { // an edit of a live duty goes back for approval only if the money or the proof changed
            $pdo->prepare("UPDATE bd_duties SET status = ? WHERE id = ?")->execute([$old['status'] === 'live' && (float)$old['pen_amount'] === (float)$c['pen_amount'] && $old['proof'] === $c['proof'] ? 'live' : ($old['status'] === 'retired' ? 'proposed' : $old['status']), $newId]);
        }
        bd_log('duty', $newId, ($id ? 'edited: ' : 'created: ') . $c['title'], $by);
        json_response(['ok' => true, 'id' => $newId, 'duty' => bd_duty_view($pdo, bd_duty_row($pdo, $newId))]);
    }

    case 'bd.duty_status': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $ids = array_map('intval', (array)($in['ids'] ?? [])); $to = (string)($in['status'] ?? '');
        if (!in_array($to, ['live', 'paused', 'proposed', 'retired'], true) || !$ids) json_response(['error' => 'Choose duties and a status.'], 422);
        $n = 0;
        foreach ($ids as $id) {
            $d = bd_duty_row($pdo, $id); if (!$d || !bd_can_section($w, $d['section_id'])) continue;
            if ($to === 'live' && !(int)bd_cfg_fresh('top_boss') && !$w['admin']) continue;
            $pdo->prepare("UPDATE bd_duties SET status = ?, updated_at = ? WHERE id = ?")->execute([$to, bd_now(), $id]); $n++; bd_log('duty', $id, $d['title'] . ' -> ' . $to, $by);
        }
        if ($to === 'live') bd_tick($pdo, true);
        json_response(['ok' => true, 'changed' => $n]);
    }

    case 'bd.library': {
        $lib = bd_library(); $have = [];
        foreach ($pdo->query("SELECT d.lib_key, s.code FROM bd_duties d JOIN bd_sections s ON s.id = d.section_id WHERE d.lib_key IS NOT NULL")->fetchAll() as $r) $have[$r['lib_key'] . '|' . $r['code']] = 1;
        $bySec = [];
        foreach ($lib as $k => $d) $bySec[$d['section']][] = ['key' => $k, 'title' => $d['title'], 'freq' => $d['freq'], 'due' => $d['due'], 'proof' => $d['proof'], 'pen' => $d['pen'], 'installed' => isset($have[$k . '|' . $d['section']])];
        json_response(['library' => $bySec]);
    }
    case 'bd.library_install': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $codes = array_map('strval', (array)($in['sections'] ?? []));
        $n = bd_install_library($pdo, $codes, $by); bd_log('library', '', $n . ' duties added as proposals', $by);
        json_response(['ok' => true, 'added' => $n, 'message' => $n . ' duties added. They are PROPOSALS: nothing runs until you approve them.']);
    }

    case 'bd.org': {
        $emps = bd_employees($pdo); $secs = [];
        foreach ($pdo->query("SELECT * FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) {
            $row = ['id' => (int)$s['id'], 'name' => $s['name'], 'code' => $s['code'], 'about' => $s['about']];
            foreach (['boss' => 'section_boss', 'checker' => 'checker', 'worker' => 'worker'] as $k => $kind) { $sd = bd_section_seat($pdo, (int)$s['id'], $kind); $row[$k . '_seat'] = $sd; $row[$k] = $sd ? bd_holders($pdo, $sd) : []; }
            $secs[] = $row;
        }
        json_response(['top_boss' => (int)bd_cfg_fresh('top_boss') ?: null, 'deputy' => (int)bd_cfg_fresh('deputy') ?: null, 'employees' => $emps, 'sections' => $secs, 'admin' => $w['admin']]);
    }
    case 'bd.org_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        if (isset($in['top_boss'])) { $v = (int)$in['top_boss']; bd_set('top_boss', $v ?: ''); bd_set_holders($pdo, bd_seat_id($pdo, 'top.boss'), $v ? [$v] : []); }
        if (isset($in['deputy'])) { $v = (int)$in['deputy']; bd_set('deputy', $v ?: ''); bd_set_holders($pdo, bd_seat_id($pdo, 'top.deputy'), $v ? [$v] : []); }
        foreach ((array)($in['sections'] ?? []) as $s) {
            $sid = (int)($s['id'] ?? 0); if (!$sid) continue;
            foreach (['boss' => 'section_boss', 'checker' => 'checker', 'worker' => 'worker'] as $k => $kind) {
                if (!array_key_exists($k, $s)) continue; $seat = bd_section_seat($pdo, $sid, $kind); if ($seat) bd_set_holders($pdo, $seat, (array)$s[$k]);
            }
            $pdo->prepare("UPDATE bd_sections SET state = 'ready' WHERE id = ? AND state = 'new'")->execute([$sid]);
        }
        bd_log('org', '', 'chain of command saved', $by);
        json_response(['ok' => true, 'collisions' => count(bd_collisions($pdo))]);
    }
    case 'bd.seat_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $sid = (int)($in['section_id'] ?? 0); $title = trim((string)($in['title'] ?? '')); if (!$sid || $title === '') json_response(['error' => 'Section and seat name are required.'], 422);
        $s = $pdo->prepare("SELECT code FROM bd_sections WHERE id = ?"); $s->execute([$sid]); $code = $s->fetchColumn(); if (!$code) json_response(['error' => 'Section not found.'], 404);
        $c = $code . '.' . bd_slug($title); $n = 1; while (bd_seat_id($pdo, $c . ($n > 1 ? $n : ''))) $n++;
        $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, needs_perm, created_at) VALUES (?,?,?,?,?,?)")->execute([$sid, $c . ($n > 1 ? $n : ''), mb_substr($title, 0, 120), 'worker', mb_substr(trim((string)($in['needs_perm'] ?? '')), 0, 200) ?: null, bd_now()]);
        $seatId = (int)$pdo->lastInsertId(); if (!empty($in['holders'])) bd_set_holders($pdo, $seatId, (array)$in['holders']);
        bd_log('seat', $seatId, 'created seat ' . $title, $by);
        json_response(['ok' => true, 'seat_id' => $seatId]);
    }
    case 'bd.holders_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $seat = bd_seat_row($pdo, (int)($in['seat_id'] ?? 0)); if (!$seat) json_response(['error' => 'Seat not found.'], 404);
        bd_set_holders($pdo, (int)$seat['id'], (array)($in['emps'] ?? [])); bd_log('seat', $seat['id'], 'holders of ' . $seat['title'] . ' changed', $by); json_response(['ok' => true]);
    }
    case 'bd.section_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $name = trim((string)($in['name'] ?? '')); if ($name === '') json_response(['error' => 'Give the section a name.'], 422);
        $id = (int)($in['id'] ?? 0);
        if ($id) { $pdo->prepare("UPDATE bd_sections SET name = ?, about = ?, active = ? WHERE id = ?")->execute([mb_substr($name, 0, 120), mb_substr(trim((string)($in['about'] ?? '')), 0, 250), isset($in['active']) ? (int)(bool)$in['active'] : 1, $id]); json_response(['ok' => true, 'id' => $id]); }
        $code = 'c_' . bd_slug($name); $n = 1; $base = $code; while ($pdo->query("SELECT 1 FROM bd_sections WHERE code = " . $pdo->quote($code))->fetchColumn()) $code = $base . (++$n);
        $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, mb_substr($name, 0, 120), mb_substr(trim((string)($in['about'] ?? '')), 0, 250), '[]', 'manual', 'new', bd_now()]);
        $nid = (int)$pdo->lastInsertId(); bd_make_seats($pdo, $nid, $code, $name); bd_log('section', $nid, 'created ' . $name, $by); json_response(['ok' => true, 'id' => $nid]);
    }

    case 'bd.interview_start': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $sid = (int)($in['section_id'] ?? 0); if (!$sid || !bd_can_section($w, $sid)) json_response(['error' => 'Choose a section.'], 403);
        $s = $pdo->prepare("SELECT * FROM bd_sections WHERE id = ?"); $s->execute([$sid]); $sec = $s->fetch(); if (!$sec) json_response(['error' => 'Section not found.'], 404);
        $q = $pdo->prepare("SELECT * FROM bd_interview WHERE section_id = ? AND status = 'asking' ORDER BY id DESC LIMIT 1"); $q->execute([$sid]); $iv = $q->fetch();
        if (!$iv) { $pdo->prepare("INSERT INTO bd_interview (section_id, answers, created_by, created_at) VALUES (?,?,?,?)")->execute([$sid, '{}', $by, bd_now()]); $iv = ['id' => (int)$pdo->lastInsertId(), 'answers' => '{}', 'follow_ups' => null]; }
        json_response(['id' => (int)$iv['id'], 'section' => $sec['name'], 'questions' => bd_questions($sec['name']), 'answers' => bd_unjson($iv['answers']), 'follow_ups' => bd_unjson($iv['follow_ups']), 'ai' => function_exists('convo_ai')]);
    }
    case 'bd.interview_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $iv = $pdo->prepare("SELECT * FROM bd_interview WHERE id = ?"); $iv->execute([(int)($in['id'] ?? 0)]); $iv = $iv->fetch(); if (!$iv || !bd_can_section($w, $iv['section_id'])) json_response(['error' => 'Not found.'], 404);
        $a = bd_unjson($iv['answers']); foreach ((array)($in['answers'] ?? []) as $k => $v) $a[preg_replace('/[^a-z_0-9]/', '', (string)$k)] = mb_substr((string)$v, 0, 2000);
        $pdo->prepare("UPDATE bd_interview SET answers = ?, updated_at = ? WHERE id = ?")->execute([bd_json($a), bd_now(), $iv['id']]); json_response(['ok' => true]);
    }
    case 'bd.interview_draft': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $iv = $pdo->prepare("SELECT * FROM bd_interview WHERE id = ?"); $iv->execute([(int)($in['id'] ?? 0)]); $iv = $iv->fetch(); if (!$iv || !bd_can_section($w, $iv['section_id'])) json_response(['error' => 'Not found.'], 404);
        $sec = $pdo->prepare("SELECT * FROM bd_sections WHERE id = ?"); $sec->execute([$iv['section_id']]); $sec = $sec->fetch();
        $a = bd_unjson($iv['answers']); if (trim(implode('', $a)) === '') json_response(['error' => 'Answer at least the daily question first.'], 422);
        $used = 'ai'; $draft = bd_ai_draft($pdo, $sec['name'], $a); if (!$draft) { $used = 'rules'; $draft = bd_rule_draft($a); }
        if (empty($draft['duties'])) json_response(['error' => 'I could not find any duties in your answers. Write each task on its own line under "every day".'], 422);
        $key = 'iv' . (int)$iv['id'];
        $pdo->prepare("DELETE FROM bd_duties WHERE lib_key = ? AND status = 'proposed'")->execute([$key]);
        $seat = bd_section_seat($pdo, (int)$sec['id'], 'worker'); $ids = [];
        foreach ($draft['duties'] as $d) {
            $c = bd_clean_duty((array)$d); $did = bd_save_duty($pdo, (int)$sec['id'], $seat, $c, 'interview', 'proposed', $by);
            $pdo->prepare("UPDATE bd_duties SET lib_key = ? WHERE id = ?")->execute([$key, $did]); $ids[] = $did;
        }
        $pdo->prepare("UPDATE bd_interview SET status = 'drafted', follow_ups = ?, updated_at = ? WHERE id = ?")->execute([bd_json((array)($draft['follow_ups'] ?? [])), bd_now(), $iv['id']]);
        $pdo->prepare("UPDATE bd_sections SET state = 'drafted' WHERE id = ? AND state = 'new'")->execute([$sec['id']]);
        bd_log('interview', $iv['id'], count($ids) . ' duties drafted for ' . $sec['name'] . ' (' . $used . ')', $by);
        $rows = []; foreach ($ids as $did) $rows[] = bd_duty_view($pdo, bd_duty_row($pdo, $did));
        json_response(['ok' => true, 'used' => $used, 'summary' => (string)($draft['summary'] ?? ''), 'follow_ups' => array_values((array)($draft['follow_ups'] ?? [])), 'duties' => $rows]);
    }

    case 'bd.collisions': { json_response(['items' => bd_collisions($pdo)]); }
    case 'bd.scan': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); json_response(['ok' => true, 'result' => bd_scan($pdo, $by)]); }

    case 'bd.match': { $m = bd_match($pdo, (int)($_GET['emp_id'] ?? 0)); if (!$m) json_response(['error' => 'Employee not found.'], 404); json_response($m); }
    case 'bd.match_apply': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $emp = (int)($in['emp_id'] ?? 0); if (!bd_emp($pdo, $emp)) json_response(['error' => 'Employee not found.'], 404);
        $seat = (int)($in['seat_id'] ?? 0);
        if (!$seat && !empty($in['new']) && is_array($in['new'])) {
            $sid = (int)($in['new']['section_id'] ?? 0); $title = trim((string)($in['new']['title'] ?? '')); if (!$sid || $title === '') json_response(['error' => 'Section and role name are required.'], 422);
            if (!bd_can_section($w, $sid)) json_response(['error' => 'Not your section.'], 403);
            $code = $pdo->prepare("SELECT code FROM bd_sections WHERE id = ?"); $code->execute([$sid]); $code = $code->fetchColumn(); if (!$code) json_response(['error' => 'Section not found.'], 404);
            $c = $code . '.' . bd_slug($title); $n = 1; while (bd_seat_id($pdo, $c . ($n > 1 ? $n : ''))) $n++;
            $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([$sid, $c . ($n > 1 ? $n : ''), mb_substr($title, 0, 120), 'worker', bd_now()]);
            $seat = (int)$pdo->lastInsertId(); bd_log('seat', $seat, 'new role created for a new joiner: ' . $title, $by);
        }
        $sr = $seat ? bd_seat_row($pdo, $seat) : null; if (!$sr) json_response(['error' => 'Choose a seat.'], 422);
        if ($sr['section_id'] && !bd_can_section($w, $sr['section_id'])) json_response(['error' => 'Not your section.'], 403);
        $cur = bd_holders($pdo, $seat); if (!in_array($emp, $cur, true)) $cur[] = $emp;
        if (!empty($in['primary'])) { $cur = array_values(array_diff($cur, [$emp])); array_unshift($cur, $emp); }
        bd_set_holders($pdo, $seat, $cur); bd_log('seat', $seat, bd_emp_name($pdo, $emp) . ' added to ' . $sr['title'], $by);
        $pdo->prepare("UPDATE bd_inbox SET status = 'done', resolved_at = ?, resolved_by = ? WHERE ref_key = ?")->execute([bd_now(), $by, 'join:' . $emp]);
        json_response(['ok' => true, 'seat_id' => $seat, 'message' => bd_emp_name($pdo, $emp) . ' now holds "' . $sr['title'] . '". Their duties appear tomorrow morning (or press Run now).']);
    }

    case 'bd.penalties': {
        $from = date('Y-m-d', strtotime('-30 days'));
        $rows = bd_tasks_q($pdo, "t.pen_state != 'none' AND t.task_date >= ? AND " . bd_scope_sql($w, 't.section_id'), [$from]);
        $tot = ['shadow' => 0, 'pending' => 0, 'applied' => 0, 'waived' => 0, 'appealed' => 0, 'capped' => 0];
        foreach ($rows as $r) if (isset($tot[$r['pen_state']])) $tot[$r['pen_state']] += $r['pen_amount'];
        usort($rows, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        json_response(['rows' => $rows, 'totals' => $tot, 'mode' => bd_mode()]);
    }

    case 'bd.seasons': { json_response(['seasons' => $pdo->query("SELECT * FROM bd_seasons ORDER BY event_date")->fetchAll()]); }
    case 'bd.season_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $name = trim((string)($in['name'] ?? '')); $date = (string)($in['event_date'] ?? ''); if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_response(['error' => 'Festival name and date are required.'], 422);
        $code = bd_slug($name);
        $pdo->prepare("INSERT INTO bd_seasons (code, name, event_date, lead_days) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), lead_days = VALUES(lead_days)")->execute([$code, mb_substr($name, 0, 80), $date, max(3, min(60, (int)($in['lead_days'] ?? 14)))]);
        json_response(['ok' => true]);
    }
    case 'bd.season_del': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth); $pdo->prepare("DELETE FROM bd_seasons WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true]); }

    case 'bd.settings': {
        $keys = ['mode', 'ai_gate', 'max_retakes', 'task_cap', 'pen_cap_day', 'pen_cap_month', 'remind_min', 'away_after_min', 'digest_hour', 'pages_dir'];
        if (!$isPost) { $o = []; foreach ($keys as $k) $o[$k] = bd_cfg_fresh($k); json_response(['settings' => $o, 'tick_key' => $w['admin'] ? bd_cfg_fresh('tick_key') : '', 'ai_available' => function_exists('convo_ai'), 'admin' => $w['admin']]); }
        bd_need_admin($auth);
        if (isset($in['mode'])) {
            if ((string)$auth['role'] !== 'Admin') json_response(['error' => 'Only the Admin can switch the mode.'], 403);
            $m = (string)$in['mode']; if (!in_array($m, ['shadow', 'soft', 'live'], true)) json_response(['error' => 'Bad mode.'], 422);
            if ($m === 'live' && empty($in['confirm'])) json_response(['error' => 'Confirm that you want real penalties switched on.'], 422);
            $old = bd_mode(); bd_set('mode', $m); bd_log('mode', '', $old . ' -> ' . $m, $by);
        }
        $num = ['max_retakes' => [1, 10], 'task_cap' => [1, 40], 'pen_cap_day' => [0, 100000], 'pen_cap_month' => [0, 1000000], 'remind_min' => [5, 240], 'away_after_min' => [15, 600], 'digest_hour' => [0, 23]];
        foreach ($num as $k => $r) if (isset($in[$k])) bd_set($k, max($r[0], min($r[1], (int)$in[$k])));
        if (isset($in['ai_gate'])) bd_set('ai_gate', !empty($in['ai_gate']) ? '1' : '0');
        if (isset($in['pages_dir'])) { $d = trim((string)$in['pages_dir']); if ($d === '' || is_dir($d)) bd_set('pages_dir', rtrim($d, '/')); else json_response(['error' => 'That folder does not exist on the server.'], 422); }
        json_response(['ok' => true, 'mode' => bd_mode()]);
    }

    case 'bd.duty_ref': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $d = bd_duty_row($pdo, (int)($_POST['duty_id'] ?? 0)); if (!$d || !bd_can_section($w, $d['section_id'])) json_response(['error' => 'Not found.'], 404);
        if (empty($_FILES['photo']) || (int)$_FILES['photo']['error'] !== UPLOAD_ERR_OK) json_response(['error' => 'Choose the example photo.'], 422);
        list($fn, $err) = bd_save_photo_file($_FILES['photo']['tmp_name'], 0); if (!$fn) json_response(['error' => $err], 422);
        $fn = 'ref_' . (int)$d['id'] . '_' . substr($fn, -12); @rename(bd_dir() . basename($fn === '' ? '' : $fn), bd_dir() . $fn);
        $pdo->prepare("UPDATE bd_duties SET ref_photo = ? WHERE id = ?")->execute([$fn, $d['id']]);
        json_response(['ok' => true]);
    }
    case 'bd.log': { bd_need_admin($auth); json_response(['log' => $pdo->query("SELECT at, actor, kind, ref, detail FROM bd_log ORDER BY id DESC LIMIT 200")->fetchAll()]); }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in the Boss Engine: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}

/* when api.php loads this file for an ordinary request, keep the engine ticking quietly after the reply is sent */
if (strpos((string)$action, 'bd.') !== 0) { bd_tick_after($pdo); }