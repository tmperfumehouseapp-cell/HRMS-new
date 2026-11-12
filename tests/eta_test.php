<?php
// Offline check of the queue end-time simulation (sqlite stands in for MySQL, the cache helpers are stubbed). Run: php tests/eta_test.php
function wa_cpc_get($p, $k) { return null; } function wa_cpc_put($p, $k, $v) { }
function wa_bizday_start($pdo) { return date('Y-m-d 12:00:00', time() - 5 * 3600); }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_dq.php');
if (!preg_match('/function wa_dq_eta\(PDO \$pdo, array \$st\): array\n\{.*?\n\}\n/s', $src, $m)) { echo "FAIL: wa_dq_eta not found\n"; exit(1); } eval($m[0]);
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_campaigns (id INTEGER, status TEXT, start_at TEXT)"); $pdo->exec("CREATE TABLE wa_camp_items (id INTEGER PRIMARY KEY, camp_id INTEGER, status TEXT)");
$add = function ($id, $status, $start, $n) use ($pdo) { $pdo->exec("INSERT INTO wa_campaigns VALUES ($id, '$status', " . ($start ? "'$start'" : 'NULL') . ")"); for ($i = 0; $i < $n; $i++) $pdo->exec("INSERT INTO wa_camp_items (camp_id, status) VALUES ($id, 'queued')"); };
$add(1, 'running', null, 4000); $add(2, 'running', null, 1000); $add(3, 'scheduled', date('Y-m-d H:i:s', time() + 6 * 3600), 3000);
$st = ['pace' => ['push_hr' => 10000], 'daily' => 150000, 'day_left' => 150000, 'eff_cap' => 15000, 'rate' => 0.7];
$r = wa_dq_eta($pdo, $st); $bad = 0; $t = fn($x) => strtotime($x);
if (empty($r['camps'][1]) || empty($r['camps'][2]) || empty($r['camps'][3])) { echo "FAIL: a campaign has no end time\n"; $bad++; }
else {
    if (!($t($r['camps'][2]) < $t($r['camps'][1]))) { echo "FAIL: the smaller campaign must end first\n"; $bad++; }
    if (abs(($t($r['camps'][1]) - time()) / 60 - 30) > 5) { echo "FAIL: 5000 pushes at 10000 an hour should end in about 30 minutes\n"; $bad++; }
    if (!($t($r['camps'][3]) > time() + 6 * 3600)) { echo "FAIL: a scheduled campaign cannot end before it starts\n"; $bad++; }
}
$st['day_left'] = 2000; $r2 = wa_dq_eta($pdo, $st);
if (!($t($r2['running']) > $t($r['running']))) { echo "FAIL: a used-up day number must push the end into the next business day\n"; $bad++; }
echo $bad ? "eta test: $bad problem(s)\n" : "eta test: ok\n"; exit($bad ? 1 : 0);
