<?php
/* Boss HQ = the Summary page (Boss > Summary). Loaded by dept.php. PHP 7.4-safe. Endpoints (all dp.hq_*):
     dp.hq_track    who has what, per section and per person, today: open / done / missed, 30-day track record, wrap-up text, "OK, fixed" status
     dp.hq_people   every person with a seat or a job today: the role they hold right now (feeds Setup > Who does what now)
     dp.hq_done     the boss says "this is done" -> the job shrinks into the done strip (POST {kind: duty|item|task, id})
     dp.hq_ack      the boss says "OK, fixed" on a section's wrap-up -> kept as a record (POST {section, note?})
     dp.hq_chat     Claude, the boss's assistant: answers, points to the page, warns about gaps, proposes actions (POST {message, history[]})
     dp.hq_act      runs ONE action Claude proposed, only after the boss pressed "Yes, do it" (POST {type, args})
   Claude never changes anything by itself: every change comes back as a proposal and runs on dp.hq_act. */

function dp_hq_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_hq_ack (
        id INT AUTO_INCREMENT PRIMARY KEY, section VARCHAR(40) NOT NULL, day DATE NOT NULL, by_name VARCHAR(80) NOT NULL, note VARCHAR(300) NULL,
        summary VARCHAR(900) NULL, done_n INT NOT NULL DEFAULT 0, total_n INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        UNIQUE KEY u_sec_day (section, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['dp_hq_ack']);
}
function dp_hq_norm($s) {
    $s = (string)$s;
    if (in_array($s, ['done', 'checked', 'closed', 'handled'], true)) return 'done';
    if ($s === 'missed') return 'missed';
    if (in_array($s, ['dismissed', 'cancelled', 'dropped'], true)) return 'skip';
    return 'open';
}
function dp_hq_hm($dt) { return $dt ? substr((string)$dt, 11, 5) : ''; }

/** the seats a person holds, as "Role" lines (all sections) */
function dp_hq_roles(PDO $pdo, $empId) {
    $o = [];
    try {
        $st = $pdo->prepare("SELECT s.title, s.kind, s.code, sc.name AS sec, sc.code AS sec_code FROM bd_holders h JOIN bd_seats s ON s.id = h.seat_id LEFT JOIN bd_sections sc ON sc.id = s.section_id WHERE h.emp_id = ? AND s.active = 1 ORDER BY s.id");
        $st->execute([(int)$empId]);
        foreach ($st->fetchAll() as $r) { $o[] = ['title' => (string)$r['title'], 'kind' => (string)$r['kind'], 'section' => (string)($r['sec'] ?: ''), 'section_code' => (string)($r['sec_code'] ?: '')]; }
    } catch (Throwable $e) {}
    return $o;
}

