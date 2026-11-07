<?php
/**
 * Claude asks — one question engine for the whole Boss Engine and People.
 *
 *  - Questions are not fixed topics. Claude looks at what exists (people, departments, seats, tasks, penalties, rules)
 *    and asks about whatever is unclear, one question at a time per department, until the concept is clear.
 *    A question disappears the moment it is answered or the gap closes by itself.
 *  - Conflicts: when a new rule/task clashes with an old one, Claude asks. "Keep new" removes the old one entirely,
 *    "Keep old" drops the new one. Follow-up questions then appear by themselves.
 *  - Structure: departments and positions (seats) are made automatically from people (their designations).
 *    Claude creates, then tells you (notice with Rename / Move). When one department holds two kinds of people it asks.
 *  - Both directions: everything is computed from the same tables (b2_items, penalty_rules, employees, bd_*), so a change
 *    made in People, in the Boss Engine or in the Penalties page is picked up by the next scan (about every minute).
 *
 *  - Handover: when someone is marked as left, Claude closes their seats and login, asks who takes over and moves every open thing (see cq_q_handover).
 *
 * Actions (Admin / HR): bd.cq_list, bd.cq_answer, bd.cq_place, bd.cq_scan, bd.cq_ho_status, bd.cq_ho_run
 */

function cq_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cq_q (
            id INT AUTO_INCREMENT PRIMARY KEY, sig VARCHAR(120) NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'gap', subject VARCHAR(120) NULL,
            section_code VARCHAR(40) NULL, title VARCHAR(255) NOT NULL, body TEXT NULL, input VARCHAR(10) NOT NULL DEFAULT 'choice',
            options MEDIUMTEXT NULL, payload MEDIUMTEXT NULL, status VARCHAR(8) NOT NULL DEFAULT 'open', answer TEXT NULL,
            series VARCHAR(60) NULL, seq INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, answered_at DATETIME NULL, answered_by VARCHAR(80) NULL,
            UNIQUE KEY sig (sig), KEY st (status, kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('cq_ensure: ' . $e->getMessage()); }
    cq_ho_ensure($pdo);
    if (function_exists('pp_ensure')) { try { pp_ensure($pdo); } catch (Throwable $e) {} }
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['cq_q']);
}

/* ------------------------------------------------------------------ small helpers */
function cq_dkey($d) { return trim(preg_replace('/\s+/', ' ', mb_strtolower((string)$d))); }
function cq_role_words() { return ['executive', 'manager', 'assistant', 'staff', 'head', 'senior', 'junior', 'associate', 'officer', 'helper', 'person', 'team', 'lead', 'incharge', 'in', 'charge', 'trainee', 'intern', 'and', 'of']; }
/** the "kind of work" of a designation: Packer / Packing -> pack, Store staff / Store Manager -> stor */
function cq_stem($d) {
    $tok = preg_split('/[^a-z0-9]+/', cq_dkey($d), -1, PREG_SPLIT_NO_EMPTY); $rw = cq_role_words(); $keep = [];
    foreach ($tok as $t) if (!in_array($t, $rw, true)) $keep[] = $t;
    if (!$keep) $keep = $tok;
    if (!$keep) return '';
    $s = $keep[0];
    if (strlen($s) > 5 && substr($s, -6) === 'person') $s = substr($s, 0, -6);
    return substr($s, 0, 4);
}
function cq_title_case($s) { return mb_convert_case(trim((string)$s), MB_CASE_TITLE, 'UTF-8'); }
function cq_people(PDO $pdo) {
    try { return $pdo->query("SELECT employee_id AS id, full_name AS name, designation, department_id FROM employees WHERE LOWER(COALESCE(status,'')) != 'ex' ORDER BY full_name")->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function cq_departments(PDO $pdo) {
    $o = []; try { foreach ($pdo->query("SELECT department_id AS id, department_name AS name FROM departments")->fetchAll() as $d) $o[(int)$d['id']] = (string)$d['name']; } catch (Throwable $e) {}
    return $o;
}
function cq_section_of_dept(PDO $pdo, $deptId) {
    $st = $pdo->prepare("SELECT * FROM bd_sections WHERE FIND_IN_SET(?, dept_ids) ORDER BY active DESC, id LIMIT 1"); $st->execute([(string)(int)$deptId]); return $st->fetch() ?: null;
}
function cq_log($pdo, $by, $what) { if (function_exists('pp_log')) pp_log($pdo, $by, $what, 0); }
/** Registered places (office_place.php) ask their own questions here. office.php may not be loaded in this request: load it once, with the globals it needs. */
function cq_ofp_load() {
    if (function_exists('ofp_cq_questions')) return true;
    global $pdo, $action;
    if (!isset($pdo, $action) || !is_file(__DIR__ . '/office.php')) return false;
    try { require_once __DIR__ . '/office.php'; } catch (Throwable $e) { error_log('cq places: ' . $e->getMessage()); }
    return function_exists('ofp_cq_questions');
}

/* ------------------------------------------------------------------ questions table */
function cq_ask(PDO $pdo, array &$live, $sig, array $f, $reopenDone = false) {
    $live[$sig] = true; $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT id, status FROM cq_q WHERE sig = ?"); $st->execute([$sig]); $r = $st->fetch();
    $opts = isset($f['options']) ? json_encode($f['options'], JSON_UNESCAPED_UNICODE) : null; $pl = isset($f['payload']) ? json_encode($f['payload'], JSON_UNESCAPED_UNICODE) : null;
    if (!$r) {
        $pdo->prepare("INSERT INTO cq_q (sig, kind, subject, section_code, title, body, input, options, payload, status, series, seq, created_at) VALUES (?,?,?,?,?,?,?,?,?, 'open', ?,?,?)")
            ->execute([$sig, $f['kind'], $f['subject'] ?? null, $f['section_code'] ?? null, $f['title'], $f['body'] ?? null, $f['input'] ?? 'choice', $opts, $pl, $f['series'] ?? null, (int)($f['seq'] ?? 0), $now]);
        return;
    }
    $reopen = $r['status'] === 'gone' || ($reopenDone && $r['status'] === 'done');
    if ($r['status'] === 'open' || $reopen) {
        $pdo->prepare("UPDATE cq_q SET title = ?, body = ?, input = ?, options = ?, payload = ?, subject = ?, seq = ?" . ($reopen ? ", status = 'open', answer = NULL, answered_at = NULL, created_at = ?" : '') . " WHERE id = ?")
            ->execute(array_merge([$f['title'], $f['body'] ?? null, $f['input'] ?? 'choice', $opts, $pl, $f['subject'] ?? null, (int)($f['seq'] ?? 0)], $reopen ? [$now] : [], [(int)$r['id']]));
    }
}
function cq_decided(PDO $pdo, $sig) { $st = $pdo->prepare("SELECT status FROM cq_q WHERE sig = ?"); $st->execute([$sig]); return $st->fetchColumn() === 'done'; }
function cq_close(PDO $pdo, $id, $answer, $by) { $pdo->prepare("UPDATE cq_q SET status = 'done', answer = ?, answered_at = ?, answered_by = ? WHERE id = ?")->execute([mb_substr((string)$answer, 0, 2000), date('Y-m-d H:i:s'), $by, (int)$id]); }

/* ------------------------------------------------------------------ departments & positions */
function cq_dept_name_for($pdo, $designation) {
    if (function_exists('b2_ai_on') && b2_ai_on()) {
        $ex = implode(', ', array_values(cq_departments($pdo)));
        $ai = b2_ai_json('You name a department of an Indian perfume business from one job title. Reply {"name":"..."} - one or two words, plural-free, e.g. "Packing", "Design", "Accounts".', 'Job title: ' . $designation . '. Existing departments: ' . $ex, 120);
        if (is_array($ai) && !empty($ai['name']) && mb_strlen(trim((string)$ai['name'])) >= 2) return mb_substr(cq_title_case(trim((string)$ai['name'])), 0, 60);
    }
    $tok = preg_split('/\s+/', trim($designation), -1, PREG_SPLIT_NO_EMPTY); $rw = cq_role_words(); $keep = [];
    foreach ($tok as $t) if (!in_array(mb_strtolower($t), $rw, true)) $keep[] = $t;
    $n = trim(implode(' ', $keep ?: $tok));
    return mb_substr(cq_title_case($n !== '' ? $n : 'General'), 0, 60);
}
/** create a department (HRMS) and its Boss Engine section + seats. Returns [dept_id, section_code]. */
function cq_create_dept(PDO $pdo, $name) {
    $name = mb_substr(trim($name), 0, 80);
    $q = $pdo->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(?)"); $q->execute([$name]); $ex = $q->fetchColumn();
    if ($ex) { $did = (int)$ex; }
    else { $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)")->execute([$name]); $did = (int)$pdo->lastInsertId(); }
    if (function_exists('bd_map_departments')) { try { bd_map_departments($pdo, false); } catch (Throwable $e) { error_log('cq_create_dept: ' . $e->getMessage()); } }
    $pdo->prepare("UPDATE bd_sections SET active = 1 WHERE FIND_IN_SET(?, dept_ids)")->execute([(string)$did]);
    if (function_exists('b2_sections')) { try { b2_sections($pdo, true); } catch (Throwable $e) {} }
    $s = cq_section_of_dept($pdo, $did);
    return [$did, $s ? (string)$s['code'] : ''];
}
/** which existing department fits this designation? 0 when none. */
function cq_pick_dept(PDO $pdo, $designation, array $people, array $depts) {
    $key = cq_dkey($designation); $stem = cq_stem($designation); if ($key === '') return 0;
    $byKey = []; $byStem = [];
    foreach ($people as $p) {
        $d = (int)$p['department_id']; if (!$d || !isset($depts[$d]) || cq_dkey($p['designation']) === '') continue;
        if (cq_dkey($p['designation']) === $key) $byKey[$d] = ($byKey[$d] ?? 0) + 1;
        if ($stem !== '' && cq_stem($p['designation']) === $stem) $byStem[$d] = ($byStem[$d] ?? 0) + 1;
    }
    if ($byKey) { arsort($byKey); return (int)key($byKey); }
    if ($byStem) { arsort($byStem); return (int)key($byStem); }
    if ($stem !== '') foreach ($depts as $id => $n) { foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($n), -1, PREG_SPLIT_NO_EMPTY) as $w) { if (substr($w, 0, 4) === $stem) return (int)$id; } }
    return 0;
}
/** put people who have no department into one - decided from the designation. Returns what was done. */
function cq_place_people(PDO $pdo, $by, $onlyEmp = 0) {
    $people = cq_people($pdo); $depts = cq_departments($pdo); $done = [];
    foreach ($people as $p) {
        $did = (int)$p['department_id']; if ($did && isset($depts[$did])) continue;
        if ($onlyEmp && (int)$p['id'] !== (int)$onlyEmp) continue;
        $desig = trim((string)$p['designation']);
        $pick = cq_pick_dept($pdo, $desig, $people, $depts); $created = false;
        if (!$pick) {
            if ($desig === '') continue;   // nothing to decide from: stays unplaced (it is shown as "Not placed")
            $name = cq_dept_name_for($pdo, $desig);
            $dup = array_search(mb_strtolower($name), array_map('mb_strtolower', $depts), true);
            if ($dup !== false) { $pick = (int)$dup; }
            else { list($pick, $code) = cq_create_dept($pdo, $name); $created = true; $depts[$pick] = $name; }
        }
        $pdo->prepare("UPDATE employees SET department_id = ? WHERE employee_id = ?")->execute([$pick, (int)$p['id']]);
        foreach ($people as &$pp) { if ((int)$pp['id'] === (int)$p['id']) $pp['department_id'] = $pick; } unset($pp);
        $done[] = ['emp' => (int)$p['id'], 'name' => (string)$p['name'], 'dept_id' => $pick, 'dept' => $depts[$pick], 'created' => $created];
        cq_log($pdo, $by, 'placed ' . $p['name'] . ' (' . $desig . ') in ' . $depts[$pick] . ($created ? ' (new department, made automatically)' : ''));
        if ($created) {
            $sig = 'notice:dept:' . $pick; $live = [];
            cq_ask($pdo, $live, $sig, ['kind' => 'notice', 'subject' => $depts[$pick], 'input' => 'choice', 'series' => 'structure',
                'title' => 'I made a new department: “' . $depts[$pick] . '”',
                'body' => $p['name'] . ' (' . $desig . ') had no department and none of the existing ones fitted. The department, its Boss Engine section and the position “' . cq_title_case($desig) . '” are ready. Change it if you disagree.',
                'options' => [['id' => 'ok', 'label' => 'Looks right'], ['id' => 'rename', 'label' => 'Rename it', 'input' => 'text', 'placeholder' => 'New name'], ['id' => 'move', 'label' => 'Wrong — move them to…', 'input' => 'dept']],
                'payload' => ['dept_id' => $pick, 'emp_ids' => [(int)$p['id']]]]);
        }
    }
    if ($done) { try { cq_sync_positions($pdo); } catch (Throwable $e) { error_log('cq positions: ' . $e->getMessage()); } if (function_exists('pp_cache_drop')) pp_cache_drop($pdo); }
    return $done;
}
/** one position (seat) per kind of person in each department section; holders follow the people */
function cq_sync_positions(PDO $pdo) {
    $now = date('Y-m-d H:i:s'); $people = cq_people($pdo); $changed = 0;
    foreach ($pdo->query("SELECT id, code, name, dept_ids, source FROM bd_sections WHERE active = 1 AND dept_ids IS NOT NULL AND dept_ids <> ''")->fetchAll() as $s) {
        $ids = array_map('intval', array_filter(explode(',', (string)$s['dept_ids']), 'strlen')); if (!$ids) continue;
        $groups = [];
        foreach ($people as $p) {
            if (!in_array((int)$p['department_id'], $ids, true)) continue;
            $k = cq_dkey($p['designation']); if ($k === '') continue;
            if (!isset($groups[$k])) $groups[$k] = ['title' => cq_title_case($p['designation']), 'emps' => []];
            $groups[$k]['emps'][] = (int)$p['id'];
        }
        $want = [];
        foreach ($groups as $k => $g) {
            $code = substr((string)$s['code'] . '.pos_' . bd_slug($k), 0, 60); $want[$code] = true;
            $pdo->prepare("INSERT IGNORE INTO bd_seats (section_id, code, title, kind, created_at) VALUES (?,?,?,?,?)")->execute([(int)$s['id'], $code, mb_substr($g['title'], 0, 120), 'worker', $now]);
            $pdo->prepare("UPDATE bd_seats SET active = 1, section_id = ?, title = ? WHERE code = ?")->execute([(int)$s['id'], mb_substr($g['title'], 0, 120), $code]);
            $sid = bd_seat_id($pdo, $code); if (!$sid) continue;
            $cur = $pdo->prepare("SELECT emp_id FROM bd_holders WHERE seat_id = ? ORDER BY emp_id"); $cur->execute([$sid]); $have = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
            $need = $g['emps']; sort($need);
            if ($have !== $need) { bd_set_holders($pdo, $sid, $need); $changed++; }
        }
        // positions nobody holds any more: closed (kept while a task is still owned by it)
        $q = $pdo->prepare("SELECT id, code FROM bd_seats WHERE section_id = ? AND code LIKE ? AND active = 1"); $q->execute([(int)$s['id'], $s['code'] . '.pos\_%']);
        foreach ($q->fetchAll() as $seat) {
            if (isset($want[$seat['code']])) continue;
            $n = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE seat_id = ? AND status IN ('active','paused')"); $n->execute([(int)$seat['id']]);
            $pdo->prepare("DELETE FROM bd_holders WHERE seat_id = ?")->execute([(int)$seat['id']]);
            if ((int)$n->fetchColumn() === 0) { $pdo->prepare("UPDATE bd_seats SET active = 0 WHERE id = ?")->execute([(int)$seat['id']]); $changed++; }
        }
        // a department that Claude made: its "team" seat is simply everybody in it
        if ((string)$s['source'] === 'dept') {
            $ts = bd_section_seat($pdo, (int)$s['id'], 'worker');
            if ($ts) {
                $all = []; foreach ($people as $p) if (in_array((int)$p['department_id'], $ids, true)) $all[] = (int)$p['id']; sort($all);
                $cur = $pdo->prepare("SELECT emp_id FROM bd_holders WHERE seat_id = ? ORDER BY emp_id"); $cur->execute([$ts]); $have = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
                if ($have !== $all) { bd_set_holders($pdo, $ts, $all); $changed++; }
            }
        }
    }
    return $changed;
}
/** delete a department the way People does: people move first; its section is closed when empty */
function cq_delete_dept(PDO $pdo, $id, $to) {
    $pdo->prepare("UPDATE employees SET department_id = ? WHERE department_id = ?")->execute([$to ?: null, $id]);
    $pdo->prepare("DELETE FROM departments WHERE department_id = ?")->execute([$id]);
    foreach ($pdo->query("SELECT id, code, dept_ids, source FROM bd_sections WHERE dept_ids IS NOT NULL AND dept_ids <> ''")->fetchAll() as $sx) {
        $all = array_filter(explode(',', (string)$sx['dept_ids']), 'strlen'); if (!in_array((string)$id, $all, true)) continue;
        $ids = array_values(array_filter($all, function ($v) use ($id) { return (int)$v !== $id; }));
        $pdo->prepare("UPDATE bd_sections SET dept_ids = ? WHERE id = ?")->execute([$ids ? implode(',', $ids) : null, (int)$sx['id']]);
        if (!$ids && (string)$sx['source'] === 'dept') {
            $n = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE section_code = ? AND status IN ('active','paused')"); $n->execute([(string)$sx['code']]);
            if ((int)$n->fetchColumn() === 0) { $pdo->prepare("UPDATE bd_sections SET active = 0 WHERE id = ?")->execute([(int)$sx['id']]); }
        }
    }
    if (function_exists('b2_sections')) { try { b2_sections($pdo, true); } catch (Throwable $e) {} }
}

