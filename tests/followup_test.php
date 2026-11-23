<?php
// Follow-up (empmanagment/followup.php): who sees what, one update a day, the automatic number, the checks. Runs on SQLite with small stand-ins for api.php.
class JrOut extends Exception { public $data; public $http; function __construct($d, $c) { parent::__construct('jr'); $this->data = $d; $this->http = $c; } }
function json_response($d, int $c = 200): void { throw new JrOut($d, $c); }
function require_auth(): array { return $GLOBALS['AUTH']; }
function json_input(): array { return $GLOBALS['IN']; }
function setting_get($pdo, $k, $d = null) { return $GLOBALS['SET'][$k] ?? $d; }
function setting_set($pdo, $k, $v): void { $GLOBALS['SET'][$k] = $v; }
function biz_today($pdo): string { return '2026-11-23'; }
function brain_ai_on() { return true; }
// stand-in for Claude's check of one update: "ok" alone is vague (asks one question), "same" = no progress, anything else is clear
function brain_ai_json($s, $a, $sys, $user, $max = 300, $o = []) { $GLOBALS['AI_CHK'] = $user; $GLOBALS['AI_N'] = ($GLOBALS['AI_N'] ?? 0) + 1; preg_match('/THE UPDATE TO CHECK: "(.*?)"/s', $user, $m); $t = trim($m[1] ?? '');
    if ($t === 'ok') return ['verdict' => 'vague', 'note' => 'Says only ok.', 'question' => 'How many shops, and which?']; if ($t === 'same') return ['verdict' => 'no_progress', 'note' => 'Nothing new since yesterday.', 'question' => 'x']; return ['verdict' => 'clear', 'note' => 'Concrete.', 'question' => '']; }
// stand-ins for the online sales day (Live sales rows), the ad spend boxes and the commission engine
function cp_day_rows($pdo, $day) { return $day === '2026-11-22' ? [1 => ['off' => 0, 'n' => 200, 's' => 500000, 'q' => 500, 'qo' => 100, 'co' => 100, 'pn' => 170], 2 => ['off' => 0, 'n' => 175, 's' => 441000, 'q' => 441, 'qo' => 80, 'co' => 95, 'pn' => 156], 3 => ['off' => 1, 'n' => 9, 's' => 9999, 'q' => 9, 'qo' => 9, 'co' => 0, 'pn' => 0]] : ($day === '2026-11-23' ? [1 => ['off' => 0, 'n' => 10, 's' => 20000, 'q' => 40, 'qo' => 6, 'co' => 4, 'pn' => 5]] : []); }
function roas_day_spend($pdo, $day) { return $day === '2026-11-22' ? 61000.0 : null; }
function cm_engine_load($pdo) { return true; } function cm_paid_day($pdo, $day) { return ['emps' => [1 => ['total' => 30000.5], 2 => ['total' => 25000]], 'ord' => []]; }
function push_send_many($pdo, $ids, $payload) { $GLOBALS['PUSH'] = [$ids, $payload]; return count($ids); }
function brain_ai_text($s, $a, $sys, $user, $max = 700, $o = []) { $GLOBALS['AI_SAW'] = $user; return "MOVING\nSales went up."; }
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE fu_sections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, owner_emp INT DEFAULT 0, owner_dept INT DEFAULT 0, sort_no INT DEFAULT 0, active INT DEFAULT 1, created_at TEXT)");
$pdo->exec("CREATE TABLE fu_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, section_id INT, title TEXT, kind TEXT DEFAULT 'progress', due_date TEXT, assignee_emp INT DEFAULT 0, source TEXT DEFAULT '', note TEXT DEFAULT '', unit TEXT DEFAULT '', lag INT DEFAULT 0, metrics TEXT DEFAULT '', need_text INT DEFAULT 0, fields TEXT, status TEXT DEFAULT 'open', done_at TEXT, sort_no INT DEFAULT 0, active INT DEFAULT 1, created_by INT DEFAULT 0, created_at TEXT)");
$pdo->exec("CREATE TABLE fu_updates (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, day TEXT, txt TEXT, val REAL, pct INT, auto INT DEFAULT 0, emp_id INT DEFAULT 0, emp_name TEXT DEFAULT '', updated_at TEXT, ai_verdict TEXT DEFAULT '', ai_note TEXT DEFAULT '', ai_q TEXT DEFAULT '', vals TEXT, fvals TEXT, UNIQUE (topic_id, day))");
$pdo->exec("CREATE TABLE fu_digest (section_id INT, day TEXT, txt TEXT, made_at TEXT, PRIMARY KEY (section_id, day))");
$pdo->exec("CREATE TABLE employees (employee_id INTEGER PRIMARY KEY, full_name TEXT, department_id INT, status TEXT, designation TEXT, mobile TEXT)");
$pdo->exec("CREATE TABLE departments (department_id INTEGER PRIMARY KEY, department_name TEXT)");
$pdo->exec("CREATE TABLE st_bills (bill_id INTEGER PRIMARY KEY, biz_date TEXT, total REAL, status TEXT)");
$pdo->exec("INSERT INTO departments VALUES (1, 'Store'), (2, 'Wholesale')");
$pdo->exec("INSERT INTO employees VALUES (10, 'Asha', 1, 'approved', 'Store Manager', '+91 98765-43210'), (11, 'Bharat', 1, 'approved', '', ''), (12, 'Chirag', 2, 'approved', 'Wholesale Head', '9123456780'), (13, 'Old', 2, 'ex', '', '')");
$pdo->exec("INSERT INTO st_bills VALUES (1, '2026-11-23', 1500, 'ok'), (2, '2026-11-23', 500, 'ok'), (3, '2026-11-23', 900, 'void')");
$ADMIN = ['user_id' => 1, 'employee_id' => null, 'username' => 'boss', 'role' => 'Admin']; $E = function ($id) { return ['user_id' => $id, 'employee_id' => $id, 'username' => 'u' . $id, 'role' => 'Employee']; };
function call($act, $auth, $in = null, $get = []) { global $pdo; $GLOBALS['AUTH'] = $auth; $GLOBALS['IN'] = $in ?? []; $_GET = $get; $_SERVER['REQUEST_METHOD'] = $in === null ? 'GET' : 'POST'; $action = $act;
    try { require __DIR__ . '/../empmanagment/followup.php'; } catch (JrOut $o) { return [$o->http, json_decode(json_encode($o->data), true)]; } return [0, null]; }