/** today's board. $only = one section code, or '' for every section the viewer may see */
function dp_hq_board(PDO $pdo, array $w, $only = '') {
    $today = bd_today(); $from = date('Y-m-d', strtotime('-1 day'));
    $secs = []; foreach ($pdo->query("SELECT id, code, name, about FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) {
        if ($only !== '' && $s['code'] !== $only) continue;
        if (!bd_can_section($w, (int)$s['id'])) continue;
        $secs[(int)$s['id']] = ['id' => (int)$s['id'], 'code' => $s['code'], 'name' => $s['name'], 'about' => (string)$s['about'], 'people' => [], 'unowned' => [], 'empty_seats' => [], 'open' => 0, 'done' => 0, 'missed' => 0];
    }
    if (!$secs) return [];
    $trackCache = []; $nameCache = [];
    $person = function (&$sec, $emp) use ($pdo, &$trackCache, &$nameCache) {
        $emp = (int)$emp;
        if (!isset($sec['people'][$emp])) {
            if (!isset($nameCache[$emp])) $nameCache[$emp] = bd_emp_name($pdo, $emp);
            if (!isset($trackCache[$emp])) $trackCache[$emp] = dp_track($pdo, $emp, 30);
            $t = $trackCache[$emp];
            $sec['people'][$emp] = ['id' => $emp, 'name' => $nameCache[$emp], 'roles' => [], 'open' => [], 'done' => [], 'missed' => [], 'track' => ['tasks' => $t['tasks'], 'on_time' => $t['on_time'], 'penalties' => $t['penalties']]];
        }
    };
    $put = function (&$sec, $emp, array $it) use ($person) {
        $k = $it['state'] === 'done' ? 'done' : ($it['state'] === 'missed' ? 'missed' : 'open');
        $sec[$k]++;
        if (!$emp) { $sec['unowned'][] = $it; return; }
        $person($sec, $emp); $sec['people'][(int)$emp][$k][] = $it;
    };
    // everyone who holds a seat in the section is tracked, even with no job today
    foreach ($secs as &$sec) {
        $st = $pdo->prepare("SELECT s.id, s.code, s.title, s.kind FROM bd_seats s WHERE s.section_id = ? AND s.active = 1 ORDER BY s.id"); $st->execute([$sec['id']]);
        foreach ($st->fetchAll() as $s) {
            $h = bd_holders($pdo, (int)$s['id']);
            if (!$h) { if ($s['kind'] === 'worker' && preg_match('/\.worker$/', (string)$s['code']) === 0) $sec['empty_seats'][] = ['code' => $s['code'], 'title' => $s['title']]; continue; }
            foreach ($h as $emp) { $person($sec, $emp); $sec['people'][(int)$emp]['roles'][] = $s['title'] . ($s['kind'] === 'section_boss' ? ' (boss)' : ($s['kind'] === 'checker' ? ' (checker)' : '')); }
        }
    } unset($sec);
    // Boss Engine duties of the day
    $q = $pdo->prepare("SELECT t.id, t.section_id, t.owner_emp, t.status, t.due_at, t.done_at, t.pen_state, t.pen_amount, d.title FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.task_date = ? AND t.section_id IN (" . implode(',', array_map('intval', array_keys($secs))) . ") ORDER BY t.due_at");
    $q->execute([$today]);
    foreach ($q->fetchAll() as $r) {
        $sid = (int)$r['section_id']; if (!isset($secs[$sid])) continue; $st = dp_hq_norm($r['status']); if ($st === 'skip') continue;
        $put($secs[$sid], (int)$r['owner_emp'], ['kind' => 'duty', 'id' => (int)$r['id'], 'title' => (string)$r['title'], 'state' => $st, 'due' => dp_hq_hm($r['due_at']), 'at' => dp_hq_hm($r['done_at']), 'pen' => in_array((string)$r['pen_state'], ['applied', 'pending', 'shadow'], true) ? (float)$r['pen_amount'] : 0]);
    }
    // department items (parcels, money checks, shop checks, supplies...) by the section their seat belongs to
    $byCode = []; foreach ($secs as $sid => $s) $byCode[$s['code']] = $sid;
    $q = $pdo->prepare("SELECT id, seat_code, owner_emp, title, status, due_at, done_at, pen_state, pen_amount FROM dp_items WHERE task_date IN (?, ?) AND (task_date = ? OR status = 'open') ORDER BY due_at");
    $q->execute([$today, $from, $today]);
    foreach ($q->fetchAll() as $r) {
        $code = strtok((string)$r['seat_code'], '.'); if (!isset($byCode[$code])) continue; $st = dp_hq_norm($r['status']); if ($st === 'skip') continue;
        $put($secs[$byCode[$code]], (int)$r['owner_emp'], ['kind' => 'item', 'id' => (int)$r['id'], 'title' => (string)$r['title'], 'state' => $st, 'due' => dp_hq_hm($r['due_at']), 'at' => dp_hq_hm($r['done_at']), 'pen' => in_array((string)$r['pen_state'], ['applied', 'pending', 'shadow'], true) ? (float)$r['pen_amount'] : 0]);
    }
    // the section's wrap-up text and the boss's "OK, fixed"
    $ack = []; try { $st = $pdo->prepare("SELECT * FROM dp_hq_ack WHERE day = ?"); $st->execute([$today]); foreach ($st->fetchAll() as $a) $ack[$a['section']] = $a; } catch (Throwable $e) {}
    $out = [];
    foreach ($secs as $sec) {
        $total = $sec['open'] + $sec['done'] + $sec['missed'];
        $ppl = array_values($sec['people']);
        usort($ppl, function ($a, $b) { return (count($b['open']) + count($b['missed'])) <=> (count($a['open']) + count($a['missed'])) ?: strcmp($a['name'], $b['name']); });
        $sec['people'] = $ppl;
        $sec['total'] = $total;
        $sec['wrap'] = dp_hq_wrap_text($sec);
        $sec['all_done'] = $total > 0 && $sec['open'] === 0 && $sec['missed'] === 0;
        $sec['closed'] = $total > 0 && $sec['open'] === 0;
        $a = $ack[$sec['code']] ?? null;
        $sec['ack'] = $a ? ['by' => $a['by_name'], 'note' => (string)$a['note'], 'at' => substr((string)$a['created_at'], 11, 5)] : null;
        $out[] = $sec;
    }
    // one-time jobs given from "Tell the boss" or the Summary chat: they belong to a person, not to a section
    if ($only === '' && (!empty($w['admin']) || !empty($w['top']))) {
        $g = ['id' => 0, 'code' => 'given', 'name' => 'Jobs you gave', 'about' => 'One-time and follow-up jobs from Tell the boss', 'people' => [], 'unowned' => [], 'empty_seats' => [], 'open' => 0, 'done' => 0, 'missed' => 0];
        try {
            $q = $pdo->prepare("SELECT id, title, owner_emp, status, due_at, done_at FROM dp_tk_tasks WHERE owner_emp IS NOT NULL AND (status = 'open' OR DATE(done_at) = ?) ORDER BY due_at"); $q->execute([$today]);
            foreach ($q->fetchAll() as $r) {
                $st = dp_hq_norm($r['status']); if ($st === 'skip') continue; if ($st === 'open' && $r['due_at'] && substr((string)$r['due_at'], 0, 10) < $today) $st = 'open';
                $put($g, (int)$r['owner_emp'], ['kind' => 'task', 'id' => (int)$r['id'], 'title' => (string)$r['title'], 'state' => $st, 'due' => $r['due_at'] ? substr((string)$r['due_at'], 5, 5) . ' ' . dp_hq_hm($r['due_at']) : '', 'at' => dp_hq_hm($r['done_at']), 'pen' => 0]);
            }
        } catch (Throwable $e) {}
        if ($g['people']) {
            $ppl = array_values($g['people']); foreach ($ppl as &$pp) { $pp['roles'] = array_map(function ($r) { return $r['title']; }, array_slice(dp_hq_roles($pdo, $pp['id']), 0, 3)); } unset($pp);
            $g['people'] = $ppl; $g['total'] = $g['open'] + $g['done'] + $g['missed']; $g['wrap'] = dp_hq_wrap_text($g); $g['all_done'] = $g['open'] === 0 && $g['total'] > 0; $g['closed'] = $g['open'] === 0; $g['ack'] = null; $g['no_ack'] = true;
            $out[] = $g;
        }
    }
    // sections with something to do first, finished ones last
    usort($out, function ($a, $b) { $ra = $a['total'] ? ($a['open'] + $a['missed'] > 0 ? 0 : 1) : 2; $rb = $b['total'] ? ($b['open'] + $b['missed'] > 0 ? 0 : 1) : 2; return $ra <=> $rb ?: strcmp($a['name'], $b['name']); });
    return $out;
}
function dp_hq_wrap_text(array $sec) {
    $t = $sec['open'] + $sec['done'] + $sec['missed'];
    if (!$t) return $sec['name'] . ': no jobs today' . (count($sec['people']) ? ' for ' . count($sec['people']) . ' tracked ' . (count($sec['people']) === 1 ? 'person' : 'people') . '.' : '.');
    $s = $sec['name'] . ': ' . $sec['done'] . ' of ' . $t . ' jobs done';
    if ($sec['missed']) {
        $who = []; foreach ($sec['people'] as $p) { if ($p['missed']) $who[] = $p['name'] . ' (' . count($p['missed']) . ')'; }
        $s .= ', ' . $sec['missed'] . ' missed' . ($who ? ' - ' . implode(', ', array_slice($who, 0, 4)) : '');
    }
    if ($sec['open']) $s .= ', ' . $sec['open'] . ' still open';
    $pen = 0.0; foreach ($sec['people'] as $p) foreach ($p['missed'] as $m) $pen += (float)$m['pen'];
    if ($pen > 0) $s .= '. Penalties waiting: ' . dp_inr($pen);
    return $s . '.';
}

/** the page map Claude may point to (name => file). Only these files are ever offered as links. */
function dp_hq_pages() {
    return [
        'boss_engine.html' => 'Boss Engine - today, sections, problems, all work, penalties, people and seats, build list, documents, setup', 'boss_section.html' => 'One section - its tasks, numbers, people and problems', 'boss_inbox.html' => 'Inbox - older decisions waiting for your yes',
        'boss_questions.html' => 'Questions - Claude learns how each department must run',
        'boss_penalties.html' => 'Penalties - every section, amounts and who is charged', 'daily_summary.html' => 'Summary - track record and Claude',
        'dept_hub.html' => 'Departments', 'dept_dispatch.html' => 'Parcels and tracking (Dispatch)', 'dept_shops.html' => 'Retail shops',
        'dept_supplies.html' => 'Supplies and buying', 'dept_work.html' => 'Work orders', 'dept_fix.html' => 'Repairs',
        'office_care.html' => 'Offices', 'people_admin.html' => 'People', 'doc_check.html' => 'Documents check', 'handover.html' => 'Handover of a leaver',
        'penalties.html' => 'HR penalty ledger', 'attendance_admin.html' => 'Attendance', 'leave_requests.html' => 'Leave', 'system_health.html' => 'System health',
    ];
}

/** everything Claude needs to know, as plain text */
function dp_hq_context(PDO $pdo, array $w) {
    $L = [];
    $L[] = 'TODAY: ' . bd_today() . '. Penalty mode: ' . bd_mode() . ' (shadow = recorded only, nobody is charged yet).';
    $board = dp_hq_board($pdo, $w, '');
    $L[] = "\nSECTIONS TODAY (done/open/missed):";
    foreach ($board as $s) {
        $L[] = '- ' . $s['name'] . ' [' . $s['code'] . ']: ' . $s['done'] . ' done, ' . $s['open'] . ' open, ' . $s['missed'] . ' missed' . ($s['empty_seats'] ? '; EMPTY SEATS: ' . implode(', ', array_map(function ($e) { return $e['title'] . ' [' . $e['code'] . ']'; }, $s['empty_seats'])) : '');
        foreach (array_slice($s['people'], 0, 8) as $p) {
            $L[] = '    * ' . $p['name'] . ' (id ' . $p['id'] . ($p['roles'] ? ', ' . implode('/', array_slice($p['roles'], 0, 3)) : '') . '): open ' . count($p['open']) . ', done ' . count($p['done']) . ', missed ' . count($p['missed']) . '; 30-day ' . ($p['track']['tasks'] ? $p['track']['on_time'] . '% on time' : 'no record') . ($p['track']['penalties'] > 0 ? ', ' . dp_inr($p['track']['penalties']) . ' penalties' : '');
            foreach (array_slice(array_merge($p['open'], $p['missed']), 0, 4) as $it) $L[] = '        - [' . $it['kind'] . ' ' . $it['id'] . '] ' . $it['title'] . ' (' . $it['state'] . ($it['due'] ? ', due ' . $it['due'] : '') . ')';
        }
        foreach (array_slice($s['unowned'], 0, 4) as $it) $L[] = '    * NOBODY OWNS: [' . $it['kind'] . ' ' . $it['id'] . '] ' . $it['title'] . ' (' . $it['state'] . ')';
    }
    $L[] = "\nPENALTY RULES (rkey | title | amount | mode | seat | on/off):";
    try { foreach ($pdo->query("SELECT rkey, title, amount, mode, seat_code, active FROM dp_rules ORDER BY sort_no, id LIMIT 60")->fetchAll() as $r) $L[] = $r['rkey'] . ' | ' . $r['title'] . ' | ' . dp_inr((float)$r['amount']) . ' | ' . $r['mode'] . ' | ' . ($r['seat_code'] ?: '-') . ' | ' . ($r['active'] ? 'on' : 'off'); } catch (Throwable $e) {}
    if (function_exists('dp_pen_overview')) {
        try { list($m1, $m2) = dp_month_range(''); $L[] = "\nPENALTIES THIS MONTH BY SECTION:"; foreach (dp_pen_overview($pdo, $m1, $m2) as $s) { $L[] = '- ' . $s['name'] . ': charged ' . dp_inr($s['month']['amount']) . ' (' . $s['month']['count'] . '), waiting ' . dp_inr($s['month']['waiting_amount']) . ' (' . $s['month']['waiting_count'] . '), ' . count($s['rule_keys']) . ' Boss rules, ' . count($s['hr_rules']) . ' HR rules'; } } catch (Throwable $e) {}
    }
    try { $c = dp_q_counts($pdo); $L[] = "\nQUESTIONS: " . $c['open'] . ' waiting, ' . $c['understood'] . ' topics waiting for your OK, ' . $c['live'] . ' of ' . $c['topics'] . ' topics live.'; } catch (Throwable $e) {}
    try { $L[] = 'INBOX: ' . (int)$pdo->query("SELECT COUNT(*) FROM bd_inbox WHERE status = 'open'")->fetchColumn() . ' items waiting.'; } catch (Throwable $e) {}
    try { $L[] = 'TELL-THE-BOSS JOBS OPEN: ' . (int)$pdo->query("SELECT COUNT(*) FROM dp_tk_tasks WHERE status = 'open'")->fetchColumn() . '.'; } catch (Throwable $e) {}
    $L[] = "\nPEOPLE (id | name | dept | role):";
    foreach (array_slice(bd_employees($pdo), 0, 120) as $e) $L[] = (int)$e['id'] . ' | ' . $e['name'] . ' | ' . ($e['dept'] ?: '-') . ' | ' . ($e['designation'] ?: '-');
    if (function_exists('ofp_hq_context')) { try { $pc = ofp_hq_context($pdo, $w); if ($pc !== '') $L[] = $pc; } catch (Throwable $e) {} }   // registered places: pantry counts, refills, equipment, owners
    $L[] = "\nPAGES (file | what it is):"; foreach (dp_hq_pages() as $f => $d) $L[] = $f . ' | ' . $d;
    return implode("\n", $L);
}

/** words in the question -> pages, used when Claude cannot be reached */
function dp_hq_route(PDO $pdo, $text) {
    $t = mb_strtolower($text); $map = [
        'penalt|fine|charge|deduct' => 'boss_penalties.html', 'question|learn|rule' => 'boss_questions.html', 'parcel|dispatch|courier|track' => 'dept_dispatch.html',
        'cash|money|bank|finance|account' => 'finance_in.html', 'shop|store|retail' => 'dept_shops.html', 'suppl|buy|purchase' => 'dept_supplies.html', 'repair|fix|broken' => 'dept_fix.html',
        'office|clean' => 'office_care.html', 'seat|boss|team|role|setup|who does|duty|duties|standing' => 'boss_engine.html?tab=people', 'inbox|waiting|approve' => 'boss_inbox.html',
        'task|tell|give|assign|problem' => 'boss_engine.html', 'attend|punch|late' => 'attendance_admin.html', 'leave|holiday' => 'leave_requests.html', 'document|doc|aadhaar|pan' => 'doc_check.html',
        'health|error|broken page|500' => 'system_health.html', 'leaver|handover|resign' => 'handover.html',
    ];
    $pages = dp_hq_pages(); $o = [];
    foreach ($map as $re => $f) { if (preg_match('/' . $re . '/u', $t) && isset($pages[$f])) $o[] = ['label' => $pages[$f], 'page' => $f]; }
    return array_slice($o, 0, 3);
}

function dp_hq_clean_links($links) {
    $pages = dp_hq_pages(); $o = [];
    foreach ((array)$links as $l) {
        $f = (string)($l['page'] ?? ''); $base = strtok($f, '?'); if (!isset($pages[$base])) continue;
        if (strpos($f, '?') !== false && !preg_match('/^[a-z_.]+\?[a-z_]+=[A-Za-z0-9_.-]{1,40}$/', $f)) $f = $base;
        $o[] = ['label' => mb_substr(trim((string)($l['label'] ?? $pages[$base])), 0, 80), 'page' => $f];
    }
    return array_slice($o, 0, 4);
}
/** one proposed action -> a clean, safe, human-readable proposal (or null if it is not allowed) */
function dp_hq_clean_action(PDO $pdo, array $w, $a) {
    if (!is_array($a)) return null; $type = (string)($a['type'] ?? ''); $g = is_array($a['args'] ?? null) ? $a['args'] : [];
    if ($type === 'place_owner') return function_exists('ofp_hq_clean_action') ? ofp_hq_clean_action($pdo, $w, $a) : null;   // who does a place's daily check (office_place.php)
    $label = mb_substr(trim((string)($a['label'] ?? '')), 0, 200);
    switch ($type) {
    case 'rule_set': {
        $r = dp_rule($pdo, (string)($g['rkey'] ?? '')); if (!$r) return null;
        $args = ['rkey' => $r['rkey']];
        if (isset($g['amount'])) { $x = (float)$g['amount']; if ($x < 0 || $x > 100000) return null; $args['amount'] = $x; }
        if (isset($g['mode']) && in_array($g['mode'], ['auto', 'approve'], true)) $args['mode'] = $g['mode'];
        if (isset($g['active'])) $args['active'] = !empty($g['active']) ? 1 : 0;
        if (count($args) < 2) return null;
        $bits = []; if (isset($args['amount'])) $bits[] = 'amount ' . dp_inr($args['amount']) . ' (now ' . dp_inr((float)$r['amount']) . ')'; if (isset($args['mode'])) $bits[] = 'mode ' . $args['mode']; if (isset($args['active'])) $bits[] = $args['active'] ? 'switch ON' : 'switch OFF';
        return ['type' => $type, 'args' => $args, 'label' => $label ?: ('Penalty "' . $r['title'] . '": ' . implode(', ', $bits)), 'admin' => false];
    }
    case 'seat_set': {
        $code = (string)($g['code'] ?? ''); $sid = bd_seat_id($pdo, $code); if (!$sid) return null;
        $ids = array_values(array_filter(array_map('intval', (array)($g['emp_ids'] ?? [])))); if (!$ids) return null;
        foreach ($ids as $i) { if (!bd_emp_active(bd_emp($pdo, $i))) return null; }
        $row = bd_seat_row($pdo, $sid);
        return ['type' => $type, 'args' => ['code' => $code, 'emp_ids' => $ids], 'label' => $label ?: ('Seat "' . $row['title'] . '": ' . implode(', ', array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $ids))), 'admin' => true];
    }
    case 'build_request': {
        $t = mb_substr(trim((string)($g['title'] ?? '')), 0, 120); $d = mb_substr(trim((string)($g['details'] ?? '')), 0, 4000);
        if (mb_strlen($t) < 4 || mb_strlen($d) < 10) return null;
        return ['type' => $type, 'args' => ['title' => $t, 'details' => $d], 'label' => $label ?: ('Ask the builder to make: ' . $t), 'admin' => true];
    }
    case 'person_title': {
        if (!function_exists('pp_title_apply')) return null;
        $ids = array_values(array_filter(array_map('intval', (array)($g['emp_ids'] ?? [])))); $name = mb_substr(trim((string)($g['title'] ?? '')), 0, 80);
        if (!$ids || $name === '') return null;
        foreach ($ids as $i) { if (!bd_emp_active(bd_emp($pdo, $i))) return null; }
        $args = ['emp_ids' => $ids, 'title' => $name, 'apply' => isset($g['apply']) ? (!empty($g['apply']) ? 1 : 0) : 1];
        foreach (['salary', 'mode', 'shift_start', 'shift_end', 'hours', 'late_after'] as $f) if (isset($g[$f]) && $g[$f] !== '') $args[$f] = $g[$f];
        $bits = []; if (isset($args['salary'])) $bits[] = 'salary ' . dp_inr((float)$args['salary']); if (isset($args['hours'])) $bits[] = $args['hours'] . ' hours a day'; if (isset($args['shift_start'], $args['shift_end'])) $bits[] = $args['shift_start'] . '-' . $args['shift_end'];
        return ['type' => $type, 'args' => $args, 'label' => $label ?: (implode(', ', array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $ids)) . ' becomes ' . $name . ($bits ? ' (' . implode(', ', $bits) . ')' : '')), 'admin' => true];
    }
    case 'task_create': {
        $title = trim((string)($g['title'] ?? '')); $emp = (int)($g['owner_emp'] ?? 0); if (mb_strlen($title) < 3 || !$emp || !bd_emp_active(bd_emp($pdo, $emp))) return null;
        $due = trim((string)($g['due_date'] ?? '')); if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = '';
        return ['type' => $type, 'args' => ['title' => mb_substr($title, 0, 200), 'detail' => mb_substr(trim((string)($g['detail'] ?? '')), 0, 600), 'owner_emp' => $emp, 'due_date' => $due], 'label' => $label ?: ('Give ' . bd_emp_name($pdo, $emp) . ' the job: ' . mb_substr($title, 0, 80) . ($due ? ' (by ' . $due . ')' : '')), 'admin' => false];
    }
    case 'mark_done': {
        $kind = (string)($g['kind'] ?? ''); $id = (int)($g['id'] ?? 0); if (!in_array($kind, ['duty', 'item', 'task'], true) || !$id) return null;
        return ['type' => $type, 'args' => ['kind' => $kind, 'id' => $id], 'label' => $label ?: ('Mark job #' . $id . ' as done'), 'admin' => false];
    }
    }
    return null;
}