/* ------------------------------------------------------------------ tasks */
function cq_owner_seat(PDO $pdo, $sectionCode) {
    $sid = function_exists('b2_section_id') ? b2_section_id($pdo, $sectionCode) : 0;
    if ($sid) {
        $q = $pdo->prepare("SELECT id FROM bd_seats WHERE section_id = ? AND kind = 'worker' AND active = 1 ORDER BY (code LIKE '%.worker') DESC, id"); $q->execute([$sid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $seat) if (bd_holders($pdo, (int)$seat)) return (int)$seat;
    }
    $r = b2_suggest_seat($pdo, $sectionCode, '', 'daily'); return (int)$r[0];
}
function cq_remove_item(PDO $pdo, $id, $why, $by) {
    $it = $pdo->prepare("SELECT id, status FROM b2_items WHERE id = ?"); $it->execute([(int)$id]); $r = $it->fetch(); if (!$r) return false;
    $pdo->prepare("UPDATE b2_items SET status = 'archived' WHERE id = ?")->execute([(int)$id]);
    try { $pdo->prepare("UPDATE b2_runs SET status = 'excused' WHERE item_id = ? AND status IN ('open','review')")->execute([(int)$id]); } catch (Throwable $e) {}
    try { $pdo->prepare("UPDATE b2_pens SET state = 'waived' WHERE item_id = ? AND state = 'ask'")->execute([(int)$id]); } catch (Throwable $e) {}
    if (function_exists('b2_ev')) b2_ev($pdo, (int)$id, null, null, null, 'archived', 'Removed by Claude question: ' . $why . ' (' . $by . ')');
    return true;
}
function cq_item_brief(array $r) {
    return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'pen' => (float)$r['pen_amount'], 'time' => (string)($r['due_time'] ?? ''), 'target' => $r['target'] === null ? null : (float)$r['target'], 'unit' => (string)($r['unit'] ?? ''), 'owner' => function_exists('b2_seat_title') && !empty($r['seat_id']) ? (string)b2_seat_title($GLOBALS['__cq_pdo'], (int)$r['seat_id']) : '', 'since' => substr((string)($r['created_at'] ?? ''), 0, 10)];
}

/* ------------------------------------------------------------------ the scan */
function cq_scan(PDO $pdo, $by = 'claude') {
    cq_ensure($pdo); $GLOBALS['__cq_pdo'] = $pdo;
    $got = 1; try { $got = (int)$pdo->query("SELECT GET_LOCK('cq_scan', 0)")->fetchColumn(); } catch (Throwable $e) {}
    if (!$got) return ['skipped' => true];
    $res = ['placed' => 0, 'questions' => 0];
    try {
        $res['placed'] = count(cq_place_people($pdo, $by));
        try { cq_sync_positions($pdo); } catch (Throwable $e) { error_log('cq sync: ' . $e->getMessage()); }
        $live = [];
        cq_q_splits($pdo, $live); cq_q_gaps($pdo, $live); cq_q_conflicts($pdo, $live);
        try { cq_q_handover($pdo, $live); } catch (Throwable $e) { error_log('cq handover: ' . $e->getMessage()); }
        try { if (cq_ofp_load()) ofp_cq_questions($pdo, $live); } catch (Throwable $e) { error_log('cq places: ' . $e->getMessage()); }   // who does the daily check of a place / what must the photo show
        // questions whose reason has gone (answered elsewhere, fixed in another page) disappear by themselves
        $open = $pdo->query("SELECT id, sig FROM cq_q WHERE status = 'open' AND kind IN ('gap','conflict','structure') AND sig NOT LIKE 'ofp:%'")->fetchAll();   // place questions close themselves in ofp_cq_questions
        foreach ($open as $o) if (!isset($live[$o['sig']])) $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$o['id']]);
        $res['questions'] = (int)$pdo->query("SELECT COUNT(*) FROM cq_q WHERE status = 'open'")->fetchColumn();
    } catch (Throwable $e) { error_log('cq_scan: ' . $e->getMessage()); $res['error'] = $e->getMessage(); }
    try { $pdo->query("SELECT RELEASE_LOCK('cq_scan')"); } catch (Throwable $e) {}
    return $res;
}
function cq_tick(PDO $pdo, $now) {
    try { cq_ensure($pdo); if (function_exists('pp_cache_get') && pp_cache_get($pdo, 'cq_scan', 55) !== null) return; if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => time()]); cq_scan($pdo, 'auto'); }
    catch (Throwable $e) { error_log('cq_tick: ' . $e->getMessage()); }
}

/** one department holding two kinds of people -> ask whether to split */
function cq_q_splits(PDO $pdo, array &$live) {
    $people = cq_people($pdo); $depts = cq_departments($pdo); $by = [];
    foreach ($people as $p) { $d = (int)$p['department_id']; if (!$d || !isset($depts[$d])) continue; $st = cq_stem($p['designation']); if ($st === '') continue; $by[$d][$st][] = $p; }
    foreach ($by as $did => $groups) {
        $big = []; foreach ($groups as $st => $ps) if (count($ps) >= 3) $big[$st] = $ps;
        if (count($big) < 2) continue;
        $tot = 0; foreach ($big as $ps) $tot += count($ps); if ($tot < 8) continue;
        uasort($big, function ($a, $b) { return count($b) - count($a); });
        $stems = array_keys($big); $main = $stems[0]; $other = $stems[1];
        $lab = function ($ps) { $c = []; foreach ($ps as $p) { $k = cq_title_case($p['designation']); $c[$k] = ($c[$k] ?? 0) + 1; } arsort($c); return key($c); };
        $mainLab = $lab($big[$main]); $othLab = $lab($big[$other]);
        $newName = cq_dept_name_for($pdo, $othLab);
        if (mb_strtolower($newName) === mb_strtolower($depts[$did])) continue;
        $sig = 'split:' . $did . ':' . $main . '_' . $other;
        $s = cq_section_of_dept($pdo, $did);
        cq_ask($pdo, $live, $sig, ['kind' => 'structure', 'subject' => $depts[$did], 'section_code' => $s ? $s['code'] : null, 'series' => 'structure', 'input' => 'choice',
            'title' => 'Two kinds of work in “' . $depts[$did] . '” — one department or two?',
            'body' => count($big[$main]) . ' people are ' . $mainLab . ' and ' . count($big[$other]) . ' are ' . $othLab . '. If they do different work I will make a separate department for the ' . $othLab . ' group, with its own tasks, penalties and positions, and ask my usual questions for it.',
            'options' => [['id' => 'split', 'label' => 'Two departments — make “' . $newName . '”'], ['id' => 'keep', 'label' => 'Keep as one department']],
            'payload' => ['dept_id' => $did, 'new_name' => $newName, 'emp_ids' => array_map(function ($p) { return (int)$p['id']; }, $big[$other]), 'stem' => $other]]);
    }
}

