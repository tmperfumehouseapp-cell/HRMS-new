<?php
/**
 * dept.php — the "Departments" add-on for the HRMS Boss Engine (actions that start with "dp.").
 *
 * One shared core + four departments, all on top of the Boss Engine you already have:
 *   core            rules (amount, seat, grace, auto/approval), seats, penalties, appeals, My Day, track record, the Claude-asks Questions engine, punch-missed
 *   dept_dispatch.php   parcels: tracking number in 48 h, in transit by day 4
 *   dept_shops.php      display visits: 12-day round, photo count, "sold more, paid less", pins, calls
 *   dept_repairs.php    things to fix in every office (reported, given, done with photos)
 *   dept_custom.php     New penalty with Claude (Boss > Penalties)
 *   dept_boss.php       Summary page: who has what (track record), done strip, "OK, fixed", Claude boss chat (dp.hq_*)
 *   dept_supplies.php   pantry and consumables per office, buyer, supplier ledger, UPI screenshot
 *
 * Put ALL of them in the SAME folder as api.php and boss.php. Own tables (dp_*). PHP 7.4 safe.
 * Nothing goes live until you approve the topic in Boss > Questions ("This is right, make it live").
 * It READS employees, attendance, day_marks, leave_requests and the Boss Engine tables; penalties go through the HRMS penalty engine.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('DP_LOADED')) { return; }
if (!is_file(__DIR__ . '/boss.php')) { return; }
define('DP_LOADED', 1);
require_once __DIR__ . '/boss.php';
if (!function_exists('bd_inbox')) { return; }

/* ============================== tiny helpers ============================== */
function dp_get($k, $d = '') { global $pdo; try { $st = $pdo->prepare("SELECT svalue FROM dp_state WHERE skey = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function dp_put($k, $v) { global $pdo; $pdo->prepare("INSERT INTO dp_state (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]); }
function dp_inr($v) { return '₹' . number_format((float)$v, 0, '.', ','); }
function dp_dir() { $d = __DIR__ . '/uploads/dept/'; if (!is_dir($d)) { @mkdir($d, 0755, true); } if (is_dir($d) && !is_file($d . '.htaccess')) { @file_put_contents($d . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); @file_put_contents($d . 'index.html', ''); } return $d; }
/** save an uploaded picture (jpg/png/webp/heic-as-is) as a ≤1600 px JPEG. Returns [filename|null, error]. */
function dp_save_image($tmp, $prefix) {
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return [null, 'That file is not a picture (use a JPG or PNG).'];
    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg'; $dest = dp_dir() . $name;
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($im) {
            $w = imagesx($im); $h = imagesy($im); $m = max($w, $h);
            if ($m > 1600) { $s = 1600 / $m; $t = imagecreatetruecolor((int)($w * $s), (int)($h * $s)); imagecopyresampled($t, $im, 0, 0, 0, 0, (int)($w * $s), (int)($h * $s), $w, $h); imagedestroy($im); $im = $t; }
            $ok = @imagejpeg($im, $dest, 82); imagedestroy($im);
            if ($ok) return [$name, ''];
        }
    }
    if (@move_uploaded_file($tmp, $dest) || @copy($tmp, $dest)) return [$name, ''];
    return [null, 'Could not save the picture on the server.'];
}
function dp_file_out($name) {
    $f = dp_dir() . basename((string)$name);
    if ($name === '' || !is_file($f)) json_response(['error' => 'File not found.'], 404);
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); readfile($f); exit;
}
function dp_month_range($ym = null) { $t = $ym ? strtotime($ym . '-01') : strtotime(date('Y-m-01')); return [date('Y-m-01', $t), date('Y-m-t', $t)]; }