/** marks one job done on the boss's word. Returns a one-line message. */
function dp_hq_mark_done(PDO $pdo, array $w, $kind, $id, $by) {
    $now = bd_now(); $note = 'marked done by ' . $by;
    if ($kind === 'duty') {
        $st = $pdo->prepare("SELECT * FROM bd_tasks WHERE id = ?"); $st->execute([$id]); $t = $st->fetch(); if (!$t) json_response(['error' => 'Job not found.'], 404);
        if (!bd_can_section($w, (int)$t['section_id'])) json_response(['error' => 'That job is not in your section.'], 403);
        $pdo->prepare("UPDATE bd_tasks SET status = 'done', done_at = COALESCE(done_at, ?), proof_note = COALESCE(NULLIF(proof_note, ''), ?), pen_state = IF(pen_state IN ('pending','shadow'), 'none', pen_state) WHERE id = ?")->execute([$now, $note, $id]);
        bd_log('hq_done', 'task:' . $id, $note, $by); return $t['pen_state'] === 'applied' ? 'Marked done. A penalty was already charged for it - remove it in Penalties if it was a mistake.' : 'Marked done.';
    }
    if ($kind === 'item') {
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE id = ?"); $st->execute([$id]); $t = $st->fetch(); if (!$t) json_response(['error' => 'Job not found.'], 404);
        $code = strtok((string)$t['seat_code'], '.'); $sid = (int)$pdo->query("SELECT id FROM bd_sections WHERE code = " . $pdo->quote((string)$code))->fetchColumn();
        if ($sid && !bd_can_section($w, $sid)) json_response(['error' => 'That job is not in your section.'], 403);
        $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = COALESCE(done_at, ?), pen_state = IF(pen_state IN ('pending','shadow'), 'none', pen_state) WHERE id = ?")->execute([$now, $id]);
        bd_log('hq_done', 'item:' . $id, $note, $by); return $t['pen_state'] === 'applied' ? 'Marked done. A penalty was already charged for it - remove it in Penalties if it was a mistake.' : 'Marked done.';
    }
    if ($kind === 'task') {
        $st = $pdo->prepare("SELECT * FROM dp_tk_tasks WHERE id = ?"); $st->execute([$id]); $t = $st->fetch(); if (!$t) json_response(['error' => 'Job not found.'], 404);
        $pdo->prepare("UPDATE dp_tk_tasks SET status = 'done', done_at = COALESCE(done_at, ?), checked_by = ?, checked_at = ?, check_note = ? WHERE id = ?")->execute([$now, $by, $now, 'marked done on the Summary', $id]);
        $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = COALESCE(done_at, ?) WHERE ref_key LIKE ? AND status = 'open'")->execute([$now, 'tk:' . $id . ':%']);
        bd_log('hq_done', 'tk:' . $id, $note, $by); return 'Marked done.';
    }
    json_response(['error' => 'Unknown job type.'], 422);
}

