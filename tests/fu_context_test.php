<?php
// The follow-up ladder must not start after our thanks for his feedback, after a plain thanks to our care message, or for a customer who just shopped and asked nothing (owner, 24 Nov: 'Very good' -> thanked -> 'step 1 of 10 ... Doubt hai' 3 minutes later).
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
if (!preg_match('/\nfunction wag_fu_context_stop\(.*?\n\}\n/s', $src, $m)) { echo "FAIL cannot find wag_fu_context_stop\n"; exit(1); } eval($m[0]);
$pdo = new PDO('sqlite::memory:'); $pdo->exec("CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, dir TEXT, mtype TEXT, body TEXT, ts TEXT)"); $pdo->exec("CREATE TABLE shop_orders (phone TEXT, created_at TEXT)"); $pdo->exec("CREATE TABLE wa_cust (phone TEXT, n INTEGER)");
$fail = 0; $chk = function ($n, $c) use (&$fail) { if (!$c) { echo "FAIL $n\n"; $fail++; } };
$add = function ($ph, $dir, $type, $body, $ts) use ($pdo) { $pdo->prepare("INSERT INTO wa_messages (phone, dir, mtype, body, ts) VALUES (?, ?, ?, ?, ?)")->execute([$ph, $dir, $type, $body, $ts]); };
$now = time(); $t = function ($m) use ($now) { return date('Y-m-d H:i:s', $now - $m * 60); };
// (a) experience message, 'Very good', the bot thanked him
$add('919000000001', 'out', 'template', 'Hi Abc, thank you for shopping with TM Perfume House. How was your experience with us overall?', $t(240)); $add('919000000001', 'in', 'text', 'Very good', $t(10)); $add('919000000001', 'sys', 'text', '🤖 The bot thanked the customer for his experience feedback (no offer, no price)', $t(9));
$chk('(a) thanked his feedback -> no follow-up', wag_fu_context_stop($pdo, '919000000001', 'Very good') === true);
// (a2) he wrote again AFTER the thanks with a question -> follow-ups may go
$add('919000000001', 'in', 'text', 'mujhe 5 bottle chahiye', $t(2)); $chk('(a2) a new question after the thanks -> ladder allowed', wag_fu_context_stop($pdo, '919000000001', 'mujhe 5 bottle chahiye') === false);
// (b) plain thanks to our customer-care message
$add('919000000002', 'out', 'template', 'Hello Tejas, this is the TM Perfume House customer care team. If you need help with an order, a delivery or a perfume you bought from us, tap a button below.', $t(30)); $add('919000000002', 'in', 'text', 'Thanks', $t(20));
$chk('(b) Thanks to our care message -> no follow-up', wag_fu_context_stop($pdo, '919000000002', 'Thanks') === true);
// (b2) thanks to a sales message -> normal
$add('919000000003', 'out', 'template', 'Hi sir, special price for 5 x 50 ml combo, order now', $t(30)); $add('919000000003', 'in', 'text', 'ok', $t(20));
$chk('(b2) ok to a sales message -> ladder allowed', wag_fu_context_stop($pdo, '919000000003', 'ok') === false);
// (c) ordered 3 days ago, asks nothing / asks a price
$pdo->prepare("INSERT INTO shop_orders (phone, created_at) VALUES (?, ?)")->execute(['+91 90000 00004', date('Y-m-d H:i:s', $now - 3 * 86400)]); $add('919000000004', 'in', 'text', 'hello', $t(5));
$chk('(c) ordered 3 days ago and asked nothing -> no follow-up', wag_fu_context_stop($pdo, '919000000004', 'hello') === true);
$chk('(c2) ordered 3 days ago but asks a price -> ladder allowed', wag_fu_context_stop($pdo, '919000000004', '5 x 50 ml price kya hai') === false);
$add('919000000005', 'in', 'text', 'hello', $t(5)); $chk('a customer with nothing special -> ladder allowed', wag_fu_context_stop($pdo, '919000000005', 'hello') === false);
$pdo->prepare("INSERT INTO shop_orders (phone, created_at) VALUES (?, ?)")->execute(['+91 90000 00006', date('Y-m-d H:i:s', $now - 100 * 86400)]); $add('919000000006', 'in', 'text', 'hi', $t(5));
$chk('(c3) ordered 100 days ago and asked nothing -> no follow-up', wag_fu_context_stop($pdo, '919000000006', 'hi') === true);
$pdo->exec("INSERT INTO wa_cust (phone, n) VALUES ('919000000007', 3)"); $add('919000000007', 'in', 'text', 'hello', $t(5));
$chk('(c4) bought customer in the customer table, asked nothing -> no follow-up', wag_fu_context_stop($pdo, '919000000007', 'hello') === true);
$chk('(c5) the same customer asks a size -> ladder allowed', wag_fu_context_stop($pdo, '919000000007', '50 ml chahiye') === false);
echo $fail ? "follow-up context FAILED ($fail)\n" : "follow-up context ok\n"; exit($fail ? 1 : 0);
