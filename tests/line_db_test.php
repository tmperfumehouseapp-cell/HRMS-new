<?php
// Lineup engine on a small in-memory SQLite copy of the tables it touches (MySQL-only words in the CREATE statements are translated). No Meta, no WhatsApp: wd_send is a stub that records who got which message.
date_default_timezone_set('Asia/Kolkata');
class T extends PDO { public function exec($q) { $q = preg_replace('/\)\s*ENGINE=InnoDB[^;]*$/', ')', $q); $q = str_replace('BIGINT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q); $q = preg_replace('/,\s*KEY\s+\w+\s*\([^)]*\)/', '', $q); return parent::exec($q); } }
$pdo = new T('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (["CREATE TABLE wa_contacts (phone TEXT PRIMARY KEY, name TEXT DEFAULT '', last_in_at TEXT, optout INT DEFAULT 0, camp_optout INT DEFAULT 0, source TEXT DEFAULT 'query', tag TEXT DEFAULT '', cx_id INT DEFAULT 0)",
 "CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, wamid TEXT DEFAULT '', phone TEXT, dir TEXT, mtype TEXT DEFAULT 'text', body TEXT, status TEXT DEFAULT 'received', who TEXT DEFAULT 'customer', ts TEXT)",
 "CREATE TABLE wa_cust (phone TEXT PRIMARY KEY, n INT DEFAULT 0, last_order TEXT)", "CREATE TABLE shop_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, created_at TEXT, total REAL, tags TEXT DEFAULT '')",
 "CREATE TABLE wa_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, category TEXT DEFAULT 'UTILITY', relabelled_at TEXT)", "CREATE TABLE wa_tpl_waba (tpl_id INT, waba_id TEXT, status TEXT)",
 "CREATE TABLE wa_camp_items (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, status TEXT, last_status TEXT, sent_at TEXT)", "CREATE TABLE wa_fu_ev (phone TEXT, status TEXT, sent_at TEXT)", "CREATE TABLE wa_wj_ev (phone TEXT, status TEXT, sent_at TEXT)"] as $q) $pdo->exec($q);
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = (string)$v; }
function json_response($a, $c = 200) { throw new Exception('json_response'); } function wa_log($p, $k, $ok, $i) { }
function wa_key($n) { $d = preg_replace('/\D+/', '', (string)$n); return strlen($d) > 10 ? substr($d, -10) : $d; }
function wa_contact($p, $k) { $q = $p->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$k]); return $q->fetch() ?: null; }
function wa_lock($p, $n, $s) { return true; }
$SENT = []; function wd_send($pdo, $c, $step) { global $SENT; $SENT[] = [$c['phone'], $step['tpl_id']]; return [true, '', 1, 'wamid' . count($SENT)]; }
$S['wl_map'] = '{}'; $pdo_ = $pdo; $GLOBALS['pdo'] = $pdo; require dirname(__DIR__) . '/empmanagment/wa_line.php';
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$now = time(); $since = wl_yesterday_start($now); $f = fn($t) => date('Y-m-d H:i:s', $t);
// customers who wrote after "yesterday 10:00 IST"
$cust = ['9810000001' => ['asli hai kya?'], '9810000002' => ['price kya hai'], '9810000003' => ['50ml'], '9810000004' => ['address bhej raha hu'], '9810000005' => ['hello'], '9810000006' => ['original hai'], '9810000007' => ['rate?']];
$t0 = $since + 3600; $i = 0;
foreach ($cust as $ph => $txt) { $t = $t0 + $i * 600; $i++; $pdo->prepare("INSERT INTO wa_contacts (phone, name, last_in_at) VALUES (?, ?, ?)")->execute([$ph, 'Cust' . $i, $f($t)]);
    foreach ($txt as $x) $pdo->prepare("INSERT INTO wa_messages (phone, dir, body, ts) VALUES (?, 'in', ?, ?)")->execute([$ph, $x, $f($t)]); }
