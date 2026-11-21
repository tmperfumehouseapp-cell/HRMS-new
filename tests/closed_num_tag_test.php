<?php
// Owner 21 Nov 2026: the "closed number" tag shows in the complaint list and in the ticket (not only in the chat).
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$php = file_get_contents(dirname(__DIR__) . '/empmanagment/complaints.php'); $js = file_get_contents(dirname(__DIR__) . '/assets/js/cx_app.js');
ok(strpos($php, "'closed_num' =>") !== false, 'list rows carry closed_num');
ok(strpos($php, 'SELECT phone, last_in_at, last_out_at, last_number_id FROM wa_contacts') !== false, 'the list reads the number of the chat');
ok(strpos($php, 'SELECT id FROM wa_numbers WHERE retired = 1') !== false, 'retired numbers are looked up once per page');
ok(strpos($php, "'retired' => !empty(\$n['retired'])") !== false, 'the ticket numbers carry the retired flag');
ok(strpos($js, "c.closed_num") !== false && strpos($js, 'Closed number') !== false, 'the list shows the tag');
ok(strpos($js, 'w.in.retired') !== false, 'the ticket shows the warning');
echo "closed number tag ok\n";