/** per department: keep asking, one question at a time, until tasks, a number, a penalty and a checker are clear */
function cq_workbench_sections() { return ['dispatch', 'accounts', 'retaildist', 'supplies']; }   // run from their own workbench pages (Boss → Workbenches): their tasks live there, so Claude does not ask the generic "what must they do" series for them
function cq_q_gaps(PDO $pdo, array &$live) {
    $people = cq_people($pdo);
    foreach ($pdo->query("SELECT id, code, name, dept_ids FROM bd_sections WHERE active = 1 AND dept_ids IS NOT NULL AND dept_ids <> '' ORDER BY id")->fetchAll() as $s) {
        $ids = array_map('intval', array_filter(explode(',', (string)$s['dept_ids']), 'strlen')); $code = (string)$s['code']; $name = (string)$s['name'];
        if (in_array($code, cq_workbench_sections(), true)) continue;
        $team = []; foreach ($people as $p) if (in_array((int)$p['department_id'], $ids, true)) $team[] = $p;
        if (!$team) continue;
        $names = implode(', ', array_slice(array_map(function ($p) { return $p['name']; }, $team), 0, 6)) . (count($team) > 6 ? ' and ' . (count($team) - 6) . ' more' : '');
        $q = $pdo->prepare("SELECT * FROM b2_items WHERE section_code = ? AND status IN ('active','paused','draft')"); $q->execute([$code]); $items = $q->fetchAll();
        $series = $code;
        // 1. what must they do?
        if (!$items) {
            cq_ask($pdo, $live, 'gap:tasks:' . $code, ['kind' => 'gap', 'subject' => $name, 'section_code' => $code, 'series' => $series, 'seq' => 1, 'input' => 'lines',
                'title' => 'What must ' . $name . ' do every day?',
                'body' => $name . ' has ' . count($team) . ' ' . (count($team) === 1 ? 'person' : 'people') . ' (' . $names . ') and no tasks yet. Write one duty per line in plain words — time, proof or penalty if you like. I turn each line into a task for the team and ask the next question after.',
                'options' => [], 'payload' => ['example' => "Pack all orders by 6 pm, photo of the packed shelf\nClean the packing table every day at 7 pm"]], true);
            continue;
        }
        // 2. is there a number to hit?
        $hasNum = false; $hasPen = false;
        foreach ($items as $it) { if ($it['kind'] === 'number') $hasNum = true; if ((float)$it['pen_amount'] > 0) $hasPen = true; }
        if (!$hasNum && !cq_decided($pdo, 'gap:number:' . $code)) {
            cq_ask($pdo, $live, 'gap:number:' . $code, ['kind' => 'gap', 'subject' => $name, 'section_code' => $code, 'series' => $series, 'seq' => 2, 'input' => 'text',
                'title' => 'Is there a number ' . $name . ' should reach every day?',
                'body' => 'For example “Parcels packed, 150” or “Orders called, 40”. Each day they type the number with a screenshot and I show it against the target. If this team has no countable work, choose that.',
                'options' => [['id' => 'none', 'label' => 'No daily number for this team']], 'payload' => ['placeholder' => 'What is counted, target (e.g. Parcels packed, 150)']]);
            continue;
        }
        // 3. what happens when it is missed?
        if (!$hasPen && !cq_decided($pdo, 'gap:penalty:' . $code)) {
            cq_ask($pdo, $live, 'gap:penalty:' . $code, ['kind' => 'gap', 'subject' => $name, 'section_code' => $code, 'series' => $series, 'seq' => 3, 'input' => 'amount',
                'title' => 'What happens when ' . $name . ' misses a task?',
                'body' => 'Pick the penalty for one missed task. It is only ever asked to you first (never automatic), and the first days are free. It goes straight into the person’s penalty record when you charge it.',
                'options' => [['id' => 'none', 'label' => 'No penalty — just show it'], ['id' => '10', 'label' => '₹10'], ['id' => '20', 'label' => '₹20'], ['id' => '50', 'label' => '₹50'], ['id' => '100', 'label' => '₹100']], 'payload' => ['max' => 500]]);
            continue;
        }
        // 4. who checks?
        $ck = bd_section_seat($pdo, (int)$s['id'], 'checker');
        if ($ck && !bd_holders($pdo, $ck) && !cq_decided($pdo, 'gap:checker:' . $code)) {
            $opt = []; foreach ($team as $p) $opt[] = ['id' => (string)$p['id'], 'label' => $p['name'] . ($p['designation'] ? ' — ' . $p['designation'] : '')];
            $opt[] = ['id' => 'skip', 'label' => 'Nobody — I check it myself'];
            cq_ask($pdo, $live, 'gap:checker:' . $code, ['kind' => 'gap', 'subject' => $name, 'section_code' => $code, 'series' => $series, 'seq' => 4, 'input' => 'choice',
                'title' => 'Who checks the work of ' . $name . '?',
                'body' => 'The checker confirms proof when something looks wrong and sees the team’s misses first.', 'options' => $opt]);
        }
    }
}

/** a new rule that clashes with an old one */
function cq_q_conflicts(PDO $pdo, array &$live) {
    // (a) tasks in the same department that say nearly the same thing but differ
    $by = [];
    foreach ($pdo->query("SELECT * FROM b2_items WHERE status = 'active' AND section_code IS NOT NULL AND section_code <> '' AND block IN ('repeat','number') ORDER BY id")->fetchAll() as $it) $by[$it['section_code']][] = $it;
    foreach ($by as $code => $its) {
        $its = array_slice($its, -150); $n = count($its); $asked = false;
        for ($i = 0; $i < $n && !$asked; $i++) for ($j = $n - 1; $j > $i && !$asked; $j--) {   // one clash per department at a time: the oldest rule against its newest twin
            $a = $its[$i]; $b = $its[$j];
            if (bd_sim(implode(' ', b2_words($a['title'])), implode(' ', b2_words($b['title']))) < 0.6) continue;
            $diff = [];
            if (abs((float)$a['pen_amount'] - (float)$b['pen_amount']) > 0.001) $diff[] = 'penalty';
            if ((string)$a['due_time'] !== (string)$b['due_time']) $diff[] = 'time';
            if ($a['target'] !== null && $b['target'] !== null && abs((float)$a['target'] - (float)$b['target']) > 0.001) $diff[] = 'target';
            if (!$diff) continue;
            $dept = function_exists('b2_section_name') ? b2_section_name($pdo, $code) : $code;
            cq_ask($pdo, $live, 'conf:item:' . $a['id'] . ':' . $b['id'], ['kind' => 'conflict', 'subject' => $dept, 'section_code' => $code, 'series' => 'conflict', 'input' => 'choice',
                'title' => 'Two ' . $dept . ' rules say almost the same thing, with a different ' . implode(' and ', $diff),
                'body' => 'The newer one is “' . $b['title'] . '”, the older one is “' . $a['title'] . '”. Only one can stay. Keeping the new one removes the old one entirely (its open runs are excused; penalties already charged stay on people’s records).',
                'options' => [['id' => 'keep_new', 'label' => 'Keep the new, remove the old entirely', 'tone' => 'go'], ['id' => 'keep_old', 'label' => 'Keep the old, drop the new']],
                'payload' => ['kind' => 'item', 'new' => cq_item_brief($b), 'old' => cq_item_brief($a), 'diff' => $diff]]);
            $asked = true;
        }
    }
    // (b) two active penalty rules for the same trigger and department with different amounts
    try {
        $g = [];
        foreach ($pdo->query("SELECT rule_id, name, amount, department, trigger_type FROM penalty_rules WHERE active = 1 AND trigger_type IS NOT NULL AND trigger_type <> '' ORDER BY rule_id")->fetchAll() as $r) $g[mb_strtolower(trim((string)$r['department'])) . '|' . $r['trigger_type']][] = $r;
        foreach ($g as $rules) {
            if (count($rules) < 2) continue; $new = $rules[count($rules) - 1];
            foreach (array_slice($rules, 0, -1) as $old) {
                if (abs((float)$old['amount'] - (float)$new['amount']) < 0.001) continue;
                $dep = trim((string)$new['department']) !== '' ? $new['department'] : 'everyone';
                cq_ask($pdo, $live, 'conf:rule:' . $old['rule_id'] . ':' . $new['rule_id'], ['kind' => 'conflict', 'subject' => $dep, 'series' => 'conflict', 'input' => 'choice',
                    'title' => 'Two penalty rules for the same thing (' . $dep . ')',
                    'body' => '“' . $new['name'] . '” (₹' . (float)$new['amount'] . ') is newer than “' . $old['name'] . '” (₹' . (float)$old['amount'] . ') and both are on. Only one can charge people.',
                    'options' => [['id' => 'keep_new', 'label' => 'Keep the new, remove the old entirely', 'tone' => 'go'], ['id' => 'keep_old', 'label' => 'Keep the old, drop the new']],
                    'payload' => ['kind' => 'rule', 'new' => ['id' => (int)$new['rule_id'], 'title' => $new['name'], 'pen' => (float)$new['amount']], 'old' => ['id' => (int)$old['rule_id'], 'title' => $old['name'], 'pen' => (float)$old['amount']]]]);
            }
        }
    } catch (Throwable $e) { /* penalty_rules may not exist on a fresh install */ }
}

/* ------------------------------------------------------------------ handover: a person leaves
 *  Marked "left" (status ex) anywhere -> within a minute Claude:
 *   1. stops everything that would keep sending work or access to him (seats, login + live sessions, notifications, pending penalties, shifts of the future, targets);
 *   2. asks ONE question: who takes over the work (suggests the best fit; "someone else" or "leave empty" are allowed);
 *   3. moves every open thing they owned, in every panel, to that person, then checks again and shows what is left (the aim: 0).
 *  Charged penalties, attendance, documents and finished work are history and stay as they are (final salary needs them).
 *  New panels are covered too: any table with a person column Claude does not know shows up as "not classified" instead of being forgotten. */
