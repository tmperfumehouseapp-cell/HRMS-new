<?php
// "We wrote first" (owner, 26 Nov): a customer who had never written and answers OUR campaign / follow-up / win-back message is a Campaign query, not a fresh one.
// Run: php tests/wq_wrote_first_test.php
if (!extension_loaded('pdo_sqlite')) { echo "wq wrote first: skipped (no sqlite)\n"; exit(0); }
define('WQ_NOW', strtotime('2026-11-26 16:40:00'));
if (!function_exists('wa_is_ad_text')) { function wa_is_ad_text(string $t): bool { return (bool)preg_match('/tm\s*perfume\s*house|\bwant to (buy|order)\b/i', $t); } }
if (!function_exists('json_response')) { function json_response($d, int $c = 200): void { } }
$action = '';
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE wa_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, dir TEXT, mtype TEXT, body TEXT, status TEXT DEFAULT 'sent', who TEXT, emp_id INT DEFAULT 0, ts TEXT, reply_to TEXT DEFAULT '')");
require __DIR__ . '/../empmanagment/wq.php';
$ins = $pdo->prepare("INSERT INTO wa_messages (phone, dir, mtype, body, status, who, emp_id, ts, reply_to) VALUES (?,?,?,?,?,?,?,?,?)");
$T = strtotime('2026-11-26 16:34:00'); $fail = 0;
$ok = function ($name, $cond) use (&$fail) { if (!$cond) { $fail++; echo "FAIL: $name\n"; } };
// A: follow-up template today, the customer taps a button (an answer to it)
$ins->execute(['A', 'out', 'template', 'Kaunsa size chahiye?', 'delivered', 'followup', 0, '2026-11-26 16:33:00', '']); $ins->execute(['A', 'in', 'button', '100ML', 'received', 'customer', 0, '2026-11-26 16:34:00', 'wamid.x']);
$w = wq_we_wrote_first($pdo, 'A', $T); $ok('A follow-up found', $w && $w['who'] === 'followup');
$ok('A is not a link arrival', !wq_came_by_link($pdo, ['body' => '100ML', 'phone' => 'A', 'T' => $T]));
// B: campaign 7 days ago, a plain "Hi" today
$ins->execute(['B', 'out', 'template', 'Mega offer', 'read', 'campaign', 0, '2026-11-19 12:12:00', '']); $ins->execute(['B', 'in', 'text', 'Hi', 'received', 'customer', 0, '2026-11-26 16:34:00', '']);
$w = wq_we_wrote_first($pdo, 'B', $T); $ok('B old campaign found', $w && $w['who'] === 'campaign' && $w['ts'] === strtotime('2026-11-19 12:12:00'));
// C: nothing from us before → fresh
$ins->execute(['C', 'in', 'text', 'Hello', 'received', 'customer', 0, '2026-11-26 16:34:00', '']); $ok('C nothing before', wq_we_wrote_first($pdo, 'C', $T) === null);
// D: our template failed (never reached them) → fresh
$ins->execute(['D', 'out', 'template', 'x', 'failed', 'followup', 0, '2026-11-26 16:00:00', '']); $ok('D failed message does not count', wq_we_wrote_first($pdo, 'D', $T) === null);
// E: a person wrote first by hand, or the bot / an order message → not this rule
$ins->execute(['E', 'out', 'template', 'x', 'sent', 'emp', 7, '2026-11-26 16:00:00', '']); $ins->execute(['E', 'out', 'template', 'x', 'sent', 'order', 0, '2026-11-26 16:01:00', '']); $ins->execute(['E', 'out', 'text', 'x', 'sent', 'bot', 0, '2026-11-26 16:02:00', '']);
$ok('E person / order / bot do not count', wq_we_wrote_first($pdo, 'E', $T) === null);
// F: our message AFTER the query opened does not count
$ins->execute(['F', 'out', 'template', 'x', 'sent', 'followup', 0, '2026-11-26 16:50:00', '']); $ok('F later message does not count', wq_we_wrote_first($pdo, 'F', $T) === null);
// G: a campaign reached them, but they came by an ad / link with its ready-made text and did not answer our message → stays fresh
$ins->execute(['G', 'out', 'template', 'x', 'sent', 'campaign', 0, '2026-11-25 11:00:00', '']); $ins->execute(['G', 'in', 'text', 'Hey TMPerfumehouse, I need the 10 x 20ml combo for 2200', 'received', 'customer', 0, '2026-11-26 16:34:00', '']);
$ok('G link text, no reply: came by link', wq_came_by_link($pdo, ['body' => 'Hey TMPerfumehouse, I need the 10 x 20ml combo for 2200', 'phone' => 'G', 'T' => $T]));
// H: the same ready-made text but as a swipe-reply to our message → an answer to us
$ins->execute(['H', 'in', 'text', 'I want to buy 7 x 20 ml', 'received', 'customer', 0, '2026-11-26 16:34:00', 'wamid.y']);
$ok('H link text as a reply: not a link arrival', !wq_came_by_link($pdo, ['body' => 'I want to buy 7 x 20 ml', 'phone' => 'H', 'T' => $T]));
$ok('I plain words are never a link arrival', !wq_came_by_link($pdo, ['body' => 'Hi', 'phone' => 'B', 'T' => $T]));
echo $fail ? "wq wrote first: $fail FAILED\n" : "wq wrote first: ok\n"; exit($fail ? 1 : 0);