/** Claude, the boss's assistant. Returns ['ai' => ok|off|error, 'reply', 'links', 'actions', 'prompt', 'gaps'] */
function dp_hq_claude(PDO $pdo, array $w, $msg, array $hist, $focus = '') {
    $fb = function ($ai, $reply) use ($pdo, $msg) { return ['ai' => $ai, 'reply' => $reply, 'links' => dp_hq_route($pdo, $msg), 'actions' => [], 'prompt' => '', 'gaps' => []]; };
    $ofpR = function_exists('ofp_hq_rule_answer') ? (function () use ($pdo, $w, $msg) { try { return ofp_hq_rule_answer($pdo, $w, $msg); } catch (Throwable $e) { return null; } }) : null;   // pantry / refill / to buy / minimum / place owner questions answered from today's counts when the AI is off
    if ($ofpR && (!function_exists('tmph_claude_key') || tmph_claude_key() === '') && ($ofpRes = $ofpR())) return $ofpRes;
    if (!function_exists('tmph_claude_key') || tmph_claude_key() === '') {
        $b = dp_hq_board($pdo, $w, ''); $open = 0; $miss = 0; foreach ($b as $s) { $open += $s['open']; $miss += $s['missed']; }
        return $fb('off', 'Claude is not connected yet (the ANTHROPIC_API_KEY line is missing in config.php), so I cannot answer in full. What I can see today: ' . $open . ' jobs open and ' . $miss . ' missed across ' . count($b) . ' sections.' . (dp_hq_route($pdo, $msg) ? ' These pages may be what you want:' : ''));
    }
    $sys = "You are Claude inside the Boss page of a perfume company's HRMS in India (Four Pirates / TM Perfume House). You talk to the OWNER. You are the total boss assistant: you answer anything about the company's sections, people, jobs, penalties and pages, you guide him to the right page, and you propose changes. "
        . "You are given LIVE DATA below. Use only it for facts about the company - never invent a person, amount, job or page. If the data does not show something, say it is not in the system. "
        . "Reply with JSON ONLY, no other text, in exactly this shape: "
        . '{"reply":"","links":[{"label":"","page":""}],"actions":[{"type":"","label":"","args":{}}],"gaps":[""],"prompt":""}. '
        . "reply: short, plain, simple English (2-6 short sentences, a shop owner reads it on a phone). links: up to 3 pages from the PAGES list (exact file name) that take him where he should go. "
        . "actions: ONLY when he asks you to change something or the fix is obvious; you NEVER do it yourself - he will press Yes. Allowed types and args: "
        . '{"type":"rule_set","args":{"rkey":"","amount":0,"mode":"auto|approve","active":1}} (change a penalty rule - only fields that change), '
        . '{"type":"seat_set","args":{"code":"","emp_ids":[0]}} (put people in a seat - use a seat code and ids from the data), '
        . '{"type":"person_title","args":{"emp_ids":[0],"title":"CEO","salary":0,"mode":"fixed|hours|free","shift_start":"HH:MM","shift_end":"HH:MM","hours":8,"late_after":15,"apply":1}} (give people a title such as CEO or CFO and, if he says so, that title\'s monthly salary and working time: mode hours = any time, complete the hours; fixed = start and end; free = no punch rule. Leave out fields he did not mention), '
        . '{"type":"task_create","args":{"title":"","detail":"","owner_emp":0,"due_date":"YYYY-MM-DD"}} (give one person a one-time job), '
        . '{"type":"mark_done","args":{"kind":"duty|item|task","id":0}} (mark a listed job done - use the kind and id shown in the data). Each action needs a plain label saying exactly what will happen. '
        . "gaps: things that are MISSING or WRONG in the system for what he asked (a section with no boss, an empty seat, a rule at 0 rupees, a job nobody owns, a department with no questions answered, a section that is only in a sidebar but has no page). Also say here if two things COLLIDE (the same job in two places, two rules for the same miss). "
        . "prompt: ONLY when something is missing that needs new work on the HRMS itself (a new page, a new report, a new section) - write a ready-to-paste request he can give to his builder, in plain words, with what he wants and where it should appear. Otherwise an empty string. "
        . "Never say you changed something. Never ask more than one question. If you are unsure, say so and ask.";
    if (function_exists('ofp_hq_prompt')) $sys .= ofp_hq_prompt();
    $msgs = []; foreach (array_slice($hist, -8) as $h) { $r = ($h['role'] ?? '') === 'user' ? 'user' : 'assistant'; $c = mb_substr(trim((string)($h['text'] ?? '')), 0, 1500); if ($c === '') continue; if ($msgs && $msgs[count($msgs) - 1]['role'] === $r) continue; $msgs[] = ['role' => $r, 'content' => $c]; }
    while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
    if ($msgs && $msgs[count($msgs) - 1]['role'] === 'user') array_pop($msgs);
    $ctx = dp_hq_context($pdo, $w);
    if ($focus === 'dispatch' && function_exists('dp_dsp_chat_context')) { try { $ctx .= "\n" . dp_dsp_chat_context($pdo, time()); } catch (Throwable $e) {} $sys .= " He is on the Dispatch (Parcels and tracking) page right now: answer about parcels, tracking numbers, Shiprocket and the people who handle them first, and use the DISPATCH FOCUS data. Statuses mean: no tracking = no tracking number yet, tracking assigned = number saved but not yet picked up, dispatched = with the courier, delivered, RTO, cancelled."; }
    $msgs[] = ['role' => 'user', 'content' => "LIVE DATA\n" . $ctx . "\n\nHIS MESSAGE:\n" . mb_substr($msg, 0, 1500)];
    $o = function_exists('brain_ai_json') ? brain_ai_json('internal', 'boss', $sys, $msgs, 1400, ['timeout' => 45]) : null;   // the ONE Claude door
    if (!is_array($o) || trim((string)($o['reply'] ?? '')) === '') { error_log('Boss chat: no usable answer from Claude'); if ($ofpR && ($ofpRes = $ofpR())) return $ofpRes; return $fb('error', 'I could not reach Claude just now. Try again in a minute. These pages may help:'); }
    $acts = []; foreach (array_slice((array)($o['actions'] ?? []), 0, 4) as $a) { $c = dp_hq_clean_action($pdo, $w, $a); if ($c) $acts[] = $c; }
    $gaps = []; foreach (array_slice((array)($o['gaps'] ?? []), 0, 6) as $g) { $g = trim((string)$g); if ($g !== '') $gaps[] = mb_substr($g, 0, 300); }
    return ['ai' => 'ok', 'reply' => mb_substr(trim((string)$o['reply']), 0, 1800), 'links' => dp_hq_clean_links($o['links'] ?? []), 'actions' => $acts, 'prompt' => mb_substr(trim((string)($o['prompt'] ?? '')), 0, 1500), 'gaps' => $gaps];
}

