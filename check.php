<?php
/**
 * check.php — "Claude checks first". An add-on for the HRMS Boss Engine (loaded by api.php for every "chk." action, and now and then to keep the checks running).
 *
 * Nobody opens every task. Claude checks everything first and a person only reads ONE page: the Daily Summary.
 *   1. every photo is judged the moment it is sent (that part lives in boss.php) - this file counts and double-checks them
 *   2. every night Claude checks the data: documents, attendance, leave, penalties, empty seats, leavers who still hold work
 *   3. uploaded documents (licence, passport, ID ...) are READ by Claude: readable? name? number? expiry?
 *   4. the same problem coming back 3 times is a LOOP - only then it is sent to a person, with a suggested fix
 *   5. a small random sample (5%) of what Claude cleared is shown to a person, so you can see how often Claude is right
 * Money, hiring/firing and legal decisions always stay with a person.
 *
 * Put this file in the SAME folder as api.php and boss.php. It creates its own tables (ck_*). PHP 7.4 safe.
 * It READS employees, employee_documents, document_types, attendance, leave_requests, employee_penalties; it WRITES only ck_* and the Boss inbox / seats.
 */
if (!isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('CK_LOADED')) { return; }
if (!is_file(__DIR__ . '/boss.php')) { return; }
define('CK_LOADED', 1);
require_once __DIR__ . '/boss.php';
if (!function_exists('bd_inbox')) { return; }