$n = 0; $bad = 0; function ok($c, $m) { global $n, $bad; $n++; if (!$c) { $bad++; echo "FAIL: $m\n"; } }

[$c, $b] = call('fup.board', $ADMIN); ok($c === 200 && count($b['sections']) === 2 && $b['sections'][0]['name'] === 'Shop Wholesale' && $b['sections'][1]['name'] === 'Surat Store', 'the two sections are made once');
$ws = $b['sections'][0]['id']; $st = $b['sections'][1]['id'];
[$c] = call('fup.topic_save', $E(10), ['section_id' => $st, 'title' => 'x']); ok($c === 403, 'staff cannot add a topic');
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Sales', 'kind' => 'progress', 'assignee_emp' => 10, 'source' => 'store_sales']); ok($c === 200, 'topic saved'); $tSales = $r['id'];
[$c] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'New display', 'kind' => 'deadline']); ok($c === 422, 'a deadline topic needs a date');
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'New display', 'kind' => 'deadline', 'due_date' => '2026-11-24', 'assignee_emp' => 11]); $tDisp = $r['id'];
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $ws, 'title' => 'New perfumes', 'assignee_emp' => 0]); $tPerf = $r['id'];

[$c, $b] = call('fup.board', $E(10)); ok(count($b['sections']) === 1 && count($b['topics']) === 1 && $b['topics'][0]['id'] === $tSales && $b['topics'][0]['can_write'], 'a person sees only his own topic');
ok(abs($b['topics'][0]['updates']['2026-11-23']['val'] - 2000) < 0.01 && $b['topics'][0]['updates']['2026-11-23']['auto'] === true, 'the store sales number comes by itself (void bill left out)');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tDisp, 'text' => 'hi']); ok($c === 403, 'cannot write on another person\'s topic');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tSales, 'text' => '']); ok($c === 422, 'an empty update is refused');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tSales, 'text' => 'Good day, 2 big bills']); ok($c === 200, 'own update saved');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tSales, 'text' => 'Good day, 3 big bills']); ok($c === 200 && (int)$pdo->query("SELECT COUNT(*) FROM fu_updates WHERE topic_id = $tSales AND day = '2026-11-23'")->fetchColumn() === 1, 'one row a day (the second save changes it)');
ok(abs((float)$pdo->query("SELECT val FROM fu_updates WHERE topic_id = $tSales AND day = '2026-11-23'")->fetchColumn() - 2000) < 0.01, 'the words do not wipe the automatic number');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tSales, 'text' => 'x', 'day' => '2026-11-20']); ok($c === 422, 'staff cannot change an old day');
[$c] = call('fup.update_save', $E(10), ['topic_id' => $tSales, 'text' => 'x', 'day' => '2026-11-25']); ok($c === 422, 'no update for a future day');
[$c, $m] = call('fup.mine', $E(10)); ok($m['due'] === 0 && count($m['topics']) === 1 && $m['topics'][0]['done_today'], 'My Day: done for today');
[$c, $m] = call('fup.mine', $E(11)); ok($m['due'] === 1 && $m['topics'][0]['days_left'] === 1, 'My Day: one topic waiting, deadline tomorrow');
[$c, $g] = call('fup.badge', $E(12)); ok($g['show'] === false, 'nothing given: no Follow-up in the menu');

