<?php
// Messages sent with a UTILITY template are marked (tpl_used) and counted for the blue part of the campaign colour line.
$camp = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php'); $ru = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $ui = file_get_contents(dirname(__DIR__) . '/assets/js/wa_camp_ui.js');
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
ok(strpos($camp, "strtoupper((string)(\$tSent['category'] ?? '')) === 'UTILITY') \$tuId = (int)\$tSent['id'];") !== false, 'send marks the utility template id');
ok(strpos($ui, 'utD = u.ut_del || 0') !== false && strpos($ui, 'utD = uu.ut_del || 0') !== false, 'both colour lines use the utility-template counts');
// run the counting SQL on a small database
preg_match('/SELECT i\.camp_id, SUM\(i\.status = \'sent\'\) AS sn.*?GROUP BY i\.camp_id/s', $ru, $m); ok(!empty($m[0]), 'counting SQL found');
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_camp_items (camp_id INT, status TEXT, wamid TEXT, tpl_used INT, variant TEXT)"); $pdo->exec("CREATE TABLE wa_templates (id INT, category TEXT)"); $pdo->exec("CREATE TABLE wa_messages (wamid TEXT, status TEXT)");
$pdo->exec("INSERT INTO wa_templates VALUES (1, 'UTILITY'), (2, 'MARKETING')");
$rows = [[7, 'sent', 'a', 1, 'M'], [7, 'sent', 'b', 1, 'M'], [7, 'sent', 'c', 1, 'M'], [7, 'failed', '', 1, 'M'], [7, 'sent', 'd', 2, 'M'], [7, 'sent', 'e', 0, 'M'], [7, 'sent', 'f', 1, 'U']];
foreach ($rows as $r) $pdo->prepare("INSERT INTO wa_camp_items VALUES (?, ?, ?, ?, ?)")->execute($r);
foreach ([['a', 'delivered'], ['b', 'read'], ['c', 'sent'], ['d', 'delivered']] as $r) $pdo->prepare("INSERT INTO wa_messages VALUES (?, ?)")->execute($r);
$r = $pdo->query(str_replace('$in', '7', $m[0]))->fetch(PDO::FETCH_ASSOC);
ok((int)$r['d'] === 2 && (int)$r['sn'] === 3 && (int)$r['f'] === 1, 'utility-template counts: 2 delivered, 3 sent, 1 failed (got d=' . $r['d'] . ' sn=' . $r['sn'] . ' f=' . $r['f'] . ')');
echo "util blue ok\n";