/* ============================== tables ============================== */
/** a fix for older Boss Engine installs: 'dismissed' (9 letters) did not fit the 8-letter status column, so Dismiss failed on a strict database */
function ck_patch_boss(PDO $pdo) {
    try {
        $c = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bd_inbox' AND COLUMN_NAME = 'status'")->fetchColumn();
        if ($c !== false && (int)$c < 10) $pdo->exec("ALTER TABLE bd_inbox MODIFY status VARCHAR(10) NOT NULL DEFAULT 'open'");
    } catch (Throwable $e) { error_log('check.php patch: ' . $e->getMessage()); }
}
function ck_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    ck_patch_boss($pdo);
    try { $pdo->query("SELECT 1 FROM ck_findings LIMIT 1"); $pdo->query("SELECT 1 FROM ck_spot LIMIT 1"); return; } catch (Throwable $e) {}
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_state (skey VARCHAR(40) PRIMARY KEY, svalue TEXT NULL)$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_doc_rules (id INT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(8) NOT NULL, scope_val VARCHAR(80) NOT NULL, document_type_id INT NOT NULL, UNIQUE KEY u_r (scope, scope_val, document_type_id))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_doc_reads (id INT AUTO_INCREMENT PRIMARY KEY, doc_id INT NOT NULL, emp_id INT NOT NULL, verdict VARCHAR(8) NOT NULL, found_name VARCHAR(120) NULL, found_number VARCHAR(60) NULL, found_expiry DATE NULL, reason VARCHAR(255) NULL, created_at DATETIME NOT NULL, UNIQUE KEY u_doc (doc_id), INDEX (emp_id))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_findings (
        id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL, ref_key VARCHAR(90) NOT NULL UNIQUE, emp_id INT NULL, section_id INT NULL,
        severity VARCHAR(5) NOT NULL DEFAULT 'warn', title VARCHAR(200) NOT NULL, detail VARCHAR(400) NULL, suggestion VARCHAR(300) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open', hits INT NOT NULL DEFAULT 1, first_at DATETIME NOT NULL, last_at DATETIME NOT NULL, last_day DATE NOT NULL, fixed_at DATETIME NULL,
        escalated TINYINT NOT NULL DEFAULT 0, INDEX (status, kind), INDEX (emp_id)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ck_spot (id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(10) NOT NULL, ref_id INT NOT NULL, label VARCHAR(200) NULL, ai_verdict VARCHAR(8) NOT NULL, human VARCHAR(8) NULL, created_at DATETIME NOT NULL, decided_at DATETIME NULL, decided_by VARCHAR(80) NULL, UNIQUE KEY u_s (source, ref_id))$E");
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['ck_state', 'ck_doc_rules', 'ck_doc_reads', 'ck_findings', 'ck_spot']);
}
function ck_get($k, $d = '') { global $pdo; try { $st = $pdo->prepare("SELECT svalue FROM ck_state WHERE skey = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function ck_put($k, $v) { global $pdo; $pdo->prepare("INSERT INTO ck_state (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$k, (string)$v]); }

/* ============================== findings (the memory of "Claude saw this") ============================== */
/** Record that a problem exists today. Same problem on a new day = hits+1. 3 hits = a loop and a person is asked, once. */
function ck_see(PDO $pdo, array &$seen, $kind, $ref, $empId, $sectionId, $sev, $title, $detail, $suggest = '') {
    $seen[$ref] = 1; $now = bd_now(); $day = bd_today();
    $st = $pdo->prepare("SELECT * FROM ck_findings WHERE ref_key = ?"); $st->execute([$ref]); $f = $st->fetch();
    if (!$f) {
        $pdo->prepare("INSERT INTO ck_findings (kind, ref_key, emp_id, section_id, severity, title, detail, suggestion, status, hits, first_at, last_at, last_day) VALUES (?,?,?,?,?,?,?,?, 'open', 1, ?,?,?)")
            ->execute([$kind, $ref, $empId ?: null, $sectionId ?: null, $sev, mb_substr($title, 0, 200), mb_substr((string)$detail, 0, 400), mb_substr((string)$suggest, 0, 300), $now, $now, $day]);
        return;
    }
    $hits = (int)$f['hits']; $status = $f['status'];
    if ($f['last_day'] !== $day) { $hits++; if ($status === 'fixed') $status = 'open'; }
    if ($status === 'dismissed' && $f['last_day'] !== $day && $hits >= 3) $status = 'open';   // a dismissed problem that keeps coming back is asked again
    $pdo->prepare("UPDATE ck_findings SET severity = ?, title = ?, detail = ?, suggestion = ?, hits = ?, status = ?, last_at = ?, last_day = ?, fixed_at = IF(? = 'open', NULL, fixed_at) WHERE id = ?")
        ->execute([$sev, mb_substr($title, 0, 200), mb_substr((string)$detail, 0, 400), mb_substr((string)$suggest, 0, 300), $hits, $status, $now, $day, $status, $f['id']]);
    if ($hits >= 3 && $status === 'open' && !(int)$f['escalated']) {
        bd_inbox('loop', 'Keeps coming back: ' . $title, 'Claude has seen this on ' . $hits . ' different days. ' . ($suggest !== '' ? 'Suggested fix: ' . $suggest : 'It needs a person.'), ['finding_id' => (int)$f['id'], 'link' => 'daily_summary.html'], $sectionId ?: null, null, 'loop:' . $ref);
        $pdo->prepare("UPDATE ck_findings SET escalated = 1 WHERE id = ?")->execute([$f['id']]);
    }
}
/** Anything of these kinds that Claude did NOT see again today is fixed by itself. */
function ck_close_unseen(PDO $pdo, array $kinds, array $seen) {
    if (!$kinds) return 0;
    $in = "'" . implode("','", array_map(function ($k) { return preg_replace('/[^a-z_]/', '', $k); }, $kinds)) . "'";
    $n = 0;
    foreach ($pdo->query("SELECT id, ref_key FROM ck_findings WHERE status = 'open' AND kind IN ($in)")->fetchAll() as $r) {
        if (isset($seen[$r['ref_key']])) continue;
        $pdo->prepare("UPDATE ck_findings SET status = 'fixed', fixed_at = ? WHERE id = ?")->execute([bd_now(), $r['id']]); $n++;
        $pdo->prepare("UPDATE bd_inbox SET status = 'done', resolved_at = ?, resolved_by = 'Claude (fixed by itself)' WHERE status = 'open' AND ref_key IN (?, ?)")->execute([bd_now(), 'loop:' . $r['ref_key'], 'ckdoc:' . $r['ref_key']]);
    }
    return $n;
}

/* ============================== documents ============================== */
function ck_doc_catalog(PDO $pdo) {
    try { return $pdo->query("SELECT document_type_id AS id, document_name AS name, is_required AS req, has_expiry AS expiry FROM document_types ORDER BY document_name")->fetchAll(); } catch (Throwable $e) { return []; }
}
/** the document types this person must have: the ones marked "required" for everyone + the rules for their department / designation */
function ck_required_for(PDO $pdo, array $emp, array $cat, array $rules) {
    $need = [];
    foreach ($cat as $c) if ((int)$c['req'] === 1) $need[(int)$c['id']] = $c['name'];
    $names = []; foreach ($cat as $c) $names[(int)$c['id']] = $c['name'];
    foreach ($rules as $r) {
        $hit = ($r['scope'] === 'all') || ($r['scope'] === 'dept' && (string)$r['scope_val'] === (string)($emp['department_id'] ?? '')) || ($r['scope'] === 'desig' && $r['scope_val'] !== '' && stripos((string)($emp['designation'] ?? ''), (string)$r['scope_val']) !== false);
        if ($hit && isset($names[(int)$r['document_type_id']])) $need[(int)$r['document_type_id']] = $names[(int)$r['document_type_id']];
    }
    return $need;
}
function ck_check_documents(PDO $pdo, array &$seen) {
    $cat = ck_doc_catalog($pdo); if (!$cat) return ['employees' => 0, 'missing' => 0, 'expiring' => 0];
    $rules = $pdo->query("SELECT * FROM ck_doc_rules")->fetchAll();
    $grace = max(0, (int)ck_get('doc_grace_days', '3')); $warn = max(1, (int)ck_get('doc_warn_days', '30'));
    $hrSeat = (int)ck_get('hr_emp', '0');
    $emps = $pdo->query("SELECT employee_id, full_name, employee_code, department_id, designation, joining_date FROM employees WHERE (status IS NULL OR status != 'ex')")->fetchAll();
    $docs = []; foreach ($pdo->query("SELECT employee_document_id AS id, employee_id, document_type_id AS tid, expiry_date, document_file FROM employee_documents")->fetchAll() as $d) $docs[(int)$d['employee_id']][(int)$d['tid']][] = $d;
    $miss = 0; $exp = 0; $today = bd_today();
    foreach ($emps as $e) {
        $id = (int)$e['employee_id'];
        $need = ck_required_for($pdo, $e, $cat, $rules); $missing = [];
        foreach ($need as $tid => $nm) {
            $has = $docs[$id][$tid] ?? [];
            if (!$has) { $missing[] = $nm; continue; }
        }
        // new joiners get a few days before we nag
        $age = !empty($e['joining_date']) ? (int)floor((strtotime($today) - strtotime($e['joining_date'])) / 86400) : 999;
        if ($missing && $age >= $grace) {
            $miss += count($missing);
            $ref = 'docm:' . $id;
            ck_see($pdo, $seen, 'doc_missing', $ref, $id, null, 'warn', $e['full_name'] . ': ' . count($missing) . ' document' . (count($missing) > 1 ? 's' : '') . ' missing', implode(', ', $missing), 'Ask ' . $e['full_name'] . ' to bring: ' . implode(', ', $missing) . '. Upload it in People → Documents.');
            bd_inbox('doc', $e['full_name'] . ': missing ' . implode(', ', array_slice($missing, 0, 3)) . (count($missing) > 3 ? ' +' . (count($missing) - 3) : ''), 'These documents are required but not uploaded yet.', ['emp_id' => $id, 'link' => 'doc_check.html?emp=' . $id], null, $hrSeat ?: null, 'ckdoc:' . $ref);
        }
        // expiry: every document of this person with an expiry date
        foreach ($docs[$id] ?? [] as $tid => $list) {
            foreach ($list as $h) {
                if (empty($h['expiry_date'])) continue;
                // a newer copy of the same type that is still valid makes the old one irrelevant
                $newerOk = false; foreach ($list as $o) if ($o['id'] !== $h['id'] && !empty($o['expiry_date']) && $o['expiry_date'] > $h['expiry_date'] && $o['expiry_date'] >= $today) $newerOk = true;
                if ($newerOk) continue;
                $days = (int)floor((strtotime($h['expiry_date']) - strtotime($today)) / 86400);
                if ($days > $warn) continue;
                $nm = ''; foreach ($cat as $c) if ((int)$c['id'] === (int)$tid) $nm = $c['name'];
                $exp++;
                $ref = 'doce:' . $h['id'];
                ck_see($pdo, $seen, 'doc_expiry', $ref, $id, null, $days < 0 ? 'bad' : 'warn', $e['full_name'] . ': ' . $nm . ($days < 0 ? ' expired ' . (-$days) . ' days ago' : ' expires in ' . $days . ' days'), 'Expiry date ' . $h['expiry_date'], 'Get the renewed ' . $nm . ' from ' . $e['full_name'] . ' and upload it.');
                if ($days < 0) bd_inbox('doc', $e['full_name'] . ': ' . $nm . ' expired', 'Expired on ' . $h['expiry_date'] . '.', ['emp_id' => $id, 'link' => 'doc_check.html?emp=' . $id], null, $hrSeat ?: null, 'ckdoc:' . $ref);
            }
        }
    }
    return ['employees' => count($emps), 'missing' => $miss, 'expiring' => $exp];
}

/** Claude READS uploaded documents (images only). Limited per run so the nightly check stays light. */
function ck_read_documents(PDO $pdo, array &$seen, $limit = 3) {
    if (!function_exists('convo_ai')) return ['read' => 0, 'note' => 'AI helper not available'];
    $rows = $pdo->query("SELECT ed.employee_document_id AS id, ed.employee_id, ed.document_number, ed.expiry_date, ed.document_file, dt.document_name, e.full_name FROM employee_documents ed JOIN document_types dt ON dt.document_type_id = ed.document_type_id JOIN employees e ON e.employee_id = ed.employee_id
        WHERE (e.status IS NULL OR e.status != 'ex') AND ed.document_file IS NOT NULL AND ed.document_file <> '' AND NOT EXISTS (SELECT 1 FROM ck_doc_reads r WHERE r.doc_id = ed.employee_document_id) ORDER BY ed.employee_document_id DESC LIMIT " . (int)$limit)->fetchAll();
    $n = 0;
    foreach ($rows as $d) {
        $rel = ltrim((string)$d['document_file'], '/');
        $abs = realpath(__DIR__ . '/' . $rel);
        $root = realpath(__DIR__ . '/uploads');
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $ins = function ($verdict, $reason, $nm = null, $num = null, $exp = null) use ($pdo, $d) {
            $pdo->prepare("INSERT IGNORE INTO ck_doc_reads (doc_id, emp_id, verdict, found_name, found_number, found_expiry, reason, created_at) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([(int)$d['id'], (int)$d['employee_id'], $verdict, $nm !== null ? mb_substr($nm, 0, 120) : null, $num !== null ? mb_substr($num, 0, 60) : null, ($exp && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) ? $exp : null, mb_substr((string)$reason, 0, 250), bd_now()]);
        };
        if (!$abs || !$root || strpos($abs, $root) !== 0 || !is_file($abs)) { $ins('skipped', 'The file is not on the server.'); continue; }
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $ins('skipped', 'This is a ' . strtoupper($ext) . ' file. Claude reads photos (JPG/PNG) only for now.'); continue; }
        $img = bd_b64_small($abs); if (!$img) { $ins('skipped', 'Could not open the picture.'); continue; }
        $sys = "You read identity and licence documents for an Indian company's HR desk. You are shown ONE photo or scan. Report only what you can really see. Never guess a number or a date. "
            . "readable=false if the document is blurry, cut off, too dark, or not a document at all. Give name, number and expiry date exactly as printed (expiry as YYYY-MM-DD, empty if none visible). "
            . "name_matches=true only if the printed name clearly matches the employee name given (spelling and order may differ a little). looks_wrong lists anything suspicious (edited look, mismatched fonts, cropped corners, a photo of a screen). You cannot prove a document is genuine - say so by keeping looks_wrong honest, not by refusing.";
        $schema = ['type' => 'OBJECT', 'properties' => ['readable' => ['type' => 'BOOLEAN'], 'doc_kind' => ['type' => 'STRING'], 'name' => ['type' => 'STRING'], 'number' => ['type' => 'STRING'], 'expiry' => ['type' => 'STRING'], 'name_matches' => ['type' => 'BOOLEAN'], 'looks_wrong' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'reason' => ['type' => 'STRING']], 'required' => ['readable', 'reason']];
        $parts = [['text' => "EMPLOYEE NAME: " . $d['full_name'] . "\nDOCUMENT TYPE EXPECTED: " . $d['document_name'] . "\nNUMBER ENTERED BY HR: " . ($d['document_number'] ?: '(none)') . "\nEXPIRY ENTERED BY HR: " . ($d['expiry_date'] ?: '(none)') . "\n\nThe document:"], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]];
        try { $r = convo_ai($sys, $parts, $schema, false, 700); } catch (Throwable $e) { $r = [false, null, $e->getMessage()]; }
        if (empty($r[0]) || !is_array($r[1])) { continue; }   // AI down: try again at the next run, nothing is recorded
        $v = $r[1]; $n++;
        $why = trim((string)($v['reason'] ?? ''));
        if (empty($v['readable'])) { $ins('retake', $why !== '' ? $why : 'Not readable. Scan it again.', $v['name'] ?? null, $v['number'] ?? null, $v['expiry'] ?? null); continue; }
        $issues = [];
        if (isset($v['name_matches']) && !$v['name_matches']) $issues[] = 'the name on it (' . ($v['name'] ?? '?') . ') does not match ' . $d['full_name'];
        if (!empty($v['number']) && !empty($d['document_number']) && preg_replace('/\W/', '', strtolower($v['number'])) !== preg_replace('/\W/', '', strtolower($d['document_number']))) $issues[] = 'the number on it (' . $v['number'] . ') differs from the one entered (' . $d['document_number'] . ')';
        if (!empty($v['expiry']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['expiry']) && !empty($d['expiry_date']) && $v['expiry'] !== $d['expiry_date']) $issues[] = 'the expiry on it (' . $v['expiry'] . ') differs from the one entered (' . $d['expiry_date'] . ')';
        if (!empty($v['expiry']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['expiry']) && $v['expiry'] < bd_today()) $issues[] = 'it has already expired (' . $v['expiry'] . ')';
        foreach ((array)($v['looks_wrong'] ?? []) as $lw) if (is_string($lw) && trim($lw) !== '') $issues[] = trim($lw);
        if (!$issues) { $ins('clear', $why !== '' ? $why : 'Readable, name and details match.', $v['name'] ?? null, $v['number'] ?? null, $v['expiry'] ?? null); }
        else {
            $ins('unsure', implode('; ', $issues), $v['name'] ?? null, $v['number'] ?? null, $v['expiry'] ?? null);
            $ref = 'docr:' . $d['id'];
            ck_see($pdo, $seen, 'doc_check', $ref, (int)$d['employee_id'], null, 'warn', $d['full_name'] . ': ' . $d['document_name'] . ' needs a look', implode('; ', $issues), 'Compare the paper with the details in People → Documents and correct whichever is wrong.');
            bd_inbox('doc', $d['full_name'] . ': ' . $d['document_name'] . ' needs a look', implode('; ', $issues), ['emp_id' => (int)$d['employee_id'], 'link' => 'doc_check.html?emp=' . (int)$d['employee_id']], null, (int)ck_get('hr_emp', '0') ?: null, 'ckdoc:' . $ref);
        }
    }
    // unreadable ones are findings too (a person must ask for a new scan)
    foreach ($pdo->query("SELECT r.doc_id, r.emp_id, r.reason, e.full_name, dt.document_name FROM ck_doc_reads r JOIN employee_documents ed ON ed.employee_document_id = r.doc_id JOIN document_types dt ON dt.document_type_id = ed.document_type_id JOIN employees e ON e.employee_id = r.emp_id WHERE r.verdict = 'retake' AND (e.status IS NULL OR e.status != 'ex')")->fetchAll() as $r) {
        ck_see($pdo, $seen, 'doc_bad', 'docb:' . $r['doc_id'], (int)$r['emp_id'], null, 'warn', $r['full_name'] . ': ' . $r['document_name'] . ' is not readable', $r['reason'], 'Ask for a clear scan and upload it again (the old one can be deleted).');
    }
    return ['read' => $n];
}

/* ============================== data checks ============================== */
function ck_check_data(PDO $pdo, array &$seen) {
    $out = []; $today = bd_today();
    // 1. punched in but never punched out (before today)
    try {
        $q = $pdo->prepare("SELECT a.employee_id, a.punch_date, e.full_name FROM attendance a JOIN employees e ON e.employee_id = a.employee_id WHERE a.punch_in IS NOT NULL AND a.punch_out IS NULL AND a.punch_date < ? AND a.punch_date >= ? AND (e.status IS NULL OR e.status != 'ex')");
        $q->execute([$today, date('Y-m-d', strtotime('-7 days'))]); $c = 0;
        foreach ($q->fetchAll() as $r) { $c++; ck_see($pdo, $seen, 'att_open', 'att:' . $r['employee_id'] . ':' . $r['punch_date'], (int)$r['employee_id'], null, 'warn', $r['full_name'] . ': no punch-out on ' . $r['punch_date'], 'Punched in but never out.', 'Fix the day in Attendance (add the out time or mark the day).'); }
        $out['att_open'] = $c;
    } catch (Throwable $e) { $out['att_open'] = 'n/a'; }
    // 2. two attendance rows on one day
    try {
        $q = $pdo->prepare("SELECT a.employee_id, a.punch_date, COUNT(*) c, e.full_name FROM attendance a JOIN employees e ON e.employee_id = a.employee_id WHERE a.punch_date >= ? GROUP BY a.employee_id, a.punch_date HAVING c > 1");
        $q->execute([date('Y-m-d', strtotime('-7 days'))]); $c = 0;
        foreach ($q->fetchAll() as $r) { $c++; ck_see($pdo, $seen, 'att_dup', 'dup:' . $r['employee_id'] . ':' . $r['punch_date'], (int)$r['employee_id'], null, 'warn', $r['full_name'] . ': ' . $r['c'] . ' punches on ' . $r['punch_date'], 'Two attendance rows for the same day.', 'Delete the extra row in Attendance.'); }
        $out['att_dup'] = $c;
    } catch (Throwable $e) { $out['att_dup'] = 'n/a'; }
    // 3. overlapping approved leave
    try {
        $c = 0;
        foreach ($pdo->query("SELECT a.leave_id a_id, b.leave_id b_id, a.employee_id, e.full_name, a.date_from af, a.date_to at_, b.date_from bf, b.date_to bt FROM leave_requests a JOIN leave_requests b ON b.employee_id = a.employee_id AND b.leave_id > a.leave_id AND a.status = 'approved' AND b.status = 'approved' AND a.date_from <= b.date_to AND b.date_from <= a.date_to JOIN employees e ON e.employee_id = a.employee_id WHERE a.date_to >= CURDATE() - INTERVAL 30 DAY")->fetchAll() as $r) {
            $c++; ck_see($pdo, $seen, 'leave_overlap', 'lv:' . $r['a_id'] . ':' . $r['b_id'], (int)$r['employee_id'], null, 'warn', $r['full_name'] . ': two approved leaves overlap', $r['af'] . '→' . $r['at_'] . ' and ' . $r['bf'] . '→' . $r['bt'], 'Cancel one of the two in Leave requests so days are not counted twice.');
        }
        $out['leave_overlap'] = $c;
    } catch (Throwable $e) { $out['leave_overlap'] = 'n/a'; }
    // 4. penalties above the monthly cap (the cap you set in the Boss Engine settings)
    try {
        $cap = (float)bd_cfg_fresh('pen_cap_month'); $c = 0;
        if ($cap > 0) {
            $q = $pdo->prepare("SELECT p.employee_id, e.full_name, SUM(p.amount) tot FROM employee_penalties p JOIN employees e ON e.employee_id = p.employee_id WHERE p.status = 'applied' AND p.penalty_date >= ? GROUP BY p.employee_id HAVING tot > ?");
            $q->execute([date('Y-m-01'), $cap]);
            foreach ($q->fetchAll() as $r) { $c++; ck_see($pdo, $seen, 'pen_cap', 'pcap:' . $r['employee_id'] . ':' . date('Y-m'), (int)$r['employee_id'], null, 'bad', $r['full_name'] . ': penalties this month are ' . bd_inr($r['tot']), 'Above the monthly limit of ' . bd_inr($cap) . '.', 'Review the penalties of this month. Confirm wage-deduction limits with your CA.'); }
        }
        $out['pen_cap'] = $c;
    } catch (Throwable $e) { $out['pen_cap'] = 'n/a'; }
    // 5. seats nobody holds
    try {
        $c = 0;
        foreach ($pdo->query("SELECT s.id, s.title, s.section_id, sec.name sec FROM bd_seats s LEFT JOIN bd_sections sec ON sec.id = s.section_id WHERE s.active = 1 AND s.kind IN ('worker','checker','section_boss') AND s.need_people > 0 AND (SELECT COUNT(*) FROM bd_holders h JOIN employees e ON e.employee_id = h.emp_id WHERE h.seat_id = s.id AND (e.status IS NULL OR e.status != 'ex')) = 0 AND (SELECT COUNT(*) FROM bd_duties d WHERE d.status = 'live' AND (d.seat_id = s.id OR (d.seat_id IS NULL AND s.kind = 'worker' AND d.section_id = s.section_id))) > 0")->fetchAll() as $r) {
            $c++; ck_see($pdo, $seen, 'seat_empty', 'seat:' . $r['id'], null, $r['section_id'] ? (int)$r['section_id'] : null, 'bad', ($r['sec'] ?: 'Section') . ' · ' . $r['title'] . ': nobody holds this seat', 'Live duties are waiting for this seat.', 'Open Boss → Setup and put a person (and a backup) on this seat.');
        }
        $out['seat_empty'] = $c;
    } catch (Throwable $e) { $out['seat_empty'] = 'n/a'; }
    // 6. people who left but still hold seats or open work
    try { $out['leavers'] = ck_check_leavers($pdo, $seen); } catch (Throwable $e) { $out['leavers'] = 'n/a'; }
    return $out;
}

/* ============================== leavers: nothing is left out ============================== */
function ck_leavers(PDO $pdo) {
    $rows = $pdo->query("SELECT e.employee_id, e.full_name, e.department_id, e.designation, e.left_at,
        (SELECT COUNT(*) FROM bd_holders h WHERE h.emp_id = e.employee_id) seats,
        (SELECT COUNT(*) FROM bd_tasks t WHERE t.owner_emp = e.employee_id AND t.status IN ('open','unowned') AND t.task_date >= CURDATE()) tasks
        FROM employees e WHERE e.status = 'ex' AND ((SELECT COUNT(*) FROM bd_holders h WHERE h.emp_id = e.employee_id) > 0 OR (SELECT COUNT(*) FROM bd_tasks t WHERE t.owner_emp = e.employee_id AND t.status IN ('open','unowned') AND t.task_date >= CURDATE()) > 0)")->fetchAll();
    return $rows;
}
function ck_check_leavers(PDO $pdo, array &$seen) {
    $c = 0;
    foreach (ck_leavers($pdo) as $r) {
        $c++;
        $ref = 'leaver:' . $r['employee_id'];
        ck_see($pdo, $seen, 'leaver', $ref, (int)$r['employee_id'], null, 'bad', $r['full_name'] . ' has left but still holds ' . (int)$r['seats'] . ' seat' . ((int)$r['seats'] === 1 ? '' : 's') . ' and ' . (int)$r['tasks'] . ' open task' . ((int)$r['tasks'] === 1 ? '' : 's'), 'Work is being covered by backups for now.', 'Open the handover and choose who takes over.');
        bd_inbox('handover', $r['full_name'] . ' has left: who takes over?', (int)$r['seats'] . ' seat(s) and ' . (int)$r['tasks'] . ' open task(s) still carry this name. Backups cover them for now.', ['emp_id' => (int)$r['employee_id'], 'link' => 'handover.html?emp=' . (int)$r['employee_id']], null, null, 'handover:' . $r['employee_id']);
    }
    return $c;
}
function ck_candidates(PDO $pdo, array $leaver, $seatId) {
    $today = bd_today(); $cand = [];
    $secId = 0; $seat = $seatId ? bd_seat_row($pdo, $seatId) : null; if ($seat) $secId = (int)$seat['section_id'];
    $pool = [];
    // 1) people already in the same section, 2) same department, 3) same designation
    if ($secId) { $q = $pdo->prepare("SELECT DISTINCT h.emp_id FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE s.section_id = ?"); $q->execute([$secId]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) $pool[(int)$e] = 'already in this section'; }
    if (!empty($leaver['department_id'])) { $q = $pdo->prepare("SELECT employee_id FROM employees WHERE department_id = ? AND (status IS NULL OR status != 'ex')"); $q->execute([$leaver['department_id']]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) if (!isset($pool[(int)$e])) $pool[(int)$e] = 'same department'; }
    unset($pool[(int)$leaver['employee_id']]);
    foreach ($pool as $eid => $why) {
        $e = bd_emp($pdo, $eid); if (!bd_emp_active($e)) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM bd_tasks WHERE owner_emp = ? AND task_date = ? AND status IN ('open','done')"); $st->execute([$eid, $today]); $load = (int)$st->fetchColumn();
        $away = bd_away($pdo, $eid, $today);
        $cand[] = ['id' => $eid, 'name' => $e['full_name'], 'dept' => $e['department_name'] ?? '', 'why' => $why, 'load' => $load, 'away' => $away];
    }
    usort($cand, function ($a, $b) { if (($a['away'] !== '') !== ($b['away'] !== '')) return $a['away'] !== '' ? 1 : -1; return $a['load'] <=> $b['load']; });
    return array_slice($cand, 0, 8);
}

/* ============================== spot check (5%) ============================== */
function ck_spot_pick(PDO $pdo) {
    $pct = max(0, min(100, (int)ck_get('spot_pct', '5')));
    if ($pct === 0) return 0;
    $n = 0;
    try {
        $q = $pdo->prepare("SELECT p.id, p.task_id, d.title FROM bd_photos p JOIN bd_duties d ON d.id = p.duty_id WHERE p.verdict = 'pass' AND p.created_at >= ? AND NOT EXISTS (SELECT 1 FROM ck_spot s WHERE s.source = 'photo' AND s.ref_id = p.id)");
        $q->execute([date('Y-m-d H:i:s', strtotime('-36 hours'))]);
        foreach ($q->fetchAll() as $r) {
            if (mt_rand(1, 100) > $pct) continue;
            $pdo->prepare("INSERT IGNORE INTO ck_spot (source, ref_id, label, ai_verdict, created_at) VALUES ('photo', ?, ?, 'pass', ?)")->execute([(int)$r['id'], mb_substr($r['title'], 0, 200), bd_now()]); $n++;
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT r.id, r.verdict, d.title FROM of_checks r JOIN bd_duties d ON d.id = r.duty_id WHERE r.ai_verdict = 'clear' AND r.created_at >= NOW() - INTERVAL 36 HOUR AND NOT EXISTS (SELECT 1 FROM ck_spot s WHERE s.source = 'office' AND s.ref_id = r.id)")->fetchAll() as $r) {
            if (mt_rand(1, 100) > $pct) continue;
            $pdo->prepare("INSERT IGNORE INTO ck_spot (source, ref_id, label, ai_verdict, created_at) VALUES ('office', ?, ?, 'clear', ?)")->execute([(int)$r['id'], mb_substr($r['title'], 0, 200), bd_now()]); $n++;
        }
    } catch (Throwable $e) {}
    return $n;
}
function ck_accuracy(PDO $pdo) {
    $r = $pdo->query("SELECT COUNT(*) tot, SUM(human = 'agree') ag, SUM(human = 'disagree') dis FROM ck_spot WHERE human IS NOT NULL")->fetch();
    $tot = (int)$r['tot']; $ag = (int)$r['ag'];
    return ['checked' => $tot, 'agree' => $ag, 'disagree' => (int)$r['dis'], 'pct' => $tot ? (int)round($ag * 100 / $tot) : null];
}

/* ============================== the nightly run ============================== */
function ck_run(PDO $pdo, $force = false) {
    $res = ['ran' => false];
    $got = true; try { $got = (bool)$pdo->query("SELECT GET_LOCK('ck_run', 0)")->fetchColumn(); } catch (Throwable $e) {}
    if (!$got) return $res;
    try {
        $last = strtotime(ck_get('last_run', '2000-01-01'));
        if (!$force && time() - $last < 3600) { $pdo->query("SELECT RELEASE_LOCK('ck_run')"); return $res; }
        ck_put('last_run', bd_now());
        $seen = []; $res = ['ran' => true];
        $res['docs'] = ck_check_documents($pdo, $seen);
        $res['reads'] = ck_read_documents($pdo, $seen, $force ? 6 : 3);
        $res['data'] = ck_check_data($pdo, $seen);
        if (function_exists('of_check_offices')) { try { $res['offices'] = of_check_offices($pdo, $seen); } catch (Throwable $e) { $res['offices'] = 'error: ' . $e->getMessage(); } }
        $kinds = ['doc_missing', 'doc_expiry', 'doc_check', 'doc_bad', 'seat_empty', 'leaver', 'of_zone', 'of_stale'];
        foreach (['att_open', 'att_dup', 'leave_overlap', 'pen_cap'] as $k) if (($res['data'][$k] ?? 'n/a') !== 'n/a') $kinds[] = $k;
        if (isset($res['offices']) && is_string($res['offices'])) $kinds = array_diff($kinds, ['of_zone', 'of_stale']);
        $res['closed'] = ck_close_unseen($pdo, $kinds, $seen);
        // inbox items that belong to a problem that is gone
        $pdo->exec("UPDATE bd_inbox SET status = 'done', resolved_at = NOW(), resolved_by = 'Claude (fixed by itself)' WHERE status = 'open' AND kind = 'handover' AND NOT EXISTS (SELECT 1 FROM ck_findings f WHERE f.ref_key = CONCAT('leaver:', SUBSTRING_INDEX(bd_inbox.ref_key, ':', -1)) AND f.status = 'open')");
        $res['spot'] = ck_spot_pick($pdo);
        ck_put('last_summary', json_encode(ck_summary_numbers($pdo)));
    } catch (Throwable $e) { error_log('check.php run: ' . $e->getMessage()); $res['error'] = mb_substr($e->getMessage(), 0, 200); }
    try { $pdo->query("SELECT RELEASE_LOCK('ck_run')"); } catch (Throwable $e) {}
    return $res;
}
function ck_run_after(PDO $pdo) {
    static $set = false; if ($set) return; $set = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { bd_ensure($pdo); ck_ensure($pdo); ck_run($pdo, false); } catch (Throwable $e) { error_log('check.php: ' . $e->getMessage()); }
    });
}

