<?php
/**
 * wa_dq.php — 📅 The daily plan: ALL campaigns are one queue (owner, 11 Nov 2026).
 *
 *  - One shared cap for every campaign together: wa_dq_hourly (default 10,000) DELIVERED campaign messages in any rolling hour (counted as delivered + waiting-for-receipt × the recent delivery rate, so we push more when Meta delivers fewer). When it is used the campaigns wait for the next hour by themselves.
 *    ⚡ Send fast campaigns are the owner's explicit "now" and are not held by it (their sends still count in the hour).
 *  - Within a campaign the people who wrote to us most recently go first (Meta holds messages to people who do not engage).
 *  - A person is not messaged twice: the sender already skips whoever got a campaign message in the last wa_camp_gap_days (default 3), who just bought, or ignored the last 2 campaigns.
 *    A new campaign simply joins the queue (the sender looks at every running campaign every round).
 *  - 🔁 Recovery messages (failed people sent again from another number, who = 'recover') COUNT in the same hour and day as campaign messages, and they go first: the recovery sender is not held by this cap,
 *    so campaigns get what is left of the hour / day.
 *  - Meta's own 24-hour room (with our margin) stays the ceiling; the number rules (same number for people who wrote to it, the rest by room, Medium = High) are unchanged.
 *  - 🧠 Hour-aware (wa_dq_smart 1): it LEARNS how well each hour of the day delivers (messages older than 2 hours from the last 14 days, today counted double; a message that was READ counts double — an hour whose people open the message is worth more than one that is only delivered) and scales that hour's allowance:
 *    a good hour gets up to 3× the cap, a weak one down to 0.3× (never zero, so every hour keeps being measured). Until 4 hours have 300+ messages of data it stays flat. Score = hour's worth (delivered + read per message pushed) ÷ the overall worth.
 *  - 🤖 Auto day cap (wa_dq_auto 1): once at the start of every business day the plan judges yesterday — did the delivery rate hold up against the 7 days before, and did yesterday really use the cap? Held up and used (90%+) →
 *    the day cap goes UP 5,000 (max wa_dq_daily_max 90,000 = Meta's 90% margin); delivery fell 8+ points → DOWN 5,000 (15+ points → 10,000); otherwise it stays. Never below wa_dq_daily_min 40,000. The number saved on the card is the start / manual value.
 *  - 💰 Money limit (wa_dq_budget, ₹ per business day, 0 = off): the estimated spend of the day (delivered + waiting-for-receipt × the live delivery rate, × the marketing rate) stops the plan when it is reached.
 *  - 🛑 Guards against over-pushing: "good" delivery also needs wa_dq_good_floor (45%) — a falling usual range can no longer make a weak rate look good — and an hour never pushes more than wa_dq_push_mult (1.5) × its delivered target.
 * Settings: wa_dq_on 1 · wa_dq_hourly 10000 · wa_dq_daily 75000 (campaigns per business day: Meta's room is also used by recovery retries, order updates and follow-ups, so campaigns leave about 15,000 of it free).   Route (in wa_camp.php): wa.dq_set POST {on, hourly}
 */
