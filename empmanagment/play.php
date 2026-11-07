<?php
/**
 * play.php — streaks and the Wins feed for the salesperson home ("My Sales"). Loaded by api.php (answers play.* itself).  PHP 7.4-safe.
 *
 *   play.me     GET   {streak, best, today_in, wins_today}      the signed-in employee's on-time streak (days in a row, newest first)
 *   play.feed   GET   {wins:[{id, who, text, amount, at, claps, mine}]}   the team's wins of the last 7 days (first names only)
 *   play.clap   POST  {id}  -> {claps, mine}                     one 👏 per person per win (tap again to take it back)
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
        default: json_response(['error' => 'Unknown play action.'], 404);
    }
}

}
