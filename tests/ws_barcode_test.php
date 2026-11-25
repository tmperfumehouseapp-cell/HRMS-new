<?php
// Shop wholesale · Barcodes (empmanagment/ws_barcode.php): wired into api.php, never handed out by itself, codes never meet the Store's.
$bad = 0; $root = __DIR__ . '/..';
$api = file_get_contents("$root/empmanagment/api.php"); $ws = file_get_contents("$root/empmanagment/ws_barcode.php");
$lay = file_get_contents("$root/assets/js/layout.js"); $pos = file_get_contents("$root/empmanagment/store_pos.php");
if (!preg_match("#strpos\(\(string\)\\\$action, 'ws\.'\) === 0\) \{ require __DIR__ \. '/ws_barcode\.php'#", $api)) { echo "api.php does not load ws_barcode.php for ws.*\n"; $bad++; }
if (strpos($api, "'ws.' => null") === false) { echo "ws.* must be checked by its own handler (action_permission null)\n"; $bad++; }
if (!preg_match("/\\\$noAuto = \[[^\]]*'ws_barcode'/", $api)) { echo "ws_barcode would be handed to every custom role automatically\n"; $bad++; }
if (strpos($api, "'ws_barcode' =>") === false) { echo "ws_barcode is not on the Roles page\n"; $bad++; }
if (strpos($ws, 'const WS_CODE_BASE = 800000;') === false) { echo "wholesale codes must start at 800000\n"; $bad++; }
if (strpos($pos, 'ST_CODE_BASE + $top >= 800000') === false) { echo "the Store generator must stop before the wholesale range\n"; $bad++; }
if (strpos($lay, "'retail_barcodes.html', 'wsb'") === false || strpos($lay, "need === 'wsb'") === false) { echo "the Barcodes tab is missing in Shop wholesale\n"; $bad++; }
foreach (['ws.me', 'ws.order_place', 'ws.make', 'ws.pack', 'ws.send', 'ws.receive', 'ws.bag', 'ws.check', 'ws.give', 'ws.overview', 'ws.find', 'ws.void', 'ws.people_save', 'ws.audit'] as $a) if (strpos($ws, "case '$a':") === false) { echo "missing action $a\n"; $bad++; }
if (!is_file("$root/retail_barcodes.html")) { echo "retail_barcodes.html missing\n"; $bad++; }
echo $bad ? "ws_barcode_test: $bad FAILED\n" : "ws_barcode_test: ok\n"; exit($bad ? 1 : 0);
