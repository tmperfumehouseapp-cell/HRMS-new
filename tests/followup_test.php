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
function brain_ai_text($s, $a, $sys, $user, $max = 700, $o = []) { $GLOBALS['AI_SAW'] = $user; return "MOVING\nSales went up."; }
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE fu_sections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, owner_emp INT DEFAULT 0, owner_dept INT DEFAULT 0, sort_no INT DEFAULT 0, active INT DEFAULT 1, created_at TEXT)");
$pdo->exec("CREATE TABLE fu_topics (id INTEGER PRIMARY KEY AUTOINCREMENT, section_id INT, title TEXT, kind TEXT DEFAULT 'progress', due_date TEXT, assignee_emp INT DEFAULT 0, source TEXT DEFAULT '', note TEXT DEFAULT '', status TEXT DEFAULT 'open', done_at TEXT, sort_no INT DEFAULT 0, active INT DEFAULT 1, created_by INT DEFAULT 0, created_at TEXT)");
$pdo->exec("CREATE TABLE fu_updates (id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INT, day TEXT, txt TEXT, val REAL, pct INT, auto INT DEFAULT 0, emp_id INT DEFAULT 0, emp_name TEXT DEFAULT '', updated_at TEXT, UNIQUE (topic_id, day))");
$pdo->exec("CREATE TABLE fu_digest (section_id INT, day TEXT, txt TEXT, made_at TEXT, PRIMARY KEY (section_id, day))");
$pdo->exec("CREATE TABLE employees (employee_id INTEGER PRIMARY KEY, full_name TEXT, department_id INT, status TEXT)");
$pdo->exec("CREATE TABLE departments (department_id INTEGER PRIMARY KEY, department_name TEXT)");
$pdo->exec("CREATE TABLE st_bills (bill_id INTEGER PRIMARY KEY, biz_date TEXT, total REAL, status TEXT)");
$pdo->exec("INSERT INTO departments VALUES (1, 'Store'), (2, 'Wholesale')");
$pdo->exec("INSERT INTO employees VALUES (10, 'Asha', 1, 'approved'), (11, 'Bharat', 1, 'approved'), (12, 'Chirag', 2, 'approved'), (13, 'Old', 2, 'ex')");
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
ok(strpos($txt, 'New display: deadline in 1 day') !== false && strpos($txt, 'New display: no update today yet (Bharat)') !== false && strpos($txt, 'Sales:') === false, 'checks: deadline close + no update today; the updated topic is clean');
$pdo->exec("INSERT INTO fu_updates (topic_id, day, txt) VALUES ($tSales, '2026-11-22', 'good day, 3 big  bills')");
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'same words as yesterday') !== false, 'checks: copied update is flagged');
[$c] = call('fup.claude', $E(10), ['section_id' => $st]); ok($c === 403, 'a topic person cannot ask Claude for the whole section');
[$c, $r] = call('fup.claude', $ADMIN, ['section_id' => $st]); ok($c === 200 && strpos($r['digest']['text'], 'MOVING') === 0 && strpos($GLOBALS['AI_SAW'], 'TOPIC: Sales') !== false && strpos($GLOBALS['AI_SAW'], '2000 ₹ (from the system)') !== false, 'Claude gets the sheet and the note is saved');
[$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]); ok($b['digest'] && $b['digest']['day'] === '2026-11-23', 'the note is shown again');
[$c] = call('fup.topic_done', $ADMIN, ['id' => $tDisp, 'done' => 1]); [$c, $m] = call('fup.mine', $E(11)); ok(count($m['topics']) === 0, 'a finished topic leaves My Day');
[$c] = call('fup.topic_del', $ADMIN, ['id' => $tSales]); ok((int)$pdo->query("SELECT COUNT(*) FROM fu_updates WHERE topic_id = $tSales")->fetchColumn() === 2, 'removing a topic keeps its updates');
[$c, $p] = call('fup.people', $ADMIN); ok(count($p['people']) === 3 && count($p['depts']) === 2, 'people list leaves out ex-employees');
[$c, $b] = call('fup.board', $ADMIN); $g = array_count_values(array_column($b['sources'], 'group')); ok(($g['Shop wholesale'] ?? 0) >= 10 && ($g['Surat store'] ?? 0) >= 3 && $b['sources'][0]['page'] !== '', 'numbers are grouped by the part of the HRMS they connect to');
foreach (fup_sources() as $k => $s) ok(count($s) === 6 && is_callable($s[2]), "source $k is complete");
[$c, $r] = call('fup.topic_save', $ADMIN, ['section_id' => $st, 'title' => 'Bills', 'source' => 'store_bills']); [$c, $b] = call('fup.board', $ADMIN, null, ['section' => $st]);
ok(strpos(implode(' | ', array_column($b['checks'], 'text')), 'Bills:') === false, 'a connected topic whose number came by itself needs no typing and no person');
echo $bad ? "followup_test: $bad of $n FAILED\n" : "followup_test: all $n passed\n"; exit($bad ? 1 : 0);
