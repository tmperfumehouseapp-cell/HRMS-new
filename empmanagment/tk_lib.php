<?php
/** tk_lib.php — ticket goal: settings + one person's card (loaded by api.php lazily and by tk_goal.php). */
require_once __DIR__ . '/tk_rules.php';

function tk_cfg(PDO $pdo): array
{
    static $memo = null; if ($memo !== null && time() - $memo['_t'] < 20) return $memo;
    $g = function ($k, $d) use ($pdo) { try { $v = setting_get($pdo, $k, ''); return $v === '' || $v === null ? $d : $v; } catch (Throwable $e) { return $d; } };
    return $memo = ['_t' => time(), 'on' => (string)$g('tk_on', '1') !== '0', 'goal' => max(0, (float)$g('tk_goal', 2700)), 'pct' => max(0, min(50, (float)$g('tk_bonus_pct', 10))), 'min' => max(1, (int)$g('tk_min_orders', 3)),
        'offer' => trim((string)$g('tk_offer', '')), 'cust' => trim((string)$g('tk_cust_goal', '')), 'pay' => (string)$g('tk_pay', '1') !== '0', 'hold' => (string)$g('tk_hold', '1') !== '0', 'hold_share' => max(0, min(100, (float)$g('tk_hold_share', 30))), 'hold_below' => (float)$g('tk_hold_below', 2300), 'hold_orders' => max(1, (int)$g('tk_hold_orders', 4)), 'hold_wait' => max(1, (int)$g('tk_hold_wait', 3)), 'relief' => max(0, min(10, (float)$g('tk_ratio_relief', 3)))];
}

/** One person's ticket card for My Sales: [goal, ticket, status, orders, need_next, bonus_pct, bonus_est, min]. $m = the coach_day row. */
function tk_card(PDO $pdo, array $m): ?array
{
    $c = tk_cfg($pdo); if (!$c['on'] || $c['goal'] <= 0) return null;
    $n = (int)($m['orders'] ?? 0); $sales = (float)($m['sales'] ?? 0); $t = (float)($m['clean_ticket'] ?? 0); if ($t <= 0) $t = (float)($m['ticket'] ?? 0);
    $ok = $n >= $c['min'] && $t >= $c['goal'];
    return ['goal' => (int)$c['goal'], 'ticket' => (int)round($t), 'status' => $n >= 1 ? tk_status($t, $c['goal']) : '', 'orders' => $n, 'min' => $c['min'], 'need_next' => tk_need_next($sales, $n, $c['goal']), 'pct' => $c['pct'], 'hit' => $ok,
            'earned' => $c['pay'] && $ok, 'bonus_est' => ($c['pay'] && $ok) ? 0 : (int)round((float)($m['com_today'] ?? 0) * $c['pct'] / 100)];
}


/** One short paragraph for Claude's suggested reply: lift the order towards the ticket goal without inventing anything. Empty when the goal is off. */
function tk_hint(PDO $pdo): string
{
    $c = tk_cfg($pdo); if (!$c['on'] || $c['goal'] <= 0) return '';
    return ' TICKET GOAL: the shop wants the average order to reach about Rs ' . number_format((int)$c['goal']) . '. When the customer asks about a price, a discount or what to buy, gently offer ONE bigger option from the shop\'s saved quick replies or the chat (a bundle, a bigger size, or one extra item) before agreeing to a single small item; never pressure a customer who clearly wants one small item, and never invent a price or an offer.'
        . ($c['offer'] !== '' ? ' The shop\'s current bigger offer to suggest: ' . $c['offer'] . '.' : '') . ($c['cust'] !== '' ? ' A reward the CUSTOMER gets for a bigger order (you may say it): ' . $c['cust'] . '.' : '');
}
