<?php
/**
 * wa_cfun_rules.php — the rules of the customer funnel as small PURE functions (no database), so they can be tested (tests/cfun_test.php) and read in one place.
 *
 *   cfun_value   customer value from orders + the date of the last order
 *   cfun_health  can a message reach them? from why the last messages failed
 *   cfun_score   quality score 0–100 and what it is made of
 *   cfun_tier    A / B / C / D from the score
 *   cfun_tags    the real tags a customer should carry (key, reason) — written to wa_auto_tags with src 'funnel'
 */
if (!function_exists('cfun_value')) {

/** new = 1 order in the last 30 days · one = 1 order 31–120 days ago · repeat = 2+ orders, last within 90 days · loyal = 3+ orders, last within 90 days · atrisk = quiet 91–180 days (121–180 for a single order) · lapsed = over 180 days · past = ordered but the date is unknown · '' = never ordered */
function cfun_value(int $orders, ?string $lastOrder, int $now): string
{
    if ($orders <= 0) return '';
    $ts = $lastOrder ? strtotime($lastOrder) : false; if (!$ts) return 'past';   // ordered, but no order date is known (old store / POS bills): not guessed
    $da = (int)floor(($now - $ts) / 86400);
    if ($da > 180) return 'lapsed';
    if ($orders === 1) return $da <= 30 ? 'new' : ($da <= 120 ? 'one' : 'atrisk');
    return $da <= 90 ? ($orders >= 3 ? 'loyal' : 'repeat') : 'atrisk';
}

/** ok · optout · notwa (131026) · held (131049 / 131048) · stopped (131050) · dead (last 3 failed for another / unknown reason) */
function cfun_health(int $optout, string $bucket, int $ecode): string
{
    if ($optout === 1) return 'optout';
    if ($bucket !== 'failed') return 'ok';
    if ($ecode === 131026) return 'notwa';
    if ($ecode === 131049 || $ecode === 131048) return 'held';
    return $ecode === 131050 ? 'stopped' : 'dead';
}

/** Score 0–100 = engagement 25 + buying 40 + spend 10 + reach 10 + interest 15 (−10 when they said they are not interested). Returns [score, parts]. */
function cfun_score(array $x, int $now): array
{
    $orders = (int)($x['orders'] ?? 0); $spent = (float)($x['spent'] ?? 0); $inN = (int)($x['in_n'] ?? 0); $replied = !empty($x['replied']); $health = (string)($x['health'] ?? 'ok'); $intent = (string)($x['intent'] ?? '');
    $da = static function (?string $t) use ($now) { $s = $t ? strtotime($t) : false; return $s ? (int)floor(($now - $s) / 86400) : 99999; };
    $eng = 0; if ($replied) { $eng += 10; $d = $da($x['last_in'] ?? null); $eng += $d <= 30 ? 10 : ($d <= 90 ? 6 : 0); if ($inN >= 3) $eng += 5; }
    $buy = $orders <= 0 ? 0 : ($orders === 1 ? 18 : ($orders === 2 ? 28 : 36)); if ($orders > 0 && $da($x['last_order'] ?? null) <= 90) $buy += 4;
    $spd = $spent >= 15000 ? 10 : ($spent >= 5000 ? 7 : ($spent >= 1500 ? 4 : ($spent > 0 ? 2 : 0)));
    $rch = $health === 'ok' ? ((int)($x['deliv_n'] ?? 0) > 0 ? 10 : 0) : (in_array($health, ['held', 'stopped'], true) ? 2 : 0);   // points only for a message that really arrived (a customer we never reached has no signal yet)
    $int = ['hot' => 15, 'interested' => 10, 'considering' => 6, 'not_interested' => -10][$intent] ?? 0;
    $score = max(0, min(100, $eng + $buy + $spd + $rch + $int));
    return [$score, ['engagement' => $eng, 'buying' => $buy, 'spend' => $spd, 'reach' => $rch, 'interest' => $int]];
}

function cfun_tier(int $score): string { return $score >= 70 ? 'a' : ($score >= 45 ? 'b' : ($score >= 20 ? 'c' : ($score >= 1 ? 'd' : ''))); }

/** The tag keys this system owns (it deletes / rewrites only these). */
function cfun_tag_keys(): array { return ['value_new', 'value_one', 'value_repeat', 'value_loyal', 'value_atrisk', 'value_lapsed', 'value_past', 'reach_held', 'reach_notwa', 'reach_stopped', 'reach_dead', 'score_a', 'score_b', 'score_c', 'score_d']; }

/** [[tag, reason], …] for one customer. Reach is tagged only when there is a problem (people who can receive are the normal case); score_d..a for every scored customer. */
function cfun_tags(string $val, string $health, int $score, array $parts, array $x): array
{
    $t = []; $V = ['new' => 'ordered once, in the last 30 days', 'one' => 'ordered once, 31–120 days ago', 'repeat' => 'ordered 2+ times, last within 90 days', 'loyal' => 'ordered 3+ times, last within 90 days', 'atrisk' => 'used to order, quiet for 91–180 days', 'lapsed' => 'last order over 180 days ago', 'past' => 'ordered before; the date of the last order is not known'];
    if ($val !== '') $t[] = ['value_' . $val, ((int)($x['orders'] ?? 0)) . ' order(s): ' . $V[$val] . '.'];
    $H = ['held' => 'Meta is holding our marketing messages to them (limit per customer)', 'notwa' => 'the number is not on WhatsApp', 'stopped' => 'they stopped marketing messages', 'dead' => 'their last 3 messages failed'];
    if (isset($H[$health])) $t[] = ['reach_' . $health, ucfirst($H[$health]) . '.'];
    $tier = cfun_tier($score);
    if ($tier !== '') $t[] = ['score_' . $tier, 'Quality score ' . $score . '/100 — engagement ' . $parts['engagement'] . '/25 · buying ' . $parts['buying'] . '/40 · spend ' . $parts['spend'] . '/10 · reach ' . $parts['reach'] . '/10 · interest ' . $parts['interest'] . '/15.'];
    return $t;
}

}
