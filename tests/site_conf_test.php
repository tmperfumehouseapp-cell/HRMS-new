<?php
// Website order confirmation (wa_site_conf.php): who gets a message. Pure decision function + phone parsing; no database, no Meta.
date_default_timezone_set('Asia/Kolkata');
require dirname(__DIR__) . '/empmanagment/wa_site_conf.php';
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$from = strtotime('2026-11-24 00:00:00'); $cfg = ['on' => true, 'from' => '2026-11-24 00:00:00', 'from_ts' => $from, 'num' => '0275', 'skip_src' => 'whatsapp, chat'];
$o = fn(array $x = []) => array_replace_recursive(['number' => '1001', 'placed_at' => '2026-11-24 10:00:00', 'source' => 'web', 'customer' => ['name' => 'Asha Rao', 'phone' => '+91 98765 43210']], $x);

ok(wsc_phone($o()) === '9876543210', '+91 number → 10 digits');
ok(wsc_phone($o(['customer' => ['phone' => '']])) === '', 'no phone');
ok(wsc_phone(['customer' => ['phone' => ''], 'shipping_address' => ['phone' => '098765 43210']]) === '9876543210', 'phone from the shipping address (leading 0)');
ok(wsc_phone($o(['customer' => ['phone' => '12345']])) === '', 'short phone');

ok(wsc_decide($o(), false, $cfg) === ['send'], 'a direct order of a customer with no chat is sent');
ok(wsc_decide($o(), true, $cfg)[0] === 'skip', 'a customer with a chat is left to the normal flow');
ok(wsc_decide($o(['source' => 'WhatsApp']), false, $cfg)[0] === 'skip', 'a chat-link order (source) is skipped, any letter case');
ok(wsc_decide($o(['source' => 'whatsapp-ad']), false, $cfg) === ['send'], 'the source must match exactly');
ok(wsc_decide($o(['placed_at' => '2026-11-23 23:59:59']), false, $cfg)[0] === 'skip', 'an order before the start time gets nothing');
ok(wsc_decide($o(['placed_at' => '2026-11-24 00:00:00']), false, $cfg) === ['send'], 'an order AT the start time is included');
ok(wsc_decide($o(['number' => '']), false, $cfg)[0] === 'skip', 'no order number');
ok(wsc_decide($o(['customer' => ['phone' => 'abc']]), false, $cfg)[0] === 'skip', 'no valid phone');
ok(wsc_decide($o(), false, ['from_ts' => 0] + $cfg)[0] === 'skip', 'no start time = nothing is sent');
ok(wsc_decide($o(['source' => 'x']), false, ['skip_src' => ''] + $cfg) === ['send'], 'empty skip list skips nothing');

// the claim (one order = one message) on SQLite: the second INSERT IGNORE of the same order changes no row
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE wa_site_conf (order_no TEXT PRIMARY KEY, state TEXT)");
$i = $pdo->prepare("INSERT OR IGNORE INTO wa_site_conf (order_no, state) VALUES (?, 'sending')");
$i->execute(['1001']); ok($i->rowCount() === 1, 'first claim');
$i->execute(['1001']); ok($i->rowCount() === 0, 'second claim of the same order changes nothing');
echo "OK site_conf\n";