if (!function_exists('wa_dq_cfg')) {

function wa_dq_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    $base = max(1000, min(1000000, (int)$g('wa_dq_daily', '75000'))); $auto = $g('wa_dq_auto', '1') !== '0'; $dmin = max(1000, (int)$g('wa_dq_daily_min', '40000')); $dmax = max($dmin, (int)$g('wa_dq_daily_max', '90000')); $daily = $base;
    if ($auto) { $av = (int)$g('wa_dq_daily_auto', '0'); if ($av > 0) $daily = max($dmin, min($dmax, $av)); }
    return ['on' => $g('wa_dq_on', '1') !== '0', 'hourly' => max(500, min(60000, (int)$g('wa_dq_hourly', '10000'))), 'daily' => $daily, 'base' => $base, 'auto' => $auto, 'dmin' => $dmin, 'dmax' => $dmax, 'smart' => $g('wa_dq_smart', '1') !== '0', 'budget' => max(0, (int)$g('wa_dq_budget', '0')), 'good_floor' => max(0.0, min(0.95, (float)$g('wa_dq_good_floor', '0.45'))), 'push_mult' => max(1.0, min(5.0, (float)$g('wa_dq_push_mult', '1.5')))];
}
/** Pushed / delivered campaign messages in a time range (for the day-cap judgement). */
function wa_dq_counts(PDO $pdo, string $a, string $b): array
{
    try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ?"); $q->execute([$a, $b]); $r = $q->fetch(); return [(int)$r['n'], (int)$r['d']]; } catch (Throwable $e) { return [0, 0]; }
}
/** 🤖 Once per business day: raise or lower the day cap from how yesterday delivered. Keeps a short log (shown on the card). */
function wa_dq_auto_eval(PDO $pdo): void
{
    $cfg = wa_dq_cfg($pdo); if (!$cfg['on'] || !$cfg['auto']) return;
    $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $key = substr($today, 0, 10);
    if ((string)setting_get($pdo, 'wa_dq_auto_day', '') === $key) return;
    setting_set($pdo, 'wa_dq_auto_day', $key);
    $t = strtotime($today); $f = 'Y-m-d H:i:s'; [$yn, $yd] = wa_dq_counts($pdo, date($f, $t - 86400), $today); [$bn, $bd] = wa_dq_counts($pdo, date($f, $t - 8 * 86400), date($f, $t - 86400));
    $cap = $cfg['daily']; $new = $cap; $why = '';
    if ($yn < 3000 || $bn < 3000) $why = 'not enough data yet — kept';
    else { $yr = $yd / $yn; $br = $bd / $bn; $txt = 'yesterday ' . round($yr * 100) . '% delivered vs ' . round($br * 100) . '% in the 7 days before';
        if ($yr < $br - 0.15) { $new = $cap - 10000; $why = $txt . ' — delivery fell a lot, lowered'; }
        elseif ($yr < $br - 0.08) { $new = $cap - 5000; $why = $txt . ' — delivery fell, lowered'; }
        elseif ($yn >= 0.9 * $cap && $yr >= $br - 0.03) { $new = $cap + 5000; $why = $txt . ' and the cap was used — raised'; }
        else $why = $txt . ' — kept'; }
    $new = max($cfg['dmin'], min($cfg['dmax'], $new)); setting_set($pdo, 'wa_dq_daily_auto', (string)$new);
    try { $log = json_decode((string)setting_get($pdo, 'wa_dq_auto_log', ''), true); $log = is_array($log) ? $log : []; $log[] = ['day' => $key, 'from' => $cap, 'to' => $new, 'why' => $why]; setting_set($pdo, 'wa_dq_auto_log', json_encode(array_slice($log, -14))); } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_stat', ''); } catch (Throwable $e) { }
}
/** What each hour of the day has delivered lately: [hour => ['n', 'rate', 'mult']] + overall + whether it is still learning. Messages older than 2 hours only (receipts have arrived), last 7 days, today counted double. Remembered for 10 minutes. */
function wa_dq_hour_scores(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_scores', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 600) return $hit;
        if (is_array($hit) && (int)setting_get($pdo, 'wa_dq_scores_lock', '0') > time() - 90) return $hit;   // another request is already recounting: use the last copy (six copies at once were freezing the database)
        setting_set($pdo, 'wa_dq_scores_lock', (string)time()); } catch (Throwable $e) { }
    $o = ['t' => time(), 'hours' => [], 'overall' => 0.0, 'measured' => 0, 'learning' => true];
    try {
        $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n, SUM(IF(ts >= ?, 2, 1)) AS wn, SUM(IF(ts >= ?, 2, 1) * ((status IN ('delivered', 'read')) + (status = 'read'))) AS wd, SUM(IF(ts >= ?, 2, 1) * (status IN ('delivered', 'read'))) AS wdel FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)");
        $q->execute([$today, $today, $today, date('Y-m-d H:i:s', time() - 14 * 86400), date('Y-m-d H:i:s', time() - 7200)]); $sn = 0.0; $sd = 0.0; $rows = [];
        foreach ($q->fetchAll() as $r) { $rows[(int)$r['h']] = $r; if ((int)$r['n'] >= 300) { $sn += (float)$r['wn']; $sd += (float)$r['wd']; $o['measured']++; } }
        $overall = $sn > 0 ? $sd / $sn : 0.0; $o['overall'] = round($overall, 3); $o['learning'] = $o['measured'] < 4 || $overall <= 0;
        foreach ($rows as $h => $r) { $rate = (float)$r['wn'] > 0 ? (float)$r['wd'] / (float)$r['wn'] : 0.0; $m = (!$o['learning'] && (int)$r['n'] >= 300) ? max(0.3, min(3.0, $rate / $overall)) : 1.0; $o['hours'][$h] = ['n' => (int)$r['n'], 'rate' => round((float)$r['wn'] > 0 ? (float)$r['wdel'] / (float)$r['wn'] : 0.0, 3), 'worth' => round($rate, 3), 'mult' => round($m, 2)]; }
    } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_scores', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** 🗄 A small cache in the database (not in a setting): the Campaigns page keeps its last answer here so it opens pre-filled and refreshes behind it. */