if (strpos((string)$action, 'dp.hq_') === 0) {
    try {
        dp_ensure($pdo); dp_hq_ensure($pdo);
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $auth = require_auth(); $w = bd_who($pdo, $auth); $by = (string)($auth['username'] ?? 'user'); $in = $isPost ? json_input() : [];
        bd_need_manager($pdo, $auth);
        switch ($action) {
        case 'dp.hq_track': {
            $only = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['section'] ?? ''));
            json_response(['today' => bd_today(), 'mode' => bd_mode(), 'sections' => dp_hq_board($pdo, $w, $only), 'ai' => (function_exists('tmph_claude_key') && tmph_claude_key() !== '') ? 'on' : 'off', 'admin' => !empty($w['admin'])]);
        }
        case 'dp.hq_people': {
            $by2 = []; foreach (dp_hq_board($pdo, $w, '') as $s) { foreach ($s['people'] as $p) {
                if (!isset($by2[$p['id']])) $by2[$p['id']] = ['id' => $p['id'], 'name' => $p['name'], 'roles' => [], 'sections' => [], 'open' => 0, 'done' => 0, 'missed' => 0, 'jobs' => []];
                $by2[$p['id']]['sections'][] = $s['name']; $by2[$p['id']]['open'] += count($p['open']); $by2[$p['id']]['done'] += count($p['done']); $by2[$p['id']]['missed'] += count($p['missed']);
                foreach (array_slice($p['open'], 0, 4) as $it) $by2[$p['id']]['jobs'][] = $it['title'];
            } }
            foreach ($by2 as $id => &$p) { $p['roles'] = dp_hq_roles($pdo, $id); $p['sections'] = array_values(array_unique($p['sections'])); } unset($p);
            // one-time jobs given from Tell the boss
            try { foreach ($pdo->query("SELECT owner_emp, COUNT(*) c FROM dp_tk_tasks WHERE status = 'open' AND owner_emp IS NOT NULL GROUP BY owner_emp")->fetchAll() as $r) { $id = (int)$r['owner_emp']; if (!isset($by2[$id])) $by2[$id] = ['id' => $id, 'name' => bd_emp_name($pdo, $id), 'roles' => dp_hq_roles($pdo, $id), 'sections' => [], 'open' => 0, 'done' => 0, 'missed' => 0, 'jobs' => []]; $by2[$id]['tk_open'] = (int)$r['c']; } } catch (Throwable $e) {}
            $list = array_values($by2); usort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            json_response(['people' => $list, 'at' => bd_now()]);
        }
        case 'dp.hq_done': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $msg = dp_hq_mark_done($pdo, $w, (string)($in['kind'] ?? ''), (int)($in['id'] ?? 0), $by);
            json_response(['ok' => true, 'message' => $msg]);
        }
        case 'dp.hq_ack': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $code = preg_replace('/[^a-z0-9_]/', '', (string)($in['section'] ?? '')); $b = dp_hq_board($pdo, $w, $code); if (!$b) json_response(['error' => 'Unknown section.'], 404);
            $s = $b[0];
            $pdo->prepare("INSERT INTO dp_hq_ack (section, day, by_name, note, summary, done_n, total_n, created_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE by_name = VALUES(by_name), note = VALUES(note), summary = VALUES(summary), done_n = VALUES(done_n), total_n = VALUES(total_n), created_at = VALUES(created_at)")
                ->execute([$code, bd_today(), $by, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), mb_substr($s['wrap'], 0, 900), $s['done'], $s['total'], bd_now()]);
            bd_log('hq_ack', $code, 'OK, fixed: ' . $s['wrap'], $by);
            json_response(['ok' => true, 'message' => $s['name'] . ' closed for today.']);
        }
        case 'dp.hq_chat': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $msg = trim((string)($in['message'] ?? '')); if (mb_strlen($msg) < 2) json_response(['error' => 'Write your question first.'], 422);
            $hist = is_array($in['history'] ?? null) ? $in['history'] : [];
            $hqr = dp_hq_claude($pdo, $w, $msg, $hist, (string)($in['focus'] ?? ''));
            try { if (!function_exists('ll_log') && is_file(__DIR__ . '/learn_loop.php')) require_once __DIR__ . '/learn_loop.php'; if (function_exists('ll_log') && !empty($hqr['reply'])) ll_log($pdo, 'boss', $by, $msg, (string)$hqr['reply'], 'none', !empty($hqr['actions']) ? ('proposed ' . count($hqr['actions']) . ' action(s)') : ''); } catch (Throwable $llErr) {}
            json_response(['ok' => true] + $hqr);
        }
        case 'dp.hq_act': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = dp_hq_clean_action($pdo, $w, ['type' => (string)($in['type'] ?? ''), 'args' => is_array($in['args'] ?? null) ? $in['args'] : []]);
            if (!$c) json_response(['error' => 'I cannot do that one. Ask Claude again.'], 422);
            if (!empty($c['admin'])) bd_need_admin($auth);
            $g = $c['args']; $msg = 'Done.';
            switch ($c['type']) {
            case 'rule_set': {
                $r = dp_rule($pdo, $g['rkey']);
                $amt = isset($g['amount']) ? (float)$g['amount'] : (float)$r['amount']; $mode = $g['mode'] ?? $r['mode']; $act = isset($g['active']) ? (int)$g['active'] : (int)$r['active'];
                $pdo->prepare("UPDATE dp_rules SET amount = ?, mode = ?, active = ?, decide = ?, updated_at = ? WHERE rkey = ?")->execute([$amt, $mode, $act, $amt > 0 ? 0 : (int)$r['decide'], bd_now(), $r['rkey']]);
                bd_log('rule', $r['rkey'], 'via Summary chat: amount ' . $amt . ', ' . $mode . ', ' . ($act ? 'on' : 'off'), $by); $msg = 'Penalty rule updated.'; break;
            }
            case 'seat_set': {
                $sid = bd_seat_id($pdo, $g['code']); bd_set_holders($pdo, $sid, $g['emp_ids']); bd_log('seat', $g['code'], 'holders (Summary chat): ' . implode(',', $g['emp_ids']), $by);
                foreach ($g['emp_ids'] as $e) bd_push($pdo, $e, 'You now hold a seat', bd_seat_row($pdo, $sid)['title'] . '. Your tasks are in My Day.');
                $pdo->prepare("UPDATE dp_items SET owner_emp = ? WHERE seat_code = ? AND status = 'open'")->execute([$g['emp_ids'][0], $g['code']]); $msg = 'Seat updated.'; break;
            }
            case 'build_request': {
                if (!function_exists('chg_create')) { $cf = __DIR__ . '/changes.php'; if (is_file($cf)) require_once $cf; }
                if (!function_exists('chg_create')) json_response(['error' => 'Changes are not installed.'], 422);
                list($cok, $cmsg) = chg_create($g['title'], $g['details'], $by); if (!$cok) json_response(['error' => $cmsg], 422);
                $msg = $cmsg; break;
            }
            case 'person_title': {
                if (!function_exists('pp_title_apply')) json_response(['error' => 'People is not installed.'], 422);
                pp_titles_ensure($pdo);
                if (isset($g['salary']) || isset($g['mode']) || isset($g['hours']) || isset($g['shift_start'])) { $q = $pdo->prepare("SELECT * FROM pp_titles WHERE LOWER(name) = LOWER(?)"); $q->execute([$g['title']]); $old = $q->fetch() ?: []; pp_title_save($pdo, pp_title_clean(array_merge($old, array_diff_key($g, ['emp_ids' => 1, 'apply' => 1]), ['name' => $g['title']]))); }
                $ms = []; foreach ($g['emp_ids'] as $i) $ms[] = pp_title_apply($pdo, $i, $g['title'], !empty($g['apply']), $by);
                $msg = implode(' ', $ms); break;
            }
            case 'task_create': {
                if (!function_exists('dp_tk_make_task')) json_response(['error' => 'The Tell-the-boss job system is not installed.'], 422);
                dp_tk_ensure($pdo);
                $due = $g['due_date'] !== '' ? $g['due_date'] . ' 18:00:00' : date('Y-m-d', strtotime('+1 day')) . ' 18:00:00';
                dp_tk_make_task($pdo, 0, 0, 'one', ['title' => $g['title'], 'detail' => $g['detail'], 'owner_emp' => $g['owner_emp'], 'checker_emp' => 0, 'due_at' => $due, 'proof' => 'none', 'every_days' => 0], $by);
                bd_push($pdo, $g['owner_emp'], 'New job for you', $g['title']); bd_log('hq_task', (string)$g['owner_emp'], $g['title'], $by); $msg = 'Job given. It shows in My Day for that person and on this board.'; break;
            }
            case 'place_owner': { $msg = ofp_hq_do_place_owner($pdo, $g, $by); break; }
            case 'mark_done': { $msg = dp_hq_mark_done($pdo, $w, $g['kind'], $g['id'], $by); break; }
            }
            try { if (!function_exists('ll_log') && is_file(__DIR__ . '/learn_loop.php')) require_once __DIR__ . '/learn_loop.php'; if (function_exists('ll_log')) ll_log($pdo, 'action', $by, (string)($c['label'] ?? ''), (string)$msg, 'accepted', (string)($c['type'] ?? '')); } catch (Throwable $llErr) {}
            json_response(['ok' => true, 'message' => $msg]);
        }
        }
    } catch (PDOException $e) {
        json_response(['error' => 'Database problem in the Summary: ' . mb_substr($e->getMessage(), 0, 200)], 500);
    }
}

