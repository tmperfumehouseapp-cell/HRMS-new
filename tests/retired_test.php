<?php
// owner 20 Nov 2026: a retired number (the 4th, being closed) sends nothing: complaints skip it, the redirect never picks it, campaigns / Module B skip it (wa_camp_ctx).
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function cx_state_get($p, $k, $d = '') { global $S; return $S['cx_' . $k] ?? $d; }
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$cx = file_get_contents(dirname(__DIR__) . '/empmanagment/complaints_photo.php'); $a = strpos($cx, 'function cx_num_order'); $b = strpos($cx, '/** The complaint numbers that can send THIS approved message'); eval(substr($cx, $a, $b - $a));
$wa = file_get_contents(dirname(__DIR__) . '/empmanagment/wa.php'); $a = strpos($wa, 'function wa_redirect_target'); $b = strpos($wa, '/** Stores one incoming message'); eval(substr($wa, $a, $b - $a));
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_numbers (id INTEGER PRIMARY KEY, label TEXT, retired INT DEFAULT 0)"); $pdo->exec("CREATE TABLE wa_contacts (last_number_id INT, last_in_at TEXT)");
foreach ([[1, 'Main', 0], [2, 'Second', 0], [3, '4th', 1], [4, '5TH', 0]] as $r) $pdo->prepare("INSERT INTO wa_numbers VALUES (?, ?, ?)")->execute($r);
$o = cx_num_order($pdo); ok(!in_array(3, $o, true) && in_array(1, $o, true) && in_array(4, $o, true), 'complaints: the default list leaves out the retired number (got ' . implode(',', $o) . ')');
$S['cx_num_order'] = '3,1,4'; $o = cx_num_order($pdo); ok($o === [1, 4], 'complaints: a saved order never contains the retired number (got ' . implode(',', $o) . ')');
$S['wa_payblock_1'] = date('Y-m-d H:i:s'); $t = wa_redirect_target($pdo, 1); ok($t !== 3 && $t > 0, 'redirect: never moves a customer to the retired number (got ' . $t . ')');
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); ok(strpos($src, "!empty(\$n['retired'])") !== false, 'wa_camp_ctx skips a retired number (campaigns, utility follow-ups, recovery)');
echo "retired ok\n";