function wa_cpc_table(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cp_cache (k VARCHAR(40) NOT NULL PRIMARY KEY, v MEDIUMTEXT NOT NULL, at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); return $ok = true; } catch (Throwable $e) { return $ok = false; }
}
function wa_cpc_put(PDO $pdo, string $k, array $v): void
{
    if (!wa_cpc_table($pdo)) return; try { $j = json_encode($v, JSON_UNESCAPED_UNICODE); if ($j !== false && strlen($j) < 3000000) $pdo->prepare("REPLACE INTO wa_cp_cache (k, v, at) VALUES (?, ?, ?)")->execute([$k, $j, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
}
function wa_cpc_get(PDO $pdo, string $k): ?array
{
    if (!wa_cpc_table($pdo)) return null; try { $q = $pdo->prepare("SELECT v, at FROM wa_cp_cache WHERE k = ?"); $q->execute([$k]); $r = $q->fetch(); if (!$r) return null; $d = json_decode((string)$r['v'], true); return is_array($d) ? ['v' => $d, 'age' => max(0, time() - (int)strtotime((string)$r['at']))] : null; } catch (Throwable $e) { return null; }
}
/** The usual RANGE of the hourly delivery rate over the last 7 days (each date+hour with 300+ messages, receipts given 2 hours): p25 / p50 / p75. Judging "good" or "low" against a range, not one number. Remembered for 10 min. */
function wa_dq_range(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_range', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 600) return $hit;
        if (is_array($hit) && (int)setting_get($pdo, 'wa_dq_range_lock', '0') > time() - 90) return $hit;   // another request is already recounting: use the last copy (seven copies at once were freezing the database)
        setting_set($pdo, 'wa_dq_range_lock', (string)time()); } catch (Throwable $e) { }
    $o = ['t' => time(), 'p25' => 0.0, 'p50' => 0.0, 'p75' => 0.0, 'n' => 0];
    try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY DATE(ts), HOUR(ts) HAVING n >= 300");
        $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 7200)]); $r = []; foreach ($q->fetchAll() as $x) $r[] = (int)$x['d'] / max(1, (int)$x['n']); sort($r);
        if (count($r) >= 8) { $pc = function (float $p) use ($r) { return $r[(int)max(0, min(count($r) - 1, round($p * (count($r) - 1))))]; }; $o = ['t' => time(), 'p25' => round($pc(0.25), 3), 'p50' => round($pc(0.5), 3), 'p75' => round($pc(0.75), 3), 'n' => count($r)]; } } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_range', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** The delivery rate RIGHT NOW: the last 4 hours (receipts of the last hour left out), else today so far, else yesterday. Remembered for 60 s. Returns ['rate' (or null), 'from' => 'last 4 h' | 'today' | 'yesterday']. */