/* ============================== punch-out lock (My Day) ==============================
   A person punches out only when every job of the day is done. If a job cannot be done, they press "Ask for punch out":
   the request lands in Boss > Inbox and a phone notice goes to the HR head (Rahul Vakharia, found by name), the Admin and the top boss.
   "Allow" lets that person punch out for that day. In test mode (shadow) nothing is locked.
     dp.po_state  (any employee)  what is left, locked or not, the request state
     dp.po_ask    (POST {note?})  ask for permission
   api.php calls dp_po_gate() just before it accepts a Punch Out, so the lock also holds outside My Day. */
function dp_po_day(PDO $pdo, $emp) {
    try {
        $st = $pdo->prepare("SELECT punch_date FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ? ORDER BY punch_in DESC LIMIT 1");
        $st->execute([(int)$emp, date('Y-m-d H:i:s', time() - 20 * 3600)]); $d = $st->fetchColumn();
        return $d ? (string)$d : '';
    } catch (Throwable $e) { return ''; }
}
function dp_po_left(PDO $pdo, $emp, $day) {
    $o = [];
    try {
        $st = $pdo->prepare("SELECT d.title, t.due_at FROM bd_tasks t JOIN bd_duties d ON d.id = t.duty_id WHERE t.owner_emp = ? AND t.task_date = ? AND t.status = 'open' ORDER BY t.due_at");
        $st->execute([(int)$emp, $day]);
        foreach ($st->fetchAll() as $r) $o[] = ['title' => (string)$r['title'], 'due' => $r['due_at'] ? date('g:i A', strtotime($r['due_at'])) : ''];
        $st = $pdo->prepare("SELECT title, due_at FROM dp_items WHERE owner_emp = ? AND task_date = ? AND status = 'open' AND kind != 'punch' ORDER BY due_at");
        $st->execute([(int)$emp, $day]);
        foreach ($st->fetchAll() as $r) $o[] = ['title' => (string)$r['title'], 'due' => $r['due_at'] ? date('g:i A', strtotime($r['due_at'])) : ''];
    } catch (Throwable $e) {}
    if (function_exists('b2_po_left')) { foreach (b2_po_left($pdo, $emp, $day) as $r) $o[] = $r; }   // Boss Engine 2 tasks
    return $o;
}
function dp_po_hr(PDO $pdo) {
    static $c = null; if ($c !== null) return $c;
    $c = ['id' => 0, 'name' => ''];
    try {
        $r = $pdo->query("SELECT employee_id, full_name FROM employees WHERE full_name LIKE '%Vakharia%' AND (status IS NULL OR status <> 'ex') ORDER BY (full_name LIKE '%Rahul%') DESC, employee_id LIMIT 1")->fetch();
        if ($r) $c = ['id' => (int)$r['employee_id'], 'name' => (string)$r['full_name']];
    } catch (Throwable $e) {}
    return $c;
}
function dp_po_state(PDO $pdo, $emp) {
    $day = dp_po_day($pdo, $emp); $hr = dp_po_hr($pdo);
    $o = ['mode' => bd_mode(), 'day' => $day, 'in' => $day !== '', 'left' => [], 'locked' => false, 'req' => null, 'approved' => null, 'hr' => $hr['name']];
    if ($day === '' || bd_mode() === 'shadow') return $o;
    $o['left'] = dp_po_left($pdo, $emp, $day);
    $ok = dp_get('po_ok:' . $emp . ':' . $day, '');
    if ($ok !== '') { $p = explode('|', $ok); $o['approved'] = ['by' => $p[0], 'at' => $p[1] ?? '']; }
    $rq = json_decode(dp_get('po_req:' . $emp . ':' . $day, ''), true);
    if (is_array($rq)) $o['req'] = ['state' => (string)($rq['state'] ?? ''), 'at' => (string)($rq['at'] ?? ''), 'by' => (string)($rq['by'] ?? '')];
    $o['locked'] = count($o['left']) > 0 && !$o['approved'];
    return $o;
}
/** called by api.php right before a Punch Out is accepted */
function dp_po_gate(PDO $pdo) {
    try {
        $auth = require_auth(); $emp = (int)($auth['employee_id'] ?? 0); if (!$emp) return;
        $s = dp_po_state($pdo, $emp);
        if (!$s['locked']) return;
        $t = array_map(function ($x) { return $x['title']; }, array_slice($s['left'], 0, 3));
        json_response(['error' => 'Punch out opens after your jobs are done. Still open: ' . implode('; ', $t) . (count($s['left']) > 3 ? ' and ' . (count($s['left']) - 3) . ' more' : '') . '. If it cannot be done, open My Day and press "Ask for punch out".', 'punchout_locked' => true, 'left' => $s['left']], 409);
    } catch (PDOException $e) { /* a database hiccup must never trap someone at work */ }
}
function dp_po_notify(PDO $pdo, $exceptEmp, $title, $body) {
    if (!function_exists('push_send_employee')) return 0;
    $ids = [];
    $hr = dp_po_hr($pdo); if ($hr['id']) $ids[] = $hr['id'];
    foreach (['top_boss', 'deputy'] as $k) { $v = (int)bd_cfg_fresh($k); if ($v) $ids[] = $v; }
    try { foreach ($pdo->query("SELECT DISTINCT employee_id FROM users WHERE role IN ('Admin', 'HR') AND employee_id IS NOT NULL AND employee_id > 0")->fetchAll() as $r) $ids[] = (int)$r['employee_id']; } catch (Throwable $e) {}
    $n = 0;
    foreach (array_unique($ids) as $id) { if ($id === (int)$exceptEmp) continue; try { push_send_employee($pdo, $id, ['title' => $title, 'body' => mb_substr($body, 0, 160), 'url' => 'hub.html?w=boss&t=boss_inbox', 'tag' => 'hrms-po']); $n++; } catch (Throwable $e) {} }
    return $n;
}
/** the boss pressed Allow / No on the request in Boss > Inbox (called from dp_inbox_act). Returns [message, close]. */
function dp_po_inbox_act(PDO $pdo, array $it, $act, array $w, $by) {
    $p = bd_unjson($it['payload']); $emp = (int)($p['emp_id'] ?? 0); $day = (string)($p['day'] ?? '');
    if (!$emp || $day === '') return ['Request is gone.', 'dismissed'];
    if ($emp === (int)$w['emp'] && !$w['admin']) json_response(['error' => 'You cannot decide your own punch out.'], 403);
    $name = bd_emp_name($pdo, $emp);
    if ($act === 'approve') {
        dp_put('po_ok:' . $emp . ':' . $day, $by . '|' . date('H:i'));
        dp_put('po_req:' . $emp . ':' . $day, json_encode(['state' => 'approved', 'at' => date('H:i'), 'by' => $by]));
        if (function_exists('push_send_employee')) { try { push_send_employee($pdo, $emp, ['title' => 'You can punch out', 'body' => $by . ' allowed your punch out.', 'url' => 'my_day.html', 'tag' => 'hrms-po']); } catch (Throwable $e) {} }
        bd_log('punchout', (string)$emp, 'allowed for ' . $day, $by);
        return [$name . ' can punch out now.', 'done'];
    }
    if ($act === 'reject') {
        dp_put('po_req:' . $emp . ':' . $day, json_encode(['state' => 'denied', 'at' => date('H:i'), 'by' => $by]));
        if (function_exists('push_send_employee')) { try { push_send_employee($pdo, $emp, ['title' => 'Punch out not allowed', 'body' => 'Finish your open jobs, or call HR.', 'url' => 'my_day.html', 'tag' => 'hrms-po']); } catch (Throwable $e) {} }
        bd_log('punchout', (string)$emp, 'not allowed for ' . $day, $by);
        return ['Not allowed. ' . $name . ' is told to finish the jobs.', 'done'];
    }
    json_response(['error' => 'Choose allow or no.'], 422);
}