function cq_ho_closed() { return "('done','closed','resolved','excused','missed','cancelled','canceled','archived','completed','dropped','applied','spam','dismissed','moved','rejected','waived')"; }
/** open work that is moved to the new person: [label, table, column, "still open" condition] */
function cq_ho_move_map() {
    $g = 'status NOT IN ' . cq_ho_closed();
    return [
        ['tasks to do', 'b2_runs', 'owner_emp', "status = 'open'"],
        ['tasks tied to the person', 'b2_items', 'link_emp', "status = 'active'"],
        ['boss tasks', 'bd_tasks', 'owner_emp', "status IN ('open','unowned')"],
        ['inbox items', 'bd_inbox', 'to_emp', "status = 'open'"],
        ['business cases', 'be_cases', 'owner_emp', "status <> 'closed'"],
        ['business tasks', 'be_tasks', 'owner_emp', $g], ['business tasks to check', 'be_tasks', 'checker_emp', $g],
        ['documents to keep valid', 'be_docs', 'owner_emp', "status = 'active'"],
        ['customer cases', 'cx_cases', 'owner_emp', "status NOT IN ('closed','resolved')"],
        ['department work items', 'dp_items', 'owner_emp', $g],
        ['department tasks', 'dp_tk_tasks', 'owner_emp', $g], ['department tasks to check', 'dp_tk_tasks', 'checker_emp', $g],
        ['fix jobs', 'dp_fx_jobs', 'assigned_emp', $g], ['fix jobs followed', 'dp_fx_jobs', 'follower_emp', $g],
        ['things to buy', 'dp_su_needs', 'assigned_emp', $g],
        ['parcels without tracking', 'dp_dsp_untr_case', 'owner_emp', 'ended_at IS NULL'],
        ['social messages', 'sx_items', 'owner_emp', $g],
        ['WhatsApp chats', 'wa_contacts', 'assigned_emp', "status NOT IN ('closed','resolved')"],
        ['daily store tasks', 'store_tasks', 'employee_id', 'active = 1'],
        ['office tickets', 'of_tickets', 'assigned_to', $g],
        ['flyer routes ahead', 'fl_assign', 'emp_id', 'assign_date >= CURDATE()'],
    ];
}
/** lists of person ids kept in one text column: [label, table, key, column, moves to the new person?] */
function cq_ho_csv_map() { return [['WhatsApp numbers handled', 'wa_numbers', 'id', 'handlers', true], ['punch-proof rules naming the person', 'punch_proof_rules', 'rule_id', 'employee_ids', false]]; }
/** tables that hold a person but are history / settings: never moved, never "unknown" */
function cq_ho_known_tables() {
    $t = ['attendance', 'b2_builds', 'b2_docs', 'b2_ev', 'b2_pens', 'b2_problems', 'bd_duties', 'bd_holders', 'bd_interview', 'bd_photos', 'be_progress', 'campaign_queries', 'ck_doc_reads', 'ck_findings', 'cm_boosts', 'cm_offers', 'commission_fixed_plans', 'commission_manual',
        'convo_hashes', 'convo_uploads', 'ct_alerts', 'ct_behavior', 'ct_focus', 'ct_idea_state', 'ct_popups', 'ct_remarks', 'cx_credit', 'cx_events', 'cx_files', 'cx_links', 'cx_qc', 'day_marks', 'dp_dsp_parcels', 'dp_dsp_parcels_bak', 'dp_dsp_untr', 'dp_dsp_untr_day', 'dp_dsp_untr_miss',
        'dp_mn_entries', 'dp_proof_hash', 'dp_sh_visit', 'dp_su_checks', 'dp_su_ledger', 'dp_tk_builds', 'dp_tk_updates', 'dp_wk_shots', 'employee_addons', 'employee_documents', 'employee_penalties', 'employee_query_log', 'employee_shifts', 'employee_store_groups', 'fin_documents', 'fin_journal', 'fin_uploads',
        'fl_drops', 'leave_requests', 'lg_journal', 'lg_sources', 'office_presence', 'of_checks', 'of_offices', 'order_allocations', 'order_ratings', 'order_targets', 'order_target_hours', 'order_target_staff', 'penalty_notifications', 'ph_photos', 'punch_proofs', 'punch_reminders', 'push_log', 'push_subscriptions',
        'rating_daily', 'retail_cash_use', 'retail_demo_display', 'retail_display', 'retail_orders', 'retail_payments', 'retail_perfume_sets', 'retail_shops', 'retail_stock_log', 'retail_stock_seen', 'retail_visits', 'retail_visit_plan', 'store_task_days', 'store_task_photos', 'sx_conn', 'sx_msgs', 'users',
        'wa_assign_log', 'wa_query_moves', 'query_moves', 'dp_su_bought', 'wa_campaigns', 'wa_exports', 'wa_flows', 'wa_learn', 'wa_media_lib', 'wa_media_use', 'wa_messages', 'wa_notes', 'wa_outcomes', 'wa_queries', 'wa_templates', 'bd_settings', 'cq_ho', 'cq_q'];
    foreach (cq_ho_move_map() as $m) $t[] = $m[1];
    foreach (cq_ho_csv_map() as $m) $t[] = $m[1];
    return array_flip($t);
}
/** "WhatsApp chats: 13,822" — a label and a count, so no plural to get wrong */
function cq_ho_cnt($label, $n) { return $label . ': ' . number_format((int)$n); }
function cq_ho_ensure(PDO $pdo) {
    static $d = false; if ($d) return; $d = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS cq_ho (emp_id INT PRIMARY KEY, state VARCHAR(10) NOT NULL DEFAULT 'await', successor INT NULL, snapshot MEDIUMTEXT NULL, report MEDIUMTEXT NULL, created_at DATETIME NOT NULL, done_at DATETIME NULL, checked_at DATETIME NULL, parked_until DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
    catch (Throwable $e) { error_log('cq_ho_ensure: ' . $e->getMessage()); }
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['cq_ho']);
}
function cq_ho_n(PDO $pdo, $sql, array $a = []) { try { $s = $pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); } catch (Throwable $e) { return 0; } }
function cq_ho_ids($v) {
    $v = trim((string)$v); if ($v === '') return [[], false];
    if ($v[0] === '[') { $a = json_decode($v, true); if (is_array($a)) return [array_values(array_map('intval', $a)), true]; }
    return [array_values(array_map('intval', array_filter(array_map('trim', explode(',', $v)), 'strlen'))), false];
}
function cq_ho_fmt(array $ids, $json) { $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); return $json ? json_encode($ids) : implode(',', $ids); }
/** rows of a list column that name the person; with $to = 0 they are only counted, with $to = -1 they are removed, with $to > 0 he is replaced */
function cq_ho_csv(PDO $pdo, $table, $key, $col, $E, $to) {
    $n = 0;
    try {
        $s = $pdo->prepare("SELECT `$key` k, `$col` v FROM `$table` WHERE `$col` LIKE ?"); $s->execute(['%' . (int)$E . '%']);
        foreach ($s->fetchAll() as $r) {
            list($ids, $json) = cq_ho_ids($r['v']); if (!in_array((int)$E, $ids, true)) continue; $n++;
            if ($to === 0) continue;
            $new = array_values(array_filter($ids, function ($x) use ($E) { return $x !== (int)$E; })); if ($to > 0) $new[] = (int)$to;
            $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$key` = ?")->execute([cq_ho_fmt($new, $json), $r['k']]);
        }
    } catch (Throwable $e) {}
    return $n;
}
/** tables with a person column that Claude has never been told about (new panels): the safety net so nothing is forgotten */
function cq_ho_unknown_cols(PDO $pdo) {
    if (function_exists('pp_cache_get')) { $c = pp_cache_get($pdo, 'cq_ho_cols', 600); if (is_array($c)) return $c['cols']; }
    $known = cq_ho_known_tables(); $o = []; $ign = function_exists('bd_cfg_fresh') ? array_filter(explode(',', (string)bd_cfg_fresh('cq_ho_ignore')), 'strlen') : [];
    try {
        $in = "'" . implode("','", ['emp_id', 'employee_id', 'owner_emp', 'assigned_to', 'assignee_id', 'assigned_emp', 'checker_emp', 'link_emp', 'to_emp', 'follower_emp', 'holder_emp', 'buyer_emp', 'first_emp']) . "'";
        foreach ($pdo->query("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN ($in) AND data_type IN ('int','bigint','mediumint','smallint') AND table_name <> 'employees'")->fetchAll() as $r) {
            if (!isset($known[$r['t']]) && substr($r['t'], 0, 3) !== 'cq_' && !in_array($r['t'] . '.' . $r['c'], $ign, true)) $o[] = [(string)$r['t'], (string)$r['c']];
        }
    } catch (Throwable $e) {}
    if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_ho_cols', ['cols' => $o]);
    return $o;
}
/** what is still tied to this person: [rows of [label, n]], unknown [table.col, n], seats */
function cq_ho_refs(PDO $pdo, $E) {
    $E = (int)$E; $by = []; $unk = [];
    foreach (cq_ho_move_map() as $m) {
        $n = cq_ho_n($pdo, "SELECT COUNT(*) FROM `{$m[1]}` WHERE `{$m[2]}` = ? AND ({$m[3]})", [$E]);
        if ($n > 0) $by[$m[0]] = ($by[$m[0]] ?? 0) + $n;
    }
    foreach (cq_ho_csv_map() as $m) { $n = cq_ho_csv($pdo, $m[1], $m[2], $m[3], $E, 0); if ($n > 0) $by[$m[0]] = ($by[$m[0]] ?? 0) + $n; }
    foreach (cq_ho_unknown_cols($pdo) as $tc) {
        $n = cq_ho_n($pdo, "SELECT COUNT(*) FROM (SELECT 1 FROM `{$tc[0]}` WHERE `{$tc[1]}` = ? LIMIT 200) x", [$E]);
        if ($n > 0) $unk[] = ['table' => $tc[0] . '.' . $tc[1], 'n' => $n];
    }
    $rows = []; foreach ($by as $k => $n) $rows[] = ['label' => $k, 'n' => $n];
    return ['rows' => $rows, 'unknown' => $unk, 'total' => array_sum($by), 'unk_total' => array_sum(array_column($unk, 'n'))];
}
function cq_ho_emp(PDO $pdo, $E) { $s = $pdo->prepare("SELECT e.employee_id id, e.full_name name, e.designation, e.department_id, e.status, d.department_name dept FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $s->execute([(int)$E]); return $s->fetch() ?: null; }
/** stage 1 — stop everything that keeps sending work or access to him. Safe to repeat. */
function cq_ho_auto(PDO $pdo, $E, $by) {
    $E = (int)$E; $now = date('Y-m-d H:i:s'); $r = ['seats' => [], 'top' => [], 'pens' => 0, 'login' => '', 'off' => []];
    $s = $pdo->prepare("SELECT DISTINCT s.id, s.code, s.title, s.kind, s.section_id, s.active, (SELECT name FROM bd_sections WHERE id = s.section_id) section FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id WHERE h.emp_id = ?"); $s->execute([$E]);
    foreach ($s->fetchAll() as $q) {
        if (preg_match('/^p\.' . $E . '$/', (string)$q['code'])) { $pdo->prepare("UPDATE bd_seats SET active = 0 WHERE id = ?")->execute([(int)$q['id']]); continue; }   // his own personal seat
        if ((int)$q['active'] === 1) $r['seats'][] = ['id' => (int)$q['id'], 'code' => $q['code'], 'title' => $q['title'], 'kind' => $q['kind'], 'section' => (string)$q['section']];
    }
    foreach (['top_boss', 'deputy'] as $k) { if (function_exists('bd_cfg_fresh') && (int)bd_cfg_fresh($k) === $E) { $r['top'][] = $k; if (function_exists('bd_set')) bd_set($k, ''); } }
    $pdo->prepare("DELETE FROM bd_holders WHERE emp_id = ?")->execute([$E]);
    try { $x = $pdo->prepare("UPDATE b2_pens SET state = 'waived', decided_by = ?, decided_at = ?, note = COALESCE(NULLIF(note,''), 'Person left') WHERE emp_id = ? AND state = 'ask'"); $x->execute([$by, $now, $E]); $r['pens'] = $x->rowCount(); } catch (Throwable $e) {}
    try {   // the login goes with live sessions: the app re-reads the users row on every request
        $us = $pdo->prepare("SELECT user_id, username, role FROM users WHERE employee_id = ?"); $us->execute([$E]);
        foreach ($us->fetchAll() as $u) {
            if ((string)$u['role'] === 'Admin' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn() <= 1) { $r['login'] = 'kept — he is the last Admin'; continue; }
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([(int)$u['user_id']]); $r['login'] = 'removed (' . $u['username'] . ', ' . $u['role'] . ')';
        }
    } catch (Throwable $e) {}
    foreach ([['push_subscriptions', 'employee_id', ''], ['order_target_staff', 'employee_id', ''], ['employee_store_groups', 'employee_id', ''], ['order_targets', 'employee_id', ' AND target_date >= CURDATE()'], ['punch_reminders', 'employee_id', ' AND reminder_date >= CURDATE()']] as $o) {
        try { $x = $pdo->prepare("DELETE FROM `{$o[0]}` WHERE `{$o[1]}` = ?{$o[2]}"); $x->execute([$E]); if ($x->rowCount()) $r['off'][] = $o[0]; } catch (Throwable $e) {}
    }
    try { $x = $pdo->prepare("UPDATE commission_fixed_plans SET enabled = 0 WHERE employee_id = ? AND enabled = 1"); $x->execute([$E]); if ($x->rowCount()) $r['off'][] = 'commission_fixed_plans'; } catch (Throwable $e) {}
    foreach (cq_ho_csv_map() as $m) if (!$m[4]) { $n = cq_ho_csv($pdo, $m[1], $m[2], $m[3], $E, -1); if ($n) $r['off'][] = $m[1]; }
    return $r;
}
/** best people to take over: same designation + department first, then lightest load */
function cq_ho_candidates(PDO $pdo, $E, $max = 3) {
    $me = cq_ho_emp($pdo, $E); $ld = [];
    try { foreach ($pdo->query("SELECT h.emp_id, COUNT(*) c FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id AND s.active = 1 GROUP BY h.emp_id")->fetchAll() as $r) $ld[(int)$r['emp_id']] = (int)$r['c']; } catch (Throwable $e) {}
    $out = [];
    foreach (cq_people($pdo) as $p) {
        if ((int)$p['id'] === (int)$E) continue;
        $sd = $me && (int)$p['department_id'] === (int)$me['department_id'] && (int)$p['department_id'] > 0; $sg = $me && cq_dkey($p['designation']) !== '' && cq_dkey($p['designation']) === cq_dkey($me['designation']);
        $out[] = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'score' => ($sg ? 3 : 0) + ($sd ? 2 : 0), 'load' => $ld[(int)$p['id']] ?? 0, 'line' => ($sg && $sd ? 'same role and department' : ($sd ? 'same department' : ($sg ? 'same role' : 'free to help'))) . ' · ' . ($ld[(int)$p['id']] ?? 0) . (($ld[(int)$p['id']] ?? 0) === 1 ? ' seat' : ' seats')];
    }
    usort($out, function ($a, $b) { return $b['score'] <=> $a['score'] ?: $a['load'] <=> $b['load'] ?: strcmp($a['name'], $b['name']); });
    return array_slice($out, 0, $max);
}
function cq_ho_checklist() { return ['Shopify staff account', 'Meta (Facebook / Instagram) ad account role', 'Google Business Profile and Google Ads access', 'WhatsApp Business app or number on their phone', 'Company email, Hostinger and server logins', 'Phone, SIM, keys, laptop and ID card']; }
/** the person's handover record (for the People page) */
function cq_ho_summary(PDO $pdo, $E) {
    cq_ho_ensure($pdo); $s = $pdo->prepare("SELECT * FROM cq_ho WHERE emp_id = ?"); $s->execute([(int)$E]); $r = $s->fetch(); if (!$r) return null;
    $rep = json_decode((string)$r['report'], true) ?: []; $succ = (int)$r['successor'];
    return ['state' => $r['state'], 'successor' => $succ ?: null, 'successor_name' => $succ ? bd_emp_name($pdo, $succ) : '', 'done_at' => $r['done_at'], 'seats' => count($rep['seats'] ?? []), 'moved' => $rep['moved'] ?? [], 'left' => $rep['left'] ?? null, 'login' => $rep['login'] ?? ''];
}
function cq_ho_body(PDO $pdo, $E, array $snap, array $refs, $name) {
    $l = [];
    $seats = array_values(array_unique(array_map(function ($s) { return $s['title'] . ($s['section'] !== '' ? ' (' . $s['section'] . ')' : ''); }, $snap['seats'] ?? [])));
    if ($seats) $l[] = (count($seats) === 1 ? 'Seat: ' : 'Seats: ' . count($seats) . ' — ') . implode(', ', array_slice($seats, 0, 4)) . (count($seats) > 4 ? ' …' : '') . '.';
    if (!empty($snap['top'])) $l[] = 'Top role: ' . implode(' and ', array_map(function ($k) { return $k === 'top_boss' ? 'top boss' : 'deputy'; }, $snap['top'])) . '.';
    if ($refs['rows']) $l[] = 'Open work — ' . implode(' · ', array_map(function ($r) { return cq_ho_cnt($r['label'], $r['n']); }, $refs['rows'])) . '.';
    if ($refs['unknown']) $l[] = 'Panels I do not know yet: ' . implode(', ', array_map(function ($r) { return number_format($r['n']) . ' in ' . $r['table']; }, $refs['unknown'])) . ' — I never guess; I will ask about them after this.';
    $d = []; if (!empty($snap['login'])) $d[] = 'login ' . $snap['login']; if (!empty($snap['pens'])) $d[] = $snap['pens'] . ' waiting penalt' . ($snap['pens'] === 1 ? 'y' : 'ies') . ' waived'; if (!empty($snap['seats'])) $d[] = 'removed from their seats';
    $l[] = 'Already done by me: ' . ($d ? implode(', ', $d) : 'nothing was open on the login') . '. Charged penalties, attendance and finished work stay for the final settlement.';
    return implode("\n", $l);
}
/** the question for one leaver */
function cq_ho_ask(PDO $pdo, array &$live, $E, array $snap, $reopen = false) {
    $me = cq_ho_emp($pdo, $E); if (!$me) return; $refs = cq_ho_refs($pdo, $E); $name = (string)$me['name']; $first = trim(explode(' ', $name)[0]);
    $opts = []; foreach (cq_ho_candidates($pdo, $E, 3) as $c) $opts[] = ['id' => (string)$c['id'], 'label' => $c['name'] . ' — ' . $c['line']];
    $opts[] = ['id' => 'other', 'label' => 'Someone else…', 'input' => 'person']; $opts[] = ['id' => 'empty', 'label' => 'Leave empty — I will hire (ask me again in 7 days)'];
    $people = []; foreach (cq_people($pdo) as $p) if ((int)$p['id'] !== (int)$E) $people[] = ['id' => (int)$p['id'], 'name' => (string)$p['name']];
    cq_ask($pdo, $live, 'ho:' . (int)$E, ['kind' => 'handover', 'subject' => $name, 'series' => 'handover', 'input' => 'choice', 'title' => $name . ' left. Who takes over ' . $first . '’s work?',
        'body' => cq_ho_body($pdo, $E, $snap, $refs, $name), 'options' => $opts, 'payload' => ['emp' => (int)$E, 'people' => $people, 'rows' => $refs['rows']]], $reopen);
}
/** the generator: finds people marked as left, does stage 1, asks, and re-checks the ones already handed over */
function cq_q_handover(PDO $pdo, array &$live) {
    cq_ho_ensure($pdo); $now = date('Y-m-d H:i:s'); $budget = 6;
    // came back? the record is closed with a note; if they leave again it starts fresh
    foreach ($pdo->query("SELECT h.emp_id FROM cq_ho h JOIN employees e ON e.employee_id = h.emp_id WHERE LOWER(COALESCE(e.status,'')) <> 'ex' AND h.state <> 'back'")->fetchAll(PDO::FETCH_COLUMN) as $E) {
        $pdo->prepare("UPDATE cq_ho SET state = 'back' WHERE emp_id = ?")->execute([(int)$E]); $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE sig = ? AND status = 'open'")->execute([$now, 'ho:' . (int)$E]);
        $nm = bd_emp_name($pdo, (int)$E);
        cq_ask($pdo, $live, 'ho:back:' . (int)$E . ':' . date('ymd'), ['kind' => 'notice', 'subject' => $nm, 'series' => 'handover', 'input' => 'choice', 'title' => $nm . ' is active again',
            'body' => 'The handover is not undone: the login was removed and the seats and open work went to someone else. Add the login again in Users and the seats in People if needed.', 'options' => [['id' => 'ok', 'label' => 'Got it']], 'payload' => ['emp' => (int)$E]]);
    }
    // new leavers
    $st = $pdo->query("SELECT e.employee_id FROM employees e LEFT JOIN cq_ho h ON h.emp_id = e.employee_id WHERE LOWER(COALESCE(e.status,'')) = 'ex' AND (h.emp_id IS NULL OR h.state = 'back') ORDER BY e.employee_id");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $E) {
        if ($budget-- <= 0) break; $E = (int)$E;
        $snap = cq_ho_auto($pdo, $E, 'claude'); $refs = cq_ho_refs($pdo, $E);
        $nothing = !$snap['seats'] && !$snap['top'] && $refs['total'] === 0 && $refs['unk_total'] === 0;
        $pdo->prepare("REPLACE INTO cq_ho (emp_id, state, snapshot, created_at, done_at) VALUES (?,?,?,?,?)")->execute([$E, $nothing ? 'none' : 'await', json_encode($snap, JSON_UNESCAPED_UNICODE), $now, $nothing ? $now : null]);
        if ($nothing) { cq_log($pdo, 'claude', bd_emp_name($pdo, $E) . ' left: nothing was open, login and notifications closed'); continue; }
        cq_ho_ask($pdo, $live, $E, $snap, true);   // true: a person who left, came back and left again is asked again
        cq_log($pdo, 'claude', bd_emp_name($pdo, $E) . ' left: seats and login closed, asking who takes over');
    }
    // people still waiting keep their question alive and fresh (the open work changes while it waits)
    foreach ($pdo->query("SELECT emp_id, snapshot FROM cq_ho WHERE state = 'await'")->fetchAll() as $h) cq_ho_ask($pdo, $live, (int)$h['emp_id'], json_decode((string)$h['snapshot'], true) ?: []);
    // parked ("leave empty"): ask again after 7 days; done: look again for 30 days in case a panel still holds something
    foreach ($pdo->query("SELECT emp_id, snapshot FROM cq_ho WHERE state = 'parked' AND parked_until <= CURDATE()")->fetchAll() as $h) {
        $pdo->prepare("UPDATE cq_ho SET state = 'await' WHERE emp_id = ?")->execute([(int)$h['emp_id']]); cq_ho_ask($pdo, $live, (int)$h['emp_id'], json_decode((string)$h['snapshot'], true) ?: [], true);
    }
    // questions whose reason has gone disappear by themselves: a panel that has no rows left (or was classified as history), a top boss chosen elsewhere
    $rc = [];
    foreach ($pdo->query("SELECT id, sig FROM cq_q WHERE status = 'open' AND (sig LIKE 'ho:unk:%' OR sig LIKE 'ho:top:%')")->fetchAll() as $o) {
        $x = explode(':', $o['sig'], 4); $gone = false;
        if ($x[1] === 'unk') { $E = (int)$x[2]; if (!isset($rc[$E])) $rc[$E] = array_column(cq_ho_refs($pdo, $E)['unknown'], 'table'); $gone = !in_array($x[3], $rc[$E], true); }
        elseif ($x[1] === 'top') { $gone = function_exists('bd_cfg_fresh') && (int)bd_cfg_fresh(str_replace('ho:top:' . (int)$x[2] . ':', '', $o['sig'])) > 0; }
        if ($gone) $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE id = ?")->execute([$now, (int)$o['id']]);
    }
    $chk = $pdo->query("SELECT emp_id, snapshot FROM cq_ho WHERE state IN ('done','none') AND done_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) ORDER BY checked_at LIMIT 5")->fetchAll();
    foreach ($chk as $h) {
        $E = (int)$h['emp_id']; $refs = cq_ho_refs($pdo, $E); $pdo->prepare("UPDATE cq_ho SET checked_at = ? WHERE emp_id = ?")->execute([$now, $E]);
        if ($refs['total'] > 0) { $pdo->prepare("UPDATE cq_ho SET state = 'await' WHERE emp_id = ?")->execute([$E]); cq_ho_ask($pdo, $live, $E, json_decode((string)$h['snapshot'], true) ?: [], true); }
        elseif ($refs['unknown']) cq_ho_unk_ask($pdo, $live, $E, (int)($pdo->query("SELECT successor FROM cq_ho WHERE emp_id = " . $E)->fetchColumn()), $refs);
    }
}
/** a panel Claude does not know holds something of the person: ask once per panel — give it to the new person, or treat the panel as history from now on */
function cq_ho_unk_ask(PDO $pdo, array &$live, $E, $S, array $refs) {
    $nm = bd_emp_name($pdo, $E); $sn = $S ? bd_emp_name($pdo, $S) : '';
    foreach ($refs['unknown'] as $u) {
        $o = [['id' => 'keep', 'label' => 'Keep as history — never ask about ' . explode('.', $u['table'])[0] . ' again']]; if ($S) array_unshift($o, ['id' => 'give', 'label' => 'Give these to ' . $sn]);
        cq_ask($pdo, $live, 'ho:unk:' . (int)$E . ':' . substr($u['table'], 0, 80), ['kind' => 'handover', 'subject' => $nm, 'series' => 'handover', 'input' => 'choice', 'title' => $u['n'] . ' row' . ($u['n'] === 1 ? ' in “' : 's in “') . explode('.', $u['table'])[0] . '” ' . ($u['n'] === 1 ? 'is' : 'are') . ' still under ' . $nm,
            'body' => 'I do not know this panel yet, so I did not touch it. Is this work that must move, or is it history (a log, a record of the past)?', 'options' => $o, 'payload' => ['emp' => (int)$E, 'to' => (int)$S, 'target' => $u['table']]]);
    }
}
/** answering the question: move everything to the new person, check again, report */
function cq_ho_do(PDO $pdo, $E, $S, $by) {
    $E = (int)$E; $S = (int)$S; $now = date('Y-m-d H:i:s');
    $row = $pdo->prepare("SELECT * FROM cq_ho WHERE emp_id = ?"); $row->execute([$E]); $h = $row->fetch(); $snap = $h ? (json_decode((string)$h['snapshot'], true) ?: []) : [];
    if ($S <= 0) { $pdo->prepare("UPDATE cq_ho SET state = 'parked', parked_until = DATE_ADD(CURDATE(), INTERVAL 7 DAY) WHERE emp_id = ?")->execute([$E]); return ['Left empty for now. I will ask again in 7 days.', 'empty']; }
    $se = cq_ho_emp($pdo, $S); if (!$se || strtolower((string)$se['status']) === 'ex') json_response(['error' => 'Choose a person who works here now.'], 422);
    $moved = []; $given = 0; $seatNames = [];
    foreach ((array)($snap['seats'] ?? []) as $q) {   // seats nobody else holds go to the new person (positions follow the designation and fill by themselves)
        if (strpos((string)$q['code'], '.pos_') !== false) continue;
        if (!bd_holders($pdo, (int)$q['id'])) { bd_set_holders($pdo, (int)$q['id'], [$S]); $given++; $seatNames[] = $q['title']; }
    }
    if ($given) $moved[] = ['label' => 'seats', 'n' => $given];
    $date = date('Y-m-d');
    foreach (cq_ho_move_map() as $m) {
        try {
            $wqPre = ($m[1] === 'wa_contacts' && function_exists('wq_handover_pre')) ? wq_handover_pre($pdo, $E, $m[3]) : [];   // WhatsApp Sales: the chats that are about to move (the bulk update below skips the assign log and the query ledger)
            $set = "`{$m[2]}` = ?"; $args = [$S];
            if ($m[1] === 'wa_contacts') { try { $pdo->query("SELECT assigned_at FROM wa_contacts LIMIT 0"); $set .= ", assigned_at = NOW()"; } catch (Throwable $e) {} }
            if ($m[1] === 'fl_assign') { $set .= ", emp_name = ?"; $args[] = (string)$se['name']; }
            $x = $pdo->prepare("UPDATE `{$m[1]}` SET $set WHERE `{$m[2]}` = ? AND ({$m[3]})"); $x->execute(array_merge($args, [$E]));
            if ($wqPre && function_exists('wq_handover_post')) wq_handover_post($pdo, $wqPre, $E, $S);
            if ($x->rowCount() > 0) { $k = $m[0]; $f = false; foreach ($moved as &$mv) if ($mv['label'] === $k) { $mv['n'] += $x->rowCount(); $f = true; } unset($mv); if (!$f) $moved[] = ['label' => $k, 'n' => $x->rowCount()]; }
        } catch (Throwable $e) { error_log('cq_ho move ' . $m[1] . ': ' . $e->getMessage()); }
    }
    foreach (cq_ho_csv_map() as $m) if ($m[4]) { $n = cq_ho_csv($pdo, $m[1], $m[2], $m[3], $E, $S); if ($n) $moved[] = ['label' => $m[0], 'n' => $n]; }
    // not classified yet: Claude does not guess, it only reports (the card says which table)
    $refs = cq_ho_refs($pdo, $E);
    $emp = cq_ho_emp($pdo, $E); $dn = '';
    if ($emp && (int)$emp['department_id'] > 0 && cq_ho_n($pdo, "SELECT COUNT(*) FROM employees WHERE department_id = ? AND LOWER(COALESCE(status,'')) <> 'ex'", [(int)$emp['department_id']]) === 0) $dn = (string)$emp['dept'];
    $report = ['seats' => $snap['seats'] ?? [], 'moved' => $moved, 'left' => $refs['rows'], 'unknown' => $refs['unknown'], 'login' => $snap['login'] ?? '', 'pens' => $snap['pens'] ?? 0, 'successor' => $S];
    $pdo->prepare("UPDATE cq_ho SET state = 'done', successor = ?, report = ?, done_at = ?, checked_at = ? WHERE emp_id = ?")->execute([$S, json_encode($report, JSON_UNESCAPED_UNICODE), $now, $now, $E]);
    $nm = bd_emp_name($pdo, $E); $sn = bd_emp_name($pdo, $S);
    $lines = [];
    foreach ($moved as $mv) $lines[] = cq_ho_cnt($mv['label'], $mv['n']) . ' → ' . $sn;
    if (!empty($snap['seats'])) { $pos = count(array_filter($snap['seats'], function ($q) { return strpos((string)$q['code'], '.pos_') !== false; })); if ($pos) $lines[] = $pos . ' position seat' . ($pos === 1 ? ' follows' : 's follow') . ' the designation and fill when someone with that designation joins'; }
    $left = $refs['total']; if ($refs['unknown']) { $lv0 = []; cq_ho_unk_ask($pdo, $lv0, $E, $S, $refs); }
    $body = ($lines ? implode("\n", $lines) : 'Nothing open had to be moved.') . "\n" . ($left === 0 ? 'Check after the move: 0 left under ' . $nm . ' in any panel.' : 'Check after the move: ' . $left . ' still open — ' . implode(' · ', array_map(function ($r) { return cq_ho_cnt($r['label'], $r['n']); }, $refs['rows'])) . '. I will ask again.') . ($refs['unknown'] ? "\n" . $refs['unk_total'] . ' rows in panels I do not know yet — see the next question.' : '') . ($dn !== '' ? "\n" . $dn . ' now has nobody left.' : '');
    $lv = []; cq_ask($pdo, $lv, 'ho:done:' . $E . ':' . date('ymdHi'), ['kind' => 'notice', 'subject' => $nm, 'series' => 'handover', 'input' => 'choice', 'title' => 'Handover of ' . $nm . ' to ' . $sn . ' is done',
        'body' => $body, 'options' => [['id' => 'ok', 'label' => 'Got it']], 'payload' => ['emp' => $E, 'checklist' => cq_ho_checklist(), 'checklist_title' => 'Still to remove by hand (outside this system):']]);
    foreach (['top_boss' => 'top boss', 'deputy' => 'deputy'] as $k => $lab) {
        if (!in_array($k, (array)($snap['top'] ?? []), true)) continue;
        $people = []; foreach (cq_people($pdo) as $p) $people[] = ['id' => (int)$p['id'], 'name' => (string)$p['name']];
        cq_ask($pdo, $lv, 'ho:top:' . $E . ':' . $k, ['kind' => 'handover', 'subject' => $nm, 'series' => 'handover', 'input' => 'choice', 'title' => $nm . ' was the ' . $lab . '. Who is the ' . $lab . ' now?', 'body' => 'Until you choose, the Boss Engine has no ' . $lab . '.',
            'options' => [['id' => (string)$S, 'label' => $sn . ' — the person taking over'], ['id' => 'other', 'label' => 'Someone else…', 'input' => 'person'], ['id' => 'none', 'label' => 'Nobody for now']], 'payload' => ['emp' => $E, 'role' => $k, 'people' => $people]]);
    }
    cq_log($pdo, $by, 'handover: ' . $nm . ' → ' . $sn . ' (' . ($lines ? implode('; ', $lines) : 'nothing open') . '; left ' . $left . ')');
    return ['Handed over to ' . $sn . '. ' . ($left === 0 ? 'Nothing is left under ' . $nm . ' anywhere.' : $left . ' still open — I will ask again.'), (string)$S];
}

/* ------------------------------------------------------------------ answering */
function cq_view(array $r) {
    return ['id' => (int)$r['id'], 'sig' => $r['sig'], 'kind' => $r['kind'], 'subject' => (string)$r['subject'], 'section_code' => (string)$r['section_code'], 'title' => $r['title'], 'body' => (string)$r['body'],
        'input' => $r['input'], 'options' => json_decode((string)$r['options'], true) ?: [], 'payload' => json_decode((string)$r['payload'], true) ?: new stdClass(), 'series' => (string)$r['series'], 'seq' => (int)$r['seq'], 'created_at' => $r['created_at']];
}
/** the workbench departments' own questions (dispatch, money, shops, supplies, punch) shown in the same panel; their answers still go through their own code (dp_q_answer), so nothing about going live changes */
function cq_dp_load(PDO $pdo) {
    if (!function_exists('dp_topic_defs') && is_file(__DIR__ . '/dept.php')) { try { require_once __DIR__ . '/dept.php'; } catch (Throwable $e) { return false; } }
    if (function_exists('dp_ensure')) { try { dp_ensure($pdo); } catch (Throwable $e) {} }
    return function_exists('dp_topic_defs') && function_exists('dp_q_rows') && function_exists('dp_q_view') && function_exists('dp_q_answer');
}
function cq_dp_items(PDO $pdo) {
    $o = []; if (!cq_dp_load($pdo)) return $o;
    try {
        $emps = null;
        foreach (dp_topic_defs() as $topic => $tname) {
            $rows = array_values(array_filter(array_map('dp_q_view', dp_q_rows($pdo, $topic))));
            $open = array_values(array_filter($rows, function ($q) { return $q['state'] === 'open'; }));
            if (!$open) $open = array_values(array_filter($rows, function ($q) { return $q['state'] === 'later'; }));
            $done = count(array_filter($rows, function ($q) { return in_array($q['state'], ['answered', 'removed'], true); })); $tot = count($rows);
            if ($open) {
                $q = $open[0]; $type = (string)$q['type']; $opts = []; $pl = ['dp' => true, 'topic' => $topic, 'sug' => (string)$q['sug']]; $input = 'choice';
                if ($type === 'confirm') {
                    if ($q['key'] === 'pu_pen') { $opts = [['id' => 'wait', 'label' => 'Wait for my approval'], ['id' => 'auto', 'label' => 'Take it automatically']]; }
                    else $opts = [['id' => 'yes', 'label' => 'Yes, that is right'], ['id' => 'change', 'label' => 'Change it', 'input' => 'text', 'placeholder' => 'Write your answer']];
                } elseif ($type === 'text') { $input = 'long'; $pl['placeholder'] = 'Write your answer'; if ($q['sug'] !== '') $opts[] = ['id' => 'use_sug', 'label' => 'Use my suggestion'];
                } elseif ($type === 'amount') { $input = 'amount'; $pl['max'] = 100000; $pl['placeholder'] = $q['key'] === 'pu_grace' ? 'Minutes after shift start' : 'Penalty amount in rupees (0 = no penalty yet)'; $pl['min0'] = true;
                } elseif ($type === 'person') {
                    if ($emps === null) { $emps = []; foreach (cq_people($pdo) as $p) $emps[] = ['id' => (int)$p['id'], 'name' => (string)$p['name']]; }
                    try { foreach (dp_suggest($pdo, $q['seat'] === 'supplies.buyer' ? 'supplies.buyer' : (string)$q['seat'], 3) as $c) $opts[] = ['id' => (string)$c['id'], 'label' => $c['name'] . (!empty($c['line']) ? ' — ' . $c['line'] : '')]; } catch (Throwable $e) {}
                    $opts[] = ['id' => 'other', 'label' => 'Someone else…', 'input' => 'person']; $pl['people'] = $emps;
                }
                $opts[] = ['id' => 'later', 'label' => 'Ask me later'];
                $o[] = ['id' => 'dp:' . $q['key'], 'sig' => 'dp:' . $q['key'], 'kind' => 'gap', 'subject' => (string)$tname, 'section_code' => '', 'title' => $q['q'],
                    'body' => trim((string)$q['why'] . ($q['sug'] !== '' && $type !== 'text' ? ($q['why'] !== '' ? ' ' : '') . 'My suggestion: ' . $q['sug'] : '')), 'input' => $input, 'options' => $opts, 'payload' => $pl, 'series' => 'dp_' . $topic, 'seq' => $done + 1, 'total' => max($tot, $done + 1), 'created_at' => ''];
            } elseif (dp_topic_state($pdo, $topic) === 'understood') {
                $o[] = ['id' => 'dpgo:' . $topic, 'sig' => 'dpgo:' . $topic, 'kind' => 'gap', 'subject' => (string)$tname, 'section_code' => '', 'title' => 'Is this right? Then ' . $tname . ' goes live',
                    'body' => dp_summary($pdo, $topic), 'input' => 'choice', 'options' => [['id' => 'live', 'label' => 'This is right, make it live', 'tone' => 'go'], ['id' => 'redo', 'label' => 'Not quite, let me correct it']], 'payload' => ['dp' => true, 'topic' => $topic], 'series' => 'dp_' . $topic, 'seq' => $tot, 'total' => $tot, 'created_at' => ''];
            }
        }
    } catch (Throwable $e) { error_log('cq_dp_items: ' . $e->getMessage()); }
    return $o;
}
function cq_dp_apply(PDO $pdo, $id, $choice, $text, $by) {
    if (!cq_dp_load($pdo)) json_response(['error' => 'The department questions are not available.'], 500);
    if (strpos($id, 'dpgo:') === 0) {
        $t = substr($id, 5); if (!isset(dp_topic_defs()[$t])) json_response(['error' => 'Unknown topic.'], 404);
        if ($choice === 'redo') { $pdo->prepare("UPDATE dp_topics SET state = 'asking' WHERE topic = ?")->execute([$t]); return 'OK — tell me what is different: the answers are open again.'; }
        if (dp_topic_state($pdo, $t) !== 'understood') json_response(['error' => 'Answer the open questions of this topic first.'], 409);
        $pdo->prepare("UPDATE dp_topics SET state = 'live', approved_by = ?, approved_at = ? WHERE topic = ?")->execute([$by, date('Y-m-d H:i:s'), $t]);
        bd_log('golive', $t, 'topic is live', $by);
        return dp_topic_defs()[$t] . ' is live. It follows the Boss Engine mode: ' . bd_mode() . '.';
    }
    $key = substr($id, 3);
    if ($choice === 'later') { $pdo->prepare("UPDATE dp_q SET state = 'later', later_n = later_n + 1 WHERE qkey = ? AND state IN ('open','later')")->execute([$key]); return 'OK, I will ask again later.'; }
    $st = $pdo->prepare("SELECT * FROM dp_q WHERE qkey = ?"); $st->execute([$key]); $r = $st->fetch(); $b = $r ? dp_q_def($r) : null; if (!$b) json_response(['error' => 'That question is gone.'], 404);
    $ans = ''; $extra = [];
    if ($b['type'] === 'confirm') {
        if ($key === 'pu_pen') { $auto = $choice === 'auto'; $ans = $auto ? 'Taken automatically' : 'Waits for your yes or no'; $extra['auto'] = $auto; }
        else $ans = $choice === 'yes' ? '' : (string)$text;
    } elseif ($b['type'] === 'text') { $ans = $choice === 'use_sug' ? (string)$b['sug'] : (string)$text; }
    elseif ($b['type'] === 'amount') { $ans = (string)$text; $extra['amount'] = (float)$text; $extra['grace_min'] = (float)$text; }
    elseif ($b['type'] === 'person') { $extra['emp_id'] = (int)($choice === 'other' ? $text : $choice); }
    $fu = dp_q_answer($pdo, $key, $ans, $by, $extra);
    $n = (int)($fu['n'] ?? 0);
    return 'Saved. ' . ($n > 0 ? 'Claude has ' . $n . ' more question' . ($n === 1 ? '' : 's') . ' about this.' : ((string)($fu['understood'] ?? '') !== '' ? 'I understood: ' . $fu['understood'] : ''));
}
function cq_list_open(PDO $pdo) {
    $o = []; $rest = [];
    foreach ($pdo->query("SELECT * FROM cq_q WHERE status = 'open' ORDER BY FIELD(kind,'handover','conflict','structure','gap','followup','notice'), series, seq, id")->fetchAll() as $r) { $v = cq_view($r); if (in_array($v['kind'], ['handover', 'conflict', 'structure', 'gap'], true)) $o[] = $v; else $rest[] = $v; }
    return array_merge($o, cq_dp_items($pdo), $rest);
}
function cq_apply(PDO $pdo, array $w, $by, array $q, $choice, $text) {
    $pl = json_decode((string)$q['payload'], true) ?: []; $sig = (string)$q['sig']; $code = (string)$q['section_code']; $msg = 'Done.';
    if (strpos($sig, 'wq:') === 0) {   // WhatsApp Sales: query types and proposed rules (wq.php is loaded here when this request did not load it)
        if (!function_exists('wq_cq_apply') && is_file(__DIR__ . '/wq.php')) { try { require_once __DIR__ . '/wq.php'; } catch (Throwable $e) { } }
        if (function_exists('wq_cq_apply')) return wq_cq_apply($pdo, $q, (string)$choice, (string)$text, $by);
    }
    if (strpos($sig, 'ofp:') === 0 && cq_ofp_load()) return ofp_cq_apply($pdo, $w, $q, (string)$choice, (string)$text, $by);   // Register a place: owner / must-have list
    if (strpos($sig, 'ho:unk:') === 0) {
        $E = (int)($pl['emp'] ?? 0); $S = (int)($pl['to'] ?? 0); $tb = (string)($pl['target'] ?? ''); $hit = null;
        foreach (cq_ho_unknown_cols($pdo) as $tc) if ($tc[0] . '.' . $tc[1] === $tb) { $hit = $tc; break; }
        if (!$hit) return ['That panel is already handled.', 'gone'];
        if ($choice === 'give' && $S) {
            $x = $pdo->prepare("UPDATE `{$hit[0]}` SET `{$hit[1]}` = ? WHERE `{$hit[1]}` = ?"); $x->execute([$S, $E]);
            return [$x->rowCount() . ' rows in ' . $hit[0] . ' now belong to ' . bd_emp_name($pdo, $S) . '.', 'give'];
        }
        $ign = array_filter(explode(',', (string)bd_cfg_fresh('cq_ho_ignore')), 'strlen'); $ign[] = $hit[0] . '.' . $hit[1]; bd_set('cq_ho_ignore', implode(',', array_unique($ign)));
        try { $pdo->exec("DELETE FROM pp_cache WHERE k = 'cq_ho_cols'"); } catch (Throwable $e) {}
        return [$hit[0] . ' is history from now on. I will not ask about it again.', 'keep'];
    }
    if (strpos($sig, 'ho:top:') === 0) {
        $role = (string)($pl['role'] ?? 'top_boss'); if ($choice === 'none') return ['Noted: nobody for now. The Boss Engine will keep reminding you.', 'none'];
        $v = (int)($choice === 'other' ? $text : $choice); $ne = $v ? cq_ho_emp($pdo, $v) : null; if (!$ne || strtolower((string)$ne['status']) === 'ex') json_response(['error' => 'Choose a person who works here now.'], 422);
        bd_set($role, $v); bd_set_holders($pdo, bd_seat_id($pdo, $role === 'deputy' ? 'top.deputy' : 'top.boss'), [$v]);
        return [$ne['name'] . ' is now the ' . ($role === 'deputy' ? 'deputy' : 'top boss') . '.', (string)$v];
    }
    if (strpos($sig, 'ho:done:') === 0 || strpos($sig, 'ho:back:') === 0) return ['OK.', 'ok'];
    if (strpos($sig, 'ho:') === 0) {
        $E = (int)($pl['emp'] ?? 0); if (!$E) json_response(['error' => 'That handover is gone.'], 404);
        $S = $choice === 'empty' ? 0 : (int)($choice === 'other' ? $text : $choice); if ($choice !== 'empty' && !$S) json_response(['error' => 'Choose who takes over.'], 422);
        return cq_ho_do($pdo, $E, $S, $by);
    }
    if (strpos($sig, 'gap:tasks:') === 0) {
        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$text)), function ($l) { return mb_strlen($l) >= 3; })), 0, 12);
        $made = 0; $skipped = [];
        $ex = $pdo->prepare("SELECT title FROM b2_items WHERE section_code = ? AND status IN ('active','paused','draft')"); $ex->execute([$code]); $titles = $ex->fetchAll(PDO::FETCH_COLUMN);
        foreach ($lines as $ln) {
            $dr = b2_draft($pdo, $w, $ln, ['section' => $code]); $d = $dr['draft'] ?? null; if (!$d) { $skipped[] = $ln; continue; }
            $d['section_code'] = $code;
            if (($d['kind'] ?? '') === 'short' && !preg_match('/\b(today|tomorrow|this week|next week|once|by \d{1,2}(st|nd|rd|th)\b)/i', $ln)) $d['kind'] = 'daily';   // the question was "what must they do every day?"
            if (!empty($d['title'])) $d['title'] = rtrim((string)$d['title'], " ,;:.");
            if (empty($d['seat_id']) && empty($d['emp_id'])) $d['seat_id'] = cq_owner_seat($pdo, $code);
            list($c, $err) = b2_item_clean($pdo, $d);
            if (!$c) { $skipped[] = $ln . ' (' . $err . ')'; continue; }
            $dup = false; foreach ($titles as $t) if (bd_sim(implode(' ', b2_words($t)), implode(' ', b2_words($c['title']))) >= 0.8) $dup = true;
            if ($dup) { $skipped[] = $ln . ' (already a task)'; continue; }
            b2_item_insert($pdo, $c, 'active', 'claude', $by); $titles[] = $c['title']; $made++;
        }
        if ($made === 0) json_response(['error' => 'I could not make a task from that. Write one duty per line, for example “Pack all orders by 6 pm, photo of the packed shelf”.' . ($skipped ? ' (' . $skipped[0] . ')' : '')], 422);
        $msg = $made . ($made === 1 ? ' task is' : ' tasks are') . ' live now — in the Boss Engine and on each person’s task list.' . ($skipped ? ' Skipped: ' . implode('; ', array_slice($skipped, 0, 3)) . '.' : '');
        return [$msg, (string)$text];
    }
    if (strpos($sig, 'gap:number:') === 0) {
        if ($choice === 'none') return ['Noted: no daily number for this team.', 'none'];
        $parts = array_map('trim', explode(',', (string)$text, 2)); $label = $parts[0] ?? ''; $tg = isset($parts[1]) ? (float)preg_replace('/[^0-9.]/', '', $parts[1]) : 0;
        if (mb_strlen($label) < 3) json_response(['error' => 'Say what is counted, then the target — for example “Parcels packed, 150”.'], 422);
        $d = ['kind' => 'number', 'title' => 'Enter ' . mb_strtolower($label) . ' every day', 'section_code' => $code, 'seat_id' => cq_owner_seat($pdo, $code), 'unit' => mb_strtolower($label), 'cmp' => 'min', 'proof' => 'number'];
        if ($tg > 0) $d['target'] = $tg;
        list($c, $err) = b2_item_clean($pdo, $d); if (!$c) json_response(['error' => $err ?: 'I could not make that number task.'], 422);
        b2_item_insert($pdo, $c, 'active', 'claude', $by);
        return ['Daily number added' . ($tg > 0 ? ' with a target of ' . $tg : '') . '.', (string)$text];
    }
    if (strpos($sig, 'gap:penalty:') === 0) {
        if ($choice === 'none') return ['Noted: no penalty for this team.', 'none'];
        $amt = $choice !== '' && is_numeric($choice) ? (float)$choice : (float)preg_replace('/[^0-9.]/', '', (string)$text);
        if ($amt <= 0 || $amt > 500) json_response(['error' => 'Choose an amount between ₹1 and ₹500, or “No penalty”.'], 422);
        $st = $pdo->prepare("UPDATE b2_items SET pen_amount = ? WHERE section_code = ? AND status IN ('active','paused') AND pen_amount = 0"); $st->execute([$amt, $code]);
        return ['₹' . $amt . ' is now the penalty on ' . $st->rowCount() . ' ' . $q['subject'] . ' tasks (asked to you first, never automatic).', (string)$amt];
    }
    if (strpos($sig, 'gap:checker:') === 0) {
        if ($choice === 'skip') return ['Noted: you check it yourself.', 'skip'];
        $emp = (int)$choice; if (!$emp) json_response(['error' => 'Choose a person.'], 422);
        $s = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $s->execute([$code]); $sid = (int)$s->fetchColumn(); $seat = $sid ? bd_section_seat($pdo, $sid, 'checker') : 0;
        if (!$seat) json_response(['error' => 'This department has no checker seat.'], 422);
        bd_set_holders($pdo, $seat, [$emp]);
        return [bd_emp_name($pdo, $emp) . ' now checks the work of ' . $q['subject'] . '.', (string)$emp];
    }
    if (strpos($sig, 'conf:item:') === 0) {
        $new = (int)($pl['new']['id'] ?? 0); $old = (int)($pl['old']['id'] ?? 0);
        if ($choice === 'keep_new') {
            cq_remove_item($pdo, $old, 'a newer rule replaced it', $by);
            $msg = 'Kept “' . ($pl['new']['title'] ?? '') . '”. The old rule is removed entirely.';
        } else { cq_remove_item($pdo, $new, 'the old rule was kept', $by); $msg = 'Kept “' . ($pl['old']['title'] ?? '') . '”. The new one is dropped.'; }
        $keep = $choice === 'keep_new' ? $new : $old; $kr = $pdo->prepare("SELECT pen_amount, section_code FROM b2_items WHERE id = ?"); $kr->execute([$keep]); $k = $kr->fetch();
        if ($k && (float)$k['pen_amount'] > 0) {   // follow-up: should the rest of the department carry the same penalty?
            $o = $pdo->prepare("SELECT COUNT(*) FROM b2_items WHERE section_code = ? AND status IN ('active','paused') AND ABS(pen_amount - ?) > 0.001 AND id <> ?"); $o->execute([$k['section_code'], $k['pen_amount'], $keep]);
            if ((int)$o->fetchColumn() > 0) { $lv = []; cq_ask($pdo, $lv, 'fu:pen:' . $keep . ':' . $k['pen_amount'], ['kind' => 'followup', 'subject' => $q['subject'], 'section_code' => $k['section_code'], 'series' => 'conflict', 'input' => 'choice',
                'title' => 'Should every ' . $q['subject'] . ' task now carry ₹' . (float)$k['pen_amount'] . '?',
                'body' => 'You just chose ₹' . (float)$k['pen_amount'] . ' for “' . ($choice === 'keep_new' ? ($pl['new']['title'] ?? '') : ($pl['old']['title'] ?? '')) . '”. Other tasks of this department have a different penalty.',
                'options' => [['id' => 'yes', 'label' => 'Yes, same for all'], ['id' => 'no', 'label' => 'No, leave the others']], 'payload' => ['amount' => (float)$k['pen_amount'], 'keep' => $keep]]); }
        }
        return [$msg, $choice];
    }
    if (strpos($sig, 'conf:rule:') === 0) {
        $new = (int)($pl['new']['id'] ?? 0); $old = (int)($pl['old']['id'] ?? 0); $drop = $choice === 'keep_new' ? $old : $new;
        try { $pdo->prepare("DELETE FROM penalty_rules WHERE rule_id = ?")->execute([$drop]); }
        catch (Throwable $e) { $pdo->prepare("UPDATE penalty_rules SET active = 0 WHERE rule_id = ?")->execute([$drop]); }
        return [$choice === 'keep_new' ? 'Kept the new rule. The old one is removed entirely.' : 'Kept the old rule. The new one is dropped.', $choice];
    }
    if (strpos($sig, 'fu:pen:') === 0) {
        if ($choice !== 'yes') return ['Left the other tasks as they are.', 'no'];
        $amt = (float)($pl['amount'] ?? 0); $st = $pdo->prepare("UPDATE b2_items SET pen_amount = ? WHERE section_code = ? AND status IN ('active','paused')"); $st->execute([$amt, $code]);
        return ['All ' . $q['subject'] . ' tasks now carry ₹' . $amt . '.', 'yes'];
    }
    if (strpos($sig, 'split:') === 0) {
        if ($choice === 'keep') return ['Kept as one department. I will not ask again about this.', 'keep'];
        $did = (int)($pl['dept_id'] ?? 0); $name = (string)($pl['new_name'] ?? ''); if ($name === '') json_response(['error' => 'No name for the new department.'], 422);
        list($nid, $ncode) = cq_create_dept($pdo, $name);
        $moved = 0; $mv = $pdo->prepare("UPDATE employees SET department_id = ? WHERE employee_id = ? AND department_id = ?");
        foreach (($pl['emp_ids'] ?? []) as $e) { $mv->execute([$nid, (int)$e, $did]); $moved += $mv->rowCount(); }
        cq_sync_positions($pdo); if (function_exists('pp_cache_drop')) pp_cache_drop($pdo);
        cq_log($pdo, $by, 'split department: made ' . $name . ', moved ' . $moved . ' people');
        $lv = []; cq_ask($pdo, $lv, 'notice:dept:' . $nid, ['kind' => 'notice', 'subject' => $name, 'series' => 'structure', 'input' => 'choice', 'title' => 'New department “' . $name . '” is ready', 'body' => $moved . ' people moved into it. I will now ask what ' . $name . ' must do.',
            'options' => [['id' => 'ok', 'label' => 'Good'], ['id' => 'rename', 'label' => 'Rename it', 'input' => 'text', 'placeholder' => 'New name']], 'payload' => ['dept_id' => $nid, 'emp_ids' => $pl['emp_ids'] ?? []]]);
        return ['Made “' . $name . '” and moved ' . $moved . ' people. Its first question is next.', 'split'];
    }
    if (strpos($sig, 'notice:dept:') === 0) {
        $did = (int)($pl['dept_id'] ?? 0);
        if ($choice === 'rename') {
            $name = trim((string)$text); if (mb_strlen($name) < 2) json_response(['error' => 'Type the new name.'], 422);
            $d = $pdo->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(?) AND department_id <> ?"); $d->execute([$name, $did]); if ($d->fetchColumn()) json_response(['error' => 'A department with that name already exists.'], 409);
            $pdo->prepare("UPDATE departments SET department_name = ? WHERE department_id = ?")->execute([mb_substr($name, 0, 80), $did]);
            $pdo->prepare("UPDATE bd_sections SET name = ? WHERE source = 'dept' AND dept_ids = ?")->execute([mb_substr($name, 0, 120), (string)$did]);
            if (function_exists('b2_sections')) { try { b2_sections($pdo, true); } catch (Throwable $e) {} }
            if (function_exists('pp_cache_drop')) pp_cache_drop($pdo);
            return ['Renamed to “' . $name . '”.', 'rename:' . $name];
        }
        if ($choice === 'move') {
            $to = (int)$text; $dn = cq_departments($pdo); if (!$to || $to === $did || !isset($dn[$to])) json_response(['error' => 'Choose the department they belong in.'], 422);
            cq_delete_dept($pdo, $did, $to); cq_sync_positions($pdo); if (function_exists('pp_cache_drop')) pp_cache_drop($pdo);
            return ['Moved them to ' . $dn[$to] . ' and removed the department I made.', 'move:' . $to];
        }
        return ['OK.', 'ok'];
    }
    return ['Done.', (string)$choice];
}

function cq_dispatch(PDO $pdo, $action, array $auth, array $w, $by, array $in, $isPost) {
    if (strpos((string)$action, 'bd.cq_') !== 0) return;
    cq_ensure($pdo); if (function_exists('pp_need_admin')) pp_need_admin($auth);
    $GLOBALS['__cq_pdo'] = $pdo;
    switch ($action) {
        case 'bd.cq_list': {
            $age = function_exists('pp_cache_get') ? pp_cache_get($pdo, 'cq_scan', 50) : null;
            if ($age === null || !empty($_GET['fresh'])) { if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => time()]); cq_scan($pdo, $by); }
            $items = cq_list_open($pdo);
            $recent = []; foreach ($pdo->query("SELECT id, title, subject, answer, answered_at, answered_by FROM cq_q WHERE status = 'done' ORDER BY answered_at DESC LIMIT 6")->fetchAll() as $r) $recent[] = $r;
            json_response(['ok' => true, 'items' => $items, 'count' => count($items), 'recent' => $recent, 'at' => date('H:i')]);
        }
        case 'bd.cq_scan': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => time()]);
            $r = cq_scan($pdo, $by); json_response(['ok' => true, 'scan' => $r, 'items' => cq_list_open($pdo)]);
        }
        case 'bd.cq_ho_status': {
            $emp = (int)($_GET['emp_id'] ?? $in['emp_id'] ?? 0); if (!$emp) json_response(['error' => 'Which person?'], 422);
            json_response(['ok' => true, 'handover' => cq_ho_summary($pdo, $emp), 'left' => cq_ho_refs($pdo, $emp)]);
        }
        case 'bd.cq_ho_run': {   // "left" was just set: do not wait for the next minute
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $emp = (int)($in['emp_id'] ?? 0); if (!$emp) json_response(['error' => 'Which person?'], 422);
            if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => time()]);
            cq_scan($pdo, $by); json_response(['ok' => true, 'handover' => cq_ho_summary($pdo, $emp), 'items' => cq_list_open($pdo)]);
        }
        case 'bd.cq_place': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $emp = (int)($in['emp_id'] ?? 0); if (!$emp) json_response(['error' => 'Which person?'], 422);
            $r = cq_place_people($pdo, $by, $emp);
            $st = $pdo->prepare("SELECT d.department_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.employee_id = ?"); $st->execute([$emp]); $dn = (string)$st->fetchColumn();
            if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => 0]);
            json_response(['ok' => true, 'placed' => $r, 'dept' => $dn, 'created' => !empty($r[0]['created']), 'message' => $dn !== '' ? bd_emp_name($pdo, $emp) . ' is in ' . $dn . (!empty($r[0]['created']) ? ' (I made this department — change it from “Claude asks” if you disagree).' : '.') : 'No department yet — add a designation and I will place them.']);
        }
        case 'bd.cq_answer': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $rid = (string)($in['id'] ?? '');
            if (strpos($rid, 'dp:') === 0 || strpos($rid, 'dpgo:') === 0) {
                $msg = cq_dp_apply($pdo, $rid, (string)($in['choice'] ?? ''), (string)($in['text'] ?? ''), $by);
                cq_log($pdo, $by, 'answered a department question: ' . $rid); if (function_exists('pp_cache_drop')) pp_cache_drop($pdo);
                json_response(['ok' => true, 'message' => $msg, 'items' => cq_list_open($pdo)]);
            }
            $id = (int)$rid; $st = $pdo->prepare("SELECT * FROM cq_q WHERE id = ?"); $st->execute([$id]); $q = $st->fetch();
            if (!$q) json_response(['error' => 'That question is gone.'], 404);
            if ($q['status'] !== 'open') json_response(['ok' => true, 'message' => 'Already answered.', 'items' => cq_list_open($pdo)]);
            $choice = (string)($in['choice'] ?? ''); $text = (string)($in['text'] ?? '');
            list($msg, $ans) = cq_apply($pdo, $w, $by, $q, $choice, $text);
            cq_close($pdo, $id, $ans, $by);
            cq_log($pdo, $by, 'answered: ' . mb_substr($q['title'], 0, 120) . ' → ' . mb_substr($msg, 0, 120));
            if (function_exists('pp_cache_drop')) pp_cache_drop($pdo);
            $sc = cq_scan($pdo, $by); if (function_exists('pp_cache_put')) pp_cache_put($pdo, 'cq_scan', ['at' => time()]);
            json_response(['ok' => true, 'message' => $msg, 'items' => cq_list_open($pdo)]);
        }
    }
}
