<?php
// Owner 22 Nov 2026: campaigns Meta paused for low quality (132015) carry on with another approved version by themselves; failed utility messages go out again with ANOTHER utility message; number 11 opens with a slow start.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$camp = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $ru = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $more = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_more.php');
ok(preg_match('/function wa_camp_swap_paused\(PDO \$pdo\): int\n\{.*?\n\}\n/s', $camp, $m) === 1, 'swap function present');
ok(strpos($ru, "wa_camp_swap_paused(\$pdo)") !== false, 'Module B tick calls the swap');
ok(strpos($ru, '$tplErr = [132000, 132001, 132005, 132007, 132012, 132015, 132016, 131009]') !== false && strpos($ru, 'l.err_code AS uerr') !== false, 'retry tick sends another utility message when the message itself was the problem');
ok(strpos($more, "wa_open11_2211") !== false && strpos($more, "tier_manual = 250 WHERE phone_id = '1561947433664883'") !== false, 'number 11 opens with a slow start of 250 a day');
ok(strpos($ru, "'wa_t11_at'") !== false, 'number 11 daily limit climbs');
if (extension_loaded('pdo_sqlite')) {
    $S = []; function setting_get($pdo, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($pdo, $k, $v) { global $S; $S[$k] = $v; } function wa_log(...$a) { global $LOG; $LOG[] = $a[2] ?? ''; }
    function wa_camp_tpl($pdo, $id, $media = false, $lib = 0) { return in_array($id, [396, 405], true) ? ['id' => $id, 'nvars' => 1] : null; }   // 396 approved, 405 approved utility, others not
    eval($m[0]);
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE wa_campaigns (id INTEGER, tpl_id INTEGER, media_lib_id INTEGER, status TEXT, note TEXT)");
    $pdo->exec("CREATE TABLE wa_templates (id INTEGER, name TEXT, category TEXT, family_id INTEGER, version INTEGER)");
    $pdo->exec("INSERT INTO wa_templates VALUES (395,'a','MARKETING',395,1),(396,'a_v2','MARKETING',395,2),(405,'u1','UTILITY',395,1),(500,'other','MARKETING',500,1)");
    $pdo->exec("INSERT INTO wa_campaigns VALUES (1,395,0,'paused','Meta stopped the sending: (#132015) Template is temporarily unavailable (code 132015)'),(2,395,0,'paused','Paused by you'),(3,500,0,'paused','(code 132015)'),(4,395,0,'running','')");
    $n = wa_camp_swap_paused($pdo); ok($n === 1, 'one campaign switched, got ' . $n);
    $r = $pdo->query("SELECT id, tpl_id, status FROM wa_campaigns ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok($r[0]['tpl_id'] == 396 && $r[0]['status'] === 'running', 'paused-by-Meta campaign now runs with v2');
    ok($r[1]['status'] === 'paused' && $r[1]['tpl_id'] == 395, 'a campaign the owner paused stays paused');
    ok($r[2]['status'] === 'paused' && $r[2]['tpl_id'] == 500, 'no approved sibling: stays paused');
    ok($r[3]['status'] === 'running' && $r[3]['tpl_id'] == 395, 'running campaign untouched');
    $S['wa_camp_swap_at'] = '0'; $pdo->exec("UPDATE wa_campaigns SET status='paused', note='(code 132015)' WHERE id = 1"); $n = wa_camp_swap_paused($pdo);
    $t = $pdo->query("SELECT tpl_id FROM wa_campaigns WHERE id = 1")->fetchColumn(); ok($t == 405, 'second pause: next approved version (the utility one), never back to a bad one, got ' . $t);
    $pdo->exec("INSERT INTO wa_campaigns VALUES (5,3200,0,'paused','Fill the text for {{2}}.'),(6,500,0,'paused','Fill the text for {{2}}.')"); $S['wa_camp_swap_at'] = '0'; $S['wa_camp_tpl_bad'] = ''; wa_camp_swap_paused($pdo);
    $f = $pdo->query("SELECT id, tpl_id, status FROM wa_campaigns WHERE id IN (5, 6) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok($f[0]['tpl_id'] == 396 && $f[0]['status'] === 'running', 'unknown template with an unfilled blank: approved fallback v2 carries on');
    ok($f[1]['tpl_id'] == 396 && $f[1]['status'] === 'running', 'template without siblings and an unfilled blank: fallback too');
    $S['wa_camp_swap_at'] = '0'; $S['wa_camp_auto_swap'] = '0'; $pdo->exec("UPDATE wa_campaigns SET status='paused', note='(code 132015)' WHERE id = 1"); ok(wa_camp_swap_paused($pdo) === 0, 'switch-off setting works');
}
echo "auto swap ok\n";
