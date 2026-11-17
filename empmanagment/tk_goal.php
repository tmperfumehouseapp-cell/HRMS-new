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
    try { if ($me > 0 && function_exists('waa_cp')) { $r = (array)(waa_cp($pdo)[$me] ?? []); if ($r) $card = tk_card($pdo, ['orders' => (int)($r['n'] ?? 0), 'sales' => (float)($r['s'] ?? 0), 'clean_ticket' => (float)($r['ctk'] ?? 0), 'ticket' => (float)($r['tk'] ?? 0), 'com_today' => 0]); } } catch (Throwable $e) { }
    json_response(['ok' => true, 'tk' => $card]);
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
        if (isset($in['pay'])) setting_set($pdo, 'tk_pay', !empty($in['pay']) ? '1' : '0');
    }
    $c = tk_cfg($pdo); json_response(['ok' => true, 'goal' => (int)$c['goal'], 'hold' => $c['hold'], 'pct' => $c['pct']]);
}