$pdo->exec("INSERT INTO wa_cust (phone, n) VALUES ('9810000005', 2)");   // 5 bought before -> 'reorder'
$pdo->prepare("INSERT INTO shop_orders (phone, created_at, total) VALUES ('+919810000007', ?, 899)")->execute([$f($t0 + 5000)]);   // 7 ordered after writing -> not enrolled
$pdo->prepare("INSERT INTO wa_contacts (phone, name, last_in_at, optout) VALUES ('9810000008', 'Opt', ?, 1)")->execute([$f($t0)]);
ok(wln_tables($pdo), 'tables');
$n = wl_enroll($pdo, $since); ok($n === 5 || $n === 6, "enrolled: $n"); 
$tp = []; foreach ($pdo->query("SELECT phone, topic FROM wa_line_cust")->fetchAll() as $r) $tp[$r['phone']] = $r['topic'];
ok(($tp['9810000001'] ?? '') === 'trust' && ($tp['9810000002'] ?? '') === 'price' && ($tp['9810000003'] ?? '') === 'size' && ($tp['9810000004'] ?? '') === 'pending' && ($tp['9810000005'] ?? '') === 'reorder' && ($tp['9810000006'] ?? '') === 'trust', 'topics ' . json_encode($tp));
ok(!isset($tp['9810000007']), 'a customer who already ordered is not enrolled'); ok(!isset($tp['9810000008']), 'opted out is not enrolled');
ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan")->fetchColumn() === 6 * 7, 'seven rows each');
ok(wl_enroll($pdo, $since) === 0, 'a second enrol adds nobody');
$pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00'");   // every conversation is over (the clock of the test must not matter)
// the sending: templates made (ids) and approved
$ids = []; $k = 0; foreach (wl_pack() as $key => $m) { $k++; $ids[$key] = $k; $pdo->exec("INSERT INTO wa_templates (id) VALUES ($k)"); $pdo->exec("INSERT INTO wa_tpl_waba VALUES ($k, 'w1', 'APPROVED')"); } setting_set($pdo, 'wl_tpls', json_encode($ids));
wl_cfg_put($pdo, ['mode' => 'auto', 'cap' => 60, 'from' => 0, 'to' => 24, 'since' => $since, 'started' => '2026-11-21 00:00:00', 'offer' => '']);
ok(wl_ready($pdo), 'all approved -> ready');
// the day-1 steps are due 24 h after the first message: move the clock by making them due
$pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE step = 1");
$r = wl_tick($pdo, ['now' => 1, 'cap' => 60]); ok($r['sent'] === 6, 'six step-1 messages sent, got ' . json_encode($r));
ok(count($SENT) === 6, 'stub got six'); ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE status = 'sent'")->fetchColumn() === 6, 'six rows marked sent');
$r = wl_tick($pdo, ['now' => 1, 'cap' => 60]); ok($r['sent'] === 0, 'nothing more is due: one message per customer');
// a customer orders -> out; a customer replies -> ladder stops
$pdo->prepare("INSERT INTO shop_orders (phone, created_at, total) VALUES ('9810000002', ?, 1200)")->execute([$f($now + 10)]);
$pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE step = 2"); $pdo->exec("UPDATE wa_line_plan SET sent_at = '2000-01-01 00:00:00' WHERE status = 'sent'");   // 24 h passed
$pdo->prepare("UPDATE wa_contacts SET last_in_at = ? WHERE phone = '9810000003'")->execute([$f($now + 20)]);
$pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone NOT IN ('9810000003')");
$SENT = []; $r = wl_tick($pdo, ['now' => 1, 'cap' => 60]);
ok(!in_array('9810000002', array_column($SENT, 0), true), 'the customer who ordered gets nothing'); ok(!in_array('9810000003', array_column($SENT, 0), true), 'the customer who replied gets nothing');
ok((string)$pdo->query("SELECT left_why FROM wa_line_cust WHERE phone = '9810000002'")->fetchColumn() === 'ordered', 'left because ordered');
ok((string)$pdo->query("SELECT status FROM wa_line_cust WHERE phone = '9810000003'")->fetchColumn() === 'replied', 'replied -> waits 24 quiet hours');
ok(count($SENT) === 4, 'the other four got step 2, got ' . count($SENT));
// report, status, overview, heal, snapshot
$pdo->prepare("UPDATE wa_line_plan SET sent_at = ? WHERE phone = '9810000002' AND step = 1")->execute([$f($now - 3600)]);
$rep = wl_report($pdo, 30, 1); $tr = $rep['keys']['ln_trust_2']['sent'] ?? 0; ok($tr === 2, 'report counts the trust step 2 sends (two trust customers), got ' . $tr); ok($rep['topics']['price']['buyers'] === 1, 'the order is credited to the price topic');
$st = wl_status($pdo); ok($st['messages']['total'] === 33 && $st['messages']['approved'] === 33 && $st['customers']['identified'] === 6 && $st['sending']['sent'] >= 10, 'status ' . json_encode($st['customers']) . json_encode($st['sending']));
$ov = wl_overview($pdo); ok(isset($ov['board']) && isset($ov['tomorrow']) && isset($ov['status']), 'overview');
$h = wl_heal($pdo); ok(is_array($h['issues']), 'self-check runs'); ok(wl_snap($pdo, true), 'snapshot written'); ok(count(wl_snaps($pdo)) === 1, 'snapshot read back');
$c = wl_customer($pdo, '9810000001'); ok($c['in'] && count($c['plan']) === 7, 'customer timeline');
// replied customer is read again after 24 quiet hours: a new ladder from the unsent messages
$pdo->exec("UPDATE wa_line_cust SET recheck_at = '2000-01-01 00:00:00' WHERE phone = '9810000003'"); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9810000003'");
$pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9810000003', 'in', 'kitna rate hai', '" . $f($now + 30) . "')");
ok(wl_recheck($pdo, $now + 100000) === 1, 'recheck re-plans'); ok((string)$pdo->query("SELECT topic FROM wa_line_cust WHERE phone = '9810000003'")->fetchColumn() === 'price', 'new topic price');
ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE phone = '9810000003' AND status = 'planned'")->fetchColumn() === 7, 'new ladder of seven (step 1 of the old topic was sent, a different message)');
ok(strtotime((string)$pdo->query("SELECT due_at FROM wa_line_plan WHERE phone = '9810000003' AND status = 'planned' ORDER BY step LIMIT 1")->fetchColumn()) <= $now + 100000, 'the new ladder\'s first step is due at once (his 24 quiet hours are over)');
// he wrote again before the recheck: the conversation is not over, the 24 hours move to his new last message
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9880000001', 'Again', '" . $f($now - 3600) . "', 'query')"); $pdo->exec("INSERT INTO wa_line_cust (phone, topic, anchor_at, status, created_at, recheck_at) VALUES ('9880000001', 'price', '" . $f($now - 86400) . "', 'replied', '" . $f($now - 86400) . "', '2000-01-01 00:00:00')");
ok(wl_recheck($pdo, $now) === 0, 'no re-plan while he is still talking'); $ra = strtotime((string)$pdo->query("SELECT recheck_at FROM wa_line_cust WHERE phone = '9880000001'")->fetchColumn()); ok(abs($ra - ($now - 3600 + 86400)) < 5, 'recheck moved to his last message + 24 h');
$sm = wl_summary($pdo, 0); ok($sm['sent'] >= 10 && $sm['utility'] === $sm['sent'] && $sm['plain'] === 0, 'summary: every message went as utility ' . json_encode([$sm['sent'], $sm['utility'], $sm['plain']]));
ok(abs($sm['cost'] - round($sm['sent'] * $sm['rate_u'], 2)) < 0.001 && $sm['orders'] === 1 && $sm['buyers'] === 1, 'summary: cost = utility messages x rate; the order is credited');
$pdo->exec("UPDATE wa_templates SET category = 'MARKETING' WHERE id = " . $ids['ln_trust_2']); $sm2 = wl_summary($pdo, 0); ok($sm2['plain'] >= 1 && $sm2['cost'] > $sm['cost'], 'a message Meta made marketing is counted and costs more');
// the shop's own approved messages (default map): seven different messages in every ladder, and they replace the lineup's own templates
unset($S['wl_map']); $mp = wln_map($pdo); ok(count($mp) === 31, 'map has 27 base steps + 4 for the campaign ladder, got ' . count($mp));
foreach (array_keys(wl_topics()) as $tp) { $seen = []; for ($st = 1; $st <= 7; $st++) { $id = $mp[wl_key($tp, $st)] ?? 0; ok($id > 0, "map: $tp step $st"); ok(!isset($seen[$id]), "map: $tp repeats message #$id inside one ladder"); $seen[$id] = 1; } }
$eff = wln_eff_ids($pdo); ok($eff['ln_trust_1'] === 386 && $eff['ln_all_7'] === 109 && $eff['ln_trust_1b'] === $ids['ln_trust_1b'], 'effective ids: mapped steps use the shop messages, the extra wording keeps its own');
$S['wl_map'] = '{}'; ok(wln_eff_ids($pdo)['ln_trust_1'] === $ids['ln_trust_1'], '{} switches the map off');
// no clock window of its own: the default window is the whole day, and the steps that were pushed into 10-20 go back to their natural time
ok(wl_cfg($pdo)['from'] === 0 && wl_cfg($pdo)['to'] === 24, 'no window by default'); $S['wl_cfg'] = json_encode(['mode' => 'auto', 'since' => $since, 'started' => '2026-11-21 00:00:00', 'from' => 10, 'to' => 20]); ok(wl_cfg($pdo)['from'] === 0 && wl_cfg($pdo)['to'] === 24, 'an old saved 10-20 is ignored (win flag missing)');
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9850000001', 'Win', '" . $f($now - 7200) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9850000001', 'in', 'kimat', '" . $f($now - 7200) . "')"); wl_enroll($pdo, $since); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9850000001'");
$pdo->exec("UPDATE wa_line_plan SET due_at = '2030-01-01 10:00:00' WHERE phone = '9850000001' AND status = 'planned'"); $S['wl_unwin'] = '';
$nn = wln_unwindow($pdo); ok($nn >= 7, 'unwindow moved the waiting rows of a customer nobody has written to yet'); $d4 = $pdo->query("SELECT p.due_at, c.anchor_at FROM wa_line_plan p JOIN wa_line_cust c ON c.phone = p.phone WHERE p.phone = '9850000001' AND p.step = 3")->fetch(); ok(strtotime($d4['due_at']) === strtotime($d4['anchor_at']) + 3 * 86400, 'step 3 is back at anchor + 3 days'); ok(wln_unwindow($pdo) === 0, 'once only');
// one time: catch everybody since the day before yesterday 10:00 IST
$S['wl_cfg'] = json_encode(['mode' => 'auto', 'since' => $since, 'started' => '2026-11-21 00:00:00']); $S['wl_since_v2'] = ''; ok(wln_since_back($pdo) === true && wl_cfg($pdo)['since'] === wl_yesterday_start(time()) - 86400, 'since moves one day back (day before yesterday 10:00 IST)'); ok(wln_since_back($pdo) === false, 'only once');
$S['wl_cfg'] = json_encode(['mode' => 'auto', 'since' => $since, 'started' => '2026-11-21 00:00:00', 'offer' => '', 'camp' => 'off']);
// the 24 hours count from the END of the conversation: a customer who wrote one hour ago waits; once he is quiet for a day his step 1 goes, and the next steps are 24 h after it
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9840000001', 'Talk', '" . $f($now - 3600) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9840000001', 'in', 'asli hai', '" . $f($now - 3600) . "')");
ok(wl_enroll($pdo, $since) >= 1, 'talking customer enrolled'); $pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE phone = '9840000001' AND step = 1");
$SENT = []; wl_tick($pdo, ['now' => 1, 'cap' => 60]); ok(!in_array('9840000001', array_column($SENT, 0), true), 'while the conversation goes on nothing is sent');
$pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9840000001'"); $pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE phone = '9840000001' AND step IN (1, 2, 3)");   // steps 2 and 3 are also "due" by the old clock
$SENT = []; wl_tick($pdo, ['now' => 1, 'cap' => 60]); $got = array_values(array_filter($SENT, fn($x) => $x[0] === '9840000001')); ok(count($got) === 1, 'once the conversation is over exactly ONE message goes (step 1, not step 3)');
ok((string)$pdo->query("SELECT status FROM wa_line_plan WHERE phone = '9840000001' AND step = 1")->fetchColumn() === 'sent' && (string)$pdo->query("SELECT status FROM wa_line_plan WHERE phone = '9840000001' AND step = 2")->fetchColumn() === 'planned', 'step 1 sent, step 2 still planned');
ok((int)strtotime((string)$pdo->query("SELECT due_at FROM wa_line_plan WHERE phone = '9840000001' AND step = 2")->fetchColumn()) >= time() + 18 * 3600, 'step 2 is moved to about 24 h after step 1');
// a campaign or a template from another module does not hold the Lineup; a salesperson who wrote does
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9860000001', 'Camp', '" . $f($now - 7200) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9860000001', 'in', 'rate', '" . $f($now - 7200) . "')");
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9860000002', 'Sale', '" . $f($now - 7200) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9860000002', 'in', 'rate', '" . $f($now - 7200) . "')");
wl_enroll($pdo, $since); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone IN ('9860000001','9860000002')"); $pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE phone IN ('9860000001','9860000002') AND step = 1");
$pdo->exec("INSERT INTO wa_messages (phone, dir, mtype, body, status, who, ts) VALUES ('9860000001', 'out', 'template', 'campaign', 'delivered', 'campaign', '" . $f($now - 3600) . "')");   // a campaign went an hour ago
$pdo->exec("INSERT INTO wa_messages (phone, dir, mtype, body, status, who, ts) VALUES ('9860000002', 'out', 'text', 'hello', 'sent', 'emp', '" . $f($now - 3600) . "')");   // a salesperson wrote an hour ago
$SENT = []; wl_tick($pdo, ['now' => 1, 'cap' => 60]); $gp = array_column($SENT, 0); ok(in_array('9860000001', $gp, true), 'a campaign an hour ago does NOT hold the lineup'); ok(!in_array('9860000002', $gp, true), 'a salesperson who wrote an hour ago does');
// the conversation is read again when the first message is about to go: 'hello' enrolled him as general, his later words say address -> the pending ladder
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9870000001', 'Chg', '" . $f($now - 7200) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9870000001', 'in', 'hello', '" . $f($now - 7200) . "')");
wl_enroll($pdo, $since); ok((string)$pdo->query("SELECT topic FROM wa_line_cust WHERE phone = '9870000001'")->fetchColumn() === 'general', 'enrolled as general (only "hello" so far)');
$pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9870000001', 'in', 'address bhej raha hu', '" . $f($now - 3600) . "')"); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9870000001'"); $pdo->exec("UPDATE wa_line_plan SET due_at = '2000-01-01 00:00:00' WHERE phone = '9870000001' AND step = 1");
$SENT = []; wl_tick($pdo, ['now' => 1, 'cap' => 60]); $gs = array_values(array_filter($SENT, fn($x) => $x[0] === '9870000001')); ok(count($gs) === 1 && (int)$gs[0][1] === (int)$ids['ln_pending_1'], 'the pending ladder step 1 went, not the general one: ' . json_encode($gs));
ok((string)$pdo->query("SELECT topic FROM wa_line_cust WHERE phone = '9870000001'")->fetchColumn() === 'pending', 'his topic is now pending'); ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_log WHERE phone = '9870000001'")->fetchColumn() === 1, 'the change is written in the log');
$lr = json_decode((string)($S['wl_last_round'] ?? ''), true); ok(is_array($lr) && isset($lr['waits']) && isset($lr['due']), 'every round writes down why the due customers waited: ' . json_encode($lr));
// a message that no number can send right now is a waiting state, not a failure; old "failed" rows of that kind go back to planned once
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9890000001', 'NoNum', '" . $f($now - 7200) . "', 'query')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9890000001', 'in', 'rate', '" . $f($now - 7200) . "')"); wl_enroll($pdo, $since); $pdo->exec("UPDATE wa_contacts SET last_in_at = '2000-01-01 00:00:00' WHERE phone = '9890000001'");
$pdo->exec("UPDATE wa_line_plan SET status = 'failed', why = 'No number can send this message now (x)' WHERE phone = '9890000001' AND step = 1"); $S['wl_requeue_v1'] = ''; ok(wln_requeue_failed($pdo) >= 1, 'the old failed rows go back'); ok((string)$pdo->query("SELECT status FROM wa_line_plan WHERE phone = '9890000001' AND step = 1")->fetchColumn() === 'planned', 'step 1 is planned again'); ok(wln_requeue_failed($pdo) === 0, 'once only');
// campaign recipients: opened the campaign message, did not write / order / opt out; capped per day; oldest first
$cs = $t0 + 7200; $ci = fn($ph, $ls, $off) => $pdo->prepare("INSERT INTO wa_camp_items (phone, status, last_status, sent_at) VALUES (?, 'sent', ?, ?)")->execute([$ph, $ls, $f($cs + $off)]);
foreach (['9820000001' => 0, '9820000002' => 10, '9820000003' => 20, '9820000004' => 30, '9820000005' => 40, '9820000006' => 50, '9820000007' => 60] as $ph => $off) { $ph = (string)$ph; $ci($ph, $ph === '9820000005' ? 'delivered' : 'read', $off); $pdo->prepare("INSERT INTO wa_contacts (phone, name, last_in_at, optout, source) VALUES (?, ?, ?, ?, 'campaign')")->execute([$ph, 'C' . $ph, null, $ph === '9820000002' ? 1 : 0]); }
$pdo->prepare("UPDATE wa_contacts SET last_in_at = ? WHERE phone = '9820000003'")->execute([$f($cs + 100)]);   // wrote back: the normal path handles him
$pdo->prepare("INSERT INTO shop_orders (phone, created_at, total) VALUES ('9820000004', ?, 500)")->execute([$f($cs + 500)]);
wl_cfg_put($pdo, ['mode' => 'auto', 'cap' => 60, 'from' => 0, 'to' => 24, 'since' => $since, 'started' => '2026-11-21 00:00:00', 'offer' => '', 'camp' => 'read', 'camp_cap' => 2]);
ok(wln_enroll_camp($pdo, $since) === 2, 'campaign enrolment: cap of 2 a day, oldest first'); $ct = $pdo->query("SELECT phone FROM wa_line_cust WHERE topic = 'campaign' ORDER BY phone")->fetchAll(PDO::FETCH_COLUMN);
ok($ct === ['9820000001', '9820000006'], 'campaign enrolment picks the read, quiet, not-ordered, not-opted-out ones: ' . json_encode($ct)); ok(wln_enroll_camp($pdo, $since) === 0, 'the day cap holds');
ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE phone = '9820000001' AND status = 'planned'")->fetchColumn() === 7, 'a campaign recipient gets seven steps');
wl_cfg_put($pdo, ['mode' => 'auto', 'cap' => 60, 'from' => 0, 'to' => 24, 'since' => $since, 'started' => '2026-11-21 00:00:00', 'offer' => '', 'camp' => 'off']); $S['wl_camp_day'] = ''; ok(wln_enroll_camp($pdo, $since) === 0, 'camp = off enrols nobody');
ok(wln_camp_cleanup($pdo) === 2, 'switching it off takes the two campaign people off again'); ok((int)$pdo->query("SELECT COUNT(*) FROM wa_line_plan WHERE phone = '9820000001' AND status = 'planned'")->fetchColumn() === 0, 'and cancels their planned steps'); ok(wln_camp_cleanup($pdo) === 0, 'nothing left to clean');
ok(!isset(wl_cfg($pdo)['camp']) || wl_cfg($pdo)['camp'] === 'off', 'default is off');
// a reply counts whatever the contact's source says
$pdo->exec("INSERT INTO wa_contacts (phone, name, last_in_at, source) VALUES ('9830000001', 'Src', '" . $f($now) . "', 'community')"); $pdo->exec("INSERT INTO wa_messages (phone, dir, body, ts) VALUES ('9830000001', 'in', 'combo price', '" . $f($now) . "')");
ok(wl_enroll($pdo, $since) >= 1 && (string)$pdo->query("SELECT topic FROM wa_line_cust WHERE phone = '9830000001'")->fetchColumn() === 'price', 'a reply from a community contact enrols him with the topic of his words');
echo "lineup db ok\n";