function wa_dq_live(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_live', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 60) return $hit; } catch (Throwable $e) { }
    $f = 'Y-m-d H:i:s'; $now = time(); $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $t = strtotime($today); $o = ['t' => $now, 'rate' => null, 'from' => ''];
    foreach ([['last 4 h', date($f, $now - 4 * 3600), date($f, $now - 3600), 500], ['today', $today, date($f, $now - 3600), 500], ['yesterday', date($f, $t - 86400), $today, 1000]] as [$lab, $a, $b, $min]) {
        if (strtotime($b) <= strtotime($a)) continue; [$n, $d] = wa_dq_counts($pdo, $a, $b); if ($n >= $min) { $o['rate'] = round($d / $n, 3); $o['from'] = $lab; break; } }
    try { setting_set($pdo, 'wa_dq_live', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** 🛡 Safety net for "Meta limit counted per number": if we are past the SHARED limit's margin and a third of the campaign messages of the last 30 minutes were refused (anything but the per-person refusals),
 *  Meta is counting one shared pool after all → the mode goes back to 'shared' by itself and says so. Checked at most once a minute. */
function wa_limit_guard(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'wa_limit_mode', 'shared') !== 'per_number') return;
        if ((int)setting_get($pdo, 'wa_limit_guard_at', '0') > time() - 60) return; setting_set($pdo, 'wa_limit_guard_at', (string)time());
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status = 'failed' AND err_code NOT IN (131049, 131026, 130472, 131050, 131047, 131021, 131051)) AS f FROM (SELECT status, err_code FROM wa_camp_items WHERE sent_at >= ? ORDER BY sent_at DESC LIMIT 300) t");
        $q->execute([date('Y-m-d H:i:s', time() - 1800)]); $r = $q->fetch(); $n = (int)$r['n']; $f = (int)$r['f'];
        $q = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 86400)]); $day = (int)$q->fetchColumn();
        if (!function_exists('wa_health_all') && is_file(__DIR__ . '/wa_console.php')) require_once __DIR__ . '/wa_console.php';
        $tiers = array_filter(array_column(wa_health_all($pdo, false), 'tier'), fn($t) => $t !== null); $shared = (int)floor(($tiers ? (in_array(-1, $tiers, true) ? 1000000 : max($tiers)) : 250) * 0.9);   // the margin of the SHARED limit
        if ($n >= 100 && $f / max(1, $n) >= 0.33 && $day >= $shared) { setting_set($pdo, 'wa_limit_mode', 'shared'); wa_log($pdo, 'campaign', false, 'Meta limit counting went back to SHARED: ' . $f . ' of the last ' . $n . ' messages were refused after ' . $day . ' people in 24 h'); try { setting_set($pdo, 'wa_dq_stat', ''); } catch (Throwable $e) { } }
    } catch (Throwable $e) { }
}
/** This hour's room (owner, 11 Nov): the hourly cap counts messages Meta DELIVERS. Messages still waiting for their receipt count at today's real delivery rate. The delivery rate is judged against its usual RANGE:
 *   good (at or above the middle of the range) → the hour's target grows with it (×1.0 up to ×2, on top of the hour-of-day score) and the sender is allowed to push more to deliver it;
 *   ok (between the low and the middle) → the normal target, half-compensating pushes;
 *   low (below the low end of the range) → NO extra pushing: pushes are sized as if delivery were normal, so we do not burn Meta's room on people it will not deliver to.
 *  The day cap counts pushes (Meta's own wall counts pushes) and keeps room for retries / order updates.
 *  Returns ['on', 'cap', 'used' (pushed this hour), 'exp' (expected delivered), 'rate', 'state', 'range', 'mult' (hour score), 'm_live', 'eff_cap', 'daily', 'day_used', 'day_left', 'left' (pushes still allowed), 'why']. Remembered for 15 s ($fresh: counted again). */