/* ============================== the Daily Summary ============================== */
function ck_summary_numbers(PDO $pdo) {
    $today = bd_today(); $yest = date('Y-m-d', strtotime('-1 day'));
    $n = [];
    $t = $pdo->prepare("SELECT status, COUNT(*) c FROM bd_tasks WHERE task_date = ? GROUP BY status"); $t->execute([$yest]);
    $n['y'] = ['done' => 0, 'missed' => 0, 'open' => 0, 'unowned' => 0, 'excused' => 0]; foreach ($t->fetchAll() as $r) $n['y'][$r['status']] = (int)$r['c'];
    $t->execute([$today]); $n['t'] = ['done' => 0, 'missed' => 0, 'open' => 0, 'unowned' => 0, 'excused' => 0]; foreach ($t->fetchAll() as $r) $n['t'][$r['status']] = (int)$r['c'];
    $p = $pdo->prepare("SELECT verdict, COUNT(*) c FROM bd_photos WHERE created_at >= ? GROUP BY verdict"); $p->execute([date('Y-m-d H:i:s', strtotime('-24 hours'))]);
    $n['photos'] = ['pass' => 0, 'fail' => 0, 'manual' => 0, 'skip' => 0]; foreach ($p->fetchAll() as $r) if (isset($n['photos'][$r['verdict']])) $n['photos'][$r['verdict']] = (int)$r['c'];
    $n['findings'] = []; foreach ($pdo->query("SELECT kind, COUNT(*) c FROM ck_findings WHERE status = 'open' GROUP BY kind")->fetchAll() as $r) $n['findings'][$r['kind']] = (int)$r['c'];
    $n['loops'] = (int)$pdo->query("SELECT COUNT(*) FROM ck_findings WHERE status = 'open' AND (hits >= 3 OR kind = 'of_zone')")->fetchColumn();
    $n['docs'] = ['clear' => 0, 'unsure' => 0, 'retake' => 0, 'skipped' => 0]; foreach ($pdo->query("SELECT verdict, COUNT(*) c FROM ck_doc_reads GROUP BY verdict")->fetchAll() as $r) if (isset($n['docs'][$r['verdict']])) $n['docs'][$r['verdict']] = (int)$r['c'];
    $n['fixed_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM ck_findings WHERE status = 'fixed' AND fixed_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $n['spot_open'] = (int)$pdo->query("SELECT COUNT(*) FROM ck_spot WHERE human IS NULL")->fetchColumn();
    return $n;
}
function ck_headline(array $n, $needs) {
    $checked = array_sum($n['photos']) + array_sum($n['y']) - $n['y']['excused'] + array_sum($n['docs']) + $n['fixed_24h'];
    return ['checked' => $checked, 'needs' => $needs];
}

/* the Offices module rides along (same hook in api.php) */
if (is_file(__DIR__ . '/office.php')) { require_once __DIR__ . '/office.php'; }
if (is_file(__DIR__ . '/dept.php')) { require_once __DIR__ . '/dept.php'; }

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'chk.') === 0) {
    bd_ensure($pdo); ck_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($action === 'chk.tick') {
        $key = (string)($_GET['key'] ?? '');
        if ($key !== '' && hash_equals((string)bd_cfg_fresh('tick_key'), $key)) { json_response(['ok' => true, 'result' => ck_run($pdo, !empty($_GET['force']))]); }
        $auth = require_auth(); bd_need_admin($auth);
        json_response(['ok' => true, 'result' => ck_run($pdo, true)]);
    }

    $auth = require_auth();
    $w = bd_who($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];

    switch ($action) {

    case 'chk.summary': {
        if (!$w['manager']) json_response(['error' => 'The Daily Summary is for Admin, HR and section bosses.'], 403);
        ck_run_after($pdo);
        $n = ck_summary_numbers($pdo);
        $scope = $w['admin'] || $w['top'] ? '1=1' : '(section_id IN (' . bd_in($w['sections']) . '))';
        $items = [];
        foreach ($pdo->query("SELECT f.*, e.full_name emp FROM ck_findings f LEFT JOIN employees e ON e.employee_id = f.emp_id WHERE f.status = 'open' AND $scope ORDER BY FIELD(f.severity,'bad','warn','info'), f.hits DESC, f.id DESC LIMIT 300")->fetchAll() as $f) {
            $items[] = ['id' => (int)$f['id'], 'kind' => $f['kind'], 'sev' => $f['severity'], 'title' => $f['title'], 'detail' => $f['detail'], 'suggest' => $f['suggestion'], 'hits' => (int)$f['hits'], 'loop' => (int)$f['hits'] >= 3 || $f['kind'] === 'of_zone', 'since' => substr($f['first_at'], 0, 10), 'emp_id' => $f['emp_id'] ? (int)$f['emp_id'] : null];
        }
        $acc = ck_accuracy($pdo);
        $fixed = []; foreach ($pdo->query("SELECT title FROM ck_findings WHERE status = 'fixed' AND fixed_at >= NOW() - INTERVAL 24 HOUR ORDER BY fixed_at DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN) as $t) $fixed[] = $t;
        $offices = [];
        if (function_exists('of_summary')) { try { $offices = of_summary($pdo, $w); } catch (Throwable $e) { $offices = []; } }
        $needs = count($items);
        json_response(['asof' => bd_now(), 'last_run' => ck_get('last_run', ''), 'mode' => bd_mode(), 'n' => $n, 'items' => $items, 'fixed' => $fixed, 'accuracy' => $acc, 'offices' => $offices, 'needs' => $needs, 'loops' => $n['loops'],
            'headline' => ck_headline($n, $needs), 'can_decide' => $w['admin'] || $w['top']]);
    }

    case 'chk.badge': {
        if (!$w['manager']) json_response(['loops' => 0, 'leavers' => 0, 'docs' => 0, 'review' => 0, 'other' => 0]);
        ck_run_after($pdo);
        $full = $w['admin'] || $w['top'];
        $scope = $full ? '1=1' : '(section_id IN (' . bd_in($w['sections']) . '))';
        $c = function ($where) use ($pdo, $scope) { return (int)$pdo->query("SELECT COUNT(*) FROM ck_findings WHERE status = 'open' AND $scope AND ($where)")->fetchColumn(); };
        $loops = $c("hits >= 3 OR kind = 'of_zone'"); $leavers = $full ? $c("kind = 'leaver'") : 0;
        $docs = $w['admin'] ? $c("kind IN ('doc_missing','doc_expiry','doc_check','doc_bad') AND hits < 3") : 0;
        $all = $c('1=1'); $other = max(0, $all - $loops - $leavers - $docs);
        $review = 0;
        if (function_exists('of_summary')) { try { foreach (of_summary($pdo, $w) as $o) $review += (int)$o['to_review']; } catch (Throwable $e) {} }
        json_response(['loops' => $loops, 'leavers' => $leavers, 'docs' => $docs, 'review' => $review, 'other' => $other, 'total' => $all]);
    }

    case 'chk.run': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        json_response(['ok' => true, 'result' => ck_run($pdo, true)]);
    }

    case 'chk.finding_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$w['manager']) json_response(['error' => 'Not allowed.'], 403);
        $id = (int)($in['id'] ?? 0); $act = (string)($in['act'] ?? '');
        $st = $pdo->prepare("SELECT * FROM ck_findings WHERE id = ?"); $st->execute([$id]); $f = $st->fetch();
        if (!$f) json_response(['error' => 'Not found.'], 404);
        if ($f['section_id'] && !bd_can_section($w, $f['section_id'])) json_response(['error' => 'This belongs to another section.'], 403);
        if ($act === 'dismiss') { $pdo->prepare("UPDATE ck_findings SET status = 'dismissed' WHERE id = ?")->execute([$id]); bd_log('chk', $id, 'dismissed: ' . $f['title'], $by); json_response(['ok' => true, 'message' => 'Dismissed. Claude will ask again only if it keeps coming back.']); }
        if ($act === 'fixed') { $pdo->prepare("UPDATE ck_findings SET status = 'fixed', fixed_at = ? WHERE id = ?")->execute([bd_now(), $id]); json_response(['ok' => true, 'message' => 'Marked fixed. Claude will check again tonight.']); }
        json_response(['error' => 'Unknown action.'], 422);
    }

    case 'chk.spot': {
        if (!$w['manager']) json_response(['error' => 'Not allowed.'], 403);
        $rows = $pdo->query("SELECT * FROM ck_spot WHERE human IS NULL ORDER BY id DESC LIMIT 12")->fetchAll(); $o = [];
        foreach ($rows as $r) {
            $x = ['id' => (int)$r['id'], 'source' => $r['source'], 'ref_id' => (int)$r['ref_id'], 'label' => $r['label'], 'ai' => $r['ai_verdict'], 'at' => $r['created_at']];
            if ($r['source'] === 'photo') { $q = $pdo->prepare("SELECT p.task_id, p.reason, p.emp_id FROM bd_photos p WHERE p.id = ?"); $q->execute([(int)$r['ref_id']]); $p = $q->fetch(); if ($p) { $x['task_id'] = (int)$p['task_id']; $x['reason'] = $p['reason']; $x['who'] = bd_emp_name($pdo, $p['emp_id']); } }
            elseif ($r['source'] === 'office' && function_exists('of_spot_info')) { $x = array_merge($x, of_spot_info($pdo, (int)$r['ref_id'])); }
            $o[] = $x;
        }
        json_response(['items' => $o, 'accuracy' => ck_accuracy($pdo)]);
    }
    case 'chk.spot_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!$w['manager']) json_response(['error' => 'Not allowed.'], 403);
        $id = (int)($in['id'] ?? 0); $v = (string)($in['verdict'] ?? '');
        if (!in_array($v, ['agree', 'disagree'], true)) json_response(['error' => 'Choose agree or disagree.'], 422);
        $pdo->prepare("UPDATE ck_spot SET human = ?, decided_at = ?, decided_by = ? WHERE id = ? AND human IS NULL")->execute([$v, bd_now(), $by, $id]);
        if ($v === 'disagree') {
            $st = $pdo->prepare("SELECT * FROM ck_spot WHERE id = ?"); $st->execute([$id]); $sp = $st->fetch();
            if ($sp) { $seen = []; ck_see($pdo, $seen, 'spot_miss', 'spot:' . $id, null, null, 'warn', 'Claude cleared something you disagree with: ' . $sp['label'], 'Spot check ' . $sp['source'] . ' #' . $sp['ref_id'], 'Add a clearer example photo or a stricter checklist line for this duty.'); }
        }
        json_response(['ok' => true, 'accuracy' => ck_accuracy($pdo)]);
    }

    /* ---------- documents (Admin / HR only: passports and licences are private) ---------- */
    case 'chk.docs': {
        bd_need_admin($auth);
        $cat = ck_doc_catalog($pdo); $rules = $pdo->query("SELECT * FROM ck_doc_rules")->fetchAll();
        $empId = (int)($_GET['emp'] ?? 0);
        $emps = $pdo->query("SELECT e.employee_id, e.full_name, e.employee_code, e.department_id, e.designation, e.joining_date, d.department_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE (e.status IS NULL OR e.status != 'ex') ORDER BY e.full_name")->fetchAll();
        $docs = []; foreach ($pdo->query("SELECT ed.employee_document_id id, ed.employee_id, ed.document_type_id tid, ed.document_number num, ed.expiry_date, ed.status, r.verdict, r.reason FROM employee_documents ed LEFT JOIN ck_doc_reads r ON r.doc_id = ed.employee_document_id")->fetchAll() as $d) $docs[(int)$d['employee_id']][] = $d;
        $out = []; $tot = ['people' => 0, 'complete' => 0, 'missing' => 0, 'expiring' => 0, 'check' => 0];
        $today = bd_today(); $warn = max(1, (int)ck_get('doc_warn_days', '30'));
        foreach ($emps as $e) {
            $id = (int)$e['employee_id']; $need = ck_required_for($pdo, $e, $cat, $rules);
            $have = $docs[$id] ?? []; $haveT = []; foreach ($have as $h) $haveT[(int)$h['tid']][] = $h;
            $rows = [];
            foreach ($need as $tid => $nm) {
                $list = $haveT[$tid] ?? [];
                if (!$list) { $rows[] = ['type' => $nm, 'state' => 'missing']; continue; }
                $b = $list[0]; $state = 'ok'; $note = '';
                if (!empty($b['expiry_date'])) { $dd = (int)floor((strtotime($b['expiry_date']) - strtotime($today)) / 86400); if ($dd < 0) { $state = 'expired'; $note = 'expired ' . $b['expiry_date']; } elseif ($dd <= $warn) { $state = 'expiring'; $note = 'expires ' . $b['expiry_date']; } }
                if ($state === 'ok' && $b['verdict'] === 'retake') { $state = 'unreadable'; $note = $b['reason']; }
                elseif ($state === 'ok' && $b['verdict'] === 'unsure') { $state = 'check'; $note = $b['reason']; }
                elseif ($state === 'ok' && $b['verdict'] === 'skipped') { $note = 'not read by Claude (' . $b['reason'] . ')'; }
                elseif ($state === 'ok' && $b['verdict'] === 'clear') { $note = 'Claude read it: fine'; }
                $rows[] = ['type' => $nm, 'state' => $state, 'note' => $note, 'doc_id' => (int)$b['id']];
            }
            // documents that are not "required" but were uploaded are shown too (a licence, say), so nothing Claude read is hidden
            foreach ($haveT as $tid => $list) {
                if (isset($need[$tid])) continue;
                $b = $list[0]; $nm = ''; foreach ($cat as $cc) if ((int)$cc['id'] === (int)$tid) $nm = $cc['name'];
                $state = 'ok'; $note = '';
                if (!empty($b['expiry_date'])) { $dd = (int)floor((strtotime($b['expiry_date']) - strtotime($today)) / 86400); if ($dd < 0) { $state = 'expired'; $note = 'expired ' . $b['expiry_date']; } elseif ($dd <= $warn) { $state = 'expiring'; $note = 'expires ' . $b['expiry_date']; } }
                if ($state === 'ok' && $b['verdict'] === 'retake') { $state = 'unreadable'; $note = $b['reason']; }
                elseif ($state === 'ok' && $b['verdict'] === 'unsure') { $state = 'check'; $note = $b['reason']; }
                elseif ($state === 'ok' && $b['verdict'] === 'clear') { $note = 'Claude read it: fine'; }
                $rows[] = ['type' => $nm . ' (extra)', 'state' => $state, 'note' => $note, 'doc_id' => (int)$b['id']];
            }
            $bad = 0; $m = 0; $x = 0; $c = 0; foreach ($rows as $r) { if ($r['state'] === 'missing') $m++; elseif (in_array($r['state'], ['expired', 'expiring'], true)) $x++; elseif (in_array($r['state'], ['unreadable', 'check'], true)) $c++; }
            $tot['people']++; if (!$m && !$x && !$c) $tot['complete']++; $tot['missing'] += $m; $tot['expiring'] += $x; $tot['check'] += $c;
            if ($empId && $empId !== $id) continue;
            if (!$empId && !$m && !$x && !$c) continue;
            $out[] = ['emp_id' => $id, 'name' => $e['full_name'], 'code' => $e['employee_code'], 'dept' => $e['department_name'], 'designation' => $e['designation'], 'rows' => $rows, 'missing' => $m, 'expiring' => $x, 'check' => $c];
        }
        usort($out, function ($a, $b) { return ($b['missing'] * 3 + $b['expiring'] * 2 + $b['check']) <=> ($a['missing'] * 3 + $a['expiring'] * 2 + $a['check']); });
        json_response(['people' => $out, 'totals' => $tot, 'types' => $cat, 'rules' => $rules, 'hr_emp' => (int)ck_get('hr_emp', '0'), 'grace' => (int)ck_get('doc_grace_days', '3'), 'warn' => $warn, 'agreement_note' => true]);
    }
    case 'chk.doc_rule_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $scope = (string)($in['scope'] ?? ''); $val = trim((string)($in['scope_val'] ?? '')); $tid = (int)($in['document_type_id'] ?? 0);
        if (!in_array($scope, ['all', 'dept', 'desig'], true) || !$tid) json_response(['error' => 'Choose who it applies to and which document.'], 422);
        if ($scope !== 'all' && $val === '') json_response(['error' => 'Choose the department or type the designation.'], 422);
        if (!empty($in['remove'])) { $pdo->prepare("DELETE FROM ck_doc_rules WHERE scope = ? AND scope_val = ? AND document_type_id = ?")->execute([$scope, $scope === 'all' ? '' : $val, $tid]); }
        else { $pdo->prepare("INSERT IGNORE INTO ck_doc_rules (scope, scope_val, document_type_id) VALUES (?,?,?)")->execute([$scope, $scope === 'all' ? '' : mb_substr($val, 0, 80), $tid]); }
        bd_log('chk', 'rule', ($in['remove'] ? 'removed' : 'added') . " rule $scope/$val/$tid", $by);
        json_response(['ok' => true, 'message' => 'Saved. Tonight Claude checks with the new list.']);
    }
    case 'chk.settings': {
        bd_need_admin($auth);
        $keys = ['hr_emp' => '0', 'doc_grace_days' => '3', 'doc_warn_days' => '30', 'spot_pct' => '5'];
        if ($isPost) {
            foreach ($keys as $k => $d) if (isset($in[$k])) { $v = (int)$in[$k]; if ($k === 'spot_pct') $v = max(0, min(100, $v)); if ($k === 'doc_warn_days') $v = max(1, min(365, $v)); if ($k === 'doc_grace_days') $v = max(0, min(60, $v)); ck_put($k, (string)$v); }
            json_response(['ok' => true, 'message' => 'Saved.']);
        }
        $o = []; foreach ($keys as $k => $d) $o[$k] = (int)ck_get($k, $d);
        json_response(['settings' => $o, 'employees' => bd_employees($pdo)]);
    }

    /* ---------- leaver handover ---------- */
    case 'chk.handover': {
        bd_need_admin($auth);
        $only = (int)($_GET['emp'] ?? 0); $o = [];
        foreach (ck_leavers($pdo) as $l) {
            if ($only && (int)$l['employee_id'] !== $only) continue;
            $seats = [];
            $q = $pdo->prepare("SELECT h.seat_id, h.rank_no, s.title, s.kind, s.section_id, sec.name sec FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id LEFT JOIN bd_sections sec ON sec.id = s.section_id WHERE h.emp_id = ?");
            $q->execute([(int)$l['employee_id']]);
            foreach ($q->fetchAll() as $s) {
                $others = []; foreach (bd_holders($pdo, (int)$s['seat_id']) as $oid) if ($oid !== (int)$l['employee_id']) $others[] = bd_emp_name($pdo, $oid);
                $d = $pdo->prepare("SELECT COUNT(*) FROM bd_duties WHERE seat_id = ? AND status = 'live'"); $d->execute([(int)$s['seat_id']]);
                $seats[] = ['seat_id' => (int)$s['seat_id'], 'title' => $s['title'], 'kind' => $s['kind'], 'section' => $s['sec'], 'rank' => (int)$s['rank_no'], 'others' => $others, 'duties' => (int)$d->fetchColumn(), 'candidates' => ck_candidates($pdo, $l, (int)$s['seat_id'])];
            }
            $tq = $pdo->prepare("SELECT t.id, d.title, t.task_date, t.status FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.owner_emp = ? AND t.status IN ('open','unowned') AND t.task_date >= CURDATE() ORDER BY t.task_date LIMIT 20");
            $tq->execute([(int)$l['employee_id']]);
            $o[] = ['emp_id' => (int)$l['employee_id'], 'name' => $l['full_name'], 'left_at' => $l['left_at'], 'seats' => $seats, 'tasks' => $tq->fetchAll(), 'task_count' => (int)$l['tasks'], 'docs' => ck_leaver_docs($pdo, (int)$l['employee_id'])];
        }
        json_response(['leavers' => $o]);
    }
    case 'chk.handover_apply': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        bd_need_admin($auth);
        $emp = (int)($in['emp_id'] ?? 0); $moves = (array)($in['moves'] ?? []);
        $e = bd_emp($pdo, $emp); if (!$e || bd_emp_active($e)) json_response(['error' => 'This person is still active. Handover is only for people who have left.'], 409);
        $done = 0; $msgs = [];
        foreach ($moves as $m) {
            $seat = (int)($m['seat_id'] ?? 0); $to = (int)($m['to_emp'] ?? 0);
            $has = $pdo->prepare("SELECT rank_no FROM bd_holders WHERE seat_id = ? AND emp_id = ?"); $has->execute([$seat, $emp]); $rank = $has->fetchColumn();
            if ($rank === false) continue;
            $cur = []; $q = $pdo->prepare("SELECT emp_id FROM bd_holders WHERE seat_id = ? ORDER BY rank_no, id"); $q->execute([$seat]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $cur[] = (int)$x;
            $pos = array_search($emp, $cur, true); $new = [];
            foreach ($cur as $i => $x) { if ($x === $emp) { if ($to > 0 && bd_emp_active(bd_emp($pdo, $to)) && !in_array($to, $cur, true)) $new[] = $to; continue; } $new[] = $x; }
            if ($to > 0 && !in_array($to, $new, true) && bd_emp_active(bd_emp($pdo, $to))) $new[] = $to;
            bd_set_holders($pdo, $seat, $new); $done++;
            $msgs[] = ($to > 0 ? bd_emp_name($pdo, $to) : 'nobody') . ' on seat #' . $seat;
            if ($to > 0) bd_push($pdo, $to, 'New responsibility', 'You now hold a seat that ' . $e['full_name'] . ' used to hold. Check My Day.');
        }
        // seats the leaver still holds that were not mentioned: they are simply removed (backups already cover them)
        $rest = $pdo->prepare("SELECT seat_id FROM bd_holders WHERE emp_id = ?"); $rest->execute([$emp]);
        foreach ($rest->fetchAll(PDO::FETCH_COLUMN) as $s) { $pdo->prepare("DELETE FROM bd_holders WHERE seat_id = ? AND emp_id = ?")->execute([(int)$s, $emp]); }
        $mv = bd_reassign($pdo, bd_today());
        // future tasks that are still on the leaver and not today's: give them to whoever now holds the seat
        $pdo->prepare("UPDATE bd_tasks SET owner_emp = NULL, orig_emp = COALESCE(orig_emp, ?), status = IF(status = 'open', 'unowned', status) WHERE owner_emp = ? AND task_date > CURDATE() AND status IN ('open','unowned')")->execute([$emp, $emp]);
        bd_log('handover', $emp, $e['full_name'] . ' left: ' . ($msgs ? implode('; ', $msgs) : 'seats cleared'), $by);
        $pdo->prepare("UPDATE bd_inbox SET status = 'done', resolved_at = ?, resolved_by = ? WHERE kind = 'handover' AND status = 'open' AND ref_key = ?")->execute([bd_now(), $by, 'handover:' . $emp]);
        $seen = []; ck_check_leavers($pdo, $seen); ck_close_unseen($pdo, ['leaver'], $seen);
        json_response(['ok' => true, 'message' => 'Handover done: ' . ($msgs ? implode(', ', $msgs) : 'seats cleared') . '. ' . $mv . ' of today\'s tasks moved.']);
    }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in the checks: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}
function ck_leaver_docs(PDO $pdo, $empId) {
    try { $q = $pdo->prepare("SELECT dt.document_name FROM employee_documents ed JOIN document_types dt ON dt.document_type_id = ed.document_type_id WHERE ed.employee_id = ?"); $q->execute([$empId]); return $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
}

/* when api.php loads this file for an ordinary request, keep the checks running quietly after the reply is sent */
if (strpos((string)$action, 'chk.') !== 0) { try { bd_ensure($pdo); ck_ensure($pdo); } catch (Throwable $e) {} ck_run_after($pdo); }
