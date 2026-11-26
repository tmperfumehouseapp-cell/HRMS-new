<?php
/**
 * retail_route.php — Shop wholesale → 🛣 Routes: every active shop of a city is put on one of N routes (10 by default), area by area,
 * one route per day, starting tomorrow, and the cycle repeats (day 11 = route 1 again, and so on).
 *
 *   retail.route_board  ?city=&c=     the board of one cycle (c = 0 the cycle of today, 1 the next 10 days, -1 the last one)
 *   retail.route_make   POST {days, start_date, keep_priority}   make (or make again) the routes of the city, area-wise
 *   retail.route_move   POST {shop_id, route_no, index}          drag a shop to another day (or another place in the same day)
 *   retail.route_flag   POST {shop_id, priority?, disp_flag?}    ⭐ top priority (visited first) / "display pending" by hand
 *   retail.route_clear  POST {}                                  remove the routes of the city
 *
 * Colours on the board (for the day of the cycle the card is on):
 *   green  = visited in this cycle · purple = visited but a display is still pending · red = its day has passed, not visited
 *   a red "missing" badge = a perfume of the shop's perfume list is not on its display any more
 * When a salesman visits the shops of a day in another order, the route learns that order (visited first, in the order he walked);
 * ⭐ shops always stay on top. The day's route is copied into the Visits plan (and the Retail Field phone list follows it).
 * Loaded by api.php (one hook line). Its own tables only: retail_route_stop, retail_route_cfg, retail_route_synced. PHP 7.4 safe.
 */
if (defined('RR_LOADED')) { return; }
define('RR_LOADED', 1);

