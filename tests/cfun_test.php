<?php
// Rules of the customer funnel (empmanagment/wa_cfun_rules.php). Run: php tests/cfun_test.php   (exit code 1 = a rule changed by mistake)
require dirname(__DIR__) . '/empmanagment/wa_cfun_rules.php';
$now = strtotime('2026-11-14 12:00:00'); $bad = 0;
$eq = function ($name, $got, $want) use (&$bad) { if ($got !== $want) { echo "FAIL $name: got " . json_encode($got) . ", want " . json_encode($want) . "\n"; $bad++; } };
// customer value
$eq('never ordered', cfun_value(0, null, $now), '');
$eq('new', cfun_value(1, '2026-11-01 10:00:00', $now), 'new');
$eq('one-time', cfun_value(1, '2026-08-20 10:00:00', $now), 'one');
$eq('single order, 150 days = at risk', cfun_value(1, '2026-06-17 10:00:00', $now), 'atrisk');
$eq('single order, 200 days = lapsed', cfun_value(1, '2026-04-28 10:00:00', $now), 'lapsed');
$eq('repeat', cfun_value(2, '2026-10-01 10:00:00', $now), 'repeat');
$eq('loyal', cfun_value(3, '2026-10-01 10:00:00', $now), 'loyal');
$eq('repeat gone quiet = at risk', cfun_value(4, '2026-07-01 10:00:00', $now), 'atrisk');
$eq('no date = past buyer (not guessed)', cfun_value(2, null, $now), 'past');
// every value the rules can give must fit the table columns (VARCHAR 8) and every tag key must fit the tag column (VARCHAR 40)
foreach (['new', 'one', 'repeat', 'loyal', 'atrisk', 'lapsed', 'past'] as $v) if (strlen($v) > 8) { echo "FAIL value $v is longer than the column\n"; $bad++; }
foreach (['ok', 'optout', 'notwa', 'held', 'stopped', 'dead'] as $v) if (strlen($v) > 8) { echo "FAIL health $v is longer than the column\n"; $bad++; }
foreach (['hot', 'interested', 'considering', 'not_interested'] as $v) if (strlen($v) > 16) { echo "FAIL intent $v is longer than the column\n"; $bad++; }
// import check: last order
$eq('imp order: none', cfun_imp_order(0, '', $now), 'none'); $eq('imp order: unknown date', cfun_imp_order(2, '', $now), 'oun'); $eq('imp order: 10 days', cfun_imp_order(1, date('Y-m-d H:i:s', $now - 10 * 86400), $now), 'o30'); $eq('imp order: 45 days', cfun_imp_order(1, date('Y-m-d H:i:s', $now - 45 * 86400), $now), 'o60'); $eq('imp order: 100 days', cfun_imp_order(1, date('Y-m-d H:i:s', $now - 100 * 86400), $now), 'o60p');
// import check buckets
$eq('imp: unknown number is new', cfun_imp_bucket(null, false, $now), 'new'); $eq('imp: in contacts only', cfun_imp_bucket(null, true, $now), 'never');
$eq('imp: opt-out wins', cfun_imp_bucket(['optout' => 1, 'grp' => 'ord2', 'health' => 'optout'], true, $now), 'optout');
$eq('imp: not on WhatsApp', cfun_imp_bucket(['grp' => 'failed', 'health' => 'notwa'], true, $now), 'notwa');
$eq('imp: buyer is never "failed"', cfun_imp_bucket(['grp' => 'ord1', 'health' => 'held'], true, $now), 'ord1');
$eq('imp: held', cfun_imp_bucket(['grp' => 'failed', 'health' => 'held'], true, $now), 'held'); $eq('imp: dead = failed', cfun_imp_bucket(['grp' => 'failed', 'health' => 'dead'], true, $now), 'failed');
$eq('imp: messaged 2 days ago', cfun_imp_bucket(['grp' => 'deliv', 'health' => 'ok', 'last_out' => date('Y-m-d H:i:s', $now - 2 * 86400)], true, $now), 'recent');
$eq('imp: delivered, no reply', cfun_imp_bucket(['grp' => 'deliv', 'health' => 'ok', 'last_out' => date('Y-m-d H:i:s', $now - 20 * 86400)], true, $now), 'deliv');
// movements
$mv = function ($o, $n) { return cfun_moves($o, $n); };
$eq('mv: no old row', $mv(null, ['bucket' => 'delivered', 'out_n' => 1, 'orders_n' => 0]), []);
$eq('mv: failed -> delivered after a retry', $mv(['bucket' => 'failed', 'out_n' => 3, 'orders_n' => 0], ['bucket' => 'delivered', 'out_n' => 4, 'orders_n' => 0]), ['f>d', 'retry']);
$eq('mv: failed -> waiting (retry sent)', $mv(['bucket' => 'failed', 'out_n' => 3, 'orders_n' => 0], ['bucket' => 'pending', 'out_n' => 4, 'orders_n' => 0]), ['f>s', 'retry']);
$eq('mv: delivered -> failed', $mv(['bucket' => 'delivered', 'out_n' => 2, 'orders_n' => 0], ['bucket' => 'failed', 'out_n' => 5, 'orders_n' => 0]), ['d>f']);
$eq('mv: waiting -> delivered', $mv(['bucket' => 'pending', 'out_n' => 2, 'orders_n' => 0], ['bucket' => 'delivered', 'out_n' => 2, 'orders_n' => 0]), ['s>d']);
$eq('mv: never messaged -> messaged', $mv(['bucket' => 'untried', 'out_n' => 0, 'orders_n' => 0], ['bucket' => 'pending', 'out_n' => 1, 'orders_n' => 0]), ['u>m']);
$eq('mv: failed -> replied', $mv(['bucket' => 'failed', 'out_n' => 3, 'orders_n' => 0], ['bucket' => 'replied', 'out_n' => 3, 'orders_n' => 0]), ['x>r']);
$eq('mv: first order', $mv(['bucket' => 'delivered', 'out_n' => 1, 'orders_n' => 0], ['bucket' => 'delivered', 'out_n' => 1, 'orders_n' => 1]), ['ord']);
$eq('mv: second order', $mv(['bucket' => 'replied', 'out_n' => 1, 'orders_n' => 1], ['bucket' => 'replied', 'out_n' => 1, 'orders_n' => 2]), ['ord2']);
$eq('mv: nothing changed', $mv(['bucket' => 'delivered', 'out_n' => 1, 'orders_n' => 1], ['bucket' => 'delivered', 'out_n' => 1, 'orders_n' => 1]), []);
foreach (cfun_tag_keys() as $k) if (strlen($k) > 40) { echo "FAIL tag $k is too long\n"; $bad++; }
// reach health
$eq('opted out', cfun_health(1, 'replied', 0), 'optout');
$eq('not failed = ok', cfun_health(0, 'delivered', 0), 'ok');
$eq('not on whatsapp', cfun_health(0, 'failed', 131026), 'notwa');
$eq('held', cfun_health(0, 'failed', 131049), 'held');
$eq('stopped', cfun_health(0, 'failed', 131050), 'stopped');
$eq('dead', cfun_health(0, 'failed', 0), 'dead');
// score
[$s, $p] = cfun_score(['orders' => 3, 'spent' => 20000, 'in_n' => 5, 'replied' => 1, 'last_in' => '2026-11-10 10:00:00', 'last_order' => '2026-11-01 10:00:00', 'health' => 'ok', 'deliv_n' => 4, 'intent' => 'hot'], $now);
$eq('best customer = 100', $s, 100); $eq('parts add up', array_sum($p), 100);
[$s0] = cfun_score([], $now); $eq('empty = 0', $s0, 0);
[$s1] = cfun_score(['health' => 'ok', 'deliv_n' => 2], $now); $eq('only delivered = 10', $s1, 10);
[$s2] = cfun_score(['intent' => 'not_interested', 'health' => 'dead'], $now); $eq('never below 0', $s2, 0);
$eq('tier a', cfun_tier(70), 'a'); $eq('tier b', cfun_tier(45), 'b'); $eq('tier c', cfun_tier(20), 'c'); $eq('tier d', cfun_tier(1), 'd'); $eq('no tier', cfun_tier(0), '');
// tags
[$sc, $pa] = cfun_score(['orders' => 1, 'spent' => 2000, 'health' => 'ok', 'deliv_n' => 1], $now); $tg = cfun_tags('new', 'ok', $sc, $pa, ['orders' => 1]);
$eq('tags: value + score, no reach tag for people who can receive', array_map(function ($x) { return $x[0]; }, $tg), ['value_new', 'score_' . cfun_tier($sc)]);
$eq('tags: reach problem is tagged', array_map(function ($x) { return $x[0]; }, cfun_tags('', 'notwa', 0, ['engagement' => 0, 'buying' => 0, 'spend' => 0, 'reach' => 0, 'interest' => 0], [])), ['reach_notwa']);
foreach (cfun_tag_keys() as $k) if (!preg_match('/^[a-z_]+$/', $k)) { echo "FAIL tag key $k\n"; $bad++; }
echo $bad ? "cfun test: $bad problem(s)\n" : "cfun test: ok\n"; exit($bad ? 1 : 0);
