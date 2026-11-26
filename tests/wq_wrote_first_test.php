<?php
// "We wrote first" (owner, 26 Nov): a customer who had never written and answers OUR campaign / follow-up / win-back message is a Campaign query, not a fresh one.
// Run: php tests/wq_wrote_first_test.php
if (!extension_loaded('pdo_sqlite')) { echo "wq wrote first: skipped (no sqlite)\n"; exit(0); }
define('WQ_NOW', strtotime('2026-11-26 16:40:00'));
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
// G: a campaign reached them and they come with a website button's ready-made text → still "we wrote first" (owner, 26 Nov: a campaign went to him above → Campaign, not fresh)
$ins->execute(['G', 'out', 'template', 'x', 'delivered', 'campaign', 0, '2026-11-25 11:00:00', '']); $ins->execute(['G', 'in', 'text', 'Hey TMPerfumehouse, I need the 10 x 20ml combo for 2200', 'received', 'customer', 0, '2026-11-26 16:34:00', '']);
$w = wq_we_wrote_first($pdo, 'G', $T); $ok('G a campaign that reached him counts, website text or not', $w && $w['who'] === 'campaign');
// H: MANY campaigns went to him and every one failed, then he comes with the website text → nothing reached him: a new customer, fresh
foreach (['2026-11-20 11:00:00', '2026-11-22 11:00:00', '2026-11-25 11:00:00'] as $ft) $ins->execute(['H', 'out', 'template', 'x', 'failed', 'campaign', 0, $ft, '']);
$ins->execute(['H', 'in', 'text', 'Hi, I want to place an order', 'received', 'customer', 0, '2026-11-26 16:34:00', '']);
$ok('H only failed sends: nothing reached him', wq_we_wrote_first($pdo, 'H', $T) === null);
// I: two failed and one delivered → it reached him
$ins->execute(['I', 'out', 'template', 'x', 'failed', 'campaign', 0, '2026-11-20 11:00:00', '']); $ins->execute(['I', 'out', 'template', 'x', 'read', 'followup', 0, '2026-11-21 11:00:00', '']); $ins->execute(['I', 'out', 'template', 'x', 'failed', 'campaign', 0, '2026-11-25 11:00:00', '']);
$w = wq_we_wrote_first($pdo, 'I', $T); $ok('I one send reached him among failed ones', $w && $w['who'] === 'followup');
$ok('the old link exception is gone', !function_exists('wq_came_by_link'));
// wq_decide — the three cases the owner named (26 Nov), each a customer who never wrote before and comes with a website message:
$ctx = function (string $ph, array $tags) use ($T) { return ['q' => ['id' => 1, 'phone' => $ph, 'opened_at' => date('Y-m-d H:i:s', $T)], 'T' => $T, 'phone' => $ph, 'c' => ['source' => '', 'last_number_id' => 0], 'body' => 'Hi, I want to place an order', 'body_ts' => '', 'ref_now' => false, 'ref_head' => '',
    'first_query' => true, 'prev_in' => 0, 'story' => [], 'tags' => $tags, 'camp_days' => 3, 'wake_days' => 60, 'camp' => null]; };
$imp = [['tag' => 'campaign', 'src' => 'import', 'at' => '2026-11-18 10:00:00']];
$d1 = wq_decide($pdo, $ctx('C', [])); $ok('new customer, nothing sent → fresh (new)', $d1['type'] === 'new');
$d2 = wq_decide($pdo, $ctx('H', $imp)); $ok('imported for campaigns, every send failed → fresh (new), got ' . $d2['type'], $d2['type'] === 'new');
$d3 = wq_decide($pdo, $ctx('G', $imp)); $ok('a campaign reached him → campaign, got ' . $d3['type'], $d3['type'] === 'campaign');
$d4 = wq_decide($pdo, $ctx('G', [])); $ok('a campaign reached him, no tag → campaign (we wrote first)', $d4['type'] === 'campaign' && strpos($d4['reason'], 'we wrote first') !== false);
echo $fail ? "wq wrote first: $fail FAILED\n" : "wq wrote first: ok\n"; exit($fail ? 1 : 0);