if (strpos((string)$action, 'dp.po_') === 0) {
    try {
        dp_ensure($pdo);
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $auth = require_auth(); $emp = (int)($auth['employee_id'] ?? 0); $by = (string)($auth['username'] ?? 'user');
        if (!$emp) json_response(['error' => 'This login is not linked to an employee.'], 400);
        switch ($action) {
        case 'dp.po_state': { json_response(dp_po_state($pdo, $emp)); }
        case 'dp.po_ask': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $s = dp_po_state($pdo, $emp);
            if (!$s['in']) json_response(['error' => 'You are not punched in.'], 409);
            if (!$s['locked']) json_response(['ok' => true, 'message' => 'Nothing is stopping your punch out.', 'state' => $s]);
            if ($s['req'] && $s['req']['state'] === 'pending') json_response(['ok' => true, 'message' => 'Already asked at ' . $s['req']['at'] . '. Wait for the answer, or call ' . ($s['hr'] !== '' ? $s['hr'] : 'HR') . '.', 'state' => $s]);
            $day = $s['day']; $n = (int)dp_get('po_n:' . $emp . ':' . $day, '0') + 1; dp_put('po_n:' . $emp . ':' . $day, (string)$n);
            $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200); $name = bd_emp_name($pdo, $emp);
            $titles = array_map(function ($x) { return $x['title']; }, $s['left']);
            $title = $name . ' cannot punch out: ' . count($titles) . ' job' . (count($titles) === 1 ? '' : 's') . ' not done';
            $body = 'Still open: ' . implode('; ', array_slice($titles, 0, 6)) . (count($titles) > 6 ? ' and ' . (count($titles) - 6) . ' more' : '') . '.' . ($note !== '' ? ' ' . $name . ' says: ' . $note : '');
            $iid = bd_inbox('dp_punchout', $title, $body, ['emp_id' => $emp, 'day' => $day, 'left' => $titles, 'note' => $note], null, null, 'dp_po:' . $emp . ':' . $day . ':' . $n);
            dp_put('po_req:' . $emp . ':' . $day, json_encode(['state' => 'pending', 'at' => date('H:i'), 'id' => $iid]));
            $told = dp_po_notify($pdo, $emp, 'Punch out request: ' . $name, count($titles) . ' job' . (count($titles) === 1 ? '' : 's') . ' not done. Open Boss > Inbox.');
            bd_log('punchout', (string)$emp, 'asked to punch out with ' . count($titles) . ' open', $by);
            json_response(['ok' => true, 'message' => 'Asked. ' . ($s['hr'] !== '' ? $s['hr'] . ' (HR)' : 'HR') . ' and the Admin are told. Call ' . ($s['hr'] !== '' ? $s['hr'] : 'HR') . ' if the answer is slow.', 'told' => $told, 'state' => dp_po_state($pdo, $emp)]);
        }
        }
    } catch (PDOException $e) {
        json_response(['error' => 'Database problem in punch out: ' . mb_substr($e->getMessage(), 0, 200)], 500);
    }
}