[$c] = call('fup.section_save', $ADMIN, ['id' => $ws, 'name' => 'Shop Wholesale', 'owner_dept' => 2]); ok($c === 200, 'section given to a department');
[$c, $g] = call('fup.badge', $E(12)); ok($g['show'] === true && $g['due'] === 1, 'department owner sees the section and its open topic');
[$c, $b] = call('fup.board', $E(12)); ok(count($b['topics']) === 1 && $b['topics'][0]['can_write'] && $b['sections'][0]['own'], 'department owner can write every topic of the section');
[$c] = call('fup.update_save', $E(12), ['topic_id' => $tPerf, 'text' => '4 new perfumes added', 'value' => 4, 'pct' => 40]); ok($c === 200, 'owner writes with a number and %');
[$c] = call('fup.update_save', $E(12), ['topic_id' => $tSales, 'text' => 'x']); ok($c === 403, 'the owner of one section cannot write in another');

[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $txt = implode(' | ', array_column($b['checks'], 'text'));
ok(strpos($txt, 'New display: deadline in 1 day') !== false && strpos($txt, 'New display: no update for today yet (Bharat)') !== false && strpos($txt, 'Sales:') === false, 'checks: deadline close + no update today; the updated topic is clean');
$pdo->exec("INSERT INTO fu_updates (topic_id, day, txt) VALUES ($tSales, '2026-11-22', 'good day, 3 big  bills')");
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'same words as the day before') !== false, 'checks: copied update is flagged');
[$c] = call('fup.claude', $E(10), ['section_id' => $st]); ok($c === 403, 'a topic person cannot ask Claude for the whole section');
[$c, $r] = call('fup.claude', $ADMIN, ['section_id' => $st]); ok($c === 200 && strpos($r['digest']['text'], 'MOVING') === 0 && strpos($GLOBALS['AI_SAW'], 'TOPIC: Sales') !== false && strpos($GLOBALS['AI_SAW'], 'Sales of the day (₹) 2000 (from the system)') !== false, 'Claude gets the sheet and the note is saved');
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok($b['digest'] && $b['digest']['day'] === '2026-11-23', 'the note is shown again');
[$c] = call('fup.topic_done', $ADMIN, ['id' => $tDisp, 'done' => 1]); [$c, $m] = call('fup.mine', $E(11)); ok(count($m['topics']) === 0, 'a finished topic leaves My Day');
[$c] = call('fup.topic_del', $ADMIN, ['id' => $tSales]); ok((int)$pdo->query("SELECT COUNT(*) FROM fu_updates WHERE topic_id = $tSales")->fetchColumn() === 2, 'removing a topic keeps its updates');
[$c, $p] = call('fup.people', $ADMIN); ok(count($p['people']) === 3 && count($p['depts']) === 2, 'people list leaves out ex-employees');
[$c, $b] = call('fup.board', $ADMIN); $g = array_count_values(array_column($b['sources'], 'group')); ok(($g['Shop wholesale'] ?? 0) >= 10 && ($g['Surat store'] ?? 0) >= 3 && $b['sources'][0]['page'] !== '', 'numbers are grouped by the part of the HRMS they connect to');
foreach (fup_sources() as $k => $s) ok(count($s) === 6 && is_callable($s[2]), "source $k is complete");
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Bills', 'source' => 'store_bills']); [$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]);
ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Bills:') === false, 'a connected topic whose number came by itself needs no typing and no person');
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $ws, 'title' => 'Board fitting', 'kind' => 'deadline', 'due_date' => '2026-11-20', 'assignee_emp' => 12]);
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $ov = $b['overview'];
ok(count($ov['late']) === 1 && $ov['late'][0]['topic'] === 'Board fitting' && $ov['late'][0]['days'] === 3 && $ov['late'][0]['section'] === 'Shop Wholesale' && $ov['late'][0]['who'] === 'Chirag', 'top of the page: a missed deadline of ANOTHER section is listed with its person and days');
ok(count(array_filter($ov['empty'], function ($x) { return $x['topic'] === 'Board fitting'; })) === 1 && ($ov['by_section'][$ws] ?? 0) >= 2, 'top of the page: not filled today is listed, and the section shows its count');
[$c, $b] = call('fup.board', $E(10)); ok(count($b['overview']['late']) === 0 && count($b['overview']['empty']) === 0, 'a person with only his own topic does not get the company list');
// ---- Claude checks each update
$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, role TEXT, employee_id INT)"); $pdo->exec("INSERT INTO users VALUES (1, 'Admin', 10), (2, 'Employee', 12)");
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $ws, 'title' => 'Ad spend', 'assignee_emp' => 12, 'unit' => '₹', 'note' => 'Type the Meta ad spend of the day and which campaign.']); $tAd = $r['id'];
[$c, $r] = call('fup.update_save', $E(12), ['topic_id' => $tAd, 'text' => 'ok']); ok($c === 200 && $r['ai']['verdict'] === 'vague' && $r['ai']['question'] !== '', 'a vague update gets one question from Claude');
ok(strpos($GLOBALS['AI_CHK'], 'WHAT THE OWNER WANTS IN THIS UPDATE: Type the Meta ad spend') !== false, 'Claude is given what the owner wrote about the topic');
[$c, $m] = call('fup.mine', $E(12)); $row = array_values(array_filter($m['topics'], function ($x) use ($tAd) { return $x['id'] === $tAd; }))[0]; ok($row['done_today'] === false && $row['today']['q'] !== '' && $row['unit'] === '₹', 'the day is not counted as filled until the question is answered');
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $ws]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Ad spend: the update is not clear') !== false, 'the owner sees the unanswered question');
[$c, $r] = call('fup.update_save', $E(12), ['topic_id' => $tAd, 'text' => "ok\n3 shops: A, B, C", 'value' => 4200, 'final' => 1]); ok($r['ai']['verdict'] === 'clear' && $r['ai']['question'] === '', 'the answer is checked once more and no second question is asked');
[$c, $m] = call('fup.mine', $E(12)); $row = array_values(array_filter($m['topics'], function ($x) use ($tAd) { return $x['id'] === $tAd; }))[0]; ok($row['done_today'] === true && abs($row['today']['val'] - 4200) < 0.01, 'answered: filled, with the typed number');
$n0 = $GLOBALS['AI_N']; [$c, $r] = call('fup.update_save', $E(12), ['topic_id' => $tAd, 'text' => "ok\n3 shops: A, B, C", 'pct' => 50]); ok($GLOBALS['AI_N'] === $n0 && $r['ai'] === null, 'the same words are not sent to Claude again');
[$c, $r] = call('fup.update_save', $ADMIN, ['topic_id' => $tAd, 'text' => 'ok', 'day' => '2026-11-10', 'value' => 3900]); ok($c === 200 && $r['ai']['question'] === '', 'the Admin can fill any earlier date, and is never asked a question');
[$c, $r] = call('fup.update_save', $E(12), ['topic_id' => $tAd, 'text' => 'same']); [$c, $b] = call('fup.board', $ADMIN, null, ['section' => $ws]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Ad spend: Claude — Nothing new since yesterday.') !== false, 'Claude\'s remark shows in the checks');
// ---- a topic about the day before ("yesterday's sale"), filled the next day
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Kal no sale', 'assignee_emp' => 11, 'lag' => 1, 'note' => 'sales, query, ads spend']); $tKal = $r['id'];
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Kal no sale: no update for yesterday yet') !== false && strpos(implode(' | ', array_column($b['checks'], 'text')), 'Kal no sale: no update for 2') === false, 'a new next-day topic asks for yesterday at once, and never for the days before that');
$pdo->exec("UPDATE fu_topics SET created_at = '2026-11-15 10:00:00' WHERE id = $tKal");
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Kal no sale: no update for 8 days') !== false, 'an older next-day topic is checked on YESTERDAY\'s row');
[$c] = call('fup.update_save', $E(11), ['topic_id' => $tKal, 'text' => 'Sales 9,41,000. Query 941. Ads spend 61,000.', 'day' => '2026-11-22', 'value' => 941000]);
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Kal no sale') === false, 'filled for yesterday: nothing is flagged although today\'s row is empty');
[$c, $m] = call('fup.mine', $E(11)); $row = array_values(array_filter($m['topics'], function ($x) use ($tKal) { return $x['id'] === $tKal; }))[0]; ok($row['done_today'] && $row['day'] === '2026-11-22' && $row['lag'], 'My Day asks for yesterday\'s row of a next-day topic');
[$c] = call('fup.claude', $ADMIN, ['section_id' => $st]); ok(strpos($GLOBALS['AI_SAW'], 'filled the next day: the newest row that is due is 2026-11-22') !== false && strpos($GLOBALS['AI_SAW'], '2026-11-23: (not due yet)') !== false && strpos($GLOBALS['AI_SAW'], 'started on: 2026-11-14') !== false, 'Claude is told when the topic started and that today is not due yet');
$pdo->exec("UPDATE fu_topics SET created_at = '2026-11-22 10:00:00' WHERE id = $tKal"); [$c] = call('fup.claude', $ADMIN, ['section_id' => $st]); ok(strpos($GLOBALS['AI_SAW'], '2026-11-20: (the topic did not exist yet)') !== false, 'days before the topic started are not shown to Claude as missing');
// ---- one topic, many numbers from the system (day-wise online sale); the person writes only the summary
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Day wise sale', 'assignee_emp' => 11, 'lag' => 1, 'need_text' => 1, 'metrics' => ['on_sales', 'on_queries', 'on_orders', 'on_ticket', 'on_corders', 'on_qorders', 'on_prepaid', 'on_adspend', 'on_roas', 'on_commission', 'nonsense']]); $tDay = $r['id'];
$pdo->exec("UPDATE fu_topics SET created_at = '2026-11-15 10:00:00' WHERE id = $tDay");
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $tp = array_values(array_filter($b['topics'], function ($x) use ($tDay) { return $x['id'] === $tDay; }))[0]; $u = $tp['updates']['2026-11-22'];
ok($tp['source'] === 'on_sales' && count($tp['metrics']) === 10 && $tp['need_text'] === true, 'the ticked numbers are kept (unknown ones dropped), the first is the main one');
ok(abs($u['val'] - 941000) < 0.01 && $u['auto'] && $u['vals']['on_queries'] == 941 && $u['vals']['on_orders'] == 375 && $u['vals']['on_qorders'] == 180 && $u['vals']['on_corders'] == 195 && $u['vals']['on_ticket'] == 2509 && abs($u['vals']['on_prepaid'] - 86.9) < 0.01, 'online numbers of the day come by themselves (people marked off are left out)');
ok($u['vals']['on_adspend'] == 61000 && abs($u['vals']['on_roas'] - 15.43) < 0.01 && abs($u['vals']['on_commission'] - 55000.5) < 0.01, 'ad spend, ROAS and commission come from their own places');
ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Day wise sale: no update for') !== false, 'numbers alone are not enough when the topic asks for a summary');
[$c, $r] = call('fup.update_save', $E(11), ['topic_id' => $tDay, 'day' => '2026-11-22', 'text' => 'Sales up because the Sunday campaign went to 12,000 people; ad spend kept the same.']);
ok(strpos($GLOBALS['AI_CHK'], 'NUMBERS FROM THE SYSTEM FOR THIS DAY') !== false && strpos($GLOBALS['AI_CHK'], 'Ad spend (₹) 61000') !== false, 'Claude checks the summary against the real numbers');
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Day wise sale') === false, 'summary written: the topic is clean');
[$c, $m] = call('fup.mine', $E(11)); $row = array_values(array_filter($m['topics'], function ($x) use ($tDay) { return $x['id'] === $tDay; }))[0]; ok(count($row['nums']) === 10 && $row['nums'][0][0] === 'Total sales (₹)' && $row['need_text'], 'My Day shows the numbers to the person who writes the summary');
[$c] = call('fup.claude', $ADMIN, ['section_id' => $st]); ok(strpos($GLOBALS['AI_SAW'], 'Total sales (₹) 941000 (from the system) · Queries 941 (from the system)') !== false, 'Claude\'s section note reads every number');
// ---- the owner's own fields on a topic (post link, yes / no, review counts, a file)
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Daily Reddit post', 'assignee_emp' => 11, 'fields' => [['label' => 'Post link', 'type' => 'link', 'req' => 1], ['label' => 'Website changed', 'type' => 'yesno', 'req' => 1], ['label' => 'Google reviews', 'type' => 'num'], ['label' => 'Platform', 'type' => 'choice', 'opts' => 'Reddit, Instagram, YouTube'], ['label' => 'Screenshot', 'type' => 'file'], ['label' => '', 'type' => 'num'], ['label' => 'Bad list', 'type' => 'choice', 'opts' => 'one']]]); $tRd = $r['id'];
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $tp = array_values(array_filter($b['topics'], function ($x) use ($tRd) { return $x['id'] === $tRd; }))[0];
ok(count($tp['fields']) === 6 && $tp['fields'][0]['k'] === 'f1' && $tp['fields'][3]['opts'] === ['Reddit', 'Instagram', 'YouTube'] && $tp['fields'][5]['type'] === 'text', 'fields are kept with their own keys; an empty label is dropped; a list with one choice becomes plain words');
[$c, $r] = call('fup.update_save', $E(11), ['topic_id' => $tRd, 'text' => '', 'fields' => ['f1' => 'not a link']]); ok($c === 422, 'a link field takes only a link');
[$c, $r] = call('fup.update_save', $E(11), ['topic_id' => $tRd, 'text' => '', 'fields' => ['f2' => 'maybe']]); ok($c === 422, 'a yes / no field takes only yes or no');
[$c, $r] = call('fup.update_save', $E(11), ['topic_id' => $tRd, 'text' => '', 'fields' => ['f1' => 'https://reddit.com/r/x/1', 'f3' => '41', 'f4' => 'Reddit']]); ok($c === 200, 'fields can be saved without words');
ok(strpos($GLOBALS['AI_CHK'], 'FIELDS FILLED WITH THIS UPDATE: Post link: https://reddit.com/r/x/1 · Website changed: (not filled, REQUIRED)') !== false, 'Claude sees the fields');
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Daily Reddit post: not complete, still to fill: Website changed') !== false, 'a required field left empty keeps the day open');
[$c, $m] = call('fup.mine', $E(11)); $row = array_values(array_filter($m['topics'], function ($x) use ($tRd) { return $x['id'] === $tRd; }))[0]; ok($row['done_today'] === false && $row['missing'] === ['Website changed'] && $row['fields'] === 6, 'My Day says what is still to fill');
[$c, $r] = call('fup.update_save', $E(11), ['topic_id' => $tRd, 'text' => '', 'fields' => ['f2' => 'Yes']]); [$c, $m] = call('fup.mine', $E(11)); $row = array_values(array_filter($m['topics'], function ($x) use ($tRd) { return $x['id'] === $tRd; }))[0];
ok($row['done_today'] === true && $row['today']['f']['f1'] === 'https://reddit.com/r/x/1' && $row['today']['f']['f2'] === 'yes' && $row['today']['f']['f3'] === '41', 'all required fields filled: the day is complete, earlier fields are kept');
[$c, $r] = call('fup.topic_save', $ADMIN, ['id' => $tRd, 'section_id' => $st, 'title' => 'Daily Reddit post', 'assignee_emp' => 11, 'fields' => [['k' => 'f1', 'label' => 'Link of the post', 'type' => 'link', 'req' => 1], ['label' => 'Comments', 'type' => 'num']]]);
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $tp = array_values(array_filter($b['topics'], function ($x) use ($tRd) { return $x['id'] === $tRd; }))[0]; ok($tp['fields'][0]['k'] === 'f1' && $tp['fields'][1]['k'] === 'f7', 'renaming a field keeps its key; a new field never takes the key of a removed one');
// ---- who to call: the boss of a section and the person behind every open line
[$c] = call('fup.section_save', $ADMIN, ['id' => $st, 'name' => 'Surat Store', 'owner_emp' => 10]);
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); $pp = $b['people'];
ok($pp[10]['name'] === 'Asha' && $pp[10]['phone'] === '919876543210' && $pp[10]['title'] === 'Store Manager' && isset($pp[11]) && isset($pp[12]), 'the Admin gets the name, title and phone of every boss and every topic person');
$noOne = array_values(array_filter(array_merge($b['overview']['late'], $b['overview']['empty']), function ($x) { return $x['section'] === 'Surat Store' && $x['who'] === ''; }));
ok(!$noOne || $noOne[0]['emp'] === 10, 'a topic given to nobody points to the boss of its section');
[$c, $b] = call('fup.board', $E(11)); ok(isset($b['people'][10]) && !isset($b['people'][12]), 'a person with only his own topic sees his section boss, not everybody\'s number');
[$c] = call('fup.section_save', $ADMIN, ['id' => $st, 'name' => 'Surat Store', 'owner_emp' => 0]);
// ---- every section's sheet in one answer (the "All sections" view)
[$c, $b] = call('fup.board', $ADMIN, null, ['all' => 1]); ok(count($b['all']) === count($b['sections']) && $b['all'][0]['id'] === $b['sections'][0]['id'] && count($b['all'][1]['topics']) > 0 && isset($b['all'][1]['topics'][0]['updates']), 'all sections come with their topics and updates');
[$c, $b] = call('fup.board', $E(11), null, ['all' => 1]); $ids = array_column($b['all'], 'id'); ok($ids === array_column($b['sections'], 'id') && !in_array($ws, $ids, true), 'a person gets only the sections (and topics) he may see');
[$c, $b] = call('fup.board', $ADMIN); ok(!isset($b['all']), 'the single-section answer stays as it was');
ok(fup_phone('098765 43210') === '919876543210' && fup_phone('9876543210 / 9123456780') === '919876543210' && fup_phone('+91-98765-43210') === '919876543210' && fup_phone('12345') === '' && fup_phone('') === '', 'a typed mobile number is made ready to dial');
// ---- punch-out lock
$l = fup_po_left($pdo, 12); ok(count($l) >= 1 && strpos(implode('|', array_column($l, 'title')), 'Follow-up update: Board fitting (Shop Wholesale)') !== false, 'punch out: the topics not filled today are listed for the lock');
[$c] = call('fup.update_save', $E(12), ['topic_id' => $tAd, 'text' => 'ok']); ok(strpos(implode('|', array_column(fup_po_left($pdo, 12), 'title')), "Ad spend (Shop Wholesale) — answer Claude's question") !== false, 'punch out: an unanswered question also holds it');
ok(fup_po_left($pdo, 10) === [] || !in_array('Follow-up update: Board fitting (Shop Wholesale)', array_column(fup_po_left($pdo, 10), 'title'), true), 'punch out: another person is not held by it');
ok(fup_po_left($pdo, 0) === [], 'punch out: a login without an employee is never held');
// ---- the evening pass
$r = fup_daily_pass($pdo, true); ok($r['notes'] === 2 && $r['sent'] === 1 && $GLOBALS['PUSH'][0] === [10] && strpos($GLOBALS['PUSH'][1]['body'], '1 deadline missed') === 0, 'evening: a note for every section and one notification to the Admin with the count');
ok((int)$pdo->query("SELECT COUNT(*) FROM fu_digest WHERE day = '2026-11-23'")->fetchColumn() === 2 && ($GLOBALS['SET']['fup_digest_day'] ?? '') === date('Y-m-d'), 'the notes are saved and the day is marked done');
$r = fup_daily_pass($pdo); ok(isset($r['skipped']), 'it does not run twice a day');
echo $bad ? "followup_test: $bad of $n FAILED\n" : "followup_test: all $n passed\n"; exit($bad ? 1 : 0);
