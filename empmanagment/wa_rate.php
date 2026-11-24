<?php
/** wa_rate.php — an HOURLY SPEED LIMIT with a ladder that moves by itself (owner, 23 Nov night: "not so fast: Cold up to 2,500 an hour, utility rescue from 1,000 up to 4,000; when failures grow go one step down, when it runs well one step up").
 *  Two meters: 'cold' (Cold funnel, ladder 1,500 / 2,000 / 2,500 / 3,000, starts at 2,500) and 'resc' (utility rescue of failed Lineup sends, ladder 1,000 ... 4,000 in steps of 500, starts at 1,000).
 *  The count is kept per clock hour in the table wa_rate (hour = floor(time() / 3600): no time-zone trouble between the web server and the command-line copies); every send adds to it with one atomic UPDATE.
 *  Once an hour the caller gives the failure share of the latest sends: a share at or above the DOWN mark moves one step down; below the UP mark, and at least 80% of the limit really used, one step up; otherwise it stays.
 *  The last decision is kept in setting wrt_last_<meter> (shown by diag_ask.php ASK RATE). Functions only (PHP 7.4 safe). */
if (!function_exists('wrt_used')) {
    function wrt_table(PDO $pdo): void { static $ok = false; if ($ok) return; $ok = true; try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_rate (k VARCHAR(20) NOT NULL, hr INT NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (k, hr))"); } catch (Throwable $e) { } }
    function wrt_hour(): int { return intdiv(time(), 3600); }
    /** Sends counted in the clock hour $hr (default: this one). */
    function wrt_used(PDO $pdo, string $k, int $hr = 0): int { wrt_table($pdo); try { $q = $pdo->prepare("SELECT n FROM wa_rate WHERE k = ? AND hr = ?"); $q->execute([$k, $hr ?: wrt_hour()]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } }
    /** Adds $n sends to this hour (one atomic UPDATE; the row is created by the first send of the hour). */
    function wrt_add(PDO $pdo, string $k, int $n): void
    {
        if ($n <= 0) return; wrt_table($pdo); $h = wrt_hour();
        try { $u = $pdo->prepare("UPDATE wa_rate SET n = n + ? WHERE k = ? AND hr = ?"); $u->execute([$n, $k, $h]); if ($u->rowCount() > 0) return; } catch (Throwable $e) { return; }
        try { $pdo->prepare("INSERT INTO wa_rate (k, hr, n) VALUES (?, ?, ?)")->execute([$k, $h, $n]); } catch (Throwable $e) { try { $pdo->prepare("UPDATE wa_rate SET n = n + ? WHERE k = ? AND hr = ?")->execute([$n, $k, $h]); } catch (Throwable $e2) { } }   // another copy made the row first
    }
    /** The ladder index now (setting wrt_idx_<k>, default $start). */
    function wrt_idx(PDO $pdo, string $k, array $ladder, int $start): int { $v = (string)setting_get($pdo, 'wrt_idx_' . $k, ''); $i = $v === '' ? $start : (int)$v; return max(0, min(count($ladder) - 1, $i)); }
    /** The sends an hour allowed right now. */
    function wrt_limit(PDO $pdo, string $k, array $ladder, int $start): int { return (int)$ladder[wrt_idx($pdo, $k, $ladder, $start)]; }
    /** How many a single call may send now: what is left of the hour, and an even share of the hour's limit per minute (so the limit is not used up in the first minutes). $callers = how many callers run at the same time. */
    function wrt_room(PDO $pdo, string $k, array $ladder, int $start, int $callers = 1): int
    {
        $lim = wrt_limit($pdo, $k, $ladder, $start); $left = $lim - wrt_used($pdo, $k); if ($left <= 0) return 0;
        return max(1, min($left, (int)ceil($lim / 60 / max(1, $callers)) + 1));
    }
    /** True once per clock hour (the first caller of the hour gets it): the moment to look at the failures and move the ladder. */
    function wrt_due(PDO $pdo, string $k): bool
    {
        $h = wrt_hour(); if ((string)setting_get($pdo, 'wrt_chk_' . $k, '') === (string)$h) return false; setting_set($pdo, 'wrt_chk_' . $k, (string)$h); return true;
    }
    /** The decision (pure): the new ladder index. $pct = failure share in % of the latest sends (null = too few to judge). */
    function wrt_decide(int $idx, int $max, ?float $pct, int $usedLastHour, int $limit, float $up, float $down): int
    {
        if ($pct === null) return $idx;
        if ($pct >= $down) return max(0, $idx - 1);
        if ($pct < $up && $usedLastHour >= 0.8 * $limit) return min($max, $idx + 1);
        return $idx;
    }
    /** Moves the ladder from the failure numbers of the latest sends ($ok delivered+read, $fail failed). Returns the new limit. */
    function wrt_adapt(PDO $pdo, string $k, array $ladder, int $start, int $ok, int $fail, float $up, float $down): int
    {
        $idx = wrt_idx($pdo, $k, $ladder, $start); $lim = (int)$ladder[$idx]; $n = $ok + $fail; $pct = $n >= 50 ? round(100 * $fail / $n, 1) : null;
        $used = wrt_used($pdo, $k, wrt_hour() - 1); $new = wrt_decide($idx, count($ladder) - 1, $pct, $used, $lim, $up, $down);
        try { if ($new !== $idx) setting_set($pdo, 'wrt_idx_' . $k, (string)$new); setting_set($pdo, 'wrt_last_' . $k, json_encode(['at' => date('m-d H:i'), 'from' => $lim, 'to' => (int)$ladder[$new], 'fail_pct' => $pct, 'n' => $n, 'used_last_hour' => $used])); } catch (Throwable $e) { }
        return (int)$ladder[$new];
    }
}
