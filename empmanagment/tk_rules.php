<?php
/**
 * tk_rules.php — the ticket-size goal as small PURE functions (no database), tested by tests/tk_test.php.
 *   tk_status      green (at or above the goal) · amber (within 10 %) · red (below) · '' (no orders yet)
 *   tk_need_next   how much the NEXT order must be so the day's ticket reaches the goal
 *   tk_hold        should this person's next fresh queries wait? (low ticket after several orders AND customers waiting)
 */
if (!function_exists('tk_status')) {

function tk_status(float $ticket, float $goal): string
{
    if ($goal <= 0 || $ticket <= 0) return '';
    if ($ticket >= $goal) return 'g';
    return $ticket >= 0.9 * $goal ? 'a' : 'r';
}

/** The smallest NEXT order that brings (or keeps) today's average (sales ÷ orders) at the goal: goal × (orders + 1) − sales. 0 when any order keeps it. */
function tk_need_next(float $sales, int $orders, float $goal): int
{
    if ($goal <= 0) return 0;
    $n = $goal * ($orders + 1) - $sales; return $n > 0 ? (int)ceil($n) : 0;
}

/** Hold fresh queries back for a moment: at least $minOrders orders today, a ticket below $below, and $wait or more customers still waiting. */
function tk_hold(float $ticket, int $orders, int $waiting, float $below, int $minOrders, int $wait): bool
{
    return $below > 0 && $orders >= $minOrders && $ticket > 0 && $ticket < $below && $waiting >= $wait;
}


/** The hold only makes sense when enough people reach the goal: if almost nobody does, every ticket is low and queries must keep going to everybody. $share = the minimum % of the working people that are at the goal. */
function tk_hold_share_ok(int $achievers, int $people, float $share): bool
{
    return $people > 0 && $achievers * 100 >= $share * $people - 1e-9;
}


/** A customer whose average order so far is at or above the goal is a big-order customer: his query goes to a seller who reaches the goal. */
function tk_is_vip(int $orders, float $spent, float $goal): bool { return $orders >= 1 && $goal > 0 && $spent / $orders >= $goal; }

/** Replies $factor times slower than the team's typical first reply. */
function tk_slow(?float $own, ?float $team, float $factor = 2.0): bool { return $own !== null && $team !== null && $own > 0 && $team > 0 && $own >= $team * $factor; }

}
