<?php
/**
 * boss2.php — Boss Engine 2.   Lives next to boss.php / api.php (empmanagment/).  PHP 7.4 safe.
 *
 *  ONE picture: every piece of work is an ITEM with ONE owner seat and ONE clock.
 *    8 kinds of item  =  3 building blocks
 *      repeat : daily, temp                      (a RUN is made for every working day)
 *      once   : short, followup, fix, buy, improve
 *      number : number                           (a RUN with a typed number + screenshot proof)
 *  Problems (told by anyone, on any panel) go through a pipeline: record > understand > cause > options > route > you decide > work + watch.
 *  Penalties are only ever ASKED here; the boss's yes (and the safety limits) decide.  Nothing is charged by itself.
 *  The Build-system list holds what must be built before a task can run.
 *
 *  Endpoints are bd.b2_*  (routed from boss.php).   Tables are b2_*   (created and upgraded by itself, no installer to run).
 *  Reuses from boss.php / dept.php:  seats, holders, away, push, inbox, section list, penalty engine, department jobs (dp_items, read-only).
 *  Works with no AI key too (a built-in reader does the same job from the words and the records); with a key Claude reads with AI.
 */
if (!function_exists('json_response') || !function_exists('bd_now')) { return; }
if (defined('B2_LOADED')) { return; }
define('B2_LOADED', 1);

/* ============================== small helpers ============================== */
function b2_short($t, $n = 140) { $t = trim(preg_replace('/\s+/u', ' ', (string)$t)); return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t; }
function b2_day($ts = null) { return date('Y-m-d', $ts === null ? time() : $ts); }
function b2_dow($date) { return (int)date('N', strtotime($date)); }        // 1 Mon .. 7 Sun
function b2_ago($dt) { if (!$dt) return ''; $s = time() - strtotime($dt); if ($s < 3600) return max(1, (int)floor($s / 60)) . ' min'; if ($s < 86400) return (int)floor($s / 3600) . ' h'; return (int)floor($s / 86400) . ' d'; }
function b2_t12($hhmm) { if (!preg_match('/^(\d\d):(\d\d)/', (string)$hhmm, $m)) return (string)$hhmm; $h = (int)$m[1]; return (($h + 11) % 12 + 1) . ':' . $m[2] . ' ' . ($h >= 12 ? 'PM' : 'AM'); }
function b2_inr($v) { return '₹' . number_format((float)$v, 0, '.', ','); }
function b2_num($v) { $v = (float)$v; return $v == floor($v) ? number_format($v, 0, '.', ',') : rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.'); }
function b2_row(PDO $pdo, $table, $id) { $st = $pdo->prepare("SELECT * FROM $table WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function b2_in_ids($ids) { return bd_in($ids); }
function b2_log($kind, $ref, $detail, $actor = 'Boss Engine 2') { bd_log($kind, $ref, $detail, $actor); }

/* ============================== kinds ============================== */
function b2_kinds() {
    // key => [label, block, plain words, default proof, default due offset (days, once only)]
    return [
        'daily'    => ['Daily',     'repeat', 'Something done every working day.',                'tick',   0],
        'number'   => ['Number',    'number', 'A number someone enters every working day.',       'number', 0],
        'short'    => ['Short',     'once',   'A small job with one deadline.',                   'tick',   2],
        'temp'     => ['Temporary', 'repeat', 'A daily job for a limited number of days.',        'tick',   0],
        'followup' => ['Follow-up', 'once',   'Chase something or someone until it is answered.', 'text',   2],
        'fix'      => ['To fix',    'once',   'Something broken or wrong that must be repaired.', 'photo',  3],
        'buy'      => ['To buy',    'once',   'Something that must be bought.',                   'text',   2],
        'improve'  => ['Improve',   'once',   'Make one thing better; closes after 7 good days in a row.', 'tick', 0],
    ];
}
function b2_kind_label($k) { $d = b2_kinds(); return isset($d[$k]) ? $d[$k][0] : ucfirst((string)$k); }
function b2_block_of($k) { $d = b2_kinds(); return isset($d[$k]) ? $d[$k][1] : 'once'; }
function b2_proofs() { return ['tick' => 'Tap done', 'photo' => 'Photo', 'number' => 'Number + screenshot', 'text' => 'A few words', 'screenshot' => 'Screenshot', 'auto' => 'System checks it']; }

/* ============================== settings and state ============================== */
function b2_defaults() {
    return [
        'mode'            => 'on',          // on | off   (off = the whole engine only watches)
        'live_sections'   => '',            // CSV of section codes whose staff see their tasks (others are in shadow mode)
        'cutoff'          => '19:00',       // default cut-off for a task
        'cap_person'      => '2000',        // most one person can be charged by Boss Engine 2 in a month (Rs)
        'cap_total'       => '2000',        // most all people together can be charged in a month (Rs)
        'fair_days'       => '14',          // no penalty asks for the first N days of a new task
        'slip_miss'       => '3',           // daily task is slipping when missed this many times in 7 days
        'slip_under'      => '5',           // number task is slipping when under target on this many of 7 days
        'improve_days'    => '7',           // good days in a row that close an Improve task
        'watch_days'      => '30',          // clean days before a solved problem is closed
        'cap_daily'       => '6',           // most daily tasks one person (or one team seat) can own; a new one asks the boss to merge, pause or move one
        'scan'            => '1',           // 1 = the daily scan reads the system numbers and raises a problem when one is bad
        'scan_hour'       => '11',          // the scan runs once a day, at or after this hour
        'target_bump'     => '10',          // proposed number target = last-14-days average + this %
        'pilot'           => '0',           // 1 = Claude reads typed-number screenshots and only flags a difference
        'trust'           => '0',           // trust ladder (off)
        'deputy_name'     => '',
        'ai'              => '1',
        'installed_at'    => '',
    ];
}
function b2_cfg($k) { $v = bd_cfg_fresh('b2_' . $k); if ($v === '' || $v === null || $v === false) { $d = b2_defaults(); return $d[$k] ?? ''; } return $v; }
function b2_set($k, $v) { bd_set('b2_' . $k, (string)$v); }
function b2_live_sections() { $s = (string)b2_cfg('live_sections'); $o = []; foreach (explode(',', $s) as $c) { $c = trim($c); if ($c !== '') $o[$c] = 1; } return array_keys($o); }
function b2_section_live($code) { return b2_cfg('mode') === 'on' && in_array((string)$code, b2_live_sections(), true); }
function b2_state_get(PDO $pdo, $k, $d = '') { try { $st = $pdo->prepare("SELECT svalue FROM b2_state WHERE skey = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : $v; } catch (Throwable $e) { return $d; } }
function b2_state_put(PDO $pdo, $k, $v) { $pdo->prepare("INSERT INTO b2_state (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]); }

/* ============================== tables ============================== */
function b2_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    $need = ['b2_state', 'b2_items', 'b2_runs', 'b2_ev', 'b2_pens', 'b2_problems', 'b2_builds', 'b2_docs', 'b2_alerts'];
    $have = true;
    foreach ($need as $t) { try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); } catch (Throwable $e) { $have = false; break; } }
    if (!$have) {
        $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_state (skey VARCHAR(60) PRIMARY KEY, svalue MEDIUMTEXT NULL)$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_items (
            id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, block VARCHAR(8) NOT NULL DEFAULT 'once',
            title VARCHAR(200) NOT NULL, why VARCHAR(400) NULL, detail TEXT NULL, section_code VARCHAR(40) NULL,
            seat_id INT NULL, backup_seat_id INT NULL, checker_seat_id INT NULL,
            dow VARCHAR(20) NULL, dom TINYINT NULL, start_date DATE NOT NULL, end_date DATE NULL, due_date DATE NULL,
            start_time CHAR(5) NULL, due_time CHAR(5) NOT NULL DEFAULT '19:00', grace_min SMALLINT NOT NULL DEFAULT 0,
            proof VARCHAR(10) NOT NULL DEFAULT 'tick', adapter VARCHAR(20) NULL, photo_prompt VARCHAR(400) NULL,
            metric VARCHAR(40) NULL, unit VARCHAR(30) NULL, target DECIMAL(14,2) NULL, cmp VARCHAR(4) NOT NULL DEFAULT 'min', target_state VARCHAR(8) NOT NULL DEFAULT 'none',
            target_prop DECIMAL(14,2) NULL, home VARCHAR(80) NULL, pin TINYINT NOT NULL DEFAULT 0,
            pen_amount DECIMAL(10,2) NOT NULL DEFAULT 0, pen_note VARCHAR(200) NULL, fair_until DATE NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'draft', source VARCHAR(10) NOT NULL DEFAULT 'boss',
            problem_id INT NULL, build_id INT NULL, ref_item_id INT NULL, link_emp INT NULL, good_days SMALLINT NOT NULL DEFAULT 0, last_good DATE NULL,
            pct TINYINT NOT NULL DEFAULT 0, progress_every TINYINT NOT NULL DEFAULT 0,
            buy_amount DECIMAL(12,2) NULL, vendor VARCHAR(120) NULL, waiting_on VARCHAR(160) NULL,
            checks MEDIUMTEXT NULL, old_ref VARCHAR(40) NULL, blocked_by INT NULL,
            created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, closed_at DATETIME NULL,
            INDEX (status, kind), INDEX (section_code), INDEX (seat_id), INDEX (old_ref), INDEX (problem_id)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_runs (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, run_date DATE NOT NULL, owner_emp INT NULL, orig_emp INT NULL, cover VARCHAR(80) NULL,
            due_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', value DECIMAL(14,2) NULL, note VARCHAR(400) NULL,
            proof_path VARCHAR(160) NULL, proof_state VARCHAR(8) NOT NULL DEFAULT '', proof_why VARCHAR(220) NULL, phash CHAR(64) NULL,
            met TINYINT NULL, late TINYINT NOT NULL DEFAULT 0, pct TINYINT NULL, done_at DATETIME NULL, checked_by VARCHAR(80) NULL, checked_at DATETIME NULL,
            reminded TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            UNIQUE KEY u_run (item_id, run_date), INDEX (run_date, status), INDEX (owner_emp, run_date), INDEX (proof_state)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_ev (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NULL, run_id INT NULL, problem_id INT NULL, emp_id INT NULL, kind VARCHAR(12) NOT NULL,
            text VARCHAR(700) NULL, pct TINYINT NULL, created_at DATETIME NOT NULL, INDEX (item_id), INDEX (problem_id), INDEX (run_id)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_pens (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, run_id INT NULL, emp_id INT NOT NULL, section_code VARCHAR(40) NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0, reason VARCHAR(255) NULL, state VARCHAR(8) NOT NULL DEFAULT 'ask', note VARCHAR(400) NULL,
            decided_by VARCHAR(80) NULL, decided_at DATETIME NULL, penalty_id INT NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY u_pen (run_id), INDEX (state), INDEX (emp_id, created_at)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_problems (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NULL, by_emp INT NULL, by_name VARCHAR(80) NULL,
            section_code VARCHAR(40) NULL, page VARCHAR(80) NULL, src VARCHAR(10) NOT NULL DEFAULT 'boss', sev VARCHAR(5) NOT NULL DEFAULT 'med',
            step VARCHAR(8) NOT NULL DEFAULT 'asking', qa MEDIUMTEXT NULL, cause_level VARCHAR(10) NULL, cause_text TEXT NULL, evidence MEDIUMTEXT NULL, options MEDIUMTEXT NULL,
            route VARCHAR(10) NULL, route_ref VARCHAR(60) NULL, choice VARCHAR(10) NULL, closed_reason VARCHAR(300) NULL,
            parent_id INT NULL, again_n INT NOT NULL DEFAULT 0, watch_from DATE NULL, watch_until DATE NULL, old_ref VARCHAR(40) NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NULL, closed_at DATETIME NULL, INDEX (step), INDEX (section_code), INDEX (by_emp), INDEX (old_ref)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_builds (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, spec MEDIUMTEXT NULL, why VARCHAR(500) NULL, section_code VARCHAR(40) NULL, home VARCHAR(80) NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'spec', problem_id INT NULL, seed_key VARCHAR(30) NULL, note VARCHAR(500) NULL,
            created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, live_at DATETIME NULL, UNIQUE KEY u_seed (seed_key), INDEX (status)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_docs (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'licence', section_code VARCHAR(40) NULL, seat_id INT NULL,
            party VARCHAR(160) NULL, expires_on DATE NULL, ladder VARCHAR(60) NULL, notes VARCHAR(500) NULL, link VARCHAR(300) NULL,
            status VARCHAR(8) NOT NULL DEFAULT 'active', raised VARCHAR(60) NULL, old_ref VARCHAR(40) NULL,
            created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, INDEX (status, expires_on)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS b2_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(14) NOT NULL, text VARCHAR(400) NOT NULL, section_code VARCHAR(40) NULL, ref_key VARCHAR(80) NULL UNIQUE,
            state VARCHAR(6) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL, closed_at DATETIME NULL, INDEX (state)
        )$E");
    }
    // columns added after the first release go here (ADD COLUMN only when missing)
    b2_add_col($pdo, 'b2_items', 'dom', 'TINYINT NULL');
    b2_add_col($pdo, 'b2_runs', 'fhash', 'VARCHAR(256) NULL');
    b2_add_col($pdo, 'b2_problems', 'old_ref', 'VARCHAR(40) NULL');
    b2_add_col($pdo, 'b2_items', 'pen_mode', "VARCHAR(6) NOT NULL DEFAULT 'flat'");   // flat = one amount; unit = rate x items short
    b2_add_col($pdo, 'b2_items', 'pen_unit', 'DECIMAL(10,2) NULL');                   // rupees per item
    b2_add_col($pdo, 'b2_items', 'pen_min', 'DECIMAL(10,2) NULL');                    // least amount asked when the day is missed
    b2_add_col($pdo, 'b2_items', 'pen_max', 'DECIMAL(10,2) NULL');                    // most asked for one day
    b2_add_col($pdo, 'b2_items', 'pen_base', 'INT NULL');                             // the backlog the task started with: only growth above it is counted
    b2_add_col($pdo, 'b2_pens', 'calc', 'VARCHAR(200) NULL');                         // "4 short x Rs 40 = Rs 160"
    if (b2_state_get($pdo, 'seeded', '') === '') { b2_seed($pdo); b2_state_put($pdo, 'seeded', bd_now()); }
    if (b2_cfg('installed_at') === '') { b2_set('installed_at', bd_now()); b2_first_install($pdo); }
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['b2_state', 'b2_items', 'b2_runs', 'b2_ev', 'b2_pens', 'b2_problems', 'b2_builds', 'b2_docs', 'b2_alerts']);
}
function b2_add_col(PDO $pdo, $table, $col, $def) {
    try { $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"); $st->execute([$table, $col]); if ((int)$st->fetchColumn() === 0) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Throwable $e) {}
}

/* ============================== sections ============================== */
function b2_sections(PDO $pdo, $force = false) {
    static $c = null; if ($c !== null && !$force) return $c;
    $c = [];
    try { foreach ($pdo->query("SELECT id, code, name, about, active FROM bd_sections WHERE active = 1 ORDER BY id")->fetchAll() as $r) $c[$r['code']] = ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'about' => (string)$r['about']]; } catch (Throwable $e) {}
    return $c;
}
function b2_section_name(PDO $pdo, $code) { $s = b2_sections($pdo); return isset($s[$code]) ? $s[$code]['name'] : ($code !== '' && $code !== null ? ucfirst((string)$code) : 'General'); }
function b2_section_id(PDO $pdo, $code) { $s = b2_sections($pdo); return isset($s[$code]) ? $s[$code]['id'] : 0; }
function b2_section_code_of_id(PDO $pdo, $id) { foreach (b2_sections($pdo) as $s) if ($s['id'] === (int)$id) return $s['code']; return ''; }
function b2_can_section(array $w, $code, PDO $pdo) { return $w['admin'] || $w['top'] || in_array(b2_section_id($pdo, $code), $w['sections'], true); }
/** sections this person manages (all = null for admin/top boss) */
function b2_my_section_codes(PDO $pdo, array $w) { if ($w['admin'] || $w['top']) return null; $o = []; foreach ($w['sections'] as $id) { $c = b2_section_code_of_id($pdo, $id); if ($c !== '') $o[] = $c; } return $o; }
function b2_scope_sql(PDO $pdo, array $w, $col = 'section_code') {
    $codes = b2_my_section_codes($pdo, $w); if ($codes === null) return '1=1';
    if (!$codes) return '0=1';
    return "$col IN (" . implode(',', array_map(function ($c) use ($pdo) { return $pdo->quote($c); }, $codes)) . ")";
}

/* ============================== seats, owners ============================== */
/** a seat that belongs to ONE person (created on demand) */
function b2_person_seat(PDO $pdo, $empId) {
    $empId = (int)$empId; if ($empId <= 0) return 0;
    $code = 'p.' . $empId;
    $id = bd_seat_id($pdo, $code);
    if (!$id) { $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, created_at) VALUES (NULL,?,?,?,?)")->execute([$code, bd_emp_name($pdo, $empId), 'worker', bd_now()]); $id = (int)$pdo->lastInsertId(); }
    $h = $pdo->prepare("SELECT COUNT(*) FROM bd_holders WHERE seat_id = ?"); $h->execute([$id]);
    if ((int)$h->fetchColumn() === 0) bd_set_holders($pdo, $id, [$empId]);
    return $id;
}
function b2_seat_title(PDO $pdo, $seatId) {
    if (!$seatId) return '';
    $s = bd_seat_row($pdo, $seatId); if (!$s) return '';
    if (strpos((string)$s['code'], 'p.') === 0) return bd_emp_name($pdo, (int)substr($s['code'], 2));
    return (string)$s['title'];
}
function b2_seat_is_person($seat) { return $seat && strpos((string)$seat['code'], 'p.') === 0; }
function b2_seat_people(PDO $pdo, $seatId) { $o = []; foreach (bd_holders($pdo, $seatId) as $e) $o[] = ['id' => (int)$e, 'name' => bd_emp_name($pdo, $e)]; return $o; }
/** the person who must do this item on this date, and why somebody else is covering. Returns [emp, cover-reason, original-owner] */
function b2_owner(PDO $pdo, array $item, $date) {
    $first = 0; $skipped = [];
    $try = function ($seatId) use ($pdo, $date, &$first, &$skipped) {
        if (!$seatId) return 0;
        foreach (bd_holders($pdo, $seatId) as $e) { if (!$first) $first = (int)$e; $why = bd_away($pdo, $e, $date); if ($why === '') return (int)$e; $skipped[$e] = $why; }
        return 0;
    };
    $e = $try((int)$item['seat_id']);
    if ($e) return [$e, $e === $first ? '' : 'covering for ' . bd_emp_name($pdo, $first), $first];
    $e = $try((int)$item['backup_seat_id']);
    if ($e) return [$e, 'backup for ' . ($first ? bd_emp_name($pdo, $first) : 'the seat'), $first];
    $sec = b2_section_id($pdo, (string)$item['section_code']);
    if ($first && $sec) { list($fb, $label) = bd_fallback($pdo, ['section_id' => $sec, 'id' => 0], $date, array_keys($skipped)); if ($fb) return [(int)$fb, $label . ' cover for ' . bd_emp_name($pdo, $first), $first]; }
    return [0, $first ? 'everyone on the seat is away' : 'nobody holds the seat', $first];
}
/** why nobody can be found: whole company off (holiday/week off) is not a problem, anything else is */
function b2_everyone_off(PDO $pdo, $first, $date) { if (!$first) return false; $w = bd_away($pdo, $first, $date); return in_array($w, ['holiday', 'week off'], true); }

/* ============================== items: clean, save, view ============================== */
function b2_dow_label($dow) {
    $dow = trim((string)$dow); if ($dow === '' || $dow === '1,2,3,4,5,6') return 'Mon–Sat'; if ($dow === '1,2,3,4,5,6,7') return 'Every day';
    $n = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; $o = [];
    foreach (explode(',', $dow) as $d) { $d = (int)$d; if ($d >= 1 && $d <= 7) $o[] = $n[$d]; }
    return $o ? implode(', ', $o) : 'Mon–Sat';
}
function b2_clean_dow($v) { $o = []; foreach (preg_split('/[,\s]+/', (string)$v) as $d) { $d = (int)$d; if ($d >= 1 && $d <= 7) $o[$d] = 1; } $k = array_keys($o); sort($k); return $k ? implode(',', $k) : '1,2,3,4,5,6'; }
function b2_clean_time($v, $def) { $v = trim((string)$v); if (preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]); return $def; }
function b2_clean_date($v, $def = null) { $v = trim((string)$v); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) return $v; return $def; }
function b2_metric_slug($s) { return substr(bd_slug($s), 0, 40); }

/** validate and normalise an item from the page / Claude. $by = login name. Returns [clean, error] */
function b2_item_clean(PDO $pdo, array $in, $existing = null) {
    $kinds = b2_kinds(); $c = [];
    $kind = (string)($in['kind'] ?? ($existing['kind'] ?? 'short'));
    if (!isset($kinds[$kind])) return [null, 'Choose what kind of task this is.'];
    $c['kind'] = $kind; $c['block'] = $kinds[$kind][1];
    $title = trim((string)($in['title'] ?? ($existing['title'] ?? '')));
    if (mb_strlen($title) < 3) return [null, 'Give the task a short name (at least 3 letters).'];
    $c['title'] = mb_substr($title, 0, 200);
    $c['why'] = mb_substr(trim((string)($in['why'] ?? ($existing['why'] ?? ''))), 0, 400);
    $c['detail'] = mb_substr(trim((string)($in['detail'] ?? ($existing['detail'] ?? ''))), 0, 2000);
    $sec = (string)($in['section_code'] ?? ($existing['section_code'] ?? ''));
    if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
    $c['section_code'] = $sec;
    // owner: a person (becomes a person seat) or a team seat
    $seat = (int)($in['seat_id'] ?? ($existing['seat_id'] ?? 0));
    if (!empty($in['emp_id'])) $seat = b2_person_seat($pdo, (int)$in['emp_id']);
    if ($seat && !bd_seat_row($pdo, $seat)) $seat = 0;
    $c['seat_id'] = $seat ?: null;
    $bk = (int)($in['backup_seat_id'] ?? ($existing['backup_seat_id'] ?? 0));
    if (!empty($in['backup_emp_id'])) $bk = b2_person_seat($pdo, (int)$in['backup_emp_id']);
    if ($bk && (!bd_seat_row($pdo, $bk) || $bk === $seat)) $bk = 0;
    $c['backup_seat_id'] = $bk ?: null;
    $ck = (int)($in['checker_seat_id'] ?? ($existing['checker_seat_id'] ?? 0));
    if (!empty($in['checker_emp_id'])) $ck = b2_person_seat($pdo, (int)$in['checker_emp_id']);
    $c['checker_seat_id'] = ($ck && bd_seat_row($pdo, $ck)) ? $ck : null;
    $today = b2_day();
    $c['start_date'] = b2_clean_date($in['start_date'] ?? null, $existing['start_date'] ?? $today);
    $c['dow'] = $c['block'] === 'once' ? null : b2_clean_dow($in['dow'] ?? ($existing['dow'] ?? ''));
    $dom = (int)($in['dom'] ?? ($existing['dom'] ?? 0)); $c['dom'] = ($dom >= 1 && $dom <= 31 && $c['block'] !== 'once') ? $dom : null;
    if ($c['dom']) $c['dow'] = '1,2,3,4,5,6,7';
    $c['end_date'] = null; $c['due_date'] = null;
    if ($kind === 'temp') {
        $days = (int)($in['days'] ?? 0);
        $end = b2_clean_date($in['end_date'] ?? null, $existing['end_date'] ?? null);
        if ($days > 0) $end = date('Y-m-d', strtotime($c['start_date'] . ' +' . ($days - 1) . ' days'));
        if (!$end) $end = date('Y-m-d', strtotime($c['start_date'] . ' +9 days'));
        if ($end < $c['start_date']) return [null, 'The last day is before the first day.'];
        $c['end_date'] = $end;
    } elseif ($c['block'] !== 'once') {
        $c['end_date'] = b2_clean_date($in['end_date'] ?? null, $existing['end_date'] ?? null);
    } else {
        $off = $kinds[$kind][4];
        $c['due_date'] = b2_clean_date($in['due_date'] ?? null, $existing['due_date'] ?? date('Y-m-d', strtotime($c['start_date'] . ' +' . $off . ' days')));
        if ($kind === 'improve') $c['due_date'] = null;
    }
    $cut = (string)b2_cfg('cutoff');
    $c['due_time'] = b2_clean_time($in['due_time'] ?? null, $existing['due_time'] ?? $cut);
    $c['start_time'] = b2_clean_time($in['start_time'] ?? null, '') ?: null;
    $c['grace_min'] = max(0, min(240, (int)($in['grace_min'] ?? ($existing['grace_min'] ?? 0))));
    $proofs = b2_proofs();
    $proof = (string)($in['proof'] ?? ($existing['proof'] ?? $kinds[$kind][3]));
    if (!isset($proofs[$proof])) $proof = $kinds[$kind][3];
    if ($kind === 'number' && !in_array($proof, ['number', 'auto'], true)) $proof = 'number';
    $c['proof'] = $proof;
    $ad = (string)($in['adapter'] ?? ($existing['adapter'] ?? '')); $c['adapter'] = (in_array($ad, ['flyers', 'punch'], true) || ($ad !== '' && function_exists('b2_checks') && isset(b2_checks()[$ad]))) ? $ad : null;
    $c['photo_prompt'] = mb_substr(trim((string)($in['photo_prompt'] ?? ($existing['photo_prompt'] ?? ''))), 0, 400) ?: null;
    $c['unit'] = mb_substr(trim((string)($in['unit'] ?? ($existing['unit'] ?? ''))), 0, 30) ?: null;
    $c['metric'] = $kind === 'number' ? (b2_metric_slug((string)($in['metric'] ?? ($existing['metric'] ?? '')) ?: $title)) : null;
    $cmp = (string)($in['cmp'] ?? ($existing['cmp'] ?? 'min')); $c['cmp'] = in_array($cmp, ['min', 'max', 'any'], true) ? $cmp : 'min';
    $tg = $in['target'] ?? ($existing['target'] ?? null);
    if ($tg === '' || $tg === null) { $c['target'] = null; $c['target_state'] = $existing ? (string)$existing['target_state'] : 'none'; if ($c['target_state'] === 'approved') $c['target_state'] = 'none'; }
    else {
        $tgv = (float)$tg; if ($tgv < 0 || $tgv > 1e9) return [null, 'Enter a sensible target.'];
        $cmpIn = (string)($in['cmp'] ?? ($existing['cmp'] ?? 'min'));
        if ($tgv == 0 && $cmpIn !== 'max') { $c['target'] = null; $c['target_state'] = 'none'; }     // "at least 0" is no target at all
        else { $c['target'] = $tgv; $c['target_state'] = 'approved'; }
    }
    $c['home'] = mb_substr(trim((string)($in['home'] ?? ($existing['home'] ?? ''))), 0, 80) ?: null;
    $c['pin'] = !empty($in['pin']) || (isset($existing['pin']) && !array_key_exists('pin', $in) && (int)$existing['pin'] === 1) ? 1 : 0;
    $pen = (float)($in['pen_amount'] ?? ($existing['pen_amount'] ?? 0)); if ($pen < 0) $pen = 0; if ($pen > 500) return [null, 'A single penalty above ₹500 needs a different decision. Use ₹500 or less.'];
    $c['pen_amount'] = round($pen, 2);
    // penalty per item: only for a number task (a number the system or the owner gives); rate x items short, never below the minimum, never above the daily maximum
    $pm = (string)($in['pen_mode'] ?? ($existing['pen_mode'] ?? 'flat')); $c['pen_mode'] = $pm === 'unit' ? 'unit' : 'flat';
    $c['pen_unit'] = null; $c['pen_min'] = null; $c['pen_max'] = null;
    if ($c['pen_mode'] === 'unit') {
        if (!($kind === 'number' || $proof === 'auto')) return [null, 'A penalty per item needs a number task (a count the system reads or the owner types). Use one amount for this task.'];
        $ru = round((float)($in['pen_unit'] ?? ($existing['pen_unit'] ?? 0)), 2); if ($ru <= 0 || $ru > 500) return [null, 'Enter the rupees for each item (₹1 to ₹500).'];
        $mn = round(max(0, (float)($in['pen_min'] ?? ($existing['pen_min'] ?? 0))), 2);
        $mxRaw = $in['pen_max'] ?? ($existing['pen_max'] ?? 500); $mx = round((float)$mxRaw, 2); if ($mx <= 0) $mx = 500.0;
        if ($mx > 2000) return [null, 'A daily maximum above ₹2000 is not allowed. That is also the monthly limit for one person.'];
        if ($mn > $mx) return [null, 'The minimum penalty cannot be more than the daily maximum.'];
        $c['pen_unit'] = $ru; $c['pen_min'] = $mn; $c['pen_max'] = $mx; $c['pen_amount'] = $ru;
    }
    $c['pen_note'] = mb_substr(trim((string)($in['pen_note'] ?? ($existing['pen_note'] ?? ''))), 0, 200) ?: null;
    $c['waiting_on'] = mb_substr(trim((string)($in['waiting_on'] ?? ($existing['waiting_on'] ?? ''))), 0, 160) ?: null;
    $c['vendor'] = mb_substr(trim((string)($in['vendor'] ?? ($existing['vendor'] ?? ''))), 0, 120) ?: null;
    $ba = $in['buy_amount'] ?? ($existing['buy_amount'] ?? null); $c['buy_amount'] = ($ba === '' || $ba === null) ? null : max(0, (float)$ba);
    $c['progress_every'] = !empty($in['progress_every']) || ($kind === 'fix' && !array_key_exists('progress_every', $in) && !$existing) ? 1 : 0;
    $c['problem_id'] = (int)($in['problem_id'] ?? ($existing['problem_id'] ?? 0)) ?: null;
    $c['build_id'] = (int)($in['build_id'] ?? ($existing['build_id'] ?? 0)) ?: null;
    $c['blocked_by'] = (int)($in['blocked_by'] ?? ($existing['blocked_by'] ?? 0)) ?: null;
    $c['ref_item_id'] = (int)($in['ref_item_id'] ?? ($existing['ref_item_id'] ?? 0)) ?: null;
    $c['link_emp'] = (int)($in['link_emp'] ?? ($existing['link_emp'] ?? 0)) ?: null;
    if ($kind === 'improve' && !$c['ref_item_id'] && !$c['link_emp']) return [null, 'An Improve task needs something to watch: choose the task or the person it is about.'];
    if ($kind !== 'improve' && !$c['seat_id'] && empty($in['allow_unowned'])) return [null, 'Choose who owns this task. Every task needs an owner.'];
    if ($kind === 'improve' && !$c['seat_id'] && $c['link_emp']) $c['seat_id'] = b2_person_seat($pdo, $c['link_emp']);
    $fd = (int)b2_cfg('fair_days');
    $c['fair_until'] = $existing && !empty($existing['fair_until']) ? $existing['fair_until'] : date('Y-m-d', strtotime($c['start_date'] . ' +' . $fd . ' days'));
    return [$c, ''];
}
function b2_item_insert(PDO $pdo, array $c, $status, $source, $by) {
    $now = bd_now();
    $pdo->prepare("INSERT INTO b2_items (kind, block, title, why, detail, section_code, seat_id, backup_seat_id, checker_seat_id, dow, dom, start_date, end_date, due_date, start_time, due_time, grace_min, proof, adapter, photo_prompt, metric, unit, target, cmp, target_state, home, pin, pen_amount, pen_note, fair_until, status, source, problem_id, build_id, ref_item_id, link_emp, progress_every, buy_amount, vendor, waiting_on, blocked_by, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$c['kind'], $c['block'], $c['title'], $c['why'] ?: null, $c['detail'] ?: null, $c['section_code'] ?: null, $c['seat_id'], $c['backup_seat_id'], $c['checker_seat_id'], $c['dow'], $c['dom'], $c['start_date'], $c['end_date'], $c['due_date'], $c['start_time'], $c['due_time'], $c['grace_min'], $c['proof'], $c['adapter'], $c['photo_prompt'], $c['metric'], $c['unit'], $c['target'], $c['cmp'], $c['target_state'], $c['home'], $c['pin'], $c['pen_amount'], $c['pen_note'], $c['fair_until'], $status, $source, $c['problem_id'], $c['build_id'], $c['ref_item_id'], $c['link_emp'], $c['progress_every'], $c['buy_amount'], $c['vendor'], $c['waiting_on'], $c['blocked_by'], mb_substr((string)$by, 0, 80), $now]);
    $id = (int)$pdo->lastInsertId();
    b2_item_pen_save($pdo, $id, $c, null);
    b2_ev($pdo, $id, null, null, null, 'created', $source === 'claude' ? 'Added with Claude' : ('Added by ' . $by));
    if ($status === 'active') b2_activate($pdo, $id);
    return $id;
}
function b2_item_update(PDO $pdo, $id, array $c) {
    $pdo->prepare("UPDATE b2_items SET kind=?, block=?, title=?, why=?, detail=?, section_code=?, seat_id=?, backup_seat_id=?, checker_seat_id=?, dow=?, dom=?, start_date=?, end_date=?, due_date=?, start_time=?, due_time=?, grace_min=?, proof=?, adapter=?, photo_prompt=?, metric=?, unit=?, target=?, cmp=?, target_state=?, home=?, pin=?, pen_amount=?, pen_note=?, progress_every=?, buy_amount=?, vendor=?, waiting_on=?, ref_item_id=?, link_emp=?, updated_at=? WHERE id=?")
        ->execute([$c['kind'], $c['block'], $c['title'], $c['why'] ?: null, $c['detail'] ?: null, $c['section_code'] ?: null, $c['seat_id'], $c['backup_seat_id'], $c['checker_seat_id'], $c['dow'], $c['dom'], $c['start_date'], $c['end_date'], $c['due_date'], $c['start_time'], $c['due_time'], $c['grace_min'], $c['proof'], $c['adapter'], $c['photo_prompt'], $c['metric'], $c['unit'], $c['target'], $c['cmp'], $c['target_state'], $c['home'], $c['pin'], $c['pen_amount'], $c['pen_note'], $c['progress_every'], $c['buy_amount'], $c['vendor'], $c['waiting_on'], $c['ref_item_id'], $c['link_emp'], bd_now(), (int)$id]);
    $old = b2_row($pdo, 'b2_items', $id); b2_item_pen_save($pdo, $id, $c, $old);
    $it = b2_row($pdo, 'b2_items', $id);
    if ($it && $it['status'] === 'active') b2_activate($pdo, $id);
}
/** the per-item penalty fields are saved on their own, so the long insert/update lists stay as they were */
function b2_item_pen_save(PDO $pdo, $id, array $c, $old) {
    $mode = ($c['pen_mode'] ?? 'flat') === 'unit' ? 'unit' : 'flat';
    $resetBase = $mode === 'unit' && (!$old || ($old['pen_mode'] ?? 'flat') !== 'unit');          // a task that just became per-item starts a fresh baseline
    $pdo->prepare("UPDATE b2_items SET pen_mode = ?, pen_unit = ?, pen_min = ?, pen_max = ?" . ($resetBase || $mode === 'flat' ? ", pen_base = NULL" : "") . " WHERE id = ?")
        ->execute([$mode, $c['pen_unit'] ?? null, $c['pen_min'] ?? null, $c['pen_max'] ?? null, (int)$id]);
}
function b2_ev(PDO $pdo, $itemId, $runId, $problemId, $empId, $kind, $text, $pct = null) {
    try { $pdo->prepare("INSERT INTO b2_ev (item_id, run_id, problem_id, emp_id, kind, text, pct, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$itemId ?: null, $runId ?: null, $problemId ?: null, $empId ?: null, $kind, mb_substr((string)$text, 0, 700), $pct, bd_now()]); } catch (Throwable $e) {}
}
/** an item became active: make today's run (repeat/number) or the one run (once) */
function b2_activate(PDO $pdo, $id) {
    $it = b2_row($pdo, 'b2_items', $id); if (!$it || $it['status'] !== 'active') return;
    if ($it['block'] === 'once') {
        if ($it['kind'] === 'improve') return;
        b2_make_once_run($pdo, $it);
    } else {
        $t = b2_day(); if ($it['start_date'] <= $t && (!$it['end_date'] || $it['end_date'] >= $t)) b2_make_run($pdo, $it, $t);
    }
}

/* ============================== runs ============================== */
function b2_due_ts($date, $hhmm) { return strtotime($date . ' ' . $hhmm . ':00'); }
function b2_make_run(PDO $pdo, array $it, $date) {
    $st = $pdo->prepare("SELECT id FROM b2_runs WHERE item_id = ? AND run_date = ?"); $st->execute([(int)$it['id'], $date]); if ($st->fetchColumn()) return 0;
    if ($it['block'] !== 'once') {
        $dows = explode(',', b2_clean_dow($it['dow'])); if (!in_array((string)b2_dow($date), $dows, true)) return 0;
        if (!empty($it['dom']) && (int)date('j', strtotime($date)) !== (int)$it['dom']) return 0;
    }
    list($emp, $cover, $orig) = b2_owner($pdo, $it, $date);
    $status = 'open';
    if (!$emp && b2_everyone_off($pdo, $orig, $date)) $status = 'excused';
    $due = date('Y-m-d H:i:s', b2_due_ts($date, $it['due_time']));
    $pdo->prepare("INSERT IGNORE INTO b2_runs (item_id, run_date, owner_emp, orig_emp, cover, due_at, status, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([(int)$it['id'], $date, $emp ?: null, ($orig && $orig !== $emp) ? $orig : null, $cover ?: null, $due, $status, bd_now()]);
    $rid = (int)$pdo->lastInsertId();
    if ($rid && !$emp && $status === 'open') b2_alert($pdo, 'nobody', 'Nobody can do "' . b2_short($it['title'], 80) . '" today: ' . ($cover ?: 'no owner') . '.', (string)$it['section_code'], 'nobody:' . $it['id'] . ':' . $date);
    if ($rid && $emp && $cover && $date === b2_day() && b2_section_live((string)$it['section_code'])) bd_push($pdo, $emp, 'Please cover: ' . $it['title'], ucfirst($cover) . '. Due ' . b2_t12($it['due_time']) . '.');
    return $rid;
}
function b2_make_once_run(PDO $pdo, array $it) {
    $due = $it['due_date'] ?: b2_day();
    $st = $pdo->prepare("SELECT id, run_date, status FROM b2_runs WHERE item_id = ? ORDER BY id DESC LIMIT 1"); $st->execute([(int)$it['id']]); $r = $st->fetch();
    $dueAt = date('Y-m-d H:i:s', b2_due_ts($due, $it['due_time']));
    if ($r) { if ($r['status'] === 'open' || $r['status'] === 'missed') $pdo->prepare("UPDATE b2_runs SET run_date = ?, due_at = ?, status = IF(status = 'missed' AND ? > NOW(), 'open', status) WHERE id = ?")->execute([$due, $dueAt, $dueAt, $r['id']]); return (int)$r['id']; }
    list($emp, $cover, $orig) = b2_owner($pdo, $it, b2_day());
    $pdo->prepare("INSERT IGNORE INTO b2_runs (item_id, run_date, owner_emp, orig_emp, cover, due_at, status, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([(int)$it['id'], $due, $emp ?: null, ($orig && $orig !== $emp) ? $orig : null, $cover ?: null, $dueAt, 'open', bd_now()]);
    $rid = (int)$pdo->lastInsertId();
    if ($rid && !$emp) b2_alert($pdo, 'nobody', 'Nobody owns "' . b2_short($it['title'], 80) . '" yet.', (string)$it['section_code'], 'nobody:' . $it['id'] . ':once');
    if ($rid && $emp && b2_section_live((string)$it['section_code'])) bd_push($pdo, $emp, 'New: ' . $it['title'], 'Due ' . date('D j M', strtotime($due)) . '.');
    return $rid;
}
function b2_generate(PDO $pdo, $date) {
    $n = 0;
    $st = $pdo->prepare("SELECT * FROM b2_items WHERE status = 'active' AND block IN ('repeat','number') AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
    $st->execute([$date, $date]);
    foreach ($st->fetchAll() as $it) { if (b2_make_run($pdo, $it, $date)) $n++; }
    $st = $pdo->query("SELECT i.* FROM b2_items i WHERE i.status = 'active' AND i.block = 'once' AND i.kind != 'improve' AND NOT EXISTS (SELECT 1 FROM b2_runs r WHERE r.item_id = i.id)");
    foreach ($st->fetchAll() as $it) { if (b2_make_once_run($pdo, $it)) $n++; }
    return $n;
}
/** open runs with no owner get one as soon as the seat has a free holder; owners who became away hand over to the cover */
function b2_reassign(PDO $pdo, $date) {
    $n = 0;
    $st = $pdo->prepare("SELECT r.*, i.seat_id, i.backup_seat_id, i.section_code, i.title, i.due_time, i.kind FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.status = 'open' AND r.run_date <= ? AND r.run_date >= ? AND i.status = 'active'");
    $st->execute([$date, date('Y-m-d', strtotime($date . ' -3 days'))]);
    foreach ($st->fetchAll() as $r) {
        $cur = (int)$r['owner_emp'];
        if ($cur && bd_away($pdo, $cur, $date) === '') continue;
        list($e, $cover, $orig) = b2_owner($pdo, $r, $date);
        if (!$e || $e === $cur) continue;
        $pdo->prepare("UPDATE b2_runs SET owner_emp = ?, orig_emp = COALESCE(orig_emp, ?), cover = ?, reminded = 0 WHERE id = ?")->execute([$e, $cur ?: ($orig ?: null), $cover, $r['id']]);
        b2_ev($pdo, (int)$r['item_id'], (int)$r['id'], null, $e, 'moved', 'Moved to ' . bd_emp_name($pdo, $e) . ($cover ? ' (' . $cover . ')' : ''));
        if (b2_section_live((string)$r['section_code'])) bd_push($pdo, $e, 'Please cover: ' . $r['title'], ucfirst($cover) . '. Due ' . b2_t12($r['due_time']) . '.');
        $n++;
    }
    return $n;
}
function b2_remind(PDO $pdo, $now) {
    $n = 0; $min = max(10, (int)bd_cfg('remind_min'));
    $st = $pdo->prepare("SELECT r.id, r.owner_emp, r.due_at, i.title, i.section_code FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.status = 'open' AND r.reminded = 0 AND r.owner_emp IS NOT NULL AND r.due_at > ? AND r.due_at <= ? AND r.run_date = ?");
    $st->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + $min * 60), b2_day($now)]);
    foreach ($st->fetchAll() as $r) {
        $pdo->prepare("UPDATE b2_runs SET reminded = 1 WHERE id = ?")->execute([$r['id']]);
        if (b2_section_live((string)$r['section_code'])) { bd_push($pdo, (int)$r['owner_emp'], 'Still open: ' . $r['title'], 'Due ' . b2_t12(date('H:i', strtotime($r['due_at']))) . '.'); $n++; }
    }
    return $n;
}
/** system-checked numbers (flyers, punch) */
function b2_auto_check(PDO $pdo, $date) {
    $n = 0;
    $st = $pdo->prepare("SELECT r.id, r.owner_emp, r.due_at, i.adapter, i.target, i.cmp, i.id AS item_id FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.run_date = ? AND r.status = 'open' AND i.proof = 'auto'");
    $st->execute([$date]);
    $reg = function_exists('b2_checks') ? b2_checks() : [];
    foreach ($st->fetchAll() as $t) {
        $val = null;
        // a "keep it at zero" check (e.g. no order older than 48 hours without a tracking ID) is judged at the cut-off: 0 = done, otherwise it stays open and becomes missed
        if (isset($reg[$t['adapter']]) && $reg[$t['adapter']]['mode'] === 'zero') {
            $r = b2_check_value($pdo, $t['adapter']); if ($r['value'] === null) continue;
            $bad = $reg[$t['adapter']]['bad'];
            b2_pen_base_touch($pdo, (int)$t['item_id'], (int)$r['value']);               // the old backlog can only shrink: a per-item penalty counts growth above the lowest level seen
            $pdo->prepare("UPDATE b2_runs SET value = ?, note = ? WHERE id = ? AND status = 'open'")->execute([$r['value'], $r['value'] > 0 ? ($r['value'] . ' ' . $bad . ' (checked ' . date('g:i A') . ')') : 'None left (checked ' . date('g:i A') . ')', $t['id']]);
            if ($r['value'] === 0 && strtotime($t['due_at']) <= time()) { $pdo->prepare("UPDATE b2_runs SET status = 'done', met = 1, done_at = ?, note = 'checked by the system: none left', proof_state = 'ok' WHERE id = ? AND status = 'open'")->execute([bd_now(), $t['id']]); $n++; }
            continue;
        }
        if (!$t['owner_emp']) continue;
        try {
            if ($t['adapter'] === 'flyers') { $q = $pdo->prepare("SELECT COUNT(*) FROM fl_drops WHERE emp_id = ? AND created_at >= ? AND (flag IS NULL OR flag_ok = 1)"); $q->execute([(int)$t['owner_emp'], $date . ' 00:00:00']); $val = (int)$q->fetchColumn(); }
            elseif ($t['adapter'] === 'punch') { $q = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_date = ? AND punch_in IS NOT NULL"); $q->execute([(int)$t['owner_emp'], $date]); $val = (int)$q->fetchColumn(); }
        } catch (Throwable $e) { $val = null; }
        if ($val === null) continue;
        $need = max(1, (int)$t['target']);
        $pdo->prepare("UPDATE b2_runs SET value = ? WHERE id = ?")->execute([$val, $t['id']]);
        if ($val >= $need) { $pdo->prepare("UPDATE b2_runs SET status = 'done', met = 1, done_at = ?, note = 'checked by the system', proof_state = 'ok' WHERE id = ?")->execute([bd_now(), $t['id']]); $n++; }
    }
    return $n;
}
/** open runs past their cut-off become missed; a missed run may raise a penalty ASK (never a charge) */
function b2_miss(PDO $pdo, $now) {
    $n = 0;
    $st = $pdo->prepare("SELECT r.*, i.title, i.kind, i.block, i.grace_min, i.section_code, i.pen_amount, i.fair_until, i.target_state, i.pen_mode, i.pen_unit, i.pen_min, i.pen_max, i.pen_base, i.adapter, i.target, i.cmp, i.unit, i.proof FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.status = 'open' AND r.due_at < ? AND r.run_date >= ? AND i.status = 'active'");
    $st->execute([date('Y-m-d H:i:s', $now), date('Y-m-d', $now - 5 * 86400)]);
    foreach ($st->fetchAll() as $r) {
        if (strtotime($r['due_at']) + 60 * (int)$r['grace_min'] > $now) continue;
        $pdo->prepare("UPDATE b2_runs SET status = 'missed', met = IF(? = 'number', 0, met) WHERE id = ? AND status = 'open'")->execute([$r['kind'], $r['id']]);
        $n++; b2_ev($pdo, (int)$r['item_id'], (int)$r['id'], null, (int)$r['owner_emp'], 'missed', 'Not done by ' . b2_t12(date('H:i', strtotime($r['due_at']))));
        b2_log('missed', 'b2r' . $r['id'], $r['title'] . ' - ' . ($r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : 'nobody'));
        if ($r['owner_emp'] && b2_section_live((string)$r['section_code'])) bd_push($pdo, (int)$r['owner_emp'], 'Missed: ' . $r['title'], 'This was not done in time.' . ((float)$r['pen_amount'] > 0 ? ' Your boss may decide about a penalty.' : ''));
        b2_pen_ask($pdo, $r);
    }
    // a number handed in SHORT of the target ("17 of 20 reviews") is judged at the same cut-off: a per-item penalty asks for the shortfall x the rate. The owner can still correct the number until then.
    $st = $pdo->prepare("SELECT r.*, i.title, i.kind, i.block, i.grace_min, i.section_code, i.pen_amount, i.fair_until, i.target_state, i.pen_mode, i.pen_unit, i.pen_min, i.pen_max, i.pen_base, i.adapter, i.target, i.cmp, i.unit, i.proof FROM b2_runs r JOIN b2_items i ON i.id = r.item_id
        WHERE r.status IN ('done','review') AND r.met = 0 AND i.kind = 'number' AND i.pen_mode = 'unit' AND r.due_at < ? AND r.run_date >= ? AND i.status = 'active' AND NOT EXISTS (SELECT 1 FROM b2_pens p WHERE p.run_id = r.id)");
    $st->execute([date('Y-m-d H:i:s', $now), date('Y-m-d', $now - 5 * 86400)]);
    foreach ($st->fetchAll() as $r) {
        if (strtotime($r['due_at']) + 60 * (int)$r['grace_min'] > $now) continue;
        b2_ev($pdo, (int)$r['item_id'], (int)$r['id'], null, (int)$r['owner_emp'], 'under', 'Handed in ' . b2_num($r['value']) . ' of ' . b2_num($r['target']) . ($r['unit'] ? ' ' . $r['unit'] : ''));
        b2_pen_ask($pdo, $r); $n++;
    }
    return $n;
}

/* ============================== doing the work: proof, numbers, checks ============================== */
function b2_run_full(PDO $pdo, $runId) {
    $st = $pdo->prepare("SELECT r.*, i.kind, i.block, i.title, i.why, i.section_code, i.proof, i.target, i.cmp, i.unit, i.target_state, i.checker_seat_id, i.photo_prompt, i.seat_id, i.pen_amount, i.progress_every, i.status AS item_status, i.metric, i.adapter, i.due_time FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.id = ?");
    $st->execute([(int)$runId]); return $st->fetch() ?: null;
}
function b2_can_do_run(array $w, array $run) { return $w['admin'] || ($w['emp'] && (int)$run['owner_emp'] === (int)$w['emp']); }
function b2_met($run, $val) {
    if ($run['block'] !== 'number' && $run['kind'] !== 'number') return null;
    if ($run['target_state'] !== 'approved' || $run['target'] === null) return null;
    $t = (float)$run['target'];
    if ($run['cmp'] === 'min') return $val >= $t ? 1 : 0;
    if ($run['cmp'] === 'max') return $val <= $t ? 1 : 0;
    return 1;
}
/** read the number off a screenshot with Claude; returns [value|null, note]. Only ever used to FLAG a difference. */
function b2_pilot_read($absPath, $label) {
    if (!function_exists('convo_ai')) return [null, ''];
    $img = bd_b64_small($absPath); if (!$img) return [null, ''];
    $parts = [['text' => "Look at this screenshot. Find the single number that answers: \"" . $label . "\". Reply with that number only (digits, no commas)."], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]];
    $schema = ['type' => 'OBJECT', 'properties' => ['found' => ['type' => 'BOOLEAN'], 'number' => ['type' => 'NUMBER']], 'required' => ['found']];
    try { $r = convo_ai("You read numbers from screenshots for a small business. Be exact. If the screenshot does not show it, found=false.", $parts, $schema, false, 200); } catch (Throwable $e) { return [null, '']; }
    if (empty($r[0]) || !is_array($r[1]) || empty($r[1]['found']) || !isset($r[1]['number'])) return [null, ''];
    return [(float)$r[1]['number'], ''];
}
/** save one proof picture (or reuse the one already saved for a group of numbers) and run the mechanical checks. Returns [file, phash, flagWhy, error] */
function b2_take_proof(PDO $pdo, $fileField, array $run) {
    if (empty($_FILES[$fileField]) || (int)$_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) return [null, null, '', 'Add the photo or screenshot first.'];
    list($fn, $err) = bd_save_photo_file($_FILES[$fileField]['tmp_name'], (int)$run['id']);
    if (!$fn) return [null, null, '', $err];
    // Fine fingerprint (1024 steps). The old 8x8 one made every phone screenshot of the same screen look identical. Only EARLIER days of this same task are compared.
    $abs = bd_dir() . $fn; $hash = function_exists('bd_fhash') ? bd_fhash($abs) : null; $why = '';
    if ($hash) {
        $q = $pdo->prepare("SELECT fhash, run_date FROM b2_runs WHERE item_id = ? AND id != ? AND run_date >= ? AND run_date < ? AND fhash IS NOT NULL ORDER BY id DESC LIMIT 40");
        $q->execute([(int)$run['item_id'], (int)$run['id'], date('Y-m-d', strtotime('-14 days')), b2_day()]);
        foreach ($q->fetchAll() as $o) { if (bd_fdist($hash, (string)$o['fhash']) <= 6) { $why = 'Looks the same as the picture from ' . date('j M', strtotime($o['run_date'])) . '.'; break; } }
    }
    return [$fn, $hash, $why, ''];
}
/**
 * Finish one run. $o = ['value'=>, 'note'=>, 'proof'=>file, 'hash'=>, 'flag'=>why, 'by'=>login]. Returns [ok, message, done]
 */
function b2_finish_run(PDO $pdo, array $run, array $o, array $w) {
    $now = bd_now(); $late = $run['status'] === 'missed' ? 1 : 0; $why = (string)($o['flag'] ?? '');
    $val = array_key_exists('value', $o) && $o['value'] !== null ? (float)$o['value'] : null;
    $met = $val !== null ? b2_met($run, $val) : null;
    // the Claude-reading pilot only flags, it never blocks
    if (b2_cfg('pilot') === '1' && !empty($o['proof'])) {
        $abs = bd_dir() . $o['proof'];
        if ($run['kind'] === 'number' && $val !== null) { list($read) = b2_pilot_read($abs, $run['title']); if ($read !== null && abs($read - $val) > max(0.5, 0.05 * abs($val))) $why = 'The screenshot seems to show ' . b2_num($read) . ' but ' . b2_num($val) . ' was typed.'; }
        elseif ($run['proof'] === 'photo') { list($v, $r) = bd_photo_judge($pdo, ['title' => $run['title'], 'photo_prompt' => $run['photo_prompt'], 'ref_photo' => null], $abs); if ($v === 'fail') $why = $r ?: 'Claude thinks the photo does not show the work.'; }
    }
    $state = $why !== '' ? 'flag' : (!empty($o['proof']) || $run['proof'] === 'tick' || $run['proof'] === 'text' ? 'ok' : '');
    $needCheck = !empty($run['checker_seat_id']) && in_array($run['proof'], ['photo', 'screenshot'], true);
    $status = $needCheck ? 'review' : 'done';
    $pdo->prepare("UPDATE b2_runs SET status = ?, value = ?, met = ?, late = ?, note = ?, proof_path = COALESCE(?, proof_path), fhash = COALESCE(?, fhash), proof_state = ?, proof_why = ?, done_at = ? WHERE id = ?")
        ->execute([$status, $val, $met, $late, ($o['note'] ?? '') !== '' ? mb_substr((string)$o['note'], 0, 400) : ($late ? 'done late' : null), $o['proof'] ?? null, $o['hash'] ?? null, $state, $why !== '' ? mb_substr($why, 0, 220) : null, $now, (int)$run['id']]);
    b2_ev($pdo, (int)$run['item_id'], (int)$run['id'], null, (int)($w['emp'] ?: $run['owner_emp']), 'done', ($val !== null ? 'Entered ' . b2_num($val) . ($run['unit'] ? ' ' . $run['unit'] : '') . '. ' : '') . ($late ? 'Late. ' : '') . ($why ? 'Flagged: ' . $why : ''), null);
    b2_log('done', 'b2r' . $run['id'], $run['title'] . ($late ? ' (late)' : ''), 'employee');
    if ($late) { $pdo->prepare("UPDATE b2_pens SET note = CONCAT(COALESCE(note,''), ' Done late.') WHERE run_id = ?")->execute([(int)$run['id']]); }
    // a run done while it was still just "missed" removes nothing by itself: the boss sees both
    if ($run['kind'] !== 'number' && $run['block'] === 'once') $pdo->prepare("UPDATE b2_items SET status = 'done', closed_at = ?, pct = 100 WHERE id = ? AND status = 'active'")->execute([$now, (int)$run['item_id']]);
    if ($run['block'] === 'once') b2_unblock($pdo, (int)$run['item_id']);
    $msg = 'Done. Thank you.';
    if ($met === 0) $msg = 'Saved: ' . b2_num($val) . ($run['unit'] ? ' ' . $run['unit'] : '') . '. The target is ' . ($run['cmp'] === 'max' ? 'at most ' : '') . b2_num($run['target']) . '. Your boss can see this.';
    elseif ($needCheck) $msg = 'Done. The checker will look at it.';
    elseif ($why !== '') $msg = 'Saved. Your boss may look at the picture again.';
    return [true, $msg, true];
}
/** items waiting for this one to finish become free (blocked_by) */
function b2_unblock(PDO $pdo, $itemId) {
    $pdo->prepare("UPDATE b2_items SET blocked_by = NULL WHERE blocked_by = ?")->execute([(int)$itemId]);
}
/** one run: tick / text / photo / number+screenshot / screenshot */
function b2_submit(PDO $pdo, array $w, array $in, $by) {
    $run = b2_run_full($pdo, (int)($_POST['run_id'] ?? ($in['run_id'] ?? 0)));
    if (!$run) return [false, 'Task not found.', 404];
    if (!b2_can_do_run($w, $run)) return [false, 'This task belongs to ' . ($run['owner_emp'] ? bd_emp_name($pdo, $run['owner_emp']) : 'nobody yet') . '.', 403];
    if ($run['item_status'] !== 'active') return [false, 'This task is not active.', 409];
    if (!b2_section_live((string)$run['section_code']) && !$w['admin']) return [false, 'This section is still being tested. Tasks are not live yet.', 409];
    if (in_array($run['status'], ['done', 'review'], true) && $run['block'] !== 'number') return [false, 'This is already done.', 409];
    if ($run['block'] !== 'once' && $run['run_date'] !== b2_day()) return [false, 'Only today\'s task can be done.', 409];
    if ($run['status'] === 'excused') return [false, 'This one is not needed today.', 409];
    if ($run['proof'] === 'auto') return [false, 'The system checks this by itself. Just do the work.', 409];
    $note = trim((string)($_POST['note'] ?? ($in['note'] ?? '')));
    $o = ['note' => $note];
    if ($run['proof'] === 'text' && mb_strlen($note) < 3) return [false, 'Write a few words about what you did.', 422];
    if ($run['kind'] === 'number') {
        $raw = $_POST['value'] ?? ($in['value'] ?? null);
        if ($raw === null || $raw === '' || !is_numeric(str_replace(',', '', (string)$raw))) return [false, 'Type the number first.', 422];
        $v = (float)str_replace(',', '', (string)$raw); if ($v < 0 || $v > 1e9) return [false, 'Type a sensible number.', 422];
        $o['value'] = $v;
    }
    if (in_array($run['proof'], ['photo', 'number', 'screenshot'], true)) {
        list($fn, $hash, $flag, $err) = b2_take_proof($pdo, 'photo', $run);
        if (!$fn) { $msg = $run['proof'] === 'number' ? 'Add a screenshot of where this number comes from.' : $err; return [false, $err !== 'Add the photo or screenshot first.' ? $err : $msg, 422]; }
        $o['proof'] = $fn; $o['hash'] = $hash; $o['flag'] = $flag;
    }
    list($ok, $msg, $done) = b2_finish_run($pdo, $run, $o, $w);
    return [$ok, $msg, 200, ['done' => $done]];
}
/** several numbers, ONE screenshot for all of them */
function b2_submit_many(PDO $pdo, array $w, array $in) {
    $ids = $_POST['run_ids'] ?? ($in['run_ids'] ?? []); if (!is_array($ids)) $ids = json_decode((string)$ids, true) ?: [];
    $vals = $_POST['values'] ?? ($in['values'] ?? []); if (!is_array($vals)) $vals = json_decode((string)$vals, true) ?: [];
    if (!$ids) return [false, 'Nothing to save.', 422];
    $runs = []; foreach ($ids as $i => $id) { $r = b2_run_full($pdo, (int)$id); if (!$r || $r['kind'] !== 'number') return [false, 'One of these is not a number task.', 422]; if (!b2_can_do_run($w, $r)) return [false, 'Not your task.', 403]; $v = $vals[$i] ?? ($vals[(string)$id] ?? null); if ($v === null || $v === '' || !is_numeric(str_replace(',', '', (string)$v))) return [false, 'Type every number first.', 422]; $runs[] = [$r, (float)str_replace(',', '', (string)$v)]; }
    list($fn, $hash, $flag, $err) = b2_take_proof($pdo, 'photo', $runs[0][0]);
    if (!$fn) return [false, 'Add one screenshot that shows these numbers.', 422];
    $n = 0;
    foreach ($runs as $pair) {
        $r = $pair[0];
        if (!b2_section_live((string)$r['section_code']) && !$w['admin']) continue;
        if ($r['run_date'] !== b2_day() || $r['status'] === 'excused') continue;
        b2_finish_run($pdo, $r, ['value' => $pair[1], 'proof' => $fn, 'hash' => $hash, 'flag' => $flag, 'note' => trim((string)($_POST['note'] ?? ''))], $w); $n++;
    }
    return [true, $n . ' number' . ($n === 1 ? '' : 's') . ' saved with one screenshot. Thank you.', 200, ['saved' => $n]];
}
/** a daily progress note on a long job (to fix / follow-up / improve) */
function b2_progress(PDO $pdo, array $w, array $in) {
    $run = b2_run_full($pdo, (int)($in['run_id'] ?? 0)); if (!$run) return [false, 'Task not found.', 404];
    if (!b2_can_do_run($w, $run)) return [false, 'Not your task.', 403];
    $note = trim((string)($in['note'] ?? '')); if (mb_strlen($note) < 3) return [false, 'Write one line about what you did.', 422];
    $pct = max(0, min(100, (int)($in['pct'] ?? 0)));
    $pdo->prepare("UPDATE b2_runs SET pct = ? WHERE id = ?")->execute([$pct, (int)$run['id']]);
    $pdo->prepare("UPDATE b2_items SET pct = ?, updated_at = ? WHERE id = ?")->execute([$pct, bd_now(), (int)$run['item_id']]);
    b2_ev($pdo, (int)$run['item_id'], (int)$run['id'], null, (int)$w['emp'], 'progress', $note, $pct);
    return [true, 'Progress saved: ' . $pct . '%.', 200];
}
/** checker / boss looks at a flagged or waiting picture */
function b2_check_run(PDO $pdo, array $w, $runId, $ok, $note, $by) {
    $run = b2_run_full($pdo, $runId); if (!$run) return [false, 'Task not found.', 404];
    $secOk = b2_can_section($w, (string)$run['section_code'], $pdo); $checker = false;
    if (!empty($run['checker_seat_id']) && $w['emp'] && in_array((int)$w['emp'], bd_holders($pdo, (int)$run['checker_seat_id']), true)) $checker = true;
    if (!$secOk && !$checker) return [false, 'Only the checker or a boss can do this.', 403];
    if ($ok) {
        $pdo->prepare("UPDATE b2_runs SET status = 'done', proof_state = 'ok', checked_by = ?, checked_at = ? WHERE id = ?")->execute([$by, bd_now(), $runId]);
        b2_ev($pdo, (int)$run['item_id'], $runId, null, (int)$w['emp'], 'checked', 'Checked and accepted.' . ($note ? ' ' . $note : ''));
        return [true, 'Accepted.', 200];
    }
    $late = strtotime($run['due_at']) < time();
    $pdo->prepare("UPDATE b2_runs SET status = ?, proof_state = 'bad', proof_why = ?, checked_by = ?, checked_at = ?, proof_path = NULL, phash = NULL, fhash = NULL WHERE id = ?")->execute([$late && $run['block'] !== 'once' ? 'missed' : 'open', mb_substr($note !== '' ? $note : 'Please do it again.', 0, 220), $by, bd_now(), $runId]);
    if ($run['block'] === 'once') $pdo->prepare("UPDATE b2_items SET status = 'active', closed_at = NULL WHERE id = ? AND status = 'done'")->execute([(int)$run['item_id']]);
    b2_ev($pdo, (int)$run['item_id'], $runId, null, (int)$w['emp'], 'rejected', $note !== '' ? $note : 'Sent back to do again.');
    if ($run['owner_emp'] && b2_section_live((string)$run['section_code'])) bd_push($pdo, (int)$run['owner_emp'], 'Please redo: ' . $run['title'], $note !== '' ? $note : 'Your picture was not accepted.');
    return [true, 'Sent back to ' . ($run['owner_emp'] ? bd_emp_name($pdo, $run['owner_emp']) : 'the owner') . '.', 200];
}
function b2_proof_file(PDO $pdo, array $w, $runId) {
    $run = b2_run_full($pdo, $runId); if (!$run || empty($run['proof_path'])) return null;
    $okAccess = $w['admin'] || ($w['emp'] && (int)$run['owner_emp'] === (int)$w['emp']) || b2_can_section($w, (string)$run['section_code'], $pdo);
    if (!$okAccess && !empty($run['checker_seat_id']) && $w['emp'] && in_array((int)$w['emp'], bd_holders($pdo, (int)$run['checker_seat_id']), true)) $okAccess = true;
    if (!$okAccess) return null;
    $abs = bd_dir() . basename($run['proof_path']); return is_file($abs) ? $abs : null;
}

/* ============================== punch-out lock: what is still open for this person ============================== */
function b2_po_left(PDO $pdo, $emp, $day) {
    $o = [];
    try {
        $st = $pdo->prepare("SELECT i.title, r.due_at, i.section_code FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.owner_emp = ? AND r.status = 'open' AND r.run_date <= ? AND i.status = 'active' AND i.kind != 'improve' AND (i.block != 'once' OR r.run_date = ?) AND i.blocked_by IS NULL ORDER BY r.due_at");
        $st->execute([(int)$emp, $day, $day]);
        foreach ($st->fetchAll() as $r) { if (!b2_section_live((string)$r['section_code'])) continue; $o[] = ['title' => (string)$r['title'], 'due' => $r['due_at'] ? date('g:i A', strtotime($r['due_at'])) : '']; }
    } catch (Throwable $e) {}
    return $o;
}

/* ============================== views: strips, states, item cards ============================== */
function b2_strips(PDO $pdo, array $ids, $days = 7) {
    $ids = array_values(array_unique(array_map('intval', $ids))); $out = [];
    $dates = []; for ($i = $days - 1; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime('-' . $i . ' days'));
    foreach ($ids as $id) { $out[$id] = ['days' => [], 'by' => []]; }
    if (!$ids) return $out;
    $st = $pdo->prepare("SELECT item_id, run_date, status, met, value, late, owner_emp, id FROM b2_runs WHERE item_id IN (" . bd_in($ids) . ") AND run_date >= ? AND run_date <= ?");
    $st->execute([$dates[0], end($dates)]);
    foreach ($st->fetchAll() as $r) $out[(int)$r['item_id']]['by'][$r['run_date']] = $r;
    foreach ($ids as $id) {
        foreach ($dates as $d) {
            $r = $out[$id]['by'][$d] ?? null; $s = 'off';
            if ($r) {
                if ($r['status'] === 'done') $s = ($r['met'] !== null && (int)$r['met'] === 0) ? 'under' : ($r['late'] ? 'late' : 'done');
                elseif ($r['status'] === 'review') $s = 'review';
                elseif ($r['status'] === 'missed') $s = 'missed';
                elseif ($r['status'] === 'excused') $s = 'off';
                else $s = $d === b2_day() ? 'open' : 'missed';
            }
            $out[$id]['days'][] = ['d' => $d, 's' => $s, 'v' => $r && $r['value'] !== null ? (float)$r['value'] : null];
        }
    }
    return $out;
}
/** plain counting: slipping / late / waiting / ontrack / draft / paused / done */
function b2_state_of(array $it, array $strip, $run) {
    $st = (string)$it['status'];
    if ($st === 'draft') return 'draft'; if ($st === 'paused') return 'paused'; if ($st === 'done' || $st === 'archived') return 'done';
    if ($it['kind'] === 'improve') return 'ontrack';
    if ($it['block'] === 'once') {
        if (!empty($it['blocked_by'])) return 'waiting';
        if (!$run) return 'ontrack';
        if ($run['status'] === 'review') return 'waiting';
        if (in_array($run['status'], ['open', 'missed'], true)) { if (strtotime($run['due_at']) < time()) return 'late'; if (!empty($it['waiting_on'])) return 'waiting'; }
        return 'ontrack';
    }
    $miss = 0; $under = 0;
    foreach ($strip['days'] as $d) { if ($d['s'] === 'missed') { $miss++; $under++; } elseif ($d['s'] === 'under') $under++; }
    if ($it['block'] === 'number' && $it['target_state'] === 'approved') { if ($under >= (int)b2_cfg('slip_under')) return 'slipping'; }
    else { if ($miss >= (int)b2_cfg('slip_miss')) return 'slipping'; }
    if ($run && $run['status'] === 'missed') return 'late';
    if ($run && $run['status'] === 'review') return 'waiting';
    return 'ontrack';
}
function b2_state_label($s) { $m = ['ontrack' => 'On track', 'slipping' => 'Slipping', 'late' => 'Late', 'waiting' => 'Waiting', 'draft' => 'Waiting for your yes', 'paused' => 'Paused', 'done' => 'Done']; return $m[$s] ?? $s; }
function b2_due_label(array $it) {
    if ($it['block'] === 'once') return $it['kind'] === 'improve' ? 'Watching' : ('Due ' . ($it['due_date'] ? date('D j M', strtotime($it['due_date'])) : '—') . ' ' . b2_t12($it['due_time']));
    $w = b2_dow_label($it['dow']); if (!empty($it['dom'])) $w = 'Day ' . (int)$it['dom'] . ' of the month';
    return $w . ' · by ' . b2_t12($it['due_time']) . ($it['kind'] === 'temp' && $it['end_date'] ? ' · until ' . date('j M', strtotime($it['end_date'])) : '');
}
/** many items -> cards for the pages (one query for strips, one for today's runs) */
function b2_items_view(PDO $pdo, array $rows) {
    if (!$rows) return [];
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $strips = b2_strips($pdo, $ids);
    $today = b2_day(); $runs = [];
    $st = $pdo->prepare("SELECT r.* FROM b2_runs r WHERE r.item_id IN (" . bd_in($ids) . ") AND r.id = (SELECT MAX(r2.id) FROM b2_runs r2 WHERE r2.item_id = r.item_id AND (r2.run_date = ? OR r2.item_id IN (SELECT id FROM b2_items WHERE block = 'once')))");
    $st->execute([$today]);
    foreach ($st->fetchAll() as $r) $runs[(int)$r['item_id']] = $r;
    $seatCache = [];
    $o = [];
    foreach ($rows as $it) {
        $id = (int)$it['id']; $run = $runs[$id] ?? null; $strip = $strips[$id];
        $seatId = (int)$it['seat_id'];
        if (!isset($seatCache[$seatId])) { $s = $seatId ? bd_seat_row($pdo, $seatId) : null; $seatCache[$seatId] = ['title' => $s ? b2_seat_title($pdo, $seatId) : '', 'person' => $s ? b2_seat_is_person($s) : false, 'people' => $s ? b2_seat_people($pdo, $seatId) : []]; }
        $sc = $seatCache[$seatId];
        $state = b2_state_of($it, $strip, $run);
        $ownerNow = $run && $run['owner_emp'] ? bd_emp_name($pdo, $run['owner_emp']) : ($sc['people'] ? $sc['people'][0]['name'] : '');
        $row = [
            'id' => $id, 'kind' => $it['kind'], 'kind_label' => b2_kind_label($it['kind']), 'block' => $it['block'], 'title' => $it['title'], 'why' => (string)$it['why'], 'detail' => (string)$it['detail'],
            'section_code' => (string)$it['section_code'], 'section' => $it['section_code'] ? b2_section_name($pdo, $it['section_code']) : 'General',
            'seat_id' => $seatId ?: null, 'seat' => $sc['title'], 'seat_person' => $sc['person'], 'people' => $sc['people'], 'owner_now' => $ownerNow, 'cover' => $run ? (string)$run['cover'] : '',
            'backup_seat_id' => $it['backup_seat_id'] ? (int)$it['backup_seat_id'] : null, 'backup' => $it['backup_seat_id'] ? b2_seat_title($pdo, $it['backup_seat_id']) : '',
            'checker_seat_id' => $it['checker_seat_id'] ? (int)$it['checker_seat_id'] : null, 'checker' => $it['checker_seat_id'] ? b2_seat_title($pdo, $it['checker_seat_id']) : '',
            'dow' => (string)$it['dow'], 'dow_label' => $it['block'] === 'once' ? '' : b2_dow_label($it['dow']), 'dom' => $it['dom'] ? (int)$it['dom'] : null,
            'start_date' => $it['start_date'], 'end_date' => $it['end_date'], 'due_date' => $it['due_date'], 'due_time' => $it['due_time'], 'due_label' => b2_due_label($it), 'grace_min' => (int)$it['grace_min'],
            'proof' => $it['proof'], 'proof_label' => b2_proofs()[$it['proof']] ?? $it['proof'], 'adapter' => $it['adapter'], 'photo_prompt' => (string)$it['photo_prompt'],
            'metric' => $it['metric'], 'unit' => (string)$it['unit'], 'target' => $it['target'] !== null ? (float)$it['target'] : null, 'cmp' => $it['cmp'], 'target_state' => $it['target_state'], 'target_prop' => $it['target_prop'] !== null ? (float)$it['target_prop'] : null,
            'home' => (string)$it['home'], 'pin' => (int)$it['pin'], 'pen_amount' => (float)$it['pen_amount'], 'pen_mode' => (string)($it['pen_mode'] ?? 'flat'), 'pen_unit' => $it['pen_unit'] !== null ? (float)$it['pen_unit'] : null, 'pen_min' => $it['pen_min'] !== null ? (float)$it['pen_min'] : null, 'pen_max' => $it['pen_max'] !== null ? (float)$it['pen_max'] : null, 'pen_note' => (string)$it['pen_note'], 'fair_until' => $it['fair_until'],
            'status' => $it['status'], 'state' => $state, 'state_label' => b2_state_label($state), 'source' => $it['source'], 'problem_id' => $it['problem_id'] ? (int)$it['problem_id'] : null, 'build_id' => $it['build_id'] ? (int)$it['build_id'] : null,
            'blocked_by' => $it['blocked_by'] ? (int)$it['blocked_by'] : null, 'ref_item_id' => $it['ref_item_id'] ? (int)$it['ref_item_id'] : null, 'link_emp' => $it['link_emp'] ? (int)$it['link_emp'] : null, 'link_name' => $it['link_emp'] ? bd_emp_name($pdo, $it['link_emp']) : '',
            'good_days' => (int)$it['good_days'], 'improve_days' => (int)b2_cfg('improve_days'), 'pct' => (int)$it['pct'], 'progress_every' => (int)$it['progress_every'], 'waiting_on' => (string)$it['waiting_on'], 'vendor' => (string)$it['vendor'], 'buy_amount' => $it['buy_amount'] !== null ? (float)$it['buy_amount'] : null,
            'strip' => $strip['days'], 'created_at' => $it['created_at'], 'created_by' => $it['created_by'],
            'today' => $run ? ['run_id' => (int)$run['id'], 'status' => $run['status'], 'value' => $run['value'] !== null ? (float)$run['value'] : null, 'late' => (int)$run['late'], 'proof_state' => $run['proof_state'], 'due_at' => $run['due_at'], 'met' => $run['met'] === null ? null : (int)$run['met'], 'has_proof' => !empty($run['proof_path'])] : null,
            'fair' => $it['fair_until'] && $it['fair_until'] >= $today,
        ];
        $o[] = $row;
    }
    return $o;
}
function b2_item_row(PDO $pdo, $id) { return b2_row($pdo, 'b2_items', $id); }
function b2_item_card(PDO $pdo, $id) { $r = b2_item_row($pdo, $id); if (!$r) return null; $v = b2_items_view($pdo, [$r]); return $v[0]; }

/* ============================== department jobs (read-only mirror of dp_items) ============================== */
function b2_dept_section_of(PDO $pdo, array $r) {
    $seat = (string)$r['seat_code'];
    if ($seat !== '' && $seat !== 'self') { $c = strtok($seat, '.'); if (isset(b2_sections($pdo)[$c])) return $c; }
    $m = ['dispatch' => 'dispatch', 'shops' => 'retaildist', 'supplies' => 'supplies', 'punch' => 'hr'];
    try {
        if ($r['rkey']) { $st = $pdo->prepare("SELECT topic FROM dp_rules WHERE rkey = ?"); $st->execute([$r['rkey']]); $t = (string)$st->fetchColumn(); if (isset($m[$t]) && isset(b2_sections($pdo)[$m[$t]])) return $m[$t]; }
    } catch (Throwable $e) {}
    return '';
}
function b2_dept_rows(PDO $pdo, array $o = []) {
    $out = [];
    try {
        $from = $o['from'] ?? date('Y-m-d', strtotime('-14 days')); $to = $o['to'] ?? b2_day();
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE task_date >= ? AND task_date <= ? ORDER BY task_date DESC, id DESC LIMIT 600"); $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $sec = b2_dept_section_of($pdo, $r);
            if (!empty($o['section']) && $sec !== $o['section']) continue;
            if (!empty($o['emp']) && (int)$r['owner_emp'] !== (int)$o['emp']) continue;
            $out[] = ['src' => 'dept', 'ref' => 'dp' . $r['id'], 'kind' => 'dept', 'kind_label' => 'Department job', 'title' => $r['title'], 'detail' => (string)$r['detail'], 'section_code' => $sec, 'section' => $sec ? b2_section_name($pdo, $sec) : 'General',
                'owner' => $r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : '', 'owner_id' => $r['owner_emp'] ? (int)$r['owner_emp'] : null, 'status' => $r['status'], 'date' => $r['task_date'], 'due_at' => $r['due_at'], 'pen_state' => $r['pen_state'], 'pen_amount' => (float)$r['pen_amount']];
        }
    } catch (Throwable $e) {}
    return $out;
}
function b2_dept_counts(PDO $pdo, $section = null) {
    $c = ['open' => 0, 'done' => 0, 'missed' => 0, 'late' => 0];
    foreach (b2_dept_rows($pdo, ['from' => b2_day(), 'to' => b2_day(), 'section' => $section]) as $r) {
        if ($r['status'] === 'done') $c['done']++; elseif ($r['status'] === 'missed') $c['missed']++; elseif ($r['status'] === 'open') { $c['open']++; if ($r['due_at'] && strtotime($r['due_at']) < time()) $c['late']++; }
    }
    return $c;
}

/* ============================== numbers (with home panels) ============================== */
/** every number task: today's value, target, 14-day series */
function b2_numbers(PDO $pdo, array $o = []) {
    $where = "i.kind = 'number' AND i.status IN ('active','paused')"; $args = [];
    if (!empty($o['section'])) { $where .= " AND i.section_code = ?"; $args[] = $o['section']; }
    if (!empty($o['pinned'])) $where .= " AND i.pin = 1";
    if (!empty($o['scope'])) $where .= " AND " . $o['scope'];
    $st = $pdo->prepare("SELECT i.* FROM b2_items i WHERE $where ORDER BY i.pin DESC, i.id"); $st->execute($args);
    $rows = $st->fetchAll(); if (!$rows) return [];
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $series = []; $q = $pdo->prepare("SELECT item_id, run_date, value, status, met FROM b2_runs WHERE item_id IN (" . bd_in($ids) . ") AND run_date >= ? ORDER BY run_date"); $q->execute([date('Y-m-d', strtotime('-13 days'))]);
    foreach ($q->fetchAll() as $r) $series[(int)$r['item_id']][$r['run_date']] = $r;
    $out = [];
    foreach ($rows as $it) {
        $pts = []; $sum = 0; $cnt = 0; $s = $series[(int)$it['id']] ?? [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime('-' . $i . ' days')); $r = $s[$d] ?? null; $v = ($r && $r['value'] !== null) ? (float)$r['value'] : null; $pts[] = ['d' => $d, 'v' => $v, 'st' => $r ? $r['status'] : '']; if ($v !== null) { $sum += $v; $cnt++; } }
        $todayR = $s[b2_day()] ?? null;
        $out[] = ['id' => (int)$it['id'], 'title' => $it['title'], 'section_code' => (string)$it['section_code'], 'section' => $it['section_code'] ? b2_section_name($pdo, $it['section_code']) : 'General', 'unit' => (string)$it['unit'],
            'target' => $it['target'] !== null ? (float)$it['target'] : null, 'target_state' => $it['target_state'], 'cmp' => $it['cmp'], 'home' => (string)$it['home'], 'pin' => (int)$it['pin'], 'metric' => $it['metric'],
            'today' => $todayR && $todayR['value'] !== null ? (float)$todayR['value'] : null, 'today_status' => $todayR ? $todayR['status'] : '', 'avg14' => $cnt ? round($sum / $cnt, 1) : null, 'days_with_value' => $cnt, 'series' => $pts,
            'last' => (function () use ($pts) { for ($i = count($pts) - 1; $i >= 0; $i--) if ($pts[$i]['v'] !== null) return $pts[$i]; return null; })(),
            'has_home' => (string)$it['home'] !== ''];
    }
    return $out;
}

/* ============================== penalties: only ever ASKED here, the boss's yes decides ============================== */
function b2_pen_month(PDO $pdo, $emp = null, $month = null) {
    $m1 = ($month ?: date('Y-m')) . '-01'; $m2 = date('Y-m-t', strtotime($m1));
    try {
        if ($emp) { $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id = ? AND auto_key LIKE 'b2:%' AND status = 'applied' AND penalty_date >= ? AND penalty_date <= ?"); $q->execute([(int)$emp, $m1, $m2]); }
        else { $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE auto_key LIKE 'b2:%' AND status = 'applied' AND penalty_date >= ? AND penalty_date <= ?"); $q->execute([$m1, $m2]); }
        return (float)$q->fetchColumn();
    } catch (Throwable $e) { return 0.0; }
}
/** the backlog a per-item task started with can only go down: store the lowest count seen */
function b2_pen_base_touch(PDO $pdo, $itemId, $cur) {
    try { $pdo->prepare("UPDATE b2_items SET pen_base = IF(pen_base IS NULL OR pen_base > ?, ?, pen_base) WHERE id = ? AND pen_mode = 'unit'")->execute([(int)$cur, (int)$cur, (int)$itemId]); } catch (Throwable $e) {}
}
/** how many items count for the penalty of this missed run: [units, what]. Zero-type system numbers count only the growth above the starting backlog; target tasks count what is short of (or over) the target. */
function b2_pen_units(PDO $pdo, array $r) {
    $reg = function_exists('b2_checks') ? b2_checks() : [];
    $ad = (string)($r['adapter'] ?? '');
    if ($ad !== '' && isset($reg[$ad]) && $reg[$ad]['mode'] === 'zero') {
        $v = b2_check_value($pdo, $ad); $cur = $v['value'] !== null ? (int)$v['value'] : (isset($r['value']) && $r['value'] !== null ? (int)$r['value'] : null);
        $what = (string)($reg[$ad]['unit'] ?? 'items');
        if ($cur === null) return [0, $what];
        b2_pen_base_touch($pdo, (int)$r['item_id'], $cur);
        $q = $pdo->prepare("SELECT pen_base FROM b2_items WHERE id = ?"); $q->execute([(int)$r['item_id']]); $base = $q->fetchColumn(); $base = ($base === false || $base === null) ? $cur : (int)$base;
        return [max(0, $cur - $base), 'new ' . $what . ' (backlog of ' . $base . ' not counted)'];
    }
    $t = isset($r['target']) && $r['target'] !== null ? (float)$r['target'] : null; $what = trim((string)($r['unit'] ?? '')) ?: 'items';
    $val = isset($r['value']) && $r['value'] !== null ? (float)$r['value'] : null;
    if ($t !== null && ($r['cmp'] ?? 'min') === 'min') return [(int)ceil(max(0, $t - ($val ?? 0))), $what . ' short'];
    if ($t !== null && ($r['cmp'] ?? '') === 'max') return [$val === null ? 0 : (int)ceil(max(0, $val - $t)), $what . ' over'];
    return [0, $what];
}
/** the amount to ASK about for a missed run, and the sum behind it: [amount, text] */
function b2_pen_calc(PDO $pdo, array $r) {
    if (($r['pen_mode'] ?? 'flat') !== 'unit') return [(float)$r['pen_amount'], ''];
    $rate = (float)$r['pen_unit']; $min = (float)$r['pen_min']; $max = (float)$r['pen_max']; if ($max <= 0) $max = 500.0;
    list($units, $what) = b2_pen_units($pdo, $r);
    $raw = round($units * $rate, 2); $amt = round(max($min, min($max, $raw)), 2);
    $txt = $units . ' ' . $what . ' × ' . b2_inr($rate) . ' = ' . b2_inr($raw) . ($raw > $max ? ', capped at ' . b2_inr($max) . ' a day' : ($raw < $min ? ', minimum ' . b2_inr($min) : ''));
    return [$amt, mb_substr($txt, 0, 200)];
}
function b2_pen_ask(PDO $pdo, array $r) {
    if (!$r['owner_emp']) return 0;
    list($amt, $calc) = b2_pen_calc($pdo, $r); if ($amt <= 0) return 0;
    if ($r['fair_until'] && $r['run_date'] <= $r['fair_until']) return 0;                       // the 2-week fair start: no charges
    $live = b2_section_live((string)$r['section_code']);
    $reason = ((string)($r['status'] ?? '') === 'missed' || (string)($r['status'] ?? '') === 'open' || !isset($r['met']) || $r['met'] === null ? 'Missed: ' : 'Short of the target: ') . b2_short($r['title'], 120) . ' (' . date('j M', strtotime($r['run_date'])) . ')';
    $pdo->prepare("INSERT IGNORE INTO b2_pens (item_id, run_id, emp_id, section_code, amount, reason, state, created_at, calc) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$r['item_id'], (int)$r['id'], (int)$r['owner_emp'], $r['section_code'] ?: null, $amt, $reason, $live ? 'ask' : 'shadow', bd_now(), $calc !== '' ? $calc : null]);
    $id = (int)$pdo->lastInsertId();
    if ($id && $live) { $top = (int)bd_cfg_fresh('top_boss'); if ($top) bd_push($pdo, $top, 'Penalty to decide', bd_emp_name($pdo, $r['owner_emp']) . ': ' . b2_short($r['title'], 60) . ' — ' . b2_inr($amt)); }
    return $id;
}
function b2_pen_view(PDO $pdo, array $p) {
    $it = b2_row($pdo, 'b2_items', $p['item_id']);
    $st = $pdo->prepare("SELECT COUNT(*) FROM b2_pens WHERE emp_id = ? AND item_id = ? AND created_at >= ?"); $st->execute([(int)$p['emp_id'], (int)$p['item_id'], date('Y-m-d', strtotime('-30 days'))]);
    $same = (int)$st->fetchColumn();
    $run = $p['run_id'] ? b2_row($pdo, 'b2_runs', $p['run_id']) : null;
    $mm = b2_pen_month($pdo, $p['emp_id']);
    return ['id' => (int)$p['id'], 'item_id' => (int)$p['item_id'], 'item' => $it ? $it['title'] : '', 'kind_label' => $it ? b2_kind_label($it['kind']) : '', 'emp_id' => (int)$p['emp_id'], 'emp' => bd_emp_name($pdo, $p['emp_id']),
        'section_code' => (string)$p['section_code'], 'section' => $p['section_code'] ? b2_section_name($pdo, $p['section_code']) : 'General', 'amount' => (float)$p['amount'], 'calc' => (string)($p['calc'] ?? ''), 'reason' => $p['reason'], 'state' => $p['state'], 'note' => (string)$p['note'],
        'created_at' => $p['created_at'], 'ago' => b2_ago($p['created_at']), 'same_30d' => $same, 'run_date' => $run ? $run['run_date'] : '', 'late_done' => $run ? (int)$run['late'] : 0,
        'month_charged' => $mm, 'cap_person' => (float)b2_cfg('cap_person'), 'decided_by' => $p['decided_by'], 'decided_at' => $p['decided_at']];
}
function b2_pen_list(PDO $pdo, array $w, $states = ['ask'], $limit = 100) {
    $in = implode(',', array_map(function ($s) use ($pdo) { return $pdo->quote($s); }, $states));
    $st = $pdo->query("SELECT * FROM b2_pens WHERE state IN ($in) AND " . b2_scope_sql($pdo, $w, 'section_code') . " ORDER BY id DESC LIMIT " . (int)$limit);
    $o = []; foreach ($st->fetchAll() as $p) $o[] = b2_pen_view($pdo, $p); return $o;
}
/** who has the highest penalty this month (everything the boss engines charged), with asks still waiting */
function b2_pen_top(PDO $pdo, array $w, $n = 3) {
    $m1 = date('Y-m-01'); $m2 = date('Y-m-t'); $rows = [];
    try {
        $st = $pdo->prepare("SELECT employee_id, SUM(amount) a, COUNT(*) c FROM employee_penalties WHERE status = 'applied' AND source = 'boss' AND penalty_date >= ? AND penalty_date <= ? GROUP BY employee_id ORDER BY a DESC LIMIT 8");
        $st->execute([$m1, $m2]);
        foreach ($st->fetchAll() as $r) $rows[(int)$r['employee_id']] = ['emp_id' => (int)$r['employee_id'], 'emp' => bd_emp_name($pdo, $r['employee_id']), 'charged' => (float)$r['a'], 'count' => (int)$r['c'], 'waiting' => 0, 'waiting_amount' => 0.0];
    } catch (Throwable $e) {}
    $q = $pdo->query("SELECT emp_id, COUNT(*) c, SUM(amount) a FROM b2_pens WHERE state = 'ask' AND " . b2_scope_sql($pdo, $w, 'section_code') . " GROUP BY emp_id");
    foreach ($q->fetchAll() as $r) { $e = (int)$r['emp_id']; if (!isset($rows[$e])) $rows[$e] = ['emp_id' => $e, 'emp' => bd_emp_name($pdo, $e), 'charged' => 0.0, 'count' => 0, 'waiting' => 0, 'waiting_amount' => 0.0]; $rows[$e]['waiting'] = (int)$r['c']; $rows[$e]['waiting_amount'] = (float)$r['a']; }
    $rows = array_values($rows); usort($rows, function ($a, $b) { return ($b['charged'] + $b['waiting_amount']) <=> ($a['charged'] + $a['waiting_amount']); });
    return array_slice($rows, 0, $n);
}
/** Charge | Waive | Call | Fix the cause | Move the work.  Returns [ok, message, code, extra] */
function b2_pen_act(PDO $pdo, array $w, $id, $act, array $in, $by) {
    $p = b2_row($pdo, 'b2_pens', $id); if (!$p) return [false, 'Penalty not found.', 404];
    if (!b2_can_section($w, (string)$p['section_code'], $pdo)) return [false, 'This is for the section boss or Admin.', 403];
    if (!in_array($p['state'], ['ask', 'called', 'shadow'], true)) return [false, 'This is already ' . $p['state'] . '.', 409];
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 400);
    $now = bd_now();
    if ($act === 'waive') { $pdo->prepare("UPDATE b2_pens SET state='waived', note=?, decided_by=?, decided_at=? WHERE id=?")->execute([$note, $by, $now, $id]); b2_ev($pdo, (int)$p['item_id'], (int)$p['run_id'], null, (int)$p['emp_id'], 'pen', 'Penalty waived by ' . $by); return [true, 'Waived. ' . bd_emp_name($pdo, $p['emp_id']) . ' is not charged.', 200]; }
    if ($act === 'call') { $pdo->prepare("UPDATE b2_pens SET state='called', note=?, decided_by=?, decided_at=? WHERE id=?")->execute([$note ?: 'Boss will call.', $by, $now, $id]); return [true, 'Marked: you will call ' . bd_emp_name($pdo, $p['emp_id']) . '. You can still charge or waive later.', 200]; }
    if ($act === 'fix') {
        $it = b2_row($pdo, 'b2_items', $p['item_id']);
        $pid = b2_problem_create($pdo, $w, ['title' => 'Why is "' . ($it ? b2_short($it['title'], 70) : 'this task') . '" being missed?', 'body' => $p['reason'] . ' (' . bd_emp_name($pdo, $p['emp_id']) . '). A penalty was suggested; the cause should be fixed first.', 'section_code' => (string)$p['section_code'], 'src' => 'penalty', 'skip_questions' => 1], $by);
        $pdo->prepare("UPDATE b2_pens SET state='fixing', note=?, decided_by=?, decided_at=? WHERE id=?")->execute(['Problem #' . $pid, $by, $now, $id]);
        return [true, 'Opened a problem to find the cause. No one is charged.', 200, ['problem_id' => $pid]];
    }
    if ($act === 'move') {
        $it = b2_row($pdo, 'b2_items', $p['item_id']); if (!$it) return [false, 'Task not found.', 404];
        $seat = !empty($in['emp_id']) ? b2_person_seat($pdo, (int)$in['emp_id']) : (int)($in['seat_id'] ?? 0);
        if (!$seat || !bd_seat_row($pdo, $seat)) return [false, 'Choose who gets this work.', 422];
        $pdo->prepare("UPDATE b2_items SET seat_id = ?, updated_at = ? WHERE id = ?")->execute([$seat, $now, (int)$p['item_id']]);
        $pdo->prepare("UPDATE b2_pens SET state='moved', note=?, decided_by=?, decided_at=? WHERE id=?")->execute(['Moved to ' . b2_seat_title($pdo, $seat), $by, $now, $id]);
        b2_ev($pdo, (int)$p['item_id'], null, null, null, 'moved', 'Work moved to ' . b2_seat_title($pdo, $seat) . ' by ' . $by);
        b2_reassign_item_today($pdo, (int)$p['item_id']);
        return [true, 'The work now belongs to ' . b2_seat_title($pdo, $seat) . '. No one is charged.', 200];
    }
    if ($act !== 'charge') return [false, 'Unknown action.', 422];
    if (!function_exists('penalty_create')) return [false, 'The HRMS penalty engine was not found.', 500];
    $emp = function_exists('penalty_employee') ? penalty_employee($pdo, (int)$p['emp_id']) : bd_emp($pdo, $p['emp_id']);
    if (!$emp) return [false, 'Employee not found.', 404];
    $itx = b2_row($pdo, 'b2_items', $p['item_id']); $lim = max(500.0, $itx ? (float)($itx['pen_max'] ?? 0) : 0.0);
    $amt = round((float)($in['amount'] ?? $p['amount']), 2); if ($amt <= 0 || $amt > $lim) return [false, 'Enter an amount up to ' . b2_inr($lim) . '.', 422];
    $leftP = (float)b2_cfg('cap_person') - b2_pen_month($pdo, $p['emp_id']); $leftT = (float)b2_cfg('cap_total') - b2_pen_month($pdo);
    $left = min($leftP, $leftT);
    if ($left <= 0) {
        $pdo->prepare("UPDATE b2_pens SET state='capped', note=?, decided_by=?, decided_at=? WHERE id=?")->execute([$leftP <= 0 ? 'Monthly limit for this person reached.' : 'Monthly limit for everyone reached.', $by, $now, $id]);
        return [true, 'Not charged: the monthly safety limit is reached (' . ($leftP <= 0 ? bd_emp_name($pdo, $p['emp_id']) . ' ' . b2_inr(b2_cfg('cap_person')) : 'everyone together ' . b2_inr(b2_cfg('cap_total'))) . '). You can change limits in Setup.', 200, ['capped' => true]];
    }
    $amt = min($amt, $left);
    $it = b2_row($pdo, 'b2_items', $p['item_id']);
    $rrow = !empty($p['run_id']) ? b2_row($pdo, 'b2_runs', $p['run_id']) : null; $pdate = $rrow ? $rrow['run_date'] : ($p['created_at'] ? substr($p['created_at'], 0, 10) : b2_day()); if ($pdate > b2_day()) $pdate = b2_day();
    $pid = penalty_create($pdo, $emp, null, 'Task missed: ' . ($it ? $it['title'] : 'task'), $pdate, $amt, 'boss', mb_substr(($p['reason'] ?: 'Missed task') . ($note ? ' — ' . $note : ''), 0, 255), $by, 'b2:' . $id, true);
    $pdo->prepare("UPDATE b2_pens SET state='charged', amount=?, penalty_id=?, note=?, decided_by=?, decided_at=? WHERE id=?")->execute([$amt, $pid ?: null, $note, $by, $now, $id]);
    b2_ev($pdo, (int)$p['item_id'], (int)$p['run_id'], null, (int)$p['emp_id'], 'pen', 'Penalty ' . b2_inr($amt) . ' charged by ' . $by);
    b2_log('penalty', 'b2p' . $id, 'applied ' . b2_inr($amt) . ' to ' . $emp['full_name'], $by);
    return [true, 'Penalty of ' . b2_inr($amt) . ' given to ' . $emp['full_name'] . '.', 200];
}
function b2_reassign_item_today(PDO $pdo, $itemId) {
    $it = b2_row($pdo, 'b2_items', $itemId); if (!$it) return;
    $st = $pdo->prepare("SELECT id FROM b2_runs WHERE item_id = ? AND status = 'open' AND run_date >= ?"); $st->execute([$itemId, b2_day()]);
    foreach ($st->fetchAll() as $r) { list($e, $cover, $orig) = b2_owner($pdo, $it, b2_day()); $pdo->prepare("UPDATE b2_runs SET owner_emp = ?, cover = ? WHERE id = ?")->execute([$e ?: null, $cover ?: null, $r['id']]); }
}

/* ============================== Claude (optional) ============================== */
function b2_ai_on() {
    if (b2_cfg('ai') !== '1') return false;
    if (function_exists('b2_ai_stub')) return true;                                   // tests
    return function_exists('tmph_claude_key') && tmph_claude_key() !== '';
}
/** ask Claude for JSON. Returns the decoded array, or null when Claude is off / failed (callers always have a built-in fallback). */
function b2_ai_json($system, $user, $max = 1000) {
    if (!b2_ai_on()) return null;
    if (function_exists('b2_ai_stub')) { $r = b2_ai_stub($system, $user); return is_array($r) ? $r : null; }
    if (function_exists('brain_ai_json')) return brain_ai_json('internal', '', $system, $user, $max);   // the ONE Claude door (key, model, budget, retry, empty-answer fix) + the team-side company brain; the Boss Engine switch above still decides if Claude is on
    return null;   // brain.php is not loaded: callers keep their built-in fallback
}
/** plain-text answer (for the Ask box). Returns string or ''. */
function b2_ai_text($system, $user, $max = 700) {
    $o = b2_ai_json($system . ' Put your whole answer in {"answer":"..."}.', $user, $max);
    return is_array($o) && !empty($o['answer']) ? trim((string)$o['answer']) : '';
}

/* ============================== words: a built-in reader ============================== */
function b2_words($s) { return bd_tokens(mb_strtolower((string)$s)); }
function b2_has($text, array $words) { $t = ' ' . mb_strtolower((string)$text) . ' '; foreach ($words as $w) { if (mb_strpos($t, $w) !== false) return true; } return false; }
/** which of the 11 routes does this sound like? returns [route, kind]  */
function b2_classify($text) {
    $t = mb_strtolower((string)$text);
    if (b2_has($t, ['licence', 'license', 'expire', 'expiry', 'renew', 'certificate', 'agreement end', 'insurance', 'fssai', 'gst return', 'rent agreement'])) return ['docs', ''];
    if (b2_has($t, ['no system', 'there is no page', 'no report', 'no panel', 'no screen', 'no way to track', 'cannot see', "can't see", 'we need a page', 'need a report', 'software', 'tracking system', 'system for', 'app for', 'dashboard for'])) return ['build', ''];
    if (b2_has($t, ['buy ', 'purchase', 'need to get', 'we need a ', 'we need new', 'out of stock', 'running out', 'ordered', 'order more', 'table', 'chair', 'printer', 'bulb'])) return ['buy', 'buy'];
    if (b2_has($t, ['broken', 'not working', 'repair', 'leak', 'damaged', 'stopped working', 'fix ', 'tap ', 'light is', 'ac is', 'jammed', 'crack'])) return ['fix', 'fix'];
    if (b2_has($t, ['penalty', 'fine ', 'should be charged', 'no consequence', 'nothing happens when'])) return ['rule', ''];
    if (b2_has($t, ['how many', 'count', 'followers', 'likes', 'reviews', 'sales number', 'number of', 'per day', 'target'])) return ['number', 'number'];
    if (b2_has($t, ['every day', 'daily', 'each day', 'every morning', 'every evening', 'every shift', 'always forget', 'keeps forgetting', 'forgets to', 'forget to'])) return ['daily', 'daily'];
    if (b2_has($t, ['again and again', 'keeps happening', 'repeated', 'same mistake', 'improve', 'getting worse', 'slow', 'too many complaints', 'late every'])) return ['improve', 'improve'];
    if (b2_has($t, ['follow up', 'follow-up', 'waiting for', 'no reply', 'not replied', 'chase', 'pending from', 'ask them', 'call back'])) return ['followup', 'followup'];
    if (b2_has($t, ['no problem', 'all fine', 'not a problem', 'ignore this', 'test message', 'just checking'])) return ['closed', ''];
    return ['short', 'short'];
}
function b2_route_label($r) {
    $m = ['daily' => 'Daily task', 'number' => 'Number task', 'short' => 'Short task', 'fix' => 'To fix', 'buy' => 'To buy', 'followup' => 'Follow-up', 'improve' => 'Improve task', 'rule' => 'Penalty rule', 'docs' => 'Document', 'build' => 'Build the system', 'closed' => 'Close'];
    return $m[$r] ?? $r;
}
function b2_route_list() { return ['daily', 'number', 'short', 'fix', 'buy', 'followup', 'improve', 'rule', 'docs', 'build', 'closed']; }
/** guess the section from words or from the page it was raised on */
function b2_guess_section(PDO $pdo, $text, $page = '') {
    $secs = b2_sections($pdo);
    if ($page !== '') { $c = b2_page_section($pdo, $page); if ($c !== '') return $c; }
    $t = mb_strtolower((string)$text); $best = ''; $bn = 0;
    $kw = ['dispatch' => ['parcel', 'courier', 'shiprocket', 'tracking', 'ndr', 'rto', 'packing', 'dispatch', 'awb', 'delivery'], 'store' => ['store', 'shop', 'counter', 'billing', 'pos', 'display', 'customer walk'], 'care' => ['customer', 'complaint', 'whatsapp', 'chat', 'review', 'call back', 'refund'],
        'sales' => ['sale', 'lead', 'enquiry', 'order', 'cod', 'target', 'commission'], 'hr' => ['salary', 'attendance', 'leave', 'punch', 'joining', 'staff'], 'accounts' => ['cash', 'money', 'gst', 'invoice', 'payment', 'expense', 'bank', 'petty'],
        'marketing' => ['instagram', 'post', 'reel', 'story', 'ads', 'flyer', 'poster', 'festival', 'google review', 'followers'], 'supplies' => ['supply', 'stationery', 'tea', 'pantry', 'milk'], 'retaildist' => ['display visit', 'retail shop', 'retailer']];
    foreach ($kw as $code => $ws) { if (!isset($secs[$code])) continue; $n = 0; foreach ($ws as $w) if (mb_strpos($t, $w) !== false) $n++; if ($n > $bn) { $bn = $n; $best = $code; } }
    return $best;
}
function b2_find_person(PDO $pdo, $text) {
    $t = ' ' . mb_strtolower((string)$text) . ' '; $best = 0; $bl = 0;
    foreach (bd_employees($pdo) as $e) {
        $full = mb_strtolower($e['name']); $first = mb_strtolower(preg_split('/\s+/', trim($e['name']))[0]);
        if (mb_strlen($full) > 3 && mb_strpos($t, $full) !== false && mb_strlen($full) > $bl) { $best = (int)$e['id']; $bl = mb_strlen($full); }
        elseif (mb_strlen($first) >= 3 && preg_match('/[^a-z]' . preg_quote($first, '/') . '[^a-z]/u', $t) && mb_strlen($first) > $bl) { $best = (int)$e['id']; $bl = mb_strlen($first); }
    }
    return $best;
}

/* ============================== problems: record > understand > cause > options > route > you decide > work + watch ============================== */
function b2_step_label($s, $forStaff = false) {
    $m = ['asking' => 'Claude has a question', 'decide' => $forStaff ? 'Waiting for your boss' : 'Needs your decision', 'work' => 'Being worked on', 'watch' => $forStaff ? 'Fixed — being watched' : 'Fixed — watching for repeats', 'closed' => 'Closed'];
    return $m[$s] ?? $s;
}
function b2_problem_title_from($body) { $first = trim(preg_split('/[\n.!?]/u', trim((string)$body))[0] ?? ''); return b2_short($first !== '' ? $first : (string)$body, 110); }
function b2_similar_problem(PDO $pdo, $title, $body, $sec) {
    $mine = b2_words($title . ' ' . $body); if (count($mine) < 2) return null;
    $st = $pdo->prepare("SELECT * FROM b2_problems WHERE created_at >= ? ORDER BY id DESC LIMIT 200"); $st->execute([date('Y-m-d', strtotime('-120 days'))]);
    $best = null; $bs = 0;
    foreach ($st->fetchAll() as $p) {
        if ($sec !== '' && $p['section_code'] !== '' && $p['section_code'] !== $sec) continue;
        $s = bd_sim(implode(' ', $mine), implode(' ', b2_words($p['title'] . ' ' . $p['body'])));
        if ($s > $bs) { $bs = $s; $best = $p; }
    }
    return $bs >= 0.5 ? $best : null;
}
function b2_problem_create(PDO $pdo, array $w, array $in, $by) {
    $body = trim((string)($in['body'] ?? '')); $title = trim((string)($in['title'] ?? ''));
    if ($body === '') $body = $title;
    $page = mb_substr((string)($in['page'] ?? ''), 0, 80);
    $sec = (string)($in['section_code'] ?? ''); if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
    if ($sec === '') $sec = b2_guess_section($pdo, $title . ' ' . $body, $page);
    if ($title === '') $title = b2_problem_title_from($body);
    $parent = b2_similar_problem($pdo, $title, $body, $sec);
    $src = (string)($in['src'] ?? ($w['manager'] ? 'boss' : 'staff'));
    $sev = in_array((string)($in['sev'] ?? ''), ['low', 'med', 'high'], true) ? $in['sev'] : (!empty($in['urgent']) ? 'high' : 'med');
    $name = $w['emp'] ? bd_emp_name($pdo, $w['emp']) : $by;
    $pdo->prepare("INSERT INTO b2_problems (title, body, by_emp, by_name, section_code, page, src, sev, step, parent_id, again_n, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([mb_substr($title, 0, 200), $body, $w['emp'] ?: null, mb_substr((string)$name, 0, 80), $sec ?: null, $page ?: null, $src, $sev, 'asking', $parent ? (int)$parent['id'] : null, $parent ? (int)$parent['again_n'] + 1 : 0, bd_now()]);
    $id = (int)$pdo->lastInsertId();
    b2_ev($pdo, null, null, $id, $w['emp'] ?: null, 'reported', b2_short($body, 300));
    if ($parent) {
        b2_ev($pdo, null, null, (int)$parent['id'], null, 'again', 'The same problem was reported again (#' . $id . ').');
        if (in_array($parent['step'], ['watch', 'work'], true)) $pdo->prepare("UPDATE b2_problems SET step = 'closed', closed_reason = ?, closed_at = ?, updated_at = ? WHERE id = ?")->execute(['It came back: see #' . $id, bd_now(), bd_now(), $parent['id']]);
    }
    $p = b2_row($pdo, 'b2_problems', $id);
    $qs = !empty($in['skip_questions']) ? [] : b2_problem_questions($pdo, $p);
    if ($qs) $pdo->prepare("UPDATE b2_problems SET qa = ? WHERE id = ?")->execute([bd_json($qs), $id]);
    else b2_problem_analyse($pdo, $id);
    return $id;
}
function b2_problem_questions(PDO $pdo, array $p) {
    $text = $p['title'] . ' ' . $p['body'];
    $ai = b2_ai_json("You are the Boss Engine of an Indian perfume business HRMS. Somebody reported a problem. Ask at most 3 SHORT plain-English questions, only for what is missing to find the cause (what exactly, where, how often, since when, who). Do not ask what is already clear. Shape: {\"questions\":[{\"q\":\"\",\"why\":\"\"}]}. Return an empty list if the report is already clear.", "REPORT: " . $text . "\nSECTION: " . ($p['section_code'] ?: 'unknown'), 500);
    $out = [];
    if (is_array($ai) && isset($ai['questions']) && is_array($ai['questions'])) {
        foreach (array_slice($ai['questions'], 0, 3) as $i => $q) { $qq = trim((string)($q['q'] ?? '')); if ($qq !== '') $out[] = ['k' => 'q' . ($i + 1), 'q' => mb_substr($qq, 0, 240), 'why' => mb_substr((string)($q['why'] ?? ''), 0, 160), 'type' => 'text', 'a' => null]; }
        return $out;
    }
    if ($p['section_code'] === null || $p['section_code'] === '') {
        $out[] = ['k' => 'q1', 'q' => 'Which part of the business is this about?', 'why' => 'So it goes to the right boss.', 'type' => 'section', 'a' => null];
    }
    if (!b2_has($text, ['every', 'daily', 'today', 'yesterday', 'since', 'times', 'often', 'sometimes', 'always', 'again', 'last week', 'this week', 'morning', 'evening'])) {
        $out[] = ['k' => 'q' . (count($out) + 1), 'q' => 'How often does this happen, and since when?', 'why' => 'One time and every day are fixed in different ways.', 'type' => 'text', 'a' => null, 'chips' => ['Every day', 'A few times a week', 'First time', 'Since last month']];
    }
    if (mb_strlen($text) < 70 && !b2_find_person($pdo, $text)) {
        $out[] = ['k' => 'q' . (count($out) + 1), 'q' => 'Who or what is affected? (a person, a parcel, a machine, a customer…)', 'why' => 'So the right owner is chosen.', 'type' => 'text', 'a' => null];
    }
    return array_slice($out, 0, 3);
}
function b2_problem_answer(PDO $pdo, array $w, $id, array $answers, $skip = false) {
    $p = b2_row($pdo, 'b2_problems', $id); if (!$p) return [false, 'Problem not found.', 404];
    $mine = $w['emp'] && (int)$p['by_emp'] === (int)$w['emp'];
    if (!$mine && !$w['manager']) return [false, 'This is not your report.', 403];
    if ($p['step'] !== 'asking') return [true, 'Already understood.', 200];
    $qa = bd_unjson($p['qa'], []); $sec = $p['section_code'];
    foreach ($qa as &$q) {
        if (isset($answers[$q['k']])) {
            $a = trim((string)$answers[$q['k']]); $q['a'] = mb_substr($a, 0, 400);
            if ($q['type'] === 'section' && isset(b2_sections($pdo)[$a])) $sec = $a;
        }
    } unset($q);
    $extra = ''; foreach ($qa as $q) if (!empty($q['a'])) $extra .= "\n" . $q['q'] . ' ' . $q['a'];
    $pdo->prepare("UPDATE b2_problems SET qa = ?, section_code = ?, body = CONCAT(body, ?), updated_at = ? WHERE id = ?")->execute([bd_json($qa), $sec ?: null, $extra, bd_now(), $id]);
    if ($extra !== '') b2_ev($pdo, null, null, (int)$id, $w['emp'] ?: null, 'answered', b2_short($extra, 400));
    b2_problem_analyse($pdo, $id);
    return [true, 'Thank you. Claude is looking into it.', 200];
}

/* ----- evidence: what the records actually show ----- */
function b2_evidence(PDO $pdo, array $p) {
    $sec = (string)$p['section_code']; $text = $p['title'] . ' ' . $p['body']; $ev = [];
    $since = date('Y-m-d', strtotime('-7 days')); $personId = b2_find_person($pdo, $text);
    $args = [$since]; $w = "r.status = 'missed' AND r.run_date >= ?"; if ($sec !== '') { $w .= " AND i.section_code = ?"; $args[] = $sec; }
    $st = $pdo->prepare("SELECT i.id, i.title, r.owner_emp, COUNT(*) c FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE $w GROUP BY i.id, r.owner_emp ORDER BY c DESC LIMIT 5"); $st->execute($args);
    foreach ($st->fetchAll() as $r) {
        $sim = bd_sim(implode(' ', b2_words($text)), implode(' ', b2_words($r['title'])));
        $ev[] = ['t' => 'miss', 'text' => ($r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : 'Nobody') . ' missed "' . b2_short($r['title'], 70) . '" ' . $r['c'] . ' time' . ((int)$r['c'] === 1 ? '' : 's') . ' in 7 days.', 'n' => (int)$r['c'], 'item' => (int)$r['id'], 'emp' => (int)$r['owner_emp'], 'sim' => round($sim, 2), 'strong' => (int)$r['c'] >= 3];
    }
    $args = [b2_day()]; $w = "r.status IN ('open','missed') AND i.block = 'once' AND r.due_at < NOW() AND i.status = 'active' AND r.run_date <= ?"; if ($sec !== '') { $w .= " AND i.section_code = ?"; $args[] = $sec; }
    $st = $pdo->prepare("SELECT i.id, i.title, r.owner_emp, r.run_date FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE $w ORDER BY r.run_date LIMIT 4"); $st->execute($args);
    foreach ($st->fetchAll() as $r) $ev[] = ['t' => 'overdue', 'text' => '"' . b2_short($r['title'], 70) . '" is overdue since ' . date('j M', strtotime($r['run_date'])) . ($r['owner_emp'] ? ' (' . bd_emp_name($pdo, $r['owner_emp']) . ')' : '') . '.', 'item' => (int)$r['id'], 'strong' => false];
    // seats with no holder that carry tasks
    $args = []; $w = "i.status = 'active' AND i.seat_id IS NOT NULL"; if ($sec !== '') { $w .= " AND i.section_code = ?"; $args[] = $sec; }
    $st = $pdo->prepare("SELECT i.seat_id, COUNT(*) c FROM b2_items i WHERE $w GROUP BY i.seat_id"); $st->execute($args);
    foreach ($st->fetchAll() as $r) { if (!bd_holders($pdo, (int)$r['seat_id'])) $ev[] = ['t' => 'seat', 'text' => 'Nobody holds "' . b2_seat_title($pdo, (int)$r['seat_id']) . '", which carries ' . $r['c'] . ' task' . ((int)$r['c'] === 1 ? '' : 's') . '.', 'seat' => (int)$r['seat_id'], 'strong' => true]; }
    // department jobs missed
    try {
        $st = $pdo->prepare("SELECT title, owner_emp, seat_code, rkey FROM dp_items WHERE status = 'missed' AND task_date >= ? LIMIT 80"); $st->execute([$since]); $byT = [];
        foreach ($st->fetchAll() as $r) { if ($sec !== '' && b2_dept_section_of($pdo, $r) !== $sec) continue; $byT[$r['title']] = ($byT[$r['title']] ?? 0) + 1; }
        arsort($byT); foreach (array_slice($byT, 0, 2, true) as $t => $c) $ev[] = ['t' => 'dept', 'text' => 'The department job "' . b2_short($t, 70) . '" was missed ' . $c . ' time' . ($c === 1 ? '' : 's') . ' this week.', 'n' => $c, 'strong' => $c >= 3];
    } catch (Throwable $e) {}
    // is there any task that covers this topic?
    $args = []; $w = "status IN ('active','draft','paused')"; if ($sec !== '') { $w .= " AND section_code = ?"; $args[] = $sec; }
    $st = $pdo->prepare("SELECT id, title, kind FROM b2_items WHERE $w LIMIT 400"); $st->execute($args);
    $cover = null; $cs = 0;
    foreach ($st->fetchAll() as $r) { $s = bd_sim(implode(' ', b2_words($text)), implode(' ', b2_words($r['title']))); if ($s > $cs) { $cs = $s; $cover = $r; } }
    if ($cover && $cs >= 0.3) $ev[] = ['t' => 'cover', 'text' => 'A task already covers this: "' . b2_short($cover['title'], 70) . '" (' . b2_kind_label($cover['kind']) . ').', 'item' => (int)$cover['id'], 'strong' => false];
    else $ev[] = ['t' => 'nocover', 'text' => 'No task in ' . ($sec ? b2_section_name($pdo, $sec) : 'any section') . ' covers this yet.', 'strong' => false];
    $parent = !empty($p['parent_id']) ? b2_row($pdo, 'b2_problems', $p['parent_id']) : null;
    if ($parent) $ev[] = ['t' => 'again', 'text' => 'This was reported before (#' . $parent['id'] . ', ' . date('j M', strtotime($parent['created_at'])) . ') and "' . b2_short($parent['title'], 60) . '" did not hold. This is time ' . ((int)$p['again_n'] + 1) . '.', 'strong' => true];
    if ($personId) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_runs WHERE owner_emp = ? AND status = 'missed' AND run_date >= ?"); $q->execute([$personId, $since]); $mc = (int)$q->fetchColumn();
        $ev[] = ['t' => 'person', 'text' => bd_emp_name($pdo, $personId) . ' has missed ' . $mc . ' task' . ($mc === 1 ? '' : 's') . ' in the last 7 days.', 'emp' => $personId, 'n' => $mc, 'strong' => $mc >= 3];
    }
    return $ev;
}
/** the cause, honestly levelled: confirmed (records show it) / likely (pattern) / unknown */
function b2_cause_builtin(PDO $pdo, array $p, array $ev) {
    foreach ($ev as $e) if ($e['t'] === 'seat') return ['level' => 'confirmed', 'text' => $e['text'] . ' So the work has nobody to land on.'];
    $text = $p['title'] . ' ' . $p['body'];
    foreach ($ev as $e) if ($e['t'] === 'miss' && !empty($e['strong']) && ($e['sim'] ?? 0) >= 0.25) return ['level' => 'confirmed', 'text' => $e['text'] . ' The records show the work is simply not being done.'];
    foreach ($ev as $e) if ($e['t'] === 'again') return ['level' => 'likely', 'text' => 'It keeps coming back, so the earlier fix addressed the symptom, not the cause.'];
    foreach ($ev as $e) if ($e['t'] === 'person' && !empty($e['strong'])) return ['level' => 'likely', 'text' => $e['text'] . ' This looks like a habit or a workload problem for one person.'];
    foreach ($ev as $e) if ($e['t'] === 'miss' && !empty($e['strong'])) return ['level' => 'likely', 'text' => $e['text']];
    foreach ($ev as $e) if ($e['t'] === 'nocover') return ['level' => 'likely', 'text' => 'Nobody is asked to do this: there is no task for it, so it depends on somebody remembering.'];
    return ['level' => 'unknown', 'text' => 'The records do not show the cause yet. Someone has to look at it on the spot.'];
}
function b2_suggest_seat(PDO $pdo, $sec, $text, $route = '') {
    $p = b2_find_person($pdo, $text);
    $sid = b2_section_id($pdo, $sec);
    if ($route === 'improve' && $p) return [b2_person_seat($pdo, $p), bd_emp_name($pdo, $p), $p];
    foreach (['worker', 'checker', 'section_boss'] as $kind) { $seat = $sid ? bd_section_seat($pdo, $sid, $kind) : 0; if ($seat && bd_holders($pdo, $seat)) return [$seat, b2_seat_title($pdo, $seat), 0]; }
    $tb = bd_seat_id($pdo, 'top.boss'); if ($tb && bd_holders($pdo, $tb)) return [$tb, b2_seat_title($pdo, $tb), 0];
    return [0, '', 0];
}
function b2_option(PDO $pdo, array $p, $route, $label, $why, array $over = []) {
    $text = $p['title'] . ' ' . $p['body']; $sec = (string)$p['section_code'];
    list($seat, $seatName, $emp) = b2_suggest_seat($pdo, $sec, $text, $route);
    $kinds = ['daily' => 'daily', 'number' => 'number', 'short' => 'short', 'fix' => 'fix', 'buy' => 'buy', 'followup' => 'followup', 'improve' => 'improve'];
    $o = ['route' => $route, 'route_label' => b2_route_label($route), 'label' => $label, 'why' => $why, 'draft' => []];
    if (isset($kinds[$route])) {
        $o['draft'] = ['kind' => $kinds[$route], 'title' => b2_short($p['title'], 110), 'why' => b2_short($p['body'], 300), 'section_code' => $sec, 'seat_id' => $seat ?: null, 'seat' => $seatName, 'due_time' => (string)b2_cfg('cutoff'), 'pen_amount' => 0];
        if ($route === 'improve' && $emp) { $o['draft']['link_emp'] = $emp; $o['draft']['title'] = 'Improve: ' . b2_short($p['title'], 90); }
        if ($route === 'daily' && stripos($text, 'check') === false) $o['draft']['title'] = b2_short($p['title'], 110);
    } elseif ($route === 'build') $o['draft'] = ['title' => b2_short($p['title'], 120), 'spec' => b2_build_spec_from($pdo, $p), 'section_code' => $sec];
    elseif ($route === 'docs') $o['draft'] = ['title' => b2_short($p['title'], 120), 'section_code' => $sec, 'seat_id' => $seat ?: null, 'seat' => $seatName];
    elseif ($route === 'rule') $o['draft'] = ['pen_amount' => 50, 'item_id' => 0];
    elseif ($route === 'closed') $o['draft'] = ['reason' => ''];
    foreach ($over as $k => $v) $o['draft'][$k] = $v;
    return $o;
}
function b2_build_spec_from(PDO $pdo, array $p) {
    return "WHAT IS MISSING\n" . trim($p['body']) . "\n\nWHY IT MATTERS\nReported by " . ($p['by_name'] ?: 'the boss') . ' on ' . date('j M Y', strtotime($p['created_at'])) . ($p['section_code'] ? ' for ' . b2_section_name($pdo, $p['section_code']) : '') . ".\n\nWHAT THE SYSTEM MUST DO\n- (Claude and the boss fill this in together before building)\n\nHOW WE KNOW IT WORKS\n- One number or one list the boss can open and see.\n- A task owner is named for it.";
}
function b2_problem_options(PDO $pdo, array $p, array $cause, array $ev) {
    $text = $p['title'] . ' ' . $p['body']; list($route) = b2_classify($text);
    $opts = [];
    $seatEv = null; $personEv = null; $missEv = null; $coverEv = null;
    foreach ($ev as $e) { if ($e['t'] === 'seat' && !$seatEv) $seatEv = $e; if ($e['t'] === 'person' && !empty($e['strong']) && !$personEv) $personEv = $e; if ($e['t'] === 'miss' && !empty($e['strong']) && !$missEv) $missEv = $e; if ($e['t'] === 'cover' && !$coverEv) $coverEv = $e; }
    if ($seatEv) {
        $opts[] = b2_option($pdo, $p, 'followup', 'Ask HR to put a person on the empty seat', 'The tasks cannot run until someone holds the seat.', ['title' => 'Put a person on the seat: ' . b2_seat_title($pdo, $seatEv['seat']), 'kind' => 'followup']);
    }
    if ($personEv && $personEv['emp']) {
        $opts[] = b2_option($pdo, $p, 'improve', 'Make an Improve task for ' . bd_emp_name($pdo, $personEv['emp']), 'It closes by itself after ' . (int)b2_cfg('improve_days') . ' good days in a row.', ['link_emp' => $personEv['emp'], 'title' => 'Improve: ' . bd_emp_name($pdo, $personEv['emp']) . ' — no missed tasks', 'seat_id' => b2_person_seat($pdo, $personEv['emp']), 'seat' => bd_emp_name($pdo, $personEv['emp'])]);
    }
    if ($missEv && $missEv['item']) {
        $opts[] = b2_option($pdo, $p, 'rule', 'Put a penalty on the task that keeps being missed', 'You still decide every single charge. The first 14 days are free.', ['item_id' => $missEv['item'], 'pen_amount' => 50]);
    }
    if ($coverEv && !$missEv) {
        $opts[] = b2_option($pdo, $p, 'followup', 'Check why the existing task is not solving it', 'A task exists already, so no duplicate is made.', ['title' => 'Check: ' . b2_short($p['title'], 90), 'kind' => 'followup']);
    }
    if (count($opts) < 2) {
        $label = ['daily' => 'Make it a daily task', 'number' => 'Track it as a number every day', 'short' => 'Make one short task', 'fix' => 'Make a to-fix task', 'buy' => 'Make a to-buy task', 'followup' => 'Make a follow-up', 'improve' => 'Make an Improve task', 'rule' => 'Set a penalty rule', 'docs' => 'Track it as a document with a date', 'build' => 'Add it to the Build system list', 'closed' => 'Close it'][$route] ?? 'Make a task';
        $why = ['build' => 'The system to do this does not exist yet, so a spec is made and tasks wait for it.', 'docs' => 'You will get reminders before the date.', 'closed' => 'Nothing needs to be done.'][$route] ?? 'One clear owner and one deadline.';
        $opts[] = b2_option($pdo, $p, $route === 'rule' ? 'short' : $route, $label, $why);
    }
    if ($cause['level'] === 'unknown' && !array_filter($opts, function ($o) { return $o['route'] === 'followup'; })) $opts[] = b2_option($pdo, $p, 'followup', 'Ask somebody to look at it and report back', 'The cause is not known yet.', ['title' => 'Look into: ' . b2_short($p['title'], 90)]);
    if (!array_filter($opts, function ($o) { return $o['route'] === 'build'; }) && $route === 'build') $opts[] = b2_option($pdo, $p, 'build', 'Add it to the Build system list', 'The system to do this does not exist yet.');
    $opts = array_slice($opts, 0, 3);
    foreach ($opts as $i => &$o) $o['id'] = chr(97 + $i); unset($o);
    return $opts;
}
function b2_problem_analyse(PDO $pdo, $id) {
    $p = b2_row($pdo, 'b2_problems', $id); if (!$p) return;
    $ev = b2_evidence($pdo, $p);
    $cause = b2_cause_builtin($pdo, $p, $ev); $opts = null;
    $evText = implode("\n", array_map(function ($e) { return '- ' . $e['text']; }, $ev));
    $ai = b2_ai_json("You are the Boss Engine of an Indian perfume business. A problem was reported. Use ONLY the evidence lines to judge the cause. Level 'confirmed' only if the evidence directly shows it, 'likely' if it is a pattern, 'unknown' otherwise - never guess. Offer 2-3 options, each routed to exactly one of: daily, number, short, fix, buy, followup, improve, rule, docs, build, closed. Plain short English. Shape: {\"cause\":{\"level\":\"confirmed|likely|unknown\",\"text\":\"\"},\"options\":[{\"route\":\"\",\"label\":\"\",\"why\":\"\",\"title\":\"\"}]}.", "PROBLEM: " . $p['title'] . "\n" . $p['body'] . "\nSECTION: " . ($p['section_code'] ?: 'unknown') . "\nEVIDENCE:\n" . ($evText ?: '(none)'), 900);
    if (is_array($ai) && isset($ai['cause']['level']) && in_array($ai['cause']['level'], ['confirmed', 'likely', 'unknown'], true) && !empty($ai['cause']['text'])) {
        $lvl = $ai['cause']['level']; $hasStrong = false; foreach ($ev as $e) if (!empty($e['strong'])) $hasStrong = true;
        if ($lvl === 'confirmed' && !$hasStrong) $lvl = 'likely';                    // Claude may not claim more than the records show
        $cause = ['level' => $lvl, 'text' => mb_substr(trim((string)$ai['cause']['text']), 0, 400)];
        $o2 = []; $routes = b2_route_list();
        foreach (array_slice((array)($ai['options'] ?? []), 0, 3) as $o) {
            $r = (string)($o['route'] ?? ''); if (!in_array($r, $routes, true)) continue;
            $over = []; if (!empty($o['title'])) $over['title'] = mb_substr((string)$o['title'], 0, 110);
            $o2[] = b2_option($pdo, $p, $r, mb_substr((string)($o['label'] ?? b2_route_label($r)), 0, 120), mb_substr((string)($o['why'] ?? ''), 0, 200), $over);
        }
        if ($o2) { foreach ($o2 as $i => &$o) $o['id'] = chr(97 + $i); unset($o); $opts = $o2; }
    }
    if ($opts === null) $opts = b2_problem_options($pdo, $p, $cause, $ev);
    $pdo->prepare("UPDATE b2_problems SET step = 'decide', cause_level = ?, cause_text = ?, evidence = ?, options = ?, updated_at = ? WHERE id = ? AND step IN ('asking','decide')")
        ->execute([$cause['level'], $cause['text'], bd_json($ev), bd_json($opts), bd_now(), $id]);
    b2_ev($pdo, null, null, (int)$id, null, 'cause', ucfirst($cause['level']) . ': ' . $cause['text']);
    if (!$p['by_emp'] || (int)$p['by_emp'] !== (int)bd_cfg_fresh('top_boss')) {
        $top = (int)bd_cfg_fresh('top_boss'); if ($top) bd_push($pdo, $top, 'Problem to decide', b2_short($p['title'], 100));
    }
}
function b2_problem_view(PDO $pdo, array $p, $staff = false) {
    $qa = bd_unjson($p['qa'], []); $opts = bd_unjson($p['options'], []); $ev = bd_unjson($p['evidence'], []);
    $v = ['id' => (int)$p['id'], 'title' => $p['title'], 'body' => $p['body'], 'by' => $p['by_name'], 'by_emp' => $p['by_emp'] ? (int)$p['by_emp'] : null, 'section_code' => (string)$p['section_code'], 'section' => $p['section_code'] ? b2_section_name($pdo, $p['section_code']) : 'Not sure yet',
        'page' => (string)$p['page'], 'src' => $p['src'], 'sev' => $p['sev'], 'step' => $p['step'], 'step_label' => b2_step_label($p['step'], $staff), 'qa' => $qa, 'created_at' => $p['created_at'], 'ago' => b2_ago($p['created_at']),
        'again_n' => (int)$p['again_n'], 'parent_id' => $p['parent_id'] ? (int)$p['parent_id'] : null, 'route' => (string)$p['route'], 'route_label' => $p['route'] ? b2_route_label($p['route']) : '', 'route_ref' => (string)$p['route_ref'],
        'closed_reason' => (string)$p['closed_reason'], 'watch_until' => $p['watch_until'], 'watch_from' => $p['watch_from']];
    $v['days_clean'] = $p['watch_from'] ? max(0, (int)floor((strtotime(b2_day()) - strtotime($p['watch_from'])) / 86400)) : 0;
    $v['watch_days'] = (int)b2_cfg('watch_days');
    if ($staff) { $v['result'] = $p['step'] === 'closed' ? ($p['closed_reason'] ?: 'Closed.') : ($p['step'] === 'work' || $p['step'] === 'watch' ? 'Your boss decided: ' . b2_route_label($p['route']) . '.' : ''); return $v; }
    $lvl = ['confirmed' => 'Confirmed', 'likely' => 'Likely', 'unknown' => 'Unknown'];
    $v['cause'] = $p['cause_level'] ? ['level' => $p['cause_level'], 'label' => $lvl[$p['cause_level']] ?? $p['cause_level'], 'text' => $p['cause_text']] : null;
    $v['evidence'] = $ev; $v['options'] = $opts;
    $st = $pdo->prepare("SELECT kind, text, created_at, emp_id FROM b2_ev WHERE problem_id = ? ORDER BY id"); $st->execute([(int)$p['id']]);
    $v['thread'] = array_map(function ($r) use ($pdo) { return ['kind' => $r['kind'], 'text' => $r['text'], 'at' => $r['created_at'], 'who' => $r['emp_id'] ? bd_emp_name($pdo, $r['emp_id']) : '']; }, $st->fetchAll());
    return $v;
}
/** the boss picks an option (and may change owner / title / due) -> the thing is created */
function b2_problem_pick(PDO $pdo, array $w, $id, $optId, array $over, $by) {
    $p = b2_row($pdo, 'b2_problems', $id); if (!$p) return [false, 'Problem not found.', 404];
    if (!b2_can_section($w, (string)$p['section_code'], $pdo) && !($w['manager'] && !$p['section_code'])) return [false, 'This is for the section boss or Admin.', 403];
    if (!in_array($p['step'], ['decide', 'asking'], true)) return [false, 'This problem is already ' . b2_step_label($p['step']) . '.', 409];
    $opts = bd_unjson($p['options'], []); $opt = null; foreach ($opts as $o) if ($o['id'] === $optId) $opt = $o;
    if (!$opt) return [false, 'Choose one of the options.', 422];
    $route = $opt['route']; $d = array_merge($opt['draft'], array_filter($over, function ($v) { return $v !== null && $v !== ''; }));
    $ref = ''; $msg = '';
    if (in_array($route, ['daily', 'number', 'short', 'fix', 'buy', 'followup', 'improve'], true)) {
        $d['kind'] = $route; $d['problem_id'] = (int)$id; if (empty($d['section_code'])) $d['section_code'] = (string)$p['section_code'];
        if ($route === 'improve' && empty($d['link_emp']) && empty($d['ref_item_id'])) return [false, 'Choose the person this is about.', 422];
        if ($route === 'daily' || $route === 'number') $d['start_date'] = b2_day();
        list($c, $err) = b2_item_clean($pdo, $d); if (!$c) return [false, $err, 422];
        $iid = b2_item_insert($pdo, $c, 'active', 'problem', $by); $ref = 'item:' . $iid;
        $msg = 'Done. "' . b2_short($c['title'], 60) . '" is now ' . b2_kind_label($route) . ' work for ' . b2_seat_title($pdo, $c['seat_id']) . '.';
    } elseif ($route === 'rule') {
        $iid = (int)($d['item_id'] ?? 0); $it = b2_row($pdo, 'b2_items', $iid); if (!$it) return [false, 'Choose the task the penalty belongs to.', 422];
        $amt = (float)($d['pen_amount'] ?? 0); if ($amt <= 0 || $amt > 500) return [false, 'Enter an amount up to ₹500.', 422];
        $pdo->prepare("UPDATE b2_items SET pen_amount = ?, pen_note = ?, updated_at = ? WHERE id = ?")->execute([$amt, 'From problem #' . $id, bd_now(), $iid]);
        b2_ev($pdo, $iid, null, (int)$id, null, 'rule', 'Penalty suggestion ' . b2_inr($amt) . ' set; every charge still needs the boss.'); $ref = 'item:' . $iid;
        $msg = 'Done. Missing "' . b2_short($it['title'], 50) . '" will now ask you about ' . b2_inr($amt) . ' (never automatic).';
    } elseif ($route === 'docs') {
        $exp = b2_clean_date($d['expires_on'] ?? null, null);
        $pdo->prepare("INSERT INTO b2_docs (title, kind, section_code, seat_id, expires_on, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([mb_substr((string)($d['title'] ?? $p['title']), 0, 160), 'licence', $p['section_code'] ?: null, !empty($d['seat_id']) ? (int)$d['seat_id'] : null, $exp, $exp ? 'active' : 'draft', $by, bd_now()]);
        $ref = 'doc:' . (int)$pdo->lastInsertId(); $msg = $exp ? 'Document added. You will be reminded before it expires.' : 'Document added. Open Documents and enter the date so reminders can start.';
    } elseif ($route === 'build') {
        $pdo->prepare("INSERT INTO b2_builds (title, spec, why, section_code, status, problem_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([mb_substr((string)($d['title'] ?? $p['title']), 0, 200), (string)($d['spec'] ?? b2_build_spec_from($pdo, $p)), b2_short($p['body'], 480), $p['section_code'] ?: null, 'spec', (int)$id, $by, bd_now()]);
        $ref = 'build:' . (int)$pdo->lastInsertId(); $msg = 'Added to the Build system list with a spec. Tasks that need it will wait for it.';
    } elseif ($route === 'closed') {
        $why = trim((string)($d['reason'] ?? '')); if (mb_strlen($why) < 3) return [false, 'Write the reason for closing.', 422];
        return b2_problem_close($pdo, $w, $id, $why, $by);
    }
    $pdo->prepare("UPDATE b2_problems SET route = ?, route_ref = ?, choice = ?, step = 'work', updated_at = ? WHERE id = ?")->execute([$route, $ref, $optId, bd_now(), $id]);
    b2_ev($pdo, null, null, (int)$id, $w['emp'] ?: null, 'decided', $by . ' chose: ' . ($opt['label'] ?? $route));
    if ($p['by_emp']) bd_push($pdo, (int)$p['by_emp'], 'Your report was decided', b2_short($p['title'], 60) . ' — ' . b2_route_label($route));
    if ($route === 'rule' || $route === 'docs') { $pdo->prepare("UPDATE b2_problems SET step = 'watch', watch_from = ?, watch_until = ? WHERE id = ?")->execute([b2_day(), date('Y-m-d', strtotime('+' . (int)b2_cfg('watch_days') . ' days')), $id]); }
    return [true, $msg, 200, ['ref' => $ref]];
}
function b2_problem_close(PDO $pdo, array $w, $id, $reason, $by) {
    $p = b2_row($pdo, 'b2_problems', $id); if (!$p) return [false, 'Problem not found.', 404];
    if (!b2_can_section($w, (string)$p['section_code'], $pdo) && !$w['manager']) return [false, 'This is for the section boss or Admin.', 403];
    $reason = mb_substr(trim((string)$reason), 0, 300); if (mb_strlen($reason) < 3) return [false, 'Write the reason for closing.', 422];
    $pdo->prepare("UPDATE b2_problems SET step = 'closed', route = COALESCE(route, 'closed'), closed_reason = ?, closed_at = ?, updated_at = ? WHERE id = ?")->execute([$reason, bd_now(), bd_now(), $id]);
    b2_ev($pdo, null, null, (int)$id, $w['emp'] ?: null, 'closed', $by . ': ' . $reason);
    if ($p['by_emp']) bd_push($pdo, (int)$p['by_emp'], 'Your report was closed', b2_short($p['title'], 60) . ' — ' . $reason);
    return [true, 'Closed with your reason.', 200];
}
/** work finished > watch > 30 clean days > closed */
function b2_problems_tick(PDO $pdo) {
    $n = 0; $today = b2_day();
    $st = $pdo->query("SELECT * FROM b2_problems WHERE step IN ('work','watch')");
    foreach ($st->fetchAll() as $p) {
        if ($p['step'] === 'work') {
            $done = false; $ref = (string)$p['route_ref'];
            if (strpos($ref, 'item:') === 0) { $it = b2_row($pdo, 'b2_items', (int)substr($ref, 5)); $done = !$it || in_array($it['status'], ['done', 'archived'], true) || ($it['block'] !== 'once' && false); if ($it && $it['block'] !== 'once' && $it['status'] === 'active') { $done = (strtotime($today) - strtotime(substr($it['created_at'], 0, 10))) >= 7 * 86400 && b2_item_recent_ok($pdo, (int)$it['id']); } }
            elseif (strpos($ref, 'build:') === 0) { $b = b2_row($pdo, 'b2_builds', (int)substr($ref, 6)); $done = !$b || $b['status'] === 'live'; }
            elseif (strpos($ref, 'doc:') === 0) { $done = true; }
            if ($done) { $pdo->prepare("UPDATE b2_problems SET step = 'watch', watch_from = ?, watch_until = ?, updated_at = ? WHERE id = ?")->execute([$today, date('Y-m-d', strtotime('+' . (int)b2_cfg('watch_days') . ' days')), bd_now(), $p['id']]); b2_ev($pdo, null, null, (int)$p['id'], null, 'watch', 'Fixed. Watching for ' . (int)b2_cfg('watch_days') . ' clean days.'); $n++; }
        } elseif ($p['watch_until'] && $today > $p['watch_until']) {
            $pdo->prepare("UPDATE b2_problems SET step = 'closed', closed_reason = ?, closed_at = ?, updated_at = ? WHERE id = ?")->execute(['No repeat for ' . (int)b2_cfg('watch_days') . ' days. Solved.', bd_now(), bd_now(), $p['id']]);
            b2_ev($pdo, null, null, (int)$p['id'], null, 'closed', 'No repeat for ' . (int)b2_cfg('watch_days') . ' days. Solved.');
            if ($p['by_emp']) bd_push($pdo, (int)$p['by_emp'], 'Solved', b2_short($p['title'], 70) . ' has not come back for ' . (int)b2_cfg('watch_days') . ' days.'); $n++;
        }
    }
    return $n;
}
function b2_item_recent_ok(PDO $pdo, $itemId) { $st = $pdo->prepare("SELECT COUNT(*) FROM b2_runs WHERE item_id = ? AND run_date >= ? AND status = 'missed'"); $st->execute([$itemId, date('Y-m-d', strtotime('-5 days'))]); return (int)$st->fetchColumn() === 0; }

/* ============================== alerts (holes the engine found) ============================== */
function b2_alert(PDO $pdo, $kind, $text, $sec, $refKey) {
    try {
        $st = $pdo->prepare("SELECT id, state FROM b2_alerts WHERE ref_key = ?"); $st->execute([$refKey]); $r = $st->fetch();
        if ($r) return (int)$r['id'];
        $pdo->prepare("INSERT INTO b2_alerts (kind, text, section_code, ref_key, state, created_at) VALUES (?,?,?,?,?,?)")->execute([$kind, mb_substr($text, 0, 400), $sec ?: null, $refKey, 'open', bd_now()]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return 0; }
}
function b2_alert_close(PDO $pdo, $refKey) { try { $pdo->prepare("UPDATE b2_alerts SET state = 'closed', closed_at = ? WHERE ref_key = ? AND state = 'open'")->execute([bd_now(), $refKey]); } catch (Throwable $e) {} }

/* ============================== Build system ============================== */
function b2_build_status_label($s) { $m = ['spec' => 'Spec ready', 'building' => 'Building', 'live' => 'Live', 'dropped' => 'Dropped']; return $m[$s] ?? $s; }
function b2_build_view(PDO $pdo, array $b, $full = false) {
    $st = $pdo->prepare("SELECT id, title, status, kind, seat_id FROM b2_items WHERE build_id = ? AND status IN ('draft','active','paused') ORDER BY id"); $st->execute([(int)$b['id']]);
    $waiting = array_map(function ($r) use ($pdo) { return ['id' => (int)$r['id'], 'title' => $r['title'], 'status' => $r['status'], 'kind_label' => b2_kind_label($r['kind']), 'owner' => b2_seat_title($pdo, $r['seat_id'])]; }, $st->fetchAll());
    $v = ['id' => (int)$b['id'], 'title' => $b['title'], 'why' => (string)$b['why'], 'section_code' => (string)$b['section_code'], 'section' => $b['section_code'] ? b2_section_name($pdo, $b['section_code']) : 'General', 'home' => (string)$b['home'],
        'status' => $b['status'], 'status_label' => b2_build_status_label($b['status']), 'problem_id' => $b['problem_id'] ? (int)$b['problem_id'] : null, 'note' => (string)$b['note'], 'created_at' => $b['created_at'], 'updated_at' => $b['updated_at'], 'live_at' => $b['live_at'],
        'waiting' => $waiting, 'seed' => (string)$b['seed_key']];
    if ($full) $v['spec'] = (string)$b['spec'];
    return $v;
}
function b2_seed(PDO $pdo) {
    $op = "WHAT IT IS\nOne panel, \"Online presence\", where the marketing person enters the social and Google numbers once a day, each with a screenshot as proof.\n\nWHAT IT COUNTS (one number task each)\n- Instagram followers\n- Likes on the latest post\n- Stories posted today\n- Highlights updated this week\n- Google reviews (total) and new reviews today\n- Google rating\n\nWHO OWNS IT\nThe marketing seat (a person seat plus a backup). The seat must be chosen before the tasks start.\n\nPROOF\nOne screenshot of the Instagram profile and one of the Google Business profile cover all numbers of that platform (one picture may cover several numbers).\n\nWHERE IT IS SHOWN\nBoss page: Numbers strip. Marketing section page: Numbers. Each number has its 14-day line and its target.\n\nTARGETS\nProposed by the system as the last-14-days average + 10%, and approved by the boss. No penalty in the first 14 days.\n\nWHAT NEEDS BUILDING\n1. A page, \"Online presence\", with one row per number and a camera/screenshot button.\n2. The page writes into the same Number tasks, so nothing is entered twice.\n3. A weekly line chart per number.\n\nDONE WHEN\nThe marketing person can enter all numbers in under 2 minutes, and the boss sees them on one screen.\n\nUNTIL THEN\nThe same numbers can be created today as Number tasks; staff type them in My Day with a screenshot.";
    $cc = "WHAT IT IS\nOne panel, \"Customer care cases\", where every customer complaint, delay, refund or call-back becomes a case with one owner and one deadline.\n\nWHERE CASES COME FROM\n- A chat on WhatsApp that needs action\n- A call\n- A parcel problem (NDR, delay, wrong item)\n- A review that needs a reply\n\nEACH CASE HAS\nCustomer, order number, what happened, owner seat, due time, status (open / waiting for customer / solved), and what was promised.\n\nNUMBERS IT GIVES\nCases opened today, cases open now, cases overdue, average time to solve, repeat complaints.\n\nWHO OWNS IT\nThe Customer Care section: a worker seat with a backup, and the section boss decides escalations.\n\nHOW IT CONNECTS\nA case that is overdue appears in Needs you. A case caused by the same thing three times becomes a Problem with a cause. A solved case closes by itself after the customer confirms or after 3 days.\n\nWHAT NEEDS BUILDING\n1. The Cases page (list, filter, one-tap status).\n2. A \"Make a case\" button inside WhatsApp chats and parcel rows.\n3. The daily numbers feeding the Numbers strip.\n\nDONE WHEN\nNo customer problem lives only in a chat: every one has an owner and a clock.\n\nUNTIL THEN\nUse Follow-up tasks for each customer problem.";
    $ins = $pdo->prepare("INSERT IGNORE INTO b2_builds (title, spec, why, section_code, home, status, seed_key, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $secs = b2_sections($pdo);
    $ins->execute(['Online presence panel', $op, 'Numbers like Instagram likes, stories, highlights and Google reviews need one home panel.', isset($secs['marketing']) ? 'marketing' : null, 'online_presence.html', 'spec', 'online_presence', 'Boss Engine 2', bd_now()]);
    $ins->execute(['Customer care cases panel', $cc, 'Customer problems should live as cases with an owner and a clock, not only inside chats.', isset($secs['care']) ? 'care' : null, 'care_cases.html', 'spec', 'care_cases', 'Boss Engine 2', bd_now()]);
}

/* ============================== documents (licences, agreements, renewals) ============================== */
function b2_doc_view(PDO $pdo, array $d) {
    $left = $d['expires_on'] ? (int)floor((strtotime($d['expires_on']) - strtotime(b2_day())) / 86400) : null;
    $state = $left === null ? 'nodate' : ($left < 0 ? 'expired' : ($left <= 14 ? 'soon' : 'ok'));
    return ['id' => (int)$d['id'], 'title' => $d['title'], 'kind' => $d['kind'], 'section_code' => (string)$d['section_code'], 'section' => $d['section_code'] ? b2_section_name($pdo, $d['section_code']) : 'General', 'seat_id' => $d['seat_id'] ? (int)$d['seat_id'] : null, 'owner' => b2_seat_title($pdo, $d['seat_id']),
        'party' => (string)$d['party'], 'expires_on' => $d['expires_on'], 'days_left' => $left, 'state' => $state, 'ladder' => $d['ladder'] ?: '30,14,7,0', 'notes' => (string)$d['notes'], 'link' => (string)$d['link'], 'status' => $d['status']];
}
function b2_docs_tick(PDO $pdo) {
    $n = 0; $today = b2_day();
    $st = $pdo->query("SELECT * FROM b2_docs WHERE status = 'active' AND expires_on IS NOT NULL");
    foreach ($st->fetchAll() as $d) {
        $left = (int)floor((strtotime($d['expires_on']) - strtotime($today)) / 86400);
        $ladder = array_map('intval', explode(',', $d['ladder'] ?: '30,14,7,0')); rsort($ladder);
        $raised = array_filter(explode(',', (string)$d['raised']), 'strlen');
        $step = null; foreach ($ladder as $s) { if ($left <= $s && !in_array((string)$s, $raised, true)) $step = $s; }          // the smallest step not yet raised that is reached
        if ($step === null) continue;
        $skipped = array_filter($ladder, function ($s) use ($step, $raised) { return $s >= $step && !in_array((string)$s, $raised, true); });
        $due = $left > 1 ? date('Y-m-d', strtotime($d['expires_on'] . ' -1 day')) : $today;
        $seat = $d['seat_id'] ? (int)$d['seat_id'] : 0;
        if (!$seat) { $sid = b2_section_id($pdo, (string)$d['section_code']); $seat = $sid ? bd_section_seat($pdo, $sid, 'section_boss') : 0; }
        if (!$seat) $seat = bd_seat_id($pdo, 'top.boss');
        list($c, $err) = b2_item_clean($pdo, ['kind' => 'short', 'title' => ($left < 0 ? 'EXPIRED: ' : 'Renew: ') . $d['title'] . ($left < 0 ? ' (expired ' . date('j M', strtotime($d['expires_on'])) . ')' : ' (expires ' . date('j M', strtotime($d['expires_on'])) . ')'), 'why' => 'Document reminder: ' . ($left < 0 ? 'it has expired.' : $left . ' day' . ($left === 1 ? '' : 's') . ' left.'), 'section_code' => (string)$d['section_code'], 'seat_id' => $seat, 'due_date' => $due, 'proof' => 'text', 'allow_unowned' => 1]);
        if ($c) { $iid = b2_item_insert($pdo, $c, 'active', 'doc', 'Documents'); b2_ev($pdo, $iid, null, null, null, 'created', 'Raised by the document reminder (' . $step . ' days)'); $n++; }
        foreach ($skipped as $s) $raised[] = (string)$s;
        $pdo->prepare("UPDATE b2_docs SET raised = ? WHERE id = ?")->execute([implode(',', array_unique($raised)), $d['id']]);
    }
    return $n;
}

/* ============================== the page contract: what each panel counts, what is overdue, who owns what ============================== */
function b2_contracts() {
    return [
        'dept_dispatch.html' => ['section' => 'dispatch', 'title' => 'Parcels and tracking', 'topic' => 'dispatch',
            'counts' => 'Every parcel shipped through Shiprocket, grouped by where it is stuck: no tracking ID, tracking ID given but not moving, untraceable, NDR and RTO.',
            'overdue' => 'A parcel with no tracking ID after 48 hours; a tracking ID given but not in transit after 48 hours; an untraceable parcel without today\'s remark (6 PM, not Sunday); a manual tracking ID not sent within 24 hours.',
            'owners' => 'Tracking IDs: seat "dispatch.tracking". Parcels not moving: "dispatch.lead". Untraceable parcels: "dispatch.untraceable" with a backup.'],
        'dept_shops.html' => ['section' => 'retaildist', 'title' => 'Retail displays', 'topic' => 'shops',
            'counts' => 'Display visits to the retail shops in the 12-day round.', 'overdue' => 'A shop not visited within its 12-day round.', 'owners' => 'The visitor seat of the city (for example "retaildist.visitor.surat").'],
        'dept_supplies.html' => ['section' => 'supplies', 'title' => 'Office supplies', 'topic' => 'supplies',
            'counts' => 'Supplies that must be bought, by the day they are needed.', 'overdue' => 'A supply not bought on its day.', 'owners' => 'Seat "supplies.buyer".'],
        'dept_work.html' => ['section' => '', 'title' => 'Shopping list', 'topic' => '', 'counts' => 'Things asked to be bought and who buys them.', 'overdue' => 'Items still not bought after their day.', 'owners' => 'The buyer chosen on the page.'],
        'dept_fix.html' => ['section' => '', 'title' => 'Shopping list and repairs', 'topic' => '', 'counts' => 'Things to buy and things to repair.', 'overdue' => 'A repair or purchase past its day.', 'owners' => 'The person it is assigned to on the page.'],
        'attendance.html' => ['section' => 'hr', 'title' => 'Attendance', 'topic' => 'punch', 'counts' => 'Punch in and punch out for each person each day.', 'overdue' => 'Not punched in 90 minutes after the shift started (the person is treated as away and their work moves to the backup).', 'owners' => 'Each person for themselves.'],
        'office_care.html' => ['section' => '', 'title' => 'Offices', 'topic' => '', 'counts' => 'Cleaning zones of each office and the photo checks of today.', 'overdue' => 'A cleaning photo not sent by its time, or sent back by the checker.', 'owners' => 'The worker and checker seats of each office.'],
        'flyers.html' => ['section' => 'marketing', 'title' => 'Flyers', 'topic' => '', 'counts' => 'Flyer drops by each person, checked on the map.', 'overdue' => 'A person below the daily flyer target.', 'owners' => 'The person doing the drops.'],
        'daily_summary.html' => ['section' => '', 'title' => 'Summary', 'topic' => '', 'counts' => 'Claude\'s daily checks of the whole HRMS: documents, handovers, spot checks.', 'overdue' => 'A finding not closed by the boss.', 'owners' => 'The boss decides each finding.'],
        'boss_engine.html' => ['section' => '', 'title' => 'Boss Engine', 'topic' => '', 'counts' => 'All tasks, numbers, problems and penalties across every section.', 'overdue' => 'See each section: a daily task missed 3 times in 7 days is slipping; a number under target on 5 of 7 days is slipping.', 'owners' => 'Every task has one owner seat and a backup.'],
        'my_day.html' => ['section' => '', 'title' => 'My Day', 'topic' => '', 'counts' => 'Your tasks for today with the 7-day strip.', 'overdue' => 'A task not done by its cut-off.', 'owners' => 'You.'],
        'agreements.html' => ['section' => 'hr', 'title' => 'Agreements', 'topic' => '', 'counts' => 'Agreements sent to employees for signing, which are signed, which are pending, and the agreement templates.', 'overdue' => 'An agreement that was sent and is still not signed.', 'owners' => 'HR & Admin sends them; each employee signs their own.'],
        'approvals.html' => ['section' => 'hr', 'title' => 'Approvals', 'topic' => '', 'counts' => 'New employees and uploaded documents that wait for an admin to approve or decline.', 'overdue' => 'An employee or document that has waited for a decision.', 'owners' => 'The admin team decides each one.'],
        'attendance_admin.html' => ['section' => 'hr', 'title' => 'Attendance (admin)', 'topic' => '', 'counts' => 'Every employee\'s punch in and out for a day or a month, the day timing (shift hours), early check-ins and manual day edits.', 'overdue' => 'A person who has not punched in after the shift started.', 'owners' => 'HR & Admin edits; each person punches for themselves.'],
        'backups.html' => ['section' => 'sales', 'title' => 'Bot backups', 'topic' => '', 'counts' => 'Copies of everything the customer-chat bot has learned, which can be downloaded or restored.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'boss_inbox.html' => ['section' => '', 'title' => 'Boss Inbox (history)', 'topic' => '', 'counts' => 'Older decisions. Open decisions now show in Needs you on the Boss Engine; this page keeps the history.', 'overdue' => 'Nothing: open decisions are in Needs you.', 'owners' => 'The boss decides in Needs you.'],
        'boss_penalties.html' => ['section' => '', 'title' => 'Penalty rules', 'topic' => '', 'counts' => 'Each penalty rule, what it costs, who is charged and who decides, by section. You can flag a miss and ask Claude for a new rule.', 'overdue' => 'A rule set to 0 rupees, or switched off, charges nobody.', 'owners' => 'Only the boss decides a charge.'],
        'boss_questions.html' => ['section' => '', 'title' => 'Questions', 'topic' => '', 'counts' => 'The questions Claude asks the boss about each department, with the answers saved.', 'overdue' => 'A question still waiting for your answer.', 'owners' => 'The boss answers.'],
        'boss_section.html' => ['section' => '', 'title' => 'Section page', 'topic' => '', 'counts' => 'One section: its numbers, tasks and problems, with Ask Claude and Report a problem.', 'overdue' => 'See the section\'s own tasks: a missed daily task or a slipping number.', 'owners' => 'The section boss.'],
        'commission.html' => ['section' => 'sales', 'title' => 'Commission', 'topic' => '', 'counts' => 'Each sales employee\'s commission, worked out from live Targets, Orders and Attendance, with manual order shares.', 'overdue' => 'Nothing is late here. A day without attendance or orders gives no commission.', 'owners' => 'Admin sets the rules; each salesperson sees their own.'],
        'commission_rules.html' => ['section' => 'sales', 'title' => 'Commission rules', 'topic' => '', 'counts' => 'The commission rules by order code and rate, and the calculator that tests them.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'control_tower.html' => ['section' => 'sales', 'title' => 'Sales control tower', 'topic' => '', 'counts' => 'The day\'s focus list for sales, benchmarks, reconciliation and the remarks sent to the team.', 'overdue' => 'A focus item not marked done, and a remark that was not sent.', 'owners' => 'The sales boss.'],
        'departments.html' => ['section' => 'hr', 'title' => 'Departments', 'topic' => '', 'counts' => 'The list of departments of the company.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'dept_hub.html' => ['section' => '', 'title' => 'Departments hub', 'topic' => '', 'counts' => 'Who does what in each department (the seats) and how each department is doing today.', 'overdue' => 'A department task missed or a seat with nobody on it.', 'owners' => 'Each department boss; the top boss sees all.'],
        'detailed_report.html' => ['section' => 'hr', 'title' => 'Detailed performance report', 'topic' => '', 'counts' => 'One report per person for today, yesterday, this month or last month, with notes the boss can save.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'The boss and HR read it.'],
        'doc_check.html' => ['section' => 'hr', 'title' => 'Doc Check', 'topic' => '', 'counts' => 'Missing, expiring and unclear employee documents. Claude checks them every night.', 'overdue' => 'A document that is missing, expired or unclear and not fixed.', 'owners' => 'HR & Admin.'],
        'documents.html' => ['section' => 'hr', 'title' => 'Employee documents', 'topic' => '', 'counts' => 'Every uploaded employee document, by document type, with approval status.', 'overdue' => 'A document that is missing or expired.', 'owners' => 'HR & Admin; each person uploads their own.'],
        'employees.html' => ['section' => 'hr', 'title' => 'Employees', 'topic' => '', 'counts' => 'Every employee with photo, department, role and details. Bulk add and export to Excel.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'HR & Admin.'],
        'ex_employees.html' => ['section' => 'hr', 'title' => 'Ex-employees', 'topic' => '', 'counts' => 'People who left. They can rejoin or be deleted.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'HR & Admin.'],
        'excel_files.html' => ['section' => '', 'title' => 'Excel files', 'topic' => '', 'counts' => 'Excel files and which employees can open them.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'finance_in.html' => ['section' => 'accounts', 'title' => 'Finance: Incoming', 'topic' => '', 'counts' => 'Money booked from store bills and online orders, money received (bank, UPI, COD remittance), and what is still outstanding.', 'overdue' => 'Delivered parcels not yet paid for 10 days or more.', 'owners' => 'Accounts & Cash.'],
        'flyer_map.html' => ['section' => 'marketing', 'title' => 'Flyer map', 'topic' => '', 'counts' => 'Where each flyer drop happened on the map, who did it and which drops need a check.', 'overdue' => 'A drop that needs a check and is not approved.', 'owners' => 'The person doing the drops; the marketing boss approves.'],
        'handover.html' => ['section' => 'hr', 'title' => 'Handover', 'topic' => '', 'counts' => 'When someone leaves, every task and duty they held is handed to another person.', 'overdue' => 'A duty with no new owner after the person left.', 'owners' => 'HR & Admin chooses who takes over.'],
        'history.html' => ['section' => 'hr', 'title' => 'Decline history', 'topic' => '', 'counts' => 'Employees and documents that were declined, with a button to add them again.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'HR & Admin.'],
        'leaders.html' => ['section' => 'marketing', 'title' => 'Leaders commission (ROAS)', 'topic' => '', 'counts' => 'Ad spend per leader panel and the commission the page suggests from the day\'s ROAS. The boss types the amount and presses Set.', 'overdue' => 'A day with no ad spend entered.', 'owners' => 'The boss sets the amount.'],
        'learning.html' => ['section' => 'sales', 'title' => 'Learning & improvement', 'topic' => '', 'counts' => 'What the customer-chat bot learns from the team: follow-up situations, rules to teach and drafts to approve.', 'overdue' => 'A suggested lesson not yet taught or ignored.', 'owners' => 'The sales boss teaches the bot.'],
        'leave.html' => ['section' => 'hr', 'title' => 'My leave', 'topic' => '', 'counts' => 'Your leave requests and their status. Approved days appear on your attendance.', 'overdue' => 'A request waiting for approval.', 'owners' => 'You apply; the admin decides.'],
        'leave_requests.html' => ['section' => 'hr', 'title' => 'Leave requests', 'topic' => '', 'counts' => 'Leave requests from all employees, to approve or reject.', 'overdue' => 'A request still waiting for a decision.', 'owners' => 'The admin team decides.'],
        'my_agreements.html' => ['section' => 'hr', 'title' => 'My agreements', 'topic' => '', 'counts' => 'Agreements sent to you: sign with your finger and get the PDF by email.', 'overdue' => 'An agreement you have not signed.', 'owners' => 'You.'],
        'my_chats.html' => ['section' => 'care', 'title' => 'My chats', 'topic' => '', 'counts' => 'Your customer chats on WhatsApp.', 'overdue' => 'A customer message not answered.', 'owners' => 'You.'],
        'my_orders.html' => ['section' => 'sales', 'title' => 'My orders', 'topic' => '', 'counts' => 'The orders that count for you.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'You.'],
        'my_penalties.html' => ['section' => 'hr', 'title' => 'My penalties', 'topic' => '', 'counts' => 'Penalties given to you, with the reason, and where you can say you have read them.', 'overdue' => 'Nothing is late. A penalty stays until the boss waives it.', 'owners' => 'You read; the boss decides.'],
        'my_salary.html' => ['section' => 'hr', 'title' => 'My salary', 'topic' => '', 'counts' => 'Your salary for the month: attendance, penalties, commission and what you are paid.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'You.'],
        'my_sales.html' => ['section' => 'sales', 'title' => 'My sales', 'topic' => '', 'counts' => 'Your orders, calls, commission and targets for today or the month, with tips from the coach.', 'overdue' => 'Behind your daily target.', 'owners' => 'You.'],
        'my_space.html' => ['section' => '', 'title' => 'My space', 'topic' => '', 'counts' => 'Your own page: your tasks, orders and what the company wants you to see.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'You.'],
        'order_allocation.html' => ['section' => 'sales', 'title' => 'Order allocation', 'topic' => '', 'counts' => 'Every order: who it counts for, its type and its panel. Admin can lock codes and set who may change them.', 'overdue' => 'An order with no employee set.', 'owners' => 'Admin and the sales boss.'],
        'order_codes.html' => ['section' => 'sales', 'title' => 'Order codes', 'topic' => '', 'counts' => 'The code written on each order (for example AG00123) and saved as a Shopify tag.', 'overdue' => 'An order with no code.', 'owners' => 'The salesperson sets their own code.'],
        'order_targets.html' => ['section' => 'sales', 'title' => 'Order targets', 'topic' => '', 'counts' => 'The daily order target of chosen employees, tracked hour by hour, with a weekly plan.', 'overdue' => 'A person under the target for the hour.', 'owners' => 'The sales boss plans; each person works to it.'],
        'orders.html' => ['section' => 'sales', 'title' => 'Orders (team)', 'topic' => '', 'counts' => 'Shopify orders of the team, the calls made, the target rule and the coach.', 'overdue' => 'A person under the daily target.', 'owners' => 'The sales boss.'],
        'penalties.html' => ['section' => 'hr', 'title' => 'Penalties (HR)', 'topic' => '', 'counts' => 'HR penalty rules, the penalties applied, and a form to apply one.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'HR & Admin applies; the Boss Engine only suggests.'],
        'people_admin.html' => ['section' => '', 'title' => 'Admin team', 'topic' => '', 'counts' => 'Everyone who can approve and assign what people send to the boss.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'performance.html' => ['section' => 'hr', 'title' => 'Performance', 'topic' => '', 'counts' => 'Each person\'s daily performance and ratings, with add-ons.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'The boss and HR rate.'],
        'photos.html' => ['section' => 'care', 'title' => 'Photos', 'topic' => '', 'counts' => 'Photos the bot and the team can send to customers, read by Claude and listed by perfume.', 'overdue' => 'A photo Claude could not read correctly.', 'owners' => 'The care boss.'],
        'retail_inventory.html' => ['section' => 'store', 'title' => 'Retail inventory', 'topic' => '', 'counts' => 'Finished perfumes in stock by size. Every retail order takes its pieces out automatically. Low and out lists with stock limits.', 'overdue' => 'A perfume at or below its stock limit.', 'owners' => 'The store boss.'],
        'retail_shops.html' => ['section' => 'retaildist', 'title' => 'Retail shops', 'topic' => '', 'counts' => 'Partner shops that keep our perfumes on display: shop, contact, pinned location, city, and the cash and daybook of the visits.', 'overdue' => 'A shop past its visit round (see Retail displays).', 'owners' => 'The visitor of the city.'],
        'roles.html' => ['section' => 'hr', 'title' => 'Roles', 'topic' => '', 'counts' => 'The roles employees can have.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'Admin.'],
        'salary.html' => ['section' => 'hr', 'title' => 'Salary', 'topic' => '', 'counts' => 'Salary of every employee for the month: attendance, penalties, commission, and the salary rules.', 'overdue' => 'Nothing here is judged late by itself. A Boss Engine task can watch it.', 'owners' => 'HR & Admin.'],
        'sales.html' => ['section' => 'sales', 'title' => 'Sales', 'topic' => '', 'counts' => 'Customer conversations of the sales team: best replies, search, suggestions and a day-by-day view.', 'overdue' => 'A conversation not answered.', 'owners' => 'The sales team; the sales boss reviews.'],
        'system_health.html' => ['section' => '', 'title' => 'System health', 'topic' => '', 'counts' => 'Whether the HRMS parts are running: database, cron jobs and connections.', 'overdue' => 'A check that fails.', 'owners' => 'Admin.'],
        'targets.html' => ['section' => 'sales', 'title' => 'Targets', 'topic' => '', 'counts' => 'Daily order targets per employee, the target rule and day timing.', 'overdue' => 'A person under their target.', 'owners' => 'The sales boss.'],
        'wa_chats.html' => ['section' => 'care', 'title' => 'WhatsApp chats', 'topic' => '', 'counts' => 'All WhatsApp customer chats across the numbers, with quick replies and templates.', 'overdue' => 'A customer chat not answered.', 'owners' => 'The care team.'],
        'wa_setup.html' => ['section' => 'care', 'title' => 'WhatsApp setup', 'topic' => '', 'counts' => 'The WhatsApp numbers, the Meta connection and the quick replies.', 'overdue' => 'A number whose Meta check fails.', 'owners' => 'Admin.'],
        'whatsapp.html' => ['section' => 'care', 'title' => 'WhatsApp', 'topic' => '', 'counts' => 'The WhatsApp overview: chats, sales board and calls to make now.', 'overdue' => 'A customer chat not answered.', 'owners' => 'The care team.'],
    ];
}
function b2_page_key($page) { $p = strtolower(trim((string)$page)); $p = preg_replace('/[?#].*$/', '', $p); $p = basename($p); return $p; }
function b2_page_section(PDO $pdo, $page) {
    $k = b2_page_key($page); $c = b2_contracts();
    if (isset($c[$k]) && $c[$k]['section'] !== '' && isset(b2_sections($pdo)[$c[$k]['section']])) return $c[$k]['section'];
    try { $st = $pdo->prepare("SELECT section_code FROM bd_manifest WHERE path = ? OR path = ?"); $st->execute([$k, 'page:' . $k]); $v = (string)$st->fetchColumn(); if ($v !== '' && isset(b2_sections($pdo)[$v])) return $v; } catch (Throwable $e) {}
    // page patterns of the sections
    foreach (bd_section_defs() as $code => $d) { if (!isset(b2_sections($pdo)[$code])) continue; foreach ((array)$d[3] as $pat) { if (fnmatch($pat, $k)) return $code; } }
    return '';
}
function b2_page_contract(PDO $pdo, $page) {
    $k = b2_page_key($page); $c = b2_contracts();
    if (!isset($c[$k])) return null;
    $x = $c[$k]; $sec = $x['section'] !== '' ? $x['section'] : b2_page_section($pdo, $k);
    $rules = [];
    if (!empty($x['topic'])) { try { $st = $pdo->prepare("SELECT title, amount, when_text FROM dp_rules WHERE topic = ? AND active = 1 ORDER BY sort_no"); $st->execute([$x['topic']]); foreach ($st->fetchAll() as $r) $rules[] = $r['title'] . ($r['when_text'] ? ' (' . $r['when_text'] . ')' : '') . ((float)$r['amount'] > 0 ? ' — ' . b2_inr($r['amount']) : ''); } catch (Throwable $e) {} }
    return ['page' => $k, 'title' => $x['title'], 'section' => $sec, 'counts' => $x['counts'], 'overdue' => $x['overdue'], 'owners' => $x['owners'], 'rules' => $rules];
}
function b2_all_pages() {
    static $p = null; if ($p !== null) return $p;
    $p = [];
    $dir = null; foreach ([dirname(__DIR__), __DIR__] as $d) { if (is_file($d . '/assets/js/layout.js')) { $dir = $d; break; } }
    if ($dir) foreach (glob($dir . '/*.html') as $f) $p[] = basename($f);
    return $p;
}

/* ============================== lists ============================== */
function b2_items_list(PDO $pdo, array $w, array $f) {
    $where = ['1=1']; $args = [];
    $where[] = b2_scope_sql($pdo, $w, 'i.section_code');
    if (!empty($f['section'])) { $where[] = 'i.section_code = ?'; $args[] = $f['section']; }
    if (!empty($f['kind'])) { $where[] = 'i.kind = ?'; $args[] = $f['kind']; }
    $status = (string)($f['status'] ?? 'live');
    if ($status === 'live') $where[] = "i.status IN ('active','draft','paused')"; elseif ($status === 'all') $where[] = '1=1'; else { $where[] = 'i.status = ?'; $args[] = $status; }
    if (!empty($f['seat_id'])) { $where[] = 'i.seat_id = ?'; $args[] = (int)$f['seat_id']; }
    if (!empty($f['emp'])) { $where[] = "i.seat_id IN (SELECT seat_id FROM bd_holders WHERE emp_id = ?)"; $args[] = (int)$f['emp']; }
    if (!empty($f['q'])) { $where[] = 'i.title LIKE ?'; $args[] = '%' . $f['q'] . '%'; }
    $limit = max(10, min(300, (int)($f['limit'] ?? 120))); $off = max(0, (int)($f['offset'] ?? 0));
    $st = $pdo->prepare("SELECT i.* FROM b2_items i WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(i.status,'draft','active','paused','done','archived'), i.id DESC LIMIT $limit OFFSET $off"); $st->execute($args);
    $cards = b2_items_view($pdo, $st->fetchAll());
    if (!empty($f['state'])) $cards = array_values(array_filter($cards, function ($c) use ($f) { return $c['state'] === $f['state']; }));
    return $cards;
}
function b2_tile_counts(PDO $pdo, array $w, $section = '') {
    $where = ["i.status = 'active'", b2_scope_sql($pdo, $w, 'i.section_code')]; $args = [];
    if ($section !== '') { $where[] = 'i.section_code = ?'; $args[] = $section; }
    $st = $pdo->prepare("SELECT i.* FROM b2_items i WHERE " . implode(' AND ', $where)); $st->execute($args);
    $cards = b2_items_view($pdo, $st->fetchAll());
    $tiles = []; foreach (b2_kinds() as $k => $d) $tiles[$k] = ['kind' => $k, 'label' => $d[0], 'total' => 0, 'ontrack' => 0, 'slipping' => 0, 'late' => 0, 'waiting' => 0];
    foreach ($cards as $c) { $t = &$tiles[$c['kind']]; $t['total']++; if (isset($t[$c['state']])) $t[$c['state']]++; unset($t); }
    return ['tiles' => array_values($tiles), 'cards' => $cards];
}
function b2_today_stats(PDO $pdo, array $w, $section = '') {
    $where = ["r.run_date = ?", "i.status = 'active'", "r.status != 'excused'", b2_scope_sql($pdo, $w, 'i.section_code')]; $args = [b2_day()];
    if ($section !== '') { $where[] = 'i.section_code = ?'; $args[] = $section; }
    $st = $pdo->prepare("SELECT r.status, COUNT(*) c FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE " . implode(' AND ', $where) . " GROUP BY r.status"); $st->execute($args);
    $o = ['due' => 0, 'done' => 0, 'open' => 0, 'missed' => 0, 'review' => 0];
    foreach ($st->fetchAll() as $r) { $o['due'] += (int)$r['c']; if (isset($o[$r['status']])) $o[$r['status']] = (int)$r['c']; }
    $o['pct'] = $o['due'] ? (int)round(100 * ($o['done'] + $o['review']) / $o['due']) : 0;
    return $o;
}

/* ============================== Needs you ============================== */
function b2_needs(PDO $pdo, array $w, $limit = 40) {
    $n = []; $sc = function ($col) use ($pdo, $w) { return b2_scope_sql($pdo, $w, $col); };
    foreach ($pdo->query("SELECT * FROM b2_problems WHERE step = 'decide' AND (" . $sc('section_code') . " OR section_code IS NULL) ORDER BY FIELD(sev,'high','med','low'), id DESC LIMIT 20")->fetchAll() as $p) {
        $lvl = ['confirmed' => 'Confirmed', 'likely' => 'Likely', 'unknown' => 'Unknown'][$p['cause_level']] ?? '';
        $n[] = ['key' => 'problem:' . $p['id'], 'type' => 'problem', 'rank' => $p['sev'] === 'high' ? 0 : 1, 'title' => $p['title'], 'sub' => ($lvl ? 'Cause: ' . $lvl . ' — ' : '') . b2_short($p['cause_text'], 140), 'section' => $p['section_code'] ? b2_section_name($pdo, $p['section_code']) : 'Not sure yet', 'ago' => b2_ago($p['created_at']),
            'by' => $p['by_name'], 'again' => (int)$p['again_n'], 'id' => (int)$p['id'], 'actions' => [['k' => 'open', 'label' => 'Decide', 'style' => 'pri']]];
    }
    foreach (b2_pen_list($pdo, $w, ['ask'], 20) as $p) {
        $n[] = ['key' => 'pen:' . $p['id'], 'type' => 'penalty', 'rank' => 2, 'title' => $p['emp'] . ' missed “' . b2_short($p['item'], 60) . '”', 'sub' => 'Suggested ' . b2_inr($p['amount']) . ($p['calc'] !== '' ? ' (' . $p['calc'] . ')' : '') . ' · ' . ($p['same_30d'] > 1 ? $p['same_30d'] . ' times in 30 days' : 'first time in 30 days') . ($p['late_done'] ? ' · done late' : '') . ' · this month charged ' . b2_inr($p['month_charged']),
            'section' => $p['section'], 'ago' => $p['ago'], 'id' => $p['id'], 'amount' => $p['amount'], 'emp_id' => $p['emp_id'], 'item_id' => $p['item_id'],
            'actions' => [['k' => 'charge', 'label' => 'Charge ' . b2_inr($p['amount']), 'style' => 'bad'], ['k' => 'waive', 'label' => 'Waive'], ['k' => 'call', 'label' => 'Call'], ['k' => 'fix', 'label' => 'Fix the cause'], ['k' => 'move', 'label' => 'Move the work']]];
    }
    $st = $pdo->query("SELECT r.id, r.item_id, r.proof_why, r.owner_emp, r.run_date, i.title, i.section_code FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE (r.proof_state = 'flag' OR r.status = 'review') AND r.run_date >= '" . date('Y-m-d', strtotime('-14 days')) . "' AND " . $sc('i.section_code') . " ORDER BY r.id DESC LIMIT 15");
    foreach ($st->fetchAll() as $r) {
        $n[] = ['key' => 'proof:' . $r['id'], 'type' => 'proof', 'rank' => 3, 'title' => 'Look at the proof: ' . b2_short($r['title'], 70), 'sub' => ($r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) . ' · ' : '') . ($r['proof_why'] ?: 'Waiting for a check.'), 'section' => $r['section_code'] ? b2_section_name($pdo, $r['section_code']) : 'General', 'ago' => date('j M', strtotime($r['run_date'])), 'id' => (int)$r['id'], 'has_proof' => true,
            'actions' => [['k' => 'accept', 'label' => 'Accept', 'style' => 'go'], ['k' => 'reject', 'label' => 'Send back']]];
    }
    $st = $pdo->query("SELECT * FROM b2_items WHERE status = 'draft' AND " . $sc('section_code') . " ORDER BY id DESC LIMIT 15");
    foreach ($st->fetchAll() as $it) {
        $n[] = ['key' => 'draft:' . $it['id'], 'type' => 'draft', 'rank' => 4, 'title' => 'Approve this task: ' . b2_short($it['title'], 80), 'sub' => b2_kind_label($it['kind']) . ' · ' . ($it['seat_id'] ? b2_seat_title($pdo, $it['seat_id']) : 'no owner yet') . ($it['build_id'] ? ' · waits for a system to be built' : ''), 'section' => $it['section_code'] ? b2_section_name($pdo, $it['section_code']) : 'General', 'ago' => b2_ago($it['created_at']), 'id' => (int)$it['id'],
            'actions' => $it['build_id'] ? [['k' => 'open', 'label' => 'Open', 'style' => '']] : [['k' => 'approve', 'label' => 'Yes, start it', 'style' => 'go'], ['k' => 'reject', 'label' => 'No']]];
    }
    $st = $pdo->query("SELECT * FROM b2_items WHERE status = 'active' AND kind = 'number' AND target_state = 'draft' AND target_prop IS NOT NULL AND " . $sc('section_code') . " LIMIT 10");
    foreach ($st->fetchAll() as $it) {
        $n[] = ['key' => 'target:' . $it['id'], 'type' => 'target', 'rank' => 5, 'title' => 'Target for “' . b2_short($it['title'], 60) . '”', 'sub' => 'Claude proposes ' . b2_num($it['target_prop']) . ($it['unit'] ? ' ' . $it['unit'] : '') . ' a day (last 14 days average + ' . (int)b2_cfg('target_bump') . '%).', 'section' => $it['section_code'] ? b2_section_name($pdo, $it['section_code']) : 'General', 'ago' => '', 'id' => (int)$it['id'], 'proposed' => (float)$it['target_prop'],
            'actions' => [['k' => 'approve', 'label' => 'Approve ' . b2_num($it['target_prop']), 'style' => 'go'], ['k' => 'change', 'label' => 'Change'], ['k' => 'none', 'label' => 'No target']]];
    }
    $st = $pdo->query("SELECT * FROM b2_alerts WHERE state = 'open' AND (section_code IS NULL OR " . $sc('section_code') . ") ORDER BY id DESC LIMIT 15");
    foreach ($st->fetchAll() as $a) {
        $n[] = ['key' => 'alert:' . $a['id'], 'type' => 'alert', 'rank' => 6, 'title' => $a['text'], 'sub' => ['nobody' => 'No owner', 'hole' => 'A hole the engine found', 'system' => 'System'][$a['kind']] ?? 'Engine', 'section' => $a['section_code'] ? b2_section_name($pdo, $a['section_code']) : 'General', 'ago' => b2_ago($a['created_at']), 'id' => (int)$a['id'],
            'actions' => [['k' => 'fixseat', 'label' => 'Choose people', 'style' => 'pri'], ['k' => 'dismiss', 'label' => 'Got it']]];
    }
    $st = $pdo->query("SELECT * FROM b2_docs WHERE status = 'draft' AND expires_on IS NULL LIMIT 5");
    foreach ($st->fetchAll() as $d) $n[] = ['key' => 'doc:' . $d['id'], 'type' => 'doc', 'rank' => 5, 'title' => 'Enter the date for: ' . $d['title'], 'sub' => 'Reminders cannot start until the date is known.', 'section' => 'Documents', 'ago' => '', 'id' => (int)$d['id'], 'actions' => [['k' => 'open', 'label' => 'Open Documents', 'style' => 'pri']]];
    $st = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM b2_items i WHERE i.build_id = b.id AND i.status = 'draft') w FROM b2_builds b WHERE b.status = 'live' HAVING w > 0");
    foreach ($st->fetchAll() as $b) $n[] = ['key' => 'build:' . $b['id'], 'type' => 'build', 'rank' => 4, 'title' => '“' . b2_short($b['title'], 60) . '” is live', 'sub' => $b['w'] . ' task' . ((int)$b['w'] === 1 ? ' is' : 's are') . ' waiting for it.', 'section' => $b['section_code'] ? b2_section_name($pdo, $b['section_code']) : 'General', 'ago' => '', 'id' => (int)$b['id'], 'actions' => [['k' => 'start', 'label' => 'Start the tasks', 'style' => 'go']]];
    usort($n, function ($a, $b) { return $a['rank'] <=> $b['rank']; });
    return ['items' => array_slice($n, 0, $limit), 'total' => count($n)];
}

/* ============================== the brief (page layer 1) ============================== */
function b2_old_inbox_count(PDO $pdo, array $w) {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM bd_inbox WHERE status = 'open' AND kind NOT IN ('digest','note','changed','collision') AND " . bd_scope_sql($w))->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function b2_review_text(PDO $pdo, array $w, array $data) {
    $t = $data['today']; $lines = [];
    $tc = $data['tile_cards']; $slip = array_values(array_filter($tc, function ($c) { return $c['state'] === 'slipping'; }));
    $late = array_values(array_filter($tc, function ($c) { return $c['state'] === 'late'; }));
    if ($t['due'] === 0) $lines[] = 'No tasks are due today yet' . (!count($data['live_sections']) ? ' — every section is still in test mode, so staff do not see their tasks.' : '.');
    else $lines[] = 'Today ' . ($t['done'] + $t['review']) . ' of ' . $t['due'] . ' tasks are done (' . $t['pct'] . '%)' . ($t['missed'] ? ', ' . $t['missed'] . ' missed' : '') . ($t['open'] ? ', ' . $t['open'] . ' still open' : '') . '.';
    if ($slip) $lines[] = 'Slipping: ' . implode('; ', array_map(function ($c) { return b2_short($c['title'], 50) . ' (' . $c['owner_now'] . ')'; }, array_slice($slip, 0, 3))) . (count($slip) > 3 ? ' and ' . (count($slip) - 3) . ' more.' : '.');
    if ($late) $lines[] = count($late) . ' task' . (count($late) === 1 ? ' is' : 's are') . ' late.';
    $nd = $data['needs']['total'] + (int)($data['old'] ?? 0); if ($nd) $lines[] = $nd . ' thing' . ($nd === 1 ? ' needs' : 's need') . ' your decision.'; else $lines[] = 'Nothing is waiting for your decision.';
    $pt = $data['penalties']['top']; if ($pt && ($pt[0]['charged'] + $pt[0]['waiting_amount']) > 0) $lines[] = 'Highest penalty this month: ' . $pt[0]['emp'] . ' ' . b2_inr($pt[0]['charged']) . ($pt[0]['waiting'] ? ' (+' . $pt[0]['waiting'] . ' waiting for you)' : '') . '.';
    $text = implode(' ', $lines);
    $ai = b2_ai_text("You write the morning review for the owner of an Indian perfume business. Use ONLY the facts given. 3 to 4 short plain sentences, no greeting, no bullet points. Say what is going well, what is slipping and what needs his decision.", "FACTS: " . $text . "\nSLIPPING: " . json_encode(array_map(function ($c) { return $c['title'] . ' / ' . $c['section'] . ' / ' . $c['owner_now']; }, array_slice($slip, 0, 6))), 400);
    return $ai !== '' ? $ai : $text;
}
function b2_brief(PDO $pdo, array $w) {
    $tc = b2_tile_counts($pdo, $w);
    $today = b2_today_stats($pdo, $w);
    $needs = b2_needs($pdo, $w, 40);
    $nums = b2_numbers($pdo, ['pinned' => 1, 'scope' => b2_scope_sql($pdo, $w, 'i.section_code')]);
    if (count($nums) < 4) { $all = b2_numbers($pdo, ['scope' => b2_scope_sql($pdo, $w, 'i.section_code')]); $have = array_column($nums, 'id'); foreach ($all as $a) { if (!in_array($a['id'], $have, true)) $nums[] = $a; } }
    $nums = array_slice($nums, 0, 8);
    $pt = b2_pen_top($pdo, $w, 3);
    $old = b2_old_inbox_count($pdo, $w);
    $data = ['today' => $today, 'tile_cards' => $tc['cards'], 'needs' => $needs, 'old' => $old, 'penalties' => ['top' => $pt], 'live_sections' => b2_live_sections()];
    $key = 'review:' . date('Y-m-d H') . ':' . md5(bd_json([$today, $needs['total'] + $old, count($tc['cards'])]) . $w['emp']);
    $review = b2_state_get($pdo, $key, '');
    if ($review === '') { $review = b2_review_text($pdo, $w, $data); b2_state_put($pdo, $key, $review); }
    $openProb = (int)$pdo->query("SELECT COUNT(*) FROM b2_problems WHERE step NOT IN ('closed')")->fetchColumn();
    $builds = (int)$pdo->query("SELECT COUNT(*) FROM b2_builds WHERE status IN ('spec','building')")->fetchColumn();
    $mm = b2_pen_month($pdo);
    return ['review' => $review, 'ai' => b2_ai_on(), 'needs' => $needs, 'tiles' => $tc['tiles'], 'today' => $today, 'numbers' => $nums, 'penalties' => ['top' => $pt, 'month_total' => $mm, 'cap_total' => (float)b2_cfg('cap_total'), 'cap_person' => (float)b2_cfg('cap_person'), 'waiting' => count(array_filter($needs['items'], function ($n) { return $n['type'] === 'penalty'; }))],
        'chips' => ['sections' => count(b2_sections($pdo)), 'problems' => $openProb, 'builds' => $builds, 'items' => count($tc['cards']), 'drafts' => (int)$pdo->query("SELECT COUNT(*) FROM b2_items WHERE status = 'draft'")->fetchColumn()],
        'live_sections' => b2_live_sections(), 'mode' => b2_cfg('mode'), 'old_inbox' => $old, 'now' => date('Y-m-d H:i:s')];
}

/* ============================== sections ============================== */
function b2_section_boss_names(PDO $pdo, $sid) { $o = []; $seat = bd_section_seat($pdo, $sid, 'section_boss'); if ($seat) foreach (bd_holders($pdo, $seat) as $e) $o[] = bd_emp_name($pdo, $e); return $o; }
function b2_sections_view(PDO $pdo, array $w) {
    $out = [];
    foreach (b2_sections($pdo) as $code => $s) {
        if (!b2_can_section($w, $code, $pdo)) continue;
        $tc = b2_tile_counts($pdo, $w, $code); $slip = 0; $late = 0; foreach ($tc['cards'] as $c) { if ($c['state'] === 'slipping') $slip++; if ($c['state'] === 'late') $late++; }
        $td = b2_today_stats($pdo, $w, $code); $dc = b2_dept_counts($pdo, $code);
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_problems WHERE section_code = ? AND step != 'closed'"); $q->execute([$code]); $pr = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_pens WHERE section_code = ? AND state = 'ask'"); $q->execute([$code]); $pe = (int)$q->fetchColumn();
        $out[] = ['code' => $code, 'name' => $s['name'], 'about' => $s['about'], 'boss' => implode(', ', b2_section_boss_names($pdo, $s['id'])), 'live' => b2_section_live($code), 'items' => count($tc['cards']), 'slipping' => $slip, 'late' => $late + $dc['late'], 'today' => $td, 'dept' => $dc, 'problems' => $pr, 'pens_waiting' => $pe,
            'status' => $slip || $late || $dc['late'] || $dc['missed'] ? ($slip || $late > 1 ? 'bad' : 'warn') : 'ok'];
    }
    return $out;
}
function b2_section_people(PDO $pdo, $code) {
    $sid = b2_section_id($pdo, $code); $seen = []; $out = [];
    // everyone who holds a seat that owns active work in this section, plus holders of the section's own seats
    $st = $pdo->prepare("SELECT DISTINCT h.emp_id FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE s.active = 1 AND (s.section_id = ? OR s.id IN (SELECT seat_id FROM b2_items WHERE section_code = ? AND status = 'active'))");
    $st->execute([$sid, $code]);
    foreach ($st->fetchAll() as $r) {
        $e = (int)$r['emp_id']; if (isset($seen[$e]) || !bd_emp_active(bd_emp($pdo, $e))) continue; $seen[$e] = 1;
        $q = $pdo->prepare("SELECT r.status, COUNT(*) c FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.owner_emp = ? AND r.run_date = ? AND i.section_code = ? AND r.status != 'excused' GROUP BY r.status"); $q->execute([$e, b2_day(), $code]);
        $t = ['done' => 0, 'open' => 0, 'missed' => 0, 'review' => 0]; foreach ($q->fetchAll() as $x) if (isset($t[$x['status']])) $t[$x['status']] = (int)$x['c'];
        $q = $pdo->prepare("SELECT s.title, s.code FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE h.emp_id = ? AND s.active = 1 AND (s.section_id = ?)"); $q->execute([$e, $sid]);
        $seats = array_map(function ($r) { return $r['title']; }, $q->fetchAll());
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.owner_emp = ? AND r.status = 'missed' AND r.run_date >= ? AND i.section_code = ?"); $q->execute([$e, date('Y-m-d', strtotime('-7 days')), $code]);
        $away = bd_away($pdo, $e, b2_day());
        $out[] = ['emp_id' => $e, 'name' => bd_emp_name($pdo, $e), 'seats' => $seats, 'today' => $t, 'missed_7d' => (int)$q->fetchColumn(), 'away' => $away, 'month_pen' => b2_pen_month($pdo, $e)];
    }
    usort($out, function ($a, $b) { return $b['missed_7d'] <=> $a['missed_7d']; });
    return $out;
}
function b2_section_page(PDO $pdo, array $w, $code) {
    $secs = b2_sections($pdo); if (!isset($secs[$code])) return null;
    $s = $secs[$code]; $tc = b2_tile_counts($pdo, $w, $code); $td = b2_today_stats($pdo, $w, $code);
    $slip = array_values(array_filter($tc['cards'], function ($c) { return $c['state'] === 'slipping'; }));
    $late = array_values(array_filter($tc['cards'], function ($c) { return $c['state'] === 'late'; }));
    $dc = b2_dept_counts($pdo, $code);
    $line = $td['due'] === 0 ? 'No tasks are due today in ' . $s['name'] . '.' : ($td['done'] + $td['review']) . ' of ' . $td['due'] . ' tasks done today' . ($td['missed'] ? ', ' . $td['missed'] . ' missed' : '') . '.';
    if ($slip) $line .= ' ' . count($slip) . ' slipping.'; if ($late) $line .= ' ' . count($late) . ' late.';
    if ($dc['open'] + $dc['done'] + $dc['missed']) $line .= ' Department jobs today: ' . $dc['done'] . ' done, ' . $dc['open'] . ' open, ' . $dc['missed'] . ' missed.';
    $nums = b2_numbers($pdo, ['section' => $code]);
    $pr = $pdo->prepare("SELECT * FROM b2_problems WHERE section_code = ? AND step != 'closed' ORDER BY id DESC LIMIT 10"); $pr->execute([$code]);
    $problems = array_map(function ($p) use ($pdo) { return b2_problem_view($pdo, $p); }, $pr->fetchAll());
    $bd = $pdo->prepare("SELECT * FROM b2_builds WHERE section_code = ? AND status IN ('spec','building') ORDER BY id DESC"); $bd->execute([$code]);
    $builds = array_map(function ($b) use ($pdo) { return b2_build_view($pdo, $b); }, $bd->fetchAll());
    $pages = []; foreach (b2_all_pages() as $pg) { $c = b2_page_contract($pdo, $pg); if ($c && $c['section'] === $code) $pages[] = ['page' => $pg, 'title' => $c['title']]; }
    $pen = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM b2_pens WHERE section_code = ? AND state = 'ask'"); $pen->execute([$code]); $pe = $pen->fetch();
    return ['section' => ['code' => $code, 'name' => $s['name'], 'about' => $s['about'], 'boss' => implode(', ', b2_section_boss_names($pdo, $s['id'])), 'live' => b2_section_live($code)],
        'summary' => $line, 'today' => $td, 'tiles' => $tc['tiles'], 'dept' => $dc, 'numbers' => $nums, 'problems' => $problems, 'builds' => $builds, 'people' => b2_section_people($pdo, $code),
        'slipping' => array_slice($slip, 0, 6), 'late' => array_slice($late, 0, 6), 'pages' => $pages, 'pens_waiting' => ['count' => (int)$pe['c'], 'amount' => (float)$pe['a']],
        'contract' => (function () use ($pdo, $pages) { return $pages ? b2_page_contract($pdo, $pages[0]['page']) : null; })()];
}

/* ============================== people and seats ============================== */
function b2_people_view(PDO $pdo, array $w) {
    $seats = [];
    $st = $pdo->query("SELECT s.*, sec.name sec_name, sec.code sec_code FROM bd_seats s LEFT JOIN bd_sections sec ON sec.id = s.section_id WHERE s.active = 1 AND s.code NOT LIKE 'p.%' ORDER BY (s.section_id IS NULL), sec.name, s.kind, s.title");
    foreach ($st->fetchAll() as $s) {
        if ($s['sec_code'] && !b2_can_section($w, $s['sec_code'], $pdo)) continue;
        if (!$s['sec_code'] && !($w['admin'] || $w['top'])) continue;
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE seat_id = ? AND status = 'active'"); $q->execute([(int)$s['id']]);
        $seats[] = ['id' => (int)$s['id'], 'code' => $s['code'], 'title' => $s['title'], 'kind' => $s['kind'], 'section' => $s['sec_name'] ?: 'Everyone', 'section_code' => (string)$s['sec_code'], 'holders' => b2_seat_people($pdo, (int)$s['id']), 'tasks' => (int)$q->fetchColumn()];
    }
    $people = [];
    foreach (bd_employees($pdo) as $e) $people[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'dept' => $e['dept']];
    $st = $pdo->prepare("SELECT r.owner_emp e, SUM(r.status='done' OR r.status='review') done, SUM(r.status='open') open, SUM(r.status='missed') missed FROM b2_runs r WHERE r.run_date = ? AND r.owner_emp IS NOT NULL AND r.status != 'excused' GROUP BY r.owner_emp"); $st->execute([b2_day()]);
    $load = []; foreach ($st->fetchAll() as $r) $load[(int)$r['e']] = ['done' => (int)$r['done'], 'open' => (int)$r['open'], 'missed' => (int)$r['missed']];
    foreach ($people as &$p) { $p['today'] = $load[$p['id']] ?? ['done' => 0, 'open' => 0, 'missed' => 0]; $p['month_pen'] = b2_pen_month($pdo, $p['id']); } unset($p);
    return ['seats' => $seats, 'people' => $people, 'deputy_name' => b2_cfg('deputy_name')];
}

/* ============================== Claude makes tasks: the 7 checks ============================== */
function b2_parse_when($text) {
    $t = mb_strtolower((string)$text); $o = ['time' => null, 'date' => null, 'dow' => null, 'days' => 0];
    if (preg_match('/\b(?:by|before|at|until|till)\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $t, $m)) { $h = (int)$m[1] % 12; if ($m[3] === 'pm') $h += 12; $o['time'] = sprintf('%02d:%02d', $h, (int)($m[2] ?? 0)); }
    elseif (preg_match('/\b(?:by|before|at|until|till)\s+(\d{1,2}):(\d{2})\b/', $t, $m)) { $o['time'] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]); }
    elseif (preg_match('/\b(\d{1,2})\s*(am|pm)\b/', $t, $m)) { $h = (int)$m[1] % 12; if ($m[2] === 'pm') $h += 12; $o['time'] = sprintf('%02d:00', $h); }
    $today = b2_day();
    if (preg_match('/\btoday\b/', $t)) $o['date'] = $today;
    elseif (preg_match('/\btomorrow\b/', $t)) $o['date'] = date('Y-m-d', strtotime('+1 day'));
    elseif (preg_match('/\bin\s+(\d{1,2})\s+days?\b/', $t, $m)) $o['date'] = date('Y-m-d', strtotime('+' . (int)$m[1] . ' days'));
    elseif (preg_match('/\b(?:by|on|before|till|until)\s+(mon|tue|wed|thu|fri|sat|sun)[a-z]*\b/', $t, $m)) { $o['date'] = date('Y-m-d', strtotime('next ' . $m[1])); }
    elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/', $t, $m)) { $d = strtotime($m[1] . ' ' . $m[2] . ' ' . date('Y')); if ($d && $d < strtotime($today)) $d = strtotime($m[1] . ' ' . $m[2] . ' ' . (date('Y') + 1)); if ($d) $o['date'] = date('Y-m-d', $d); }
    if (preg_match('/\b(?:for|next)\s+(\d{1,2})\s+days?\b/', $t, $m)) $o['days'] = (int)$m[1];
    elseif (preg_match('/\bfor\s+(?:this|the next)\s+week\b/', $t)) $o['days'] = 7;
    $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    if (preg_match('/\b(?:7|seven)\s+days\b|including\s+sundays?|all\s+(?:7\s+)?days\b|every\s+single\s+day|every\s+day\s+of\s+the\s+week|sundays?\s+(?:too|also|as well)|day\s+after\s+day/u', $t)) $o['dow'] = '1,2,3,4,5,6,7';   // the manager said Sunday counts too
    if (preg_match_all('/\bevery\s+(mon|tue|wed|thu|fri|sat|sun)[a-z]*\b/', $t, $mm)) { $d = []; foreach ($mm[1] as $x) $d[] = $names[$x]; $o['dow'] = implode(',', array_unique($d)); }
    return $o;
}
function b2_draft_kind($text) {
    $t = mb_strtolower($text); $w = b2_parse_when($text);
    if (b2_has($t, ['improve', 'get better', 'reduce ', 'stop being late', 'fewer '])) return 'improve';
    if ($w['days'] > 0 && b2_has($t, ['every day', 'daily', 'each day', 'check', 'send', 'post', 'do ', 'clean', 'call'])) return 'temp';
    if (b2_has($t, ['how many', 'count of', 'number of', 'followers', 'likes', 'reviews', 'sales ', 'enter the number', 'at least ', 'send the number', 'send the count', 'count the ', 'count of ', 'the total of', 'total number'])) return 'number';
    if ($w['dow'] || b2_has($t, ['every day', 'daily', 'each day', 'every morning', 'every evening', 'every working day', 'each morning'])) return 'daily';
    list($route, $kind) = b2_classify($text);
    if (in_array($kind, ['buy', 'fix', 'followup'], true)) return $kind;
    return 'short';
}
function b2_draft_title($text, $kind) {
    $s = trim(preg_split('/[\n]/u', trim($text))[0]);
    // sentences: drop "I want Hemil to be responsible." and "Every day task." - they are not the job itself
    $parts = preg_split('/(?<=[.!?])\s+/u', $s);
    $parts = array_values(array_filter($parts, function ($p) {
        $p = trim($p);
        if (preg_match('/^(i want|i need|make|let|please make)\b.{0,70}\b(responsible|in charge|owner|handle it|take care)\b.{0,30}[.!?]?$/iu', $p) && mb_strlen($p) < 100) return false;
        if (preg_match('/^(it is |this is |it should be |make it )?(a |an )?(every\s*day|daily|temporary|short|one[- ]time)?\s*(task|job|duty)?[.!?]?$/iu', $p) && preg_match('/(task|job|duty|daily|every\s*day)/iu', $p)) return false;
        return true;
    }));
    if (!$parts) $parts = [$s];
    $s = implode(' ', $parts);
    $s = preg_replace('/^(please|pls|can you|could you|i want you to|i want|make|create|add|new)\s+(a\s+|an\s+|the\s+)?(daily\s+|temporary\s+|short\s+)?(task|job|duty)?\s*(to|for|that|:)?\s*/iu', '', $s);
    $s = preg_replace('/,?\s*\b(he|she|they|that person|the person|owner)\s+(gets?|will get|pays?|should get|must get|has to pay)\b[^.]*\b(penalt|fine)\w*[^.]*/iu', '', $s);   // the consequence is its own field
    $s = preg_replace('/\b(penalty|fine)\b[^.]*\.?/iu', '', $s);
    $s = preg_replace('/^(every\s+(working\s+)?day|daily|each\s+day|every\s+(morning|evening))(\s+at\s+\d{1,2}(:\d{2})?\s*(am|pm)?)?\s*,?\s*/iu', '', $s);
    $s = preg_replace('/\s+(every\s+(working\s+)?day|daily|each\s+day)\s*$/iu', '', $s);
    $s = preg_replace('/^\s*(must|should|has to|have to)\s+/iu', '', $s);
    $s = preg_replace('/\s+(by|before|at)\s+\d{1,2}(:\d{2})?\s*(am|pm)\b/iu', '', $s);                         // the time is its own field
    $s = preg_replace('/\s+(every\s+(working\s+)?day|daily|each\s+day)\b/iu', '', $s);
    if (preg_match('/^if\s+(.+?)\s+(is|are|was|were)\s+not\s+(.+?)\s*[,.]?$/iu', $s, $m)) $s = 'Make sure ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];   // "If X is not Y" -> "Make sure X is Y"
    $s = trim(preg_replace('/\s+/u', ' ', $s), " ,;:.-"); if ($s === '') $s = trim($text);
    if (preg_match('/^\d+\s+[a-z]+$/i', $s)) $s = 'Get ' . $s;                                    // "20 reviews" is a target, not a name
    $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    if (mb_strlen($s) > 90) { $cut = mb_substr($s, 0, 90); $sp = mb_strrpos($cut, ' '); $s = $sp > 40 ? mb_substr($cut, 0, $sp) : $cut; }       // cut at a whole word, never in the middle
    return rtrim($s, '. ,;');
}
/** "₹30 per parcel", "40 rupees for each review", "minimum ₹100", "daily cap ₹500" typed in a sentence -> per-item penalty fields ([] when none) */
function b2_pen_unit_parse($text) {
    $t = (string)$text; $ctx = preg_match('/(?:penalt|fine|charge|deduct|cut\b|₹|\brs\b|rupee)/iu', $t); if (!$ctx) return [];
    $nouns = 'parcels?|orders?|reviews?|items?|calls?|leads?|posts?|stor(?:y|ies)|photos?|bottles?|pieces?|units?|shops?|entr(?:y|ies)|tasks?|bills?|invoices?|customers?|chats?|messages?|followers?|likes?|sales?|tracking numbers?|labels?|packets?|boxes?';
    $cur = '(?:₹|rs\.?|rupees?|inr)?'; $m = null; $out = [];
    if (preg_match('/' . $cur . '\s*(\d{1,4}(?:\.\d+)?)\s*(?:₹|rs\b\.?|rupees?)?\s*(?:penalty\s*|fine\s*)?(?:per|for each|for every|each|\/|a|an)\s+(?:(?:missing|short|pending|late|unfinished|remaining)\s+)?(' . $nouns . ')\b/iu', $t, $m) && !preg_match('/^(?:day|week|month)/i', $m[2])) { $out['pen_unit'] = (float)$m[1]; $out['_noun'] = $m[2]; }
    elseif (preg_match('/(?:per|for each|for every|each)\s+(?:(?:missing|short|pending|late|unfinished|remaining)\s+)?(' . $nouns . ')\s*(?:is|=|:|of|will be)?\s*(?:₹|rs\.?|rupees?)\s*(\d{1,4}(?:\.\d+)?)/iu', $t, $m)) { $out['pen_unit'] = (float)$m[2]; $out['_noun'] = $m[1]; }
    if (empty($out['pen_unit']) || $out['pen_unit'] <= 0) return [];
    $out['pen_unit'] = min(500.0, $out['pen_unit']); $out['pen_mode'] = 'unit'; $out['pen_amount'] = $out['pen_unit']; $out['pen_set'] = 1;
    if (preg_match('/\bmin(?:imum)?\s+(?:flat|penalty|fine|charge)(?:\s+(?:penalty|fine|charge))?\s*(?:of|is|=|:)?\s*(?:₹|rs\.?)?\s*(\d{1,4})/iu', $t, $m) || preg_match('/(?:₹|rs\.?)\s*(\d{1,4})\s*(?:flat\s*)?(?:as\s+)?(?:the\s+)?minimum\b/iu', $t, $m)) $out['pen_min'] = (float)$m[1];
    if (preg_match('/\b(?:daily\s+(?:max(?:imum)?|cap|limit)|cap|limit|max(?:imum)?\s+per\s+(?:day|task))\s*(?:penalty\s*)?(?:of|is|=|:)?\s*(?:₹|rs\.?)?\s*(\d{2,4})/iu', $t, $m) || preg_match('/\bmax(?:imum)?\s*(?:₹|rs\.?)\s*(\d{2,4})\s*(?:per|a|each)\s*(?:day|task)/iu', $t, $m)) $out['pen_max'] = (float)min(2000, $m[1]);
    return $out;
}
function b2_draft_builtin(PDO $pdo, $text, array $ctx) {
    $kind = b2_draft_kind($text); $w = b2_parse_when($text); $sec = (string)($ctx['section'] ?? '');
    if ($sec === '' || !isset(b2_sections($pdo)[$sec])) $sec = b2_guess_section($pdo, $text, (string)($ctx['page'] ?? ''));
    $d = ['kind' => $kind, 'title' => b2_draft_title($text, $kind), 'why' => '', 'section_code' => $sec];
    if ($w['time']) $d['due_time'] = $w['time'];
    if ($w['date'] && b2_block_of($kind) === 'once') $d['due_date'] = $w['date'];
    if ($w['dow']) $d['dow'] = $w['dow'];
    if ($kind === 'temp') $d['days'] = $w['days'] ?: 10;
    $emp = b2_find_person($pdo, $text); if ($emp) $d['emp_id'] = $emp;
    if (preg_match('/\b(?:at least|minimum|min|target(?: of)?)\s+(\d+(?:\.\d+)?)\s*([a-z]+)?/i', $text, $m)) { $d['target'] = (float)$m[1]; if (!empty($m[2]) && !in_array(strtolower($m[2]), ['a', 'per', 'every', 'each', 'on', 'by'], true)) $d['unit'] = strtolower($m[2]); }
    elseif (preg_match('/\b(\d+(?:\.\d+)?)\s+([a-z]+)\s+(?:a|per|every|each)\s+day\b/i', $text, $m)) { $d['target'] = (float)$m[1]; $d['unit'] = strtolower($m[2]); }
    if (preg_match('/\b(?:at most|maximum|max|not more than|under)\s+(?!(?:₹|rs\b))(\d+(?:\.\d+)?)/iu', $text, $m) && !preg_match('/\b(?:daily\s+(?:max|cap)|max(?:imum)?\s+per\s+(?:day|task))/i', $text)) { $d['target'] = (float)$m[1]; $d['cmp'] = 'max'; }
    if ($kind === 'number' && !isset($d['target']) && preg_match('/\b(\d{1,6})\s+(reviews?|followers|likes|calls|orders|parcels|posts|stories|leads|sales)\b/i', $text, $m)) { $d['target'] = (float)$m[1]; $d['unit'] = strtolower(preg_replace('/s$/', '', $m[2])) === 'review' ? 'reviews' : strtolower($m[2]); }
    if ($kind === 'number' && empty($d['unit'])) { foreach (['reviews', 'followers', 'likes', 'stories', 'posts', 'orders', 'calls', 'parcels', 'sales'] as $u) if (mb_strpos(mb_strtolower($text), $u) !== false) { $d['unit'] = $u; break; } }
    if (b2_has($text, ['screenshot'])) $d['proof'] = $kind === 'number' ? 'number' : 'screenshot';
    elseif (b2_has($text, ['photo', 'picture', 'pic ', 'send a pic', 'show'])) $d['proof'] = 'photo';
    elseif (b2_has($text, ['write', 'report back', 'note'])) $d['proof'] = 'text';
    if (preg_match('/(?:penalty|fine)\s*(?:of)?\s*(?:rs\.?|₹)?\s*(\d{2,4})/i', $text, $m) && !b2_pen_unit_parse($text)) $d['pen_amount'] = min(500, (int)$m[1]);
    $pu = b2_pen_unit_parse($text); if ($pu) { $noun = $pu['_noun']; unset($pu['_noun']); $d = array_merge($d, $pu); if (empty($d['unit'])) $d['unit'] = strtolower(rtrim($noun, 's')) . 's'; }
    if ($kind === 'improve' && $emp) $d['link_emp'] = $emp;
    return $d;
}
/** keep only the fields a draft is allowed to carry from one round to the next */
function b2_draft_keep(array $p) {
    $d = [];
    foreach (['kind', 'title', 'why', 'section_code', 'due_time', 'due_date', 'dow', 'proof', 'adapter', 'unit', 'cmp', 'pen_note', 'photo_prompt', 'pen_mode'] as $k) if (isset($p[$k]) && is_scalar($p[$k]) && trim((string)$p[$k]) !== '') $d[$k] = trim((string)$p[$k]);
    foreach (['days', 'target', 'pen_amount', 'pen_unit', 'pen_min', 'pen_max', 'grace_min'] as $k) if (isset($p[$k]) && $p[$k] !== '' && is_numeric($p[$k])) $d[$k] = (float)$p[$k];
    foreach (['emp_id', 'seat_id', 'link_emp'] as $k) if (!empty($p[$k]) && is_numeric($p[$k])) $d[$k] = (int)$p[$k];
    foreach (['no_auto', 'pen_set'] as $k) if (!empty($p[$k])) $d[$k] = 1;
    if (empty($d['kind']) || !isset(b2_kinds()[$d['kind']])) $d['kind'] = 'short';
    return $d;
}
/** a manager's correction ("no, make it Hemil, 6 PM, ₹100 penalty") changes ONLY what it names; everything else stays as it was */
function b2_draft_correct(PDO $pdo, array $d, $fix) {
    $t = mb_strtolower((string)$fix); $w = b2_parse_when($fix);
    $e = b2_find_person($pdo, $fix); if ($e) { $d['emp_id'] = $e; $d['seat_id'] = 0; }
    if (b2_has($t, ['not daily', 'not every day', 'one time', 'one-time', 'only once', 'just once', 'once only'])) $d['kind'] = 'short';
    elseif ($w['days'] > 0) { $d['kind'] = 'temp'; $d['days'] = $w['days']; }
    elseif (b2_has($t, ['every day', 'daily', 'each day', 'every working day', 'every morning', 'every evening'])) $d['kind'] = 'daily';
    elseif (b2_has($t, ['fix ', 'repair', 'broken'])) $d['kind'] = 'fix';
    elseif (b2_has($t, ['follow up', 'follow-up', 'chase'])) $d['kind'] = 'followup';
    elseif (b2_has($t, ['improve', 'get better'])) $d['kind'] = 'improve';
    if ($w['time']) $d['due_time'] = $w['time'];
    if ($w['dow']) $d['dow'] = $w['dow'];
    if ($w['date'] && b2_block_of($d['kind']) === 'once') $d['due_date'] = $w['date'];
    if (b2_has($t, ['no penalty', 'without penalty', 'without a penalty', 'no fine', 'remove the penalty', 'remove penalty', 'zero penalty'])) { $d['pen_amount'] = 0; $d['pen_set'] = 1; }
    elseif (!b2_pen_unit_parse($fix) && (preg_match('/(?:penalty|fine)[^0-9]{0,14}(\d{2,4})/i', $fix, $m) || preg_match('/(?:₹|rs\.?|rupees?)\s*(\d{2,4})/iu', $fix, $m) || preg_match('/(\d{2,4})\s*(?:rs\b|rupees?|₹)/iu', $fix, $m))) { $d['pen_amount'] = (float)min(500, (int)$m[1]); $d['pen_set'] = 1; }
    $pu = b2_pen_unit_parse($fix); if ($pu) { unset($pu['_noun']); $d = array_merge($d, $pu); }
    if (b2_has($t, ['manual', 'i will tick', 'tick it myself', 'no system', 'not from the system', 'by hand', 'not automatic'])) { $d['no_auto'] = 1; unset($d['adapter']); if (($d['proof'] ?? '') === 'auto') $d['proof'] = 'tick'; unset($d['target']); $d['cmp'] = 'min'; }
    foreach (b2_sections($pdo) as $code => $sx) {
        if (preg_match('/\b' . preg_quote(mb_strtolower($sx['name']), '/') . '\b/u', $t) || preg_match('/\b' . preg_quote(mb_strtolower($code), '/') . '\b/u', $t)) { $d['section_code'] = $code; break; }
    }
    if (preg_match('/(?:call it|name it|rename (?:it )?(?:to)?|title:|name:)\s*["“]?([^"”\n]{6,100})["”]?/iu', $fix, $m)) $d['title'] = trim($m[1], " .");
    if (b2_has($t, ['screenshot'])) $d['proof'] = 'screenshot'; elseif (b2_has($t, ['photo', 'picture'])) $d['proof'] = 'photo'; elseif (b2_has($t, ['report back', 'write a'])) $d['proof'] = 'text';
    if (preg_match('/\b(?:at least|minimum|target(?: of)?)\s+(\d+(?:\.\d+)?)/i', $fix, $m)) { $d['target'] = (float)$m[1]; $d['cmp'] = 'min'; }
    if (preg_match('/\b(?:at most|maximum|not more than|under)\s+(\d+(?:\.\d+)?)/i', $fix, $m)) { $d['target'] = (float)$m[1]; $d['cmp'] = 'max'; }
    return $d;
}
/** the manager's tap-answers to Claude's questions (owner, section, penalty, time) are applied exactly as given */
function b2_apply_answers(PDO $pdo, array $d, array $a) {
    if (!empty($a['owner']) && preg_match('/^([es]):(\d+)$/', (string)$a['owner'], $m)) { if ($m[1] === 'e') { $d['emp_id'] = (int)$m[2]; $d['seat_id'] = 0; } else { $d['seat_id'] = (int)$m[2]; unset($d['emp_id']); } }
    if (!empty($a['section']) && isset(b2_sections($pdo)[(string)$a['section']])) $d['section_code'] = (string)$a['section'];
    if (isset($a['pen']) && $a['pen'] !== '' && is_numeric($a['pen'])) { $d['pen_amount'] = (float)max(0, min(500, (int)$a['pen'])); $d['pen_set'] = 1; }
    if (!empty($a['time']) && preg_match('/^\d{2}:\d{2}$/', (string)$a['time'])) $d['due_time'] = (string)$a['time'];
    if (!empty($a['hours']) && ctype_digit((string)$a['hours'])) $d['adapter'] = b2_check_track_key((int)$a['hours']);
    if (!empty($a['days']) && ctype_digit((string)$a['days'])) $d['adapter'] = b2_check_transit_key((int)$a['days'] * 24);
    return $d;
}
/** what Claude cannot know from the sentence: asked as short tap questions (at most 3) before it shows what it understood */
function b2_draft_questions(PDO $pdo, $full, array $d, $key, $ruleTxt, $seat) {
    $q = []; $w = b2_parse_when($full); $secs = b2_sections($pdo);
    if (!b2_find_person($pdo, $full) && empty($d['link_emp'])) {
        $opts = [];
        foreach ($seat ? bd_holders($pdo, $seat) : [] as $e) $opts[] = ['v' => 'e:' . (int)$e, 'l' => bd_emp_name($pdo, $e)];
        if ($seat) $opts[] = ['v' => 's:' . (int)$seat, 'l' => 'Whoever holds “' . b2_seat_title($pdo, $seat) . '”'];
        if (count($opts) < 2) foreach (array_slice(bd_employees($pdo), 0, 5) as $e) $opts[] = ['v' => 'e:' . (int)$e['id'], 'l' => $e['name']];
        $q[] = ['id' => 'owner', 'text' => 'Who should own this task?', 'options' => array_slice($opts, 0, 6)];
    }
    if (empty($d['section_code'])) { $o = []; foreach ($secs as $code => $sx) $o[] = ['v' => $code, 'l' => $sx['name']]; $q[] = ['id' => 'section', 'text' => 'Which section is this for?', 'options' => array_slice($o, 0, 8)]; }
    if (empty($d['pen_amount']) && empty($d['pen_set']) && $ruleTxt === '' && !b2_has($full, ['penalty', 'fine ', 'fined', 'deduct', 'no penalty'])) $q[] = ['id' => 'pen', 'text' => 'If it is missed, should I ask you about a penalty?', 'options' => [['v' => '0', 'l' => 'No penalty'], ['v' => '50', 'l' => '₹50'], ['v' => '100', 'l' => '₹100'], ['v' => '200', 'l' => '₹200']]];
    if (!$w['time'] && b2_block_of($d['kind']) !== 'once') $q[] = ['id' => 'time', 'text' => ($key ? 'At what time should the system check it?' : 'By what time must it be done?'), 'options' => [['v' => '12:00', 'l' => '12:00 PM'], ['v' => '15:00', 'l' => '3:00 PM'], ['v' => '18:00', 'l' => '6:00 PM'], ['v' => '19:00', 'l' => '7:00 PM']]];
    return array_slice($q, 0, 3);
}
function b2_draft(PDO $pdo, array $w, $text, array $ctx) {
    $orig = trim((string)($ctx['original'] ?? '')); if ($orig === '') $orig = trim((string)$text);
    $fix = trim((string)($ctx['correction'] ?? '')); $prev = (isset($ctx['prev']) && is_array($ctx['prev'])) ? $ctx['prev'] : null;
    $round = max(1, (int)($ctx['round'] ?? 1));
    $answers = (isset($ctx['answers']) && is_array($ctx['answers'])) ? $ctx['answers'] : []; $ask = !empty($ctx['ask']);
    $names = array_map(function ($e) { return $e['name']; }, array_slice(bd_employees($pdo), 0, 80));
    $shape = "Shape: {\"kind\":\"\",\"title\":\"\",\"why\":\"\",\"owner_name\":\"\",\"due_time\":\"HH:MM\",\"due_date\":\"YYYY-MM-DD\",\"days\":0,\"dow\":\"1,2,3\",\"target\":0,\"unit\":\"\",\"proof\":\"\",\"pen_amount\":0}. Leave out what the sentence does not say.";
    $kinds = "Kinds: daily (every working day), number (a number entered every working day, with a screenshot), short (one job, one deadline), temp (daily for N days: give days), followup (chase something), fix (repair), buy (purchase), improve (make a person or task better). Proof: tick, photo, number, text, screenshot.";
    $usedAi = false;
    if ($prev && $answers && $fix === '') {
        // the manager answered Claude's questions: apply exactly those answers, nothing else changes
        $d = b2_apply_answers($pdo, b2_draft_keep($prev), $answers); $full = $orig; $ai = null;
    } elseif ($prev && $fix !== '') {
        // "Not quite - I meant ...": start from the reading the manager saw, change only what the correction names
        $d = b2_draft_correct($pdo, b2_draft_keep($prev), $fix); if ($answers) $d = b2_apply_answers($pdo, $d, $answers); $full = $orig . "\n" . $fix; $round++;
        $ai = b2_ai_understand($pdo, $orig, $ctx, $prev, $fix);   // Claude reads the correction too, with the whole picture
    } else {
        $d = b2_draft_builtin($pdo, $orig, $ctx); $full = $orig;
        $ai = b2_ai_understand($pdo, $orig, $ctx);   // Claude reads FIRST; the word reader above is only the backup
    }
    $aiUnd = []; $aiQs = [];
    if (is_array($ai)) { $usedAi = true; list($d, $aiUnd, $aiQs) = b2_ai_apply($pdo, $d, $ai, $full); }
    $notes = []; $secs = b2_sections($pdo);
    $sec = (string)($d['section_code'] ?? '');
    $hint = trim((string)($ctx['title_hint'] ?? ''));
    // 1. is this a number the system already holds? then the system reads it, nobody types it
    $reg = b2_checks(); $key = '';
    if (empty($d['no_auto'])) {
        $kfix = $fix !== '' ? b2_check_match($fix) : '';
        if (!$kfix && $fix !== '' && !empty($d['adapter'])) $kfix = b2_check_retime($d['adapter'], $fix);
        if ($kfix) $key = $kfix; elseif (!empty($d['adapter']) && isset($reg[$d['adapter']])) $key = $d['adapter']; else $key = b2_check_match($full);
    }
    $prevKey = $prev && !empty($prev['adapter']) ? (string)$prev['adapter'] : '';
    $data = null;
    if ($key) {
        $r = $reg[$key];
        $d['adapter'] = $key; $d['proof'] = 'auto';
        $syn = b2_sync_preview($pdo, $key); if ($syn !== '') $notes[] = $syn;   // Boss and Dispatch are one rule: say what saving this does to Dispatch
        if (!in_array($d['kind'], ['daily', 'temp'], true)) { $d['kind'] = 'daily'; $notes[] = 'The system checks this by itself every working day, so I made it a daily task.'; }
        if ($r['mode'] === 'zero') { $d['target'] = 0.0; $d['cmp'] = 'max'; $d['unit'] = $r['unit']; } else { $d['target'] = 1.0; $d['cmp'] = 'min'; unset($d['unit']); }
        if ($round === 1 || $key !== $prevKey) {
            $d['title'] = $r['title']; $d['why'] = $r['why'];
            if ($r['section'] !== '' && isset($secs[$r['section']])) { if ($sec !== '' && $sec !== $r['section']) $notes[] = 'This number lives in ' . b2_section_name($pdo, $r['section']) . ', so I put the task there, not in ' . b2_section_name($pdo, $sec) . '.'; $sec = $r['section']; $d['section_code'] = $sec; }
        }
        $v = b2_check_value($pdo, $key);
        $data = ['key' => $key, 'label' => $r['label'], 'source' => $r['source'], 'now' => $v['value'], 'now_text' => b2_check_now_text($key, $v['value']), 'reachable' => $r['mode'] === 'owner' ? true : $v['value'] !== null, 'mode' => $r['mode']];
    } else { unset($d['adapter']); if ($hint !== '' && $round === 1) $d['title'] = mb_substr($hint, 0, 110); }
    // 2. the owner: a named person, else the section's worker seat
    $ownerGuess = false;
    if (empty($d['emp_id']) && empty($d['seat_id'])) { list($seat, $name, $emp) = b2_suggest_seat($pdo, $sec, $full, $d['kind']); if ($seat) { $d['seat_id'] = $seat; $ownerGuess = true; } }
    if ($d['kind'] === 'improve' && empty($d['link_emp']) && !empty($d['emp_id'])) $d['link_emp'] = $d['emp_id'];
    // 3. the penalty: the manager's number if he gave one; else a proposal - but never a second charge for something a rule already charges
    $penWords = b2_has($full, ['penalty', 'fine ', 'fined', 'deduct', 'charge ']);
    $ruleTxt = '';
    if (empty($d['pen_set']) && empty($d['pen_amount']) && $penWords) {
        $ru = null;
        if ($key && !empty($reg[$key]['rule_rate'])) { try { $q = $pdo->prepare("SELECT title, amount, mode, active FROM dp_rules WHERE rkey = ?"); $q->execute([$reg[$key]['rule_rate']]); $ru = $q->fetch() ?: null; } catch (Throwable $e) { $ru = null; } }
        if ($ru && (int)$ru['active'] === 1 && (float)$ru['amount'] > 0) { $d['pen_amount'] = 0.0; $ruleTxt = 'Parcels & Dispatch already charges ' . b2_inr($ru['amount']) . ' for every such order (rule “' . $ru['title'] . '”). A second penalty here would charge the same person twice, so I left this one at ₹0. Say “add ₹100 penalty” if you really want both.'; }
        else { $d['pen_amount'] = 100.0; $d['pen_proposed'] = 1; $notes[] = 'You spoke of a penalty but gave no amount. I propose ₹100. Change it if you want.'; }
    }
    $probe = $d; $probe['allow_unowned'] = 1; if ($d['kind'] === 'improve' && empty($probe['link_emp']) && empty($probe['ref_item_id'])) { $probe['link_emp'] = !empty($d['emp_id']) ? $d['emp_id'] : 0; }
    list($c, $err) = b2_item_clean($pdo, $probe);
    $checks = []; $kl = b2_kind_label($d['kind']); $needs = null; $summary = null; $capFull = false;
    $ownerName = !empty($d['emp_id']) ? bd_emp_name($pdo, $d['emp_id']) : (!empty($d['seat_id']) ? b2_seat_title($pdo, $d['seat_id']) : '');
    if (!$c) {
        $checks[] = ['n' => 1, 'name' => 'Understood', 'ok' => false, 'text' => $err ?: 'I could not understand this. Say what must be done and by whom.'];
    } else {
        $secName = $c['section_code'] ? b2_section_name($pdo, $c['section_code']) : 'no section yet';
        $ownerName = $c['seat_id'] ? b2_seat_title($pdo, $c['seat_id']) : $ownerName;
        $auto = $c['proof'] === 'auto';
        // when
        if ($c['block'] === 'once') $whenTxt = 'once, due ' . date('D j M', strtotime($c['due_date'] ?: b2_day())) . ' by ' . b2_t12($c['due_time']);
        elseif ($c['kind'] === 'temp') $whenTxt = 'every working day (' . b2_dow_label($c['dow']) . ') by ' . b2_t12($c['due_time']) . ' for ' . max(1, (int)round((strtotime($c['end_date']) - strtotime($c['start_date'])) / 86400) + 1) . ' days';
        else $whenTxt = 'every working day (' . b2_dow_label($c['dow']) . ') by ' . b2_t12($c['due_time']);
        // where does the data come from?
        $words = [];
        if (!$key && !$auto && in_array($c['proof'], ['tick', 'text', 'number', 'screenshot'], true) && $c['kind'] !== 'improve') { foreach (b2_needs_data_words() as $wd) if (mb_strpos(mb_strtolower($full), $wd) !== false) $words[] = $wd; }
        if ($key && $data && !$data['reachable']) { $needs = ['why' => 'The number “' . $data['label'] . '” should come from ' . $data['source'] . ', but that data is not on this server yet.', 'words' => []]; }
        elseif ($words && empty($d['no_auto'])) { $needs = ['why' => 'Your sentence is about ' . implode(', ', array_slice($words, 0, 3)) . '. No screen in the system gives me that number yet, so today it could only be ticked by hand.', 'words' => array_slice($words, 0, 4)]; }
        if ($key && $data && $data['reachable']) $dataTxt = ($data['mode'] === 'zero' ? 'The system reads “' . $data['label'] . '” by itself from ' . $data['source'] . '. Nobody types it. ' : 'The system reads the punch of the owner by itself from ' . $data['source'] . '. ') . $data['now_text'];
        elseif (!empty($d['no_auto']) && !$key) $dataTxt = 'You chose to tick this by hand. It stays manual until a system is built for it.';
        elseif ($needs) $dataTxt = $needs['why'] . ' You can (1) add it with a manual tick for now, or (2) put it in the Build system list: it is built later, and this task starts when the system is live.';
        else $dataTxt = 'Done by the owner (' . (b2_proofs()[$c['proof']] ?? $c['proof']) . '). It needs no number from the system.';
        // penalty
        $unitTxt = ($c['pen_mode'] ?? 'flat') === 'unit' ? b2_inr($c['pen_unit']) . ' for each ' . (rtrim(trim((string)($c['unit'] ?? '')) ?: 'item', 's') ?: 'item') . ' not done' . ((float)$c['pen_min'] > 0 ? ', at least ' . b2_inr($c['pen_min']) : '') . ', at most ' . b2_inr($c['pen_max']) . ' a day' : '';
        $penTxt = $unitTxt !== '' ? 'If missed, I will ASK you about ' . $unitTxt . ' for ' . ($ownerName ?: 'the owner') . ' (never automatic; free for the first ' . (int)b2_cfg('fair_days') . ' days). Only items above the starting backlog are counted.' : ($c['pen_amount'] > 0 ? 'If missed, I will ASK you about ' . b2_inr($c['pen_amount']) . ' for ' . ($ownerName ?: 'the owner') . ' (never automatic; free for the first ' . (int)b2_cfg('fair_days') . ' days).' . (!empty($d['pen_proposed']) ? ' (my proposal)' : '') : ($ruleTxt ?: 'No penalty for now. You can add one later if it slips.'));
        $see = $c['seat_id'] && (!empty($d['emp_id']) || b2_seat_is_person(bd_seat_row($pdo, $c['seat_id']) ?: [])) ? 'Only ' . $ownerName . ' sees it on their own list. Managers of ' . $secName . ' see it on the section board. Nobody else.' : 'Whoever holds the seat “' . $ownerName . '” sees it. Managers of ' . $secName . ' see it on the section board.';
        $judge = $auto && $data && $data['mode'] === 'zero' ? 'At ' . b2_t12($c['due_time']) . ' the system looks at the number. If it is not 0 the day is MISSED; if it is 0 the day is done' . ' (nothing for the owner to tick).' : null;
        $penShort = $unitTxt !== '' ? 'If missed, I ask you about ' . $unitTxt . '.' : ($c['pen_amount'] > 0 ? 'If missed, I ask you about ' . b2_inr($c['pen_amount']) . '.' : ($ruleTxt !== '' ? 'No extra penalty here: Dispatch already fines this.' : 'No penalty.'));
        $say = ($ownerName ?: 'Someone') . ' (' . $secName . '): “' . $c['title'] . '” - ' . $whenTxt . '. ' . ($judge ?: ($auto ? 'The system checks it by itself.' : 'Proof: ' . (b2_proofs()[$c['proof']] ?? $c['proof']) . '.')) . ' ' . $penShort;
        $summary = ['say' => trim($say), 'round' => $round, 'rows' => [
            ['k' => 'Type', 'v' => $kl . ($c['block'] === 'once' ? ' (one job)' : ($c['kind'] === 'temp' ? ' (a daily job for a limited time)' : ' (repeats every working day)'))],
            ['k' => 'Section', 'v' => $secName],
            ['k' => 'Owner', 'v' => $ownerName ?: 'not chosen'],
            ['k' => 'When', 'v' => $whenTxt],
            ['k' => $auto ? 'Checked by' : 'Proof', 'v' => $auto ? ($data ? 'The system (' . $data['source'] . ')' : 'The system') : (b2_proofs()[$c['proof']] ?? $c['proof'])],
            ['k' => 'Who sees it', 'v' => $see],
            ['k' => 'Penalty', 'v' => $unitTxt !== '' ? $unitTxt . ' (asked, never automatic)' : ($c['pen_amount'] > 0 ? b2_inr($c['pen_amount']) . ' (asked, never automatic)' : ($ruleTxt !== '' ? 'None extra (Dispatch rule already charges)' : 'None'))],
        ], 'notes' => $notes];
        if ($c['block'] === 'repeat' && $c['seat_id']) {
            $ln = b2_load_for_seat($pdo, (int)$c['seat_id']); $lc = b2_cap(); $after = $ln + 1; $capFull = $ln >= $lc;
            $summary['rows'][] = ['k' => 'Workload', 'v' => ($ownerName ?: 'The owner') . ' would then have ' . $after . ' of ' . $lc . ' daily tasks' . ($capFull ? ' (over the limit)' : '')];
            if ($capFull) $summary['notes'][] = b2_cap_block($pdo, $c);
            elseif ($after === $lc) $summary['notes'][] = ($ownerName ?: 'The owner') . ' will be full with this one: the next daily task for them will ask you to merge, pause or move one.';
        }
        if ($ownerGuess && $ownerName) $summary['notes'][] = 'You did not name an owner, so I chose ' . $ownerName . ' - the person who normally holds this work in ' . $secName . '.';
        if ($ruleTxt !== '') $summary['notes'][] = $ruleTxt;
        if ($judge) $summary['notes'][] = 'This is why the form says “Checked at”, not “Must be done by”: the owner has nothing to tick; the system looks at the number at that time.';
        $checks[] = ['n' => 1, 'name' => 'Understood', 'ok' => true, 'text' => 'A ' . $kl . ' task: “' . $c['title'] . '”' . ($c['section_code'] ? ' in ' . $secName : '') . '.'];
        $checks[] = ['n' => 2, 'name' => 'Where the number comes from', 'ok' => !$needs, 'text' => $dataTxt];
        $ov = []; $mine = implode(' ', b2_words($c['title']));
        $q = $pdo->prepare("SELECT id, title, kind, status FROM b2_items WHERE status IN ('active','draft') ORDER BY id DESC LIMIT 400"); $q->execute();
        foreach ($q->fetchAll() as $r2) { $s = bd_sim($mine, implode(' ', b2_words($r2['title']))); if ($s >= 0.45) $ov[] = ['id' => (int)$r2['id'], 'title' => $r2['title'], 'kind_label' => b2_kind_label($r2['kind']), 'sim' => round($s, 2)]; }
        $checks[] = ['n' => 3, 'name' => 'Does it overlap?', 'ok' => !$ov, 'text' => $ov ? 'Looks like “' . b2_short($ov[0]['title'], 60) . '” already exists (' . $ov[0]['kind_label'] . '). Check before adding.' : 'Nothing similar exists.'];
        $checks[] = ['n' => 4, 'name' => 'Who owns it, who sees it', 'ok' => (bool)$c['seat_id'], 'text' => $c['seat_id'] ? $see . ($c['backup_seat_id'] ? ' Backup: ' . b2_seat_title($pdo, $c['backup_seat_id']) . '.' : ' Add a backup if this person is often away.') : 'No owner found. Choose who owns it.'];
        $checks[] = ['n' => 5, 'name' => $auto ? 'When it is checked' : 'Update, proof and cut-off', 'ok' => true, 'text' => ucfirst($whenTxt) . '. ' . ($judge ?: ('Proof: ' . (b2_proofs()[$c['proof']] ?? $c['proof']) . '.'))];
        $checks[] = ['n' => 6, 'name' => 'Penalty', 'ok' => $ruleTxt === '', 'text' => $penTxt];
        $checks[] = ['n' => 7, 'name' => 'One tap', 'ok' => (bool)$c['seat_id'] && !$needs, 'text' => !$c['seat_id'] ? 'Choose an owner first, then press Add.' : ($needs ? 'You can still add it with a manual tick, or put it in the Build system list.' : 'Press Add and it starts ' . ($c['block'] === 'once' ? 'now.' : 'today.'))];
    }
    $questions = ($ask && !$prev) ? b2_draft_questions($pdo, $full, $d, $key, $ruleTxt, !empty($d['seat_id']) ? (int)$d['seat_id'] : 0) : [];
    if ($questions || ($ask && !$prev)) { $have = []; foreach ($aiQs as $x) $have[$x['id']] = 1; $keep = []; foreach ($questions as $x) if (!isset($have[$x['id']])) $keep[] = $x; $questions = array_slice(array_merge($aiQs, $keep), 0, 3); }
    $out = $c ? array_merge($d, ['seat_id' => $c['seat_id'], 'title' => $c['title'], 'due_time' => $c['due_time'], 'due_date' => $c['due_date'], 'dow' => $c['dow'], 'proof' => $c['proof'], 'section_code' => $c['section_code'], 'adapter' => $c['adapter'], 'cmp' => $c['cmp'], 'target' => $c['target'], 'unit' => $c['unit'], 'pen_amount' => $c['pen_amount'], 'kind' => $c['kind']]) : $d;
    if ($summary) { $summary['understood'] = $aiUnd; $summary['impact'] = b2_ai_impact($pdo, $c, $key ?? ''); }
    $aiState = ['on' => b2_ai_on(), 'used' => $usedAi, 'why' => $usedAi ? '' : (b2_ai_on() ? 'Claude did not answer this time, so the built-in word reader was used.' : 'Claude AI is switched off or has no key, so the built-in word reader was used.')];
    return ['draft' => $out, 'owner' => $ownerName, 'checks' => $checks, 'overlaps' => $ov ?? [], 'ai' => $usedAi, 'ai_state' => $aiState, 'ready' => $c && $c['seat_id'] && !$capFull, 'cap_full' => $capFull, 'summary' => $summary, 'data' => $data, 'needs_system' => $needs, 'round' => $round, 'original' => $orig, 'questions' => $questions];
}

/* ============================== the Ask box ============================== */
function b2_facts(PDO $pdo, array $w, $section = '') {
    $tc = b2_tile_counts($pdo, $w, $section); $td = b2_today_stats($pdo, $w, $section);
    $slip = array_values(array_filter($tc['cards'], function ($c) { return $c['state'] === 'slipping'; }));
    $late = array_values(array_filter($tc['cards'], function ($c) { return $c['state'] === 'late'; }));
    $pr = $pdo->query("SELECT id, title, step, section_code FROM b2_problems WHERE step != 'closed' AND " . b2_scope_sql($pdo, $w, 'section_code') . " ORDER BY id DESC LIMIT 8")->fetchAll();
    $away = []; foreach (bd_employees($pdo) as $e) { $a = bd_away($pdo, (int)$e['id'], b2_day()); if ($a !== '' && $a !== 'left the company') $away[] = $e['name'] . ' (' . $a . ')'; }
    return ['section' => $section ? b2_section_name($pdo, $section) : 'Whole business', 'today' => $td, 'active_tasks' => count($tc['cards']),
        'slipping' => array_map(function ($c) { return ['task' => $c['title'], 'owner' => $c['owner_now'], 'section' => $c['section'], 'kind' => $c['kind_label']]; }, array_slice($slip, 0, 8)),
        'late' => array_map(function ($c) { return ['task' => $c['title'], 'owner' => $c['owner_now'], 'section' => $c['section']]; }, array_slice($late, 0, 8)),
        'numbers' => array_map(function ($n) { return ['name' => $n['title'], 'today' => $n['today'], 'target' => $n['target'], 'avg14' => $n['avg14'], 'unit' => $n['unit']]; }, array_slice(b2_numbers($pdo, ['section' => $section, 'scope' => b2_scope_sql($pdo, $w, 'i.section_code')]), 0, 8)),
        'penalties_top' => b2_pen_top($pdo, $w, 3), 'penalty_month_total' => b2_pen_month($pdo), 'problems' => array_map(function ($p) use ($pdo) { return ['id' => (int)$p['id'], 'title' => $p['title'], 'step' => b2_step_label($p['step']), 'section' => $p['section_code'] ? b2_section_name($pdo, $p['section_code']) : '']; }, $pr),
        'away_today' => array_slice($away, 0, 12), 'needs_you' => b2_needs($pdo, $w, 5)['total']];
}
function b2_ask_builtin(array $f, $q) {
    $t = mb_strtolower($q);
    if (b2_has($t, ['penalt', 'fine', 'charged'])) {
        if (!$f['penalties_top']) return 'Nobody has been charged this month' . ($f['penalty_month_total'] > 0 ? '.' : ', and no penalty is waiting for you.');
        $x = $f['penalties_top'][0];
        return 'Highest penalty this month: ' . $x['emp'] . ' ' . b2_inr($x['charged']) . ($x['waiting'] ? ' (and ' . $x['waiting'] . ' waiting for your decision, ' . b2_inr($x['waiting_amount']) . ')' : '') . '. ' . (count($f['penalties_top']) > 1 ? 'Next: ' . implode(', ', array_map(function ($p) { return $p['emp'] . ' ' . b2_inr($p['charged']); }, array_slice($f['penalties_top'], 1))) . '. ' : '') . 'All together this month: ' . b2_inr($f['penalty_month_total']) . '.';
    }
    if (b2_has($t, ['slip', 'behind', 'bad', 'worst', 'not doing', 'missing'])) { if (!$f['slipping'] && !$f['late']) return 'Nothing is slipping or late right now in ' . $f['section'] . '.'; return 'Slipping: ' . ($f['slipping'] ? implode('; ', array_map(function ($s) { return $s['task'] . ' (' . $s['owner'] . ')'; }, $f['slipping'])) : 'none') . '. Late: ' . ($f['late'] ? implode('; ', array_map(function ($s) { return $s['task'] . ' (' . $s['owner'] . ')'; }, $f['late'])) : 'none') . '.'; }
    if (b2_has($t, ['late', 'overdue'])) return $f['late'] ? 'Late: ' . implode('; ', array_map(function ($s) { return $s['task'] . ' (' . $s['owner'] . ')'; }, $f['late'])) . '.' : 'Nothing is late right now.';
    if (b2_has($t, ['leave', 'absent', 'away', 'who is in', 'not in'])) return $f['away_today'] ? 'Away today: ' . implode(', ', $f['away_today']) . '.' : 'Nobody is marked away today.';
    if (b2_has($t, ['number', 'sales', 'review', 'likes', 'follower', 'target'])) { if (!$f['numbers']) return 'No number tasks exist yet. Add one with the Claude button, for example “Enter Google reviews every day”.'; return implode('. ', array_map(function ($n) { return $n['name'] . ': ' . ($n['today'] === null ? 'not entered today' : b2_num($n['today'])) . ($n['target'] !== null ? ' (target ' . b2_num($n['target']) . ')' : '') . ($n['avg14'] !== null ? ', 14-day average ' . b2_num($n['avg14']) : ''); }, $f['numbers'])) . '.'; }
    if (b2_has($t, ['problem', 'issue', 'complain'])) return $f['problems'] ? 'Open problems: ' . implode('; ', array_map(function ($p) { return '#' . $p['id'] . ' ' . $p['title'] . ' (' . $p['step'] . ')'; }, $f['problems'])) . '.' : 'No problem is open.';
    $td = $f['today'];
    return $f['section'] . ': ' . ($td['due'] ? ($td['done'] + $td['review']) . ' of ' . $td['due'] . ' tasks done today, ' . $td['missed'] . ' missed, ' . $td['open'] . ' open' : 'no tasks due today') . '. ' . count($f['slipping']) . ' slipping, ' . count($f['late']) . ' late, ' . $f['needs_you'] . ' waiting for you.';
}
function b2_ask(PDO $pdo, array $w, $q, $section = '', $pageText = '') {
    $f = b2_facts($pdo, $w, $section);
    $a = b2_ai_text("You answer the owner of an Indian perfume business about his team's work. Use ONLY the FACTS (JSON). If the facts do not contain the answer, say you cannot see it yet and what would show it. 2 to 5 short plain sentences, names and numbers exactly as in the facts, no markdown.", "QUESTION: " . $q . "\nFACTS: " . bd_json($f) . ($pageText !== '' ? "\nTEXT ON THE PAGE HE IS LOOKING AT: " . mb_substr($pageText, 0, 1500) : ''), 600);
    return ['answer' => $a !== '' ? $a : b2_ask_builtin($f, $q), 'ai' => $a !== ''];
}

/* ============================== Punch rules: who punches how (fixed time / hours only / no rule) ============================== */
function b2_punch_hm($min) { $min = max(0, (int)$min); return intdiv($min, 60) . 'h ' . sprintf('%02d', $min % 60) . 'm'; }
function b2_punch_view(PDO $pdo, array $w) {
    if (!function_exists('emp_shift_map') || !function_exists('shift_raw_map')) return ['rows' => [], 'counts' => ['fixed' => 0, 'hours' => 0, 'free' => 0, 'short' => 0], 'error' => 'The attendance file (api.php) is older than this page. Upload the new api.php too.'];
    $date = function_exists('attendance_day_now') ? attendance_day_now($pdo) : b2_day();
    $map = emp_shift_map($pdo, $date); $raw = shift_raw_map($pdo); $defStart = function_exists('day_start_for') ? day_start_for($pdo, $date) : '10:00';
    $att = [];
    try { $st = $pdo->prepare("SELECT employee_id, punch_in, punch_out FROM attendance WHERE punch_date = ? AND punch_in IS NOT NULL"); $st->execute([$date]); foreach ($st->fetchAll() as $r) $att[(int)$r['employee_id']] = $r; } catch (Throwable $e) {}
    $now = time(); $rows = []; $counts = ['fixed' => 0, 'hours' => 0, 'free' => 0, 'short' => 0];
    foreach (bd_employees($pdo) as $e) {
        $id = (int)$e['id']; $s = $map[$id] ?? ['shift_start' => $defStart, 'shift_end' => '', 'late_after' => 15, 'own' => 0, 'mode' => 'fixed', 'hours_target' => 0, 'hours_full' => 0, 'win_from' => '', 'win_to' => ''];
        $rw = $raw[$id] ?? []; $mode = $s['mode'] ?? 'fixed'; $a = $att[$id] ?? null; $in = $a ? strtotime((string)$a['punch_in']) : 0; $out = ($a && $a['punch_out']) ? strtotime((string)$a['punch_out']) : 0;
        $counts[$mode]++;
        $f = ['start' => (string)($rw['shift_start'] ?? ''), 'end' => (string)($rw['shift_end'] ?? ''), 'late' => isset($rw['late_after']) ? (int)$rw['late_after'] : 15];
        $tk = ''; $tt = '';
        if ($mode === 'free') {
            $detail = 'Punch is optional · never late · never marked away';
            if ($in) { $tk = 'ok'; $tt = 'Punched ' . date('H:i', $in); } else { $tk = ''; $tt = 'Not punched'; }
        } elseif ($mode === 'hours') {
            $t = (float)$s['hours_target']; $full = (float)$s['hours_full'];
            $win = $s['win_from'] !== '' && $s['win_to'] !== '' ? 'start between ' . $s['win_from'] . ' and ' . $s['win_to'] : ($s['win_from'] !== '' ? 'start from ' . $s['win_from'] : ($s['win_to'] !== '' ? 'start by ' . $s['win_to'] : 'start any time'));
            $detail = rtrim(rtrim(number_format($t, 2, '.', ''), '0'), '.') . ' h a day · ' . $win . ($full < $t ? ' · full day from ' . rtrim(rtrim(number_format($full, 2, '.', ''), '0'), '.') . ' h' : '');
            if ($in) {
                $wk = (int)floor((($out > $in ? $out : $now) - $in) / 60); $need = (int)round($t * 60);
                if ($out > $in) { if ($wk >= (int)round($full * 60)) { $tk = 'ok'; $tt = 'Done ' . b2_punch_hm($wk); } else { $tk = 'bad'; $tt = 'Left at ' . b2_punch_hm($wk) . ' (short ' . b2_punch_hm($need - $wk) . ')'; $counts['short']++; } }
                else { $tk = 'warn'; $tt = b2_punch_hm($wk) . ' of ' . rtrim(rtrim(number_format($t, 2, '.', ''), '0'), '.') . 'h · working'; }
            } else { $tk = ''; $tt = 'Not in yet'; }
        } else {
            $detail = ($s['own'] ? 'In ' . $s['shift_start'] : 'Day start ' . $s['shift_start'] . ' (default)') . ($s['shift_end'] !== '' ? ' · Out ' . $s['shift_end'] : '') . ' · late after ' . (int)$s['late_after'] . ' min';
            if ($in) { $due = strtotime($date . ' ' . $s['shift_start'] . ':00') + 60 * (int)$s['late_after']; if ($in > $due) { $tk = 'bad'; $tt = 'Late ' . (int)round(($in - $due) / 60) . ' min'; } else { $tk = 'ok'; $tt = 'In ' . date('H:i', $in); } }
            else { $tk = ''; $tt = 'Not in yet'; }
        }
        $rows[] = ['id' => $id, 'name' => $e['name'], 'dept' => (string)$e['dept'], 'mode' => $mode, 'detail' => $detail, 'today_kind' => $tk, 'today' => $tt,
            'hours_target' => (float)($s['hours_target'] ?: 8), 'hours_full' => (float)$s['hours_full'], 'win_from' => $s['win_from'] ?? '', 'win_to' => $s['win_to'] ?? '',
            'fx_start' => $f['start'], 'fx_end' => $f['end'], 'fx_late' => $f['late']];
    }
    return ['rows' => $rows, 'counts' => $counts, 'date' => $date, 'day_start' => $defStart];
}
function b2_punch_save(PDO $pdo, array $in, $by) {
    if (!function_exists('shift_set_mode')) return [false, 'The attendance file (api.php) is older than this page. Upload the new api.php too.', 500];
    $ids = []; foreach ((array)($in['ids'] ?? []) as $i) { $i = (int)$i; if ($i > 0) $ids[$i] = $i; }
    if (!$ids) return [false, 'Choose at least one person.', 422];
    if (count($ids) > 400) return [false, 'Too many people at once.', 422];
    $mode = (string)($in['mode'] ?? ''); if (!in_array($mode, ['fixed', 'hours', 'free'], true)) return [false, 'Choose a rule.', 422];
    $start = trim((string)($in['shift_start'] ?? '')); $end = trim((string)($in['shift_end'] ?? '')); $late = max(0, min(240, (int)($in['late_after'] ?? 15)));
    if ($mode === 'fixed') { if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) return [false, 'Start must be HH:MM.', 422]; if ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end)) return [false, 'End must be HH:MM.', 422]; }
    $n = 0;
    foreach ($ids as $id) {
        $err = shift_set_mode($pdo, $id, $mode, $in['hours_target'] ?? 8, $in['hours_full'] ?? 0, trim((string)($in['win_from'] ?? '')), trim((string)($in['win_to'] ?? '')));
        if ($err !== '') return [false, $err, 422];
        if ($mode === 'fixed' && ($start !== '' || isset($in['late_after']))) {
            $pdo->prepare("UPDATE employee_shifts SET shift_start = ?, shift_end = ?, late_after = ? WHERE employee_id = ?")->execute([$start, $end, $late, $id]);
        }
        $n++;
    }
    $names = ['fixed' => 'Fixed time', 'hours' => 'Hours only', 'free' => 'No rule'];
    b2_log('punch', 'b2', $names[$mode] . ' set for ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' (ids ' . implode(',', array_slice($ids, 0, 12)) . ($n > 12 ? '…' : '') . ')', $by);
    return [true, $names[$mode] . ' saved for ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . '.', 200, ['count' => $n]];
}

/* ============================== system checks: tasks whose answer already lives in the system ==============================
 * A task that says "no order older than 48 hours without a tracking ID" must not be ticked by hand. The number is already
 * in the Parcels module, so the system reads it. Each entry here is one number the system can read by itself.
 *   mode 'zero'  : one number for the whole section; the task is DONE at the cut-off only when it is 0
 *   mode 'owner' : a number per person (the owner); the task is DONE when it reaches the target
 * A sentence that needs a number which is NOT in this list is a "system to build later" (see b2_draft_later).
 */
function b2_checks() {
    static $all = null; if ($all !== null) return $all;
    $all = b2_checks_static();
    // every active department rule (money, shop visits, supplies, parcels...) is also a number the system holds: how many items broke that rule today
    global $pdo; $topicSec = ['dispatch' => 'dispatch', 'shops' => 'retaildist', 'supplies' => 'supplies', 'punch' => 'hr'];
    try {
        if ($pdo instanceof PDO) foreach ($pdo->query("SELECT rkey, title, topic, amount FROM dp_rules WHERE active = 1 ORDER BY topic, sort_no")->fetchAll() as $r) {
            if (in_array($r['rkey'], ['tracking_48h', 'transit_day4', 'punch_missed', 'dsp_g3_48h'], true)) continue;
            $all['dp:' . $r['rkey']] = ['mode' => 'zero', 'label' => 'Rule “' . $r['title'] . '”: items broken today', 'source' => 'Departments (rule “' . $r['title'] . '”)', 'section' => $topicSec[$r['topic']] ?? '',
                'unit' => 'items', 'table' => 'dp_items', 'title' => 'None today: ' . $r['title'], 'why' => 'The department page already watches this rule. This task makes one owner answerable for it every day.',
                'bad' => 'items broke this rule today', 'rule_rate' => $r['rkey'], 'say' => $r['title'], 'dp' => $r['rkey']];
        }
    } catch (Throwable $e) {}
    return $all;
}
function b2_checks_static() {
    return b2_check_variants(b2_checks_base());
}
/** the same two Dispatch checks with another limit: tracking ID within 12..240 hours (steps of 6), in transit within 2..10 days */
function b2_check_variants(array $b) {
    foreach (range(12, 240, 6) as $h) { if ($h === 48) continue; $r = $b['tracking48']; foreach (['label', 'title', 'why', 'bad', 'say'] as $f) $r[$f] = str_replace('48 hours', $h . ' hours', $r[$f]); $r['variant'] = true; $b['tracking' . $h] = $r; }
    foreach (range(12, 240, 6) as $h) { if ($h === 48) continue; $r = $b['pickup48']; foreach (['label', 'title', 'why', 'bad', 'say'] as $f) $r[$f] = str_replace('48 hours', $h . ' hours', $r[$f]); $r['variant'] = true; $b['pickup' . $h] = $r; }
    foreach (range(2, 10) as $d) { if ($d === 4) continue; $r = $b['transit4']; foreach (['label', 'title', 'why', 'bad', 'say'] as $f) $r[$f] = str_replace('4 days', $d . ' days', $r[$f]); $r['variant'] = true; $b['transit' . $d] = $r; }
    return $b;
}
function b2_checks_base() {
    return [
        'tracking48' => ['mode' => 'zero', 'label' => 'Orders older than 48 hours with no tracking ID', 'source' => 'Parcels & Dispatch (Parcels tab)', 'section' => 'dispatch',
            'unit' => 'orders', 'table' => 'dp_dsp_parcels', 'title' => 'No order older than 48 hours is left without a tracking ID',
            'why' => 'Every order needs its tracking ID within 48 hours. A parcel with no ID cannot be followed and the customer starts to doubt us.',
            'bad' => 'orders are past 48 hours with no tracking ID', 'rule_rate' => 'tracking_48h', 'say' => 'Every working day make sure no order is older than 48 hours without a tracking ID'],
        'pickup48' => ['mode' => 'zero', 'label' => 'Parcels waiting more than 48 hours for the courier', 'source' => 'Parcels & Dispatch (Parcels tab)', 'section' => 'dispatch',
            'unit' => 'parcels', 'table' => 'dp_dsp_parcels', 'title' => 'No parcel waits more than 48 hours for the courier',
            'why' => 'A parcel that has its tracking ID but is not scanned by the courier within 48 hours usually never left the shop. Somebody must chase the courier.',
            'bad' => 'parcels have waited over 48 hours for the courier', 'rule_rate' => 'dsp_g3_48h', 'say' => 'Every working day make sure no parcel waits more than 48 hours for the courier to pick it up'],
        'transit4' => ['mode' => 'zero', 'label' => 'Parcels not in transit within 4 days', 'source' => 'Parcels & Dispatch (Parcels tab)', 'section' => 'dispatch',
            'unit' => 'parcels', 'table' => 'dp_dsp_parcels', 'title' => 'Every parcel is in transit within 4 days of the order',
            'why' => 'A parcel that has not started moving after 4 days usually becomes a complaint or a return.',
            'bad' => 'parcels are past 4 days and not in transit', 'rule_rate' => '', 'say' => 'Every working day make sure no parcel stays more than 4 days and is not in transit'],
        'punch' => ['mode' => 'owner', 'label' => 'Punch-in recorded today', 'source' => 'Attendance (punch page)', 'section' => '',
            'unit' => 'punch', 'table' => 'attendance', 'title' => 'Punch in every working day',
            'why' => 'Attendance is the base of salary and of every other check.', 'bad' => '', 'rule_rate' => '', 'say' => 'Everyone must punch in every working day'],
    ];
}
/** a time limit typed in a sentence: hours ("72 hours") or days ("within 3 days"). Returns hours, or null. $loose: a bare "3 days" counts too (used when the manager corrects an existing task) */
function b2_check_hours($text, $loose = false) {
    $t = mb_strtolower((string)$text); $w = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    if (preg_match('/\b(\d{2,3})\s*(?:working\s*)?(?:hours?|hrs?)\b/u', $t, $m)) return (int)$m[1];
    $pre = $loose ? '' : '(?:within|older than|more than|after|past|over|by|under|inside|in|upto|up to|before)\s+(?:the\s+)?(?:first\s+)?';
    if (preg_match('/\b' . $pre . '(\d{1,2}|two|three|four|five|six|seven|eight|nine|ten)\s*(?:working\s*)?days?\b/u', $t, $m)) { $d = is_numeric($m[1]) ? (int)$m[1] : $w[$m[1]]; return $d * 24; }
    return null;
}
/** the key of the tracking check for H hours (nearest step of 6, 12..240) */
function b2_check_track_key($h) { $h = (int)(round($h / 6) * 6); $h = max(12, min(240, $h)); return 'tracking' . $h; }
function b2_check_pick_key($h) { $h = (int)(round($h / 6) * 6); $h = max(12, min(240, $h)); return 'pickup' . $h; }
function b2_check_transit_key($h) { $d = (int)round($h / 24); $d = max(2, min(10, $d)); return 'transit' . $d; }
/** a manager's correction ("make it 72 hours") on a task that already uses a tracking / transit check: the same check with the new limit, '' when the text names no limit */
function b2_check_retime($adapter, $text) {
    $h = b2_check_hours($text, true); if ($h === null) return '';
    if (preg_match('/^tracking\d+$/', (string)$adapter)) return b2_check_track_key($h);
    if (preg_match('/^transit\d+$/', (string)$adapter)) return b2_check_transit_key($h);
    if (preg_match('/^pickup\d+$/', (string)$adapter)) return b2_check_pick_key($h);
    return '';
}
/** working-hours maths (same as Dispatch): H hours from the order time, Sundays not counted */
function b2_work_due($createdTs, $hours) {
    $t = (int)$createdTs; $left = (int)$hours * 3600; $guard = 0;
    while ($guard++ < 80) { $mid = strtotime(date('Y-m-d', $t) . ' +1 day'); if ((int)date('N', $t) === 7) { $t = $mid; continue; } $avail = $mid - $t; if ($avail >= $left) return $t + $left; $left -= $avail; $t = $mid; }
    return $t;
}
/** midnight at the end of day N of a parcel (day 1 = order date, Sundays are not days) */
function b2_work_day_end($createdTs, $n) {
    $d = date('Y-m-d', (int)$createdTs); if ((int)date('N', strtotime($d)) === 7) $d = date('Y-m-d', strtotime($d . ' +1 day'));
    $c = 1; while ($c < $n) { $d = date('Y-m-d', strtotime($d . ' +1 day')); if ((int)date('N', strtotime($d)) !== 7) $c++; }
    return strtotime($d . ' +1 day');
}
/** parcels past the limit: 'track' = still without a tracking ID after $n hours; 'transit' = not in transit after day $n */
function b2_late_parcels(PDO $pdo, $mode, $n, $nowTs) {
    if ($mode === 'track') { $q = $pdo->prepare("SELECT created_at FROM dp_dsp_parcels WHERE status = 'placed' AND created_at <= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - (int)$n * 3600)]); }
    else { $q = $pdo->prepare("SELECT created_at FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND created_at <= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - ((int)$n - 1) * 86400)]); }
    $c = 0; foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ca) { $ts = strtotime($ca); if (!$ts) continue; $due = $mode === 'track' ? b2_work_due($ts, $n) : b2_work_day_end($ts, $n); if ($due <= $nowTs) $c++; }
    return $c;
}
/** which system check does this sentence mean? '' when none */
function b2_check_match($text) {
    $t = mb_strtolower((string)$text);
    $has = function ($ws) use ($t) { foreach ($ws as $w) if (mb_strpos($t, $w) !== false) return true; return false; };
    $trk = $has(['tracking', 'awb', 'tracking id', 'tracking number', 'lr number']);
    // the courier's first scan: "courier pickup", "not picked up" - a different wait from the tracking ID itself
    if (($has(['pickup', 'courier wait', 'courier has not', 'courier not', 'not scanned', 'courier collect', 'for the courier']) || preg_match('/\bpick(?:s|ed|ing)?\s+(?:it |them |the parcels? |the parcel )?up\b/u', $t)) && !$has(['in transit', 'without a tracking', 'no tracking'])) { $lp = b2_check_hours($text); return $lp !== null ? b2_check_pick_key($lp) : 'pickup48'; }
    $inTransit = $has(['transit', 'in transit', 'not shipped', 'not picked', 'not moving']); $lim = b2_check_hours($text);
    if ($trk && !$inTransit && ($lim !== null || $has(['48', 'forty', 'two day', '2 day', '2 days']) || ($has(['not assigned', 'not given', 'missing', 'without', 'not added', 'not updated', 'not entered', 'unassigned', 'pending', 'not put'])))) return $lim !== null ? b2_check_track_key($lim) : 'tracking48';
    if ($trk && ($has(['48', 'forty', 'two day', '2 day', '2 days']) || ($has(['not assigned', 'not given', 'missing', 'without', 'not added', 'not updated', 'not entered', 'unassigned', 'pending', 'not put']))) && !$has(['transit'])) return 'tracking48';
    if ($inTransit && ($lim !== null || $has(['4 day', 'four day', '4 working', '4th day', '96 hour']))) return $lim !== null ? b2_check_transit_key($lim) : 'transit4';
    // a department rule named in the sentence (by its words)
    $best = ''; $bs = 0.0; $mine = implode(' ', b2_words($text));
    foreach (b2_checks() as $k => $r) { if (strpos($k, 'dp:') !== 0) continue; $rw = array_unique(b2_words($r['say'])); if (count($rw) < 3) continue; $sim = count(array_intersect($rw, b2_words($text))) / count($rw); if ($sim > $bs) { $bs = $sim; $best = $k; } }
    if ($best !== '' && $bs >= 0.7) return $best;
    if ($has(['punch in', 'punch-in', 'punches in', 'punchin', 'punch every', 'come on time', 'must punch'])) return 'punch';
    return '';
}
/** the number right now. ['value' => int|null, 'ok' => bool, 'text' => ''] ; value null = the system cannot give it */
function b2_check_value(PDO $pdo, $key, $now = null) {
    $reg = b2_checks(); $out = ['value' => null, 'ok' => false, 'text' => ''];
    if (!isset($reg[$key])) return $out;
    $nowS = date('Y-m-d H:i:s', $now ?: time());
    try {
        $pdo->query("SELECT 1 FROM " . $reg[$key]['table'] . " LIMIT 0");
        if ($key === 'tracking48') { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'placed' AND due48 <= ?"); $q->execute([$nowS]); $out['value'] = (int)$q->fetchColumn(); }
        elseif ($key === 'pickup48') { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'tracked' AND tracked_at IS NOT NULL AND tracked_at <= ? AND COALESCE(sr_status, '') NOT REGEXP 'untrace|undeliver|ndr|attempt|return|rto|lost|damag|refus|hold'"); $q->execute([date('Y-m-d H:i:s', ($now ?: time()) - 48 * 3600)]); $out['value'] = (int)$q->fetchColumn(); }
        elseif ($key === 'transit4') { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status IN ('placed','tracked') AND due4 <= ?"); $q->execute([$nowS]); $out['value'] = (int)$q->fetchColumn(); }
        elseif (preg_match('/^pickup(\d+)$/', $key, $mm)) { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_dsp_parcels WHERE status = 'tracked' AND tracked_at IS NOT NULL AND tracked_at <= ? AND COALESCE(sr_status, '') NOT REGEXP 'untrace|undeliver|ndr|attempt|return|rto|lost|damag|refus|hold'"); $q->execute([date('Y-m-d H:i:s', ($now ?: time()) - (int)$mm[1] * 3600)]); $out['value'] = (int)$q->fetchColumn(); }
        elseif (preg_match('/^tracking(\d+)$/', $key, $mm)) { $out['value'] = b2_late_parcels($pdo, 'track', (int)$mm[1], $now ?: time()); }
        elseif (preg_match('/^transit(\d+)$/', $key, $mm)) { $out['value'] = b2_late_parcels($pdo, 'transit', (int)$mm[1], $now ?: time()); }
        elseif (strpos($key, 'dp:') === 0) { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_items WHERE rkey = ? AND task_date = ? AND (status = 'missed' OR (status = 'open' AND due_at IS NOT NULL AND due_at <= ?))"); $q->execute([substr($key, 3), date('Y-m-d', $now ?: time()), $nowS]); $out['value'] = (int)$q->fetchColumn(); }
        elseif ($key === 'punch') { $out['value'] = null; $out['ok'] = true; $out['text'] = 'Read for the owner from Attendance each day.'; return $out; }
        $out['ok'] = $out['value'] !== null;
    } catch (Throwable $e) { $out['value'] = null; $out['ok'] = false; }
    return $out;
}
/** sentence for "N <things> right now" */
function b2_check_now_text($key, $n) {
    $reg = b2_checks(); if (!isset($reg[$key]) || $n === null) return '';
    $u = $reg[$key]['unit'];
    if (preg_match('/^tracking(\d+)$/', $key, $mm) && $key !== 'tracking48') { $h = (int)$mm[1]; return $n === 0 ? 'Right now no order is past ' . $h . ' hours without a tracking ID.' : ($n === 1 ? 'Right now 1 order is past ' . $h . ' hours without a tracking ID.' : 'Right now ' . $n . ' orders are past ' . $h . ' hours without a tracking ID.'); }
    if (preg_match('/^transit(\d+)$/', $key, $mm) && $key !== 'transit4') { $d = (int)$mm[1]; return $n === 0 ? 'Right now no parcel is past ' . $d . ' days without moving.' : 'Right now ' . $n . ' ' . ($n === 1 ? 'parcel is' : 'parcels are') . ' past ' . $d . ' days and not in transit.'; }
    if (preg_match('/^pickup(\d+)$/', $key, $mm)) { $h = (int)$mm[1]; return $n === 0 ? 'Right now no parcel has waited over ' . $h . ' hours for the courier.' : 'Right now ' . $n . ' ' . ($n === 1 ? 'parcel has' : 'parcels have') . ' waited over ' . $h . ' hours for the courier.'; }
    if ($key === 'tracking48') return $n === 0 ? 'Right now no order is past 48 hours without a tracking ID.' : ($n === 1 ? 'Right now 1 order is past 48 hours without a tracking ID.' : 'Right now ' . $n . ' orders are past 48 hours without a tracking ID.');
    if ($key === 'transit4') return $n === 0 ? 'Right now no parcel is past 4 days without moving.' : 'Right now ' . $n . ' ' . ($n === 1 ? 'parcel is' : 'parcels are') . ' past 4 days and not in transit.';
    if (strpos($key, 'dp:') === 0) return $n === 0 ? 'Right now nothing has broken this rule today.' : 'Right now ' . $n . ' ' . ($n === 1 ? 'item has' : 'items have') . ' broken this rule today.';
    return '';
}
/** words that say "this needs numbers from a system" - used to warn when no check exists for them */
function b2_needs_data_words() {
    return ['order', 'parcel', 'tracking', 'awb', 'ndr', 'rto', 'refund', 'cod', 'payment', 'invoice', 'stock', 'inventory', 'query', 'queries', 'chat', 'whatsapp', 'review', 'lead', 'enquiry', 'courier', 'complaint', 'ticket', 'followers', 'sales', 'revenue', 'remittance', 'settlement'];
}

/** "Claude reads the page": things in this section that should become tasks, each with the reason and a sentence that b2_draft turns into a full prefilled task */
function b2_suggest(PDO $pdo, array $w, $sec) {
    $out = []; $reg = b2_checks(); $sec = (string)$sec;
    $act = []; $adapters = [];
    try {
        foreach ($pdo->query("SELECT title, adapter, section_code, problem_id FROM b2_items WHERE status IN ('active','draft','paused')")->fetchAll() as $r) {
            $act[] = ['t' => implode(' ', b2_words($r['title'])), 'sec' => (string)$r['section_code'], 'pid' => (int)$r['problem_id']];
            if ($r['adapter']) $adapters[$r['adapter']] = 1;
        }
    } catch (Throwable $e) {}
    $dup = function ($title) use ($act) { $m = implode(' ', b2_words($title)); foreach ($act as $a) if (bd_sim($m, $a['t']) >= 0.45) return true; return false; };
    // 1. numbers the system already holds and nobody watches yet
    foreach ($reg as $key => $r) {
        if ($r['mode'] !== 'zero' || isset($adapters[$key]) || !empty($r['variant'])) continue;
        if ($key === 'tracking48' && count(preg_grep('/^tracking\\d+$/', array_keys($adapters)))) continue;
        if ($key === 'transit4' && count(preg_grep('/^transit\\d+$/', array_keys($adapters)))) continue;
        if ($key === 'pickup48' && count(preg_grep('/^pickup\\d+$/', array_keys($adapters)))) continue;
        if ($sec !== '' && $r['section'] !== $sec) continue;
        $v = b2_check_value($pdo, $key); if ($v['value'] === null) continue;
        $out[] = ['tag' => 'system', 'tag_label' => 'From the system', 'kind' => 'daily', 'kind_label' => 'Daily', 'title' => $r['title'], 'text' => $r['say'], 'title_hint' => $r['title'],
            'reason' => $v['value'] > 0 ? b2_check_now_text($key, $v['value']) . ' Nobody is watching this number every day.' : 'Fine today, but nobody watches this number every day. The system can check it by itself.', 'urgent' => $v['value'] > 0 ? 1 : 0, 'section_code' => $r['section']];
    }
    // 2. open problems of this section that no task carries yet
    if ($sec !== '') {
        try {
            $q = $pdo->prepare("SELECT id, title FROM b2_problems WHERE step != 'closed' AND section_code = ? ORDER BY id DESC LIMIT 6"); $q->execute([$sec]);
            foreach ($q->fetchAll() as $p) {
                $has = false; foreach ($act as $a) if ($a['pid'] === (int)$p['id']) { $has = true; break; } if ($has) continue;
                $out[] = ['tag' => 'problem', 'tag_label' => 'Open problem', 'kind' => 'fix', 'kind_label' => 'To fix', 'title' => 'Fix: ' . b2_short($p['title'], 80), 'text' => 'Fix this: ' . $p['title'], 'title_hint' => 'Fix: ' . b2_short($p['title'], 80),
                    'reason' => 'This problem is still open. A task with an owner and a date makes it move.', 'urgent' => 0, 'section_code' => $sec];
            }
        } catch (Throwable $e) {}
    }
    // 3. the starting set of duties for this section that is not running yet
    if ($sec !== '' && function_exists('bd_library')) {
        foreach (bd_library() as $k => $row) {
            if ($row['section'] !== $sec || $dup($row['title'])) continue;
            if ($row['freq'] !== 'daily' && $row['freq'] !== 'weekly') continue;
            $dn = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']; $dd = (int)preg_replace('/\D.*/', '', (string)$row['dow']);
            $fq = $row['freq'] === 'daily' ? 'Every working day' : ('Every ' . ($dd >= 1 && $dd <= 7 ? $dn[$dd] : 'Monday'));
            $out[] = ['tag' => 'library', 'tag_label' => 'Usual for this section', 'kind' => 'daily', 'kind_label' => $row['freq'] === 'daily' ? 'Daily' : 'Weekly', 'title' => $row['title'],
                'text' => $fq . ', ' . $row['title'], 'title_hint' => $row['title'], 'reason' => $row['why'], 'urgent' => 0, 'section_code' => $sec];
            if (count($out) >= 12) break;
        }
    }
    usort($out, function ($a, $b) { return ($b['urgent'] <=> $a['urgent']); });
    return array_slice($out, 0, 8);
}
/** the system cannot give this number yet: write it into the Build system list and keep the task waiting (paused) until it is live */
function b2_draft_later(PDO $pdo, array $in, $by) {
    $title = trim((string)($in['title'] ?? '')); if (mb_strlen($title) < 3) return [false, 'Give the task a name first.', 422];
    $sec = (string)($in['section_code'] ?? ''); if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
    $orig = trim((string)($in['original'] ?? '')); $need = trim((string)($in['need'] ?? ''));
    $spec = "WHAT THE MANAGER ASKED\n" . ($orig ?: $title) . "\n\nWHAT THE SYSTEM MUST GIVE\n" . ($need ?: 'A number the task can read by itself, so nobody types it.') . "\n\nTHE TASK THAT WAITS FOR IT\n" . $title . ($sec ? ' (' . b2_section_name($pdo, $sec) . ')' : '');
    $pdo->prepare("INSERT INTO b2_builds (title, spec, why, section_code, home, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([mb_substr('Auto-check: ' . $title, 0, 200), $spec, mb_substr($need ?: 'Task data must come from the system, not from typing.', 0, 500), $sec ?: null, null, 'spec', $by, bd_now()]);
    $bid = (int)$pdo->lastInsertId();
    $in2 = $in; $in2['build_id'] = $bid; $in2['allow_unowned'] = 1; $in2['proof'] = 'tick'; $in2['adapter'] = ''; unset($in2['id']);
    list($c, $err) = b2_item_clean($pdo, $in2); if (!$c) return [false, $err, 422];
    $iid = b2_item_insert($pdo, $c, 'paused', 'boss', $by);
    return [true, ['ok' => true, 'build_id' => $bid, 'id' => $iid, 'message' => 'Added to the Build system list. The task waits (paused) and starts when that system is live.'], 200];
}

/* ============================== the daily scan ==============================
 * Once a day Claude reads every number the system holds (the registry in 77_checks.php) and, when a number is bad
 * and NOBODY is made answerable for it, raises ONE problem into "Needs you" with the numbers, a confirmed cause and
 * 3 options. It never charges anyone. It is quiet on purpose:
 *   - a number that an active task already judges (adapter = this number) is "watched": no problem
 *   - one open problem per number: it is refreshed, not repeated
 *   - the number goes back to 0: a waiting problem closes by itself ("Back to zero")
 *   - a problem the boss closed by hand is not raised again for 7 days
 */
function b2_scan_open_problem(PDO $pdo, $ref) {
    $st = $pdo->prepare("SELECT * FROM b2_problems WHERE old_ref = ? AND step != 'closed' ORDER BY id DESC LIMIT 1"); $st->execute([$ref]); return $st->fetch() ?: null;
}
function b2_scan_recent_manual_close(PDO $pdo, $ref) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM b2_problems WHERE old_ref = ? AND step = 'closed' AND closed_at >= ? AND closed_reason NOT LIKE 'Back to zero%' AND closed_reason NOT LIKE 'No repeat%' AND closed_reason NOT LIKE 'It came back%'");
    $st->execute([$ref, date('Y-m-d H:i:s', strtotime('-7 days'))]); return (int)$st->fetchColumn() > 0;
}
function b2_scan_title($key, array $r, $n) {
    if (strpos($key, 'dp:') === 0) return $n . ' ' . ($n === 1 ? 'item' : 'items') . ' broke the rule “' . $r['say'] . '” today';
    $bad = (string)$r['bad']; if ($bad === '') return $n . ' ' . $r['unit'] . ': ' . $r['label'];
    if ($n === 1) $bad = preg_replace(['/^orders are/', '/^parcels are/', '/^items broke/'], ['order is', 'parcel is', 'item broke'], $bad);
    return $n . ' ' . $bad;
}
function b2_scan_raise(PDO $pdo, $key, array $r, $n, $nowTs) {
    $sec = (string)$r['section']; if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
    $what = $n . ' ' . ($n === 1 ? rtrim($r['unit'], 's') : $r['unit']); $title = b2_scan_title($key, $r, $n);
    $now = b2_check_now_text($key, $n);
    $body = "Found by the daily scan on " . date('j M Y, g:i a', $nowTs) . ".\n" . ($now !== '' ? $now . "\n" : '') . 'Source: ' . $r['source'] . ".\n" . $r['why'] . "\nNo daily task watches this number, so nobody is asked about it.";
    $cause = 'The system counts ' . $what . ' right now. Source: ' . $r['source'] . '. No task makes one person answerable for this every day, so it depends on somebody remembering.';
    $ev = [['t' => 'scan', 'text' => $now !== '' ? $now : ('The system counts ' . $what . '.'), 'strong' => true, 'n' => $n], ['t' => 'nocover', 'text' => 'No active task reads this number every day.', 'strong' => false]];
    $ins = $pdo->prepare("INSERT INTO b2_problems (title, body, by_emp, by_name, section_code, page, src, sev, step, cause_level, cause_text, evidence, options, old_ref, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([mb_substr($title, 0, 200), $body, null, 'Claude (daily scan)', $sec ?: null, null, 'scan', $n >= 10 ? 'high' : 'med', 'decide', 'confirmed', $cause, bd_json($ev), '[]', 'scan:' . $key, date('Y-m-d H:i:s', $nowTs), date('Y-m-d H:i:s', $nowTs)]);
    $id = (int)$pdo->lastInsertId(); $p = b2_row($pdo, 'b2_problems', $id);
    $opts = [];
    $opts[] = b2_option($pdo, $p, 'daily', 'Make a daily task that the system checks by itself', 'Claude reads this number every day, nobody types it, and the task is done only when it is 0. A penalty is never automatic.', ['title' => $r['title'], 'why' => $r['why'], 'adapter' => $key, 'proof' => 'auto', 'cmp' => 'max', 'target' => 0, 'unit' => $r['unit'], 'due_time' => (string)b2_cfg('cutoff')]);
    $opts[] = b2_option($pdo, $p, 'followup', 'Ask somebody to clear them today', 'One task, one owner, one deadline. Nothing repeats.', ['title' => 'Clear today: ' . $what . ' (' . b2_short($r['label'], 60) . ')', 'kind' => 'followup']);
    $opts[] = b2_option($pdo, $p, 'closed', 'Not a problem — close it', 'Claude will not raise this number again for 7 days.', ['reason' => 'The boss closed it as not a problem (daily scan).']);
    foreach ($opts as $i => &$o) $o['id'] = chr(97 + $i); unset($o);
    $pdo->prepare("UPDATE b2_problems SET options = ? WHERE id = ?")->execute([bd_json($opts), $id]);
    b2_ev($pdo, null, null, $id, null, 'reported', b2_short($now !== '' ? $now : $title, 300));
    b2_ev($pdo, null, null, $id, null, 'cause', 'Confirmed: ' . $cause);
    return $id;
}
function b2_scan(PDO $pdo, $now = null) {
    $nowTs = $now ?: time(); $out = ['looked' => 0, 'watched' => 0, 'raised' => 0, 'refreshed' => 0, 'closed' => 0, 'cannot_read' => 0, 'quiet' => 0, 'new' => []];
    foreach (b2_checks() as $key => $r) {
        if ($r['mode'] !== 'zero' || !empty($r['variant'])) continue;
        $v = b2_check_value($pdo, $key, $nowTs);
        if ($v['value'] === null) { $out['cannot_read']++; continue; }
        $out['looked']++; $n = (int)$v['value']; $ref = 'scan:' . $key;
        $open = b2_scan_open_problem($pdo, $ref);
        if ($n === 0) {
            if ($open && $open['step'] === 'decide') {
                $pdo->prepare("UPDATE b2_problems SET step = 'closed', route = 'closed', closed_reason = ?, closed_at = ?, updated_at = ? WHERE id = ?")->execute(['Back to zero on ' . date('j M', $nowTs) . '. Nothing is waiting any more.', bd_now(), bd_now(), $open['id']]);
                b2_ev($pdo, null, null, (int)$open['id'], null, 'closed', 'Back to zero. Closed by the scan.'); $out['closed']++;
            }
            continue;
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE adapter = ? AND status = 'active'"); $q->execute([$key]);
        if ((int)$q->fetchColumn() > 0) { $out['watched']++; continue; }                       // a task already judges it: the penalty flow handles a miss
        if ($open) {
            $now2 = b2_check_now_text($key, $n);
            $pdo->prepare("UPDATE b2_problems SET title = ?, updated_at = ? WHERE id = ? AND step = 'decide'")->execute([mb_substr(b2_scan_title($key, $r, $n), 0, 200), bd_now(), $open['id']]);
            b2_ev($pdo, null, null, (int)$open['id'], null, 'scan', 'Still there on ' . date('j M', $nowTs) . ': ' . ($now2 !== '' ? $now2 : $n)); $out['refreshed']++; continue;
        }
        if (b2_scan_recent_manual_close($pdo, $ref)) { $out['quiet']++; continue; }
        $id = b2_scan_raise($pdo, $key, $r, $n, $nowTs); $out['raised']++; $out['new'][] = $id;
    }
    if ($out['raised'] > 0) { $top = (int)bd_cfg_fresh('top_boss'); if ($top) bd_push($pdo, $top, 'Daily scan', $out['raised'] . ' new problem' . ($out['raised'] === 1 ? '' : 's') . ' need your decision.'); }
    $out['at'] = bd_now(); b2_state_put($pdo, 'scan_last', bd_json($out));
    return $out;
}
/** called by the tick: once a day, at or after scan_hour */
function b2_scan_due(PDO $pdo, $nowTs) {
    if (b2_cfg('scan') !== '1') return null;
    $hour = (int)b2_cfg('scan_hour'); if ($hour < 0 || $hour > 23) $hour = 11;
    $today = b2_day($nowTs);
    if (b2_dow($today) === 7 || (int)date('G', $nowTs) < $hour || b2_state_get($pdo, 'scan_done', '') === $today) return null;
    b2_state_put($pdo, 'scan_done', $today);
    return b2_scan($pdo, $nowTs);
}

/* ============================== the Boss panel: cap, accountability, chains, notes, go-live, strip ==============================
 * Everything here only READS the engine (and flips the live switches). Nothing here charges anyone.
 */

/* ----- workload cap: at most N daily tasks per person (or per team seat) ----- */
function b2_cap() { $c = (int)b2_cfg('cap_daily'); return $c >= 1 ? $c : 6; }
/** every person / team seat that owns at least one active daily task, with its count */
function b2_loads(PDO $pdo, $w = null) {
    $sql = "SELECT id, seat_id, section_code, title FROM b2_items WHERE status = 'active' AND block = 'repeat' AND seat_id IS NOT NULL";
    if (is_array($w)) $sql .= " AND " . b2_scope_sql($pdo, $w, 'section_code');
    $by = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) { $by[(int)$r['seat_id']][] = $r; }
    $cap = b2_cap(); $out = [];
    foreach ($by as $sid => $rows) {
        $seat = bd_seat_row($pdo, $sid); if (!$seat) continue;
        $person = b2_seat_is_person($seat); $people = b2_seat_people($pdo, $sid);
        $secs = []; foreach ($rows as $r) { if ($r['section_code']) $secs[$r['section_code']] = b2_section_name($pdo, $r['section_code']); }
        $n = count($rows);
        $out[] = ['seat_id' => $sid, 'kind' => $person ? 'person' : 'seat', 'emp_id' => $person && $people ? (int)$people[0]['id'] : null,
            'name' => $person && $people ? $people[0]['name'] : b2_seat_title($pdo, $sid), 'holders' => count($people), 'sections' => array_values($secs), 'section_codes' => array_keys($secs),
            'n' => $n, 'cap' => $cap, 'full' => $n >= $cap, 'pct' => (int)min(100, round(100 * $n / $cap)), 'titles' => array_slice(array_map(function ($r) { return $r['title']; }, $rows), 0, 8)];
    }
    usort($out, function ($a, $b) { return $b['n'] <=> $a['n']; });
    return $out;
}
function b2_load_for_seat(PDO $pdo, $seatId) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE seat_id = ? AND status = 'active' AND block = 'repeat'"); $q->execute([(int)$seatId]); return (int)$q->fetchColumn();
}
/** '' when a new daily task fits; otherwise the sentence that tells the boss what to do */
function b2_cap_block(PDO $pdo, array $c) {
    if (($c['block'] ?? '') !== 'repeat' || empty($c['seat_id'])) return '';
    $n = b2_load_for_seat($pdo, (int)$c['seat_id']); $cap = b2_cap();
    if ($n < $cap) return '';
    $who = b2_seat_title($pdo, (int)$c['seat_id']);
    return $who . ' already has ' . $n . ' of ' . $cap . ' daily tasks. Merge, pause or move one first (Boss > People & seats), or raise the limit in Setup & health.';
}

/* ----- accountability: every daily task has a name, a 7-day strip and a streak. Nothing retires by itself. ----- */
function b2_accountability(PDO $pdo, array $w, $limit = 250) {
    $st = $pdo->query("SELECT i.* FROM b2_items i WHERE i.status = 'active' AND i.block = 'repeat' AND " . b2_scope_sql($pdo, $w, 'i.section_code') . " ORDER BY i.section_code, i.id LIMIT " . (int)$limit);
    $items = $st->fetchAll(); if (!$items) return ['rows' => [], 'people' => []];
    $ids = array_map(function ($r) { return (int)$r['id']; }, $items);
    $strips = b2_strips($pdo, $ids, 30); $rows = []; $per = [];
    foreach ($items as $it) {
        $id = (int)$it['id']; $days = $strips[$id]['days']; $streak = 0;
        for ($i = count($days) - 1; $i >= 0; $i--) { $s = $days[$i]['s']; if ($s === 'open' || $s === 'off') continue; if ($s === 'done' || $s === 'late' || $s === 'review') $streak++; else break; }
        $k7 = 0; $d7 = 0; foreach (array_slice($days, -7) as $d) { if ($d['s'] === 'off' || $d['s'] === 'open') continue; $d7++; if (in_array($d['s'], ['done', 'late', 'review'], true)) $k7++; }
        $k30 = 0; $d30 = 0; foreach ($days as $d) { if ($d['s'] === 'off' || $d['s'] === 'open') continue; $d30++; if (in_array($d['s'], ['done', 'late', 'review'], true)) $k30++; }
        $seat = $it['seat_id'] ? bd_seat_row($pdo, (int)$it['seat_id']) : null; $people = $seat ? b2_seat_people($pdo, (int)$it['seat_id']) : [];
        $owner = $people ? implode(', ', array_map(function ($p) { return $p['name']; }, array_slice($people, 0, 2))) : '';
        if ($seat && !b2_seat_is_person($seat) && $people) $owner = b2_seat_title($pdo, (int)$it['seat_id']);
        $today = end($days); $skey = $seat ? (int)$it['seat_id'] : 0;
        $rows[] = ['id' => $id, 'title' => $it['title'], 'section_code' => (string)$it['section_code'], 'section' => $it['section_code'] ? b2_section_name($pdo, $it['section_code']) : 'General',
            'owner' => $owner, 'hole' => !$people, 'seat_id' => $skey, 'checked_by' => $it['proof'] === 'auto' ? 'System' : ($it['proof'] === 'photo' ? 'Photo' : ($it['proof'] === 'number' ? 'Number' : 'Owner ticks')),
            'strip' => array_slice($days, -7), 'streak' => $streak, 'kept7' => $k7, 'days7' => $d7, 'today' => $today ? $today['s'] : 'off', 'fair' => $it['fair_until'] && $it['fair_until'] >= b2_day()];
        if (!isset($per[$skey])) $per[$skey] = ['seat_id' => $skey, 'kept' => 0, 'days' => 0, 'n' => 0]; $per[$skey]['kept'] += $k30; $per[$skey]['days'] += $d30; $per[$skey]['n']++;
    }
    foreach ($per as &$p) $p['kept_pct'] = $p['days'] ? (int)round(100 * $p['kept'] / $p['days']) : null; unset($p);
    return ['rows' => $rows, 'people' => array_values($per)];
}

/* ----- chains: the questions Claude keeps asking until every step of a section is a task, skipped, or waiting for a system ----- */
function b2_chains() {
    return ['dispatch' => ['title' => 'Parcels & Dispatch', 'steps' => [
        ['key' => 'tracking48', 'title' => 'A tracking ID within ' . b2_sync_now()[0] . ' hours', 'adapter' => 'tracking48'],
        ['key' => 'transit4', 'title' => 'In transit by day ' . b2_sync_now()[1], 'adapter' => 'transit4'],
        ['key' => 'pickup48', 'title' => 'Courier pickup within ' . b2_sync_now()[2] . ' hours', 'adapter' => 'pickup48'],
        ['key' => 'untraceable', 'title' => 'Untraceable parcels', 'say' => 'Every working day, by 6 PM, write a remark on every untraceable parcel and say what is being done.', 'match' => 'remark untraceable parcel courier'],
        ['key' => 'ndr', 'title' => 'NDR (failed delivery)', 'say' => 'Every working day call every customer whose parcel failed delivery (NDR) and write the answer by 6 PM.', 'match' => 'ndr failed delivery call customer', 'needs' => 'NDR count (failed deliveries per day)'],
        ['key' => 'rto', 'title' => 'RTO (parcel coming back)', 'say' => 'Every working day check every parcel that is coming back (RTO) and write why, by 6 PM.', 'match' => 'rto return origin parcel', 'needs' => 'RTO count (parcels coming back)'],
        ['key' => 'refunds', 'title' => 'Refunds', 'say' => 'Every working day make sure no approved refund is older than 3 days.', 'match' => 'refund approved days', 'needs' => 'Refund age (approved, not paid)'],
    ]]];
}
function b2_chain_state(PDO $pdo, $code) { $s = bd_unjson(b2_state_get($pdo, 'chain:' . $code, ''), []); return is_array($s) ? $s : []; }
function b2_chain_save(PDO $pdo, $code, array $s) { b2_state_put($pdo, 'chain:' . $code, bd_json($s)); }
/** the chain of one section: each step covered / later / skipped / open, and which open step Claude asks next */
function b2_chain_view(PDO $pdo, $code) {
    $ch = b2_chains(); if (!isset($ch[$code])) return null;
    $st = b2_chain_state($pdo, $code); $steps = []; $next = null; $covered = 0; $open = 0;
    $act = $pdo->prepare("SELECT id, title, seat_id FROM b2_items WHERE section_code = ? AND status = 'active'"); $act->execute([$code]); $items = $act->fetchAll();
    foreach ($ch[$code]['steps'] as $i => $s) {
        $r = ['n' => $i + 1, 'key' => $s['key'], 'title' => $s['title'], 'state' => 'open', 'say' => $s['say'] ?? '', 'needs' => $s['needs'] ?? '', 'owner' => '', 'item_id' => null, 'by_system' => false];
        $hit = null;
        foreach ($items as $it) {
            if (!empty($s['adapter'])) { $fam = preg_match('/^(tracking|transit|pickup)\d+$/', (string)$s['adapter'], $fm) ? '^' . $fm[1] . '[0-9]+$' : null;   // a 72-hour task covers the "tracking ID" step too
            $q = $fam ? $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE id = ? AND adapter REGEXP ?") : $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE id = ? AND adapter = ?"); $q->execute([(int)$it['id'], $fam ?: $s['adapter']]); if ((int)$q->fetchColumn() > 0) { $hit = $it; $r['by_system'] = true; break; } }
        }
        if (!$hit && !empty($st[$s['key']]['item_id'])) { foreach ($items as $it) if ((int)$it['id'] === (int)$st[$s['key']]['item_id']) { $hit = $it; break; } }
        if (!$hit && !empty($s['match'])) { $mine = implode(' ', b2_words($s['match'])); foreach ($items as $it) { if (bd_sim($mine, implode(' ', b2_words($it['title']))) >= 0.5) { $hit = $it; break; } } }
        if ($hit) { $r['state'] = 'covered'; $r['item_id'] = (int)$hit['id']; $r['owner'] = $hit['seat_id'] ? b2_seat_title($pdo, (int)$hit['seat_id']) : ''; $covered++; }
        elseif (($st[$s['key']]['state'] ?? '') === 'skip') $r['state'] = 'skip';
        elseif (($st[$s['key']]['state'] ?? '') === 'later') $r['state'] = 'later';
        else { $open++; if ($next === null) { $next = $i; } }
        $steps[] = $r;
    }
    if ($next !== null) $steps[$next]['state'] = 'ask';
    return ['code' => $code, 'title' => $ch[$code]['title'], 'steps' => $steps, 'total' => count($steps), 'covered' => $covered, 'open' => $open, 'pct' => count($steps) ? (int)round(100 * $covered / count($steps)) : 0, 'next' => $next !== null ? $steps[$next]['key'] : null];
}
function b2_chain_set(PDO $pdo, $code, $key, $state, $by) {
    $ch = b2_chains(); if (!isset($ch[$code])) return [false, 'No chain for this section yet.', 404];
    $step = null; foreach ($ch[$code]['steps'] as $s) if ($s['key'] === $key) $step = $s; if (!$step) return [false, 'Unknown step.', 404];
    if (!in_array($state, ['skip', 'later', 'open'], true)) return [false, 'Bad choice.', 422];
    $st = b2_chain_state($pdo, $code); $msg = 'Done.';
    if ($state === 'open') { unset($st[$key]); $msg = 'Claude will ask about this step again.'; }
    elseif ($state === 'skip') { $st[$key] = ['state' => 'skip', 'at' => bd_now(), 'by' => $by]; $msg = 'Noted: no task for “' . $step['title'] . '”. Claude will not ask again.'; }
    else {
        $need = $step['needs'] ?? $step['title']; $secs = b2_sections($pdo);
        $pdo->prepare("INSERT IGNORE INTO b2_builds (title, spec, why, section_code, home, status, seed_key, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([mb_substr($need, 0, 200),
            "WHAT IT IS\nThe system counts: " . $need . ", so Claude can watch it every day without anybody typing it.\n\nWHY\nPart of the " . $ch[$code]['title'] . " chain (" . $step['title'] . ").\n\nDONE WHEN\nThe number can be read by the engine like the 48-hour and day-4 numbers.",
            'Needed to watch “' . $step['title'] . '” by itself.', isset($secs[$code]) ? $code : null, '', 'spec', 'chain:' . $code . ':' . $key, $by, bd_now()]);
        $st[$key] = ['state' => 'later', 'at' => bd_now(), 'by' => $by]; $msg = '“' . $step['title'] . '” is on the Build system list. Tick it by hand until it is built.';
    }
    b2_chain_save($pdo, $code, $st); return [true, $msg];
}
/** a task made from a chain step remembers which step it answers */
function b2_chain_link(PDO $pdo, $ref, $itemId) {
    if (!preg_match('/^([a-z0-9_]+):([a-z0-9_]+)$/', (string)$ref, $m)) return;
    $ch = b2_chains(); if (!isset($ch[$m[1]])) return;
    $st = b2_chain_state($pdo, $m[1]); $st[$m[2]] = ['state' => 'made', 'item_id' => (int)$itemId, 'at' => bd_now()]; b2_chain_save($pdo, $m[1], $st);
}

/* ----- the numbers of a section (what the system reads by itself), cached for 5 minutes ----- */
function b2_check_cached(PDO $pdo, $key) {
    $ck = 'chkc:' . $key; $c = bd_unjson(b2_state_get($pdo, $ck, ''), []);
    if (is_array($c) && isset($c['t']) && time() - (int)$c['t'] < 300) return $c['v'];
    $v = b2_check_value($pdo, $key); $val = $v['value'] === null ? null : (int)$v['value'];
    b2_state_put($pdo, $ck, bd_json(['t' => time(), 'v' => $val])); return $val;
}
function b2_section_numbers(PDO $pdo, $code = null) {
    $out = [];
    foreach (b2_checks() as $key => $r) {
        if ($r['mode'] !== 'zero' || !empty($r['variant'])) continue; if ($code !== null && $r['section'] !== $code) continue;
        $v = b2_check_cached($pdo, $key); if ($v === null) continue;
        $q = $pdo->prepare("SELECT seat_id FROM b2_items WHERE adapter = ? AND status = 'active' LIMIT 1"); $q->execute([$key]); $sid = (int)$q->fetchColumn();
        $out[] = ['key' => $key, 'label' => $r['label'], 'unit' => $r['unit'], 'value' => $v, 'bad' => $v > 0, 'section_code' => $r['section'], 'watched_by' => $sid ? b2_seat_title($pdo, $sid) : '', 'bad_text' => (string)$r['bad']];
    }
    return $out;
}

/* ----- notes: information-only items of the old inbox, grouped so the boss reads one line, not ninety ----- */
function b2_note_kinds() { return ['digest', 'note', 'changed', 'collision']; }
function b2_note_label($k) { $m = ['digest' => 'daily digests', 'note' => 'notes', 'changed' => '“New module” changes', 'collision' => 'duplicate duties']; return $m[$k] ?? $k; }
function b2_notes(PDO $pdo, array $w) {
    $kinds = b2_note_kinds(); $out = []; $total = 0;
    try {
        $st = $pdo->query("SELECT kind, COUNT(*) c FROM bd_inbox WHERE status = 'open' AND kind IN ('" . implode("','", $kinds) . "') AND " . bd_scope_sql($w) . " GROUP BY kind");
        foreach ($st->fetchAll() as $r) { $out[] = ['kind' => $r['kind'], 'label' => b2_note_label($r['kind']), 'count' => (int)$r['c']]; $total += (int)$r['c']; }
    } catch (Throwable $e) { return ['total' => 0, 'groups' => []]; }
    return ['total' => $total, 'groups' => $out];
}
function b2_notes_clear(PDO $pdo, array $w, $by) {
    $kinds = b2_note_kinds();
    try { $st = $pdo->prepare("UPDATE bd_inbox SET status = 'dismissed', resolved_at = ?, resolved_by = ? WHERE status = 'open' AND kind IN ('" . implode("','", $kinds) . "') AND " . bd_scope_sql($w)); $st->execute([bd_now(), $by]); $n = $st->rowCount(); }
    catch (Throwable $e) { return [false, 'Could not clear the notes.', 500]; }
    b2_log('notes', 'inbox', 'cleared ' . $n . ' notes', $by); return [true, $n ? $n . ' note' . ($n === 1 ? '' : 's') . ' cleared. They stay in History.' : 'No notes to clear.'];
}

/* ----- go-live: one table, one switch for every section, one switch back ----- */
function b2_golive(PDO $pdo, array $w) {
    $loads = b2_loads($pdo); $cap = b2_cap(); $live = b2_live_sections(); $rows = []; $blockers = []; $warns = [];
    $nums = b2_section_numbers($pdo); $late = 0; foreach ($nums as $n) if ($n['bad']) $late += $n['value'];
    foreach (b2_sections($pdo) as $code => $s) {
        $q = $pdo->prepare("SELECT id, title, seat_id FROM b2_items WHERE section_code = ? AND status = 'active'"); $q->execute([$code]); $items = $q->fetchAll();
        $holes = []; $owners = [];
        foreach ($items as $it) {
            $people = $it['seat_id'] ? b2_seat_people($pdo, (int)$it['seat_id']) : [];
            if (!$people) $holes[] = $it['title']; else foreach ($people as $p) $owners[$p['name']] = 1;
        }
        $top = null; foreach ($loads as $l) if (in_array($code, $l['section_codes'], true) && ($top === null || $l['n'] > $top['n'])) $top = $l;
        $b = $pdo->prepare("SELECT COUNT(*) FROM b2_builds WHERE section_code = ? AND status IN ('spec','building')"); $b->execute([$code]); $wait = (int)$b->fetchColumn();
        $d = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE section_code = ? AND status = 'draft'"); $d->execute([$code]); $drafts = (int)$d->fetchColumn();
        $status = $holes ? 'bad' : ($wait || $drafts || ($top && $top['full']) ? 'warn' : 'ok');
        foreach ($holes as $h) $blockers[] = ['section' => $s['name'], 'code' => $code, 'text' => '“' . b2_short($h, 70) . '” has nobody on its seat.'];
        $rows[] = ['code' => $code, 'name' => $s['name'], 'live' => in_array($code, $live, true), 'tasks' => count($items), 'owners' => $owners ? implode(', ', array_slice(array_keys($owners), 0, 3)) . (count($owners) > 3 ? ' +' . (count($owners) - 3) : '') : ($items ? 'Nobody' : '—'),
            'holes' => count($holes), 'load' => $top ? $top['name'] . ' ' . $top['n'] . '/' . $cap : '—', 'load_full' => $top ? $top['full'] : false, 'waiting' => $wait + $drafts, 'status' => $status];
    }
    $h = b2_health($pdo);
    $glob = [
        ['k' => 'cron', 'ok' => !empty($h['heartbeat_ok']) && $h['heartbeat'] !== '', 'text' => $h['heartbeat'] ? ($h['heartbeat_ok'] ? 'The 5-minute cron is running.' : 'The cron has not run for ' . $h['heartbeat_min'] . ' minutes.') : 'The cron has never run. Set it up first (Setup & health).'],
        ['k' => 'selftest', 'ok' => !empty($h['selftest_ok']), 'text' => !empty($h['selftest_ok']) ? 'Daily self-test passed.' : 'The daily self-test found a problem.'],
        ['k' => 'boss', 'ok' => (int)bd_cfg_fresh('top_boss') > 0, 'text' => (int)bd_cfg_fresh('top_boss') > 0 ? 'A top boss is chosen, so penalties have someone to ask.' : 'No top boss is chosen: penalties would have nobody to ask.'],
    ];
    foreach ($glob as $g) if (!$g['ok']) $blockers[] = ['section' => '', 'code' => '', 'text' => $g['text']];
    if ($late > 0) $warns[] = $late . ' orders and parcels are already late, so the first days start with a backlog. The ' . (int)b2_cfg('fair_days') . ' fair days stop a penalty being asked on it.';
    $full = array_filter($loads, function ($l) { return $l['full']; }); if ($full) $warns[] = implode(', ', array_map(function ($l) { return $l['name']; }, array_slice(array_values($full), 0, 4))) . ' already ' . (count($full) === 1 ? 'has' : 'have') . ' ' . $cap . ' of ' . $cap . ' daily tasks.';
    $liveAll = b2_cfg('mode') === 'on' && count($rows) && count(array_filter($rows, function ($r) { return $r['live']; })) === count($rows);
    return ['sections' => $rows, 'blockers' => $blockers, 'warnings' => $warns, 'global' => $glob, 'can_go' => !$blockers, 'all_live' => $liveAll, 'live_count' => count(array_filter($rows, function ($r) { return $r['live']; })),
        'safety' => ['fair_days' => (int)b2_cfg('fair_days'), 'cap_person' => (float)b2_cfg('cap_person'), 'cap_total' => (float)b2_cfg('cap_total'), 'ask_first' => true, 'cap_daily' => $cap]];
}
function b2_golive_set(PDO $pdo, array $w, $all, $by) {
    if ($all) {
        $g = b2_golive($pdo, $w); if (!$g['can_go']) return [false, 'Not yet: ' . $g['blockers'][0]['text'], 422, ['blockers' => $g['blockers']]];
        b2_set('live_sections', implode(',', array_keys(b2_sections($pdo)))); b2_set('mode', 'on'); b2_log('switch', 'b2', 'ALL sections live', $by);
        return [true, 'Every section is live. Staff see their tasks now.'];
    }
    b2_set('live_sections', ''); b2_log('switch', 'b2', 'ALL sections back to test', $by);
    return [true, 'Every section is back in test mode. Staff do not see tasks and nobody is asked about a penalty.'];
}

/* ----- the Boss panel "Today" extras and the strip every page shows ----- */
function b2_today_plus(PDO $pdo, array $w) {
    $acc = b2_accountability($pdo, $w); $loads = b2_loads($pdo, $w); $notes = b2_notes($pdo, $w);
    $nums = b2_section_numbers($pdo); $late = 0; foreach ($nums as $n) if ($n['bad']) $late += $n['value'];
    $chains = []; $cov = 0; $tot = 0; foreach (b2_chains() as $code => $c) { $v = b2_chain_view($pdo, $code); if ($v) { $chains[$code] = ['covered' => $v['covered'], 'total' => $v['total'], 'next' => $v['next'] ? $v['steps'][array_search($v['next'], array_column($v['steps'], 'key'))]['title'] : '']; $cov += $v['covered']; $tot += $v['total']; } }
    $g = b2_golive($pdo, $w); $mode = !$g['all_live'] ? ($g['live_count'] ? 'some' : 'test') : 'live';
    return ['accountability' => $acc['rows'], 'kept' => $acc['people'], 'loads' => $loads, 'cap' => b2_cap(), 'notes' => $notes, 'numbers' => $nums, 'late_total' => $late,
        'chains' => $chains, 'coverage' => ['covered' => $cov, 'total' => $tot], 'golive' => ['mode' => $mode, 'live_count' => $g['live_count'], 'blockers' => count($g['blockers']), 'first_blocker' => $g['blockers'] ? $g['blockers'][0]['text'] : '', 'all' => count($g['sections'])]];
}
function b2_strip(PDO $pdo, array $w, $page, $emp) {
    $k = b2_page_key($page); $ct = b2_page_contract($pdo, $k); $sec = $ct && $ct['section'] ? $ct['section'] : b2_page_section($pdo, $k);
    if ($sec === '' || !isset(b2_sections($pdo)[$sec])) return ['show' => false];
    $o = ['show' => true, 'page' => $k, 'section_code' => $sec, 'section' => b2_section_name($pdo, $sec), 'live' => b2_section_live($sec), 'manager' => (bool)($w['manager'] && b2_can_section($w, $sec, $pdo))];
    if ($o['manager']) {
        $td = b2_today_stats($pdo, $w, $sec); $o['today'] = $td;
        $f = ['section' => $sec, 'status' => 'active', 'limit' => 6]; $cards = b2_items_list($pdo, $w, $f);
        $o['tasks'] = array_map(function ($c) { return ['id' => $c['id'], 'title' => $c['title'], 'owner' => $c['owner_now'] ?: $c['seat'], 'state' => $c['state'], 'state_label' => $c['state_label'], 'strip' => $c['strip'], 'kind_label' => $c['kind_label']]; }, $cards);
        $o['numbers'] = b2_section_numbers($pdo, $sec);
        $pr = $pdo->prepare("SELECT COUNT(*) FROM b2_problems WHERE section_code = ? AND step = 'decide'"); $pr->execute([$sec]); $pe = $pdo->prepare("SELECT COUNT(*) FROM b2_pens WHERE section_code = ? AND state = 'ask'"); $pe->execute([$sec]);
        $o['needs'] = (int)$pr->fetchColumn() + (int)$pe->fetchColumn();
        $o['chain'] = ($v = b2_chain_view($pdo, $sec)) ? ['covered' => $v['covered'], 'total' => $v['total'], 'next' => $v['next'] ? $v['steps'][array_search($v['next'], array_column($v['steps'], 'key'))]['title'] : ''] : null;
        $ld = []; foreach (b2_loads($pdo, $w) as $l) if (in_array($sec, $l['section_codes'], true)) $ld[] = ['name' => $l['name'], 'n' => $l['n'], 'cap' => $l['cap'], 'full' => $l['full']];
        $o['loads'] = array_slice($ld, 0, 6);
        return $o;
    }
    // staff: only their own tasks here, nothing about anybody else
    $mine = []; if ($emp) {
        $q = $pdo->prepare("SELECT i.* FROM b2_items i WHERE i.status = 'active' AND i.section_code = ? AND i.seat_id IN (SELECT seat_id FROM bd_holders WHERE emp_id = ?) ORDER BY i.id LIMIT 6"); $q->execute([$sec, (int)$emp]);
        foreach (b2_items_view($pdo, $q->fetchAll()) as $c) $mine[] = ['id' => $c['id'], 'title' => $c['title'], 'state' => $c['state'], 'state_label' => $c['state_label'], 'strip' => $c['strip'], 'due_label' => $c['due_label'], 'today' => $c['today'] ? $c['today']['status'] : '']; }
    $o['tasks'] = $mine; if (!$mine) $o['show'] = false; return $o;
}

/* ============================== moving in from the old Boss Engine (nothing is deleted; old rows are archived) ============================== */
function b2_table_ok(PDO $pdo, $t) { try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); return true; } catch (Throwable $e) { return false; } }
function b2_have_ref(PDO $pdo, $table, $ref) { $st = $pdo->prepare("SELECT id FROM $table WHERE old_ref = ? LIMIT 1"); $st->execute([$ref]); return (int)$st->fetchColumn(); }
/** every old duty that is not owned by the Offices page becomes a Daily / Number / Temporary / Short task; the old duty is retired */
function b2_adopt_duties(PDO $pdo) {
    $n = 0; $liveSecs = [];
    if (!b2_table_ok($pdo, 'bd_duties')) return 0;
    $st = $pdo->query("SELECT d.*, s.code AS sec_code FROM bd_duties d LEFT JOIN bd_sections s ON s.id = d.section_id WHERE d.status IN ('live','paused','proposed') AND d.source != 'office' ORDER BY d.id");
    foreach ($st->fetchAll() as $d) {
        $ref = 'bd:duty:' . $d['id']; if (b2_have_ref($pdo, 'b2_items', $ref)) { $pdo->prepare("UPDATE bd_duties SET status = 'retired' WHERE id = ? AND status != 'retired'")->execute([$d['id']]); continue; }
        $today = b2_day(); $in = ['title' => $d['title'], 'why' => (string)$d['why'], 'section_code' => (string)$d['sec_code'], 'seat_id' => (int)$d['seat_id'], 'checker_seat_id' => (int)$d['checker_seat_id'], 'due_time' => (string)$d['due_time'], 'grace_min' => (int)$d['grace_min'], 'photo_prompt' => (string)$d['photo_prompt'], 'pen_amount' => min(500, (float)$d['pen_amount']), 'pen_note' => (string)$d['pen_remark'], 'allow_unowned' => 1, 'start_date' => $today];
        $proof = (string)$d['proof'];
        if ($d['freq'] === 'once') { $in['kind'] = 'short'; $in['due_date'] = $d['on_date'] ?: $today; }
        elseif ($d['season_from'] && $d['season_to']) { $in['kind'] = 'temp'; $in['start_date'] = max($today, $d['season_from']); $in['end_date'] = $d['season_to']; }
        else { $in['kind'] = 'daily'; if ($d['freq'] === 'weekly') $in['dow'] = $d['dow'] ?: '1'; elseif ($d['freq'] === 'monthly') { $in['dom'] = (int)($d['dom'] ?: 1); } else $in['dow'] = $d['dow'] ?: '1,2,3,4,5,6'; }
        if ($proof === 'count') { $in['kind'] = $d['freq'] === 'once' ? 'short' : 'number'; $in['target'] = $d['target_qty']; $in['cmp'] = $d['cmp']; $in['unit'] = (string)$d['unit']; $in['proof'] = 'number'; if ($in['kind'] === 'short') $in['proof'] = 'text'; }
        elseif ($proof === 'auto') { $in['proof'] = 'auto'; $in['adapter'] = (string)$d['adapter']; $in['target'] = $d['target_qty'] ?: 1; }
        elseif ($proof === 'photo') $in['proof'] = 'photo'; else $in['proof'] = 'tick';
        list($c, $err) = b2_item_clean($pdo, $in);
        if (!$c) { b2_log('migrate', $ref, 'could not move: ' . $err); continue; }
        $status = $d['status'] === 'live' ? 'active' : ($d['status'] === 'paused' ? 'paused' : 'draft');
        $id = b2_item_insert($pdo, $c, $status, 'migrated', 'Old Boss Engine');
        $pdo->prepare("UPDATE b2_items SET old_ref = ? WHERE id = ?")->execute([$ref, $id]);
        $pdo->prepare("UPDATE bd_duties SET status = 'retired' WHERE id = ?")->execute([$d['id']]);
        if ($status === 'active' && $d['sec_code']) $liveSecs[$d['sec_code']] = 1;
        $n++;
    }
    if ($liveSecs && bd_mode() !== 'shadow') { $cur = b2_live_sections(); foreach (array_keys($liveSecs) as $c) if (!in_array($c, $cur, true)) $cur[] = $c; b2_set('live_sections', implode(',', $cur)); }
    return $n;
}
function b2_migrate_cases(PDO $pdo) {
    if (!b2_table_ok($pdo, 'be_cases')) return 0; $n = 0;
    foreach ($pdo->query("SELECT * FROM be_cases ORDER BY id")->fetchAll() as $c) {
        $ref = 'be:case:' . $c['id']; if (b2_have_ref($pdo, 'b2_problems', $ref)) continue;
        $active = $c['status'] === 'active';
        $step = $active ? 'watch' : 'closed';
        $reason = $active ? null : 'Archived from the old Boss Engine (it was “' . $c['status'] . '”). Report it again if it is still a problem.';
        $pdo->prepare("INSERT INTO b2_problems (title, body, by_emp, by_name, section_code, src, sev, step, closed_reason, watch_from, watch_until, old_ref, created_at, updated_at, closed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([mb_substr($c['title'], 0, 200), $c['body'], $c['raised_emp'] ?: null, $c['raised_by'], $c['section_code'] ?: null, 'old', in_array($c['sev'], ['low', 'med', 'high'], true) ? $c['sev'] : 'med', $step, $reason, $active ? substr($c['created_at'], 0, 10) : null, $active ? ($c['watch_until'] ?: date('Y-m-d', strtotime('+' . (int)b2_cfg('watch_days') . ' days'))) : null, $ref, $c['created_at'], bd_now(), $active ? null : bd_now()]);
        $n++;
    }
    return $n;
}
function b2_migrate_old_tasks(PDO $pdo) {
    if (!b2_table_ok($pdo, 'be_tasks')) return 0; $n = 0; $secs = [];
    foreach ($pdo->query("SELECT * FROM be_tasks WHERE status IN ('open','review') AND (doc_id IS NULL OR doc_id = 0) ORDER BY id")->fetchAll() as $t) {
        $ref = 'be:task:' . $t['id']; if (b2_have_ref($pdo, 'b2_items', $ref)) continue;
        $in = ['kind' => 'short', 'title' => $t['title'], 'why' => (string)$t['detail'], 'section_code' => (string)$t['section_code'], 'due_date' => substr($t['due_at'], 0, 10), 'due_time' => substr($t['due_at'], 11, 5), 'proof' => $t['proof'] === 'photo' ? 'photo' : 'tick', 'pen_amount' => min(500, (float)$t['pen_amount']), 'progress_every' => (int)$t['progress_every'], 'allow_unowned' => 1];
        if ($t['owner_emp']) $in['emp_id'] = (int)$t['owner_emp']; if ($t['checker_emp']) $in['checker_emp_id'] = (int)$t['checker_emp'];
        list($c, $err) = b2_item_clean($pdo, $in); if (!$c) continue;
        $id = b2_item_insert($pdo, $c, 'active', 'migrated', 'Old Boss Engine'); $pdo->prepare("UPDATE b2_items SET old_ref = ?, pct = ? WHERE id = ?")->execute([$ref, (int)$t['pct'], $id]);
        $pdo->prepare("UPDATE be_tasks SET status = 'moved' WHERE id = ? AND status IN ('open','review')")->execute([$t['id']]);
        if ($t['section_code']) $secs[$t['section_code']] = 1; $n++;
    }
    if ($secs && bd_mode() !== 'shadow') { $cur = b2_live_sections(); foreach (array_keys($secs) as $c) if (!in_array($c, $cur, true) && isset(b2_sections($pdo)[$c])) $cur[] = $c; b2_set('live_sections', implode(',', $cur)); }
    return $n;
}
function b2_migrate_docs(PDO $pdo) {
    if (!b2_table_ok($pdo, 'be_docs')) return 0; $n = 0;
    foreach ($pdo->query("SELECT * FROM be_docs WHERE status = 'active' ORDER BY id")->fetchAll() as $d) {
        $ref = 'be:doc:' . $d['id']; if (b2_have_ref($pdo, 'b2_docs', $ref)) continue;
        $seat = $d['owner_emp'] ? b2_person_seat($pdo, (int)$d['owner_emp']) : null;
        $pdo->prepare("INSERT INTO b2_docs (title, kind, section_code, seat_id, party, expires_on, ladder, notes, link, status, old_ref, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([mb_substr($d['title'], 0, 160), $d['kind'], $d['section_code'] ?: null, $seat, $d['party'], $d['expires_on'], $d['ladder'] ?: null, $d['notes'], $d['link'], 'active', $ref, 'Old Boss Engine', bd_now()]);
        $n++;
    }
    return $n;
}
function b2_migrate_tk(PDO $pdo) {
    $n = 0;
    if (b2_table_ok($pdo, 'dp_tk_tasks')) {
        foreach ($pdo->query("SELECT * FROM dp_tk_tasks WHERE status = 'open' ORDER BY id")->fetchAll() as $t) {
            $ref = 'tk:task:' . $t['id']; if (b2_have_ref($pdo, 'b2_items', $ref)) continue;
            $in = ['kind' => 'short', 'title' => $t['title'], 'why' => (string)$t['detail'], 'due_date' => $t['due_at'] ? substr($t['due_at'], 0, 10) : b2_day(), 'due_time' => $t['due_at'] ? substr($t['due_at'], 11, 5) : '19:00', 'proof' => in_array($t['proof'], ['photo'], true) ? 'photo' : 'tick', 'allow_unowned' => 1, 'section_code' => ''];
            if ($t['owner_emp']) $in['emp_id'] = (int)$t['owner_emp'];
            list($c, $err) = b2_item_clean($pdo, $in); if (!$c) continue;
            $id = b2_item_insert($pdo, $c, 'active', 'migrated', 'Old Tasks'); $pdo->prepare("UPDATE b2_items SET old_ref = ? WHERE id = ?")->execute([$ref, $id]); $n++;
        }
    }
    if (b2_table_ok($pdo, 'dp_tk_builds')) {
        foreach ($pdo->query("SELECT * FROM dp_tk_builds ORDER BY id")->fetchAll() as $b) {
            $seed = 'tk' . $b['id']; $st = $pdo->prepare("SELECT id FROM b2_builds WHERE seed_key = ?"); $st->execute([$seed]); if ($st->fetchColumn()) continue;
            $status = $b['status'] === 'done' || $b['status'] === 'live' ? 'live' : 'spec';
            $pdo->prepare("INSERT INTO b2_builds (title, spec, why, status, seed_key, created_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([mb_substr($b['title'], 0, 200), (string)$b['prompt'], mb_substr((string)$b['understood'], 0, 480), $status, $seed, $b['created_by'] ?: 'Old Tasks', $b['created_at']]); $n++;
        }
    }
    return $n;
}
function b2_first_install(PDO $pdo) {
    $r = [];
    try { $r['duties'] = b2_adopt_duties($pdo); } catch (Throwable $e) { error_log('b2 duties: ' . $e->getMessage()); }
    try { $r['cases'] = b2_migrate_cases($pdo); } catch (Throwable $e) { error_log('b2 cases: ' . $e->getMessage()); }
    try { $r['tasks'] = b2_migrate_old_tasks($pdo); } catch (Throwable $e) { error_log('b2 tasks: ' . $e->getMessage()); }
    try { $r['docs'] = b2_migrate_docs($pdo); } catch (Throwable $e) { error_log('b2 docs: ' . $e->getMessage()); }
    try { $r['tk'] = b2_migrate_tk($pdo); } catch (Throwable $e) { error_log('b2 tk: ' . $e->getMessage()); }
    b2_state_put($pdo, 'first_install', bd_json($r));
    b2_log('install', 'b2', 'Boss Engine 2 installed: ' . bd_json($r));
    return $r;
}

/* ============================== Commission 2: pricing ML by ML, offers, results, 10-day recount ==============================
 * A separate, PREVIEW-ONLY engine. It reads the orders through the HRMS functions (coach_sales_load, sales_target_fn) and works out
 * what each salesperson would earn with the new rules. It never pays, charges or changes anything the old engine shows.
 *   - Price → % : the order's price (before the prepaid 2% discount: that discount is the company's cost) goes to the NEAREST price column
 *     of its ML row. At or above the midpoint of two column prices = the higher %. Below the last column = the floor %.
 *   - An OFFER (ML + price + %, set by hand, with dates) wins over the chart: an order of that ML within +-window rupees of the offer
 *     price pays the offer's flat %.  No offer matches = the chart.
 *   - Code rule: when the day's code share reaches codepct, every order pays full; otherwise an order without a code pays nocode% of its commission.
 *   - Over target: +30% sales -> +30% extra on the chart commission, +40% -> +40%, +60% -> +50%; never above bonus_max. Offer orders are flat (no extra).
 *   - The commission base of a prepaid order is its price before the prepaid discount.
 */
/* Seeding from the old chart: old columns 7/6.5/6/4/3.5/3 -> new 7/6.5/6/5/4/3. The 5% price is NOT invented (left blank); the old 3.5% column is dropped. */
function cm_default_chart(PDO $pdo) {
    $rows = [];
    if (function_exists('ml_slab_get')) { try { $o = ml_slab_get($pdo); foreach ((array)($o['rows'] ?? []) as $r) { $p = array_pad(array_map('floatval', (array)$r['p']), 6, 0); $rows[] = ['ml' => (int)$r['ml'], 'p' => [$p[0], $p[1], $p[2], 0, $p[3], $p[5]]]; } } catch (Throwable $e) {} }
    return ['v' => 1, 'cols' => [7, 6.5, 6, 5, 4, 3], 'rows' => $rows, 'floor' => 2.8, 'codepct' => 70, 'nocode' => 50, 'prepaid' => 2, 'window' => 20,
        'tiers' => [[30, 30], [40, 40], [60, 50]], 'bonus_max' => 50, 'reviewed' => 0, 'seeded' => count($rows) ? 1 : 0, 'updated_at' => '', 'updated_by' => ''];
}
function cm_chart(PDO $pdo) {
    $d = cm_default_chart($pdo);
    $s = function_exists('setting_get') ? json_decode((string)setting_get($pdo, 'cm_chart', ''), true) : null;
    $c = (!is_array($s) || empty($s['rows'])) ? $d : array_merge($d, $s);      // the commission RULES (code limit, no-code share, prepaid, over-target steps, floor) live here
    // the PRICES live only in the Price Chart (Learn & Setup → Pricing): once it is unified, the engine reads its columns and rows from there, there is no second price table
    if (function_exists('pc_perfume') && function_exists('setting_get') && (string)setting_get($pdo, 'price_unify_v1', '') === '1') { $pc = pc_perfume($pdo); if (!empty($pc['rows'])) { $c['cols'] = array_map('floatval', $pc['cols']); $c['rows'] = $pc['rows']; } }
    return $c;
}
/** the chart sent from the Pricing tab -> [clean chart | null, error] */
function cm_chart_clean(array $in, array $cur) {
    $rows = []; $seen = [];
    foreach ((array)($in['rows'] ?? []) as $r) {
        $ml = (int)($r['ml'] ?? 0); if ($ml <= 0 && !array_sum(array_map('floatval', (array)($r['p'] ?? [])))) continue;   // an empty new row is ignored
        if ($ml <= 0 || $ml > 5000) return [null, 'Every row needs an ML between 1 and 5000.'];
        if (isset($seen[$ml])) return [null, $ml . ' ml is in the chart twice.']; $seen[$ml] = 1;
        $p = array_map(function ($v) { return round((float)$v, 2); }, array_slice(array_values((array)($r['p'] ?? [])), 0, 6)); $p = array_pad($p, 6, 0);
        $rows[] = ['ml' => $ml, 'p' => $p];
    }
    if (!$rows) return [null, 'The chart has no ML rows.'];
    usort($rows, function ($a, $b) { return $a['ml'] <=> $b['ml']; });
    $n = function ($k, $lo, $hi, $def) use ($in, $cur) { $v = $in[$k] ?? ($cur[$k] ?? $def); return max($lo, min($hi, round((float)$v, 2))); };
    $tiers = [];
    foreach ((array)($in['tiers'] ?? $cur['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; }
    usort($tiers, function ($a, $b) { return $a[0] <=> $b[0]; });
    $c = $cur; $c['rows'] = $rows; $c['floor'] = $n('floor', 0, 10, 2.8); $c['codepct'] = $n('codepct', 0, 100, 70); $c['nocode'] = $n('nocode', 0, 100, 50); $c['prepaid'] = $n('prepaid', 0, 20, 2);
    $c['bonus_max'] = $n('bonus_max', 1, 100, 50); $c['tiers'] = $tiers ?: [[30, 30], [40, 40], [60, 50]]; $c['window'] = 20; $c['cols'] = [7, 6.5, 6, 5, 4, 3];
    return [$c, ''];
}
/** the six prices of one ML (a size between two rows is worked out between them) or null when the size is outside the chart */
function cm_row_prices(array $ch, $ml) {
    $rows = $ch['rows'] ?? []; $ml = (float)$ml; if (!$rows || $ml <= 0 || $ml < $rows[0]['ml'] || $ml > $rows[count($rows) - 1]['ml']) return null;
    foreach ($rows as $i => $a) {
        if ((float)$a['ml'] === $ml) return array_map('floatval', $a['p']);
        $b = $rows[$i + 1] ?? null;
        if ($b && $ml > $a['ml'] && $ml < $b['ml']) { $f = ($ml - $a['ml']) / ($b['ml'] - $a['ml']); $p = []; foreach ($a['p'] as $j => $v) $p[] = ((float)$v > 0 && (float)$b['p'][$j] > 0) ? (float)$v + ((float)$b['p'][$j] - (float)$v) * $f : 0.0; return $p; }   // never invent a price next to a blank one
    }
    return null;
}
/** the chart % for one order: the price goes to the NEAREST column. $gross = the price before the prepaid discount. null = ML not in the chart. */
function cm_pct(array $ch, $ml, $gross) {
    $p = cm_row_prices($ch, $ml); if ($p === null) return null;
    $cols = array_map('floatval', $ch['cols']); $gross = (float)$gross;
    $pairs = []; foreach ($p as $j => $v) if ($v > 0) $pairs[] = [(float)$v, $cols[$j]];       // blank columns are skipped
    if (!$pairs) return null;                                                                   // a row with no prices is not priced yet
    if ($gross >= $pairs[0][0]) return $pairs[0][1];
    for ($k = 0; $k < count($pairs) - 1; $k++)
        if ($gross >= $pairs[$k + 1][0]) return $gross >= ($pairs[$k][0] + $pairs[$k + 1][0]) / 2 ? $pairs[$k][1] : $pairs[$k + 1][1];
    return (float)($ch['floor'] ?? 0);
}
/** the offer an order falls in: same ML, price within +-window of the offer price, on a day the offer runs (a stopped offer: up to the day it was stopped). null = none. */
function cm_offer_match(array $offers, $ml, $gross, $day, $window = 20) {
    $ml = (int)round((float)$ml); if ($ml <= 0) return null;
    foreach ($offers as $o) {
        if ((int)$o['ml'] !== $ml) continue;
        if (empty($o['active'])) { $sa = substr((string)($o['stopped_at'] ?? ''), 0, 10); if ($sa === '' || $day > $sa) continue; }      // a STOPPED offer still counts for the days it was running (up to the day it was stopped), so past days are not recounted without it
        if ($o['from_date'] && $day < $o['from_date']) continue; if ($o['to_date'] && $day > $o['to_date']) continue;
        if (abs((float)$gross - (float)$o['price']) <= $window + 1e-9) return $o;
    }
    return null;
}
function cm_bonus(array $ch, $sales, $target, array $boost = null) {
    $sales = (float)$sales; $target = (float)$target; if (!($target > 0) || $sales + 1e-9 < $target) return 0.0;
    $over = ($sales / $target - 1) * 100; $b = 0.0;
    $tiers = $boost ? $boost['tiers'] : (array)($ch['tiers'] ?? []);
    foreach ($tiers as $t) if ($over + 1e-6 >= (float)$t[0]) $b = max($b, (float)$t[1]);
    return $boost ? min($b, 200.0) : min($b, (float)($ch['bonus_max'] ?? 50));      // a boost has no 50% cap: its last slab is the cap
}
/** BOOST = a temporary over-target rule: its own steps (e.g. +10% over -> 30% extra ... last slab 70%), for everyone or chosen people,
 *  from a date until 10:00 the next morning after its end date. While it runs it replaces the normal over-target steps for those people. */
function cm_boosts(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_boosts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, tiers TEXT NOT NULL, scope VARCHAR(10) NOT NULL DEFAULT 'all', emp_ids TEXT NULL,
        from_date DATE NOT NULL, to_date DATE NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, stopped_at DATETIME NULL)");
    $rows = $pdo->query("SELECT * FROM cm_boosts ORDER BY id DESC LIMIT 100")->fetchAll();
    foreach ($rows as &$r) { $r['tiers'] = json_decode((string)$r['tiers'], true) ?: []; $r['emp_ids'] = array_map('intval', json_decode((string)$r['emp_ids'], true) ?: []); $r['ends_at'] = date('Y-m-d', strtotime($r['to_date'] . ' +1 day')) . ' 10:00'; } unset($r);
    return $rows;
}
function cm_boost_for(array $boosts, $empId, $day) {
    foreach ($boosts as $b) {
        if (empty($b['active']) || $day < $b['from_date'] || $day > $b['to_date']) continue;
        if ($b['scope'] === 'people' && !in_array((int)$empId, $b['emp_ids'], true)) continue;
        return $b;
    }
    return null;
}
function cm_boost_save(PDO $pdo, array $in, $by) {
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $from = (string)($in['from_date'] ?? ''); $to = (string)($in['to_date'] ?? '');
    $scope = ($in['scope'] ?? 'all') === 'people' ? 'people' : 'all'; $ids = array_values(array_unique(array_map('intval', (array)($in['emp_ids'] ?? []))));
    $tiers = [];
    foreach ((array)($in['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; }
    usort($tiers, function ($a, $b) { return $a[0] <=> $b[0]; });
    if ($name === '') $name = 'Boost';
    if (!$tiers) return [false, 'Add at least one step: sales over target by % → extra commission %.', 422];
    foreach ($tiers as $t) if ($t[1] > 200) return [false, 'Extra commission cannot be above 200%.', 422];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) return [false, 'Enter the start date and an end date that is not before it.', 422];
    if ($scope === 'people' && !$ids) return [false, 'Choose at least one person, or pick “Everyone”.', 422];
    cm_boosts($pdo);
    $pdo->prepare("INSERT INTO cm_boosts (name, tiers, scope, emp_ids, from_date, to_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$name, json_encode($tiers), $scope, json_encode($ids), $from, $to, $by, bd_now()]);
    return [true, 'Boost “' . $name . '” added. It runs ' . $from . ' to ' . $to . ' and closes at 10:00 on ' . date('j M', strtotime($to . ' +1 day')) . '.', 200];
}
function cm_boost_stop(PDO $pdo, $id) { cm_boosts($pdo); $pdo->prepare("UPDATE cm_boosts SET active = 0, stopped_at = ? WHERE id = ?")->execute([bd_now(), (int)$id]); return [true, 'Boost stopped. The normal over-target steps apply again.', 200]; }
/** TARGET BONUS rules (Commission rules → Target bonus): the switched-on ones, oldest first. For the chosen people and dates, a day whose sales reach that day's target
 *  (or target + X% more) pays the step's extra % on the chart commission. It is compared with the normal over-target steps / a boost: the HIGHEST one counts, never both. */
function cm_tb_rules(PDO $pdo) {
    static $r = null; if ($r !== null) return $r; $r = [];
    try {
        if (function_exists('ensure_commission_bonus_table') && !ensure_commission_bonus_table($pdo)) return $r;
        foreach ($pdo->query("SELECT rule_id, label, who, tiers, date_from, date_to, all_sales FROM commission_bonus_rules WHERE enabled = 1 ORDER BY rule_id ASC")->fetchAll() as $x) {
            $t = []; foreach ((array)(json_decode((string)$x['tiers'], true) ?: []) as $s) if (is_array($s) && !empty($s['on'])) $t[] = [(float)($s['over'] ?? 0), (float)($s['bonus'] ?? 0)];
            if (!$t) continue; usort($t, function ($a, $b) { return $a[0] <=> $b[0]; });
            $d = function ($v) { $v = substr((string)$v, 0, 10); return preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/', $v) ? $v : ''; };
            $r[] = ['id' => (int)$x['rule_id'], 'label' => trim((string)$x['label']) !== '' ? trim((string)$x['label']) : 'Target bonus', 'all' => (int)($x['all_sales'] ?? 0) === 1,
                'who' => array_values(array_filter(array_map('intval', explode(',', (string)$x['who'])))), 'from' => $d($x['date_from']), 'to' => $d($x['date_to']), 'tiers' => $t];
        }
    } catch (Throwable $e) { $r = []; }
    return $r;
}
function cm_tb_for(array $rules, $empId, $day) {
    foreach ($rules as $r) {
        if (($r['from'] !== '' && $day < $r['from']) || ($r['to'] !== '' && $day > $r['to'])) continue;
        if (!$r['all'] && !in_array((int)$empId, $r['who'], true)) continue;
        return $r;
    }
    return null;
}
/** the rule's extra % for one day: the highest step reached; 0 below the target or with no target */
function cm_tb_bonus(array $rule, $sales, $target) {
    $sales = (float)$sales; $target = (float)$target; if (!($target > 0) || $sales + 1e-9 < $target) return 0.0;
    $over = ($sales / $target - 1) * 100; $b = 0.0;
    foreach ($rule['tiers'] as $t) if ($over + 1e-6 >= $t[0]) $b = max($b, $t[1]);
    return $b;
}
/** the extra % that counts for one person / day: [%, name of the Target bonus rule when it is the one that won ('' = normal steps or boost), boost row or null] */
function cm_bonus_pick(PDO $pdo, array $ch, array $boosts, $empId, $day, $sales, $target) {
    $bo = cm_boost_for($boosts, $empId, $day); $bonus = cm_bonus($ch, $sales, $target, $bo); $name = '';
    $tb = cm_tb_for(cm_tb_rules($pdo), $empId, $day);
    if ($tb) { $v = cm_tb_bonus($tb, $sales, $target); if ($v > $bonus + 1e-9) { $bonus = $v; $name = $tb['label']; } }
    return [$bonus, $name, $bo];
}
function cm_code_need($n, $pct) { return ($n <= 0 || $pct <= 0) ? 0 : (int)max(1, round($n * $pct / 100)); }

function cm_offers(PDO $pdo, $all = true) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_offers (id INT AUTO_INCREMENT PRIMARY KEY, ml INT NOT NULL, price DECIMAL(10,2) NOT NULL, pct DECIMAL(5,2) NOT NULL, from_date DATE NOT NULL, to_date DATE NULL,
        active TINYINT NOT NULL DEFAULT 1, note VARCHAR(120) NULL, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, stopped_at DATETIME NULL, INDEX (ml, active))");
    $rows = $pdo->query("SELECT * FROM cm_offers ORDER BY id DESC LIMIT 300")->fetchAll();
    return $rows;
}
function cm_offer_save(PDO $pdo, array $in, $by) {
    $ml = (int)($in['ml'] ?? 0); $price = round((float)($in['price'] ?? 0), 2); $pct = round((float)($in['pct'] ?? 0), 2);
    $from = (string)($in['from_date'] ?? ''); $to = (string)($in['to_date'] ?? '');
    if ($ml <= 0 || $ml > 5000) return [false, 'Enter the ML size.', 422];
    if ($price <= 0) return [false, 'Enter the offer price.', 422];
    if ($pct <= 0 || $pct > 15) return [false, 'Enter the commission % (0.1 to 15).', 422];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return [false, 'Enter the start date.', 422];
    if ($to !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from)) return [false, 'The end date cannot be before the start date.', 422];
    cm_offers($pdo);
    $pdo->prepare("INSERT INTO cm_offers (ml, price, pct, from_date, to_date, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$ml, $price, $pct, $from, $to !== '' ? $to : null, mb_substr(trim((string)($in['note'] ?? '')), 0, 120) ?: null, $by, bd_now()]);
    return [true, 'Offer added: ' . $ml . ' ml at ₹' . number_format($price) . ' pays ' . $pct . '% (orders within ±₹20).', 200];
}
function cm_offer_stop(PDO $pdo, $id, $by) {
    cm_offers($pdo); $pdo->prepare("UPDATE cm_offers SET active = 0, stopped_at = ? WHERE id = ?")->execute([bd_now(), (int)$id]);
    return [true, 'Offer stopped.', 200];
}
/** people assigned to Retail wholesale or Store in Total Budgeting: they are paid by that rule (salary + collections % / store chart), so the online commission engine skips them */
function cm_offline_ids(PDO $pdo) {
    if (function_exists('pay_offline_ids')) return pay_offline_ids($pdo);
    $o = [];
    try { $j = json_decode((string)setting_get($pdo, 'budget_cfg', ''), true); foreach ((array)($j['people'] ?? []) as $id => $v) if (is_array($v) && in_array((string)($v['grp'] ?? ''), ['retail', 'store'], true)) $o[(int)$id] = 1; } catch (Throwable $e) {}
    return $o;
}
function cm_emps(PDO $pdo, $day = null) {
    $emps = []; $off = cm_offline_ids($pdo); $dayOff = ($day && function_exists('pay_day_assign')) ? (pay_day_assign($pdo)[$day] ?? []) : [];   // on a day moved to the store, no online commission
    foreach ($pdo->query("SELECT e.employee_id, e.full_name, e.sales_tag, e.basic_salary, e.joining_date, e.designation, d.department_name
        FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex'")->fetchAll() as $e) {
        if (stripos((string)$e['department_name'], 'sales') === false || ($day && function_exists('pay_off_at') ? pay_off_at($pdo, (int)$e['employee_id'], (string)$day) : isset($off[(int)$e['employee_id']])) || isset($dayOff[(int)$e['employee_id']])) continue; $emps[(int)$e['employee_id']] = $e;
    }
    return $emps;
}
/** WHY a person's target is ₹0 on a day (shown next to "Target ₹0" on the Commission page): with no target there is no target bonus and no over-target extra, so the reason must be visible. */
function cm_target_why(PDO $pdo, $id, $day, array $marks, array $emp) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM daily_targets WHERE target_date = ? AND employee_id = ?"); $q->execute([$day, (int)$id]);
        if ((int)$q->fetchColumn() > 0) return 'a target of ₹0 was typed by hand for this day';
    } catch (Throwable $e) {}
    if (($marks[$day . '|' . $id] ?? '') === 'leave') return 'the day is marked Leave and no target was typed for it';
    try { if (function_exists('ct_day_targets') && isset(ct_day_targets($pdo, (string)$day)[(int)$id])) return 'the Orders page has no target for this day (not punched in, on leave, or switched to not working)'; } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM targets WHERE month = ? AND employee_id = ? AND manual_target IS NOT NULL"); $q->execute([substr((string)$day, 0, 7), (int)$id]);
        if ((int)$q->fetchColumn() > 0) return 'the month target typed by hand is ₹0';
    } catch (Throwable $e) {}
    return 'no target is set for this person (no day target, no month target, and no salary / ladder target)';
}
/** one business day: per person target, sales, and the commission by the new rules. Returns ['rows' => [...], 'error' => ''] */
function cm_day(PDO $pdo, $day, array $ch = null, array $offers = null, array $boosts = null) {
    $ch = $ch ?: cm_chart($pdo); if ($offers === null) $offers = cm_offers($pdo); if ($boosts === null) $boosts = cm_boosts($pdo);
    foreach (['coach_sales_load', 'sales_target_fn', 'day_marks_between'] as $f) if (!function_exists($f)) return ['rows' => [], 'error' => 'The HRMS order functions were not found (' . $f . ').'];
    try {
        $emps = cm_emps($pdo, $day); if (!$emps) return ['rows' => [], 'error' => ''];
        $marks = day_marks_between($pdo, $day, $day); $targetOf = sales_target_fn($pdo, $emps, $day, $day, $marks);
        $sale = coach_sales_load($pdo, $emps, $day, $day);
    } catch (Throwable $e) { return ['rows' => [], 'error' => 'Could not read the orders: ' . $e->getMessage()]; }
    // ONE target everywhere: the day's target is the one the Orders / Targets page shows (ct_day_targets: typed by hand, month target, the automatic rule, the ladder,
    // the profile target; Absent / Leave / not working = 0). It used to come from a simpler rule that does not know the automatic target, so a person with an automatic
    // ₹25,000 on the Orders page had ₹0 here and got no target bonus. A person the Orders page has no row for keeps the simpler rule.
    $ordT = []; if (function_exists('ct_day_targets')) { try { $ordT = ct_day_targets($pdo, (string)$day); } catch (Throwable $e) { $ordT = []; } }
    $pre = (float)$ch['prepaid']; $rows = [];
    foreach ($emps as $id => $e) {
        $list = $sale[$day . '|' . $id] ?? []; $target = isset($ordT[(int)$id]) ? (float)$ordT[(int)$id] : (float)$targetOf($id, $day);
        $n = count($list); $sales = 0.0; $nCode = 0; $noMl = 0; $noPrice = 0; $chartCom = 0.0; $offerCom = 0.0; $xcomTotal = 0.0; $lines = [];
        foreach ($list as $o) { if ($o['code'] !== '') $nCode++; }
        $need = cm_code_need($n, (float)$ch['codepct']); $codeOk = $need > 0 && $nCode >= $need; $nocodeF = (float)$ch['nocode'] / 100;
        foreach ($list as $o) {
            $ok = preg_replace('/\|/', ':', (string)($o['key'] ?? ''), 1);      // the same order key the pages use: store:id
            $amt = (float)$o['amt']; $sales += $amt;
            $gross = (!empty($o['pre']) && $pre > 0) ? $amt / (1 - $pre / 100) : $amt;           // the prepaid discount is the company's cost
            $f = ($o['code'] !== '' || $codeOk) ? 1.0 : $nocodeF; $ml = $o['ml'] ?? null;
            // shower gel / attar / others / combos are priced on their own price charts (Learn & Setup → Pricing, shared): a combo prices the WHOLE order (its price already includes the perfume),
            // otherwise that part is paid on top of the perfume commission. The code rule applies to it like to every order.
            $xc = (float)($o['xcom'] ?? 0) * $f; $xcomTotal += $xc;
            if (!empty($o['xall'])) { $lines[] = ['order' => $o['name'], 'ml' => (int)($ml ?: 0), 'price' => round($gross), 'rule' => 'combo', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => round($xc, 2), 'key' => $ok]; continue; }
            $of = $ml ? cm_offer_match($offers, $ml, $gross, $day, (float)$ch['window']) : null;
            if ($of) { $v = $gross * (float)$of['pct'] / 100 * $f; $offerCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'offer #' . $of['id'], 'pct' => (float)$of['pct'], 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2)]; continue; }
            if (!$ml) { if ($xc <= 0) $noMl++; $lines[] = ['order' => $o['name'], 'ml' => 0, 'price' => round($gross), 'rule' => $xc > 0 ? 'gel / attar / others' : 'no ML', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => round($xc, 2), 'key' => $ok]; continue; }
            $pc = cm_pct($ch, $ml, $gross);
            if ($pc === null) { $noPrice++; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'ML not priced', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => 0, 'key' => $ok, 'x' => round($xc, 2)]; continue; }
            $v = $gross * $pc / 100 * $f; $chartCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'chart', 'pct' => $pc, 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2)];
        }
        [$bonus, $tbName, $bo] = cm_bonus_pick($pdo, $ch, $boosts, $id, $day, $sales, $target); $extra = $chartCom * $bonus / 100;
        // what each order PAYS: its chart commission with the day's extra %, plus its gel / attar / others part. The orders of the day add up to the day's total.
        foreach ($lines as &$ln) { $ln['paid'] = round(($ln['rule'] === 'chart' ? (float)$ln['com'] * (1 + $bonus / 100) : (float)$ln['com']) + (float)($ln['x'] ?? 0), 2); } unset($ln);
        $old = null;
        if (function_exists('coach_day')) { try { $cd = coach_day($pdo, $day, false, true); $old = isset($cd[$id]) ? (float)$cd[$id]['com_today'] : null; } catch (Throwable $e) {} }
        $total = round($chartCom + $offerCom + $extra + $xcomTotal, 2);
        $rows[] = ['id' => $id, 'name' => trim((string)$e['full_name']), 'target' => round($target), 'target_why' => (!($target > 0) && $n > 0) ? cm_target_why($pdo, $id, $day, $marks, $e) : '', 'sales' => round($sales), 'pct' => $target > 0 ? round($sales / $target * 100, 1) : null, 'orders' => $n,
            'codes' => $nCode, 'code_pct' => $n ? round($nCode / $n * 100) : null, 'code_ok' => $codeOk, 'code_need' => $need, 'chart_com' => round($chartCom, 2), 'offer_com' => round($offerCom, 2), 'x_com' => round($xcomTotal, 2),
            'bonus' => $bonus, 'bonus_rule' => $tbName, 'boost' => $bo ? $bo['name'] : '', 'extra' => round($extra, 2), 'total' => $total, 'old' => $old, 'diff' => $old === null ? null : round($total - $old, 2), 'no_ml' => $noMl, 'not_priced' => $noPrice, 'lines' => $lines];
    }
    usort($rows, function ($a, $b) { return $b['sales'] <=> $a['sales']; });
    return ['rows' => $rows, 'error' => ''];
}
/** ONE figure for every page. What a day PAYS, per person and per order, by the live engine: the same number the Commission page shows. A day with an order the engine
 *  could not price (no ML, size not in the chart) shows ₹0 for that order, exactly as on the Commission page.
 *  Kept for a short while (today / yesterday 2 minutes, older days 30 minutes; a change of the rules is seen at once) so a month does not count 30 days on every page load.
 *  Returns ['emps' => [id => ['total' => ₹, 'live' => bool]], 'ord' => [order key => [₹, how it was counted]]] */
function cm_paid_day(PDO $pdo, $day) {
    static $mem = [], $ver = null; if (isset($mem[$day])) return $mem[$day];
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    if ($ver === null) {
        $p = [(string)setting_get($pdo, 'cm_chart', ''), (string)setting_get($pdo, 'price_unify_v1', '')];
        foreach (["SELECT COUNT(*), COALESCE(MAX(updated_at), ''), COALESCE(SUM(enabled), 0) FROM commission_bonus_rules", "SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(active), 0) FROM cm_boosts", "SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(active), 0) FROM cm_offers", "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM daily_targets WHERE target_date >= '" . date('Y-m-d', strtotime('-62 days')) . "'"] as $q) {
            try { $p[] = implode(',', (array)$pdo->query($q)->fetch(PDO::FETCH_NUM)); } catch (Throwable $e) { $p[] = '-'; }
        }
        $ver = substr(md5(implode('|', $p)), 0, 12);
    }
    $ttl = $day >= date('Y-m-d', strtotime($today . ' -1 day')) ? 120 : 1800;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cm_paid_cache (day DATE NOT NULL PRIMARY KEY, ver VARCHAR(16) NOT NULL, at INT NOT NULL, data MEDIUMTEXT NOT NULL)");
        $q = $pdo->prepare("SELECT ver, at, data FROM cm_paid_cache WHERE day = ?"); $q->execute([$day]); $c = $q->fetch();
        if ($c && (string)$c['ver'] === $ver && (int)$c['at'] > time() - $ttl) { $d = json_decode((string)$c['data'], true); if (is_array($d)) return $mem[$day] = $d; }
    } catch (Throwable $e) {}
    $r = cm_day($pdo, $day); if (!empty($r['error'])) return ['emps' => [], 'ord' => [], 'error' => $r['error']];
    $out = ['emps' => [], 'ord' => []];
    foreach ((array)$r['rows'] as $x) {
        $gap = (int)$x['no_ml'] + (int)$x['not_priced'] > 0;      // an order the chart cannot price pays ₹0 (it is listed under Needs fixing on the Commission page)
        $out['emps'][(int)$x['id']] = ['total' => round((float)$x['total'], 2), 'live' => !$gap];
        foreach ((array)$x['lines'] as $l) {
            if ((string)($l['key'] ?? '') === '') continue;
            $why = $l['rule'] === 'chart' ? 'Chart ' . $l['pct'] . '%' . ($x['bonus'] > 0 ? ' + ' . ($x['bonus_rule'] !== '' ? $x['bonus_rule'] : ($x['boost'] !== '' ? $x['boost'] : 'over-target extra')) . ' +' . (0 + $x['bonus']) . '%' : '')
                : (strpos((string)$l['rule'], 'offer') === 0 ? 'Offer ' . $l['pct'] . '%' : ucfirst((string)$l['rule']));
            if ((float)($l['x'] ?? 0) > 0) $why .= ' + gel / attar / others';
            if (!$l['code'] && !$x['code_ok'] && (float)$l['com'] > 0) $why .= ' · no code: half';
            $out['ord'][(string)$l['key']] = [(float)$l['paid'], $why];
        }
    }
    try { $pdo->prepare("REPLACE INTO cm_paid_cache (day, ver, at, data) VALUES (?, ?, ?, ?)")->execute([$day, $ver, time(), json_encode($out)]); } catch (Throwable $e) {}
    return $mem[$day] = $out;
}
/** the last N days with the new rules next to the old figures. Preview only. */
function cm_recount(PDO $pdo, $days = 10, $end = '') {
    $ch = cm_chart($pdo); $offers = cm_offers($pdo); $boosts = cm_boosts($pdo); $by = []; $err = ''; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$end) && $end <= $today) $today = $end;   // the window ends on this day (a past month for Total Budgeting) instead of today
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day')); $r = cm_day($pdo, $d, $ch, $offers, $boosts); if ($r['error']) { $err = $r['error']; break; }
        foreach ($r['rows'] as $x) {
            $k = $x['id']; if (!isset($by[$k])) $by[$k] = ['id' => $k, 'name' => $x['name'], 'days' => 0, 'met' => 0, 'best_pct' => null, 'best_day' => '', 'new' => 0.0, 'old' => 0.0, 'old_known' => true, 'perday' => []];
            if (!$x['orders'] && !$x['target']) continue;
            $by[$k]['days']++; if ($x['target'] > 0 && $x['sales'] >= $x['target']) $by[$k]['met']++;
            if ($x['pct'] !== null && ($by[$k]['best_pct'] === null || $x['pct'] > $by[$k]['best_pct'])) { $by[$k]['best_pct'] = $x['pct']; $by[$k]['best_day'] = $d; }
            $by[$k]['new'] += $x['total']; if ($x['old'] === null) $by[$k]['old_known'] = false; else $by[$k]['old'] += $x['old'];
            $by[$k]['perday'][] = ['day' => $d, 'target' => $x['target'], 'sales' => $x['sales'], 'pct' => $x['pct'], 'new' => $x['total'], 'old' => $x['old']];
        }
    }
    $o = array_values(array_filter($by, function ($x) { return $x['days'] > 0; }));
    foreach ($o as &$x) { $x['new'] = round($x['new'], 2); $x['old'] = $x['old_known'] ? round($x['old'], 2) : null; $x['diff'] = $x['old'] === null ? null : round($x['new'] - $x['old'], 2); } unset($x);
    usort($o, function ($a, $b) { return $b['new'] <=> $a['new']; });
    return ['people' => $o, 'error' => $err, 'days' => $days, 'from' => date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day')), 'to' => $today];
}
/** what is missing or odd, for the "Needs fixing" box at the top of Result */
function cm_needs(PDO $pdo, array $ch, array $offers, array $dayRes) {
    $n = [];
    if (empty($ch['rows'])) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => 'The Pricing chart has no ML rows. Add them first.'];
    else {
        $empty = []; $bad = []; $gaps = [];
        foreach ($ch['rows'] as $r) {
            $p = array_map('floatval', $r['p']); if ($p[0] <= 0) { $empty[] = $r['ml']; continue; }
            if (in_array(0.0, $p, true)) $gaps[] = $r['ml'];
            $last = 1e9; foreach ($p as $v) { if ($v <= 0) continue; if ($v > $last) { $bad[] = $r['ml']; break; } $last = $v; }
        }
        if ($empty) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => count($empty) . ' ML size' . (count($empty) === 1 ? ' has' : 's have') . ' no prices: ' . implode(', ', array_slice($empty, 0, 8)) . (count($empty) > 8 ? '…' : '') . ' ml. Orders of these sizes earn nothing until priced.'];
        if ($gaps) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => count($gaps) . ' ML size' . (count($gaps) === 1 ? ' has' : 's have') . ' a blank price column (usually the new 5% column): ' . implode(', ', array_slice($gaps, 0, 8)) . (count($gaps) > 8 ? '…' : '') . ' ml. Orders are priced from the columns that are filled, so type the missing prices.'];
        if ($bad) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => 'Prices must fall from the 7% column to the 3% column. Check: ' . implode(', ', array_slice($bad, 0, 8)) . ' ml.'];
        if (empty($ch['reviewed'])) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => 'Check the prices once, especially the 5% and 4% columns (the 5% column is new and starts blank; the 4% column starts from your old 4% price), then press “I checked these prices”.'];
    }
    if ($dayRes['error']) $n[] = ['sev' => 'bad', 'tab' => 'result', 'text' => $dayRes['error']];
    $noT = []; $noMl = 0; $noPr = 0;
    foreach ($dayRes['rows'] as $r) { if ($r['target'] <= 0) $noT[] = $r['name']; $noMl += $r['no_ml']; $noPr += $r['not_priced']; }
    if ($noT) $n[] = ['sev' => 'warn', 'tab' => 'result', 'text' => count($noT) . ' ' . (count($noT) === 1 ? 'person has' : 'people have') . ' no target today: ' . implode(', ', array_slice($noT, 0, 6)) . (count($noT) > 6 ? '…' : '') . '.'];
    if ($noMl) $n[] = ['sev' => 'warn', 'tab' => 'result', 'text' => $noMl . ' order' . ($noMl === 1 ? ' has' : 's have') . ' no ML, so no commission is worked out for ' . ($noMl === 1 ? 'it' : 'them') . '.'];
    if ($noPr) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => $noPr . ' order' . ($noPr === 1 ? ' is' : 's are') . ' of an ML size outside the chart.'];
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    foreach ($offers as $o) if ((int)$o['active'] === 1 && $o['to_date'] && $o['to_date'] < $today && $o['to_date'] >= date('Y-m-d', strtotime($today . ' -3 day'))) $n[] = ['sev' => 'info', 'tab' => 'offers', 'text' => 'The ' . $o['ml'] . ' ml offer at ₹' . number_format((float)$o['price']) . ' ended on ' . date('j M', strtotime($o['to_date'])) . '. Main pricing applies again.'];
    foreach (cm_boosts($pdo) as $b) if ((int)$b['active'] === 1 && $b['to_date'] < $today && $b['to_date'] >= date('Y-m-d', strtotime($today . ' -3 day'))) $n[] = ['sev' => 'info', 'tab' => 'offers', 'text' => 'The boost “' . $b['name'] . '” ended (closed ' . date('j M', strtotime($b['to_date'] . ' +1 day')) . ' 10:00). Normal over-target steps apply again.'];
    return $n;
}

/* ============================== 82  ONE SET OF LIMITS, SHARED WITH DISPATCH ==============================
 * The Dispatch module keeps its two limits in dp_state: dsp_track_hours and dsp_transit_days. A Boss task whose adapter is
 * "trackingNN" / "transitN" IS that limit. Saving or starting such a task writes the limit through dp_dsp_set_limits(), the same
 * function the Parcels page uses, so the two doors can never disagree. The newest change wins. Returns a sentence to show, or ''. */
function b2_sync_dsp_load(PDO $pdo) {
    if (function_exists('dp_dsp_set_limits')) return true;
    global $pdo, $action;   // dept.php only loads the Dispatch module when it can see $pdo and $action (boss.php does the same at top level)
    $f = __DIR__ . '/dept.php'; if (!is_file($f)) return false;
    try { require_once $f; } catch (Throwable $e) { return false; }
    if (!function_exists('dp_dsp_set_limits')) return false;
    try { dp_ensure($pdo); if (function_exists('dp_dsp_ensure')) dp_dsp_ensure($pdo); } catch (Throwable $e) { return false; }
    return true;
}
/** the limit a Boss check key stands for: ['track', 72] / ['transit', 5] / ['pickup', 72] / null */
function b2_sync_key($adapter) {
    if (preg_match('/^tracking([0-9]+)$/', (string)$adapter, $m)) return ['track', (int)$m[1]];
    if (preg_match('/^transit([0-9]+)$/', (string)$adapter, $m)) return ['transit', (int)$m[1]];
    if (preg_match('/^pickup([0-9]+)$/', (string)$adapter, $m)) return ['pickup', (int)$m[1]];
    return null;
}
function b2_sync_dispatch(PDO $pdo, $itemId, $by = '') {
    $it = b2_row($pdo, 'b2_items', (int)$itemId); if (!$it) return '';
    $txt = '';
    if ($it['status'] === 'active' && ($k = b2_sync_key($it['adapter']))) {
        try {
            if (b2_sync_dsp_load($pdo)) {
                $src = 'Boss task #' . (int)$itemId; $r = $k[0] === 'track' ? dp_dsp_set_limits($pdo, $k[1], null, $by, $src) : ($k[0] === 'transit' ? dp_dsp_set_limits($pdo, null, $k[1], $by, $src) : dp_dsp_set_limits($pdo, null, null, $by, $src, $k[1]));
                $txt = $r['changed'] ? $r['text'] : '';
            }
        } catch (Throwable $e) { error_log('Dispatch sync: ' . $e->getMessage()); }
    }
    $notes = b2_links_pass($pdo, (int)$itemId, $by);   // the penalty / grace / time of the old rule books
    return trim($txt . ($notes ? ' ' . implode(' ', $notes) : ''));
}
/** For the read-back while a task is still a draft: what saving it will do to Dispatch. */
function b2_sync_preview(PDO $pdo, $adapter) {
    $k = b2_sync_key($adapter); if (!$k) return '';
    try {
        $cur = (int)$pdo->query("SELECT svalue FROM dp_state WHERE skey = '" . ($k[0] === 'track' ? 'dsp_track_hours' : ($k[0] === 'pickup' ? 'dsp_pick_hours' : 'dsp_transit_days')) . "'")->fetchColumn();
    } catch (Throwable $e) { $cur = 0; }
    if (!$cur) $cur = $k[0] === 'transit' ? 4 : 48;
    $what = $k[0] === 'track' ? 'tracking-ID limit' : ($k[0] === 'pickup' ? 'courier pickup wait' : 'in-transit limit'); $unit = $k[0] === 'transit' ? ' days' : ' hours';
    if ($cur === $k[1]) return 'Parcels & Dispatch already uses ' . $k[1] . $unit . '. Nothing else changes.';
    return 'Saving this also changes Parcels & Dispatch: the ' . $what . ' goes from ' . $cur . $unit . ' to ' . $k[1] . $unit . ' (deadlines, warnings and the penalty rule follow). The newest rule wins.';
}

/** [tracking hours, transit days, courier pickup hours] Dispatch uses right now (48 / 4 / 48 until somebody changes them) */
function b2_sync_now() {
    global $pdo;
    $c = [48, 4, 48];
    try { if ($pdo instanceof PDO) { $st = $pdo->query("SELECT skey, svalue FROM dp_state WHERE skey IN ('dsp_track_hours','dsp_transit_days','dsp_pick_hours')"); foreach ($st->fetchAll() as $r) { if ($r['skey'] === 'dsp_track_hours' && (int)$r['svalue'] >= 12) $c[0] = (int)$r['svalue']; if ($r['skey'] === 'dsp_transit_days' && (int)$r['svalue'] >= 2) $c[1] = (int)$r['svalue']; if ($r['skey'] === 'dsp_pick_hours' && (int)$r['svalue'] >= 12) $c[2] = (int)$r['svalue']; } } } catch (Throwable $e) {}
    return $c;
}

/* ============================== RULE LINKS: a Boss task and an old rule book are ONE rule ==============================
 * Old rule books: dp_rules (Dispatch, money, shops, supplies, punch, custom) and penalty_rules (the HRMS Penalties page: late punch, short hours, no punch out ...).
 * A Boss task and an old rule on the same thing are LINKED. A link mirrors three numbers: penalty amount, grace minutes, time of day.
 * Newest change wins, in both directions, and only ONE of the two ever charges (never twice).
 *   charger 'item': the Boss task charges, the old rule is switched OFF (remembered, put back when the task stops).
 *   charger 'rule': the old rule charges (it creates the items the task watches), the task's own penalty stays at ₹0.
 * The pass runs when a task is saved or started, on every Boss tick, and when an old Dispatch rule is saved. */
function b2_links_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS b2_links (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, book VARCHAR(4) NOT NULL, ref VARCHAR(40) NOT NULL, charger VARCHAR(5) NOT NULL DEFAULT 'item', why VARCHAR(8) NOT NULL DEFAULT 'auto', snap TEXT NULL, prev_active TINYINT NULL, created_at DATETIME NULL, synced_at DATETIME NULL, UNIQUE KEY uq_link (item_id, book, ref)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['b2_links']);
}
/** the old rules a task stands for, from its check: [[book, ref, charger, timeKey|null], ...]. Exact matches only; look-alikes are offered on the Rules in sync page. */
function b2_link_targets(array $it) {
    $a = (string)($it['adapter'] ?? ''); $o = [];
    if (preg_match('/^tracking[0-9]+$/', $a)) $o[] = ['dp', 'tracking_48h', 'item', null];
    elseif (preg_match('/^transit[0-9]+$/', $a)) $o[] = ['dp', 'transit_day4', 'item', null];
    elseif (preg_match('/^pickup[0-9]+$/', $a)) $o[] = ['dp', 'dsp_g3_48h', 'item', null];
    elseif ($a === 'punch') { $o[] = ['dp', 'punch_missed', 'item', null]; $o[] = ['pr', 'late_punch', 'item', null]; }
    elseif (strpos($a, 'dp:') === 0) { $rk = substr($a, 3); $o[] = ['dp', $rk, 'rule', null]; }
    return $o;
}
function b2_rb_get(PDO $pdo, $book, $ref) {
    try {
        if ($book === 'dp') { $st = $pdo->prepare("SELECT rkey, title, amount, grace_min, active, updated_at, per FROM dp_rules WHERE rkey = ?"); $st->execute([$ref]); $r = $st->fetch(); if (!$r) return null;
            return ['name' => $r['title'], 'amount' => (float)$r['amount'], 'grace' => (int)$r['grace_min'], 'active' => (int)$r['active'], 'upd' => (string)$r['updated_at'], 'per' => $r['per']]; }
        if ($book === 'pr') {   // penalty_rules: ref = trigger type; the first rule of that type stands for it
            $st = $pdo->prepare("SELECT rule_id, name, amount, grace_min, active, updated_at FROM penalty_rules WHERE trigger_type = ? ORDER BY rule_id LIMIT 1"); $st->execute([$ref]); $r = $st->fetch(); if (!$r) return null;
            return ['name' => $r['name'], 'amount' => (float)$r['amount'], 'grace' => (int)$r['grace_min'], 'active' => (int)$r['active'], 'upd' => (string)$r['updated_at'], 'per' => 'miss', 'id' => (int)$r['rule_id']]; }
    } catch (Throwable $e) {}
    return null;
}
function b2_rb_set(PDO $pdo, $book, $ref, array $f) {
    $now = bd_now(); $set = []; $par = [];
    foreach (['amount' => 'amount', 'grace' => 'grace_min', 'active' => 'active'] as $k => $col) if (array_key_exists($k, $f)) { $set[] = "$col = ?"; $par[] = $f[$k]; }
    if (!$set) return;
    $set[] = 'updated_at = ?'; $par[] = $now;
    try {
        if ($book === 'dp') { $par[] = $ref; $pdo->prepare("UPDATE dp_rules SET " . implode(', ', $set) . " WHERE rkey = ?")->execute($par); }
        elseif ($book === 'pr') { $par[] = $ref; $pdo->prepare("UPDATE penalty_rules SET " . implode(', ', $set) . " WHERE trigger_type = ?")->execute($par); }
    } catch (Throwable $e) { error_log('rule link: ' . $e->getMessage()); }
}
function b2_rb_time_get(PDO $pdo, $tk) { try { $st = $pdo->prepare("SELECT svalue FROM dp_state WHERE skey = ?"); $st->execute([$tk]); $v = $st->fetchColumn(); return $v === false ? '21:00' : (string)$v; } catch (Throwable $e) { return ''; } }
function b2_rb_time_set(PDO $pdo, $tk, $v) { try { $pdo->prepare("INSERT INTO dp_state (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$tk, (string)$v]); } catch (Throwable $e) {} }
function b2_item_pen_val(array $it) { return round((isset($it['pen_mode']) && $it['pen_mode'] === 'unit') ? (float)$it['pen_unit'] : (float)$it['pen_amount'], 2); }
function b2_link_inr($v) { return '₹' . rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }

/** One pass. $only = one task id (after a save), or null = every link. Returns sentences to show. Never throws. */
function b2_links_pass(PDO $pdo, $only = null, $by = '') {
    $notes = [];
    try {
        b2_links_ensure($pdo); $now = bd_now();
        // 1. make the automatic links for active tasks
        $q = $only ? $pdo->prepare("SELECT * FROM b2_items WHERE id = ? AND status = 'active' AND adapter IS NOT NULL AND adapter <> ''") : $pdo->prepare("SELECT * FROM b2_items WHERE status = 'active' AND adapter IS NOT NULL AND adapter <> '' AND (? IS NULL OR 1 = 1)");
        $only ? $q->execute([(int)$only]) : $q->execute([null]);
        foreach ($q->fetchAll() as $it) foreach (b2_link_targets($it) as $t) {
            if (!b2_rb_get($pdo, $t[0], $t[1])) continue;
            $pdo->prepare("INSERT IGNORE INTO b2_links (item_id, book, ref, charger, why, created_at) VALUES (?,?,?,?, 'auto', ?)")->execute([$it['id'], $t[0], $t[1], $t[2], $now]);
        }
        // 2. reconcile every link (or the links of one task)
        $q = $only ? $pdo->prepare("SELECT * FROM b2_links WHERE item_id = ?") : $pdo->prepare("SELECT * FROM b2_links WHERE ? IS NULL"); $only ? $q->execute([(int)$only]) : $q->execute([null]);
        foreach ($q->fetchAll() as $L) { foreach (b2_link_one($pdo, $L, $now) as $n) $notes[] = $n; }
    } catch (Throwable $e) { error_log('b2_links_pass: ' . $e->getMessage()); }
    return $notes;
}
function b2_link_one(PDO $pdo, array $L, $now) {
    $notes = []; $it = b2_row($pdo, 'b2_items', (int)$L['item_id']); $R = b2_rb_get($pdo, $L['book'], $L['ref']);
    if ($L['why'] === 'off') return $notes;   // unlinked on purpose
    $snap = $L['snap'] ? json_decode($L['snap'], true) : null;
    $tk = null; foreach (b2_link_targets($it ?: []) as $t) if ($t[0] === $L['book'] && $t[1] === $L['ref']) $tk = $t[3];
    if (!$it || !$R) { $pdo->prepare("DELETE FROM b2_links WHERE id = ?")->execute([$L['id']]); return $notes; }
    // the task stopped: give the old rule back
    if ($it['status'] !== 'active') {
        if ($L['prev_active'] !== null && (int)$L['prev_active'] === 1 && (int)$R['active'] === 0) { b2_rb_set($pdo, $L['book'], $L['ref'], ['active' => 1]); $notes[] = 'Old rule “' . $R['name'] . '” is on again (the Boss task stopped).'; }
        $pdo->prepare("DELETE FROM b2_links WHERE id = ?")->execute([$L['id']]); return $notes;
    }
    $iv = ['amount' => b2_item_pen_val($it), 'grace' => (int)$it['grace_min'], 'time' => $tk ? (string)$it['due_time'] : ''];
    $rv = ['amount' => (float)$R['amount'], 'grace' => (int)$R['grace'], 'time' => $tk ? b2_rb_time_get($pdo, $tk) : '', 'active' => (int)$R['active']];
    $dir = 'item';   // who wins
    if ($snap) {
        $iCh = $iv != $snap['i']; $rCh = $rv != $snap['r'];
        if (!$iCh && !$rCh) return $notes;
        if ($iCh && !$rCh) $dir = 'item'; elseif ($rCh && !$iCh) $dir = 'rule';
        else $dir = (string)$R['upd'] > (string)($it['updated_at'] ?: $it['created_at']) ? 'rule' : 'item';
    }
    $chargerItem = $L['charger'] === 'item'; $prevActive = $L['prev_active']; $newCharger = $L['charger'];
    if ($dir === 'item') {
        $f = [];
        if ($iv['amount'] > 0 && abs($iv['amount'] - $rv['amount']) > 0.001) $f['amount'] = $iv['amount'];
        if ($iv['grace'] > 0 && $iv['grace'] !== $rv['grace']) $f['grace'] = $iv['grace'];
        if ($tk && $iv['time'] !== '' && $iv['time'] !== $rv['time']) { b2_rb_time_set($pdo, $tk, $iv['time']); $rv['time'] = $iv['time']; $notes[] = 'The time is now ' . $iv['time'] . ' in the old rule book too.'; }
        if ($chargerItem) {
            if ($iv['amount'] > 0 && (int)$R['active'] === 1) { $f['active'] = 0; $prevActive = 1; $notes[] = 'Old rule “' . $R['name'] . '” (' . b2_link_inr($R['amount']) . ') is switched off: this Boss task charges ' . b2_link_inr($iv['amount']) . ' instead, so nobody pays twice.'; }
            elseif ($iv['amount'] > 0 && isset($f['amount'])) $notes[] = 'Old rule “' . $R['name'] . '” now shows ' . b2_link_inr($iv['amount']) . ' too.';
        } else {   // the old rule is the one that charges: the amount moves to it, the task goes back to ₹0
            if ($iv['amount'] > 0) {
                $pdo->prepare("UPDATE b2_items SET pen_amount = 0, pen_mode = 'flat' WHERE id = ?")->execute([$it['id']]); $iv['amount'] = 0.0;
                $notes[] = 'Rule “' . $R['name'] . '” now charges ' . b2_link_inr(isset($f['amount']) ? $f['amount'] : $R['amount']) . ' (set from this task). The task itself stays at ₹0 so nobody pays twice.';
            }
        }
        if ($f) { b2_rb_set($pdo, $L['book'], $L['ref'], $f); foreach ($f as $k => $v) $rv[$k] = $v; }
    } else {   // the old rule book was changed later: it wins
        $up = [];
        if ($rv['active'] === 1 && $rv['amount'] > 0) {   // the old rule charges now
            if ($iv['amount'] > 0) { $up[] = "pen_amount = 0"; $up[] = "pen_mode = 'flat'"; $iv['amount'] = 0.0; $notes[] = 'Old rule “' . $R['name'] . '” was changed later, so it charges (' . b2_link_inr($rv['amount']) . '). The Boss task’s own penalty went back to ₹0.'; }
            if ($prevActive !== null) $prevActive = null;
            $newCharger = 'rule';   // from now on the old rule is the one that charges; a later change in the task moves the amount to it
        } elseif ($iv['amount'] > 0 && abs($iv['amount'] - $rv['amount']) > 0.001 && $rv['amount'] > 0) {   // only the number changed while the task charges
            $unit = (isset($it['pen_mode']) && $it['pen_mode'] === 'unit');
            $up[] = ($unit ? 'pen_unit' : 'pen_amount') . ' = ' . (float)$rv['amount']; $iv['amount'] = $rv['amount']; $notes[] = 'The Boss task now charges ' . b2_link_inr($rv['amount']) . ' (changed in the old rule book).';
        }
        if ($rv['grace'] !== $iv['grace'] && $rv['grace'] > 0) { $up[] = 'grace_min = ' . (int)$rv['grace']; $iv['grace'] = $rv['grace']; }
        if ($tk && $rv['time'] !== '' && $rv['time'] !== $iv['time']) { $up[] = "due_time = " . $pdo->quote($rv['time']); $iv['time'] = $rv['time']; $notes[] = 'The task time is now ' . $rv['time'] . ' (changed in the old rule book).'; }
        if ($up) $pdo->exec("UPDATE b2_items SET " . implode(', ', $up) . " WHERE id = " . (int)$it['id']);
    }
    $R2 = b2_rb_get($pdo, $L['book'], $L['ref']); if ($R2) { $rv['amount'] = (float)$R2['amount']; $rv['grace'] = (int)$R2['grace']; $rv['active'] = (int)$R2['active']; }
    $pdo->prepare("UPDATE b2_links SET snap = ?, prev_active = ?, charger = ?, synced_at = ? WHERE id = ?")->execute([json_encode(['i' => $iv, 'r' => $rv]), $prevActive, $newCharger, $now, $L['id']]);
    if ($notes) b2_log('rule_link', (string)$it['id'], implode(' ', $notes), 'Rule sync');
    return $notes;
}

/** the "Rules in sync" page: every old rule book, what is linked, and look-alikes waiting for a tap */
function b2_links_view(PDO $pdo) {
    b2_links_ensure($pdo); $o = ['books' => [], 'links' => [], 'lookalikes' => [], 'limits' => []];
    $lim = b2_sync_now(); $o['limits'] = ['tracking_hours' => $lim[0], 'transit_day' => $lim[1], 'pickup_hours' => $lim[2]];
    try { foreach ($pdo->query("SELECT topic, COUNT(*) n, SUM(active) a FROM dp_rules GROUP BY topic ORDER BY topic")->fetchAll() as $r) $o['books'][] = ['name' => 'Department rules: ' . $r['topic'], 'where' => 'Boss > Penalties', 'count' => (int)$r['n'], 'on' => (int)$r['a']]; } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT category, COUNT(*) n, SUM(active) a FROM penalty_rules GROUP BY category ORDER BY category")->fetchAll() as $r) $o['books'][] = ['name' => 'HRMS penalties: ' . $r['category'], 'where' => 'Penalties page', 'count' => (int)$r['n'], 'on' => (int)$r['a']]; } catch (Throwable $e) {}
    foreach ([['Dispatch limits', 'Parcels page > Rules bar', 2], ['Money use time', 'Money page', 1]] as $x) $o['books'][] = ['name' => $x[0], 'where' => $x[1], 'count' => $x[2], 'on' => $x[2]];
    $q = $pdo->query("SELECT l.*, i.title, i.pen_amount, i.pen_unit, i.pen_mode, i.status FROM b2_links l JOIN b2_items i ON i.id = l.item_id WHERE l.why <> 'off' ORDER BY l.id DESC LIMIT 200");
    foreach ($q->fetchAll() as $L) { $R = b2_rb_get($pdo, $L['book'], $L['ref']); if (!$R) continue;
        $o['links'][] = ['id' => (int)$L['id'], 'task' => $L['title'], 'task_id' => (int)$L['item_id'], 'rule' => $R['name'], 'book' => $L['book'] === 'dp' ? 'Department' : 'HRMS penalties', 'why' => $L['why'], 'charger' => $L['charger'] === 'rule' ? ($R['active'] ? 'Old rule' : 'Nobody (old rule is off)') : ($R['active'] ? 'Both are on' : 'Boss task'),
            'task_amount' => b2_item_pen_val($L), 'rule_amount' => $R['amount'], 'rule_on' => (bool)$R['active'], 'grace' => $R['grace']]; }
    // look-alikes: active tasks with a penalty or a check against old rules that are not linked
    $linked = []; foreach ($o['links'] as $l) $linked[$l['task_id'] . '|' . $l['rule']] = 1;
    try {
        $rules = [];
        foreach ($pdo->query("SELECT rkey AS ref, title AS n, amount, active FROM dp_rules WHERE amount > 0")->fetchAll() as $r) $rules[] = ['book' => 'dp', 'ref' => $r['ref'], 'n' => $r['n'], 'amount' => (float)$r['amount'], 'active' => (int)$r['active']];
        foreach ($pdo->query("SELECT trigger_type AS ref, name AS n, amount, active FROM penalty_rules WHERE amount > 0")->fetchAll() as $r) $rules[] = ['book' => 'pr', 'ref' => $r['ref'], 'n' => $r['n'], 'amount' => (float)$r['amount'], 'active' => (int)$r['active']];
        foreach ($pdo->query("SELECT id, title, pen_amount, pen_unit, pen_mode FROM b2_items WHERE status = 'active' ORDER BY id DESC LIMIT 300")->fetchAll() as $it) {
            $m = implode(' ', b2_words($it['title']));
            foreach ($rules as $r) { if (isset($linked[$it['id'] . '|' . $r['n']])) continue; $sc = bd_sim($m, implode(' ', b2_words($r['n']))); if ($sc >= 0.5) $o['lookalikes'][] = ['task_id' => (int)$it['id'], 'task' => $it['title'], 'book' => $r['book'], 'ref' => $r['ref'], 'rule' => $r['n'], 'rule_amount' => $r['amount'], 'rule_on' => (bool)$r['active'], 'task_amount' => b2_item_pen_val($it), 'score' => round($sc, 2)]; }
        }
    } catch (Throwable $e) {}
    $o['lookalikes'] = array_slice($o['lookalikes'], 0, 40);
    return $o;
}

/* ============================== 83  CLAUDE READS FIRST ==============================
 * The sentence goes to Claude together with a picture of the whole HRMS (sections, people, every old rule and its number, the Dispatch limits,
 * the system checks). Claude fills in the task, says what it understood, asks the few things it cannot know, and names what it replaces.
 * The CODE only checks: valid values, caps, one charge, money only after the manager's own words mention a penalty. If Claude is off or does not
 * answer, the built-in word reader runs as the backup and the screen says so. */
function b2_ai_pack(PDO $pdo) {
    $secs = []; foreach (b2_sections($pdo) as $code => $sx) $secs[] = $code . ' = ' . $sx['name'];
    $people = []; foreach (array_slice(bd_employees($pdo), 0, 80) as $e) $people[] = $e['name'];
    $lim = b2_sync_now();
    $rules = [];
    try { foreach ($pdo->query("SELECT rkey, title, amount, per, grace_min, when_text FROM dp_rules WHERE active = 1 ORDER BY sort_no LIMIT 40")->fetchAll() as $r) $rules[] = ['book' => 'department', 'name' => $r['title'], 'amount' => (float)$r['amount'], 'per' => $r['per'], 'grace_min' => (int)$r['grace_min'], 'when' => $r['when_text']]; } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT name, trigger_type, amount, grace_min, category FROM penalty_rules WHERE active = 1 ORDER BY rule_id LIMIT 40")->fetchAll() as $r) $rules[] = ['book' => 'HRMS penalties', 'name' => $r['name'], 'kind' => $r['trigger_type'], 'amount' => (float)$r['amount'], 'grace_min' => (int)$r['grace_min'], 'category' => $r['category']]; } catch (Throwable $e) {}
    $checks = ['tracking' => 'orders with no tracking ID after N hours (set limit_hours; the system counts them, nobody types it)', 'transit' => 'parcels not in transit by day N (set limit_days)', 'pickup' => 'parcels that have a tracking ID but the courier has not scanned them after N hours (set limit_hours)', 'punch' => 'did the owner punch in today'];
    foreach (b2_checks() as $k => $c) if (strpos($k, 'dp:') === 0) $checks[$k] = $c['label'];
    return ['today' => date('l j F Y'), 'sections' => $secs, 'people' => $people, 'dispatch_limits_now' => ['tracking_hours' => $lim[0], 'transit_day' => $lim[1], 'courier_pickup_hours' => $lim[2]], 'old_rules_now' => $rules, 'system_checks' => $checks];
}
function b2_ai_understand(PDO $pdo, $orig, array $ctx, $prev = null, $fix = '') {
    if (!b2_ai_on()) return null;
    $kinds = "Kinds: daily (every working day), number (a number entered every working day, with a screenshot), short (one job, one deadline), temp (daily for N days: give days), followup (chase something), fix (repair / maintenance), buy (purchase, daily spend), improve (make a person or task better). Proof: tick, photo, number, text, screenshot, auto (only with a system check).";
    $shape = 'Return JSON: {"kind":"","title":"short, starts with a verb","why":"","section_code":"one of the codes","owner_name":"","due_time":"HH:MM","due_date":"YYYY-MM-DD","days":0,"dow":"1,2,3","check":"tracking|transit|pickup|punch|dp:...|empty","limit_hours":0,"limit_days":0,"target":0,"cmp":"min|max","unit":"","proof":"",'
        . '"penalty":{"mode":"none|flat|per_item","amount":0,"rate":0,"noun":"parcel","min":0,"max":0,"grace_min":0},'
        . '"understood":["3 to 6 short plain sentences saying what you understood, in the manager\'s words"],'
        . '"questions":[{"id":"hours|days|pen|time","text":"","options":[{"v":"","l":""}]}],"replaces":["names of old rules this changes"]}. '
        . 'Leave out what the sentence does not say. Ask at most 2 questions, only about what is missing, each with 2 to 5 suggested answers (hours: numbers like 72; days: 3 to 7; pen: rupees like 0, 50, 100; time: HH:MM). Never invent a penalty the manager did not mention.';
    $sys = "You are the boss engine of an Indian perfume business HRMS. You read a manager's plain sentence and turn it into ONE task, using the CONTEXT: the sections, the people, every old rule and its current number, and the system checks. If the sentence is about something an old rule or a system check already covers, use that check and say so in 'replaces'. " . $kinds . " " . $shape;
    if ($prev && $fix !== '') {
        $sys .= " The manager is CORRECTING your earlier reading: return ONLY the fields the correction changes, plus a fresh 'understood'.";
        $user = "CONTEXT: " . json_encode(b2_ai_pack($pdo), JSON_UNESCAPED_UNICODE) . "\nORIGINAL SENTENCE: " . $orig . "\nYOUR PREVIOUS READING: " . json_encode(b2_draft_keep($prev), JSON_UNESCAPED_UNICODE) . "\nCORRECTION: " . $fix;
    } else {
        $user = "CONTEXT: " . json_encode(b2_ai_pack($pdo), JSON_UNESCAPED_UNICODE) . "\nSECTION OF THE PAGE: " . ($ctx['section'] ?? 'unknown') . "\nSENTENCE: " . $orig;
    }
    return b2_ai_json($sys, $user, 1500);
}
/** what Claude says goes into the draft; every value is checked. Returns [draft, understood[], questions[]] */
function b2_ai_apply(PDO $pdo, array $d, $ai, $words) {
    $und = []; $qs = [];
    if (!is_array($ai)) return [$d, $und, $qs];
    $secs = b2_sections($pdo); $lw = mb_strtolower((string)$words);
    if (!empty($ai['kind']) && isset(b2_kinds()[$ai['kind']])) $d['kind'] = $ai['kind'];
    if (!empty($ai['title'])) $d['title'] = mb_substr(trim((string)$ai['title']), 0, 110);
    if (!empty($ai['why'])) $d['why'] = mb_substr(trim((string)$ai['why']), 0, 300);
    if (!empty($ai['section_code']) && isset($secs[(string)$ai['section_code']])) $d['section_code'] = (string)$ai['section_code'];
    if (!empty($ai['owner_name'])) { $e = b2_find_person($pdo, (string)$ai['owner_name']); if ($e) { $d['emp_id'] = $e; $d['seat_id'] = 0; } }
    if (!empty($ai['due_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$ai['due_time'])) $d['due_time'] = (string)$ai['due_time'];
    if (!empty($ai['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ai['due_date']) && strtotime((string)$ai['due_date'])) $d['due_date'] = (string)$ai['due_date'];
    if (!empty($ai['dow']) && preg_match('/^[1-7](,[1-7])*$/', (string)$ai['dow'])) $d['dow'] = (string)$ai['dow'];
    if (isset($ai['days']) && is_numeric($ai['days']) && (int)$ai['days'] >= 1 && (int)$ai['days'] <= 90) $d['days'] = (float)(int)$ai['days'];
    foreach (['unit', 'proof'] as $k) if (!empty($ai[$k])) $d[$k] = mb_substr((string)$ai[$k], 0, 30);
    if (isset($ai['target']) && is_numeric($ai['target']) && (float)$ai['target'] > 0) $d['target'] = (float)$ai['target'];
    if (!empty($ai['cmp']) && in_array($ai['cmp'], ['min', 'max'], true)) $d['cmp'] = $ai['cmp'];
    // the system check: only keys that really exist
    $chk = trim((string)($ai['check'] ?? '')); $lim = b2_sync_now(); $key = '';
    if ($chk === 'tracking') { $h = (int)($ai['limit_hours'] ?? 0); if ($h <= 0 && isset($ai['limit_days']) && (int)$ai['limit_days'] > 0) $h = (int)$ai['limit_days'] * 24; $key = b2_check_track_key($h > 0 ? $h : $lim[0]); }
    elseif ($chk === 'transit') { $dd = (int)($ai['limit_days'] ?? 0); if ($dd <= 0 && (int)($ai['limit_hours'] ?? 0) > 0) $dd = (int)round($ai['limit_hours'] / 24); $key = b2_check_transit_key(($dd > 0 ? $dd : $lim[1]) * 24); }
    elseif ($chk === 'pickup') { $h = (int)($ai['limit_hours'] ?? 0); if ($h <= 0 && isset($ai['limit_days']) && (int)$ai['limit_days'] > 0) $h = (int)$ai['limit_days'] * 24; $key = b2_check_pick_key($h > 0 ? $h : $lim[2]); }
    elseif ($chk === 'punch') $key = 'punch';
    elseif (strpos($chk, 'dp:') === 0 && isset(b2_checks()[$chk])) $key = $chk;
    if ($key !== '' && isset(b2_checks()[$key])) $d['adapter'] = $key;
    // the penalty: only when the manager's own words speak of money; amounts are capped again in b2_item_clean
    $money = b2_has($lw, ['penalty', 'fine ', 'fined', 'charge', '₹', 'rs ', 'rs.', 'rupee', 'deduct', 'minus', 'cut ']);
    $p = isset($ai['penalty']) && is_array($ai['penalty']) ? $ai['penalty'] : [];
    if ($money && empty($d['pen_set']) && $p) {
        $mode = (string)($p['mode'] ?? '');
        if ($mode === 'per_item' && isset($p['rate']) && is_numeric($p['rate']) && (float)$p['rate'] > 0 && empty($d['pen_mode'])) {
            $d['pen_mode'] = 'unit'; $d['pen_unit'] = (float)min(5000, $p['rate']);
            if (isset($p['min']) && is_numeric($p['min']) && (float)$p['min'] > 0) $d['pen_min'] = (float)$p['min'];
            if (isset($p['max']) && is_numeric($p['max']) && (float)$p['max'] > 0) $d['pen_max'] = (float)$p['max'];
            if (!empty($p['noun'])) $d['unit'] = mb_substr(preg_replace('/[^\p{L} ]/u', '', (string)$p['noun']) . 's', 0, 30);
            if (empty($d['kind']) || !in_array($d['kind'], ['daily', 'temp', 'number'], true)) $d['kind'] = 'daily';
        } elseif ($mode === 'flat' && isset($p['amount']) && is_numeric($p['amount']) && (float)$p['amount'] > 0 && empty($d['pen_mode'])) $d['pen_amount'] = (float)min(500, (float)$p['amount']);
    }
    if (isset($p['grace_min']) && is_numeric($p['grace_min']) && (int)$p['grace_min'] > 0 && (int)$p['grace_min'] <= 240) $d['grace_min'] = (float)(int)$p['grace_min'];
    foreach ((array)($ai['understood'] ?? []) as $u) if (is_string($u) && trim($u) !== '' && count($und) < 6) $und[] = mb_substr(trim($u), 0, 170);
    foreach ((array)($ai['questions'] ?? []) as $q) {
        if (!is_array($q) || count($qs) >= 2) continue; $id = (string)($q['id'] ?? ''); if (!in_array($id, ['hours', 'days', 'pen', 'time'], true)) continue;
        $opts = []; foreach ((array)($q['options'] ?? []) as $o) { if (!is_array($o)) continue; $v = trim((string)($o['v'] ?? '')); $l = trim((string)($o['l'] ?? $v));
            $okv = $id === 'time' ? preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) : ($id === 'hours' ? (ctype_digit($v) && (int)$v >= 12 && (int)$v <= 240) : ($id === 'days' ? (ctype_digit($v) && (int)$v >= 2 && (int)$v <= 10) : ($id === 'pen' ? (ctype_digit($v) && (int)$v <= 500) : false)));
            if ($okv && $l !== '') $opts[] = ['v' => $v, 'l' => mb_substr($l, 0, 40)]; }
        if (count($opts) >= 2 && !empty($q['text'])) $qs[] = ['id' => $id, 'text' => mb_substr(trim((string)$q['text']), 0, 140), 'options' => array_slice($opts, 0, 5)];
    }
    return [$d, $und, $qs];
}
/** what saving this will really do to other places: worked out by CODE from the rule books, not guessed by Claude */
function b2_ai_impact(PDO $pdo, array $c, $key) {
    $o = [];
    if ($key) { $s = b2_sync_preview($pdo, $key); if ($s !== '') $o[] = $s; }
    $p = ((($c['pen_mode'] ?? 'flat') === 'unit') ? (float)($c['pen_unit'] ?? 0) : (float)($c['pen_amount'] ?? 0));
    $seen = [];
    if ($key) foreach (b2_link_targets(['adapter' => $key]) as $t) {
        $R = b2_rb_get($pdo, $t[0], $t[1]); if (!$R) continue; $seen[$R['name']] = 1; $nm = '“' . $R['name'] . '” (' . b2_link_inr($R['amount']) . ($R['active'] ? ', on' : ', off') . ')';
        if ($t[2] === 'item') { if ($p > 0 && $R['active']) $o[] = 'Old rule ' . $nm . ' will be switched off: this task charges ' . b2_link_inr($p) . ' instead. One charge only.'; elseif ($p > 0) $o[] = 'Old rule ' . $nm . ' will show ' . b2_link_inr($p) . ' too.'; elseif ($R['active'] && $R['amount'] > 0) $o[] = 'Old rule ' . $nm . ' keeps charging. This task adds no second penalty.'; }
        else $o[] = 'Old rule ' . $nm . ' does the charging, because it creates the items this task watches.' . ($p > 0 ? ' The ' . b2_link_inr($p) . ' you set moves into that rule.' : '');
    }
    // look-alikes: shown, never changed without a tap on the Rules in sync page
    $mine = implode(' ', b2_words($c['title'] ?? '')); $found = [];
    try {
        foreach ($pdo->query("SELECT title AS n, amount FROM dp_rules WHERE active = 1 AND amount > 0")->fetchAll() as $r) if (!isset($seen[$r['n']]) && bd_sim($mine, implode(' ', b2_words($r['n']))) >= 0.5) $found[] = '“' . $r['n'] . '” (' . b2_link_inr($r['amount']) . ')';
        foreach ($pdo->query("SELECT name AS n, amount FROM penalty_rules WHERE active = 1 AND amount > 0")->fetchAll() as $r) if (!isset($seen[$r['n']]) && bd_sim($mine, implode(' ', b2_words($r['n']))) >= 0.5) $found[] = '“' . $r['n'] . '” (' . b2_link_inr($r['amount']) . ')';
    } catch (Throwable $e) {}
    if ($found) $o[] = 'Looks like the old rule ' . implode(', ', array_slice($found, 0, 3)) . ' covers the same thing. It is NOT changed. Open Boss > Rules in sync and tap Link to make them one rule.';
    return $o;
}

/* ============================== judging that runs by itself ============================== */
/** Improve tasks: a good day extends the streak, a bad day resets it, a day with nothing to judge changes nothing */
function b2_improve_judge(PDO $pdo, $untilDate) {
    $n = 0; $need = (int)b2_cfg('improve_days');
    foreach ($pdo->query("SELECT * FROM b2_items WHERE kind = 'improve' AND status = 'active'")->fetchAll() as $it) {
        $from = $it['last_good'] ? date('Y-m-d', strtotime($it['last_good'] . ' +1 day')) : $it['start_date']; $good = (int)$it['good_days'];
        if ($from < date('Y-m-d', strtotime($untilDate . ' -10 days'))) $from = date('Y-m-d', strtotime($untilDate . ' -10 days'));
        for ($d = $from; $d <= $untilDate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $verdict = null;                                                                       // true good | false bad | null nothing to judge
            if ($it['ref_item_id']) {
                $st = $pdo->prepare("SELECT status, met FROM b2_runs WHERE item_id = ? AND run_date = ?"); $st->execute([(int)$it['ref_item_id'], $d]); $r = $st->fetch();
                if ($r && $r['status'] !== 'excused') $verdict = ($r['status'] === 'done' || $r['status'] === 'review') && ($r['met'] === null || (int)$r['met'] === 1);
            } elseif ($it['link_emp']) {
                $st = $pdo->prepare("SELECT SUM(status = 'missed') m, COUNT(*) c FROM b2_runs WHERE owner_emp = ? AND run_date = ? AND status != 'excused'"); $st->execute([(int)$it['link_emp'], $d]); $r = $st->fetch();
                $m = (int)$r['m']; $c = (int)$r['c'];
                try { $q = $pdo->prepare("SELECT SUM(status = 'missed') m, COUNT(*) c FROM dp_items WHERE owner_emp = ? AND task_date = ?"); $q->execute([(int)$it['link_emp'], $d]); $x = $q->fetch(); $m += (int)$x['m']; $c += (int)$x['c']; } catch (Throwable $e) {}
                if ($c > 0) $verdict = $m === 0;
            }
            if ($verdict === true) $good++; elseif ($verdict === false) $good = 0;
            if ($good >= $need) break;
        }
        $pdo->prepare("UPDATE b2_items SET good_days = ?, last_good = ?, updated_at = ? WHERE id = ?")->execute([$good, $untilDate, bd_now(), $it['id']]);
        if ($good >= $need) {
            $pdo->prepare("UPDATE b2_items SET status = 'done', closed_at = ?, pct = 100 WHERE id = ?")->execute([bd_now(), $it['id']]);
            b2_ev($pdo, (int)$it['id'], null, null, null, 'closed', $need . ' good days in a row. Closed by itself.');
            if ($it['link_emp']) bd_push($pdo, (int)$it['link_emp'], 'Well done', $need . ' good days in a row: ' . b2_short($it['title'], 70));
            $n++;
        }
    }
    return $n;
}
/** after 14 days of numbers the system proposes a target (average + 10%); the boss approves it */
function b2_propose_targets(PDO $pdo) {
    $n = 0; $bump = (float)b2_cfg('target_bump');
    foreach ($pdo->query("SELECT * FROM b2_items WHERE kind = 'number' AND status = 'active' AND target_state = 'none'")->fetchAll() as $it) {
        $st = $pdo->prepare("SELECT AVG(value) a, COUNT(*) c FROM b2_runs WHERE item_id = ? AND value IS NOT NULL AND run_date >= ?"); $st->execute([(int)$it['id'], date('Y-m-d', strtotime('-14 days'))]); $r = $st->fetch();
        if ((int)$r['c'] < 7) continue;
        $prop = round((float)$r['a'] * (1 + $bump / 100), $it['unit'] ? 0 : 1); if ($prop <= 0) continue;
        $pdo->prepare("UPDATE b2_items SET target_prop = ?, target_state = 'draft', updated_at = ? WHERE id = ?")->execute([$prop, bd_now(), $it['id']]); $n++;
    }
    return $n;
}
/** guarantee 6: look for holes every night (and every few hours) */
function b2_holes(PDO $pdo) {
    $now = [];
    $add = function ($kind, $text, $sec, $key) use ($pdo, &$now) { $now[$key] = 1; b2_alert($pdo, $kind, $text, $sec, $key); $pdo->prepare("UPDATE b2_alerts SET state = 'open', closed_at = NULL, text = ? WHERE ref_key = ? AND state = 'closed' AND created_at < ?")->execute([mb_substr($text, 0, 400), $key, date('Y-m-d H:i:s', strtotime('-2 days'))]); };
    foreach ($pdo->query("SELECT i.seat_id, COUNT(*) c, MIN(i.section_code) sec FROM b2_items i WHERE i.status = 'active' AND i.seat_id IS NOT NULL GROUP BY i.seat_id")->fetchAll() as $r) {
        if (!bd_holders($pdo, (int)$r['seat_id'])) $add('hole', 'Nobody holds the seat “' . b2_seat_title($pdo, (int)$r['seat_id']) . '”, which owns ' . $r['c'] . ' active task' . ((int)$r['c'] === 1 ? '' : 's') . '.', (string)$r['sec'], 'seat:' . $r['seat_id']);
    }
    foreach (b2_live_sections() as $sec) { $q = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE section_code = ? AND status = 'active'"); $q->execute([$sec]); if (!(int)$q->fetchColumn() && isset(b2_sections($pdo)[$sec])) $add('hole', b2_section_name($pdo, $sec) . ' is switched on, but it has no tasks. Nothing is being watched there.', $sec, 'empty:' . $sec); }
    foreach ($pdo->query("SELECT id, title, section_code FROM b2_items WHERE status = 'draft' AND created_at < '" . date('Y-m-d', strtotime('-7 days')) . "' AND build_id IS NULL")->fetchAll() as $r) $add('hole', 'A task has waited for your yes for over 7 days: “' . b2_short($r['title'], 70) . '”.', (string)$r['section_code'], 'draft7:' . $r['id']);
    foreach ($pdo->query("SELECT id, emp_id, section_code FROM b2_pens WHERE state = 'ask' AND created_at < '" . date('Y-m-d', strtotime('-7 days')) . "'")->fetchAll() as $r) $add('hole', 'A penalty for ' . bd_emp_name($pdo, $r['emp_id']) . ' has waited for your decision for over 7 days. Decide, or waive it.', (string)$r['section_code'], 'pen7:' . $r['id']);
    foreach ($pdo->query("SELECT id, title, section_code FROM b2_problems WHERE step = 'decide' AND created_at < '" . date('Y-m-d', strtotime('-5 days')) . "'")->fetchAll() as $r) $add('hole', 'A problem has waited for your decision for over 5 days: “' . b2_short($r['title'], 70) . '”.', (string)$r['section_code'], 'prob5:' . $r['id']);
    if (b2_dow(b2_day()) !== 7 && (int)date('G') >= 11) {
        $act = (int)$pdo->query("SELECT COUNT(*) FROM b2_items WHERE status = 'active' AND block IN ('repeat','number')")->fetchColumn();
        $runs = (int)$pdo->query("SELECT COUNT(*) FROM b2_runs WHERE run_date = '" . b2_day() . "'")->fetchColumn();
        if ($act > 0 && $runs === 0) $add('system', 'No daily tasks were made today although ' . $act . ' are active. Is today a holiday? If not, the engine may have stopped: open Setup > Health.', '', 'noruns:' . b2_day());
    }
    // close what is no longer true
    foreach ($pdo->query("SELECT id, ref_key, kind FROM b2_alerts WHERE state = 'open'")->fetchAll() as $a) {
        $k = (string)$a['ref_key'];
        if (in_array($a['kind'], ['hole', 'system'], true) && !isset($now[$k]) && preg_match('/^(seat|empty|draft7|pen7|prob5|noruns):/', $k)) b2_alert_close($pdo, $k);
        if ($a['kind'] === 'nobody' && preg_match('/^nobody:(\d+):(.+)$/', $k, $m)) {
            $it = b2_row($pdo, 'b2_items', (int)$m[1]);
            if (!$it || $it['status'] !== 'active') { b2_alert_close($pdo, $k); continue; }
            $q = $pdo->prepare("SELECT owner_emp, status FROM b2_runs WHERE item_id = ? ORDER BY id DESC LIMIT 1"); $q->execute([(int)$m[1]]); $r = $q->fetch();
            if (!$r || $r['owner_emp'] || $r['status'] !== 'open') b2_alert_close($pdo, $k);
        }
    }
    return count($now);
}
function b2_selftest(PDO $pdo) {
    $res = 'ok'; $t0 = microtime(true);
    try {
        $pdo->prepare("INSERT INTO b2_ev (kind, text, created_at) VALUES ('selftest', 'night check', ?)")->execute([bd_now()]); $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("SELECT COUNT(*) FROM b2_ev WHERE id = ?"); $st->execute([$id]); if ((int)$st->fetchColumn() !== 1) throw new Exception('could not read back');
        $pdo->prepare("DELETE FROM b2_ev WHERE id = ?")->execute([$id]);
        $it = $pdo->query("SELECT * FROM b2_items WHERE status = 'active' ORDER BY id LIMIT 1")->fetch();
        if ($it) { $v = b2_items_view($pdo, [$it]); if (!$v || !isset($v[0]['state'])) throw new Exception('item view failed'); b2_owner($pdo, $it, b2_day()); }
        $x = b2_clean_dow('1,2,9'); if ($x !== '1,2') throw new Exception('clean failed');
    } catch (Throwable $e) { $res = 'FAILED: ' . $e->getMessage(); }
    b2_state_put($pdo, 'selftest', bd_json(['at' => bd_now(), 'result' => $res, 'ms' => (int)round((microtime(true) - $t0) * 1000)]));
    if ($res !== 'ok') b2_alert($pdo, 'system', 'The nightly self-test failed: ' . $res, '', 'selftest:' . b2_day());
    return $res;
}

/* ============================== the tick ============================== */
function b2_tick(PDO $pdo, $now = null, $force = false) {
    $res = ['ran' => false]; $now = $now ?: time();
    try { b2_ensure($pdo); } catch (Throwable $e) { error_log('Boss Engine 2 ensure: ' . $e->getMessage()); return ['error' => 'tables']; }
    $got = true; try { $got = (bool)$pdo->query("SELECT GET_LOCK('b2_tick', 0)")->fetchColumn(); } catch (Throwable $e) {}
    if (!$got) return $res;
    try {
        try { b2_links_pass($pdo); } catch (Throwable $e) {}   // old rule books and Boss tasks stay one rule
        $last = (int)b2_state_get($pdo, 'last_tick_ts', '0');
        if (!$force && $now - $last < 60) { return $res; }
        b2_state_put($pdo, 'last_tick_ts', (string)$now); b2_state_put($pdo, 'heartbeat', bd_now());
        if (b2_cfg('mode') !== 'on') return ['ran' => true, 'mode' => 'off'];
        $today = b2_day($now); $res = ['ran' => true];
        if ($now - (int)b2_state_get($pdo, 'last_adopt', '0') > 300) { b2_state_put($pdo, 'last_adopt', (string)$now); $res['adopted'] = b2_adopt_duties($pdo); }
        $res['made'] = b2_generate($pdo, $today);
        $res['moved'] = b2_reassign($pdo, $today);
        $res['auto'] = b2_auto_check($pdo, $today);
        $res['reminded'] = b2_remind($pdo, $now);
        $res['missed'] = b2_miss($pdo, $now);
        if (b2_state_get($pdo, 'daily_done', '') !== $today) {
            b2_state_put($pdo, 'daily_done', $today);
            $res['improve'] = b2_improve_judge($pdo, date('Y-m-d', strtotime($today . ' -1 day')));
            $res['targets'] = b2_propose_targets($pdo);
            $res['docs'] = b2_docs_tick($pdo);
            $res['selftest'] = b2_selftest($pdo);
            try { $pdo->prepare("DELETE FROM b2_state WHERE skey LIKE 'review:%' AND skey < ?")->execute(['review:' . $today]); } catch (Throwable $e) {}
        }
        try { $sc = b2_scan_due($pdo, $now); if ($sc !== null) $res['scan'] = ['raised' => $sc['raised'], 'closed' => $sc['closed']]; } catch (Throwable $e) { error_log('Boss Engine 2 scan: ' . $e->getMessage()); b2_state_put($pdo, 'last_error', bd_now() . ' scan: ' . $e->getMessage()); }
        if ($now - (int)b2_state_get($pdo, 'last_slow', '0') > 3600) { b2_state_put($pdo, 'last_slow', (string)$now); $res['problems'] = b2_problems_tick($pdo); }
        if ($now - (int)b2_state_get($pdo, 'last_holes', '0') > 3 * 3600) { b2_state_put($pdo, 'last_holes', (string)$now); $res['holes'] = b2_holes($pdo); }
    } catch (Throwable $e) {
        error_log('Boss Engine 2 tick: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 200);
        b2_state_put($pdo, 'last_error', bd_now() . ' ' . $e->getMessage());
    }
    try { $pdo->query("SELECT RELEASE_LOCK('b2_tick')"); } catch (Throwable $e) {}
    return $res;
}
function b2_health(PDO $pdo) {
    $hb = b2_state_get($pdo, 'heartbeat', ''); $age = $hb ? (int)floor((time() - strtotime($hb)) / 60) : null;
    $self = bd_unjson(b2_state_get($pdo, 'selftest', ''), []);
    $workHours = (int)date('G') >= 10 && (int)date('G') < 20 && b2_dow(b2_day()) !== 7;
    $pages = b2_all_pages(); $skip = ['index.html', 'hub.html', 'dashboard.html'];
    $full = []; $text = [];
    foreach ($pages as $p) { if (in_array($p, $skip, true)) continue; if (b2_page_contract($pdo, $p)) $full[] = $p; else $text[] = $p; }
    $unowned = (int)$pdo->query("SELECT COUNT(*) FROM b2_runs r WHERE r.run_date = '" . b2_day() . "' AND r.owner_emp IS NULL AND r.status = 'open'")->fetchColumn();
    return ['heartbeat' => $hb, 'heartbeat_min' => $age, 'heartbeat_ok' => $age !== null && ($age <= 30 || !$workHours), 'last_error' => b2_state_get($pdo, 'last_error', ''),
        'selftest' => $self, 'selftest_ok' => !$self || ($self['result'] ?? '') === 'ok',
        'counts' => ['active' => (int)$pdo->query("SELECT COUNT(*) FROM b2_items WHERE status = 'active'")->fetchColumn(), 'drafts' => (int)$pdo->query("SELECT COUNT(*) FROM b2_items WHERE status = 'draft'")->fetchColumn(), 'runs_today' => (int)$pdo->query("SELECT COUNT(*) FROM b2_runs WHERE run_date = '" . b2_day() . "'")->fetchColumn(), 'unowned_today' => $unowned, 'alerts' => (int)$pdo->query("SELECT COUNT(*) FROM b2_alerts WHERE state = 'open'")->fetchColumn()],
        'scan' => bd_unjson(b2_state_get($pdo, 'scan_last', ''), []) ?: null, 'scan_on' => b2_cfg('scan') === '1', 'scan_hour' => (int)b2_cfg('scan_hour'),
        'contracts' => ['full' => $full, 'text_only' => $text], 'ai' => b2_ai_on(), 'pilot' => b2_cfg('pilot') === '1', 'first_install' => bd_unjson(b2_state_get($pdo, 'first_install', ''), [])];
}

/* ============================== badges and My Day numbers ============================== */
function b2_badge_my(PDO $pdo, $emp) {
    $open = 0; $has = 0;
    try {
        $st = $pdo->prepare("SELECT i.section_code FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.owner_emp = ? AND r.status = 'open' AND r.run_date <= ? AND i.status = 'active' AND i.kind != 'improve' AND (i.block != 'once' OR r.run_date <= ?)");
        $st->execute([(int)$emp, b2_day(), date('Y-m-d', strtotime('+1 day'))]);
        foreach ($st->fetchAll() as $r) if (b2_section_live((string)$r['section_code'])) $open++;
        $q = $pdo->prepare("SELECT COUNT(*) FROM b2_runs WHERE owner_emp = ? AND run_date >= ?"); $q->execute([(int)$emp, date('Y-m-d', strtotime('-14 days'))]); $has = (int)$q->fetchColumn() > 0 ? 1 : 0;
    } catch (Throwable $e) {}
    return [$open, $has];
}
function b2_badge_boss(PDO $pdo, array $w) {
    try {
        $sc = b2_scope_sql($pdo, $w, 'section_code');
        return (int)$pdo->query("SELECT (SELECT COUNT(*) FROM b2_problems WHERE step = 'decide' AND ($sc OR section_code IS NULL)) + (SELECT COUNT(*) FROM b2_pens WHERE state = 'ask' AND $sc) + (SELECT COUNT(*) FROM b2_items WHERE status = 'draft' AND build_id IS NULL AND $sc) + (SELECT COUNT(*) FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.proof_state = 'flag' AND r.run_date >= '" . date('Y-m-d', strtotime('-14 days')) . "' AND " . b2_scope_sql($pdo, $w, 'i.section_code') . ") + (SELECT COUNT(*) FROM b2_alerts WHERE state = 'open')")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function b2_my(PDO $pdo, array $w) {
    $emp = (int)$w['emp']; $today = b2_day(); $out = ['today' => $today, 'cards' => [], 'improve' => [], 'problems' => [], 'summary' => ['done' => 0, 'open' => 0, 'missed' => 0], 'live' => false, 'any' => false];
    if (!$emp) return $out;
    $st = $pdo->prepare("SELECT r.id AS rid FROM b2_runs r JOIN b2_items i ON i.id = r.item_id WHERE r.owner_emp = ? AND i.status = 'active' AND i.kind != 'improve' AND r.status != 'excused' AND (
        (i.block != 'once' AND r.run_date = ?) OR (i.block = 'once' AND ((r.status IN ('open','missed','review') AND r.run_date <= ?) OR (r.status = 'done' AND r.done_at >= ?)))) ORDER BY r.due_at");
    $st->execute([$emp, $today, date('Y-m-d', strtotime('+7 days')), date('Y-m-d H:i:s', strtotime('-2 days'))]);
    $ids = array_map(function ($r) { return (int)$r['rid']; }, $st->fetchAll());
    foreach ($ids as $rid) {
        $run = b2_run_full($pdo, $rid); if (!$run) continue;
        $live = b2_section_live((string)$run['section_code']);
        if (!$live && !$w['admin']) continue;
        $it = b2_row($pdo, 'b2_items', $run['item_id']); $card = b2_items_view($pdo, [$it])[0];
        $blocked = !empty($it['blocked_by']);
        $dueIn = $run['block'] === 'once' ? (int)floor((strtotime($run['run_date']) - strtotime($today)) / 86400) : 0;
        $streak = 0;
        if ($run['block'] !== 'once') { for ($i = 1; $i <= 30; $i++) { $d = date('Y-m-d', strtotime('-' . $i . ' days')); $s = null; foreach ($card['strip'] as $x) if ($x['d'] === $d) $s = $x['s']; if ($s === null) { $q = $pdo->prepare("SELECT status FROM b2_runs WHERE item_id = ? AND run_date = ?"); $q->execute([(int)$it['id'], $d]); $s = $q->fetchColumn() ?: 'off'; $s = in_array($s, ['done', 'review'], true) ? 'done' : $s; } if ($s === 'done') $streak++; elseif ($s === 'off' || $s === 'excused') continue; else break; } }
        $c = ['run_id' => (int)$run['id'], 'item_id' => (int)$it['id'], 'kind' => $it['kind'], 'kind_label' => b2_kind_label($it['kind']), 'block' => $it['block'], 'title' => $it['title'], 'why' => (string)$it['why'], 'detail' => (string)$it['detail'], 'section' => $card['section'], 'live' => $live,
            'status' => $run['status'], 'late' => (int)$run['late'], 'due_at' => $run['due_at'], 'due_label' => $run['block'] === 'once' ? ($dueIn < 0 ? 'Overdue since ' . date('D j M', strtotime($run['run_date'])) : ($dueIn === 0 ? 'Today ' . b2_t12($it['due_time']) : 'Due ' . date('D j M', strtotime($run['run_date'])))) : 'By ' . b2_t12($it['due_time']),
            'overdue' => strtotime($run['due_at']) < time() && in_array($run['status'], ['open', 'missed'], true), 'proof' => $it['proof'], 'proof_label' => b2_proofs()[$it['proof']] ?? '', 'photo_prompt' => (string)$it['photo_prompt'], 'unit' => (string)$it['unit'], 'target' => $card['target'], 'cmp' => $it['cmp'], 'target_state' => $it['target_state'],
            'value' => $run['value'] !== null ? (float)$run['value'] : null, 'note' => (string)$run['note'], 'met' => $run['met'] === null ? null : (int)$run['met'], 'proof_state' => $run['proof_state'], 'proof_why' => (string)$run['proof_why'], 'cover' => (string)$run['cover'], 'strip' => $card['strip'], 'streak' => $streak,
            'pen_amount' => (float)$it['pen_amount'], 'fair' => $card['fair'], 'progress' => (int)$it['progress_every'], 'pct' => $run['pct'] !== null ? (int)$run['pct'] : (int)$it['pct'], 'waiting_on' => (string)$it['waiting_on'], 'blocked' => $blocked, 'checker' => $card['checker'], 'state' => $card['state'], 'state_label' => $card['state_label'], 'home' => (string)$it['home']];
        $out['cards'][] = $c; $out['live'] = $out['live'] || $live;
        if ($run['block'] === 'once' && $dueIn > 1) continue;
        if (in_array($run['status'], ['done', 'review'], true)) $out['summary']['done']++; elseif ($run['status'] === 'missed') $out['summary']['missed']++; else $out['summary']['open']++;
    }
    $st = $pdo->prepare("SELECT i.* FROM b2_items i WHERE i.kind = 'improve' AND i.status = 'active' AND (i.link_emp = ? OR i.seat_id IN (SELECT seat_id FROM bd_holders WHERE emp_id = ?))"); $st->execute([$emp, $emp]);
    foreach ($st->fetchAll() as $it) { if (!b2_section_live((string)$it['section_code']) && !$w['admin'] && $it['section_code']) continue; $out['improve'][] = ['item_id' => (int)$it['id'], 'title' => $it['title'], 'good_days' => (int)$it['good_days'], 'need' => (int)b2_cfg('improve_days')]; }
    $st = $pdo->prepare("SELECT * FROM b2_problems WHERE by_emp = ? AND created_at >= ? ORDER BY id DESC LIMIT 6"); $st->execute([$emp, date('Y-m-d', strtotime('-45 days'))]);
    $out['problems'] = array_map(function ($p) use ($pdo) { return b2_problem_view($pdo, $p, true); }, $st->fetchAll());
    $out['any'] = count($out['cards']) > 0;
    return $out;
}

/* ============================== the endpoints (bd.b2_*) ============================== */
function b2_dispatch(PDO $pdo, $action, array $auth, array $w, $by, array $in, $isPost) {
    b2_ensure($pdo);
    $me = (int)$w['emp'];
    $post = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $mgr = function () use ($w) { if (!$w['manager']) json_response(['error' => 'The Boss Engine pages are for Admin, HR and section bosses.'], 403); };
    $adm = function () use ($auth) { bd_need_admin($auth); };
    $gid = function () use ($in) { return (int)($in['id'] ?? $_GET['id'] ?? 0); };
    $out = function ($r) { $ok = $r[0]; $msg = $r[1]; $code = $r[2] ?? 200; $extra = $r[3] ?? []; if (!$ok) json_response(['error' => $msg], $code); json_response(array_merge(['ok' => true, 'message' => $msg], $extra), $code); };
    $secOk = function ($code) use ($pdo, $w) { return $w['admin'] || $w['top'] || ($code !== '' && b2_can_section($w, $code, $pdo)); };
    switch ($action) {

    /* ----- everyone ----- */
    case 'bd.b2_my': { json_response(b2_my($pdo, $w)); }
    case 'bd.b2_submit': { $post(); $out(b2_submit($pdo, $w, $in, $by)); }
    case 'bd.b2_submit_many': { $post(); $out(b2_submit_many($pdo, $w, $in)); }
    case 'bd.b2_progress': { $post(); $out(b2_progress($pdo, $w, $in)); }
    case 'bd.b2_report': {
        $post(); $body = trim((string)($in['body'] ?? $in['text'] ?? '')); if (mb_strlen($body) < 6) json_response(['error' => 'Please write a little more, so Claude can understand it.'], 422);
        $id = b2_problem_create($pdo, $w, ['body' => $body, 'title' => (string)($in['title'] ?? ''), 'section_code' => (string)($in['section_code'] ?? ''), 'page' => (string)($in['page'] ?? ''), 'urgent' => !empty($in['urgent'])], $by);
        $p = b2_row($pdo, 'b2_problems', $id);
        json_response(['ok' => true, 'id' => $id, 'step' => $p['step'], 'questions' => bd_unjson($p['qa'], []), 'message' => $p['step'] === 'asking' ? 'Claude has a question first.' : 'Thank you. Your boss will decide, and you will be told what happens.']);
    }
    case 'bd.b2_problem_answer': {
        $post(); $r = b2_problem_answer($pdo, $w, $gid(), (array)($in['answers'] ?? []), !empty($in['skip']));
        $out($r);
    }
    case 'bd.b2_proof': {
        $f = b2_proof_file($pdo, $w, (int)($_GET['run_id'] ?? 0)); if (!$f) json_response(['error' => 'No picture.'], 404);
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=600'); readfile($f); exit;
    }
    case 'bd.b2_page': {
        $page = (string)($in['page'] ?? $_GET['page'] ?? ''); $k = b2_page_key($page);
        $contract = b2_page_contract($pdo, $k); $sec = $contract && $contract['section'] ? $contract['section'] : b2_page_section($pdo, $k);
        $o = ['page' => $k, 'mode' => $contract ? 'full' : 'text', 'contract' => $contract, 'section_code' => $sec, 'section' => $sec ? b2_section_name($pdo, $sec) : '',
            'manager' => (bool)$w['manager'], 'can_add' => (bool)($w['admin'] || $w['top'] || ($sec && b2_can_section($w, $sec, $pdo))), 'ai' => b2_ai_on(), 'mine' => $me ? true : false];
        if ($w['manager'] && $sec && b2_can_section($w, $sec, $pdo)) {
            $tc = b2_tile_counts($pdo, $w, $sec); $slip = 0; $late = 0; foreach ($tc['cards'] as $c) { if ($c['state'] === 'slipping') $slip++; if ($c['state'] === 'late') $late++; }
            $o['stats'] = ['today' => b2_today_stats($pdo, $w, $sec), 'active' => count($tc['cards']), 'slipping' => $slip, 'late' => $late, 'boss' => implode(', ', b2_section_boss_names($pdo, b2_section_id($pdo, $sec)))];
        }
        json_response($o);
    }

    /* ----- boss: the page ----- */
    case 'bd.b2_brief': { $mgr(); json_response(b2_brief($pdo, $w)); }
    case 'bd.b2_ask': {
        $mgr(); $post(); $q = trim((string)($in['q'] ?? '')); if (mb_strlen($q) < 3) json_response(['error' => 'Type your question.'], 422);
        $sec = (string)($in['section_code'] ?? ''); if ($sec !== '' && !$secOk($sec)) $sec = '';
        json_response(array_merge(['ok' => true], b2_ask($pdo, $w, $q, $sec, (string)($in['page_text'] ?? ''))));
    }
    case 'bd.b2_need_act': {
        $mgr(); $post(); $type = (string)($in['type'] ?? ''); $id = $gid(); $act = (string)($in['act'] ?? '');
        if ($type === 'penalty') $out(b2_pen_act($pdo, $w, $id, $act, $in, $by));
        if ($type === 'proof') $out(b2_check_run($pdo, $w, $id, $act === 'accept', trim((string)($in['note'] ?? '')), $by));
        if ($type === 'draft') {
            $it = b2_row($pdo, 'b2_items', $id); if (!$it) json_response(['error' => 'Task not found.'], 404); if (!$secOk((string)$it['section_code'])) json_response(['error' => 'Not your section.'], 403);
            if ($act === 'approve') { if (!$it['seat_id']) json_response(['error' => 'Choose an owner first.'], 422); $pdo->prepare("UPDATE b2_items SET status = 'active', updated_at = ? WHERE id = ?")->execute([bd_now(), $id]); b2_activate($pdo, $id); b2_ev($pdo, $id, null, null, $me, 'approved', 'Approved by ' . $by); $sn = b2_sync_dispatch($pdo, $id, $by); json_response(['ok' => true, 'message' => 'Started.' . ($sn !== '' ? ' ' . $sn : '')]); }
            $pdo->prepare("UPDATE b2_items SET status = 'archived', closed_at = ? WHERE id = ?")->execute([bd_now(), $id]); json_response(['ok' => true, 'message' => 'Dropped.']);
        }
        if ($type === 'target') {
            $it = b2_row($pdo, 'b2_items', $id); if (!$it || !$secOk((string)$it['section_code'])) json_response(['error' => 'Not found.'], 404);
            if ($act === 'approve') $pdo->prepare("UPDATE b2_items SET target = target_prop, target_state = 'approved', updated_at = ? WHERE id = ?")->execute([bd_now(), $id]);
            elseif ($act === 'change') { $v = (float)($in['value'] ?? -1); if ($v < 0 || $v > 1e9) json_response(['error' => 'Enter a number.'], 422); $pdo->prepare("UPDATE b2_items SET target = ?, target_state = 'approved', updated_at = ? WHERE id = ?")->execute([$v, bd_now(), $id]); }
            else $pdo->prepare("UPDATE b2_items SET target = NULL, target_prop = NULL, target_state = 'off', updated_at = ? WHERE id = ?")->execute([bd_now(), $id]);
            b2_ev($pdo, $id, null, null, $me, 'target', 'Target decision: ' . $act . ' by ' . $by); json_response(['ok' => true, 'message' => $act === 'none' ? 'No target. It will not be judged against a number.' : 'Target saved.']);
        }
        if ($type === 'alert') { $a = b2_row($pdo, 'b2_alerts', $id); if ($a) $pdo->prepare("UPDATE b2_alerts SET state = 'closed', closed_at = ? WHERE id = ?")->execute([bd_now(), $id]); json_response(['ok' => true, 'message' => 'Noted.']); }
        if ($type === 'build') {
            $b = b2_row($pdo, 'b2_builds', $id); if (!$b) json_response(['error' => 'Not found.'], 404);
            $st = $pdo->prepare("SELECT id FROM b2_items WHERE build_id = ? AND status = 'draft'"); $st->execute([$id]); $n = 0;
            foreach ($st->fetchAll() as $r) { $pdo->prepare("UPDATE b2_items SET status = 'active', updated_at = ? WHERE id = ? AND seat_id IS NOT NULL")->execute([bd_now(), $r['id']]); b2_activate($pdo, (int)$r['id']); b2_sync_dispatch($pdo, (int)$r['id'], $by); $n++; }
            json_response(['ok' => true, 'message' => $n . ' task' . ($n === 1 ? '' : 's') . ' started.']);
        }
        json_response(['error' => 'Unknown decision.'], 422);
    }
    case 'bd.b2_sections': { $mgr(); json_response(['sections' => b2_sections_view($pdo, $w)]); }
    case 'bd.b2_section': {
        $mgr(); $code = (string)($_GET['code'] ?? $in['code'] ?? ''); if (!$secOk($code)) json_response(['error' => 'This section is not yours.'], 403);
        $d = b2_section_page($pdo, $w, $code); if (!$d) json_response(['error' => 'Section not found.'], 404); json_response($d);
    }
    case 'bd.b2_items': {
        $mgr(); $f = ['section' => (string)($_GET['section'] ?? ''), 'kind' => (string)($_GET['kind'] ?? ''), 'status' => (string)($_GET['status'] ?? 'live'), 'state' => (string)($_GET['state'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'emp' => (int)($_GET['emp'] ?? 0), 'seat_id' => (int)($_GET['seat_id'] ?? 0), 'offset' => (int)($_GET['offset'] ?? 0), 'limit' => (int)($_GET['limit'] ?? 120)];
        if ($f['section'] !== '' && !$secOk($f['section'])) json_response(['error' => 'Not your section.'], 403);
        $r = ['items' => b2_items_list($pdo, $w, $f)];
        if (!empty($_GET['dept'])) $r['dept'] = b2_dept_rows($pdo, ['section' => $f['section'], 'from' => date('Y-m-d', strtotime('-3 days'))]);
        json_response($r);
    }
    case 'bd.b2_item': {
        $mgr(); $it = b2_row($pdo, 'b2_items', $gid()); if (!$it) json_response(['error' => 'Task not found.'], 404); if (!$secOk((string)$it['section_code'])) json_response(['error' => 'Not your section.'], 403);
        $card = b2_item_card($pdo, $it['id']);
        $st = $pdo->prepare("SELECT kind, text, created_at, emp_id, pct FROM b2_ev WHERE item_id = ? ORDER BY id DESC LIMIT 40"); $st->execute([(int)$it['id']]);
        $card['timeline'] = array_map(function ($r) use ($pdo) { return ['kind' => $r['kind'], 'text' => $r['text'], 'at' => $r['created_at'], 'who' => $r['emp_id'] ? bd_emp_name($pdo, $r['emp_id']) : '', 'pct' => $r['pct']]; }, $st->fetchAll());
        $st = $pdo->prepare("SELECT id, run_date, status, value, owner_emp, late, proof_state, proof_path IS NOT NULL AS has_proof FROM b2_runs WHERE item_id = ? ORDER BY run_date DESC LIMIT 30"); $st->execute([(int)$it['id']]);
        $card['runs'] = array_map(function ($r) use ($pdo) { return ['id' => (int)$r['id'], 'date' => $r['run_date'], 'status' => $r['status'], 'value' => $r['value'] !== null ? (float)$r['value'] : null, 'owner' => $r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : '', 'late' => (int)$r['late'], 'proof_state' => $r['proof_state'], 'has_proof' => (bool)$r['has_proof']]; }, $st->fetchAll());
        json_response($card);
    }
    case 'bd.b2_item_save': {
        $mgr(); $post(); $id = $gid(); $ex = $id ? b2_row($pdo, 'b2_items', $id) : null;
        if ($id && !$ex) json_response(['error' => 'Task not found.'], 404);
        $sec = (string)($in['section_code'] ?? ($ex['section_code'] ?? '')); if (!$secOk($sec) || ($ex && !$secOk((string)$ex['section_code']))) json_response(['error' => 'You can add tasks only in your own section.'], 403);
        list($c, $err) = b2_item_clean($pdo, $in, $ex); if (!$c) json_response(['error' => $err], 422);
        if (!$ex && empty($in['draft'])) { $cm = b2_cap_block($pdo, $c); if ($cm) json_response(['error' => $cm, 'cap' => true], 422); }
        if ($ex) { b2_item_update($pdo, $id, $c); b2_ev($pdo, $id, null, null, $me, 'edited', 'Edited by ' . $by); $sn = b2_sync_dispatch($pdo, $id, $by); json_response(['ok' => true, 'id' => $id, 'message' => 'Saved.' . ($sn !== '' ? ' ' . $sn : ''), 'dispatch' => $sn, 'item' => b2_item_card($pdo, $id)]); }
        $status = empty($in['draft']) ? 'active' : 'draft';
        $nid = b2_item_insert($pdo, $c, $status, !empty($in['via_claude']) ? 'claude' : 'boss', $by);
        if (!empty($in['chain'])) b2_chain_link($pdo, (string)$in['chain'], $nid);
        $sn = $status === 'active' ? b2_sync_dispatch($pdo, $nid, $by) : '';
        json_response(['ok' => true, 'id' => $nid, 'dispatch' => $sn, 'message' => $status === 'active' ? 'Added. It starts ' . ($c['block'] === 'once' ? 'now.' : 'today.') . (b2_section_live((string)$c['section_code']) ? '' : ' (This section is still in test mode: staff do not see it yet.)') . ($sn !== '' ? ' ' . $sn : '') : 'Saved as a draft.', 'item' => b2_item_card($pdo, $nid)]);
    }
    case 'bd.b2_item_status': {
        $mgr(); $post(); $it = b2_row($pdo, 'b2_items', $gid()); if (!$it || !$secOk((string)$it['section_code'])) json_response(['error' => 'Task not found.'], 404);
        $s = (string)($in['status'] ?? ''); if (!in_array($s, ['active', 'paused', 'archived', 'done'], true)) json_response(['error' => 'Bad status.'], 422);
        $pdo->prepare("UPDATE b2_items SET status = ?, closed_at = IF(? IN ('archived','done'), ?, NULL), updated_at = ? WHERE id = ?")->execute([$s, $s, bd_now(), bd_now(), $it['id']]);
        if ($s === 'active') b2_activate($pdo, (int)$it['id']);
        $sn = $s === 'active' ? b2_sync_dispatch($pdo, (int)$it['id'], $by) : implode(' ', b2_links_pass($pdo, (int)$it['id'], $by));   // a stopped task gives its old rules back at once
        if (in_array($s, ['archived', 'done'], true)) $pdo->prepare("UPDATE b2_runs SET status = 'excused' WHERE item_id = ? AND status = 'open' AND run_date >= ?")->execute([$it['id'], b2_day()]);
        b2_ev($pdo, (int)$it['id'], null, null, $me, 'status', ucfirst($s) . ' by ' . $by); json_response(['ok' => true, 'dispatch' => $sn, 'message' => ($s === 'paused' ? 'Paused.' : ($s === 'active' ? 'Running.' : 'Done.')) . ($sn !== '' ? ' ' . $sn : '')]);
    }
    case 'bd.b2_item_pin': { $mgr(); $post(); $it = b2_row($pdo, 'b2_items', $gid()); if (!$it) json_response(['error' => 'Not found.'], 404); $v = !empty($in['pin']) ? 1 : 0; $pdo->prepare("UPDATE b2_items SET pin = ? WHERE id = ?")->execute([$v, $it['id']]); json_response(['ok' => true, 'message' => $v ? 'Pinned to the Numbers strip.' : 'Unpinned.']); }
    case 'bd.b2_draft': {
        $mgr(); $post(); $text = trim((string)($in['text'] ?? '')); if (mb_strlen($text) < 6) json_response(['error' => 'Say what must be done, in your own words.'], 422);
        $ctx = ['section' => (string)($in['section_code'] ?? ''), 'page' => (string)($in['page'] ?? ''), 'title_hint' => (string)($in['title_hint'] ?? ''),
            'original' => (string)($in['original'] ?? ''), 'correction' => (string)($in['correction'] ?? ''), 'prev' => (isset($in['prev']) && is_array($in['prev'])) ? $in['prev'] : null, 'round' => (int)($in['round'] ?? 1), 'answers' => (isset($in['answers']) && is_array($in['answers'])) ? $in['answers'] : [], 'ask' => !empty($in['ask'])];
        json_response(array_merge(['ok' => true], b2_draft($pdo, $w, $text, $ctx)));
    }
    case 'bd.b2_suggest': {
        $mgr(); $page = (string)($in['page'] ?? $_GET['page'] ?? ''); $sec = (string)($in['section_code'] ?? $_GET['section_code'] ?? '');
        if ($sec === '' && $page !== '') { $k = b2_page_key($page); $ct = b2_page_contract($pdo, $k); $sec = $ct && $ct['section'] ? $ct['section'] : b2_page_section($pdo, $k); }
        if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
        json_response(['ok' => true, 'section_code' => $sec, 'section' => $sec ? b2_section_name($pdo, $sec) : '', 'suggestions' => b2_suggest($pdo, $w, $sec)]);
    }
    case 'bd.b2_draft_later': {
        $mgr(); $post(); $sec = (string)($in['section_code'] ?? ''); if (!$secOk($sec)) json_response(['error' => 'You can add tasks only in your own section.'], 403);
        list($ok, $res, $code) = b2_draft_later($pdo, $in, $by);
        if (!$ok) json_response(['error' => $res], $code);
        json_response($res);
    }
    case 'bd.b2_numbers': { $mgr(); json_response(['numbers' => b2_numbers($pdo, ['section' => (string)($_GET['section'] ?? ''), 'scope' => b2_scope_sql($pdo, $w, 'i.section_code')])]); }
    case 'bd.b2_problems': {
        $mgr(); $step = (string)($_GET['step'] ?? 'open'); $where = ["(" . b2_scope_sql($pdo, $w, 'section_code') . " OR section_code IS NULL)"];
        if ($step === 'open') $where[] = "step != 'closed'"; elseif ($step === 'closed') $where[] = "step = 'closed'"; elseif ($step !== 'all') { $where[] = 'step = ' . $pdo->quote($step); }
        if (!empty($_GET['section'])) $where[] = 'section_code = ' . $pdo->quote((string)$_GET['section']);
        $st = $pdo->query("SELECT * FROM b2_problems WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(step,'decide','asking','work','watch','closed'), id DESC LIMIT 80");
        json_response(['problems' => array_map(function ($p) use ($pdo) { return b2_problem_view($pdo, $p); }, $st->fetchAll())]);
    }
    case 'bd.b2_problem': { $mgr(); $p = b2_row($pdo, 'b2_problems', $gid()); if (!$p) json_response(['error' => 'Not found.'], 404); json_response(b2_problem_view($pdo, $p)); }
    case 'bd.b2_problem_pick': { $mgr(); $post(); $out(b2_problem_pick($pdo, $w, $gid(), (string)($in['option'] ?? ''), (array)($in['over'] ?? []), $by)); }
    case 'bd.b2_problem_close': { $mgr(); $post(); $out(b2_problem_close($pdo, $w, $gid(), (string)($in['reason'] ?? ''), $by)); }
    case 'bd.b2_builds': {
        $mgr(); $st = $pdo->query("SELECT * FROM b2_builds ORDER BY FIELD(status,'building','spec','live','dropped'), id DESC");
        json_response(['builds' => array_map(function ($b) use ($pdo) { return b2_build_view($pdo, $b, true); }, $st->fetchAll())]);
    }
    case 'bd.b2_build_save': {
        $mgr(); $post(); $id = $gid(); $title = trim((string)($in['title'] ?? '')); if (mb_strlen($title) < 3) json_response(['error' => 'Give it a name.'], 422);
        $spec = (string)($in['spec'] ?? ''); $sec = (string)($in['section_code'] ?? ''); if ($sec !== '' && !isset(b2_sections($pdo)[$sec])) $sec = '';
        if ($id) { $pdo->prepare("UPDATE b2_builds SET title = ?, spec = ?, why = ?, section_code = ?, home = ?, note = ?, updated_at = ? WHERE id = ?")->execute([mb_substr($title, 0, 200), $spec, mb_substr((string)($in['why'] ?? ''), 0, 500), $sec ?: null, mb_substr((string)($in['home'] ?? ''), 0, 80) ?: null, mb_substr((string)($in['note'] ?? ''), 0, 500) ?: null, bd_now(), $id]); json_response(['ok' => true, 'id' => $id, 'message' => 'Saved.']); }
        $pdo->prepare("INSERT INTO b2_builds (title, spec, why, section_code, home, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([mb_substr($title, 0, 200), $spec, mb_substr((string)($in['why'] ?? ''), 0, 500), $sec ?: null, mb_substr((string)($in['home'] ?? ''), 0, 80) ?: null, 'spec', $by, bd_now()]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Added to the Build system list.']);
    }
    case 'bd.b2_build_status': {
        $mgr(); $post(); $b = b2_row($pdo, 'b2_builds', $gid()); if (!$b) json_response(['error' => 'Not found.'], 404);
        $s = (string)($in['status'] ?? ''); if (!in_array($s, ['spec', 'building', 'live', 'dropped'], true)) json_response(['error' => 'Bad status.'], 422);
        $pdo->prepare("UPDATE b2_builds SET status = ?, live_at = IF(? = 'live', ?, live_at), updated_at = ? WHERE id = ?")->execute([$s, $s, bd_now(), bd_now(), $b['id']]);
        json_response(['ok' => true, 'message' => $s === 'live' ? 'Marked live. Tasks that were waiting for it can be started.' : 'Updated.']);
    }
    case 'bd.b2_people': { $mgr(); json_response(b2_people_view($pdo, $w)); }
    case 'bd.b2_punch': { $mgr(); json_response(b2_punch_view($pdo, $w)); }
    case 'bd.b2_punch_set': { $adm(); $post(); $out(b2_punch_save($pdo, $in, $by)); }
    case 'bd.b2_seat_set': {
        $mgr(); $post(); $s = bd_seat_row($pdo, (int)($in['seat_id'] ?? 0)); if (!$s) json_response(['error' => 'Seat not found.'], 404);
        if (b2_seat_is_person($s)) json_response(['error' => 'This seat belongs to one person.'], 422);
        $code = $s['section_id'] ? b2_section_code_of_id($pdo, $s['section_id']) : '';
        if (!($w['admin'] || $w['top'] || ($code !== '' && $secOk($code)))) json_response(['error' => 'This seat is not in your section.'], 403);
        $emps = array_values(array_filter(array_map('intval', (array)($in['emps'] ?? []))));
        bd_set_holders($pdo, (int)$s['id'], $emps); b2_ev($pdo, null, null, null, $me, 'seat', $s['title'] . ' now: ' . implode(', ', array_map(function ($e) use ($pdo) { return bd_emp_name($pdo, $e); }, $emps)) . ' (by ' . $by . ')');
        json_response(['ok' => true, 'message' => 'Saved. First person owns the work; the others cover when the first is away.', 'holders' => b2_seat_people($pdo, (int)$s['id'])]);
    }
    case 'bd.b2_seat_new': {
        $mgr(); $post(); $title = trim((string)($in['title'] ?? '')); if (mb_strlen($title) < 3) json_response(['error' => 'Give the seat a name.'], 422);
        $sec = (string)($in['section_code'] ?? ''); if (!$secOk($sec)) json_response(['error' => 'Choose one of your sections.'], 403);
        $sid = b2_section_id($pdo, $sec); $code = ($sec ?: 'team') . '.t' . substr(md5($title . microtime()), 0, 6);
        $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([$sid ?: null, $code, mb_substr($title, 0, 120), 'worker', bd_now()]); $nid = (int)$pdo->lastInsertId();
        $emps = array_values(array_filter(array_map('intval', (array)($in['emps'] ?? [])))); if ($emps) bd_set_holders($pdo, $nid, $emps);
        json_response(['ok' => true, 'id' => $nid, 'message' => 'Seat added.']);
    }
    case 'bd.b2_top_set': {
        $adm(); $post();
        if (isset($in['top_boss'])) { $v = (int)$in['top_boss']; bd_set('top_boss', $v ?: ''); bd_set_holders($pdo, bd_seat_id($pdo, 'top.boss'), $v ? [$v] : []); }
        if (isset($in['deputy'])) { $v = (int)$in['deputy']; bd_set('deputy', $v ?: ''); bd_set_holders($pdo, bd_seat_id($pdo, 'top.deputy'), $v ? [$v] : []); }
        if (isset($in['deputy_name'])) b2_set('deputy_name', mb_substr(trim((string)$in['deputy_name']), 0, 80));
        json_response(['ok' => true, 'message' => 'Saved.', 'top_boss' => (int)bd_cfg_fresh('top_boss') ?: null, 'deputy' => (int)bd_cfg_fresh('deputy') ?: null]);
    }
    case 'bd.b2_pens': {
        $mgr(); $st = (string)($_GET['state'] ?? 'ask'); $states = $st === 'all' ? ['ask', 'charged', 'waived', 'called', 'fixing', 'moved', 'capped'] : ($st === 'shadow' ? ['shadow'] : [$st]);
        json_response(['penalties' => b2_pen_list($pdo, $w, $states, 120), 'top' => b2_pen_top($pdo, $w, 5), 'month_total' => b2_pen_month($pdo), 'cap_total' => (float)b2_cfg('cap_total'), 'cap_person' => (float)b2_cfg('cap_person')]);
    }
    case 'bd.b2_pen_act': { $mgr(); $post(); $out(b2_pen_act($pdo, $w, $gid(), (string)($in['act'] ?? ''), $in, $by)); }
    case 'bd.b2_check': { $post(); $out(b2_check_run($pdo, $w, (int)($in['run_id'] ?? 0), !empty($in['ok']), trim((string)($in['note'] ?? '')), $by)); }
    case 'bd.b2_docs': {
        $mgr(); $st = $pdo->query("SELECT * FROM b2_docs WHERE status != 'done' AND " . b2_scope_sql($pdo, $w, 'section_code') . " ORDER BY (expires_on IS NULL), expires_on");
        json_response(['docs' => array_map(function ($d) use ($pdo) { return b2_doc_view($pdo, $d); }, $st->fetchAll())]);
    }
    case 'bd.b2_doc_save': {
        $mgr(); $post(); $id = $gid(); $title = trim((string)($in['title'] ?? '')); if (mb_strlen($title) < 3) json_response(['error' => 'Give the document a name.'], 422);
        $exp = b2_clean_date($in['expires_on'] ?? null, null); $sec = (string)($in['section_code'] ?? ''); if ($sec !== '' && !$secOk($sec)) json_response(['error' => 'Not your section.'], 403);
        $seat = !empty($in['emp_id']) ? b2_person_seat($pdo, (int)$in['emp_id']) : (int)($in['seat_id'] ?? 0);
        $ladder = preg_replace('/[^0-9,]/', '', (string)($in['ladder'] ?? '')) ?: null;
        $f = [mb_substr($title, 0, 160), mb_substr((string)($in['kind'] ?? 'licence'), 0, 12), $sec ?: null, $seat ?: null, mb_substr((string)($in['party'] ?? ''), 0, 160) ?: null, $exp, $ladder, mb_substr((string)($in['notes'] ?? ''), 0, 500) ?: null, mb_substr((string)($in['link'] ?? ''), 0, 300) ?: null, $exp ? 'active' : 'draft'];
        if ($id) { $f[] = bd_now(); $f[] = $id; $pdo->prepare("UPDATE b2_docs SET title=?, kind=?, section_code=?, seat_id=?, party=?, expires_on=?, ladder=?, notes=?, link=?, status=?, updated_at=?, raised=NULL WHERE id=?")->execute($f); json_response(['ok' => true, 'id' => $id, 'message' => 'Saved.']); }
        $f[] = $by; $f[] = bd_now(); $pdo->prepare("INSERT INTO b2_docs (title, kind, section_code, seat_id, party, expires_on, ladder, notes, link, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute($f);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => $exp ? 'Added. You will be reminded before it expires.' : 'Added. Enter the date so reminders can start.']);
    }
    case 'bd.b2_doc_del': { $mgr(); $post(); $pdo->prepare("UPDATE b2_docs SET status = 'done', updated_at = ? WHERE id = ?")->execute([bd_now(), $gid()]); json_response(['ok' => true, 'message' => 'Removed.']); }
    case 'bd.b2_setup': {
        $mgr(); $o = []; foreach (array_keys(b2_defaults()) as $k) $o[$k] = b2_cfg($k);
        $secs = []; foreach (b2_sections($pdo) as $c => $s) $secs[] = ['code' => $c, 'name' => $s['name'], 'live' => in_array($c, b2_live_sections(), true)];
        $legacy = []; foreach (['mode', 'pen_cap_day', 'pen_cap_month', 'remind_min', 'away_after_min', 'digest_hour'] as $k) $legacy[$k] = bd_cfg_fresh($k);
        json_response(['settings' => $o, 'sections' => $secs, 'legacy' => $legacy, 'admin' => $w['admin'], 'top_boss' => (int)bd_cfg_fresh('top_boss') ?: null, 'deputy' => (int)bd_cfg_fresh('deputy') ?: null, 'tick_key' => $w['admin'] ? bd_cfg_fresh('tick_key') : '', 'ai' => b2_ai_on()]);
    }
    case 'bd.b2_setup_save': {
        $adm(); $post(); $num = ['cap_person' => [0, 100000], 'cap_total' => [0, 1000000], 'fair_days' => [0, 90], 'slip_miss' => [1, 7], 'slip_under' => [1, 7], 'improve_days' => [2, 30], 'watch_days' => [7, 120], 'target_bump' => [0, 200], 'scan_hour' => [0, 23], 'cap_daily' => [1, 30]];
        foreach ($num as $k => $r) if (isset($in[$k])) b2_set($k, max($r[0], min($r[1], (int)$in[$k])));
        if (isset($in['cutoff'])) b2_set('cutoff', b2_clean_time($in['cutoff'], '19:00'));
        foreach (['pilot', 'ai', 'trust', 'scan'] as $k) if (isset($in[$k])) b2_set($k, !empty($in[$k]) ? '1' : '0');
        if (isset($in['mode'])) { $m = (string)$in['mode']; if (!in_array($m, ['on', 'off'], true)) json_response(['error' => 'Bad mode.'], 422); b2_set('mode', $m); }
        if (isset($in['live_sections'])) { $l = []; foreach ((array)$in['live_sections'] as $c) if (isset(b2_sections($pdo)[(string)$c])) $l[] = (string)$c; b2_set('live_sections', implode(',', $l)); b2_log('switch', 'b2', 'live sections: ' . implode(',', $l), $by); }
        json_response(['ok' => true, 'message' => 'Saved.']);
    }
    case 'bd.b2_health': { $mgr(); json_response(b2_health($pdo)); }
    case 'bd.b2_scan': { $adm(); $post(); $r = b2_scan($pdo); b2_state_put($pdo, 'scan_done', b2_day()); json_response(['ok' => true, 'result' => $r, 'message' => $r['raised'] ? $r['raised'] . ' new problem' . ($r['raised'] === 1 ? '' : 's') . ' raised. See Needs you.' : 'Scanned ' . $r['looked'] . ' numbers. Nothing new to raise.']); }
    /* ----- the Boss panel (one row of tabs) ----- */
    case 'bd.b2_today_plus': { $mgr(); json_response(b2_today_plus($pdo, $w)); }
    case 'bd.b2_loads': { $mgr(); json_response(['loads' => b2_loads($pdo, $w), 'cap' => b2_cap(), 'kept' => b2_accountability($pdo, $w)['people']]); }
    case 'bd.b2_accountability': { $mgr(); $a = b2_accountability($pdo, $w); json_response(['rows' => $a['rows'], 'people' => $a['people'], 'cap' => b2_cap()]); }
    case 'bd.b2_chain': {
        $mgr(); $code = (string)($_GET['section'] ?? $in['section'] ?? ''); if ($code !== '') { if (!$secOk($code)) json_response(['error' => 'Not your section.'], 403); $v = b2_chain_view($pdo, $code); json_response(['chain' => $v]); }
        $o = []; foreach (b2_chains() as $c => $d) if ($secOk($c)) $o[] = b2_chain_view($pdo, $c); json_response(['chains' => $o]);
    }
    case 'bd.b2_chain_set': { $mgr(); $post(); $code = (string)($in['section'] ?? ''); if (!$secOk($code)) json_response(['error' => 'Not your section.'], 403); $r = b2_chain_set($pdo, $code, (string)($in['step'] ?? ''), (string)($in['state'] ?? ''), $by); $r[3] = ['chain' => b2_chain_view($pdo, $code)]; $out($r); }
    case 'bd.b2_notes_clear': { $mgr(); $post(); $out(b2_notes_clear($pdo, $w, $by)); }
    case 'bd.b2_golive': { $mgr(); json_response(b2_golive($pdo, $w)); }
    case 'bd.b2_golive_set': { $adm(); $post(); $out(b2_golive_set($pdo, $w, !empty($in['all']), $by)); }
    case 'bd.b2_strip': { json_response(b2_strip($pdo, $w, (string)($in['page'] ?? $_GET['page'] ?? ''), $me)); }
    case 'bd.b2_tick': { $adm(); json_response(['ok' => true, 'result' => b2_tick($pdo, time(), true)]); }
    /* ----- Commission 2 (preview only; admin) ----- */
    case 'bd.b2_cm_view': { $adm(); if (function_exists('price_unify_once')) price_unify_once($pdo); $ch = cm_chart($pdo); $off = cm_offers($pdo); $bo = cm_boosts($pdo); $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? (string)$_GET['day'] : b2_day();
        $dr = cm_day($pdo, $day, $ch, $off, $bo); $ppl = []; foreach (cm_emps($pdo) as $id => $e) $ppl[] = ['id' => $id, 'name' => trim((string)$e['full_name'])];
        json_response(['unified' => (string)setting_get($pdo, 'price_unify_v1', '') === '1', 'day' => $day, 'chart' => $ch, 'offers' => $off, 'boosts' => $bo, 'people' => $ppl, 'result' => $dr, 'needs' => cm_needs($pdo, $ch, $off, $dr)]); }
    case 'bd.b2_cm_recount': { $adm(); json_response(cm_recount($pdo, max(1, min(31, (int)($_GET['days'] ?? $in['days'] ?? 10))), (string)($_GET['end'] ?? $in['end'] ?? ''))); }
    case 'bd.b2_cm_chart_save': {   // ONE price chart: prices are edited only in Learn & Setup → Pricing; this saves the commission RULES (and the "I checked" tick)
        $adm(); $post(); $cur = cm_chart($pdo);
        if ((string)setting_get($pdo, 'price_unify_v1', '') === '1') {
            $raw = json_decode((string)setting_get($pdo, 'cm_chart', ''), true); if (!is_array($raw)) $raw = [];
            $n = function ($k, $lo, $hi, $def) use ($in, $cur) { $v = $in[$k] ?? ($cur[$k] ?? $def); return max($lo, min($hi, round((float)$v, 2))); };
            $tiers = []; foreach ((array)($in['tiers'] ?? $cur['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; } usort($tiers, function ($a2, $b2) { return $a2[0] <=> $b2[0]; });
            $c = $raw; $c['floor'] = $n('floor', 0, 10, 2.8); $c['codepct'] = $n('codepct', 0, 100, 70); $c['nocode'] = $n('nocode', 0, 100, 50); $c['prepaid'] = $n('prepaid', 0, 20, 2); $c['bonus_max'] = $n('bonus_max', 1, 100, 50); $c['tiers'] = $tiers ?: [[30, 30], [40, 40], [60, 50]];
            $c['reviewed'] = !empty($in['reviewed']) ? 1 : (int)($cur['reviewed'] ?? 0); $c['updated_at'] = bd_now(); $c['updated_by'] = $by; setting_set($pdo, 'cm_chart', json_encode($c));
            b2_log('commission', 'chart', 'commission rules saved', $by); if (function_exists('kb_touch')) kb_touch($pdo, 'Commission rules');
            json_response(['ok' => true, 'message' => 'Commission rules saved. The prices come from the Price Chart (Learn & Setup → Pricing).', 'chart' => cm_chart($pdo)]);
        }
        $r = cm_chart_clean((array)($in['chart'] ?? $in), $cur); if (!$r[0]) json_response(['error' => $r[1]], 422);
        $c = $r[0]; $c['reviewed'] = !empty($in['reviewed']) ? 1 : (int)($cur['reviewed'] ?? 0); $c['updated_at'] = bd_now(); $c['updated_by'] = $by; setting_set($pdo, 'cm_chart', json_encode($c)); b2_log('commission', 'chart', 'pricing chart saved', $by);
        json_response(['ok' => true, 'message' => 'Pricing saved.', 'chart' => $c]); }
    case 'bd.b2_cm_offer_add': { $adm(); $post(); $out(cm_offer_save($pdo, $in, $by)); }
    case 'bd.b2_cm_offer_stop': { $adm(); $post(); $out(cm_offer_stop($pdo, $gid(), $by)); }
    case 'bd.b2_cm_boost_add': { $adm(); $post(); $out(cm_boost_save($pdo, $in, $by)); }
    case 'bd.b2_cm_boost_stop': { $adm(); $post(); $out(cm_boost_stop($pdo, $gid())); }
    case 'bd.b2_rules_view': { $adm(); json_response(['ok' => true] + b2_links_view($pdo)); }
    case 'bd.b2_rules_pass': { $adm(); $post(); $n = b2_links_pass($pdo, null, $by); json_response(['ok' => true, 'message' => $n ? implode(' ', $n) : 'Everything is already in sync.', 'notes' => $n]); }
    case 'bd.b2_rules_link': {   // one tap: make a task and an old rule ONE rule
        $adm(); $post(); $tid = (int)($in['task_id'] ?? 0); $bk = (string)($in['book'] ?? ''); $rf = (string)($in['ref'] ?? '');
        $it = b2_row($pdo, 'b2_items', $tid); if (!$it || !in_array($bk, ['dp', 'pr'], true) || !b2_rb_get($pdo, $bk, $rf)) json_response(['error' => 'That task or rule is not there.'], 404);
        b2_links_ensure($pdo); $pdo->prepare("INSERT IGNORE INTO b2_links (item_id, book, ref, charger, why, created_at) VALUES (?,?,?,?, 'manual', ?)")->execute([$tid, $bk, $rf, 'item', bd_now()]);
        $n = b2_links_pass($pdo, $tid, $by); b2_log('rule_link', (string)$tid, 'Linked to old rule ' . $bk . ':' . $rf . ' by ' . $by, $by);
        json_response(['ok' => true, 'message' => 'Linked. ' . implode(' ', $n)]);
    }
    case 'bd.b2_rules_unlink': {
        $adm(); $post(); $lid = (int)($in['id'] ?? 0); $L = b2_row($pdo, 'b2_links', $lid); if (!$L) json_response(['error' => 'Not found.'], 404);
        if ($L['prev_active'] !== null && (int)$L['prev_active'] === 1) b2_rb_set($pdo, $L['book'], $L['ref'], ['active' => 1]);
        $pdo->prepare("UPDATE b2_links SET why = 'off', snap = NULL, prev_active = NULL WHERE id = ?")->execute([$lid]); b2_log('rule_link', (string)$L['item_id'], 'Unlinked from old rule ' . $L['book'] . ':' . $L['ref'] . ' by ' . $by, $by);
        json_response(['ok' => true, 'message' => 'Unlinked. The old rule is back on its own.']);
    }
    case 'bd.b2_csv': {
        $mgr(); $sec = (string)($_GET['section'] ?? ''); if ($sec !== '' && !$secOk($sec)) json_response(['error' => 'Not your section.'], 403);
        $rows = b2_items_list($pdo, $w, ['section' => $sec, 'status' => 'all', 'limit' => 300]);
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="boss-engine-' . ($sec ?: 'all') . '-' . b2_day() . '.csv"');
        echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
        fputcsv($f, ['ID', 'Kind', 'Task', 'Section', 'Owner', 'Backup', 'When', 'Proof', 'Target', 'Unit', 'Status', 'State', 'Last 7 days', 'Penalty if missed']);
        foreach ($rows as $r) fputcsv($f, [$r['id'], $r['kind_label'], $r['title'], $r['section'], $r['seat'], $r['backup'], $r['due_label'], $r['proof_label'], $r['target'], $r['unit'], $r['status'], $r['state_label'], implode('', array_map(function ($d) { return ['done' => '✓', 'late' => 'L', 'missed' => 'x', 'under' => 'u', 'open' => '·', 'review' => '?', 'off' => '-'][$d['s']] ?? '-'; }, $r['strip'])), $r['pen_amount']]);
        fclose($f); exit;
    }
    }
}