/* ============================== tables ============================== */
function dp_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    if (function_exists('bd_ensure')) { bd_ensure($pdo); }
    $flag = sys_get_temp_dir() . '/hrms_dp_tables_v1_' . substr(md5(__DIR__), 0, 8);
    $have = is_file($flag);
    if ($have) { try { $pdo->query("SELECT 1 FROM dp_items LIMIT 1"); $pdo->query("SELECT 1 FROM dp_q LIMIT 1"); } catch (Throwable $e) { $have = false; } }
    if (!$have) {
        $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_state (skey VARCHAR(60) PRIMARY KEY, svalue TEXT NULL)$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_rules (
            id INT AUTO_INCREMENT PRIMARY KEY, rkey VARCHAR(40) NOT NULL UNIQUE, title VARCHAR(160) NOT NULL, topic VARCHAR(12) NOT NULL DEFAULT 'dispatch',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0, per VARCHAR(10) NOT NULL DEFAULT 'miss', decide TINYINT NOT NULL DEFAULT 0,
            seat_code VARCHAR(60) NULL, when_text VARCHAR(120) NULL, mode VARCHAR(8) NOT NULL DEFAULT 'auto', grace_min SMALLINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, updated_at DATETIME NULL
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_items (
            id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, rkey VARCHAR(40) NULL, ref_key VARCHAR(120) NOT NULL UNIQUE, seat_code VARCHAR(60) NULL,
            owner_emp INT NULL, title VARCHAR(200) NOT NULL, detail VARCHAR(400) NULL, task_date DATE NOT NULL, due_at DATETIME NULL, status VARCHAR(8) NOT NULL DEFAULT 'open',
            done_at DATETIME NULL, proof_path VARCHAR(160) NULL, payload TEXT NULL, qty INT NOT NULL DEFAULT 1,
            pen_amount DECIMAL(10,2) NOT NULL DEFAULT 0, pen_state VARCHAR(8) NOT NULL DEFAULT 'none', pen_id INT NULL, pen_note VARCHAR(255) NULL, appeal_note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL, INDEX (owner_emp, task_date), INDEX (status, due_at), INDEX (kind, task_date), INDEX (rkey, pen_state)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_topics (topic VARCHAR(12) PRIMARY KEY, name VARCHAR(80) NOT NULL, state VARCHAR(10) NOT NULL DEFAULT 'asking', approved_by VARCHAR(80) NULL, approved_at DATETIME NULL)$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_q (
            id INT AUTO_INCREMENT PRIMARY KEY, topic VARCHAR(12) NOT NULL, qkey VARCHAR(30) NOT NULL UNIQUE, ord SMALLINT NOT NULL DEFAULT 0, state VARCHAR(10) NOT NULL DEFAULT 'open',
            answer TEXT NULL, answered_by VARCHAR(80) NULL, answered_at DATETIME NULL, source VARCHAR(8) NOT NULL DEFAULT 'you', removed_by VARCHAR(30) NULL, later_n TINYINT NOT NULL DEFAULT 0, INDEX (topic, state)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_proof_hash (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, rule_id INT NULL, ahash CHAR(64) NOT NULL, file VARCHAR(160) NULL, created_at DATETIME NOT NULL, INDEX (ahash), INDEX (emp_id))$E");
        @file_put_contents($flag, '1');
    }
    dp_seed_rules($pdo);
    dp_seed_topics($pdo);
    dp_seed_seats($pdo);
}

/* ============================== rules (what costs how much) ============================== */
function dp_rule_defaults() {
    // rkey => [title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, sort]
    return [
        'tracking_48h'  => ['Tracking number not assigned in 48 hours', 'dispatch', 50, 'parcel', 0, 'dispatch.tracking', 'Order time + 48 h', 'auto', 0, 10],
        'transit_day4'  => ['Parcel not in transit by day 4', 'dispatch', 0, 'parcel', 1, 'dispatch.lead', 'Day 4, 12 AM', 'auto', 0, 20],
        'visit_missed'  => ['Display visit missed in the 12-day round', 'shops', 0, 'miss', 1, 'retaildist.visitor.surat', 'Day 12', 'auto', 0, 40],
        'supply_missed' => ['Supply not bought on its day', 'supplies', 0, 'miss', 1, 'supplies.buyer', 'Next check', 'auto', 0, 50],
        'punch_missed'  => ['Punch in missed', 'punch', 100, 'day', 0, 'self', 'Shift start + grace', 'approve', 30, 60],
    ];
}
function dp_seed_rules(PDO $pdo) {
    try { $pdo->exec("DELETE FROM dp_rules WHERE rkey = 'money_unused'"); } catch (Throwable $e) {}   // Daily money is gone: its 9 PM rule goes with it
    $n = (int)$pdo->query("SELECT COUNT(*) FROM dp_rules")->fetchColumn();
    foreach (dp_rule_defaults() as $k => $d) {
        $st = $pdo->prepare("SELECT id FROM dp_rules WHERE rkey = ?"); $st->execute([$k]);
        if ($st->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO dp_rules (rkey, title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, sort_no, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$k, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9], bd_now()]);
    }
}
function dp_rule(PDO $pdo, $rkey) { $st = $pdo->prepare("SELECT * FROM dp_rules WHERE rkey = ?"); $st->execute([$rkey]); return $st->fetch() ?: null; }

/* ============================== seats ============================== */
/** Sections the departments need (created once) and the work seats inside them. */
function dp_seat_defs() {
    // code => [section code, title, kind]
    return [
        'dispatch.lead'              => ['dispatch', 'Dispatch lead', 'worker'],
        'dispatch.tracking'          => ['dispatch', 'Tracking number person', 'worker'],
        'retaildist.manager'         => ['retaildist', 'Retail distribution manager', 'worker'],
        'retaildist.visitor.surat'   => ['retaildist', 'Display visitor (Surat)', 'worker'],
        'retaildist.visitor.vadodara' => ['retaildist', 'Display visitor (Vadodara)', 'worker'],
        'supplies.buyer'             => ['supplies', 'Supplies buyer', 'worker'],
    ];
}
function dp_seed_seats(PDO $pdo) {
    $now = bd_now();
    foreach (['retaildist' => ['Retail Distribution', 'Display visits, collection and new displays in every city', ['retail distribution']], 'supplies' => ['Supplies', 'Pantry and consumables for every office and the store', ['supplies']]] as $code => $d) {
        $st = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $st->execute([$code]);
        if (!$st->fetchColumn()) {
            $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, $d[0], $d[1], '[]', 'dept', 'ready', $now]);
            bd_make_seats($pdo, (int)$pdo->lastInsertId(), $code, $d[0]);
        }
    }
    // dispatch and accounts normally exist from the Boss Engine; make them if somebody deleted them
    if (function_exists('bd_section_defs')) { $defs = bd_section_defs(); foreach (['dispatch', 'accounts'] as $code) { if (!isset($defs[$code])) continue; $st = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $st->execute([$code]); if (!$st->fetchColumn()) { $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, $defs[$code][0], $defs[$code][1], '[]', 'dept', 'ready', $now]); bd_make_seats($pdo, (int)$pdo->lastInsertId(), $code, $defs[$code][0]); } } }
    foreach (dp_seat_defs() as $code => $d) {
        $st = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $st->execute([$d[0]]); $sid = (int)$st->fetchColumn();
        if (!$sid) continue;
        $pdo->prepare("INSERT IGNORE INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([$sid, $code, $d[1], $d[2], $now]);
    }
}
function dp_seat_ensure(PDO $pdo, $code, $title, $sectionCode) {
    $st = $pdo->prepare("SELECT id FROM bd_seats WHERE code = ?"); $st->execute([$code]); $id = (int)$st->fetchColumn();
    if ($id) return $id;
    $s = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $s->execute([$sectionCode]); $sid = (int)$s->fetchColumn();
    $pdo->prepare("INSERT INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([$sid ?: null, $code, $title, 'worker', bd_now()]);
    return (int)$pdo->lastInsertId();
}
/** people holding a seat (by code). 'self' = nobody in particular. */
function dp_holders(PDO $pdo, $code) { $id = bd_seat_id($pdo, $code); return $id ? bd_holders($pdo, $id) : []; }
/** all seat codes that start with a prefix, e.g. 'supplies.buyer' */
function dp_seat_codes(PDO $pdo, $prefix) { $st = $pdo->prepare("SELECT code FROM bd_seats WHERE code = ? OR code LIKE ? AND active = 1 ORDER BY code"); $st->execute([$prefix, $prefix . '.%']); return array_map(function ($r) { return $r['code']; }, $st->fetchAll()); }
/** who gets the work of a seat today: the first holder who is not away, else a backup (boss.php fallback chain), else 0. */
function dp_owner_for(PDO $pdo, $seatCode, $date = null, $sectionCode = '') {
    $date = $date ?: bd_today();
    $hold = [];
    foreach (array_merge([$seatCode], $seatCode === 'supplies.buyer' ? dp_seat_codes($pdo, 'supplies.buyer') : []) as $c) foreach (dp_holders($pdo, $c) as $h) $hold[$h] = 1;
    foreach (array_keys($hold) as $h) { if (bd_away($pdo, $h, $date) === '') return (int)$h; }
    if ($hold) { /* everyone on the seat is away: boss.php fallback chain */
        $sec = $sectionCode !== '' ? $sectionCode : strtok($seatCode, '.');
        $st = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $st->execute([$sec]); $sid = (int)$st->fetchColumn();
        list($fb) = bd_fallback($pdo, ['section_id' => $sid, 'id' => 0], $date, array_keys($hold)); return (int)$fb;
    }
    return 0;
}
/** may this login work on a seat? Admin, HR, top boss, section boss/checker, or a holder of the seat (any seat with the same prefix for 'supplies.buyer'). */
function dp_can_seat(PDO $pdo, array $auth, $seatCode) {
    $w = bd_who($pdo, $auth);
    if ($w['admin'] || $w['top']) return true;
    $sec = strtok($seatCode, '.');
    $st = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $st->execute([$sec]); $sid = (int)$st->fetchColumn();
    if ($sid && in_array($sid, $w['sections'], true)) return true;
    $emp = (int)($auth['employee_id'] ?? 0); if (!$emp) return false;
    $codes = $seatCode === 'supplies.buyer' ? dp_seat_codes($pdo, 'supplies.buyer') : [$seatCode];
    foreach ($codes as $c) { if (in_array($emp, dp_holders($pdo, $c), true)) return true; }
    return false;
}
function dp_need_seat(PDO $pdo, array $auth, $seatCode, $what = 'this') {
    if (!dp_can_seat($pdo, $auth, $seatCode)) json_response(['error' => 'Only the person holding this seat (or a boss) can do ' . $what . '.'], 403);
}
function dp_manager(PDO $pdo, array $auth) { return bd_who($pdo, $auth); }

/* ============================== track record (after every task) ============================== */
function dp_track(PDO $pdo, $empId, $days = 30) {
    $from = date('Y-m-d', strtotime('-' . (int)$days . ' days')); $to = bd_today();
    $done = 0; $missed = 0;
    try { $q = $pdo->prepare("SELECT status, COUNT(*) c FROM bd_tasks WHERE owner_emp = ? AND task_date >= ? AND task_date < ? AND status IN ('done','missed') GROUP BY status"); $q->execute([(int)$empId, $from, $to]); foreach ($q->fetchAll() as $r) { if ($r['status'] === 'done') $done += (int)$r['c']; else $missed += (int)$r['c']; } } catch (Throwable $e) {}
    try { $q = $pdo->prepare("SELECT status, COUNT(*) c FROM dp_items WHERE owner_emp = ? AND task_date >= ? AND task_date < ? AND status IN ('done','missed') GROUP BY status"); $q->execute([(int)$empId, $from, $to]); foreach ($q->fetchAll() as $r) { if ($r['status'] === 'done') $done += (int)$r['c']; else $missed += (int)$r['c']; } } catch (Throwable $e) {}
    $pen = 0.0; try { $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id = ? AND source = 'boss' AND status = 'applied' AND penalty_date >= ?"); $q->execute([(int)$empId, $from]); $pen = (float)$q->fetchColumn(); } catch (Throwable $e) {}
    $tot = $done + $missed;
    return ['tasks' => $tot, 'done' => $done, 'missed' => $missed, 'on_time' => $tot ? (int)round(100 * $done / $tot) : null, 'penalties' => $pen];
}
function dp_track_line(array $t) { return $t['tasks'] ? $t['on_time'] . '% on time (' . $t['done'] . ' of ' . $t['tasks'] . '), ' . dp_inr($t['penalties']) . ' penalties' : 'no track record yet'; }
/** Claude's suggestion for a seat: the best track record among active people, preferring the seat's department. Never assigns. */
function dp_suggest(PDO $pdo, $seatCode, $limit = 3) {
    $cands = []; $dept = 0;
    $sec = strtok($seatCode, '.');
    $st = $pdo->prepare("SELECT s.dept_ids FROM bd_sections s WHERE s.code = ?"); $st->execute([$sec]); $dids = array_filter(explode(',', (string)$st->fetchColumn()));
    foreach (bd_employees($pdo) as $e) {
        $t = dp_track($pdo, $e['id']);
        $score = ($t['on_time'] === null ? 50 : $t['on_time']) - min(30, $t['penalties'] / 20) + (in_array((string)$e['department_id'], $dids, true) ? 15 : 0) + min(10, $t['tasks'] / 5);
        $cands[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'dept' => $e['dept'], 'score' => $score, 'line' => dp_track_line($t), 'track' => $t];
    }
    usort($cands, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($cands, 0, $limit);
}

/* ============================== items (the daily obligations of the departments) ============================== */
/** make one item (once per ref_key). Returns the id (existing id when it was already there). */
function dp_item_make(PDO $pdo, $kind, $rkey, $refKey, $seatCode, $title, $detail, $date, $dueAt, array $payload = [], $owner = null, $qty = 1) {
    $st = $pdo->prepare("SELECT id FROM dp_items WHERE ref_key = ?"); $st->execute([$refKey]); $id = (int)$st->fetchColumn(); if ($id) return $id;
    if ($owner === null) $owner = $seatCode && $seatCode !== 'self' ? dp_owner_for($pdo, $seatCode, $date) : 0;
    $pdo->prepare("INSERT INTO dp_items (kind, rkey, ref_key, seat_code, owner_emp, title, detail, task_date, due_at, payload, qty, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$kind, $rkey, $refKey, $seatCode, $owner ?: null, mb_substr($title, 0, 200), mb_substr((string)$detail, 0, 400), $date, $dueAt, bd_json($payload), max(1, (int)$qty), bd_now()]);
    return (int)$pdo->lastInsertId();
}
function dp_item(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM dp_items WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function dp_item_done(PDO $pdo, $id, $by = '') { $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ? WHERE id = ? AND status = 'open'")->execute([bd_now(), (int)$id]); }
function dp_item_view(PDO $pdo, array $r) {
    $rule = $r['rkey'] ? dp_rule($pdo, $r['rkey']) : null;
    return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'rkey' => $r['rkey'], 'title' => $r['title'], 'detail' => $r['detail'], 'date' => $r['task_date'], 'due_at' => $r['due_at'], 'due_label' => $r['due_at'] ? date('D g:i A', strtotime($r['due_at'])) : '',
        'status' => $r['status'], 'seat' => $r['seat_code'], 'owner_id' => $r['owner_emp'] ? (int)$r['owner_emp'] : null, 'owner' => $r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : '', 'qty' => (int)$r['qty'],
        'pen_state' => $r['pen_state'], 'pen_amount' => (float)$r['pen_amount'], 'pen_note' => $r['pen_note'], 'rule' => $rule ? $rule['title'] : '', 'payload' => bd_unjson($r['payload']), 'has_proof' => !empty($r['proof_path'])];
}

/* ============================== topics that are live ============================== */
function dp_topic_state(PDO $pdo, $topic) { $st = $pdo->prepare("SELECT state FROM dp_topics WHERE topic = ?"); $st->execute([$topic]); $s = $st->fetchColumn(); return $s ?: 'asking'; }
/** a topic goes live only after the boss said "This is right, make it live". 'punch' follows the same rule. */
function dp_live(PDO $pdo, $topic) { return dp_topic_state($pdo, $topic) === 'live'; }

/* ============================== penalties ============================== */
/**
 * Raise the penalty for a missed item.
 * - rule inactive, or amount 0 and "you decide": no money, but Boss is told ONCE per rule to set the amount.
 * - Test mode / Staff-see-tasks mode: only recorded ("shadow"), nobody is charged or told.
 * - Live: auto rules take the money now (within the Boss Engine day/month limit); approval rules wait in the Boss inbox.
 * Returns the new pen_state.
 */
function dp_penalize(PDO $pdo, $itemId, $rkey, $count = 1) {
    $it = dp_item($pdo, $itemId); $rule = dp_rule($pdo, $rkey);
    if (!$it || !$rule || (int)$rule['active'] !== 1) return 'none';
    if (!$it['owner_emp']) { bd_inbox('dp_note', 'Nobody holds the seat: ' . $it['title'], 'A penalty rule fired but no one is assigned to the seat "' . ($it['seat_code'] ?: '') . '". Assign the seat in Boss > Setup.', [], null, null, 'dp_noseat:' . $rkey . ':' . bd_today()); }
    $amt = (float)$rule['amount'] * (($rule['per'] === 'parcel') ? max(1, (int)$count) : 1);
    if ($amt <= 0) {
        if ((int)$rule['decide'] === 1) bd_inbox('dp_note', 'Set the penalty amount: ' . $rule['title'], 'This rule has no amount yet, so nobody is charged. Set it in Boss > Penalties.', ['rkey' => $rkey], null, null, 'dp_decide:' . $rkey);
        $pdo->prepare("UPDATE dp_items SET pen_state = 'none', pen_note = ? WHERE id = ?")->execute(['amount not set', $itemId]);
        return 'none';
    }
    $note = $rule['title'] . ($rule['per'] === 'parcel' ? ' (' . (int)$count . ' parcel' . ((int)$count === 1 ? '' : 's') . ')' : '');
    $mode = bd_mode();
    if (!$it['owner_emp'] || $mode !== 'live') { $pdo->prepare("UPDATE dp_items SET pen_state = 'shadow', pen_amount = ?, pen_note = ? WHERE id = ?")->execute([$amt, $note, $itemId]); return 'shadow'; }
    $pdo->prepare("UPDATE dp_items SET pen_state = 'pending', pen_amount = ?, pen_note = ? WHERE id = ?")->execute([$amt, $note, $itemId]);
    if ($rule['mode'] === 'auto') { list($ok) = dp_apply($pdo, $itemId, 'Departments'); return $ok ? 'applied' : 'pending'; }
    bd_inbox('dp_pen', bd_emp_name($pdo, $it['owner_emp']) . ': ' . $rule['title'], 'Penalty ' . dp_inr($amt) . '. ' . $it['title'] . ($it['detail'] ? ' · ' . $it['detail'] : ''), ['item_id' => (int)$itemId], null, null, 'dp_pen:' . $itemId);
    return 'pending';
}
/** take the money through the HRMS penalty engine (same day/month limits as the Boss Engine). [ok, message] */
function dp_apply(PDO $pdo, $itemId, $by) {
    $it = dp_item($pdo, $itemId); if (!$it) return [false, 'Item not found.'];
    if (!in_array($it['pen_state'], ['pending', 'shadow', 'appealed'], true)) return [false, 'This penalty is already ' . $it['pen_state'] . '.'];
    if (!function_exists('penalty_create')) return [false, 'The HRMS penalty engine was not found.'];
    $emp = function_exists('penalty_employee') ? penalty_employee($pdo, (int)$it['owner_emp']) : bd_emp($pdo, $it['owner_emp']);
    if (!$emp) return [false, 'Employee not found.'];
    $amt = (float)$it['pen_amount'];
    $day = date('Y-m-d'); $m1 = date('Y-m-01'); $m2 = date('Y-m-t');
    $left = min((float)bd_cfg('pen_cap_day') - bd_month_total($pdo, $it['owner_emp'], $day, $day), (float)bd_cfg('pen_cap_month') - bd_month_total($pdo, $it['owner_emp'], $m1, $m2));
    if ($left <= 0) { $pdo->prepare("UPDATE dp_items SET pen_state = 'capped' WHERE id = ?")->execute([$itemId]); return [true, 'Not applied: ' . bd_emp_name($pdo, $it['owner_emp']) . ' has reached the penalty limit (Boss > Setup).']; }
    $amt = min($amt, $left);
    $pid = penalty_create($pdo, $emp, null, (string)($it['pen_note'] ?: $it['title']), $it['task_date'], $amt, 'boss', trim($it['title'] . ' (' . $it['task_date'] . ')'), $by, 'dp:' . $itemId, true);
    $pdo->prepare("UPDATE dp_items SET pen_state = 'applied', pen_id = ?, pen_amount = ? WHERE id = ?")->execute([$pid ?: null, $amt, $itemId]);
    bd_log('penalty', 'dp' . $itemId, 'applied ' . dp_inr($amt) . ' to ' . $emp['full_name'] . ' (' . $it['title'] . ')', $by);
    return [true, 'Penalty of ' . dp_inr($amt) . ' given to ' . $emp['full_name'] . '.'];
}
/** a missed item: mark it, raise its penalty and tell the owner (not in test mode). */
function dp_miss(PDO $pdo, $itemId, $rkey, $count = 1, $note = '') {
    $it = dp_item($pdo, $itemId); if (!$it || $it['status'] !== 'open') return;
    $pdo->prepare("UPDATE dp_items SET status = 'missed' WHERE id = ?")->execute([$itemId]);
    $state = $rkey ? dp_penalize($pdo, $itemId, $rkey, $count) : 'none';
    bd_log('missed', 'dp' . $itemId, $it['title'] . ' - ' . bd_emp_name($pdo, $it['owner_emp']) . ($note ? ' - ' . $note : ''), 'Departments');
    if ($it['owner_emp'] && bd_mode() !== 'shadow') bd_push($pdo, $it['owner_emp'], 'Missed: ' . $it['title'], 'This was not done in time.' . (in_array($state, ['pending', 'applied'], true) ? ' A penalty follows.' : ''));
}
/** called by boss.php bd.inbox_act for kinds that start with dp_ */
function dp_inbox_act(PDO $pdo, array $it, $act, array $w, $by) {
    $p = bd_unjson($it['payload']); $msg = 'Done.'; $close = 'done';
    $item = isset($p['item_id']) ? dp_item($pdo, (int)$p['item_id']) : null;
    if ($act === 'dismiss' || $it['kind'] === 'dp_note') { $close = 'dismissed'; $msg = 'OK.'; }
    elseif ($it['kind'] === 'dp_pen') {
        if (!$item) json_response(['error' => 'Item is gone.'], 404);
        if ($item['owner_emp'] && (int)$item['owner_emp'] === (int)$w['emp'] && !$w['admin']) json_response(['error' => 'You cannot decide your own penalty.'], 403);
        if ($act === 'approve') {
            if (bd_mode() !== 'live') json_response(['error' => 'The Boss Engine is not in LIVE mode, so no money is taken yet.'], 409);
            list($ok, $m) = dp_apply($pdo, $item['id'], $by); if (!$ok) json_response(['error' => $m], 409); $msg = $m;
        } elseif ($act === 'reject') { $pdo->prepare("UPDATE dp_items SET pen_state = 'waived' WHERE id = ?")->execute([$item['id']]); $msg = 'No penalty. Closed.'; bd_log('penalty', 'dp' . $item['id'], 'waived before applying', $by); }
        else json_response(['error' => 'Choose approve or reject.'], 422);
    } elseif ($it['kind'] === 'dp_appeal') {
        if (!$item) json_response(['error' => 'Item is gone.'], 404);
        if ($item['owner_emp'] && (int)$item['owner_emp'] === (int)$w['emp'] && !$w['admin']) json_response(['error' => 'You cannot decide your own appeal.'], 403);
        if ($act === 'approve') {
            if (!empty($item['pen_id'])) {
                $pdo->prepare("UPDATE employee_penalties SET status = 'waived', resolved_by = ?, resolved_at = ?, resolve_note = 'Appeal accepted (Departments)' WHERE penalty_id = ? AND status = 'applied'")->execute([$by, bd_now(), $item['pen_id']]);
                if (function_exists('penalty_notify')) { try { penalty_notify($pdo, (int)$item['owner_emp'], (int)$item['pen_id'], 'waived', 'Penalty waived', 'Your appeal for "' . $item['title'] . '" was accepted. The penalty is cancelled.'); } catch (Throwable $e) {} }
            }
            $pdo->prepare("UPDATE dp_items SET pen_state = 'waived' WHERE id = ?")->execute([$item['id']]); $msg = 'Appeal accepted. Penalty cancelled.';
            bd_push($pdo, $item['owner_emp'], 'Appeal accepted', 'Your appeal for "' . $item['title'] . '" was accepted.');
        } elseif ($act === 'reject') {
            $back = !empty($item['pen_id']) ? 'applied' : (bd_mode() === 'live' ? 'pending' : 'shadow');
            $pdo->prepare("UPDATE dp_items SET pen_state = ? WHERE id = ?")->execute([$back, $item['id']]); $msg = 'Appeal declined.';
            bd_push($pdo, $item['owner_emp'], 'Appeal declined', 'Your appeal for "' . $item['title'] . '" was not accepted.');
        } else json_response(['error' => 'Choose approve or reject.'], 422);
    } elseif ($it['kind'] === 'dp_punchout' && function_exists('dp_po_inbox_act')) { list($msg, $close) = dp_po_inbox_act($pdo, $it, $act, $w, $by);
    } else { $close = 'done'; }
    $pdo->prepare("UPDATE bd_inbox SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?")->execute([$close, bd_now(), $by, (int)$it['id']]);
    json_response(['ok' => true, 'message' => $msg]);
}
function dp_badge_open(PDO $pdo, $empId) {
    if (!$empId) return 0;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_items WHERE owner_emp = ? AND status = 'open' AND task_date = ?"); $q->execute([(int)$empId, bd_today()]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function dp_has_items(PDO $pdo, $empId) {
    if (!$empId) return false;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_items WHERE owner_emp = ? AND task_date >= ?"); $q->execute([(int)$empId, date('Y-m-d', strtotime('-14 days'))]); return (int)$q->fetchColumn() > 0; } catch (Throwable $e) { return false; }
}

/* ============================== punch missed ============================== */
/** after shift start + grace, a person who normally punches in and has not: one item + penalty per day. Only when the "punch" topic is live. */
function dp_punch_tick(PDO $pdo, $now) {
    if (!dp_live($pdo, 'punch')) return 0;
    $rule = dp_rule($pdo, 'punch_missed'); if (!$rule || (int)$rule['active'] !== 1) return 0;
    $date = date('Y-m-d', $now); $n = 0;
    $shift = function_exists('emp_shift_map') ? (function () use ($pdo, $date) { try { return emp_shift_map($pdo, $date); } catch (Throwable $e) { return []; } })() : [];
    $grace = max(0, (int)$rule['grace_min']);
    foreach (bd_employees($pdo) as $e) {
        $id = (int)$e['id'];
        $ss = $shift[$id]['shift_start'] ?? '10:00'; if (!preg_match('/^\d{2}:\d{2}$/', (string)$ss)) $ss = '10:00';
        // Punch rule: "no rule" people are never marked missed; "hours only" people only after their latest punch-in time (none set = never).
        $pm = $shift[$id]['mode'] ?? 'fixed'; if ($pm === 'free') continue;
        if ($pm === 'hours') { $wt = (string)($shift[$id]['win_to'] ?? ''); if (!preg_match('/^\d{2}:\d{2}$/', $wt)) continue; $ss = $wt; }
        if ($now < strtotime($date . ' ' . $ss) + 60 * $grace) continue;
        if ($now > strtotime($date . ' ' . $ss) + 6 * 3600) continue;                         // after that it is an absence for HR, not a missed punch
        $why = bd_away($pdo, $id, $date); if (in_array($why, ['on leave', 'holiday', 'week off', 'left the company'], true)) continue;
        try {
            $u = $pdo->prepare("SELECT 1 FROM attendance WHERE employee_id = ? AND punch_date BETWEEN ? AND ? AND punch_in IS NOT NULL LIMIT 1");
            $u->execute([$id, date('Y-m-d', strtotime($date . ' -14 days')), date('Y-m-d', strtotime($date . ' -1 day'))]); if (!$u->fetchColumn()) continue;   // never punches: field staff, owner
            $a = $pdo->prepare("SELECT 1 FROM attendance WHERE employee_id = ? AND punch_date = ? AND punch_in IS NOT NULL LIMIT 1"); $a->execute([$id, $date]); if ($a->fetchColumn()) continue;
        } catch (Throwable $e2) { continue; }
        $ref = 'punch:' . $id . ':' . $date;
        $st = $pdo->prepare("SELECT id FROM dp_items WHERE ref_key = ?"); $st->execute([$ref]); if ($st->fetchColumn()) continue;
        $iid = dp_item_make($pdo, 'punch', 'punch_missed', $ref, 'self', 'Punch in missed', 'Shift ' . $ss . ' + ' . $grace . ' min grace', $date, date('Y-m-d H:i:s', $now), [], $id);
        dp_miss($pdo, $iid, 'punch_missed', 1, 'no punch in');
        bd_inbox('dp_note', bd_emp_name($pdo, $id) . ' did not punch in', 'Shift ' . $ss . '. The proof steps were not finished or he did not come.', ['emp_id' => $id], null, null, 'dp_punchn:' . $ref);
        $n++;
    }
    return $n;
}

/** dp_proof_hash gets its fine-fingerprint column once, safely (an old server without the column keeps working: the look-alike check is skipped) */
function dp_fhash_ready(PDO $pdo) {
    static $ok = null; if ($ok !== null) return $ok;
    try { $st = $pdo->query("SHOW COLUMNS FROM dp_proof_hash LIKE 'fhash'"); if (!$st->fetch()) $pdo->exec("ALTER TABLE dp_proof_hash ADD COLUMN fhash VARCHAR(256) NULL"); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** Refusals of a screenshot (Claude said no) for one person and one step, today: after two, the third try is let through (see dp_proof_screen_check). */
function dp_rej_ready(PDO $pdo) {
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS dp_proof_rej (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, rule_id INT NOT NULL, day DATE NOT NULL, created_at DATETIME NOT NULL, KEY idx_dp_rej (emp_id, rule_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('dp_proof_rej: ' . $e->getMessage()); return $ok = false; }
}
function dp_rej_count(PDO $pdo, $empId, $ruleId) {
    if (!dp_rej_ready($pdo)) return 0;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_proof_rej WHERE emp_id = ? AND rule_id = ? AND day = ?"); $q->execute([(int)$empId, (int)$ruleId, date('Y-m-d')]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function dp_rej_add(PDO $pdo, $empId, $ruleId) {
    if (!dp_rej_ready($pdo)) return;
    try { $pdo->prepare("INSERT INTO dp_proof_rej (emp_id, rule_id, day, created_at) VALUES (?,?,?,?)")->execute([(int)$empId, (int)$ruleId, date('Y-m-d'), date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
}

/* ============================== screenshots: look-alike and "is this really the comment" ============================== */
/**
 * Called from api.php proofs.upload (installer adds the call) after the exact-copy check.
 * Returns '' when fine, or a plain-English reason the screenshot is not accepted.
 *   1. look-alike: the same picture re-saved, cropped a little or edited (average hash, up to 5 of 64 bits apart) already used by anyone in the last 60 days
 *   2. Claude reads it: a comment, on our page, from this person, and nothing cut off  (only when the AI helper exists; AI down = accepted and marked for a person)
 */
function dp_proof_screen_check(PDO $pdo, $empId, array $item, $absPath) {
    try { dp_ensure($pdo); } catch (Throwable $e) { return ''; }
    $kind = (string)($item['kind'] ?? 'custom');
    if (!in_array($kind, ['instagram', 'youtube'], true)) return '';
    $h = function_exists('bd_ahash') ? bd_ahash($absPath) : null;
    $f = (function_exists('bd_fhash') && dp_fhash_ready($pdo)) ? bd_fhash($absPath) : null;
    $ruleId = (int)($item['rule_id'] ?? 0);
    if ($f) {
        // Look-alike = the SAME person sending a screenshot from an EARLIER day for this same step. Everyone's screenshot of "today's reel" looks alike
        // by design, so other people's screenshots are never a reason to refuse (Claude below checks that the comment is really there).
        $q = $pdo->prepare("SELECT fhash, created_at FROM dp_proof_hash WHERE emp_id = ? AND rule_id = ? AND fhash IS NOT NULL AND created_at >= ? AND created_at < ?");
        $q->execute([(int)$empId, $ruleId, date('Y-m-d H:i:s', strtotime('-60 days')), date('Y-m-d 00:00:00')]);
        foreach ($q->fetchAll() as $r) {
            if (bd_fdist($f, $r['fhash']) <= 48) {
                bd_inbox('dp_note', bd_emp_name($pdo, $empId) . ' sent a screenshot that looks like an old one', 'It matches his own screenshot from ' . date('D j M', strtotime($r['created_at'])) . '. Not accepted.', ['emp_id' => (int)$empId], null, null, 'dp_dupshot:' . $empId . ':' . date('Ymd'));
                return 'This screenshot looks the same as the one you sent on ' . date('D j M', strtotime($r['created_at'])) . '. Comment on today\'s ' . ($kind === 'instagram' ? 'reel' : 'video') . ' and take a new screenshot.';
            }
        }
    }
    $why = '';
    if (dp_get('proof_ai', '1') === '1' && function_exists('convo_ai') && function_exists('bd_b64_small')) {
        $img = bd_b64_small($absPath);
        if ($img) {
            $name = bd_emp_name($pdo, $empId);
            $sys = "You check a screenshot an employee sends as proof that he/she commented on our company's " . ($kind === 'instagram' ? 'Instagram reel' : 'YouTube video') . ". "
                . "Say comment_visible=true if the screenshot shows the " . ($kind === 'instagram' ? 'reel' : 'video') . "'s comments and at least one comment is visible. "
                . "IMPORTANT: you cannot know the employee's own " . ($kind === 'instagram' ? 'Instagram' : 'YouTube') . " user name. It is a handle that is different from the name we give you, so NEVER reject because the name of a commenter does not match the employee's name, and never ask for it. "
                . "Say wrong_app=true only if it is clearly not " . ($kind === 'instagram' ? 'Instagram' : 'YouTube') . ". "
                . "Say edited=true only if it clearly looks edited (cropped to hide the date or user names, pasted text, or a photo of another screen with signs of fakery). "
                . "Give ONE short plain sentence 'reason' telling the employee what to fix. Be fair and lenient: normal screenshots pass.";
            $schema = ['type' => 'OBJECT', 'properties' => ['comment_visible' => ['type' => 'BOOLEAN'], 'wrong_app' => ['type' => 'BOOLEAN'], 'edited' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['comment_visible', 'wrong_app', 'edited']];
            try { $r = convo_ai($sys, [['text' => 'Employee: ' . $name . '. Screenshot:'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]], $schema, false, 400); } catch (Throwable $e) { $r = null; }
            if (is_array($r) && !empty($r[0]) && is_array($r[1])) {
                $v = $r[1];
                if (!empty($v['wrong_app']) || empty($v['comment_visible']) || !empty($v['edited'])) $why = trim((string)($v['reason'] ?? '')) ?: 'Claude could not see your comment in this screenshot. Take a new one that shows your comment.';
            }
        }
    }
    if ($why !== '') {
        // Never lock a person out of punching because of Claude: the first two refusals of the day for this step are shown, the THIRD try goes through and the boss is told to look.
        $n = dp_rej_count($pdo, $empId, $ruleId);
        if ($n >= 2) {
            try { bd_inbox('dp_note', bd_emp_name($pdo, $empId) . ' sent a screenshot Claude was not sure about (3rd try)', 'Accepted so that he/she can punch in. Claude said: ' . $why . ' Please look at the screenshot.', ['emp_id' => (int)$empId], null, null, 'dp_shotpass:' . $empId . ':' . $ruleId . ':' . date('Ymd')); } catch (Throwable $e) { }
            $why = '';
        } else { dp_rej_add($pdo, $empId, $ruleId); return $why; }
    }
    if ($f) { $pdo->prepare("INSERT INTO dp_proof_hash (emp_id, rule_id, ahash, fhash, created_at) VALUES (?,?,?,?,?)")->execute([(int)$empId, $ruleId, $h ?: str_repeat('0', 64), $f, bd_now()]); }
    elseif ($h) { $pdo->prepare("INSERT INTO dp_proof_hash (emp_id, rule_id, ahash, created_at) VALUES (?,?,?,?)")->execute([(int)$empId, $ruleId, $h, bd_now()]); }
    return '';
}

/* ============================== Claude asks (the Questions engine) ============================== */
function dp_topic_defs() { return ['dispatch' => 'Parcels and tracking', 'shops' => 'Retail displays', 'supplies' => 'Office supplies', 'punch' => 'Punch in and out']; }
/** Follow-up questions Claude writes after an answer live in dp_q too (qkey f_...): they need these extra columns. Added once, safely. */
function dp_q_migrate(PDO $pdo) {
    static $d = false; if ($d) return; $d = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM dp_q LIKE 'qtext'")->fetch()) {
            $pdo->exec("ALTER TABLE dp_q ADD COLUMN qtext TEXT NULL, ADD COLUMN qwhy TEXT NULL, ADD COLUMN qsug TEXT NULL, ADD COLUMN qtype VARCHAR(8) NULL, ADD COLUMN parent VARCHAR(30) NULL, ADD COLUMN depth TINYINT NOT NULL DEFAULT 0");
        }
        if (!$pdo->query("SHOW COLUMNS FROM dp_q LIKE 'qund'")->fetch()) {   // what Claude understood from the answer, and what it will do about it
            $pdo->exec("ALTER TABLE dp_q ADD COLUMN qund TEXT NULL, ADD COLUMN qwill TEXT NULL");
        }
    } catch (Throwable $e) { error_log('Questions migrate: ' . $e->getMessage()); }
}
function dp_seed_topics(PDO $pdo) {
    dp_q_migrate($pdo);
    foreach (dp_topic_defs() as $k => $n) $pdo->prepare("INSERT IGNORE INTO dp_topics (topic, name) VALUES (?,?)")->execute([$k, $n]);
    $bank = dp_question_bank();
    $have = []; foreach ($pdo->query("SELECT qkey FROM dp_q")->fetchAll() as $r) $have[$r['qkey']] = 1;
    foreach ($bank as $q) {
        if (isset($have[$q['k']])) continue;
        if (!empty($q['earlier'])) $pdo->prepare("INSERT INTO dp_q (topic, qkey, ord, state, answer, answered_by, answered_at, source) VALUES (?,?,?,?,?,?,?,?)")->execute([$q['t'], $q['k'], $q['ord'], 'removed', $q['earlier'], 'Jaimin', bd_now(), 'earlier']);
        else $pdo->prepare("INSERT INTO dp_q (topic, qkey, ord) VALUES (?,?,?)")->execute([$q['t'], $q['k'], $q['ord']]);
    }
}
/**
 * The question bank. k = key, t = topic, ord = order, q = question, why = why I ask, sug = my suggestion (shown as "My suggestion"),
 * type = confirm | text | person | amount | choice, choices = for 'choice', rule = for 'amount' (the penalty rule that gets the number),
 * seat = for 'person' (the seat the person gets), covers = questions that this answer makes unnecessary, earlier = already answered by you (text).
 */
function dp_question_bank() {
    $Q = [];
    $add = function ($t, $ord, $k, $q, array $o = []) use (&$Q) { $Q[] = array_merge(['t' => $t, 'ord' => $ord, 'k' => $k, 'q' => $q, 'type' => 'confirm', 'why' => '', 'sug' => '', 'covers' => [], 'choices' => [], 'earlier' => '', 'rule' => '', 'seat' => ''], $o); };
    /* ---- Parcels and tracking ---- */
    $add('dispatch', 1, 'd_clock', 'When does the clock start for a parcel?', ['earlier' => 'The order date. An order placed after 12 AM counts as the next day. Sundays are skipped. Cancelled and RTO orders are left out.']);
    $add('dispatch', 2, 'd_48', 'How long may an order wait for a tracking number?', ['earlier' => '48 hours from the order time. Claude warns the Dispatch seat before it runs out.']);
    $add('dispatch', 3, 'd_4day', 'By when must a parcel be in transit?', ['earlier' => 'Within 4 days (Sundays skipped). Day 4 ends at 12 AM.']);
    $add('dispatch', 4, 'd_pen48', 'What is the penalty when the tracking number is late?', ['earlier' => '₹50 per parcel, taken automatically from the seat holder. You can change it any time in Boss > Penalties.']);
    $add('dispatch', 5, 'd_seat_track', 'Who is the "Tracking number person"?', ['type' => 'person', 'seat' => 'dispatch.tracking', 'why' => 'The penalty for a late tracking number goes to this seat.']);
    $add('dispatch', 6, 'd_seat_lead', 'Who is the "Dispatch lead"?', ['type' => 'person', 'seat' => 'dispatch.lead', 'why' => 'The lead is charged when a parcel is not in transit by day 4.']);
    $add('dispatch', 7, 'd_pen4', 'How much is the penalty when a parcel is not in transit by day 4?', ['type' => 'amount', 'rule' => 'transit_day4', 'sug' => '₹50 per parcel, the same as the tracking penalty.', 'why' => 'You said you will decide this later. Until then nobody is charged.']);
    $add('dispatch', 8, 'd_warn', 'When should I warn the Dispatch seat?', ['sug' => 'At 36 hours and 44 hours with no tracking number (the second one also goes to you); on day 3 morning for parcels not in transit; on day 4 at 12 noon and 5 PM (also to you).', 'why' => 'A warning before the deadline is worth more than a penalty after it.']);
    $add('dispatch', 9, 'd_api', 'How do I get live tracking from the courier?', ['type' => 'text', 'sug' => 'I need your courier API (Shiprocket or other) and where its key is kept.', 'why' => 'Without it this page shows the numbers your orders already have, but "in transit" cannot be checked.']);
    /* ---- Retail displays ---- */
    $add('shops', 1, 'sh_full', 'How many bottles make a full display?', ['sug' => '24 bottles, 4 rows of 6. A photo with fewer than 22 bottles asks the salesperson to retake it.', 'why' => 'I looked at the display photos in your retail section: most show 24 bottles in 4 rows of 6.',
        'covers' => ['sh_def', 'sh_count', 'sh_google']]);
    $add('shops', 2, 'sh_def', 'What is a full display?', ['earlier' => 'A full bottle display, as in the reference photos of your retail section.']);
    $add('shops', 3, 'sh_count', 'Who counts the bottles?', ['earlier' => 'Claude counts them from the photo. The salesperson is asked to retake it on the spot when the count is short.']);
    $add('shops', 4, 'sh_google', 'Do I need a Google key for phone numbers and maps?', ['earlier' => 'Yes, a Google API key is fine. It is added in Boss > Setup (Maps and Places).']);
    $add('shops', 5, 'sh_rule', 'What if a shop owes more than the stock it holds?', ['earlier' => 'Stock value = bottles x price (for example 15 x ₹175). If the amount due is more than the stock value, the shop sold it and must pay now. If the goods are still in stock, it can pay later.']);
    $add('shops', 6, 'sh_round', 'How long is the visit round and how many shops a day?', ['earlier' => 'About 280 displays in Surat, one person, about 30 a day, a 12-day round. New displays join the list automatically.']);
    $add('shops', 7, 'sh_price', 'What is the default price for a 20 ml and a 50 ml bottle?', ['type' => 'text', 'sug' => 'Fill it in Setup > Retail prices. The shop price chart is used when a shop has no price of its own.', 'why' => 'Needed for the "sold more, paid less" rule.']);
    $add('shops', 8, 'sh_seat_vis', 'Who is the Display visitor for Surat?', ['type' => 'person', 'seat' => 'retaildist.visitor.surat', 'why' => 'He gets the daily list, the route and the missed-visit penalty.']);
    $add('shops', 9, 'sh_seat_mgr', 'Who is the Retail distribution manager?', ['type' => 'person', 'seat' => 'retaildist.manager', 'why' => 'The manager calls shops from the call list and gives new displays.']);
    $add('shops', 10, 'sh_pen', 'What is the penalty for a missed display visit?', ['type' => 'amount', 'rule' => 'visit_missed', 'why' => 'Until you set an amount nobody is charged.']);
    $add('shops', 11, 'sh_recheck', 'How often is a filled display photographed again?', ['sug' => 'Every visit (once in the 12-day round).', 'why' => 'That is what the round is for.']);
    $add('shops', 12, 'sh_big', 'Do bigger shops get a bigger display?', ['sug' => 'No. One display type for every shop, 24 bottles.', 'why' => 'Keeps the count rule simple.']);
    $add('shops', 13, 'sh_cities', 'Which cities after Surat and Vadodara?', ['type' => 'text', 'why' => 'Each city gets its own list, visitor seat and counts.']);
    /* ---- Office supplies ---- */
    $add('supplies', 1, 'su_places', 'Which places need supplies?', ['earlier' => 'Surat store, Oberon 1, 2 and 3, Nishal office and RJD Hub.']);
    $add('supplies', 2, 'su_learn', 'How do I know how much of each item to bring?', ['earlier' => 'I learn the items from the office video, then from the checker marking Ok, Low or Empty at each check. After 2 to 3 days I work out how often each item must come (every day, every 10 or 15 days).']);
    $add('supplies', 3, 'su_pay', 'How are suppliers paid?', ['earlier' => 'By UPI or ledger only, cash is avoided. Every ledger payment needs a payment screenshot. A cash entry tells the person to avoid cash.']);
    $add('supplies', 4, 'su_own', 'What if someone buys from his own pocket?', ['earlier' => 'It goes to reimbursement. The main admin panel pays back and attaches the transaction screenshot.']);
    $add('supplies', 5, 'su_items', 'Which items should I start with?', ['type' => 'text', 'sug' => 'Milk, ice, coffee, tea, hand wash, toilet cleaner, salt. I add more when the office video or the checker shows them.', 'why' => 'Milk and ice come every day; the rest I learn.']);
    $add('supplies', 6, 'su_buyer', 'Who is the buyer?', ['type' => 'person', 'seat' => 'supplies.buyer', 'sug' => 'I suggest from track record: the person who bought on time most often.', 'why' => 'You assign the buyer seat; I only suggest. One buyer can cover every office, or you can add a buyer per office in Offices > Supplies.']);
    $add('supplies', 7, 'su_suppliers', 'Who are the suppliers?', ['type' => 'text', 'why' => 'You will fill them in later in Offices > Supplies. Nothing is blocked until then.']);
    $add('supplies', 8, 'su_pen', 'What is the penalty when a supply is not bought on its day?', ['type' => 'amount', 'rule' => 'supply_missed', 'why' => 'Until you set an amount nobody is charged.']);
    /* ---- Punch in and out ---- */
    $add('punch', 1, 'pu_steps', 'Which steps must be done before Punch In?', ['sug' => 'Live shoe photo (camera only, no gallery), the Instagram comment screenshot, the YouTube comment screenshot, and the "before punch in" store photo. These are the ones you set in Punch-in Proofs, and the screen shows only them.', 'why' => 'The new My Day shows the same steps in one place.']);
    $add('punch', 2, 'pu_fake', 'What happens to a repeated or fake screenshot?', ['sug' => 'It is not accepted, Boss sees it with the reason, and the person cannot punch in until a real screenshot is sent.', 'why' => 'Same picture (even re-saved or cropped), not our page, no comment visible, or edited.']);
    $add('punch', 3, 'pu_grace', 'How many minutes after shift start before "Punch in missed"?', ['type' => 'amount', 'rule' => 'punch_missed', 'sug' => '30 minutes after shift start.', 'why' => 'After that the day is marked late, Boss is told and the penalty rule applies.']);
    $add('punch', 4, 'pu_pen', 'What is the penalty for a missed punch in, and does it need your approval?', ['sug' => '₹100, waits in the Boss inbox for your yes or no.', 'why' => 'Change the amount or switch it to automatic in Boss > Penalties.']);
    return $Q;
}
function dp_bank_index() { static $i = null; if ($i === null) { $i = []; foreach (dp_question_bank() as $q) $i[$q['k']] = $q; } return $i; }
function dp_q_rows(PDO $pdo, $topic = null) {
    $sql = "SELECT * FROM dp_q" . ($topic ? " WHERE topic = ?" : "") . " ORDER BY topic, ord";
    $st = $pdo->prepare($sql); $st->execute($topic ? [$topic] : []); return $st->fetchAll();
}
/** the question text and type for a row: from the fixed bank, or (for a follow-up Claude wrote) from the row itself. */
function dp_q_def(array $r) {
    $b = dp_bank_index()[$r['qkey']] ?? null; if ($b) return $b;
    if (strpos((string)$r['qkey'], 'f_') === 0 && !empty($r['qtext'])) {
        $t = ($r['qtype'] ?? '') === 'confirm' ? 'confirm' : 'text';
        return ['t' => $r['topic'], 'k' => $r['qkey'], 'q' => $r['qtext'], 'why' => (string)$r['qwhy'], 'sug' => (string)$r['qsug'], 'type' => $t, 'rule' => '', 'seat' => '', 'covers' => [], 'follow' => true];
    }
    return null;
}
function dp_q_view(array $r) {
    $b = dp_q_def($r); if (!$b) return null;
    return ['follow' => !empty($b['follow']), 'key' => $r['qkey'], 'topic' => $r['topic'], 'q' => $b['q'], 'why' => $b['why'], 'sug' => $b['sug'], 'type' => $b['type'], 'rule' => $b['rule'], 'seat' => $b['seat'], 'state' => $r['state'], 'answer' => $r['answer'], 'source' => $r['source'], 'later' => (int)$r['later_n'], 'removed_by' => $r['removed_by'], 'covers' => $b['covers'], 'und' => (string)($r['qund'] ?? ''), 'will' => (string)($r['qwill'] ?? '')];
}
/** what I understood so far, as plain sentences built from the answers (never invented). */
function dp_summary(PDO $pdo, $topic) {
    $a = []; foreach (dp_q_rows($pdo, $topic) as $r) if ($r['answer'] !== null && $r['answer'] !== '') $a[$r['qkey']] = $r['answer'];
    $rule = function ($k) use ($pdo) { $x = dp_rule($pdo, $k); return $x ? $x : null; };
    $seatLine = function ($code, $label) use ($pdo) { $h = dp_holders($pdo, $code); return $label . ': ' . ($h ? implode(', ', array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $h)) : 'nobody yet'); };
    $o = [];
    if ($topic === 'dispatch') {
        $r1 = $rule('tracking_48h'); $r2 = $rule('transit_day4');
        $o[] = 'The clock starts on the order date (after 12 AM counts as the next day, Sundays skipped, cancelled and RTO left out).';
        $o[] = 'A tracking number must exist within 48 hours' . ($r1 ? ' (penalty ' . dp_inr($r1['amount']) . ' per parcel, ' . ($r1['mode'] === 'auto' ? 'taken automatically' : 'after your approval') . ')' : '') . '.';
        $o[] = 'A parcel must be in transit within 4 days' . ($r2 && (float)$r2['amount'] > 0 ? ' (penalty ' . dp_inr($r2['amount']) . ' per parcel)' : ' (penalty amount not set yet)') . '.';
        $o[] = $seatLine('dispatch.tracking', 'Tracking number person') . '. ' . $seatLine('dispatch.lead', 'Dispatch lead') . '.';
    } elseif ($topic === 'shops') {
        $o[] = 'Each shop has one display with a fixed bottle list (' . ($a['sh_full'] ?? 'full display not set yet') . '). A salesperson visits every display in the 12-day round and takes one photo; I count the bottles and ask for a retake when it is short.';
        $o[] = 'A shop that owes more than the value of the bottles on its shelf is asked to pay at once.';
        $o[] = $seatLine('retaildist.visitor.surat', 'Display visitor (Surat)') . '. ' . $seatLine('retaildist.manager', 'Retail distribution manager') . '.';
    } elseif ($topic === 'supplies') {
        $o[] = 'Every office keeps a list of supplies. The checker marks Ok, Low or Empty at each check; after 2 to 3 days I work out how often each item must come.';
        $o[] = 'The buyer buys it, and pays the supplier by UPI with a screenshot (or is reimbursed if he paid himself). ' . (dp_holders($pdo, 'supplies.buyer') ? $seatLine('supplies.buyer', 'Buyer') : 'Buyer: not set yet') . '.';
    } elseif ($topic === 'punch') {
        $r = $rule('punch_missed');
        $o[] = 'Before Punch In the screen asks for: live shoe photo, Instagram and YouTube comment screenshots and the before-punch-in store photo.';
        $o[] = 'A repeated, look-alike or fake screenshot is not accepted. If the steps are not done ' . ($r ? (int)$r['grace_min'] : 30) . ' minutes after shift start, the day is "Punch in missed" and a ' . ($r ? dp_inr($r['amount']) : '₹100') . ' penalty ' . ($r && $r['mode'] === 'auto' ? 'is taken automatically' : 'waits for your approval') . '.';
    }
    foreach (dp_q_rows($pdo, $topic) as $r) {
        if (strpos((string)$r['qkey'], 'f_') !== 0 || $r['state'] !== 'answered' || trim((string)$r['answer']) === '') continue;
        $o[] = 'You also told me, about "' . mb_substr((string)$r['qtext'], 0, 120) . '": ' . mb_substr(trim((string)$r['answer']), 0, 220) . (preg_match('/[.!?]$/', trim((string)$r['answer'])) ? '' : '.');
    }
    return implode(' ', $o);
}
/** the answer is saved; questions it covers are removed; the topic becomes "understood" when nothing is left to ask. */
function dp_q_answer(PDO $pdo, $qkey, $answer, $by, array $extra = []) {
    $st = $pdo->prepare("SELECT * FROM dp_q WHERE qkey = ?"); $st->execute([$qkey]); $r = $st->fetch(); if (!$r) json_response(['error' => 'Unknown question.'], 404);
    $b = dp_q_def($r); if (!$b) json_response(['error' => 'Unknown question.'], 404);
    $typed = trim((string)$answer) !== '';   // he wrote something (a plain "Yes, that is right" on my own suggestion needs no follow-up)
    if (in_array($r['state'], ['answered', 'removed'], true) && $r['source'] !== 'later') json_response(['error' => 'This one is already answered. Use "Correct me" on the topic to change it.'], 409);
    $ans = trim((string)$answer);
    if ($b['type'] === 'person') {
        $emp = (int)($extra['emp_id'] ?? 0); if (!$emp) json_response(['error' => 'Choose a person.'], 422);
        $e = bd_emp($pdo, $emp); if (!bd_emp_active($e)) json_response(['error' => 'That person is not active.'], 422);
        $code = $b['seat'] === 'supplies.buyer' ? dp_seat_ensure($pdo, 'supplies.buyer', 'Supplies buyer', 'supplies') : bd_seat_id($pdo, $b['seat']);
        if (!$code) json_response(['error' => 'The seat does not exist yet.'], 500);
        $id = $b['seat'] === 'supplies.buyer' ? $code : bd_seat_id($pdo, $b['seat']);
        $cur = bd_holders($pdo, $id); if (!in_array($emp, $cur, true)) $cur[] = $emp; bd_set_holders($pdo, $id, $cur);
        $ans = $e['full_name'];
    } elseif ($b['type'] === 'amount') {
        $amt = (float)($extra['amount'] ?? preg_replace('/[^0-9.]/', '', $ans)); if ($amt < 0 || $amt > 100000) json_response(['error' => 'Enter an amount from 0 to 100000.'], 422);
        if ($b['rule'] === 'punch_missed' && $qkey === 'pu_grace') { $g = (int)($extra['grace_min'] ?? $amt); $pdo->prepare("UPDATE dp_rules SET grace_min = ?, updated_at = ? WHERE rkey = 'punch_missed'")->execute([max(0, min(240, $g)), bd_now()]); $ans = $g . ' minutes'; }
        else { $pdo->prepare("UPDATE dp_rules SET amount = ?, updated_at = ? WHERE rkey = ?")->execute([$amt, bd_now(), $b['rule']]); $ans = dp_inr($amt); }
    } elseif ($b['type'] === 'confirm') { $ans = $ans !== '' ? $ans : ($b['sug'] !== '' ? $b['sug'] : 'Yes'); }
    else { if ($ans === '') json_response(['error' => 'Please write an answer.'], 422); }
    if ($qkey === 'pu_pen') { $auto = !empty($extra['auto']); $pdo->prepare("UPDATE dp_rules SET mode = ?, updated_at = ? WHERE rkey = 'punch_missed'")->execute([$auto ? 'auto' : 'approve', bd_now()]); }
    $pdo->prepare("UPDATE dp_q SET state = 'answered', answer = ?, answered_by = ?, answered_at = ?, source = 'you' WHERE qkey = ?")->execute([mb_substr($ans, 0, 2000), $by, bd_now(), $qkey]);
    foreach ($b['covers'] as $c) $pdo->prepare("UPDATE dp_q SET state = 'removed', removed_by = ?, answered_at = ? WHERE qkey = ? AND state IN ('open','later')")->execute([$qkey, bd_now(), $c]);
    bd_log('question', $qkey, mb_substr($ans, 0, 200), $by);
    $fu = dp_q_after($pdo, $b, $qkey, $ans, $typed && in_array($b['type'], ['text', 'confirm'], true));
    dp_topic_refresh($pdo, $b['t']);
    $fu['q'] = $b['q'];
    return $fu;
}
/**
 * After every answer Claude (1) says in plain words what it UNDERSTOOD and what the system WILL DO about it, and (2) when you typed something
 * that is vague, missing a detail or against an earlier answer, writes up to 2 follow-up questions (each with a suggested answer). Follow-ups go to
 * the front of the topic's list. It stops by itself: when the answers are clear nothing is added, and there are hard limits (a follow-up of a
 * follow-up of a follow-up is the last one; 12 per topic). Without a Claude key or when Claude cannot be reached, a plain sentence is stored instead.
 * Returns ['n' => new questions, 'ai' => ok | off | error | limit, 'understood' => text, 'will' => text].
 */
function dp_q_after(PDO $pdo, array $b, $qkey, $ans, $mayAsk) {
    $topic = $b['t'];
    $fallU = 'You answered: ' . mb_substr(trim((string)$ans), 0, 300);
    $fallW = 'I will use this when I check ' . (dp_topic_defs()[$topic] ?? $topic) . '. (Claude could not add more detail just now.)';
    $save = function ($u, $w) use ($pdo, $qkey) { $pdo->prepare("UPDATE dp_q SET qund = ?, qwill = ? WHERE qkey = ?")->execute([mb_substr($u, 0, 900), mb_substr($w, 0, 900), $qkey]); };
    $res = function ($n, $ai, $u, $w) use ($save) { $save($u, $w); return ['n' => $n, 'ai' => $ai, 'understood' => $u, 'will' => $w]; };
    if (!function_exists('tmph_claude_key') || tmph_claude_key() === '') return $res(0, 'off', $fallU, $fallW);
    $rows = dp_q_rows($pdo, $topic); $dyn = 0; $depth = 0; $lines = []; $open = [];
    foreach ($rows as $r) {
        if (strpos((string)$r['qkey'], 'f_') === 0) $dyn++;
        if ($r['qkey'] === $qkey) $depth = (int)($r['depth'] ?? 0);
        $d = dp_q_def($r); if (!$d) continue;
        if ($r['answer'] !== null && trim((string)$r['answer']) !== '') $lines[] = 'Q: ' . $d['q'] . "\nA: " . mb_substr((string)$r['answer'], 0, 600);
        elseif (in_array($r['state'], ['open', 'later'], true)) $open[] = $d['q'];
    }
    $limit = ($depth >= 3 || $dyn >= 12);
    if ($limit || !$mayAsk) $askNote = "Do NOT ask any follow-up for this one: reply with an empty follow_ups list.";
    else $askNote = "Ask a follow-up ONLY if the latest answer is vague, contradicts an earlier answer, or leaves out something the system needs to run it (who, when, how much, what proof, what happens if it is not done). Never repeat a question that is already answered or still waiting. At most 2 follow-ups, usually 0 or 1. Each follow-up has a suggested answer he can accept with one tap (type 'confirm' when it can be a yes/no on your suggestion, else 'text').";
    $sys = "You are the Boss Engine of a company HRMS in India (perfume business). You are learning from the owner how the department '" . (dp_topic_defs()[$topic] ?? $topic) . "' must run, before it goes live. "
        . "He just answered one question. Reply with JSON only, no other text, in this shape: "
        . '{"understood":"","will_do":"","follow_ups":[{"q":"","why":"","sug":"","type":"confirm"}]}. '
        . "\"understood\": ONE short plain sentence saying what you understood from his answer. \"will_do\": ONE short plain sentence saying what the system WILL DO with it (what it will check, warn, charge, show, or who it will tell). Be honest: if the HRMS cannot do it by itself yet, say who must do it by hand. "
        . $askNote . " Plain, short, simple English a shop manager understands.";
    $user = "THE QUESTION HE JUST ANSWERED: " . $b['q'] . "\nHIS ANSWER: " . mb_substr(trim((string)$ans), 0, 800) . "\n\nALL ANSWERS SO FAR:\n" . implode("\n\n", array_slice($lines, -30)) . "\n\nSTILL WAITING (do not repeat):\n" . ($open ? '- ' . implode("\n- ", $open) : '(none)') . "\n\nLATEST ANSWERED KEY: " . $qkey;
    $o = function_exists('brain_ai_json') ? brain_ai_json('internal', 'dispatch', $sys, $user, 900) : null;   // the ONE Claude door (+ what the owner taught the team-side brain)
    if (!is_array($o)) { error_log('Questions follow-up: no usable answer from Claude'); return $res(0, 'error', $fallU, $fallW); }
    $und = trim((string)($o['understood'] ?? '')) ?: $fallU; $will = trim((string)($o['will_do'] ?? '')) ?: $fallW;
    $n = 0;
    if (!$limit && $mayAsk) {
        $have = []; foreach ($rows as $r) { $d = dp_q_def($r); if ($d) $have[mb_strtolower(trim($d['q']))] = 1; }
        foreach (array_slice((array)($o['follow_ups'] ?? []), 0, 2) as $f) {
            $q = trim((string)($f['q'] ?? '')); if ($q === '' || isset($have[mb_strtolower($q)])) continue;
            $sug = trim((string)($f['sug'] ?? '')); $type = (($f['type'] ?? '') === 'confirm' && $sug !== '') ? 'confirm' : 'text';
            $key = 'f_' . substr(base_convert((string)time(), 10, 36) . bin2hex(random_bytes(3)), 0, 14);
            $pdo->prepare("INSERT INTO dp_q (topic, qkey, ord, state, qtext, qwhy, qsug, qtype, parent, depth) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$topic, $key, -20 + $n, 'open', mb_substr($q, 0, 400), mb_substr(trim((string)($f['why'] ?? '')), 0, 400), mb_substr($sug, 0, 600), $type, $qkey, $depth + 1]);
            bd_log('question', $key, 'follow-up: ' . mb_substr($q, 0, 160), 'Claude');
            $n++;
        }
    }
    return $res($n, 'ok', $und, $will);
}
function dp_topic_refresh(PDO $pdo, $topic) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM dp_q WHERE topic = ? AND state IN ('open','later')"); $st->execute([$topic]); $left = (int)$st->fetchColumn();
    $cur = dp_topic_state($pdo, $topic);
    if ($cur === 'live') return;
    $pdo->prepare("UPDATE dp_topics SET state = ? WHERE topic = ?")->execute([$left ? 'asking' : 'understood', $topic]);
}
function dp_q_counts(PDO $pdo) {
    $o = ['open' => 0, 'removed' => 0, 'live' => 0, 'understood' => 0, 'topics' => 0];
    foreach (dp_topic_defs() as $k => $n) { $s = dp_topic_state($pdo, $k); $o['topics']++; if ($s === 'live') $o['live']++; elseif ($s === 'understood') $o['understood']++; }
    foreach ($pdo->query("SELECT state, COUNT(*) c FROM dp_q GROUP BY state")->fetchAll() as $r) { if (in_array($r['state'], ['open', 'later'], true)) $o['open'] += (int)$r['c']; elseif ($r['state'] === 'removed') $o['removed'] += (int)$r['c']; }
    return $o;
}
/** number for the Questions badge: questions waiting + topics waiting for your OK */
function dp_q_badge(PDO $pdo) { $c = dp_q_counts($pdo); return $c['open'] + $c['understood']; }

/* ============================== the tick ============================== */
function dp_tick(PDO $pdo, $force = false) {
    $res = ['ran' => false];
    $got = true;
    try { $got = (bool)$pdo->query("SELECT GET_LOCK('dp_tick', 0)")->fetchColumn(); } catch (Throwable $e) {}
    if (!$got) return $res;
    try {
        $last = strtotime((string)dp_get('last_tick', '2000-01-01')); $now = time();
        if (!$force && $now - $last < 90) { try { $pdo->query("SELECT RELEASE_LOCK('dp_tick')"); } catch (Throwable $e) {} return $res; }
        dp_put('last_tick', bd_now()); $res = ['ran' => true];
        $res['punch'] = dp_punch_tick($pdo, $now);
        foreach (['dispatch', 'shops', 'supplies', 'repairs', 'tasks'] as $m) { $fn = $m === 'tasks' ? 'dp_tasks_tick' : 'dp_' . $m . '_tick'; if (function_exists($fn)) { try { $res[$m] = $fn($pdo, $now); } catch (Throwable $e) { error_log('Departments ' . $m . ': ' . $e->getMessage()); $res[$m] = 'error: ' . mb_substr($e->getMessage(), 0, 120); } } }
    } catch (Throwable $e) { error_log('Departments tick: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 200); }
    try { $pdo->query("SELECT RELEASE_LOCK('dp_tick')"); } catch (Throwable $e) {}
    return $res;
}
function dp_tick_after(PDO $pdo) {
    static $set = false; if ($set) return; $set = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { dp_ensure($pdo); dp_tick($pdo, false); } catch (Throwable $e) { error_log('Departments: ' . $e->getMessage()); }
    });
}

/* ============================== load the four departments ============================== */
foreach (['dispatch', 'shops', 'supplies', 'repairs', 'custom', 'tasks', 'boss'] as $__m) { $__f = __DIR__ . '/dept_' . $__m . '.php'; if (is_file($__f)) { require_once $__f; } }
unset($__m, $__f);

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'dp.') === 0) {
    dp_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($action === 'dp.tick') {
        $key = (string)($_GET['key'] ?? '');
        if ($key !== '' && hash_equals((string)bd_cfg_fresh('tick_key'), $key)) { json_response(['ok' => true, 'result' => dp_tick($pdo, !empty($_GET['force']))]); }
        $auth = require_auth(); bd_need_admin($auth); json_response(['ok' => true, 'result' => dp_tick($pdo, true)]);
    }
    $auth = require_auth();
    $w = bd_who($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];
    dp_tick_after($pdo);

    switch ($action) {

    /* ---------- everyone: My Day ---------- */
    case 'dp.me': {
        $emp = (int)($w['emp'] ?? 0);
        if (!$emp) json_response(['error' => 'This login is not linked to an employee.'], 400);
        $e = bd_emp($pdo, $emp); $photo = '';
        try { $q = $pdo->prepare("SELECT photo, employee_code, designation FROM employees WHERE employee_id = ?"); $q->execute([$emp]); $x = $q->fetch(); if ($x) { $photo = (string)($x['photo'] ?? ''); } } catch (Throwable $e2) {}
        $h = (int)date('G'); $greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
        // month numbers: on-time punch %, tasks on time %, penalties, days in a row
        $from = date('Y-m-01'); $today = bd_today();
        $punches = 0; $onTime = 0;
        try {
            $shiftMap = function_exists('emp_shift_map') ? emp_shift_map($pdo, $today) : [];
            $ss = $shiftMap[$emp]['shift_start'] ?? '10:00'; if (!preg_match('/^\d{2}:\d{2}$/', (string)$ss)) $ss = '10:00';
            $q = $pdo->prepare("SELECT punch_date, punch_in FROM attendance WHERE employee_id = ? AND punch_date >= ? AND punch_date <= ? AND punch_in IS NOT NULL ORDER BY punch_date DESC");
            $q->execute([$emp, date('Y-m-d', strtotime('-60 days')), $today]);
            $streak = 0; $broke = false;
            foreach ($q->fetchAll() as $r) {
                $limit = strtotime($r['punch_date'] . ' ' . $ss) + 15 * 60; $ok = strtotime($r['punch_in']) <= $limit;
                if ($r['punch_date'] >= $from) { $punches++; if ($ok) $onTime++; }
                if (!$broke) { if ($ok) $streak++; else $broke = true; }
            }
        } catch (Throwable $e3) { $streak = 0; }
        $tr = dp_track($pdo, $emp, 30);
        list($m1, $m2) = dp_month_range();
        $pen = 0.0; try { $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_penalties WHERE employee_id = ? AND status = 'applied' AND penalty_date BETWEEN ? AND ?"); $q->execute([$emp, $m1, $m2]); $pen = (float)$q->fetchColumn(); } catch (Throwable $e4) {}
        json_response(['name' => $e ? $e['full_name'] : '', 'first' => $e ? strtok($e['full_name'], ' ') : '', 'code' => (string)($e['employee_code'] ?? ''), 'designation' => (string)($e['designation'] ?? ''), 'photo' => $photo, 'greeting' => $greet,
            'seats' => dp_my_seats($pdo, $emp), 'on_time_punch' => $punches ? (int)round(100 * $onTime / $punches) : null, 'tasks_on_time' => $tr['on_time'], 'penalties_month' => $pen, 'streak' => $streak, 'mode' => bd_mode(), 'date' => $today]);
    }
    case 'dp.my_day': {
        $emp = (int)($w['emp'] ?? 0);
        if (!$emp) json_response(['error' => 'This login is not linked to an employee.'], 400);
        $mode = bd_mode(); $today = bd_today();
        if ($mode === 'shadow') json_response(['mode' => $mode, 'items' => [], 'done' => [], 'penalties' => [], 'punch_missed' => null, 'punch_live' => false]);
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE owner_emp = ? AND task_date = ? AND status IN ('open','done') AND kind != 'punch' ORDER BY status = 'done', due_at"); $st->execute([$emp, $today]);
        $open = []; $done = []; foreach ($st->fetchAll() as $r) { $v = dp_item_view($pdo, $r); if ($r['status'] === 'open') $open[] = $v; else $done[] = $v; }
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE owner_emp = ? AND pen_state IN ('pending','applied','appealed','waived') AND task_date >= ? ORDER BY task_date DESC"); $st->execute([$emp, date('Y-m-d', strtotime('-30 days'))]);
        $pen = []; foreach ($st->fetchAll() as $r) $pen[] = ['item_id' => (int)$r['id'], 'title' => $r['title'], 'date' => $r['task_date'], 'amount' => (float)$r['pen_amount'], 'state' => $r['pen_state'], 'remark' => $r['pen_note']];
        $pm = null; $q = $pdo->prepare("SELECT * FROM dp_items WHERE owner_emp = ? AND kind = 'punch' AND task_date = ? LIMIT 1"); $q->execute([$emp, $today]); $pr = $q->fetch();
        if ($pr) { $ru = dp_rule($pdo, 'punch_missed'); $pm = ['status' => $pr['status'], 'pen_state' => $pr['pen_state'], 'pen_amount' => (float)$pr['pen_amount'], 'at' => $pr['created_at'], 'grace_min' => $ru ? (int)$ru['grace_min'] : 30, 'rule_mode' => $ru ? $ru['mode'] : 'approve']; }
        json_response(['mode' => $mode, 'items' => $open, 'done' => $done, 'penalties' => $pen, 'punch_missed' => $pm, 'punch_live' => dp_live($pdo, 'punch')]);
    }
    case 'dp.appeal': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $it = dp_item($pdo, (int)($in['item_id'] ?? 0)); if (!$it || (int)$it['owner_emp'] !== (int)$w['emp']) json_response(['error' => 'Not found.'], 404);
        if (!in_array($it['pen_state'], ['pending', 'applied'], true)) json_response(['error' => 'This cannot be appealed now.'], 409);
        $note = trim((string)($in['note'] ?? '')); if (mb_strlen($note) < 3) json_response(['error' => 'Write a short reason.'], 422);
        $pdo->prepare("UPDATE dp_items SET pen_state = 'appealed', appeal_note = ? WHERE id = ?")->execute([mb_substr($note, 0, 250), $it['id']]);
        bd_inbox('dp_appeal', bd_emp_name($pdo, $it['owner_emp']) . ' appeals: ' . $it['title'], $note, ['item_id' => (int)$it['id']], null, null, 'dp_app:' . $it['id'] . ':' . time());
        json_response(['ok' => true, 'message' => 'Your appeal was sent to the Boss.']);
    }
    case 'dp.badge': {
        json_response(['my_open' => dp_badge_open($pdo, (int)$w['emp']), 'has_items' => dp_has_items($pdo, (int)$w['emp']), 'questions' => $w['manager'] ? dp_q_badge($pdo) : 0, 'tasks' => ($w['manager'] && function_exists('dp_tk_badge')) ? dp_tk_badge($pdo) : 0]);
    }

    /* ---------- managers: penalty rules ---------- */
    case 'dp.rules': {
        bd_need_manager($pdo, $auth);
        $o = []; foreach ($pdo->query("SELECT * FROM dp_rules ORDER BY sort_no, id")->fetchAll() as $r) {
            $hold = $r['seat_code'] && $r['seat_code'] !== 'self' ? dp_holders($pdo, $r['seat_code']) : [];
            $o[] = ['rkey' => $r['rkey'], 'title' => $r['title'], 'topic' => $r['topic'], 'amount' => (float)$r['amount'], 'per' => $r['per'], 'decide' => (int)$r['decide'], 'seat' => $r['seat_code'], 'holders' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $hold),
                'when' => $r['when_text'], 'mode' => $r['mode'], 'grace_min' => (int)$r['grace_min'], 'active' => (int)$r['active']];
        }
        // existing Boss Engine duties that carry a penalty are listed for completeness (edited on Duties)
        $seats = []; foreach ($pdo->query("SELECT code, title FROM bd_seats WHERE active = 1 AND code NOT LIKE 'top.%' ORDER BY code")->fetchAll() as $s) $seats[] = ['code' => $s['code'], 'title' => $s['title']];
        $seats[] = ['code' => 'self', 'title' => 'The person himself'];
        json_response(['rules' => $o, 'seats' => $seats, 'mode' => bd_mode(), 'limits' => ['day' => (int)bd_cfg('pen_cap_day'), 'month' => (int)bd_cfg('pen_cap_month')]]);
    }
    case 'dp.rule_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_manager($pdo, $auth);
        $k = (string)($in['rkey'] ?? ''); $r = dp_rule($pdo, $k); if (!$r) json_response(['error' => 'Unknown rule.'], 404);
        $amt = isset($in['amount']) ? (float)$in['amount'] : (float)$r['amount']; if ($amt < 0 || $amt > 100000) json_response(['error' => 'Amount must be 0 to 100000.'], 422);
        $mode = in_array(($in['mode'] ?? $r['mode']), ['auto', 'approve'], true) ? $in['mode'] : $r['mode'];
        $seat = array_key_exists('seat', $in) ? (string)$in['seat'] : (string)$r['seat_code'];
        if ($seat !== '' && $seat !== 'self' && !bd_seat_id($pdo, $seat) && strpos($seat, 'supplies.buyer') !== 0) json_response(['error' => 'That seat does not exist.'], 422);
        $grace = isset($in['grace_min']) ? max(0, min(1440, (int)$in['grace_min'])) : (int)$r['grace_min'];
        $active = isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : (int)$r['active'];
        $decide = $amt > 0 ? 0 : (int)$r['decide'];
        $pdo->prepare("UPDATE dp_rules SET amount = ?, mode = ?, seat_code = ?, grace_min = ?, active = ?, decide = ?, updated_at = ? WHERE rkey = ?")->execute([$amt, $mode, $seat, $grace, $active, $decide, bd_now(), $k]);
        bd_log('rule', $k, 'amount ' . $amt . ', ' . $mode . ', seat ' . $seat . ', grace ' . $grace . ', ' . ($active ? 'on' : 'off'), $by);
        $sn = function_exists('b2_links_pass') ? b2_links_pass($pdo, null, $by) : [];   // Boss task and old rule are ONE rule: the newest change wins, only one charges
        json_response(['ok' => true] + ($sn ? ['message' => 'Saved. ' . implode(' ', $sn)] : []));
    }
    case 'dp.charged': {
        bd_need_manager($pdo, $auth);
        list($m1, $m2) = dp_month_range((string)($_GET['month'] ?? ''));
        $st = $pdo->prepare("SELECT seat_code, SUM(pen_amount) a, COUNT(*) c FROM dp_items WHERE pen_state = 'applied' AND task_date BETWEEN ? AND ? GROUP BY seat_code ORDER BY a DESC"); $st->execute([$m1, $m2]);
        $o = []; foreach ($st->fetchAll() as $r) { $s = $r['seat_code'] === 'self' || !$r['seat_code'] ? null : bd_seat_row($pdo, bd_seat_id($pdo, $r['seat_code'])); $o[] = ['seat' => $s ? $s['title'] : 'Punch in / direct', 'amount' => (float)$r['a'], 'count' => (int)$r['c']]; }
        $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(pen_amount),0) a FROM dp_items WHERE pen_state IN ('pending','shadow') AND task_date BETWEEN ? AND ?"); $st->execute([$m1, $m2]); $w2 = $st->fetch();
        json_response(['month' => $m1, 'by_seat' => $o, 'waiting' => ['count' => (int)$w2['c'], 'amount' => (float)$w2['a']], 'mode' => bd_mode()]);
    }

    /* ---------- managers: seats ---------- */
    case 'dp.seats': {
        bd_need_manager($pdo, $auth);
        $secs = []; foreach ($pdo->query("SELECT id, code, name FROM bd_sections WHERE code IN ('dispatch','accounts','retaildist','supplies') ORDER BY FIELD(code,'dispatch','accounts','retaildist','supplies')")->fetchAll() as $s) $secs[(int)$s['id']] = $s;
        $o = [];
        foreach ($pdo->query("SELECT * FROM bd_seats WHERE active = 1 AND section_id IS NOT NULL AND kind = 'worker' AND code LIKE '%.%' ORDER BY section_id, id")->fetchAll() as $s) {
            if (!isset($secs[(int)$s['section_id']]) || preg_match('/\.worker$/', $s['code'])) continue;
            $h = bd_holders($pdo, (int)$s['id']);
            $sug = dp_suggest($pdo, $s['code'], 2);
            $o[] = ['code' => $s['code'], 'seat_id' => (int)$s['id'], 'title' => $s['title'], 'department' => $secs[(int)$s['section_id']]['name'], 'dept_code' => $secs[(int)$s['section_id']]['code'],
                'holders' => array_map(function ($i) use ($pdo) { return ['id' => $i, 'name' => bd_emp_name($pdo, $i)]; }, $h),
                'suggest' => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'line' => $c['line']]; }, $sug)];
        }
        $tr = []; foreach (bd_employees($pdo) as $e) { $t = dp_track($pdo, $e['id']); if ($t['tasks'] > 0) $tr[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'tasks' => $t['tasks'], 'on_time' => $t['on_time'], 'penalties' => $t['penalties']]; }
        usort($tr, function ($a, $b) { return $b['tasks'] <=> $a['tasks']; });
        json_response(['seats' => $o, 'track' => array_slice($tr, 0, 12), 'employees' => bd_employees($pdo)]);
    }
    case 'dp.seat_set': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $code = (string)($in['code'] ?? ''); $id = bd_seat_id($pdo, $code); if (!$id) json_response(['error' => 'Unknown seat.'], 404);
        $emps = array_values(array_filter(array_map('intval', (array)($in['emp_ids'] ?? []))));
        foreach ($emps as $e) { if (!bd_emp_active(bd_emp($pdo, $e))) json_response(['error' => 'One of those people is not active.'], 422); }
        bd_set_holders($pdo, $id, $emps); bd_log('seat', $code, 'holders: ' . implode(',', $emps), $by);
        foreach ($emps as $e) bd_push($pdo, $e, 'You now hold a seat', bd_seat_row($pdo, $id)['title'] . '. Your tasks are in My Day.');
        // work already waiting on that seat moves to the new first holder
        $first = $emps ? $emps[0] : null;
        if ($first) { $pdo->prepare("UPDATE dp_items SET owner_emp = ? WHERE seat_code = ? AND status = 'open'")->execute([$first, $code]); }
        json_response(['ok' => true]);
    }
    case 'dp.people': { bd_need_manager($pdo, $auth); json_response(['employees' => bd_employees($pdo)]); }

    /* ---------- managers: the Questions engine ---------- */
    case 'dp.q_list': {
        bd_need_manager($pdo, $auth);
        $topic = (string)($_GET['topic'] ?? '');
        $topics = [];
        foreach (dp_topic_defs() as $k => $n) {
            $rows = array_map('dp_q_view', dp_q_rows($pdo, $k)); $open = array_values(array_filter($rows, function ($q) { return $q && in_array($q['state'], ['open', 'later'], true); }));
            usort($open, function ($a, $b) { return [$a['state'] === 'later' ? 1 : 0] <=> [$b['state'] === 'later' ? 1 : 0]; });
            $topics[] = ['topic' => $k, 'name' => $n, 'state' => dp_topic_state($pdo, $k), 'open' => count($open), 'answered' => count(array_filter($rows, function ($q) { return $q && $q['state'] === 'answered'; })), 'removed' => count(array_filter($rows, function ($q) { return $q && $q['state'] === 'removed'; }))];
        }
        $use = $topic !== '' && isset(dp_topic_defs()[$topic]) ? $topic : '';
        $rows = []; foreach (dp_q_rows($pdo, $use ?: null) as $r) { $v = dp_q_view($r); if ($v) $rows[] = $v; }
        $open = array_values(array_filter($rows, function ($q) { return in_array($q['state'], ['open', 'later'], true); }));
        usort($open, function ($a, $b) { return ($a['state'] === 'later' ? 1 : 0) <=> ($b['state'] === 'later' ? 1 : 0); });
        $current = $open ? $open[0] : null; $cu = $current ? $current['topic'] : ($use ?: 'dispatch');
        $emps = []; if ($current && $current['type'] === 'person') { $emps = bd_employees($pdo); $current['suggest'] = array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'line' => $c['line']]; }, dp_suggest($pdo, $current['seat'] === 'supplies.buyer' ? 'supplies.buyer' : $current['seat'], 3)); }
        if ($current && $current['type'] === 'amount') { $r = dp_rule($pdo, $current['rule']); $current['rule_now'] = $r ? ['amount' => (float)$r['amount'], 'grace_min' => (int)$r['grace_min'], 'mode' => $r['mode']] : null; }
        json_response(['topics' => $topics, 'current' => $current, 'next' => array_slice(array_map(function ($q) { return ['key' => $q['key'], 'q' => $q['q'], 'topic' => $q['topic'], 'maybe' => false]; }, array_slice($open, 1, 2)), 0, 2),
            'removed' => array_values(array_filter($rows, function ($q) { return $q['state'] === 'removed'; })), 'answered' => array_values(array_filter($rows, function ($q) { return $q['state'] === 'answered'; })),
            'points' => array_values(array_map(function ($q) { return ['key' => $q['key'], 'q' => $q['q'], 'und' => $q['und'], 'will' => $q['will'], 'follow' => $q['follow']]; }, array_filter($rows, function ($q) use ($cu) { return $q['topic'] === $cu && $q['state'] === 'answered' && $q['und'] !== ''; }))),
            'summary' => dp_summary($pdo, $cu), 'topic' => $cu, 'topic_state' => dp_topic_state($pdo, $cu), 'counts' => dp_q_counts($pdo), 'employees' => $emps, 'mode' => bd_mode()]);
    }
    case 'dp.q_answer': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $fu = dp_q_answer($pdo, (string)($in['key'] ?? ''), (string)($in['answer'] ?? ''), $by, $in);
        json_response(['ok' => true, 'followups' => (int)($fu['n'] ?? 0), 'ai' => (string)($fu['ai'] ?? 'skip'), 'q' => (string)($fu['q'] ?? ''), 'understood' => (string)($fu['understood'] ?? ''), 'will' => (string)($fu['will'] ?? '')]);
    }
    case 'dp.q_later': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $pdo->prepare("UPDATE dp_q SET state = 'later', later_n = later_n + 1 WHERE qkey = ? AND state IN ('open','later')")->execute([(string)($in['key'] ?? '')]);
        json_response(['ok' => true]);
    }
    case 'dp.q_correct': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $t = (string)($in['topic'] ?? ''); if (!isset(dp_topic_defs()[$t])) json_response(['error' => 'Unknown topic.'], 404);
        $k = (string)($in['key'] ?? '');
        if ($k !== '') { $pdo->prepare("DELETE FROM dp_q WHERE parent = ? AND topic = ? AND state IN ('open','later')")->execute([$k, $t]); $pdo->prepare("UPDATE dp_q SET state = 'open', answer = NULL, source = 'you', qund = NULL, qwill = NULL WHERE qkey = ? AND topic = ? AND source != 'earlier'")->execute([$k, $t]); }
        $pdo->prepare("UPDATE dp_topics SET state = 'asking' WHERE topic = ?")->execute([$t]); bd_log('question', $t, 'corrected' . ($k ? ' ' . $k : ''), $by);
        json_response(['ok' => true]);
    }
    case 'dp.q_golive': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $t = (string)($in['topic'] ?? ''); if (!isset(dp_topic_defs()[$t])) json_response(['error' => 'Unknown topic.'], 404);
        if (dp_topic_state($pdo, $t) !== 'understood') json_response(['error' => 'Answer the open questions of this topic first, then I show you what I understood.'], 409);
        if (empty($in['confirm'])) json_response(['error' => 'Confirm that the summary is right.'], 422);
        $pdo->prepare("UPDATE dp_topics SET state = 'live', approved_by = ?, approved_at = ? WHERE topic = ?")->execute([$by, bd_now(), $t]);
        bd_log('golive', $t, 'topic is live', $by);
        json_response(['ok' => true, 'message' => dp_topic_defs()[$t] . ' is live. It follows the Boss Engine mode: ' . bd_mode() . '.']);
    }

    /* ---------- managers: settings + test tools ---------- */
    case 'dp.settings': {
        bd_need_manager($pdo, $auth);
        if ($isPost) {
            bd_need_admin($auth);
            foreach (['google_key', 'proof_ai'] as $k) { if (array_key_exists($k, $in)) dp_put($k, trim((string)$in[$k])); }
        }
        $gk = dp_get('google_key', '');
        json_response(['google_key_set' => $gk !== '', 'google_key_hint' => $gk !== '' ? substr($gk, 0, 4) . '…' . substr($gk, -3) : '', 'proof_ai' => dp_get('proof_ai', '1') === '1', 'mode' => bd_mode(), 'topics' => array_map(function ($k) use ($pdo) { return ['topic' => $k, 'state' => dp_topic_state($pdo, $k)]; }, array_keys(dp_topic_defs()))]);
    }
    case 'dp.summary': {
        bd_need_manager($pdo, $auth);
        list($m1, $m2) = dp_month_range(); $today = bd_today(); $o = [];
        foreach (dp_topic_defs() as $k => $n) {
            if ($k === 'punch') { $kind = 'punch'; } else { $kind = $k; }
            $q = $pdo->prepare("SELECT SUM(status='open' AND task_date = ?) op, SUM(status='done' AND task_date = ?) dn, SUM(status='missed' AND task_date BETWEEN ? AND ?) ms, COALESCE(SUM(CASE WHEN pen_state='applied' AND task_date BETWEEN ? AND ? THEN pen_amount ELSE 0 END),0) pa, COALESCE(SUM(CASE WHEN pen_state IN ('pending','shadow') AND task_date BETWEEN ? AND ? THEN pen_amount ELSE 0 END),0) pw FROM dp_items WHERE kind = ?");
            $q->execute([$today, $today, $m1, $m2, $m1, $m2, $m1, $m2, $kind]); $r = $q->fetch();
            $qq = $pdo->prepare("SELECT COUNT(*) FROM dp_q WHERE topic = ? AND state IN ('open','later')"); $qq->execute([$k]);
            $o[] = ['topic' => $k, 'name' => $n, 'state' => dp_topic_state($pdo, $k), 'questions' => (int)$qq->fetchColumn(), 'open_today' => (int)$r['op'], 'done_today' => (int)$r['dn'], 'missed_month' => (int)$r['ms'], 'pen_applied' => (float)$r['pa'], 'pen_waiting' => (float)$r['pw']];
        }
        json_response(['topics' => $o, 'mode' => bd_mode()]);
    }
    case 'dp.file': {
        $name = basename((string)($_GET['name'] ?? ''));
        $st = $pdo->prepare("SELECT 1 FROM dp_items WHERE proof_path = ? LIMIT 1"); $st->execute([$name]); $isItem = (bool)$st->fetchColumn();
        if (!$w['manager'] && !$isItem) json_response(['error' => 'Not allowed.'], 403);
        if (!$w['manager']) { $st = $pdo->prepare("SELECT 1 FROM dp_items WHERE proof_path = ? AND owner_emp = ?"); $st->execute([$name, (int)$w['emp']]); if (!$st->fetchColumn()) json_response(['error' => 'Not allowed.'], 403); }
        dp_file_out($name);
    }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Departments: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}

/* when api.php loads this file for an ordinary request, keep the departments ticking quietly after the reply is sent */
if (strpos((string)$action, 'dp.') !== 0) { try { dp_ensure($pdo); dp_tick_after($pdo); } catch (Throwable $e) {} }

/** the seats a person holds (for the My Day header) */
function dp_my_seats(PDO $pdo, $empId) {
    $o = [];
    try { $st = $pdo->prepare("SELECT s.code, s.title FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE h.emp_id = ? AND s.active = 1 AND s.kind = 'worker' AND s.code NOT LIKE '%.worker'"); $st->execute([(int)$empId]); foreach ($st->fetchAll() as $r) $o[] = ['code' => $r['code'], 'title' => $r['title']]; } catch (Throwable $e) {}
    return $o;
}
