<?php
/**
 * wq_rescue_bonus.php — the Rescue bonus of ONE person on ONE business day (money, in rupees), read from the Rescue ledger (wq_rescue_orders).
 * It is added to that day's commission wherever the commission is worked out (cm_day in boss2.php, coach_day in api.php), so My Sales, Commission,
 * Total Budgeting and the salary all include it. It never changes the normal commission of the order: it is an extra line (bonus % of the order total).
 * Standalone on purpose (no other WhatsApp file needed). PHP 7.4 safe. Never throws: no ledger yet = 0.
 */
if (!function_exists('wq_rescue_day_bonus')) {
function wq_rescue_day_bonus(PDO $pdo, string $day, int $emp): float
{
    static $memo = [];
    if (!isset($memo[$day])) {
        $memo[$day] = [];
        try {
            $start = function_exists('day_start_for') ? strtotime($day . ' ' . day_start_for($pdo, $day) . ':00') : strtotime($day . ' 00:00:00'); if (!$start) $start = strtotime($day . ' 00:00:00');
            $q = $pdo->prepare("SELECT emp_id, COALESCE(SUM(bonus_amt),0) b FROM wq_rescue_orders WHERE created_at >= ? AND created_at < ? GROUP BY emp_id");
            $q->execute([date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 86400)]);
            foreach ($q->fetchAll() as $r) $memo[$day][(int)$r['emp_id']] = round((float)$r['b'], 2);
        } catch (Throwable $e) { /* no ledger yet */ }
    }
    return (float)($memo[$day][$emp] ?? 0.0);
}
}