function wa_dq_left(PDO $pdo, bool $fresh = false): array
{
    static $memo = null, $at = 0; if (!$fresh && $memo !== null && microtime(true) - $at < 15) return $memo;
    try { wa_dq_auto_eval($pdo); wa_limit_guard($pdo); } catch (Throwable $e) { }
    $cfg = wa_dq_cfg($pdo); $used = 0; $dayUsed = 0; $exp = 0; $rate = 0.75; $mult = 1.0; $learning = true; $state = 'unknown'; $mLive = 1.0; $range = ['p25' => 0.0, 'p50' => 0.0, 'p75' => 0.0]; $rateUsed = 0.75; $spend = 0.0; $mr = function_exists('wab_rates') ? (float)wab_rates($pdo)['marketing'] : 1.09;
    if ($cfg['on'] && $cfg['smart']) { $sc = wa_dq_hour_scores($pdo); $learning = !empty($sc['learning']); $mult = (float)($sc['hours'][(int)date('G')]['mult'] ?? 1.0); }   // 🧠 this hour of the day delivers better / worse than average
    if ($cfg['on']) {
        $now = time(); $dH = 0; $pH = 0;
        try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'sent') AS p FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', $now - 3600)]); $r = $q->fetch(); $used = (int)$r['n']; $dH = (int)$r['d']; $pH = (int)$r['p']; } catch (Throwable $e) { }
        $lv = wa_dq_live($pdo); $rg = wa_dq_range($pdo); if ($lv['rate'] !== null) $rate = (float)$lv['rate'];
        $rate = max(0.15, min(0.95, $rate)); $exp = (int)round($dH + $pH * $rate);
        if ($lv['rate'] !== null && $rg['n'] >= 8 && $rg['p50'] > 0) { $range = $rg; $r0 = (float)$lv['rate'];
            if ($r0 >= $rg['p50'] && $r0 >= $cfg['good_floor']) { $state = 'good'; $mLive = max(1.0, min(2.0, $r0 / $rg['p50'])); $rateUsed = $r0; }
            elseif ($r0 >= $rg['p25'] || $r0 >= $rg['p50']) { $state = 'ok'; $rateUsed = $r0 >= $rg['p50'] ? $rg['p50'] : $rg['p50'] - ($rg['p50'] - $r0) * 0.5; }
            else { $state = 'low'; $rateUsed = $rg['p50']; } }
        else $rateUsed = max(0.5, $rate);
        try { $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([$from]); $dayUsed = (int)$q->fetchColumn(); $q = $pdo->prepare("SELECT SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'sent') AS p FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([$from]); $sr = $q->fetch(); $spend = ((int)$sr['d'] + (int)$sr['p'] * $rate) * $mr; } catch (Throwable $e) { }
    }
    $effCap = (int)round($cfg['hourly'] * $mult * $mLive); $rateUsed = max(0.15, min(0.95, $rateUsed)); $hourLeft = max(0, (int)ceil(($effCap - $exp) / $rateUsed)); $pushCap = (int)round($effCap * $cfg['push_mult']); $hourLeft = max(0, min($hourLeft, $pushCap - $used)); $dayLeft = max(0, $cfg['daily'] - $dayUsed);
    $moneyLeft = $cfg['budget'] > 0 ? max(0.0, $cfg['budget'] - $spend) : null; $moneyPush = $moneyLeft === null ? PHP_INT_MAX : (int)floor($moneyLeft / max(0.01, $mr * max(0.2, $rate)));
    $memo = ['on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $used, 'exp' => $exp, 'rate' => round($rate, 2), 'state' => $state, 'range' => $range, 'm_live' => round($mLive, 2), 'mult' => round($mult, 2), 'eff_cap' => $effCap, 'learning' => $learning, 'smart' => $cfg['smart'], 'daily' => $cfg['daily'], 'day_used' => $dayUsed, 'day_left' => $dayLeft, 'left' => min($hourLeft, $dayLeft, $moneyPush), 'spend' => (int)round($spend), 'budget' => $cfg['budget'], 'push_cap' => $pushCap, 'why' => ($moneyLeft !== null && $moneyLeft <= 0) ? 'money' : ($dayLeft <= 0 ? 'day' : ($hourLeft <= 0 ? 'hour' : ''))]; $at = microtime(true); return $memo;
}
/** The plain sentence for why the plan is holding the campaigns right now. */
function wa_dq_why_text(array $d): string
{
    $w = (string)($d['why'] ?? '');
    if ($w === 'money') return 'Daily plan: today’s money limit of ₹' . number_format((int)($d['budget'] ?? 0)) . ' is reached — it continues tomorrow.';
    if ($w === 'day') return 'Daily plan: today’s ' . number_format((int)($d['daily'] ?? 0)) . ' campaign messages are used — it continues tomorrow.';
    return 'Daily plan: this hour’s allowance (about ' . number_format((int)($d['push_cap'] ?? $d['cap'] ?? 0)) . ' pushes) is used by all campaigns together — it continues in the next hour.';
}
/** Campaign messages by hour of the day: pushed (accepted by Meta), delivered / read — for the current business day and for the last 7 days together (which hours suit best). Remembered for 60 s. */
function wa_dq_hours(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_hours', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 60) return $hit; } catch (Throwable $e) { }
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $to = date('Y-m-d H:i:s', strtotime($from) + 86400);
    $o = ['t' => time(), 'from' => $from, 'to' => $to, 'start_hour' => (int)date('G', strtotime($from)), 'today' => [], 'week' => []];
    $run = function (string $a, string $b) use ($pdo) { $r = []; try { $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'read') AS rd FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)"); $q->execute([$a, $b]); foreach ($q->fetchAll() as $x) $r[(int)$x['h']] = [(int)$x['n'], (int)$x['d'], (int)$x['rd']]; } catch (Throwable $e) { } return $r; };
    $o['mult'] = []; if (wa_dq_cfg($pdo)['smart']) foreach ((wa_dq_hour_scores($pdo)['hours'] ?? []) as $h => $x) $o['mult'][(int)$h] = $x['mult'];
    // the plan for every hour of the day: delivered target (hourly number × the hour's score) and the pushes that takes at that hour's delivery rate; hours outside the sending window get 0
    $cfg2 = wa_dq_cfg($pdo); $sc2 = wa_dq_hour_scores($pdo); [$w0, $w1] = function_exists('wa_camp_window') ? wa_camp_window($pdo) : [0, 24]; $o['plan'] = []; $o['cur'] = (int)date('G'); $o['hourly'] = $cfg2['hourly']; $o['daily'] = $cfg2['daily']; $o['smart'] = $cfg2['smart']; $o['on'] = $cfg2['on'];
    for ($h = 0; $h < 24; $h++) { $in = $h >= $w0 && $h < $w1; $m = ($cfg2['smart'] && empty($sc2['learning'])) ? (float)($sc2['hours'][$h]['mult'] ?? 1.0) : 1.0; $rt = (float)($sc2['hours'][$h]['rate'] ?? 0); if ($rt <= 0) $rt = 0.75; $rt = max(0.3, min(0.95, $rt)); if ($h === $o['cur']) $m *= (float)(wa_dq_left($pdo)['m_live'] ?? 1.0); $d = $in ? (int)round($cfg2['hourly'] * $m) : 0; $o['plan'][$h] = ['d' => $d, 'p' => $d ? (int)ceil($d / $rt) : 0]; }
    $o['today'] = $run($from, $to); $o['week'] = $run(date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() + 60));
    try { setting_set($pdo, 'wa_dq_hours', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** The plan card: cap, used, people waiting in the one queue, Meta room. Remembered for 30 s. */
function wa_dq_auto_last(PDO $pdo): ?array
{
    try { $log = json_decode((string)setting_get($pdo, 'wa_dq_auto_log', ''), true); return is_array($log) && $log ? end($log) : null; } catch (Throwable $e) { return null; }
}
function wa_dq_status(PDO $pdo): array
{
    $cfg = wa_dq_cfg($pdo);
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_stat', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 30 && ($hit['cap'] ?? 0) === $cfg['hourly'] && ($hit['daily'] ?? 0) === $cfg['daily'] && ($hit['on'] ?? null) === $cfg['on']) return $hit; } catch (Throwable $e) { }
    $l = wa_dq_left($pdo); $o = ['t' => time(), 'on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $l['used'], 'exp' => $l['exp'], 'rate' => $l['rate'], 'state' => $l['state'], 'range' => $l['range'], 'm_live' => $l['m_live'], 'mult' => $l['mult'], 'eff_cap' => $l['eff_cap'], 'learning' => $l['learning'], 'smart' => $l['smart'], 'left' => $l['left'], 'daily' => $l['daily'], 'day_used' => $l['day_used'], 'why' => $l['why'], 'spend' => $l['spend'], 'budget' => $l['budget'], 'push_cap' => $l['push_cap'], 'limit_mode' => (string)setting_get($pdo, 'wa_limit_mode', 'shared'), 'early' => (string)setting_get($pdo, 'wa_dq_early', '1') !== '0', 'auto' => $cfg['auto'], 'dmin' => $cfg['dmin'], 'dmax' => $cfg['dmax'], 'base' => $cfg['base'], 'auto_last' => wa_dq_auto_last($pdo), 'people' => 0, 'campaigns' => 0, 'gap_days' => max(0, (int)setting_get($pdo, 'wa_camp_gap_days', '3'))];
    try { $r = $pdo->query("SELECT COUNT(DISTINCT i.phone) AS p, COUNT(DISTINCT i.camp_id) AS c FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE c.status = 'running' AND i.status IN ('queued', 'retry')")->fetch(); $o['people'] = (int)$r['p']; $o['campaigns'] = (int)$r['c']; } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_stat', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}

}
