<?php
/* New penalty with Claude (Boss > Penalties > "New penalty").
   Loaded by dept.php. PHP 7.4-safe. Endpoints (all dp.pen_*):
     dp.pen_setup      departments + seats + people + similar rules + recent flags
     dp.pen_draft      Claude reads what the boss typed and fills EVERY field with a suggestion and a short reason (also re-fills after "change it": {fix})
     dp.pen_dept_create  a new department (3 seats: boss, checker, team)  - Admin / HR
     dp.pen_create     makes the rule from the boss's confirmed draft (seats a person only when Admin/HR and the seat is empty)
     dp.pen_flag       a manager / the section checker flags "this was missed" for a person -> penalty by the usual rules (test mode = recorded only)
   A custom rule is "flagged by a person" (the HRMS has no data to detect it by itself); the screen says so before the boss confirms. */

function dp_pen_sections(PDO $pdo) {
    $o = [];
    foreach ($pdo->query("SELECT id, code, name, about FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) {
        $seats = [];
        $st = $pdo->prepare("SELECT code, title FROM bd_seats WHERE section_id = ? AND active = 1 AND code LIKE '%.%' AND code NOT LIKE '%.worker' ORDER BY id"); $st->execute([(int)$s['id']]);
        $teamSt = $pdo->prepare("SELECT code, title FROM bd_seats WHERE section_id = ? AND active = 1 AND code LIKE '%.worker' ORDER BY id"); $teamSt->execute([(int)$s['id']]);
        foreach (array_merge($teamSt->fetchAll(), $st->fetchAll()) as $x) {
            $h = dp_holders($pdo, $x['code']);
            $seats[] = ['code' => $x['code'], 'title' => $x['title'], 'holders' => array_map(function ($i) use ($pdo) { return ['id' => (int)$i, 'name' => bd_emp_name($pdo, $i)]; }, $h)];
        }
        if ($seats) $o[] = ['code' => $s['code'], 'name' => $s['name'], 'about' => (string)$s['about'], 'seats' => $seats];
    }
    return $o;
}
function dp_pen_valid_seat(PDO $pdo, $seat) { return $seat === 'self' || ($seat !== '' && bd_seat_id($pdo, $seat) > 0); }
/** the heuristic draft (also the fallback when Claude cannot be reached) */
function dp_pen_heuristic(PDO $pdo, $text, array $secs) {
    $t = mb_strtolower($text); $best = null; $bs = 0;
    foreach ($secs as $s) { $sc = 0; foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($s['name'] . ' ' . $s['about'])) as $w) { if (strlen($w) >= 4 && strpos($t, $w) !== false) $sc++; } if ($sc > $bs) { $bs = $sc; $best = $s; } }
    if (!$best && $secs) $best = $secs[0];
    $seat = ''; $why = '';
    if ($best) { $seat = $best['seats'][0]['code']; $why = $bs ? 'Your words match "' . $best['name'] . '".' : 'No department matched your words, so I started with "' . $best['name'] . '". Change it if it is wrong.'; }
    $amts = []; foreach ($pdo->query("SELECT amount FROM dp_rules WHERE amount > 0 ORDER BY amount")->fetchAll() as $r) $amts[] = (float)$r['amount'];
    $amt = $amts ? $amts[(int)floor(count($amts) / 2)] : 100;
    $title = mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 100); if ($title !== '') $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    return ['title' => $title, 'dept' => $best ? $best['code'] : '', 'seat' => $seat, 'amount' => $amt, 'per' => 'miss', 'mode' => $amt >= 200 ? 'approve' : 'auto', 'grace_min' => 0, 'when_text' => '',
        'why' => ['dept' => $why, 'seat' => 'The team seat does the work in most departments.', 'amount' => 'Your other rules are mostly around ' . dp_inr($amt) . '.', 'mode' => $amt >= 200 ? 'A bigger amount is better after your yes.' : 'A small amount can be taken automatically.']];
}
function dp_pen_ai_draft(PDO $pdo, $text, $fix, array $prev, array $secs) {
    if (!function_exists('convo_ai')) return null;
    $__brainScope = class_exists('BrainScope') ? new BrainScope('internal', 'penalties') : null;   // staff-only: what was taught about penalties applies; nothing here is for a customer
    $rules = []; foreach ($pdo->query("SELECT title, amount, per, mode, seat_code FROM dp_rules WHERE active = 1")->fetchAll() as $r) $rules[] = $r['title'] . ' - ' . dp_inr($r['amount']) . ' ' . $r['per'] . ' - ' . ($r['mode'] === 'auto' ? 'automatic' : 'after boss yes') . ' - seat ' . $r['seat_code'];
    $list = []; foreach ($secs as $s) foreach ($s['seats'] as $x) $list[] = $s['code'] . ' / ' . $x['code'] . ' (' . $s['name'] . ': ' . $x['title'] . ')';
    $sys = "You help the owner of a small Indian company write a staff penalty rule. Fill every field yourself; the owner only approves or changes. Pick dept_code and seat_code ONLY from the list given. "
        . "Amount in rupees: keep it fair and close to the similar rules; never above 500. per is 'miss' (each time) or 'day'. mode is 'auto' (small, clear-cut) or 'approve' (the boss says yes first). grace_min is minutes. when_text is a short phrase like 'by 6 PM every day'. "
        . "title: a short plain sentence, e.g. 'Dustbin not emptied by 6 PM'. Each why_* is ONE short plain sentence telling the owner why you chose it. Plain English, no jargon.";
    $ctx = "Departments and seats:\n" . implode("\n", $list) . "\n\nExisting rules:\n" . implode("\n", $rules) . "\n\nThe owner wrote: " . $text
        . ($prev ? "\n\nYour earlier draft: " . json_encode($prev, JSON_UNESCAPED_UNICODE) . "\nThe owner now asks to change it: " . $fix : '');
    $schema = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'dept_code' => ['type' => 'STRING'], 'seat_code' => ['type' => 'STRING'], 'amount' => ['type' => 'NUMBER'], 'per' => ['type' => 'STRING'], 'mode' => ['type' => 'STRING'],
        'grace_min' => ['type' => 'INTEGER'], 'when_text' => ['type' => 'STRING'], 'why_dept' => ['type' => 'STRING'], 'why_seat' => ['type' => 'STRING'], 'why_amount' => ['type' => 'STRING'], 'why_mode' => ['type' => 'STRING']],
        'required' => ['title', 'dept_code', 'seat_code', 'amount', 'per', 'mode']];
    try { $r = convo_ai($sys, [['text' => $ctx]], $schema, false, 700); } catch (Throwable $e) { return null; }
    if (!is_array($r) || empty($r[0]) || !is_array($r[1])) return null;
    return $r[1];
}
function dp_pen_clean_draft(PDO $pdo, array $d, array $base, array $secs) {
    $out = $base;
    $title = trim((string)($d['title'] ?? '')); if (mb_strlen($title) >= 3) $out['title'] = mb_substr($title, 0, 120);
    $dept = (string)($d['dept_code'] ?? ($d['dept'] ?? '')); $seat = (string)($d['seat_code'] ?? ($d['seat'] ?? ''));
    foreach ($secs as $s) { if ($s['code'] === $dept) { foreach ($s['seats'] as $x) { if ($x['code'] === $seat) { $out['dept'] = $dept; $out['seat'] = $seat; } } if ($out['dept'] !== $dept) { $out['dept'] = $dept; $out['seat'] = $s['seats'][0]['code']; } } }
    if (isset($d['amount']) && is_numeric($d['amount'])) $out['amount'] = max(0, min(500, round((float)$d['amount'])));
    if (in_array($d['per'] ?? '', ['miss', 'day'], true)) $out['per'] = $d['per'];
    if (in_array($d['mode'] ?? '', ['auto', 'approve'], true)) $out['mode'] = $d['mode'];
    if (isset($d['grace_min']) && is_numeric($d['grace_min'])) $out['grace_min'] = max(0, min(1440, (int)$d['grace_min']));
    if (isset($d['when_text'])) $out['when_text'] = mb_substr(trim((string)$d['when_text']), 0, 120);
    foreach (['dept', 'seat', 'amount', 'mode'] as $k) { $v = trim((string)($d['why_' . $k] ?? '')); if ($v !== '') $out['why'][$k] = mb_substr($v, 0, 200); }
    return $out;
}