function rr_ensure(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!empty($GLOBALS['RR_TABLES_READY'])) return $ok = true;   // tests (SQLite) make the tables themselves
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS retail_route_stop (stop_id INT AUTO_INCREMENT PRIMARY KEY, city VARCHAR(60) NOT NULL DEFAULT '', route_no INT NOT NULL DEFAULT 1, shop_id INT NOT NULL, sort_no INT NOT NULL DEFAULT 0, priority TINYINT NOT NULL DEFAULT 0, disp_flag TINYINT NOT NULL DEFAULT 0, updated_at DATETIME NULL, UNIQUE KEY uq_rr_city_shop (city, shop_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS retail_route_cfg (city VARCHAR(60) NOT NULL PRIMARY KEY, start_date DATE NOT NULL, days INT NOT NULL DEFAULT 10, learned TEXT NULL, made_by VARCHAR(100) NOT NULL DEFAULT '', made_at DATETIME NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS retail_route_synced (plan_id INT NOT NULL PRIMARY KEY, city VARCHAR(60) NOT NULL DEFAULT '', plan_date DATE NOT NULL)");
        $ok = true;
    } catch (PDOException $e) { error_log('retail_route tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

function rr_today(): string { return function_exists('rr_today_override') ? rr_today_override() : date('Y-m-d'); }
function rr_add(string $d, int $n): string { return date('Y-m-d', strtotime($d . ' 00:00:00') + $n * 86400 + 7200); }
function rr_diff(string $a, string $b): int { return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400); }
function rr_all(PDO $pdo, string $sql, array $a = []): array { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); }
function rr_akey($a): string { return mb_strtolower(trim((string)$a)); }

/** the city a route belongs to: "All cities" on the page means the default city (a route never mixes cities) */
function rr_city(PDO $pdo, string $raw): string
{
    $raw = trim($raw);
    if ($raw !== '') return mb_substr($raw, 0, 60);
    return function_exists('retail_default_city') ? (string)retail_default_city($pdo) : '';
}
function rr_city_where(PDO $pdo): string { return function_exists('retail_city_sql') ? retail_city_sql($pdo, 's') : 's.city'; }

function rr_cfg(PDO $pdo, string $city): ?array
{
    $r = rr_all($pdo, "SELECT city, start_date, days, learned, made_by, made_at FROM retail_route_cfg WHERE city = ?", [$city]);
    if (!$r) return null;
    $c = $r[0]; $c['start_date'] = substr((string)$c['start_date'], 0, 10); $c['days'] = max(1, (int)$c['days']);
    $l = json_decode((string)($c['learned'] ?? ''), true); $c['learned'] = is_array($l) ? $l : [];
    return $c;
}
function rr_cfg_all(PDO $pdo): array
{
    $out = [];
    try { foreach (rr_all($pdo, "SELECT city FROM retail_route_cfg") as $r) { $c = rr_cfg($pdo, (string)$r['city']); if ($c) $out[] = $c; } } catch (PDOException $e) {}
    return $out;
}

/** active shops of a city */
function rr_shops(PDO $pdo, string $city): array
{
    $rows = rr_all($pdo, "SELECT s.shop_id, s.shop_name, s.owner_name, s.mobile, s.area, s.lat, s.lng FROM retail_shops s WHERE s.status = 'active' AND " . rr_city_where($pdo) . " = ? ORDER BY s.area, s.shop_name", [$city]);
    foreach ($rows as &$r) { $r['shop_id'] = (int)$r['shop_id']; $r['lat'] = $r['lat'] !== null && $r['lat'] !== '' ? (float)$r['lat'] : null; $r['lng'] = $r['lng'] !== null && $r['lng'] !== '' ? (float)$r['lng'] : null; }
    unset($r);
    return $rows;
}
function rr_km(float $a, float $b, float $c, float $d): float
{
    $r = 6371.0; $x = deg2rad($c - $a); $y = deg2rad($d - $b);
    $h = sin($x / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($y / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

/** all shops in walking order: area after area (nearest area next), and inside an area the nearest shop next (pinned shops first) */
function rr_order(array $shops): array
{
    $areas = [];
    foreach ($shops as $s) { $k = rr_akey($s['area']); if (!isset($areas[$k])) $areas[$k] = ['key' => $k, 'shops' => [], 'lat' => 0.0, 'lng' => 0.0, 'n' => 0]; $areas[$k]['shops'][] = $s; if ($s['lat'] !== null && $s['lng'] !== null) { $areas[$k]['lat'] += $s['lat']; $areas[$k]['lng'] += $s['lng']; $areas[$k]['n']++; } }
    $pinned = []; $other = [];
    foreach ($areas as $k => $a) { if ($a['n'] > 0) { $a['lat'] /= $a['n']; $a['lng'] /= $a['n']; $pinned[] = $a; } else $other[] = $a; }
    $seq = [];
    if ($pinned) {
        $mLa = 0.0; $mLn = 0.0; foreach ($pinned as $a) { $mLa += $a['lat']; $mLn += $a['lng']; } $mLa /= count($pinned); $mLn /= count($pinned);
        $bi = 0; $bd = -1.0; foreach ($pinned as $i => $a) { $d = rr_km($mLa, $mLn, $a['lat'], $a['lng']); if ($d > $bd) { $bd = $d; $bi = $i; } }   // start at the edge of the city
        $cur = $pinned[$bi]; unset($pinned[$bi]); $seq[] = $cur;
        while ($pinned) { $bi = null; $bd = 1e12; foreach ($pinned as $i => $a) { $d = rr_km($cur['lat'], $cur['lng'], $a['lat'], $a['lng']); if ($d < $bd) { $bd = $d; $bi = $i; } } $cur = $pinned[$bi]; unset($pinned[$bi]); $seq[] = $cur; }
    }
    usort($other, function ($a, $b) { return ($a['key'] === '' ? 1 : 0) <=> ($b['key'] === '' ? 1 : 0) ?: strcmp($a['key'], $b['key']); });
    foreach ($other as $a) $seq[] = $a;
    $out = []; $pLa = null; $pLn = null;
    foreach ($seq as $a) {
        $pin = array_values(array_filter($a['shops'], function ($s) { return $s['lat'] !== null && $s['lng'] !== null; }));
        $nop = array_values(array_filter($a['shops'], function ($s) { return $s['lat'] === null || $s['lng'] === null; }));
        while ($pin) {
            $bi = 0;
            if ($pLa !== null) { $bd = 1e12; foreach ($pin as $i => $s) { $d = rr_km($pLa, $pLn, $s['lat'], $s['lng']); if ($d < $bd) { $bd = $d; $bi = $i; } } }
            $s = $pin[$bi]; array_splice($pin, $bi, 1); $out[] = $s; $pLa = $s['lat']; $pLn = $s['lng'];
        }
        usort($nop, function ($x, $y) { return strcasecmp((string)$x['shop_name'], (string)$y['shop_name']); });
        foreach ($nop as $s) $out[] = $s;
    }
    return $out;
}

/** cut the walking order into $days routes of about the same size, cutting where an area ends when that is close (so an area stays on one day) */
function rr_split(array $ordered, int $days): array
{
    $n = count($ordered); $routes = array_fill(0, $days, []);
    if (!$n) return $routes;
    $keys = array_map(function ($s) { return rr_akey($s['area']); }, $ordered);
    $cuts = []; $prev = 0;
    for ($i = 1; $i < $days; $i++) {
        $avg = ($n - $prev) / ($days - $i + 1); $t = $prev + (int)round($avg); $tol = max(1, (int)round(0.25 * $avg));   // what is left, shared by the days that are left
        $lo = $prev + 1; $hi = $n - ($days - $i);
        if ($hi < $lo) { $cuts[] = min($n, max($prev, $t)); $prev = end($cuts); continue; }
        $best = max($lo, min($hi, $t)); $bd = PHP_INT_MAX;
        for ($b = max($lo, $t - $tol); $b <= min($hi, $t + $tol); $b++) { if ($b > 0 && $b < $n && $keys[$b - 1] !== $keys[$b]) { $d = abs($b - $t); if ($d < $bd) { $bd = $d; $best = $b; } } }
        $cuts[] = $best; $prev = $best;
    }
    $cuts[] = $n; $from = 0;
    foreach ($cuts as $r => $c) { $routes[$r] = array_slice($ordered, $from, max(0, $c - $from)); $from = max($from, $c); }
    return $routes;
}

/** make the routes of a city (again): every active shop on one route. ⭐ flags and "display pending" flags are kept when asked */
function rr_make(PDO $pdo, string $city, int $days, string $start, bool $keep, string $who): array
{
    $days = max(1, min(31, $days));
    $old = []; if ($keep) foreach (rr_all($pdo, "SELECT shop_id, priority, disp_flag FROM retail_route_stop WHERE city = ?", [$city]) as $r) $old[(int)$r['shop_id']] = $r;
    $routes = rr_split(rr_order(rr_shops($pdo, $city)), $days); $now = date('Y-m-d H:i:s'); $n = 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM retail_route_stop WHERE city = ?")->execute([$city]);
        $ins = $pdo->prepare("INSERT INTO retail_route_stop (city, route_no, shop_id, sort_no, priority, disp_flag, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($routes as $ri => $list) foreach ($list as $i => $s) { $o = $old[$s['shop_id']] ?? null; $ins->execute([$city, $ri + 1, $s['shop_id'], $i + 1, $o ? (int)$o['priority'] : 0, $o ? (int)$o['disp_flag'] : 0, $now]); $n++; }
        $pdo->prepare("DELETE FROM retail_route_cfg WHERE city = ?")->execute([$city]);
        $pdo->prepare("INSERT INTO retail_route_cfg (city, start_date, days, learned, made_by, made_at) VALUES (?, ?, ?, '[]', ?, ?)")->execute([$city, $start, $days, mb_substr($who, 0, 100), $now]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return ['shops' => $n, 'sizes' => array_map('count', $routes)];
}

/** the stops of each route in the order they are walked: ⭐ first, then the route order */
function rr_stops(PDO $pdo, string $city): array
{
    $out = [];
    foreach (rr_all($pdo, "SELECT shop_id, route_no, sort_no, priority, disp_flag FROM retail_route_stop WHERE city = ? ORDER BY route_no, priority DESC, sort_no, stop_id", [$city]) as $r)
        $out[(int)$r['route_no']][] = ['shop_id' => (int)$r['shop_id'], 'sort_no' => (int)$r['sort_no'], 'priority' => (int)$r['priority'], 'disp_flag' => (int)$r['disp_flag']];
    return $out;
}
function rr_renumber(PDO $pdo, string $city, int $route, array $ids): void
{
    $u = $pdo->prepare("UPDATE retail_route_stop SET route_no = ?, sort_no = ?, updated_at = ? WHERE city = ? AND shop_id = ?"); $now = date('Y-m-d H:i:s');
    foreach (array_values($ids) as $i => $sid) $u->execute([$route, $i + 1, $now, $city, (int)$sid]);
}

/** shops that left the city / became inactive come off; a new shop joins the route that already has most shops of its area (else the shortest one) */
function rr_refresh(PDO $pdo, string $city, array $cfg): array
{
    $shops = rr_shops($pdo, $city); $byId = []; foreach ($shops as $s) $byId[$s['shop_id']] = $s;
    $have = []; foreach (rr_all($pdo, "SELECT shop_id FROM retail_route_stop WHERE city = ?", [$city]) as $r) $have[(int)$r['shop_id']] = 1;
    $gone = array_diff(array_keys($have), array_keys($byId)); $added = 0;
    if ($gone) $pdo->exec("DELETE FROM retail_route_stop WHERE city = " . $pdo->quote($city) . " AND shop_id IN (" . implode(',', array_map('intval', $gone)) . ")");
    $new = array_diff(array_keys($byId), array_keys($have));
    if ($new) {
        $stops = rr_stops($pdo, $city); $days = (int)$cfg['days'];
        foreach ($new as $sid) {
            $k = rr_akey($byId[$sid]['area']); $best = 0; $bestN = -1; $short = 1; $shortN = PHP_INT_MAX;
            for ($r = 1; $r <= $days; $r++) {
                $list = $stops[$r] ?? []; $same = 0;
                foreach ($list as $x) if (isset($byId[$x['shop_id']]) && rr_akey($byId[$x['shop_id']]['area']) === $k) $same++;
                if ($k !== '' && $same > $bestN && $same > 0) { $best = $r; $bestN = $same; }
                if (count($list) < $shortN) { $short = $r; $shortN = count($list); }
            }
            $route = $best ?: $short; $list = $stops[$route] ?? []; $pos = count($list);
            if ($best) { foreach ($list as $i => $x) if (isset($byId[$x['shop_id']]) && rr_akey($byId[$x['shop_id']]['area']) === $k) $pos = $i + 1; }
            array_splice($list, $pos, 0, [['shop_id' => $sid, 'sort_no' => 0, 'priority' => 0, 'disp_flag' => 0]]);
            $pdo->prepare("INSERT INTO retail_route_stop (city, route_no, shop_id, sort_no, priority, disp_flag, updated_at) VALUES (?, ?, ?, 0, 0, 0, ?)")->execute([$city, $route, $sid, date('Y-m-d H:i:s')]);
            rr_renumber($pdo, $city, $route, array_column($list, 'shop_id'));
            $stops[$route] = $list; $added++;
        }
    }
    return ['added' => $added, 'removed' => count($gone)];
}

/** which route is walked on a date (0 = before the routes start) */
function rr_route_for(array $cfg, string $date): int
{
    $d = rr_diff($cfg['start_date'], $date);
    return $d < 0 ? 0 : ($d % (int)$cfg['days']) + 1;
}

/** the first visit of each shop between two dates (photo visits and done plan rows) */
function rr_visits(PDO $pdo, array $ids, string $from, string $to): array
{
    $out = []; if (!$ids) return $out; $in = implode(',', array_map('intval', $ids));
    foreach (rr_all($pdo, "SELECT v.shop_id, v.visit_at, e.full_name FROM retail_visits v LEFT JOIN employees e ON e.employee_id = v.employee_id WHERE v.shop_id IN ($in) AND v.visit_at >= ? AND v.visit_at <= ? ORDER BY v.visit_at", [$from . ' 00:00:00', $to . ' 23:59:59']) as $r) {
        $sid = (int)$r['shop_id']; if (!isset($out[$sid])) $out[$sid] = ['at' => (string)$r['visit_at'], 'by' => (string)($r['full_name'] ?? ''), 'n' => 0]; $out[$sid]['n']++;
    }
    foreach (rr_all($pdo, "SELECT shop_id, plan_date, done_at FROM retail_visit_plan WHERE shop_id IN ($in) AND status = 'done' AND plan_date >= ? AND plan_date <= ?", [$from, $to]) as $r) {
        $sid = (int)$r['shop_id']; if (!isset($out[$sid])) $out[$sid] = ['at' => (string)($r['done_at'] ?: substr((string)$r['plan_date'], 0, 10) . ' 12:00:00'), 'by' => '', 'n' => 1];
    }
    return $out;
}

/** the route learns the order a salesman really walked on a day: ⭐ first, then the shops he visited (in his order), then the rest */
function rr_learn(PDO $pdo, string $city, array &$cfg): int
{
    $today = rr_today(); $days = (int)$cfg['days']; $changed = 0; $learned = $cfg['learned'];
    $stops = rr_stops($pdo, $city);
    for ($k = $days; $k >= 0; $k--) {
        $d = rr_add($today, -$k); $r = rr_route_for($cfg, $d); if (!$r || empty($stops[$r])) continue;
        $ids = array_column($stops[$r], 'shop_id'); $in = implode(',', array_map('intval', $ids));
        $seen = [];
        foreach (rr_all($pdo, "SELECT shop_id, MIN(visit_at) AS t FROM retail_visits WHERE shop_id IN ($in) AND visit_at >= ? AND visit_at <= ? GROUP BY shop_id ORDER BY t", [$d . ' 00:00:00', $d . ' 23:59:59']) as $v) $seen[] = (int)$v['shop_id'];
        if (count($seen) < 2) continue;   // one visit says nothing about the order
        $sig = implode(',', $seen);
        if (($learned[$d] ?? '') === $sig) continue;
        $pri = []; $rest = [];
        foreach ($stops[$r] as $x) { if ($x['priority']) $pri[] = $x['shop_id']; elseif (!in_array($x['shop_id'], $seen, true)) $rest[] = $x['shop_id']; }
        $order = array_merge($pri, array_values(array_filter($seen, function ($s) use ($pri) { return !in_array($s, $pri, true); })), $rest);
        rr_renumber($pdo, $city, $r, $order); $learned[$d] = $sig; $changed++;
        $stops = rr_stops($pdo, $city);
    }
    if ($changed) {
        ksort($learned); $learned = array_slice($learned, -40, null, true);
        $pdo->prepare("UPDATE retail_route_cfg SET learned = ? WHERE city = ?")->execute([json_encode($learned), $city]); $cfg['learned'] = $learned;
    }
    return $changed;
}

/** copy the route of a date into the Visits plan of that date (today or later): missing shops are added as Pending,
 *  pending shops this route added earlier that are no longer on it come off. Visits already done are never touched. */
function rr_sync_day(PDO $pdo, string $city, string $date, $emp = null): int
{
    if (!rr_ensure($pdo)) return 0;
    $cfg = rr_cfg($pdo, $city); if (!$cfg || $date < rr_today()) return 0;
    $r = rr_route_for($cfg, $date); if (!$r) return 0;
    rr_refresh($pdo, $city, $cfg);   // a shop added since then is already on its area's route
    $want = array_column(rr_stops($pdo, $city)[$r] ?? [], 'shop_id');
    $have = []; foreach (rr_all($pdo, "SELECT plan_id, shop_id FROM retail_visit_plan WHERE plan_date = ?", [$date]) as $x) $have[(int)$x['shop_id']] = (int)$x['plan_id'];
    $now = date('Y-m-d H:i:s'); $n = 0;
    foreach (rr_all($pdo, "SELECT y.plan_id, p.shop_id, p.status, p.approval FROM retail_route_synced y JOIN retail_visit_plan p ON p.plan_id = y.plan_id WHERE y.city = ? AND y.plan_date = ?", [$city, $date]) as $x) {
        if (in_array((int)$x['shop_id'], $want, true)) continue;
        if ((string)$x['status'] !== 'pending' || (string)($x['approval'] ?? '') !== '') continue;
        $pdo->prepare("DELETE FROM retail_visit_plan WHERE plan_id = ?")->execute([(int)$x['plan_id']]);
        $pdo->prepare("DELETE FROM retail_route_synced WHERE plan_id = ?")->execute([(int)$x['plan_id']]); unset($have[(int)$x['shop_id']]);
    }
    $ins = $pdo->prepare("INSERT INTO retail_visit_plan (plan_date, shop_id, status, note, created_by, created_at) VALUES (?, ?, 'pending', '', ?, ?)");
    $mark = $pdo->prepare("INSERT INTO retail_route_synced (plan_id, city, plan_date) VALUES (?, ?, ?)");
    $seq = null; try { $pdo->query("SELECT plan_id FROM rf_plan_seq LIMIT 1"); $seq = $pdo->prepare("REPLACE INTO rf_plan_seq (plan_id, seq) VALUES (?, ?)"); } catch (Throwable $e) { $seq = null; }
    foreach ($want as $i => $sid) {
        if (!isset($have[$sid])) { $ins->execute([$date, $sid, $emp ?: null, $now]); $pid = (int)$pdo->lastInsertId(); $mark->execute([$pid, $city, $date]); $have[$sid] = $pid; $n++; }
        if ($seq) { try { $seq->execute([$have[$sid], $i + 1]); } catch (Throwable $e) {} }   // the Retail Field phone list walks the route order
    }
    return $n;
}
/** every city that has routes: copy its route of this date into the Visits plan */
function rr_sync_all(PDO $pdo, string $date): void
{
    if (!rr_ensure($pdo)) return;
    foreach (rr_cfg_all($pdo) as $c) { try { rr_sync_day($pdo, (string)$c['city'], $date); } catch (Throwable $e) { error_log('rr_sync_day: ' . $e->getMessage()); } }
}
/** the Visits plan rows get their place on the route (route, position, ⭐) so the page lists them in route order */
function rr_annotate(PDO $pdo, array $day): array
{
    if (!rr_ensure($pdo) || empty($day['rows'])) return $day;
    $date = (string)($day['date'] ?? rr_today()); $pos = [];
    foreach (rr_cfg_all($pdo) as $c) {
        $r = rr_route_for($c, $date); if (!$r) continue;
        foreach (rr_stops($pdo, (string)$c['city'])[$r] ?? [] as $i => $x) $pos[$x['shop_id']] = ['route' => $r, 'pos' => $i + 1, 'pri' => $x['priority']];
    }
    foreach ($day['rows'] as &$row) { $p = $pos[(int)$row['shop_id']] ?? null; $row['route_no'] = $p ? $p['route'] : 0; $row['route_pos'] = $p ? $p['pos'] : 0; $row['route_pri'] = $p ? $p['pri'] : 0; }
    unset($row);
    return $day;
}

/** what the board shows for each shop: orders, money, display pieces, perfumes missing from the display */
function rr_shop_facts(PDO $pdo, array $ids, string $from, string $to): array
{
    $out = []; if (!$ids) return $out; $in = implode(',', array_map('intval', $ids));
    foreach ($ids as $id) $out[(int)$id] = ['orders' => 0, 'order_amt' => 0.0, 'order_qty' => 0, 'paid' => 0.0, 'balance' => 0.0, 'disp_pcs' => 0, 'disp_todo' => 0, 'missing' => [], 'list_n' => 0];
    foreach (rr_all($pdo, "SELECT shop_id, COUNT(*) AS n, SUM(qty) AS q, SUM(billed_total) AS t FROM retail_orders WHERE shop_id IN ($in) AND order_type IN ('sale','display') AND order_date >= ? AND order_date <= ? GROUP BY shop_id", [$from, $to]) as $r) { $s = (int)$r['shop_id']; $out[$s]['orders'] = (int)$r['n']; $out[$s]['order_qty'] = (int)$r['q']; $out[$s]['order_amt'] = round((float)$r['t'], 2); }
    try { foreach (rr_all($pdo, "SELECT shop_id, SUM(amount) AS a FROM retail_payments WHERE shop_id IN ($in) AND pay_date >= ? AND pay_date <= ? GROUP BY shop_id", [$from, $to]) as $r) $out[(int)$r['shop_id']]['paid'] = round((float)$r['a'], 2); } catch (PDOException $e) {}
    foreach (rr_all($pdo, "SELECT shop_id, SUM(qty - delivered_qty) AS q FROM retail_orders WHERE shop_id IN ($in) AND order_type = 'display' AND qty > delivered_qty AND status <> 'returned' GROUP BY shop_id") as $r) $out[(int)$r['shop_id']]['disp_todo'] = max(0, (int)$r['q']);
    if (function_exists('retail_ledger_balances')) { try { foreach (retail_ledger_balances($pdo, array_map('intval', $ids)) as $sid => $b) if (isset($out[(int)$sid])) $out[(int)$sid]['balance'] = round((float)$b, 2); } catch (Throwable $e) {} }
    $lists = []; try { foreach (rr_all($pdo, "SELECT shop_id, perfume FROM retail_shop_perfumes WHERE shop_id IN ($in) ORDER BY sort_no, id") as $r) $lists[(int)$r['shop_id']][] = trim((string)$r['perfume']); } catch (PDOException $e) {}
    foreach ($ids as $id) {
        $id = (int)$id; $rows = function_exists('retail_display_rows') ? retail_display_rows($pdo, $id) : [];
        $on = []; $pcs = 0; foreach ($rows as $d) { if ((int)$d['qty'] > 0) { $pcs += (int)$d['qty']; $on[mb_strtolower(trim((string)$d['product_name']))] = 1; } }
        $out[$id]['disp_pcs'] = $pcs; $want = array_values(array_unique(array_filter($lists[$id] ?? [], 'strlen'))); $out[$id]['list_n'] = count($want);
        if ($want) foreach ($want as $p) if (!isset($on[mb_strtolower($p)])) $out[$id]['missing'][] = $p;
    }
    return $out;
}

/** one cycle of the board: $c = 0 the cycle of today (or the first one when the routes start later), 1 the next, -1 the last */
function rr_board(PDO $pdo, string $city, int $c): array
{
    $cfg = rr_cfg($pdo, $city); $today = rr_today();
    $base = ['city' => $city, 'today' => $today, 'tomorrow' => rr_add($today, 1), 'has_route' => (bool)$cfg, 'active_shops' => count(rr_shops($pdo, $city))];
    if (!$cfg) return $base + ['days' => []];
    $ch = rr_refresh($pdo, $city, $cfg); $learned = rr_learn($pdo, $city, $cfg);
    try { rr_sync_day($pdo, $city, $today); } catch (Throwable $e) { error_log('rr_sync_day: ' . $e->getMessage()); }
    $D = (int)$cfg['days']; $now = rr_diff($cfg['start_date'], $today); $ci = $now < 0 ? 0 : intdiv($now, $D);
    $cyc = max(0, $ci + $c); $cs = rr_add($cfg['start_date'], $cyc * $D); $ce = rr_add($cs, $D - 1);
    $stops = rr_stops($pdo, $city); $shops = []; foreach (rr_shops($pdo, $city) as $s) $shops[$s['shop_id']] = $s;
    $ids = []; foreach ($stops as $list) foreach ($list as $x) $ids[] = $x['shop_id'];
    $vis = rr_visits($pdo, $ids, $cs, min($ce, $today)); $facts = rr_shop_facts($pdo, $ids, $cs, $ce);
    $days = []; $tot = ['shops' => 0, 'visited' => 0, 'missed' => 0, 'orders' => 0, 'order_amt' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    for ($r = 1; $r <= $D; $r++) {
        $date = rr_add($cs, $r - 1); $cards = []; $sum = ['shops' => 0, 'visited' => 0, 'missed' => 0, 'disp' => 0, 'missing' => 0, 'orders' => 0, 'order_amt' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        foreach ($stops[$r] ?? [] as $i => $x) {
            $sid = $x['shop_id']; $s = $shops[$sid] ?? null; if (!$s) continue; $f = $facts[$sid]; $v = $vis[$sid] ?? null;
            $dispPend = $x['disp_flag'] || $f['disp_todo'] > 0;
            $st = $v ? ($dispPend ? 'disp' : 'done') : ($date < $today ? 'missed' : ($date === $today ? 'today' : 'next'));
            $cards[] = ['shop_id' => $sid, 'pos' => $i + 1, 'name' => (string)$s['shop_name'], 'owner' => (string)$s['owner_name'], 'mobile' => (string)$s['mobile'], 'area' => (string)$s['area'],
                'lat' => $s['lat'], 'lng' => $s['lng'], 'priority' => $x['priority'], 'disp_flag' => $x['disp_flag'], 'status' => $st,
                'visited_at' => $v ? $v['at'] : null, 'visited_by' => $v ? $v['by'] : '', 'early' => $v && substr($v['at'], 0, 10) !== $date ? substr($v['at'], 0, 10) : null] + $f;
            $sum['shops']++; if ($v) $sum['visited']++; if ($st === 'missed') $sum['missed']++; if ($dispPend) $sum['disp']++; if ($f['missing']) $sum['missing']++;
            $sum['orders'] += $f['orders']; $sum['order_amt'] += $f['order_amt']; $sum['paid'] += $f['paid']; $sum['balance'] += max(0, $f['balance']);
        }
        foreach (['shops', 'visited', 'missed', 'orders', 'order_amt', 'paid', 'balance'] as $k) $tot[$k] += $sum[$k];
        $days[] = ['route_no' => $r, 'date' => $date, 'is_today' => $date === $today, 'past' => $date < $today, 'cards' => $cards, 'sum' => $sum];
    }
    return $base + ['start_date' => $cfg['start_date'], 'days_n' => $D, 'cycle' => $cyc + 1, 'cycle_from' => $cs, 'cycle_to' => $ce, 'today_cycle' => $cyc === $ci && $now >= 0,
        'can_prev' => $cyc > 0, 'made_by' => (string)$cfg['made_by'], 'made_at' => $cfg['made_at'], 'days' => $days, 'totals' => $tot, 'changes' => $ch + ['learned' => $learned]];
}

function rr_move(PDO $pdo, string $city, int $sid, int $route, int $index): void
{
    $cfg = rr_cfg($pdo, $city); if (!$cfg) throw new RuntimeException('Make the routes first.');
    if ($route < 1 || $route > (int)$cfg['days']) throw new RuntimeException('That day is not on the route.');
    $chk = rr_all($pdo, "SELECT priority FROM retail_route_stop WHERE city = ? AND shop_id = ?", [$city, $sid]); if (!$chk) throw new RuntimeException('That shop is not on a route.');
    $list = array_values(array_filter(rr_stops($pdo, $city)[$route] ?? [], function ($x) use ($sid) { return $x['shop_id'] !== $sid; }));
    $ids = array_column($list, 'shop_id'); $index = max(0, min(count($ids), $index));
    array_splice($ids, $index, 0, [$sid]);
    rr_renumber($pdo, $city, $route, $ids);
}

/** retail.route_* actions (called from api.php after the login and the 'retail' permission check) */
function rr_dispatch(PDO $pdo, string $action, array $auth): void
{
    if (!rr_ensure($pdo)) json_response(['error' => 'Could not create the route tables.'], 500);
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : [];
    $city = rr_city($pdo, (string)($_GET['city'] ?? ($in['city'] ?? '')));
    $err = function ($m, $c = 422) { json_response(['error' => $m], $c); };
    try {
        switch ($action) {
            case 'retail.route_board':
                json_response(rr_board($pdo, $city, (int)($_GET['c'] ?? 0)));
                break;
            case 'retail.route_make':
                $days = (int)($in['days'] ?? 10); if ($days < 1 || $days > 31) $err('Days must be 1 to 31.');
                $start = (string)($in['start_date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = rr_add(rr_today(), 1);
                if (!rr_shops($pdo, $city)) $err('No active shop in ' . ($city ?: 'this city') . ' yet.');
                $who = (string)($auth['username'] ?? '');
                $r = rr_make($pdo, $city, $days, $start, !array_key_exists('keep_priority', $in) || !empty($in['keep_priority']), $who);
                json_response(['message' => $r['shops'] . ' shops of ' . $city . ' put on ' . $days . ' routes, from ' . date('d M', strtotime($start)) . '.', 'sizes' => $r['sizes']] + rr_board($pdo, $city, 0));
                break;
            case 'retail.route_move':
                rr_move($pdo, $city, (int)($in['shop_id'] ?? 0), (int)($in['route_no'] ?? 0), (int)($in['index'] ?? 0));
                json_response(['message' => 'Moved.'] + rr_board($pdo, $city, (int)($in['c'] ?? 0)));
                break;
            case 'retail.route_flag':
                $sid = (int)($in['shop_id'] ?? 0); $set = []; $a = [];
                if (array_key_exists('priority', $in)) { $set[] = 'priority = ?'; $a[] = !empty($in['priority']) ? 1 : 0; }
                if (array_key_exists('disp_flag', $in)) { $set[] = 'disp_flag = ?'; $a[] = !empty($in['disp_flag']) ? 1 : 0; }
                if (!$set) $err('Nothing to change.');
                $a[] = date('Y-m-d H:i:s'); $a[] = $city; $a[] = $sid;
                $pdo->prepare("UPDATE retail_route_stop SET " . implode(', ', $set) . ", updated_at = ? WHERE city = ? AND shop_id = ?")->execute($a);
                json_response(['message' => 'Saved.'] + rr_board($pdo, $city, (int)($in['c'] ?? 0)));
                break;
            case 'retail.route_clear':
                $pdo->prepare("DELETE FROM retail_route_stop WHERE city = ?")->execute([$city]);
                $pdo->prepare("DELETE FROM retail_route_cfg WHERE city = ?")->execute([$city]);
                json_response(['message' => 'Routes of ' . $city . ' removed.'] + rr_board($pdo, $city, 0));
                break;
            default:
                $err('Unknown route action.', 404);
        }
    } catch (RuntimeException $e) { $err($e->getMessage()); }
}
