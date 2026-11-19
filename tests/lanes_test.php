<?php
// Module B lanes: the safest people (wrote to us / ordered) and the never-reached people go first, 1 : 2, everybody else after.
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $p = strpos($src, 'function wa_ru_lane_order('); $i = strpos($src, '{', $p); $d = 0; for ($j = $i; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) { eval(substr($src, $p, $j - $p + 1)); break; } } }
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$now = time(); $items = []; $ct = []; $ever = []; $ord = [];
for ($n = 1; $n <= 12; $n++) { $ph = '90000000' . str_pad((string)$n, 2, '0', STR_PAD_LEFT); $items[] = ['id' => $n, 'phone' => $ph]; $ct[$ph] = ['last_in_at' => null]; }
// 1..3 safe (1 wrote 5 days ago, 2 ordered, 3 wrote long ago but ordered); 4..8 never reached; 9..12 reached before (lane C)
$ct['9000000001']['last_in_at'] = date('Y-m-d H:i:s', $now - 5 * 86400); $ord['9000000002'] = ['order' => '#1']; $ord['9000000003'] = ['order' => '#2'];
foreach ([9, 10, 11, 12] as $n) $ever['90000000' . str_pad((string)$n, 2, '0', STR_PAD_LEFT)] = 1; $ever['9000000001'] = 1; $ever['9000000002'] = 1;
$out = wa_ru_lane_order($items, $ct, $ever, $ord, $now - 90 * 86400); $ids = array_map(fn($x) => $x['id'], $out);
ok($ids === [4, 5, 1, 6, 7, 2, 8, 3, 9, 10, 11, 12], 'order B B A B B A B A then C: ' . implode(',', $ids));
ok(count($ids) === 12, 'nobody is lost');
$only = wa_ru_lane_order([['id' => 1, 'phone' => '9000000009']], [], ['9000000009' => 1], [], 0); ok(count($only) === 1, 'a single person in lane C stays');
echo "lanes ok\n";
