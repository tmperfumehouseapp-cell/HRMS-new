<?php
/**
 * play.php — streaks and the Wins feed for the salesperson home ("My Sales"). Loaded by api.php (answers play.* itself).  PHP 7.4-safe.
 *
 *   play.me     GET   {streak, best, today_in, wins_today}      the signed-in employee's on-time streak (days in a row, newest first)
 *   play.feed   GET   {wins:[{id, who, text, amount, at, claps, mine}]}   the team's wins of the last 7 days (first names only)
 *   play.clap   POST  {id}  -> {claps, mine}                     one 👏 per person per win (tap again to take it back)
 *   play.dino_score POST {score, ms}   play.dino_board GET       the company Dino-run board (today's champion, all-time best)
 *   play.level  GET   Bronze / Silver / Gold this month from sales, on-time days and rating, with the bars to the next step
 *   play.week   GET   "Your week": last Monday-to-Sunday (sales, orders, rank, best day, streak)
 *   play.gifts  GET / play.gift_open POST   Mystery gifts: earned by a quality win, the SERVER picks the prize
 *   play.cfg_get GET / play.cfg_set POST    (Admin) thresholds, gift trigger, odds, cash on/off     play.gift_report GET (Admin)
 *
 * A win is written by the server when something real happens (record_win), never by the browser:
 *   order allocated to a salesperson  -> "Riya closed order #4821 · ₹2,450"
 * The streak counts on-time punch-ins in a row. A day without a punch row (weekly off, leave) neither adds nor breaks it; a late day ends it.
 * Tables are created the first time they are needed.
 */
