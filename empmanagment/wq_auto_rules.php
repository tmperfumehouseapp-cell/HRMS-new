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
if (!defined('WAA_BAND')) { define('WAA_BAND', 0.05); define('WAA_MIN_Q', 5); define('WAA_NIGHT_MAX', 5); define('WAA_MAX_WAIT', 10); }   // allowed: average down to 5 points below · 6 fresh queries before the ratio is judged · 5 overnight picks from Requests
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
 * Who gets the next FRESH query (owner, 20 Nov 2026) — one query at a time, in line, never 2-5 at once:
 *   tier 0 = GREY (fewer than 5 queries today): they come FIRST, one query each in turn, until everybody has 5;
 *   then GREEN (tier 1, ratio at or above the team) and YELLOW (tier 2, inside the band) in the pattern green, green, yellow (6 queries to the greens = 3 to the yellows), each group in line;
 *   RED (tier 3) gets nothing until the ratio is back. Returns 0 (grey), 1, 2 or 3.
 */
function waa_tier(int $q, int $qo, float $team): int
{
    if ($q < WAA_MIN_Q) return 0;   // grey: not judged yet, first to be filled up to 5
    if ($team <= 0) return 2;
    $r = $qo / $q; if ($r >= $team - 1e-9) return 1;
    return $r < waa_floor($team) - 1e-9 ? 3 : 2;
}

/** THE LINE (owner, 20 Nov: "one query each in turn, then the next person"): the person whose last query from the engine is the OLDEST goes next (nobody given one yet today = first); a tie goes to the one with fewer queries, then to the lower id. */
function waa_pick_line(array $c): int
{
    $best = 0; $bk = null; foreach ($c as $e => $x) { $k = [(int)($x['last'] ?? 0), (int)($x['cnt'] ?? 0), (int)$e]; if ($bk === null || $k < $bk) { $bk = $k; $best = (int)$e; } }
    return $best;
}
/** The person with the FEWEST queries so far (the grey people are filled up one by one); a tie goes to the one waiting longest in line, then to the lower id. */
function waa_pick_fewest(array $c): int
{
    $best = 0; $bk = null; foreach ($c as $e => $x) { $k = [(int)($x['cnt'] ?? 0), (int)($x['last'] ?? 0), (int)$e]; if ($bk === null || $k < $bk) { $bk = $k; $best = (int)$e; } }
    return $best;
}
/**
 * $c = [emp => ['w', 'cnt', 'tier', 'full', 'ahead', 'rest']]. $pos = how many green/yellow queries were given before (the pattern is green, green, yellow: $pos % 3 === 2 is a yellow's turn).
 * Order of care: not full (WAA_MAX_WAIT customers waiting) → not AHEAD of his group's fair share → not resting (one query at a time) → GREY first, then the pattern. Inside the grey group the fewest goes first,
 * inside green / yellow the fairest share. When everybody is full the query is still given (a query nobody takes dies), by the same order.
 */
function waa_pick_tiers(array $c, int $min = WAA_MIN_Q, int $pos = 0): int
{
    foreach ([false, true] as $ignoreFull) {
        foreach ([false, true] as $ignoreAhead) {
            foreach ([false, true] as $ignoreRest) {
                $in = function (int $t) use ($c, $ignoreFull, $ignoreAhead, $ignoreRest) { return array_filter($c, function ($x) use ($t, $ignoreFull, $ignoreAhead, $ignoreRest) { return (int)($x['tier'] ?? 2) === $t && ($ignoreFull || empty($x['full'])) && ($ignoreAhead || empty($x['ahead'])) && ($ignoreRest || empty($x['rest'])); }); };
                $g0 = $in(0); if ($g0) return waa_pick_fewest($g0);
                foreach (($pos % 3 === 2) ? [2, 1] : [1, 2] as $t) { $g = $in($t); if ($g) return waa_pick_line($g); }
            }
        }
    }
    return 0;
}

/** Who is far ahead of his fair share of fresh queries INSIDE HIS OWN GROUP (green among greens, yellow among yellows; the greens are meant to get twice what the yellows get): more than 25% over the share his weight earns (and at least 2 queries over). Marks cand[e]['ahead']. */
function waa_mark_ahead(array $c): array
{
    foreach ([1, 2] as $t) {
        $g = array_filter($c, function ($x) use ($t) { return (int)($x['tier'] ?? 2) === $t; }); if (count($g) < 3) continue;
        $tc = 0; $tw = 0.0; foreach ($g as $x) { $tc += (int)$x['cnt']; $tw += max(0.0001, (float)$x['w']); } if ($tw <= 0) continue; $per = $tc / $tw;
        foreach ($g as $e => $x) $c[$e]['ahead'] = ((int)$x['cnt'] + 1) > $per * max(0.0001, (float)$x['w']) * 1.25 + 2;
    }
    return $c;
}

/** A customer's last message that needs no answer. */
function waa_no_answer_needed(string $mtype, string $body): bool
{
    if (in_array($mtype, ['reaction', 'sticker', 'system', 'unsupported', 'ephemeral'], true)) return true;
    return (bool)preg_match('/^\s*((ok+|okay|okk|k+|kk|thanks?|thank you|thank u|thx|ty|done|noted|fine|sure|cool|hmm+|yes|haan|ha|already ordered|order ho gaya|[\x{1F44D}\x{1F64F}\x{1F60A}\x{2764}\x{FE0F}])\s*[,.!]*\s*)+$/iu', $body);
}


function waa_night(int $hour): bool { return $hour >= 0 && $hour < 10; }

/** A person below the band may take at most WAA_NIGHT_MAX queries from Requests overnight (not when Admin / Sales Head turned the button ON). */
function waa_night_block(bool $below, bool $manualOn, int $picked): bool { return !$manualOn && $below && $picked >= WAA_NIGHT_MAX; }

}
