<?php
/**
 * wa_plan.php — 🎯 DAILY BUDGET PLANNER (Campaigns → "Daily budget"). Admin / HR.
 *   wa.plan_get  GET budget (₹, default 50000)  → what it takes to spend that budget in one day, worked out from the last 7 days of campaign results:
 *   price per delivered marketing message (Billing rate), delivery rate (delivered / sent), reply rate (replies / delivered), the sends needed, Meta's daily room, the hourly speed of the numbers,
 *   the hours it takes, an hour-by-hour send plan, expected replies, the audience sizes (people who wrote in the last 7 / 30 / 90 days) and plain advice.
 *   Read only — it changes nothing. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

$__wp = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wp === 'wa.plan_get') {
    $auth = require_auth(); if (!wa_is_admin($pdo, $auth)) json_response(['error' => 'Only the owner / admin can use the planner.'], 403);
    $budget = max(1000, min(1000000, (float)($_GET['budget'] ?? 50000)));
    $R = function_exists('wab_rates') ? wab_rates($pdo) : ['marketing' => 1.09]; $price = max(0.05, (float)$R['marketing']);
    $today = date('Y-m-d'); $week = date('Y-m-d', strtotime('-6 days'));
    $T = ['sent' => 0, 'delivered' => 0, 'replied' => 0, 'paid_m' => 0, 'cost' => 0]; $spent = 0.0;
    try { if (function_exists('wab_report')) { $rep = wab_report($pdo, $week, $today, 'day', 'campaign'); $T = array_merge($T, (array)($rep['total'] ?? [])); $t0 = wab_report($pdo, $today, $today, 'day', 'all'); $spent = (float)($t0['total']['cost'] ?? 0); } } catch (Throwable $e) { }
    $sent7 = (int)$T['sent']; $dl7 = (int)$T['delivered'];
    $dr = $sent7 >= 500 ? $dl7 / max(1, $sent7) : 0.55; $dr = max(0.15, min(0.95, $dr));                       // delivery rate (delivered / sent)
    $rr = $dl7 >= 500 ? (int)$T['replied'] / max(1, $dl7) : 0.03; $rr = max(0.003, min(0.3, $rr));              // reply rate (replies / delivered)
    $cf = $dl7 >= 500 ? max(0.5, min(1.0, (int)$T['paid_m'] / max(1, $dl7))) : 1.0;                            // share of delivered that Meta charges
    $left = max(0, $budget - $spent);
    $needDel = $left / ($price * $cf); $needSent = $needDel / $dr;
    // capacity: Meta's room today + the hourly speed of the numbers that can send
    $room = 0; $speed = 0; $nums = [];
    try {
        $caps = wa_number_caps($pdo, wa_camp_health($pdo)); $port = $caps['_port'] ?? []; $room = (int)($port['left'] ?? 0);
        foreach ((array)($port['auto']['nums'] ?? []) as $nid => $n) { $nums[] = ['label' => (string)($n['label'] ?? ('Number ' . $nid)), 'speed' => (int)$n['cap'], 'left_hour' => (int)$n['left_hour']]; $speed += (int)$n['cap']; }
        if (!$nums) foreach ($caps as $k => $c) if ($k !== '_port' && (int)$c['left'] > 0) { $nums[] = ['label' => 'Number ' . $k, 'speed' => 5000, 'left_hour' => 5000]; $speed += 5000; }
    } catch (Throwable $e) { }
    if ($speed <= 0) $speed = 10000;
    $hoursLeftToday = max(1, 23 - (int)date('G')); $hoursNeed = $needSent / $speed;
    $plan = []; $rem = $needSent; $h = (int)date('G') + (int)(date('i') > 0 ? 1 : 0); if ($h < 9) $h = 10;
    for ($i = 0; $i < 14 && $rem > 0; $i++, $h++) { if ($h > 23) break; $n = (int)min($speed, ceil($rem)); $plan[] = ['hour' => sprintf('%02d:00', $h), 'sends' => $n, 'rs' => round($n * $dr * $price * $cf)]; $rem -= $n; }
    $fits = $rem <= 0 && $needSent <= max(1, $room);
    // audiences: people who wrote lately (the ones who deliver and reply best)
    $aud = ['d7' => 0, 'd30' => 0, 'd90' => 0];
    try { foreach (['d7' => 7, 'd30' => 30, 'd90' => 90] as $k => $d) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE optout = 0 AND last_in_at >= ?"); $q->execute([date('Y-m-d H:i:s', time() - $d * 86400)]); $aud[$k] = (int)$q->fetchColumn(); } } catch (Throwable $e) { }
    $running = 0; try { $running = (int)$pdo->query("SELECT COUNT(*) FROM wa_campaigns WHERE status = 'running'")->fetchColumn(); } catch (Throwable $e) { }
    $adv = [];
    if ($needSent > $room && $room > 0) $adv[] = 'Meta lets you start about ' . number_format($room) . ' more people today, but this budget needs about ' . number_format((int)$needSent) . ' sends at the current delivery rate (' . round($dr * 100) . '%). Raise the delivery rate (recent customers only) or spend the rest tomorrow.';
    if ($dr < 0.6) $adv[] = 'Only ' . round($dr * 100) . '% of what you send is delivered (Meta holds the rest). Send to people who wrote or ordered in the last 7–30 days and leave out anybody who got a campaign in the last 2–3 days — delivery then goes up and the same ₹ buys many more replies.';
    if ($running > 2) $adv[] = $running . ' campaigns are running together and they share the same hourly speed. Pause the extra ones so the main campaign gets the full speed.';
    if ($hoursNeed > $hoursLeftToday) $adv[] = 'At ' . number_format($speed) . ' sends an hour it needs about ' . round($hoursNeed, 1) . ' hours but only ' . $hoursLeftToday . ' are left today. Start earlier tomorrow (around 10 am) or fix the numbers that are stopped (Low quality / payment problem).';
    if (!$adv) $adv[] = 'Looks doable today: start the main campaign now and keep the others paused.';
    json_response(['ok' => true, 'budget' => $budget, 'spent_today' => round($spent), 'left' => round($left), 'price' => round($price, 2), 'charged_share' => round($cf, 2), 'delivery_rate' => round($dr, 3), 'reply_rate' => round($rr, 4), 'based_on' => ['sent' => $sent7, 'delivered' => $dl7],
        'need_delivered' => (int)round($needDel), 'need_sent' => (int)round($needSent), 'expected_replies' => (int)round($needDel * $rr), 'room_today' => $room, 'speed_per_hour' => $speed, 'hours_needed' => round($hoursNeed, 1), 'hours_left_today' => $hoursLeftToday, 'fits' => $fits,
        'plan' => $plan, 'numbers' => $nums, 'audience' => $aud, 'running' => $running, 'advice' => $adv]);
}