if (!function_exists('play_streak_from_rows')) {

/** rows = newest first, each ['on_time' => bool]. Returns [current streak, best streak in these rows]. */
function play_streak_from_rows(array $rows): array
{
    $cur = 0; $best = 0; $run = 0; $open = true;
    foreach ($rows as $r) {
        if (!empty($r['on_time'])) { $run++; if ($open) $cur = $run; if ($run > $best) $best = $run; }
        else { $open = false; $run = 0; }
    }
    return [$cur, $best];
}

function play_ensure(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS play_wins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'order',
            text VARCHAR(200) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            ref VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref (ref),
            KEY k_created (created_at)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS play_claps (
            win_id INT NOT NULL,
            employee_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (win_id, employee_id)
        )");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}

/** Write one win. Safe to call from anywhere: never throws, the same ref is only written once. */
function play_record_win(PDO $pdo, int $empId, string $kind, string $text, float $amount, string $ref): void
{
    try {
        if ($empId <= 0 || !play_ensure($pdo)) return;
        $pdo->prepare("INSERT IGNORE INTO play_wins (employee_id, kind, text, amount, ref) VALUES (?, ?, ?, ?, ?)")
            ->execute([$empId, mb_substr($kind, 0, 20), mb_substr($text, 0, 200), $amount, mb_substr($ref, 0, 80)]);
        if ($kind === 'order' && function_exists('play_cfg') && play_is_quality_order($amount, play_cfg($pdo))) play_gift_add($pdo, $empId, 'g:' . $ref, 'A big order · ₹' . number_format($amount, 0));   // a quality win earns a Mystery gift
    } catch (Throwable $e) { /* the feed is a bonus: never break the real action */ }
}

function play_first_name(string $full): string
{
    $p = preg_split('/\s+/', trim($full)); return (string)($p[0] ?? '');
}

/** [streak, best, punched in today] for one employee. */
function play_streak(PDO $pdo, int $empId): array
{
    $st = $pdo->prepare("SELECT punch_date, punch_in FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL ORDER BY punch_date DESC LIMIT 60");
    $st->execute([$empId]);
    $rows = []; $today = false;
    foreach ($st->fetchAll() as $r) {
        $d = (string)$r['punch_date']; if ($d === date('Y-m-d')) $today = true;
        $onTime = true;
        try {
            $s = function_exists('emp_shift') ? emp_shift($pdo, $empId, $d) : [];
            if (($s['mode'] ?? 'fixed') === 'fixed' && (string)($s['shift_start'] ?? '') !== '') {
                $due = strtotime($d . ' ' . $s['shift_start'] . ':00') + (int)($s['late_after'] ?? 15) * 60;
                $onTime = strtotime((string)$r['punch_in']) <= $due;
            }
        } catch (Throwable $e) { $onTime = true; }
        $rows[] = ['on_time' => $onTime];
    }
    [$cur, $best] = play_streak_from_rows($rows);
    return [$cur, $best, $today];
}


// ---------------------------------------------------------------- settings (Admin changes them with play.cfg_set; these are the defaults)
function play_cfg_default(): array
{
    return [
        'silver' => ['sales' => 120000, 'days' => 15, 'rating' => 6.0],
        'gold' => ['sales' => 200000, 'days' => 20, 'rating' => 8.0],
        'gift_min_order' => 3000, 'gift_streaks' => [7, 14, 30],
        'odds' => ['points' => 60, 'shout' => 25, 'badge' => 10, 'cash' => 5],
        'cash_on' => false, 'cash_amount' => 100, 'cash_cap_month' => 2000,
        'perks' => ['silver' => 'First pick of flash offers', 'gold' => 'Gold badge and double bonus points'],
    ];
}
function play_cfg(PDO $pdo): array
{
    $d = play_cfg_default();
    $j = json_decode((string)setting_get($pdo, 'play_cfg', ''), true);
    if (is_array($j)) { foreach ($d as $k => $v) if (isset($j[$k])) $d[$k] = is_array($v) && is_array($j[$k]) ? array_replace_recursive($v, $j[$k]) : $j[$k]; }
    return $d;
}

// ---------------------------------------------------------------- pure rules (no database): easy to test
/** have = [sales, days, rating|null]. Bronze → Silver → Gold. All the needs of a step must be met. */
function play_level_calc(array $have, array $cfg): array
{
    $label = ['sales' => 'Sales this month', 'days' => 'On-time days', 'rating' => 'Rating'];
    $reqsFor = function (string $tier) use ($have, $cfg, $label): array {
        $out = [];
        foreach (['sales', 'days', 'rating'] as $k) {
            if ($k === 'rating' && $have['rating'] === null) continue;
            $need = (float)$cfg[$tier][$k]; $h = (float)$have[$k];
            $out[] = ['key' => $k, 'label' => $label[$k], 'have' => $h, 'need' => $need, 'pct' => $need > 0 ? (int)round(min(1, $h / $need) * 100) : 100, 'met' => $h >= $need];
        }
        return $out;
    };
    $met = function (string $tier) use ($reqsFor): bool { foreach ($reqsFor($tier) as $r) if (!$r['met']) return false; return true; };
    $level = $met('gold') ? 'gold' : ($met('silver') ? 'silver' : 'bronze');
    $next = $level === 'bronze' ? 'silver' : ($level === 'silver' ? 'gold' : null);
    return ['level' => $level, 'next' => $next, 'reqs' => $reqsFor($next ?? 'gold')];
}
/** r = 0..1 position in the odds table. Cash only when allowed; otherwise its share goes to points. */
function play_pick_prize(float $r, array $odds, bool $cashOk): string
{
    $o = $odds; if (!$cashOk) { $o['points'] = ($o['points'] ?? 0) + ($o['cash'] ?? 0); $o['cash'] = 0; }
    $tot = array_sum($o); if ($tot <= 0) return 'points';
    $x = $r * $tot; $acc = 0;
    foreach (['points', 'shout', 'badge', 'cash'] as $k) { $acc += (float)($o[$k] ?? 0); if ($x < $acc) return $k; }
    return 'points';
}
/** Last full Monday-to-Sunday before $today. */
function play_week_range(string $today): array
{
    $mon = strtotime('monday this week', strtotime($today));
    return [date('Y-m-d', strtotime('-7 day', $mon)), date('Y-m-d', strtotime('-1 day', $mon))];
}
function play_is_quality_order(float $amount, array $cfg): bool { return $amount >= (float)$cfg['gift_min_order'] && $amount > 0; }

function play_ensure2(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS play_dino (day DATE NOT NULL, employee_id INT NOT NULL, best INT NOT NULL DEFAULT 0, plays INT NOT NULL DEFAULT 0, PRIMARY KEY (day, employee_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS play_levels (employee_id INT NOT NULL, month CHAR(7) NOT NULL, level VARCHAR(10) NOT NULL, PRIMARY KEY (employee_id, month))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS play_gifts (
            id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, ref VARCHAR(80) NOT NULL, why VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'new', prize VARCHAR(10) NULL, prize_text VARCHAR(160) NULL, points INT NOT NULL DEFAULT 0, cash DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, opened_at DATETIME NULL, UNIQUE KEY uq_ref (ref), KEY k_emp (employee_id, status))");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function play_gift_add(PDO $pdo, int $emp, string $ref, string $why): void
{
    try { if ($emp > 0 && play_ensure2($pdo)) $pdo->prepare("INSERT IGNORE INTO play_gifts (employee_id, ref, why) VALUES (?, ?, ?)")->execute([$emp, mb_substr($ref, 0, 80), mb_substr($why, 0, 120)]); } catch (Throwable $e) { }
}
/** ₹ and order count of the orders allocated to this person, created between two dates (cancelled orders left out). */
function play_sales(PDO $pdo, int $emp, string $from, string $to): array
{
    try {
        $q = $pdo->prepare("SELECT COALESCE(SUM(o.total),0) s, COUNT(*) n FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND o.shopify_id = a.order_id
            WHERE a.employee_id = ? AND a.is_direct = 0 AND o.total > 0 AND (o.tags IS NULL OR o.tags NOT LIKE '%cancel%') AND DATE(o.created_at) BETWEEN ? AND ?");
        $q->execute([$emp, $from, $to]); $r = $q->fetch(); return [(float)($r['s'] ?? 0), (int)($r['n'] ?? 0)];
    } catch (Throwable $e) { return [0.0, 0]; }
}
function play_on_time_days(PDO $pdo, int $empId, string $from, string $to): int
{
    $st = $pdo->prepare("SELECT punch_date, punch_in FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_date BETWEEN ? AND ?");
    $st->execute([$empId, $from, $to]); $n = 0;
    foreach ($st->fetchAll() as $r) {
        $ok = true;
        try { $s = function_exists('emp_shift') ? emp_shift($pdo, $empId, (string)$r['punch_date']) : []; if (($s['mode'] ?? 'fixed') === 'fixed' && (string)($s['shift_start'] ?? '') !== '') $ok = strtotime((string)$r['punch_in']) <= strtotime($r['punch_date'] . ' ' . $s['shift_start'] . ':00') + (int)($s['late_after'] ?? 15) * 60; } catch (Throwable $e) { $ok = true; }
        if ($ok) $n++;
    }
    return $n;
}
function play_rating_month(PDO $pdo, int $emp): ?float
{
    try {
        if (!function_exists('rating_emps') || !function_exists('rating_person') || !isset(rating_emps($pdo)[$emp])) return null;
        $r = rating_person($pdo, $emp); $v = $r['month']['score'] ?? null; return $v === null ? null : (float)$v;
    } catch (Throwable $e) { return null; }
}
function play_points(PDO $pdo, int $emp): int
{
    $q = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM play_gifts WHERE employee_id = ? AND status = 'opened'"); $q->execute([$emp]); return (int)$q->fetchColumn();
}

$__play = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__play, 'play.') === 0) {
    $auth = require_auth();
    $emp = (int)($auth['employee_id'] ?? 0);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!play_ensure($pdo)) json_response(['error' => 'The wins feed could not start (database).'], 500);
    switch ($__play) {
        case 'play.me': {
            if ($emp <= 0) json_response(['streak' => 0, 'best' => 0, 'today_in' => false, 'wins_today' => 0, 'linked' => false]);
            [$cur, $best, $today] = play_streak($pdo, $emp);
            $w = $pdo->prepare("SELECT COUNT(*) FROM play_wins WHERE employee_id = ? AND created_at >= CURDATE()"); $w->execute([$emp]);
            json_response(['streak' => $cur, 'best' => $best, 'today_in' => $today, 'wins_today' => (int)$w->fetchColumn(), 'linked' => true]);
        }
        case 'play.feed': {
            $q = $pdo->prepare("SELECT w.id, w.employee_id, w.kind, w.text, w.amount, w.created_at, e.full_name,
                    (SELECT COUNT(*) FROM play_claps c WHERE c.win_id = w.id) AS claps,
                    (SELECT COUNT(*) FROM play_claps c2 WHERE c2.win_id = w.id AND c2.employee_id = ?) AS mine
                FROM play_wins w LEFT JOIN employees e ON e.employee_id = w.employee_id
                WHERE w.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY w.id DESC LIMIT 30");
            $q->execute([$emp]);
            $out = [];
            foreach ($q->fetchAll() as $r) {
                $out[] = ['id' => (int)$r['id'], 'who' => play_first_name((string)$r['full_name']), 'mine_win' => (int)$r['employee_id'] === $emp,
                    'kind' => (string)$r['kind'], 'text' => (string)$r['text'], 'amount' => (float)$r['amount'], 'at' => (string)$r['created_at'],
                    'claps' => (int)$r['claps'], 'mine' => (int)$r['mine'] > 0];
            }
            json_response(['wins' => $out]);
        }
        case 'play.clap': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if ($emp <= 0) json_response(['error' => 'Your login is not linked to an employee.'], 403);
            $in = json_input(); $id = (int)($in['id'] ?? 0);
            $own = $pdo->prepare("SELECT employee_id FROM play_wins WHERE id = ?"); $own->execute([$id]); $o = $own->fetchColumn();
            if ($o === false) json_response(['error' => 'That win is gone.'], 404);
            if ((int)$o === $emp) json_response(['error' => 'Others clap for your wins.'], 422);
            $has = $pdo->prepare("SELECT 1 FROM play_claps WHERE win_id = ? AND employee_id = ?"); $has->execute([$id, $emp]);
            if ($has->fetchColumn()) { $pdo->prepare("DELETE FROM play_claps WHERE win_id = ? AND employee_id = ?")->execute([$id, $emp]); $mine = false; }
            else { $pdo->prepare("INSERT IGNORE INTO play_claps (win_id, employee_id) VALUES (?, ?)")->execute([$id, $emp]); $mine = true; }
            $n = $pdo->prepare("SELECT COUNT(*) FROM play_claps WHERE win_id = ?"); $n->execute([$id]);
            json_response(['claps' => (int)$n->fetchColumn(), 'mine' => $mine]);
        }

        case 'play.dino_score': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if ($emp <= 0) json_response(['error' => 'Your login is not linked to an employee.'], 403);
            if (!play_ensure2($pdo)) json_response(['error' => 'The board could not start (database).'], 500);
            $in = json_input(); $sc = (int)($in['score'] ?? 0); $ms = (int)($in['ms'] ?? 0);
            // light sanity check: a score cannot grow faster than the game's top speed allows
            if ($sc < 1 || $sc > 20000 || $ms < 500 || $sc > $ms * 0.09 + 10) json_response(['error' => 'That score does not look right.'], 422);
            $pdo->prepare("INSERT INTO play_dino (day, employee_id, best, plays) VALUES (CURDATE(), ?, ?, 1) ON DUPLICATE KEY UPDATE best = GREATEST(best, VALUES(best)), plays = plays + 1")->execute([$emp, $sc]);
            $top = $pdo->query("SELECT employee_id, best FROM play_dino WHERE day = CURDATE() ORDER BY best DESC, employee_id ASC LIMIT 1")->fetch();
            $champ = $top && (int)$top['employee_id'] === $emp;
            if ($champ && (int)$top['best'] >= 50) play_record_win($pdo, $emp, 'dino', "is today's Dino champion · " . (int)$top['best'], 0, 'dino:' . date('Y-m-d') . ':' . $emp);
            json_response(['ok' => true, 'champion' => $champ]);
        }
        case 'play.dino_board': {
            if (!play_ensure2($pdo)) json_response(['error' => 'The board could not start (database).'], 500);
            $t = $pdo->query("SELECT d.employee_id, d.best, e.full_name FROM play_dino d LEFT JOIN employees e ON e.employee_id = d.employee_id WHERE d.day = CURDATE() ORDER BY d.best DESC, d.employee_id ASC")->fetchAll();
            $champion = null; $mine = 0; $rank = 0; $i = 0;
            foreach ($t as $r) { $i++; if ($i === 1) $champion = ['who' => play_first_name((string)$r['full_name']), 'best' => (int)$r['best'], 'mine' => (int)$r['employee_id'] === $emp]; if ((int)$r['employee_id'] === $emp) { $mine = (int)$r['best']; $rank = $i; } }
            $a = $pdo->query("SELECT d.employee_id, MAX(d.best) b, e.full_name FROM play_dino d LEFT JOIN employees e ON e.employee_id = d.employee_id GROUP BY d.employee_id, e.full_name ORDER BY b DESC LIMIT 1")->fetch();
            json_response(['champion' => $champion, 'mine_today' => $mine, 'mine_rank' => $rank, 'players' => count($t),
                'all_time' => $a ? ['who' => play_first_name((string)$a['full_name']), 'best' => (int)$a['b']] : null]);
        }
        case 'play.level': {
            if ($emp <= 0) json_response(['linked' => false]);
            play_ensure2($pdo); $cfg = play_cfg($pdo); $m = date('Y-m');
            [$sales, $n] = play_sales($pdo, $emp, $m . '-01', date('Y-m-d'));
            $have = ['sales' => $sales, 'days' => play_on_time_days($pdo, $emp, $m . '-01', date('Y-m-d')), 'rating' => play_rating_month($pdo, $emp)];
            $L = play_level_calc($have, $cfg);
            $rank = ['bronze' => 0, 'silver' => 1, 'gold' => 2];
            $prev = $pdo->prepare("SELECT level FROM play_levels WHERE employee_id = ? AND month = ?"); $prev->execute([$emp, $m]); $pl = $prev->fetchColumn();
            $up = false;
            if ($pl === false || $rank[$L['level']] > ($rank[(string)$pl] ?? 0)) {
                $pdo->prepare("INSERT INTO play_levels (employee_id, month, level) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE level = VALUES(level)")->execute([$emp, $m, $L['level']]);
                if ($L['level'] !== 'bronze') { $up = true; play_record_win($pdo, $emp, 'level', 'reached ' . ucfirst($L['level']) . ' this month', 0, 'lvl:' . $m . ':' . $emp . ':' . $L['level']); play_gift_add($pdo, $emp, 'g:lvl:' . $m . ':' . $emp . ':' . $L['level'], 'Reached ' . ucfirst($L['level'])); }
            }
            json_response(['linked' => true, 'level' => $L['level'], 'next' => $L['next'], 'reqs' => $L['reqs'], 'levelled_up' => $up, 'orders' => $n,
                'perks' => $cfg['perks'], 'points' => play_points($pdo, $emp), 'month' => $m]);
        }
        case 'play.week': {
            if ($emp <= 0) json_response(['linked' => false]);
            [$f, $t] = play_week_range(date('Y-m-d'));
            [$s1, $n1] = play_sales($pdo, $emp, $f, $t);
            [$s0] = play_sales($pdo, $emp, date('Y-m-d', strtotime($f . ' -7 day')), date('Y-m-d', strtotime($t . ' -7 day')));
            $best = null;
            try {
                $q = $pdo->prepare("SELECT DATE(o.created_at) d, SUM(o.total) s FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND o.shopify_id = a.order_id
                    WHERE a.employee_id = ? AND a.is_direct = 0 AND o.total > 0 AND (o.tags IS NULL OR o.tags NOT LIKE '%cancel%') AND DATE(o.created_at) BETWEEN ? AND ? GROUP BY DATE(o.created_at) ORDER BY s DESC LIMIT 1");
                $q->execute([$emp, $f, $t]); $r = $q->fetch(); if ($r) $best = ['day' => (string)$r['d'], 'sales' => (float)$r['s']];
            } catch (Throwable $e) { }
            $rank = 0; $players = 0;
            try {
                $q = $pdo->prepare("SELECT a.employee_id, SUM(o.total) s FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND o.shopify_id = a.order_id
                    WHERE a.employee_id > 0 AND a.is_direct = 0 AND o.total > 0 AND (o.tags IS NULL OR o.tags NOT LIKE '%cancel%') AND DATE(o.created_at) BETWEEN ? AND ? GROUP BY a.employee_id ORDER BY s DESC");
                $q->execute([$f, $t]); $i = 0; foreach ($q->fetchAll() as $r) { $i++; $players++; if ((int)$r['employee_id'] === $emp) $rank = $i; }
            } catch (Throwable $e) { }
            [$cur, $bestStreak] = play_streak($pdo, $emp);
            json_response(['linked' => true, 'from' => $f, 'to' => $t, 'sales' => $s1, 'orders' => $n1, 'last_week_sales' => $s0, 'best_day' => $best, 'rank' => $rank, 'players' => $players,
                'on_time_days' => play_on_time_days($pdo, $emp, $f, $t), 'streak' => $cur]);
        }
        case 'play.gifts': {
            if ($emp <= 0 || !play_ensure2($pdo)) json_response(['new' => 0, 'points' => 0, 'gifts' => []]);
            $cfg = play_cfg($pdo);
            foreach ((array)$cfg['gift_streaks'] as $N) {   // a streak milestone earns a gift, once a month per milestone
                [$cur] = play_streak($pdo, $emp); if ($cur >= (int)$N) play_gift_add($pdo, $emp, 'g:streak:' . (int)$N . ':' . date('Y-m') . ':' . $emp, (int)$N . ' on-time days in a row');
            }
            $q = $pdo->prepare("SELECT id, why FROM play_gifts WHERE employee_id = ? AND status = 'new' ORDER BY id"); $q->execute([$emp]); $g = $q->fetchAll();
            json_response(['new' => count($g), 'gifts' => $g, 'points' => play_points($pdo, $emp), 'earn' => 'Close an order of ₹' . number_format((float)$cfg['gift_min_order'], 0) . '+, keep a ' . (int)$cfg['gift_streaks'][0] . '-day on-time streak, or level up.']);
        }
        case 'play.gift_open': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if ($emp <= 0 || !play_ensure2($pdo)) json_response(['error' => 'Your login is not linked to an employee.'], 403);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $cfg = play_cfg($pdo);
            $q = $pdo->prepare("SELECT id, why FROM play_gifts WHERE id = ? AND employee_id = ? AND status = 'new'"); $q->execute([$id, $emp]); $g = $q->fetch();
            if (!$g) json_response(['error' => 'That gift is already opened.'], 422);
            $cashUsed = $pdo->prepare("SELECT COALESCE(SUM(cash),0) FROM play_gifts WHERE status = 'opened' AND opened_at >= ?"); $cashUsed->execute([date('Y-m-01')]);
            $cashOk = !empty($cfg['cash_on']) && ((float)$cashUsed->fetchColumn() + (float)$cfg['cash_amount']) <= (float)$cfg['cash_cap_month'];
            $r = mt_rand() / mt_getrandmax(); $prize = play_pick_prize($r, (array)$cfg['odds'], $cashOk);
            $pts = 0; $cash = 0.0; $text = '';
            if ($prize === 'points') { $pts = [5, 10, 10, 15][mt_rand(0, 3)]; $text = '+' . $pts . ' bonus points'; }
            elseif ($prize === 'shout') { $text = 'A shout-out on the team feed'; }
            elseif ($prize === 'badge') { $text = 'Rare badge: Closer of the week'; }
            else { $cash = (float)$cfg['cash_amount']; $text = '₹' . number_format($cash, 0) . ' bonus (Admin will pay it)'; }
            $u = $pdo->prepare("UPDATE play_gifts SET status = 'opened', prize = ?, prize_text = ?, points = ?, cash = ?, opened_at = NOW() WHERE id = ? AND status = 'new'");
            $u->execute([$prize, $text, $pts, $cash, $id]);
            if ($u->rowCount() < 1) json_response(['error' => 'That gift is already opened.'], 422);
            if ($prize === 'shout' || $prize === 'badge') play_record_win($pdo, $emp, 'gift', $prize === 'badge' ? 'won a rare badge from a Mystery gift' : 'opened a Mystery gift: a shout-out 🎉', 0, 'giftw:' . $id);
            json_response(['prize' => $prize, 'text' => $text, 'points' => play_points($pdo, $emp)]);
        }
        case 'play.cfg_get': {
            if (($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Only Admin can see this.'], 403);
            json_response(['cfg' => play_cfg($pdo)]);
        }
        case 'play.cfg_set': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            if (($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Only Admin can change this.'], 403);
            $in = json_input(); $c = is_array($in['cfg'] ?? null) ? $in['cfg'] : [];
            $d = play_cfg_default(); $out = $d;
            foreach (['silver', 'gold'] as $t) foreach (['sales', 'days', 'rating'] as $k) if (isset($c[$t][$k])) $out[$t][$k] = max(0, (float)$c[$t][$k]);
            if (isset($c['gift_min_order'])) $out['gift_min_order'] = max(0, (float)$c['gift_min_order']);
            foreach (['points', 'shout', 'badge', 'cash'] as $k) if (isset($c['odds'][$k])) $out['odds'][$k] = max(0, (float)$c['odds'][$k]);
            if (isset($c['cash_on'])) $out['cash_on'] = (bool)$c['cash_on'];
            if (isset($c['cash_amount'])) $out['cash_amount'] = max(0, (float)$c['cash_amount']);
            if (isset($c['cash_cap_month'])) $out['cash_cap_month'] = max(0, (float)$c['cash_cap_month']);
            foreach (['silver', 'gold'] as $t) if (isset($c['perks'][$t])) $out['perks'][$t] = mb_substr((string)$c['perks'][$t], 0, 120);
            setting_set($pdo, 'play_cfg', json_encode($out));
            json_response(['ok' => true, 'cfg' => $out]);
        }
        case 'play.gift_report': {
            if (($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Only Admin can see this.'], 403);
            play_ensure2($pdo);
            $q = $pdo->query("SELECT g.id, e.full_name, g.why, g.prize, g.prize_text, g.cash, g.opened_at FROM play_gifts g LEFT JOIN employees e ON e.employee_id = g.employee_id WHERE g.status = 'opened' ORDER BY g.opened_at DESC LIMIT 100");
            json_response(['opened' => $q->fetchAll()]);
        }
        default: json_response(['error' => 'Unknown play action.'], 404);
    }
}

}
