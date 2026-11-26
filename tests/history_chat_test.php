<?php
// A pre-made / bulk message must keep a History chat in History: closed + (last message over 24 h old, opted out, or Stop / Not now = tag 'declined').
$src = file_get_contents(__DIR__ . '/../empmanagment/wa.php'); $i = strpos($src, 'function wa_is_history_chat'); $j = strpos($src, 'function wa_manual_assign'); eval(substr($src, $i, $j - $i));
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE wa_ctags (phone TEXT, tag TEXT)"); $pdo->exec("INSERT INTO wa_ctags VALUES ('9000000001', 'declined')");
$now = date('Y-m-d H:i:s', time() - 3600); $old = date('Y-m-d H:i:s', time() - 3 * 86400);
assert(wa_is_history_chat($pdo, ['phone' => '9000000001', 'status' => 'closed', 'last_in_at' => $now, 'optout' => 0]) === true);    // Not now an hour ago
assert(wa_is_history_chat($pdo, ['phone' => '9000000002', 'status' => 'closed', 'last_in_at' => $now, 'optout' => 0]) === false);   // closed today, no decline: a normal closed chat
assert(wa_is_history_chat($pdo, ['phone' => '9000000002', 'status' => 'closed', 'last_in_at' => $old, 'optout' => 0]) === true);    // old
assert(wa_is_history_chat($pdo, ['phone' => '9000000002', 'status' => 'closed', 'last_in_at' => $now, 'optout' => 1]) === true);    // opted out
assert(wa_is_history_chat($pdo, ['phone' => '9000000001', 'status' => 'open', 'last_in_at' => $now, 'optout' => 0]) === false);     // open chats are never History
echo "history chat test: ok\n";
