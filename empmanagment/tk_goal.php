<?php
/**
 * tk_goal.php — TICKET-SIZE GOAL (one number, shown everywhere). Settings (hrms_settings):
 *   tk_on (1) · tk_goal (2700) · tk_bonus_pct (10: shown as an ESTIMATE only until the commission engine pays it) · tk_min_orders (3) · tk_hold (0: off) · tk_hold_below (2300) · tk_hold_orders (4) · tk_hold_wait (3) · tk_ratio_relief (3 points)
 * The ticket is the one the Control Tower already uses (clean ticket when known, else sales ÷ orders of the business day).
 */
require_once __DIR__ . '/tk_lib.php';

// the admin's two controls (Team performance → Auto assign): the goal, and the hold switch
$__tkA = (string)($action ?? ($_GET['action'] ?? ''));
if ($__tkA === 'wq.tk_me') {   // the salesperson's own ticket for the WhatsApp panel chip: reads the cached day rows only (cheap)
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $card = null;
    try { if ($me > 0 && function_exists('waa_cp')) { $r = (array)(waa_cp($pdo)[$me] ?? []); $card = tk_card($pdo, ['orders' => (int)($r['n'] ?? 0), 'sales' => (float)($r['s'] ?? 0), 'clean_ticket' => (float)($r['ctk'] ?? 0), 'ticket' => (float)($r['tk'] ?? 0), 'com_today' => 0]); } } catch (Throwable $e) { }
    $slow = null; try { if ($me > 0 && function_exists('waa_state')) { $x = (array)(waa_state($pdo)['e'][$me] ?? []); if ((int)($x['sh'] ?? 0) > time()) $slow = ['state' => 'hold', 'until' => (int)$x['sh'], 'mins' => (int)ceil(((int)$x['sh'] - time()) / 60)];
        elseif ((int)($x['sw'] ?? 0) > 0 && (int)($x['sw']) > time() - 3600) $slow = ['state' => 'warn', 'since' => (int)$x['sw']]; } } catch (Throwable $e) { }
    json_response(['ok' => true, 'tk' => $card, 'slow' => $slow]);
}
if ($__tkA === 'wq.ad_me') {   // ➕ the salesperson's OWN add-on effort today (read from the chats after the orders): four counts, stars, add-on rupees, the orders where nothing was offered, the words that worked. Only the person's own rows.
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    $out = ['ok' => true, 'n' => [0, 0, 0, 0], 'sz' => [0, 0, 0, 0], 'stars' => null, 'addon_rs' => 0, 'missed' => [], 'tip' => '', 'team' => [0, 0, 0, 0], 'read' => 0];
    try {
        if ($me > 0 && function_exists('ora_tables') && ora_tables($pdo)) {
            $day = biz_today($pdo); $st = 0.0; $rows = [];
            $q = $pdo->prepare("SELECT order_key, order_name, rating, tried, worked, tries, sz_tried, sz_worked, sz_tries, reason, phone FROM order_rate_auto WHERE employee_id = ? AND day = ? AND rating IS NOT NULL AND sz_ok = 1 ORDER BY at DESC LIMIT 400"); $q->execute([$me, $day]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            try { if (function_exists('addons_attach')) addons_attach($pdo, $rows, 'order_name'); } catch (Throwable $e) { }
            foreach ($rows as $r) {
                $lv = ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries']); $out['n'][$lv]++; $out['sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; $st += (float)$r['rating'] / 2; $out['addon_rs'] += (float)($r['addon_total'] ?? 0);
                if ($lv === 0 && count($out['missed']) < 5) { $out['missed'][] = ['order' => (string)$r['order_name'], 'phone' => (string)$r['phone'], 'why' => mb_substr((string)$r['reason'], 0, 140)]; }
            }
            $n = array_sum($out['n']); $out['read'] = $n; $out['stars'] = $n ? round($st / $n, 1) : null; $out['addon_rs'] = round($out['addon_rs']);
            $t = $pdo->prepare("SELECT tip FROM order_rate_auto WHERE employee_id = ? AND worked = 1 AND tip <> '' AND at >= ? ORDER BY at DESC LIMIT 1"); $t->execute([$me, date('Y-m-d H:i:s', time() - 14 * 86400)]); $out['tip'] = (string)$t->fetchColumn();
            $tm = $pdo->prepare("SELECT tried, worked, tries FROM order_rate_auto WHERE day = ? AND rating IS NOT NULL AND sz_ok = 1 LIMIT 3000"); $tm->execute([$day]); foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $r) $out['team'][ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries'])]++;
        }
    } catch (Throwable $e) { error_log('wq.ad_me: ' . $e->getMessage()); }
    json_response($out);
}
if ($__tkA === 'wq.tk_cfg') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm && function_exists('wa_boss_ok') && wa_boss_ok($pdo, $auth)) $adm = true;
    if (!$adm) json_response(['error' => 'Only Admin, HR or a Sales Head can change the ticket goal.'], 403);
    if ($isPost) { $in = json_input();
        if (isset($in['goal'])) setting_set($pdo, 'tk_goal', (string)max(0, min(50000, (int)$in['goal'])));
        if (isset($in['hold'])) setting_set($pdo, 'tk_hold', !empty($in['hold']) ? '1' : '0');
        if (isset($in['pct'])) setting_set($pdo, 'tk_bonus_pct', (string)max(0, min(50, (float)$in['pct'])));
        if (isset($in['offer'])) setting_set($pdo, 'tk_offer', mb_substr(trim((string)$in['offer']), 0, 300));
        if (isset($in['cust'])) setting_set($pdo, 'tk_cust_goal', mb_substr(trim((string)$in['cust']), 0, 300));
        if (isset($in['pay'])) setting_set($pdo, 'tk_pay', !empty($in['pay']) ? '1' : '0');
    }
    $c = tk_cfg($pdo); json_response(['ok' => true, 'goal' => (int)$c['goal'], 'hold' => $c['hold'], 'pct' => $c['pct'], 'offer' => $c['offer'], 'cust' => $c['cust']]);
}
