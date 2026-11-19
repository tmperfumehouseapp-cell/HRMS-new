<?php
/**
 * wq_auto_rules.php — the rules of AUTO ASSIGN (Sales → Team performance) as small PURE functions (no database), so they can be tested (tests/waa_test.php).
 *
 *   waa_below         is a salesperson's ratio below the allowed band? (company average − 5 points, judged after 6 fresh queries)
 *   waa_speed_factor  how much of a normal share a slow replier gets (twice as slow = half)
 *   waa_button        is the button ON? (punch-in · Admin / Sales Head choice · the ratio rule)
 *   waa_weight_fresh  share of fresh queries: in proportion to the day's target × reply speed
 *   waa_weight_camp   share of campaign queries: equal for everybody, 2× for the ones below the band
 *   waa_pick          who gets the next query (everybody reaches the minimum first, then the fairest share)
 *   waa_night / waa_night_block   overnight (12 am – 10 am): a person below the band takes at most 5 from Requests
 */
if (!defined('WAA_BAND')) { define('WAA_BAND', 0.05); define('WAA_MIN_Q', 6); define('WAA_NIGHT_MAX', 5); define('WAA_MAX_WAIT', 10); }   // allowed: average down to 5 points below · 6 fresh queries before the ratio is judged · 5 overnight picks from Requests
if (!function_exists('waa_below')) {


/** The lowest ratio that is still allowed (a fraction, 0.25 → 0.20). */
function waa_floor(float $team): float { return max(0.0, $team - WAA_BAND); }

/** Below the band? Only judged after $min fresh queries; with no company ratio yet nobody is below. */
function waa_below(int $q, int $qo, float $team, int $min = WAA_MIN_Q): bool
{
    if ($q < $min || $team <= 0) return false;
    return ($qo / $q) < waa_floor($team) - 1e-9;
}

/** 1 = normal. Takes $own minutes where the team takes $team: share = team / own (twice as slow → ½, three times → ⅓). Unknown speed = normal. */
function waa_speed_factor(?float $own, ?float $team): float
{
    if (!$own || !$team || $own <= $team) return 1.0;
    return $team / $own;
}

/**
 * [on, why-code]. $p: present (punched in), m ('on' | 'off' | '' = the Admin / Sales Head choice), ao (the ratio rule switched it off earlier today), below (below the band right now).
 * Admin / Sales Head ON wins over everything; Admin / Sales Head OFF stops everything; the ratio rule switches off by itself and stays off until Admin / Sales Head turn it on.
 */
function waa_button(array $p): array
{
    if (empty($p['present'])) return [false, 'not_in'];
    $m = (string)($p['m'] ?? '');
    if ($m === 'off') return [false, 'admin_off'];
    if ($m === 'on') return [true, 'admin_on'];
    if (!empty($p['ao']) || !empty($p['below'])) return [false, 'ratio'];
    return [true, 'on'];
}

/** Fresh queries: in proportion to the target (a person with no target counts as the average target); reply speed lowers it unless Admin / Sales Head turned the button ON. */
function waa_weight_fresh(array $p, float $avgTarget): float
{
    $t = (float)($p['t'] ?? 0); if ($t <= 0) $t = $avgTarget > 0 ? $avgTarget : 1.0;
    if ((string)($p['m'] ?? '') !== 'on') $t *= waa_speed_factor($p['speed'] ?? null, $p['team_speed'] ?? null);
    return max(0.0001, $t);
}

/** Campaign queries: equal for everybody; 2× for a person below the band (not when Admin / Sales Head turned the button ON: no other rule applies to them). */
function waa_weight_camp(array $p): float
{
    return ((string)($p['m'] ?? '') !== 'on' && (!empty($p['ao']) || !empty($p['below']))) ? 2.0 : 1.0;
}

/**
 * $c = [emp => ['w' => weight, 'cnt' => queries so far today]]. Anybody under $min goes first (lowest share first); otherwise the one furthest below their fair share
 * ((cnt + 1) / weight is the smallest). Returns 0 when nobody can take it.
 */
function waa_pick(array $c, int $min = 0): int
{
    if (!$c) return 0;
    $pool = $c; if ($min > 0) { $low = array_filter($c, function ($x) use ($min) { return (int)$x['cnt'] < $min; }); if ($low) $pool = $low; }
    $best = 0; $bs = null;
    foreach ($pool as $e => $x) { $s = ((int)$x['cnt'] + 1) / max(0.0001, (float)$x['w']); if ($bs === null || $s < $bs - 1e-12 || (abs($s - $bs) <= 1e-12 && (int)$e < $best)) { $bs = $s; $best = (int)$e; } }
    return $best;
}

/**
 * Who gets the next FRESH query, in order: tier 1 = GREEN (judged after 6 queries, ratio at or above the team average) · tier 2 = inside the allowed band, and people not judged yet (fewer than 6 queries).
 * Below the band (red) is switched off for fresh queries and never reaches this list. Returns 1, 2 or 3 (3 = red).
 */
function waa_tier(int $q, int $qo, float $team): int
{
    if ($q < WAA_MIN_Q || $team <= 0) return 2;
    $r = $qo / $q; if ($r >= $team - 1e-9) return 1;
    return $r < waa_floor($team) - 1e-9 ? 3 : 2;
}

/**
 * $c = [emp => ['w', 'cnt', 'tier', 'full']]. The first tier that has somebody who is not full (WAA_MAX_WAIT customers already waiting for an answer) wins; inside a tier the fairest share goes first
 * (tier 2 first brings everybody to the minimum of 6). When everybody is full the query is still given (a query nobody takes dies), by the same order.
 */
function waa_pick_tiers(array $c, int $min = WAA_MIN_Q): int
{
    // one query at a time: a person who was given a query a moment ago ('rest') is skipped while somebody else can take it — green first, but never 2-4 at once (owner, 19 Nov)
    foreach ([false, true] as $ignoreFull) {
        foreach ([false, true] as $ignoreRest) {
            foreach ([1, 2] as $t) {
                $g = array_filter($c, function ($x) use ($t, $ignoreFull, $ignoreRest) { return (int)($x['tier'] ?? 2) === $t && ($ignoreFull || empty($x['full'])) && ($ignoreRest || empty($x['rest'])); });
                if ($g) return waa_pick($g, $t === 2 ? $min : 0);
            }
        }
    }
    return 0;
}

function waa_night(int $hour): bool { return $hour >= 0 && $hour < 10; }

/** A person below the band may take at most WAA_NIGHT_MAX queries from Requests overnight (not when Admin / Sales Head turned the button ON). */
function waa_night_block(bool $below, bool $manualOn, int $picked): bool { return !$manualOn && $below && $picked >= WAA_NIGHT_MAX; }

}