/** which section a Boss penalty rule belongs to (the five Departments topics have a fixed section; your own rules follow the seat they charge). */
function dp_pen_rule_section(array $r) {
    $map = ['dispatch' => 'dispatch', 'money' => 'accounts', 'shops' => 'retaildist', 'supplies' => 'supplies', 'punch' => 'hr'];
    if (isset($map[$r['topic']])) return $map[$r['topic']];
    $c = (string)$r['seat_code']; if ($c !== '' && $c !== 'self' && strpos($c, '.') !== false) return substr($c, 0, strpos($c, '.'));
    return 'other';
}
/**
 * Penalties by section, for the Penalties page. The money comes from the one real list (employee_penalties: what employees are actually charged);
 * each row is put in a section by where it came from: a Boss duty (its section), a Departments rule (its topic), an HR rule (late punch-in and
 * short hours are "hr", the rest "other"), or given by hand ("other"). Rules, duties with a penalty, seats and the people charged come with it.
 */
function dp_pen_overview(PDO $pdo, $m1, $m2) {
    $secs = []; foreach ($pdo->query("SELECT id, code, name, about FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) $secs[$s['code']] = ['id' => (int)$s['id'], 'code' => $s['code'], 'name' => $s['name'], 'about' => (string)$s['about']];
    $seatsBy = []; foreach (dp_pen_sections($pdo) as $s) $seatsBy[$s['code']] = $s['seats'];
    $idToCode = []; foreach ($secs as $c => $s) $idToCode[$s['id']] = $c;
    $out = []; $blank = function ($code, $name, $about) use ($seatsBy) { return ['code' => $code, 'name' => $name, 'about' => $about, 'seats' => $seatsBy[$code] ?? [], 'rule_keys' => [], 'hr_rules' => [], 'duties' => [], 'duties_total' => 0,
        'month' => ['amount' => 0.0, 'count' => 0, 'waiting_amount' => 0.0, 'waiting_count' => 0, 'missed' => 0], 'people' => []]; };
    foreach ($secs as $c => $s) $out[$c] = $blank($c, $s['name'], $s['about']);
    $out['other'] = $blank('other', 'Given by hand / other', 'Penalties HR gave by hand, and rules that belong to no section.');
    $get = function ($c) use (&$out, $blank) { if (!isset($out[$c])) $c = 'other'; return $c; };
    // Boss rules (Departments topics + your own)
    $ruleSec = []; foreach ($pdo->query("SELECT * FROM dp_rules ORDER BY sort_no, id")->fetchAll() as $r) { $c = $get(dp_pen_rule_section($r)); $ruleSec[$r['rkey']] = $c; $out[$c]['rule_keys'][] = $r['rkey']; }
    // HR rules (read only here; edited in HR > Penalties)
    $hrSec = []; try { foreach ($pdo->query("SELECT rule_id, name, category, department, trigger_type, amount, active FROM penalty_rules ORDER BY rule_id")->fetchAll() as $r) {
        $c = in_array($r['trigger_type'], ['late_punch', 'short_hours'], true) ? 'hr' : 'other'; $c = $get($c); $hrSec[(int)$r['rule_id']] = $c;
        $out[$c]['hr_rules'][] = ['id' => (int)$r['rule_id'], 'name' => $r['name'], 'amount' => (float)$r['amount'], 'trigger' => $r['trigger_type'], 'active' => (int)$r['active']];
    } } catch (Throwable $e) { /* the HR penalty tables are created on first use */ }
    // duties that carry a penalty
    $tot = []; foreach ($pdo->query("SELECT section_id, COUNT(*) c FROM bd_duties WHERE status IN ('live','proposed') GROUP BY section_id")->fetchAll() as $r) { $c = $idToCode[(int)$r['section_id']] ?? null; if ($c) $out[$c]['duties_total'] = (int)$r['c']; }
    foreach ($pdo->query("SELECT d.id, d.section_id, d.title, d.pen_amount, d.pen_mode, d.freq, d.due_time, d.status, d.seat_id FROM bd_duties d WHERE d.status IN ('live','proposed') AND d.pen_amount > 0 ORDER BY d.section_id, d.title")->fetchAll() as $d) {
        $c = $idToCode[(int)$d['section_id']] ?? null; if (!$c) continue;
        $seat = (int)$d['seat_id'] ?: bd_section_seat($pdo, (int)$d['section_id'], 'worker'); $h = $seat ? bd_holders($pdo, $seat) : [];
        $out[$c]['duties'][] = ['id' => (int)$d['id'], 'title' => $d['title'], 'amount' => (float)$d['pen_amount'], 'mode' => $d['pen_mode'], 'freq' => $d['freq'], 'due' => $d['due_time'], 'status' => $d['status'],
            'holders' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $h)];
    }
    // missed duties this month
    $st = $pdo->prepare("SELECT section_id, COUNT(*) c FROM bd_tasks WHERE status = 'missed' AND task_date BETWEEN ? AND ? GROUP BY section_id"); $st->execute([$m1, $m2]);
    foreach ($st->fetchAll() as $r) { $c = $idToCode[(int)$r['section_id']] ?? null; if ($c) $out[$c]['month']['missed'] = (int)$r['c']; }
    // charged this month: every applied penalty, put in a section by where it came from
    $bdSec = []; $dpRule = [];
    if (function_exists('ensure_penalty_tables')) { try { ensure_penalty_tables($pdo); } catch (Throwable $e) { /* below */ } }
    $rows = []; try { $st = $pdo->prepare("SELECT p.penalty_id, p.employee_id, p.amount, p.rule_id, p.auto_key, p.source FROM employee_penalties p WHERE p.status = 'applied' AND p.penalty_date BETWEEN ? AND ?"); $st->execute([$m1, $m2]); $rows = $st->fetchAll(); } catch (Throwable $e) { /* no HR penalty table yet: nothing charged */ }
    $bdIds = []; $dpIds = []; foreach ($rows as $r) { $k = (string)$r['auto_key']; if (strpos($k, 'bd:') === 0) $bdIds[] = (int)substr($k, 3); elseif (strpos($k, 'dp:') === 0) $dpIds[] = (int)substr($k, 3); }
    if ($bdIds) foreach ($pdo->query("SELECT id, section_id FROM bd_tasks WHERE id IN (" . implode(',', array_unique($bdIds)) . ")")->fetchAll() as $r) $bdSec[(int)$r['id']] = $idToCode[(int)$r['section_id']] ?? 'other';
    if ($dpIds) foreach ($pdo->query("SELECT id, rkey FROM dp_items WHERE id IN (" . implode(',', array_unique($dpIds)) . ")")->fetchAll() as $r) $dpRule[(int)$r['id']] = $ruleSec[$r['rkey']] ?? 'other';
    $people = [];
    foreach ($rows as $r) {
        $k = (string)$r['auto_key'];
        if (strpos($k, 'bd:') === 0) $c = $bdSec[(int)substr($k, 3)] ?? 'other'; elseif (strpos($k, 'dp:') === 0) $c = $dpRule[(int)substr($k, 3)] ?? 'other'; elseif ($r['rule_id'] && isset($hrSec[(int)$r['rule_id']])) $c = $hrSec[(int)$r['rule_id']]; else $c = 'other';
        $c = $get($c); $amt = (float)$r['amount']; $out[$c]['month']['amount'] += $amt; $out[$c]['month']['count']++;
        $e = (int)$r['employee_id']; $people[$c][$e] = ($people[$c][$e] ?? ['amount' => 0.0, 'count' => 0]); $people[$c][$e]['amount'] += $amt; $people[$c][$e]['count']++;
    }
    // recorded but not charged yet (test mode / waiting for your yes)
    $st = $pdo->prepare("SELECT section_id, COUNT(*) c, COALESCE(SUM(pen_amount),0) a FROM bd_tasks WHERE pen_state IN ('pending','shadow') AND task_date BETWEEN ? AND ? GROUP BY section_id"); $st->execute([$m1, $m2]);
    foreach ($st->fetchAll() as $r) { $c = $get($idToCode[(int)$r['section_id']] ?? 'other'); $out[$c]['month']['waiting_count'] += (int)$r['c']; $out[$c]['month']['waiting_amount'] += (float)$r['a']; }
    $st = $pdo->prepare("SELECT rkey, COUNT(*) c, COALESCE(SUM(pen_amount),0) a FROM dp_items WHERE pen_state IN ('pending','shadow') AND task_date BETWEEN ? AND ? GROUP BY rkey"); $st->execute([$m1, $m2]);
    foreach ($st->fetchAll() as $r) { $c = $get($ruleSec[$r['rkey']] ?? 'other'); $out[$c]['month']['waiting_count'] += (int)$r['c']; $out[$c]['month']['waiting_amount'] += (float)$r['a']; }
    foreach ($people as $c => $list) { uasort($list, function ($a, $b) { return $b['amount'] <=> $a['amount']; }); $n = 0; foreach ($list as $e => $v) { if (++$n > 8) break; $out[$c]['people'][] = ['id' => $e, 'name' => bd_emp_name($pdo, $e), 'amount' => round($v['amount'], 2), 'count' => $v['count']]; } }
    foreach ($out as $c => &$o) { $o['month']['amount'] = round($o['month']['amount'], 2); $o['month']['waiting_amount'] = round($o['month']['waiting_amount'], 2); } unset($o);
    // 'other' only when it has something to show
    $x = $out['other']; if (!$x['rule_keys'] && !$x['hr_rules'] && !$x['month']['count'] && !$x['month']['waiting_count']) unset($out['other']);
    return array_values($out);
}

if (strpos((string)$action, 'dp.pen_') === 0) {
    try {
        dp_ensure($pdo);
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $auth = require_auth(); $w = bd_who($pdo, $auth); $by = (string)($auth['username'] ?? 'user'); $in = $isPost ? json_input() : [];
        switch ($action) {
        case 'dp.pen_setup': {
            bd_need_manager($pdo, $auth);
            $rules = []; foreach ($pdo->query("SELECT rkey, title, amount, per, mode, seat_code, topic, active FROM dp_rules ORDER BY sort_no, id")->fetchAll() as $r) $rules[] = ['rkey' => $r['rkey'], 'title' => $r['title'], 'amount' => (float)$r['amount'], 'per' => $r['per'], 'mode' => $r['mode'], 'seat' => $r['seat_code'], 'custom' => $r['topic'] === 'custom', 'active' => (int)$r['active']];
            $rec = []; foreach ($pdo->query("SELECT * FROM dp_items WHERE kind = 'custom' ORDER BY id DESC LIMIT 15")->fetchAll() as $r) $rec[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'who' => $r['owner_emp'] ? bd_emp_name($pdo, $r['owner_emp']) : '', 'date' => $r['task_date'], 'detail' => $r['detail'], 'pen_state' => $r['pen_state'], 'pen_amount' => (float)$r['pen_amount']];
            json_response(['sections' => dp_pen_sections($pdo), 'employees' => bd_employees($pdo), 'rules' => $rules, 'recent' => $rec, 'mode' => bd_mode(), 'admin' => in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true), 'ai' => function_exists('convo_ai')]);
        }
        case 'dp.pen_overview': {
            bd_need_manager($pdo, $auth);
            list($m1, $m2) = dp_month_range((string)($_GET['month'] ?? ''));
            json_response(['month' => $m1, 'sections' => dp_pen_overview($pdo, $m1, $m2), 'mode' => bd_mode()]);
        }
        case 'dp.pen_draft': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            bd_need_manager($pdo, $auth);
            $text = trim((string)($in['text'] ?? '')); if (mb_strlen($text) < 4) json_response(['error' => 'Write what the penalty is for, in a few words.'], 422);
            $fix = trim((string)($in['fix'] ?? '')); $prev = is_array($in['draft'] ?? null) ? $in['draft'] : [];
            $secs = dp_pen_sections($pdo); if (!$secs) json_response(['error' => 'There are no departments yet.'], 409);
            $base = $prev && $fix !== '' ? dp_pen_clean_draft($pdo, $prev, dp_pen_heuristic($pdo, $text, $secs), $secs) : dp_pen_heuristic($pdo, $text, $secs);
            $ai = dp_pen_ai_draft($pdo, mb_substr($text, 0, 600), mb_substr($fix, 0, 300), $prev, $secs);
            $draft = $ai ? dp_pen_clean_draft($pdo, $ai, $base, $secs) : $base;
            // who should sit there: only when the seat is empty - from the track record (Claude suggests, never assigns)
            $holders = $draft['seat'] ? dp_holders($pdo, $draft['seat']) : [];
            $sug = []; if ($draft['seat'] && !$holders) foreach (dp_suggest($pdo, $draft['seat'], 3) as $c) $sug[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'line' => $c['line']];
            $draft['holders'] = array_map(function ($i) use ($pdo) { return ['id' => (int)$i, 'name' => bd_emp_name($pdo, $i)]; }, $holders);
            $draft['suggest'] = $sug;
            json_response(['draft' => $draft, 'by_claude' => (bool)$ai]);
        }
        case 'dp.pen_dept_create': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            bd_need_admin($auth);
            $name = trim((string)($in['name'] ?? '')); $about = trim((string)($in['about'] ?? ''));
            if (mb_strlen($name) < 3 || mb_strlen($name) > 60) json_response(['error' => 'Give the department a name (3 to 60 letters).'], 422);
            $st = $pdo->prepare("SELECT id FROM bd_sections WHERE LOWER(name) = LOWER(?)"); $st->execute([$name]); if ($st->fetchColumn()) json_response(['error' => 'A department with this name already exists. Pick it from the list.'], 409);
            $slug = 'c' . substr(preg_replace('/[^a-z0-9]+/', '', strtolower($name)), 0, 12); $code = $slug; $n = 1;
            while (true) { $q = $pdo->prepare("SELECT id FROM bd_sections WHERE code = ?"); $q->execute([$code]); if (!$q->fetchColumn()) break; $n++; $code = $slug . $n; }
            $pdo->prepare("INSERT INTO bd_sections (code, name, about, pages, source, state, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$code, $name, mb_substr($about, 0, 250), '[]', 'custom', 'ready', bd_now()]);
            $sid = (int)$pdo->lastInsertId(); bd_make_seats($pdo, $sid, $code, $name); bd_log('section', $code, 'new department "' . $name . '" with 3 seats', $by);
            json_response(['ok' => true, 'sections' => dp_pen_sections($pdo), 'code' => $code]);
        }
        case 'dp.pen_create': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            bd_need_manager($pdo, $auth);
            $title = trim((string)($in['title'] ?? '')); if (mb_strlen($title) < 3 || mb_strlen($title) > 120) json_response(['error' => 'The penalty needs a short name.'], 422);
            $seat = (string)($in['seat'] ?? ''); if (!dp_pen_valid_seat($pdo, $seat)) json_response(['error' => 'Choose a seat.'], 422);
            $amt = (float)($in['amount'] ?? 0); if ($amt < 0 || $amt > (float)bd_cfg('pen_cap_month')) json_response(['error' => 'The amount must be between 0 and the monthly limit (' . dp_inr(bd_cfg('pen_cap_month')) . ').'], 422);
            $per = (string)($in['per'] ?? 'miss'); if (!in_array($per, ['miss', 'day'], true)) $per = 'miss';
            $mode = (string)($in['mode'] ?? 'approve'); if (!in_array($mode, ['auto', 'approve'], true)) $mode = 'approve';
            $grace = max(0, min(1440, (int)($in['grace_min'] ?? 0))); $when = mb_substr(trim((string)($in['when_text'] ?? '')), 0, 120);
            $st = $pdo->prepare("SELECT id FROM dp_rules WHERE LOWER(title) = LOWER(?)"); $st->execute([$title]); if ($st->fetchColumn()) json_response(['error' => 'A penalty with this name already exists.'], 409);
            $seated = '';
            $pid = (int)($in['person_id'] ?? 0);
            if ($pid && $seat !== 'self' && !dp_holders($pdo, $seat)) {
                if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) json_response(['error' => 'Only Admin or HR can seat a person. Create the penalty without choosing a person, or ask Admin.'], 403);
                if (!bd_emp_active(bd_emp($pdo, $pid))) json_response(['error' => 'That person is not active.'], 422);
                $sid = bd_seat_id($pdo, $seat); bd_set_holders($pdo, $sid, [$pid]); bd_log('seat', $seat, 'holders: ' . $pid, $by); bd_push($pdo, $pid, 'You now hold a seat', bd_seat_row($pdo, $sid)['title'] . '. Its work and penalties show in My Day.'); $seated = bd_emp_name($pdo, $pid);
            }
            $rkey = 'c_' . substr(md5($title . microtime(true)), 0, 10);
            $pdo->prepare("INSERT INTO dp_rules (rkey, title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, active, sort_no, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)")
                ->execute([$rkey, $title, 'custom', $amt, $per, $amt > 0 ? 0 : 1, $seat, $when !== '' ? $when : 'Flagged by a boss or checker', $mode, $grace, 100 + (int)$pdo->query("SELECT COUNT(*) FROM dp_rules")->fetchColumn(), bd_now()]);
            bd_log('rule', $rkey, 'new custom penalty "' . $title . '" ' . dp_inr($amt) . ' ' . $per . ', ' . $mode . ', seat ' . $seat, $by);
            json_response(['ok' => true, 'rkey' => $rkey, 'seated' => $seated, 'mode' => bd_mode()]);
        }
        case 'dp.pen_flag': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = dp_rule($pdo, (string)($in['rkey'] ?? '')); if (!$r || $r['topic'] !== 'custom') json_response(['error' => 'Choose one of your own penalties.'], 404);
            if (empty($w['manager']) && !dp_can_seat($pdo, $auth, (string)$r['seat_code'])) json_response(['error' => 'Only a boss or the checker of this department can flag a miss.'], 403);
            if ((int)$r['active'] !== 1) json_response(['error' => 'This penalty is switched off.'], 409);
            $emp = (int)($in['emp_id'] ?? 0); if (!$emp && $r['seat_code'] !== 'self') $emp = (int)dp_owner_for($pdo, (string)$r['seat_code']);
            if (!$emp) json_response(['error' => 'Choose the person who missed it (nobody sits in this seat).'], 422);
            if (!bd_emp_active(bd_emp($pdo, $emp))) json_response(['error' => 'That person is not active.'], 422);
            if ($emp === (int)($w['emp'] ?? 0) && empty($w['admin'])) json_response(['error' => 'You cannot flag yourself.'], 403);
            $date = (string)($in['date'] ?? bd_today()); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > bd_today() || $date < date('Y-m-d', strtotime('-45 days'))) json_response(['error' => 'The date must be within the last 45 days.'], 422);
            $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300); if (mb_strlen($note) < 3) json_response(['error' => 'Write a short note saying what was missed. The person sees it.'], 422);
            $count = max(1, min(20, (int)($in['count'] ?? 1)));
            $ref = 'cust:' . $r['rkey'] . ':' . $emp . ':' . $date . ':' . substr(md5(microtime(true) . mt_rand()), 0, 6);
            $id = dp_item_make($pdo, 'custom', $r['rkey'], $ref, (string)$r['seat_code'], $r['title'], $note, $date, null, ['flagged_by' => $by], $emp, 1);
            dp_miss($pdo, $id, $r['rkey'], $count, 'flagged by ' . $by);
            $it = dp_item($pdo, $id);
            json_response(['ok' => true, 'pen_state' => $it['pen_state'], 'amount' => (float)$it['pen_amount'], 'who' => bd_emp_name($pdo, $emp), 'mode' => bd_mode()]);
        }
        default: json_response(['error' => 'Unknown action: ' . $action], 404);
        }
    } catch (PDOException $e) {
        json_response(['error' => 'Database problem in New penalty: ' . mb_substr($e->getMessage(), 0, 200)], 500);
    }
}
