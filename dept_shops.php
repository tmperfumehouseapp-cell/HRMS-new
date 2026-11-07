<?php
/**
 * dept_shops.php — Retail displays and visits (department "shops"), loaded by dept.php. Actions: dp.sh_*
 *
 * Uses the retail tables you already have (retail_shops with its pins, retail_orders, retail_payments, retail_visits, retail_display, retail_ml_prices, retail_cities).
 * Its own tables: dp_sh_shop (which shops are in the visit round + call notes), dp_sh_plan (today's list), dp_sh_visit (every visit with the bottle count),
 * dp_sh_miss (missed visits, one per shop per round), dp_sh_prospect (shops to call for a NEW display).
 *
 *   - the round: every display is visited once in 12 days (Boss > Questions, change in Setup); about 30 a day
 *   - the daily list: most overdue first, then shops close to them; ordered as a route (nearest next) from the Google-map pins
 *   - the photo: Claude counts the bottles (24 = full display); short -> "retake", or "it is really short" tells the manager
 *   - the money rule: bottles the shop holds x price  vs  what it owes. Owes more than it holds = it sold it, collect now
 *   - a display not visited in 12 days (+1) = a missed visit -> the "visit_missed" penalty rule (Boss > Penalties)
 * Nothing is charged until the topic is made live in Boss > Questions.
 */
if (!isset($pdo, $action) || !function_exists('dp_ensure')) { return; }
if (defined('DP_SHOPS_LOADED')) { return; }
define('DP_SHOPS_LOADED', 1);

/* ============================== settings ============================== */
function dp_sh_int($key, $def, $min, $max) { $v = (int)dp_get($key, (string)$def); return max($min, min($max, $v ?: $def)); }
function dp_sh_full() { return dp_sh_int('sh_full', 24, 1, 200); }
function dp_sh_min() { return min(dp_sh_full(), dp_sh_int('sh_min', 22, 1, 200)); }
function dp_sh_round_days() { return dp_sh_int('sh_round_days', 12, 2, 60); }
function dp_sh_per_day() { return dp_sh_int('sh_per_day', 30, 1, 200); }

/* ============================== tables ============================== */
function dp_sh_ready(PDO $pdo) {
    static $r = null; if ($r !== null) return $r;
    try { $pdo->query("SELECT shop_id, shop_name, status FROM retail_shops LIMIT 1"); $r = true; } catch (Throwable $e) { $r = false; }
    return $r;
}
function dp_sh_has_col(PDO $pdo, $table, $col) {
    static $c = [];
    $k = $table . '.' . $col;
    if (!isset($c[$k])) { try { $c[$k] = (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); } catch (Throwable $e) { $c[$k] = false; } }
    return $c[$k];
}
function dp_sh_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_dp_shops_v1_' . substr(md5(__DIR__), 0, 8);
    $have = is_file($flag);
    if ($have) { try { $pdo->query("SELECT 1 FROM dp_sh_visit LIMIT 1"); $pdo->query("SELECT 1 FROM dp_sh_prospect LIMIT 1"); } catch (Throwable $e) { $have = false; } }
    if ($have) return;
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_sh_shop (
        shop_id INT PRIMARY KEY, in_round TINYINT NOT NULL DEFAULT 1, added_at DATE NOT NULL, seed_due DATE NULL, source VARCHAR(8) NOT NULL DEFAULT 'auto',
        phone2 VARCHAR(30) NULL, call_state VARCHAR(10) NOT NULL DEFAULT 'none', call_at DATETIME NULL, call_note VARCHAR(255) NULL
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_sh_plan (
        id INT AUTO_INCREMENT PRIMARY KEY, plan_day DATE NOT NULL, city VARCHAR(60) NOT NULL, shop_id INT NOT NULL, ord SMALLINT NOT NULL DEFAULT 0, extra TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_plan (plan_day, shop_id), INDEX (plan_day, city)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_sh_visit (
        id INT AUTO_INCREMENT PRIMARY KEY, shop_id INT NOT NULL, emp_id INT NULL, city VARCHAR(60) NOT NULL DEFAULT '', visit_day DATE NOT NULL, visit_at DATETIME NOT NULL,
        photo VARCHAR(160) NULL, retail_visit_id INT NULL, sha1 CHAR(40) NULL, ahash CHAR(64) NULL, status VARCHAR(8) NOT NULL DEFAULT 'ok',
        ai_state VARCHAR(8) NOT NULL DEFAULT 'skip', ai_count INT NULL, expected INT NOT NULL DEFAULT 24, ai_flags VARCHAR(255) NULL, ai_note VARCHAR(255) NULL, short_confirmed TINYINT NOT NULL DEFAULT 0, short_note VARCHAR(255) NULL,
        b20 INT NULL, b50 INT NULL, stock_value DECIMAL(12,2) NULL, due DECIMAL(12,2) NULL, collect DECIMAL(12,2) NULL, due_flag TINYINT NOT NULL DEFAULT 0, price_note VARCHAR(160) NULL,
        lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL, distance_m INT NULL, note VARCHAR(255) NULL,
        INDEX (shop_id, status, visit_day), INDEX (visit_day, city), INDEX (ahash)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_sh_miss (
        id INT AUTO_INCREMENT PRIMARY KEY, shop_id INT NOT NULL, cycle_due DATE NOT NULL, found_on DATE NOT NULL, city VARCHAR(60) NOT NULL DEFAULT '', UNIQUE KEY uq_miss (shop_id, cycle_due)
    )$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dp_sh_prospect (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, phone VARCHAR(40) NULL, address VARCHAR(255) NULL, area VARCHAR(80) NULL, city VARCHAR(60) NOT NULL DEFAULT '',
        lat DECIMAL(10,7) NULL, lng DECIMAL(10,7) NULL, place_id VARCHAR(120) NULL, source VARCHAR(8) NOT NULL DEFAULT 'manual', call_state VARCHAR(10) NOT NULL DEFAULT 'none', call_at DATETIME NULL, call_note VARCHAR(255) NULL,
        shop_id INT NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_place (place_id), INDEX (city, call_state)
    )$E");
    @file_put_contents($flag, '1');
}

/* ============================== cities, seats, pins ============================== */
function dp_sh_default_city(PDO $pdo) {
    $v = '';
    if (function_exists('setting_get')) { try { $v = trim((string)setting_get($pdo, 'retail_default_city', '')); } catch (Throwable $e) { $v = ''; } }
    return $v !== '' ? $v : 'Surat';
}
function dp_sh_city_expr(PDO $pdo, $alias = 's') {
    if (!dp_sh_has_col($pdo, 'retail_shops', 'city')) return $pdo->quote(dp_sh_default_city($pdo));
    return "(CASE WHEN $alias.city IS NULL OR $alias.city = '' THEN " . $pdo->quote(dp_sh_default_city($pdo)) . " ELSE $alias.city END)";
}
function dp_sh_slug($city) { $s = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$city))); return $s !== '' ? $s : 'city'; }
function dp_sh_seat_code($city) { return 'retaildist.visitor.' . dp_sh_slug($city); }
function dp_sh_seat_for_city(PDO $pdo, $city) {
    $code = dp_sh_seat_code($city);
    if (!bd_seat_id($pdo, $code)) dp_seat_ensure($pdo, $code, 'Display visitor (' . $city . ')', 'retaildist');
    return $code;
}
function dp_sh_cities(PDO $pdo, $onlyRound = true) {
    $cx = dp_sh_city_expr($pdo, 's'); $out = [];
    $sql = "SELECT $cx AS c, COUNT(*) AS n FROM retail_shops s " . ($onlyRound ? "JOIN dp_sh_shop d ON d.shop_id = s.shop_id AND d.in_round = 1 " : '') . "WHERE s.status = 'active' GROUP BY c ORDER BY n DESC";
    try { foreach ($pdo->query($sql)->fetchAll() as $r) $out[$r['c']] = (int)$r['n']; } catch (Throwable $e) {}
    try { foreach ($pdo->query("SELECT name FROM retail_cities ORDER BY sort_order, name")->fetchAll() as $r) { if (!isset($out[$r['name']])) $out[$r['name']] = 0; } } catch (Throwable $e) {}
    return $out;
}
function dp_sh_is_mgr(PDO $pdo, array $auth) { return dp_can_seat($pdo, $auth, 'retaildist.manager'); }
function dp_sh_need_mgr(PDO $pdo, array $auth) { if (!dp_sh_is_mgr($pdo, $auth)) json_response(['error' => 'Only the retail distribution manager or a boss can do this.'], 403); }
/** the cities this login may work in: all for a manager, else the cities whose visitor seat he holds */
function dp_sh_my_cities(PDO $pdo, array $auth) {
    $all = array_keys(dp_sh_cities($pdo, false)); $mgr = dp_sh_is_mgr($pdo, $auth); $out = [];
    foreach ($all as $c) { if ($mgr || dp_can_seat($pdo, $auth, dp_sh_seat_code($c))) $out[] = $c; }
    return $out;
}
function dp_sh_need_city(PDO $pdo, array $auth, $city) {
    if (dp_sh_is_mgr($pdo, $auth)) return;
    if (!in_array($city, dp_sh_my_cities($pdo, $auth), true)) json_response(['error' => 'Only the display visitor of ' . $city . ' (or a boss) can do this.'], 403);
}
function dp_sh_km($la1, $ln1, $la2, $ln2) {
    $dLa = deg2rad($la2 - $la1); $dLn = deg2rad($ln2 - $ln1);
    $a = sin($dLa / 2) * sin($dLa / 2) + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLn / 2) * sin($dLn / 2);
    return 2 * 6371.0 * asin(min(1.0, sqrt($a)));
}
function dp_sh_parse_geo($txt) {
    if (function_exists('retail_parse_location')) { try { $r = retail_parse_location($txt); if (is_array($r)) return $r; } catch (Throwable $e) {} }
    $txt = urldecode(trim((string)$txt)); if ($txt === '') return null;
    foreach (['/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', '/[?&]q=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', '/[?&]ll=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', '/(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/'] as $re) {
        if (preg_match($re, $txt, $m)) { $la = (float)$m[1]; $ln = (float)$m[2]; if (abs($la) <= 90 && abs($ln) <= 180) return ['lat' => $la, 'lng' => $ln]; }
    }
    return null;
}
function dp_sh_maps_link($lat, $lng, $name = '') { return $lat === null || $lng === null ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng); }

/* ============================== the round ============================== */
/** every active shop that has a display joins the round (once). New shops get their first visit spread over the next days so day 13 does not fall on everybody. */
function dp_sh_sync(PDO $pdo) {
    if (!dp_sh_ready($pdo)) return 0;
    $R = dp_sh_round_days(); $today = bd_today(); $n = 0;
    $has = "(EXISTS (SELECT 1 FROM retail_orders o WHERE o.shop_id = s.shop_id AND o.order_type = 'display' AND o.delivered_qty > 0)";
    if (dp_sh_has_table($pdo, 'retail_display')) $has .= " OR EXISTS (SELECT 1 FROM retail_display d WHERE d.shop_id = s.shop_id AND d.qty > 0)";
    $has .= ")";
    try { $rows = $pdo->query("SELECT s.shop_id FROM retail_shops s LEFT JOIN dp_sh_shop x ON x.shop_id = s.shop_id WHERE x.shop_id IS NULL AND s.status = 'active' AND $has ORDER BY s.area, s.shop_id")->fetchAll(); } catch (Throwable $e) { return 0; }
    $i = 0;
    foreach ($rows as $r) {
        $seed = date('Y-m-d', strtotime($today . ' +' . (1 + ($i % max(1, $R - 1))) . ' days')); $i++;
        $pdo->prepare("INSERT IGNORE INTO dp_sh_shop (shop_id, in_round, added_at, seed_due, source) VALUES (?,?,?,?, 'auto')")->execute([(int)$r['shop_id'], 1, $today, $seed]); $n++;
    }
    return $n;
}
function dp_sh_has_table(PDO $pdo, $t) { static $c = []; if (!isset($c[$t])) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $c[$t] = true; } catch (Throwable $e) { $c[$t] = false; } } return $c[$t]; }
/** the shops of the round with last visit, due day, days since. $city '' = all. */
function dp_sh_rows(PDO $pdo, $city = '', $today = null) {
    if (!dp_sh_ready($pdo)) return [];
    $today = $today ?: bd_today(); $R = dp_sh_round_days();
    $cx = dp_sh_city_expr($pdo, 's');
    $lat = dp_sh_has_col($pdo, 'retail_shops', 'lat') ? 's.lat, s.lng' : 'NULL AS lat, NULL AS lng';
    $rv = dp_sh_has_table($pdo, 'retail_visits') ? "(SELECT MAX(DATE(rv.visit_at)) FROM retail_visits rv WHERE rv.shop_id = s.shop_id)" : "NULL";
    $sql = "SELECT s.shop_id, s.shop_name, s.owner_name, s.mobile, s.area, s.address, $lat, $cx AS city, d.added_at, d.seed_due, d.source, d.call_state,
            (SELECT MAX(v.visit_day) FROM dp_sh_visit v WHERE v.shop_id = s.shop_id AND v.status = 'ok') AS last_dp, $rv AS last_rv
            FROM retail_shops s JOIN dp_sh_shop d ON d.shop_id = s.shop_id WHERE s.status = 'active' AND d.in_round = 1" . ($city !== '' ? " AND $cx = " . $pdo->quote($city) : '') . " ORDER BY s.shop_id";
    $live = dp_get('sh_live_since', '');
    $out = [];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $last = max((string)$r['last_dp'], (string)$r['last_rv']); $last = $last !== '' ? $last : null;
        if ($last !== null) $due = date('Y-m-d', strtotime($last . ' +' . $R . ' days'));
        else $due = $r['seed_due'] ?: date('Y-m-d', strtotime($r['added_at'] . ' +' . $R . ' days'));
        $days = $last !== null ? (int)round((strtotime($today) - strtotime($last)) / 86400) : null;
        // a miss is counted from the later of "last visit" and "the day this went live" so nobody pays for the time before go-live
        $base = $last !== null ? $last : $r['added_at']; if ($live !== '' && $live > $base) $base = $live;
        $missAt = date('Y-m-d', strtotime($base . ' +' . ($R + 1) . ' days'));
        $r['last'] = $last; $r['due_on'] = $due; $r['days'] = $days; $r['overdue_days'] = (int)round((strtotime($today) - strtotime($due)) / 86400);
        $r['miss_on'] = $missAt;
        $r['missed'] = $today >= $r['miss_on'];
        $r['lat'] = $r['lat'] !== null ? (float)$r['lat'] : null; $r['lng'] = $r['lng'] !== null ? (float)$r['lng'] : null;
        $out[(int)$r['shop_id']] = $r;
    }
    return $out;
}

/** price of one bottle of this size for this shop: its own latest display/sale price, else the default for the size */
function dp_sh_price(PDO $pdo, $shopId, $ml) {
    $ml = preg_replace('/[^0-9.]/', '', (string)$ml);
    try {
        $st = $pdo->prepare("SELECT i.price FROM retail_order_items i JOIN retail_orders o ON o.order_id = i.order_id WHERE o.shop_id = ? AND o.order_type IN ('display','sale') AND i.price > 0 AND REPLACE(LOWER(i.ml),' ml','') = ? ORDER BY o.order_date DESC, i.item_id DESC LIMIT 1");
        $st->execute([(int)$shopId, $ml]); $p = $st->fetchColumn(); if ($p !== false && (float)$p > 0) return [round((float)$p, 2), 'shop price'];
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT price FROM retail_display WHERE shop_id = ? AND price > 0 AND REPLACE(LOWER(ml),' ml','') = ? ORDER BY display_id DESC LIMIT 1"); $st->execute([(int)$shopId, $ml]); $p = $st->fetchColumn();
        if ($p !== false && (float)$p > 0) return [round((float)$p, 2), 'shop price'];
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT ml, price FROM retail_ml_prices")->fetchAll() as $r) { if (preg_replace('/[^0-9.]/', '', (string)$r['ml']) === $ml && (float)$r['price'] > 0) return [round((float)$r['price'], 2), 'default price']; }
    } catch (Throwable $e) {}
    return [0.0, 'no price set'];
}
/** what the shop still owes: opening balance + bills (pending/cash/upi) - payments. Same maths as the Retail ledger. */
function dp_sh_due(PDO $pdo, $shopId) {
    if (function_exists('retail_ledger_balances')) { try { $b = retail_ledger_balances($pdo, [(int)$shopId]); if (isset($b[(int)$shopId])) return max(0.0, round((float)$b[(int)$shopId], 2)); } catch (Throwable $e) {} }
    try {
        $st = $pdo->prepare("SELECT opening_balance FROM retail_shops WHERE shop_id = ?"); $st->execute([(int)$shopId]); $bal = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN order_type <> 'return' AND payment IN ('pending','cash','upi') THEN billed_total ELSE 0 END),0) FROM retail_orders WHERE shop_id = ?"); $st->execute([(int)$shopId]); $bal += (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM retail_payments WHERE shop_id = ?"); $st->execute([(int)$shopId]); $bal -= (float)$st->fetchColumn();
        return max(0.0, round($bal, 2));
    } catch (Throwable $e) { return 0.0; }
}
/** the stock-vs-due rule. [stock_value, due, collect_now, flag, text, price_note] */
function dp_sh_money_rule(PDO $pdo, $shopId, $b20, $b50) {
    list($p20, $s20) = dp_sh_price($pdo, $shopId, '20'); list($p50, $s50) = dp_sh_price($pdo, $shopId, '50');
    $stock = round(max(0, (int)$b20) * $p20 + max(0, (int)$b50) * $p50, 2);
    $due = dp_sh_due($pdo, $shopId);
    $missing = ((int)$b20 > 0 && $p20 <= 0) || ((int)$b50 > 0 && $p50 <= 0);
    $note = ((int)$b20 > 0 ? (int)$b20 . ' x ' . dp_inr($p20) . ' (' . $s20 . ')' : '') . ((int)$b20 > 0 && (int)$b50 > 0 ? ' + ' : '') . ((int)$b50 > 0 ? (int)$b50 . ' x ' . dp_inr($p50) . ' 50 ml (' . $s50 . ')' : '');
    if ($missing) return [$stock, $due, 0.0, 0, 'No price is set for this size. Set it in Retail prices; until then the money rule cannot be checked.', $note];
    if ($due <= 0.5) return [$stock, $due, 0.0, 0, 'Nothing is due. All paid.', $note];
    if ($due > $stock + 0.5) { $collect = round($due - $stock, 2); return [$stock, $due, $collect, 1, 'This shop sold more and paid less. It owes ' . dp_inr($due) . ' but holds only ' . dp_inr($stock) . ' of stock. Collect ' . dp_inr($collect) . ' now.', $note]; }
    return [$stock, $due, 0.0, 0, 'The goods are still in stock (' . dp_inr($stock) . ' on the shelf, ' . dp_inr($due) . ' due). It can pay later.', $note];
}

/* ============================== today's list ============================== */
function dp_sh_visited_today(PDO $pdo, $city, $day) {
    $st = $pdo->prepare("SELECT shop_id FROM dp_sh_visit WHERE visit_day = ? AND status = 'ok' AND city = ?"); $st->execute([$day, $city]);
    return array_map('intval', array_column($st->fetchAll(), 'shop_id'));
}
function dp_sh_route(array $rows, $startLat = null, $startLng = null) {
    $pinned = []; $loose = [];
    foreach ($rows as $r) { if ($r['lat'] !== null) $pinned[] = $r; else $loose[] = $r; }
    $out = []; $cl = $startLat; $cg = $startLng;
    if ($cl === null && $pinned) { $cl = $pinned[0]['lat']; $cg = $pinned[0]['lng']; }
    while ($pinned) {
        $bi = 0; $bd = 1e9;
        foreach ($pinned as $i => $p) { $d = dp_sh_km($cl, $cg, $p['lat'], $p['lng']); if ($d < $bd) { $bd = $d; $bi = $i; } }
        $x = array_splice($pinned, $bi, 1)[0]; $x['leg_km'] = round($bd, 1); $out[] = $x; $cl = $x['lat']; $cg = $x['lng'];
    }
    usort($loose, function ($a, $b) { return [(string)$a['area'], $a['due_on']] <=> [(string)$b['area'], $b['due_on']]; });
    foreach ($loose as $x) { $x['leg_km'] = null; $out[] = $x; }
    return $out;
}
/** build (once per day and city) the list of shops to visit. */
function dp_sh_plan_make(PDO $pdo, $city, $day) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM dp_sh_plan WHERE plan_day = ? AND city = ?"); $st->execute([$day, $city]); if ((int)$st->fetchColumn() > 0) return;
    $N = dp_sh_per_day(); $rows = dp_sh_rows($pdo, $city, $day);
    $done = array_flip(dp_sh_visited_today($pdo, $city, $day));
    $c = array_values(array_filter($rows, function ($r) use ($done) { return !isset($done[(int)$r['shop_id']]); }));
    usort($c, function ($a, $b) { return [$a['due_on'], $a['shop_id']] <=> [$b['due_on'], $b['shop_id']]; });
    $chosen = [];
    foreach ($c as $r) { if ($r['due_on'] <= $day && count($chosen) < $N) $chosen[] = $r; }
    if (count($chosen) < $N) {
        $ids = array_flip(array_column($chosen, 'shop_id'));
        $soon = date('Y-m-d', strtotime($day . ' +4 days'));
        $pool = array_values(array_filter($c, function ($r) use ($ids, $soon) { return !isset($ids[$r['shop_id']]) && $r['due_on'] <= $soon; }));
        $pin = array_values(array_filter($chosen, function ($r) { return $r['lat'] !== null; }));
        if (!$chosen) { $seed = $pool ? $pool[0] : null; if ($seed) { $chosen[] = $seed; $pool = array_values(array_filter($pool, function ($r) use ($seed) { return $r['shop_id'] !== $seed['shop_id']; })); $pin = $seed['lat'] !== null ? [$seed] : []; } }
        $areas = []; foreach ($chosen as $r) $areas[(string)$r['area']] = 1;
        if ($pin) { $cl = array_sum(array_column($pin, 'lat')) / count($pin); $cg = array_sum(array_column($pin, 'lng')) / count($pin); }
        usort($pool, function ($a, $b) use ($pin, $cl, $cg, $areas) {
            if ($pin) { $da = $a['lat'] !== null ? dp_sh_km($cl, $cg, $a['lat'], $a['lng']) : 999; $db = $b['lat'] !== null ? dp_sh_km($cl, $cg, $b['lat'], $b['lng']) : 999; if (abs($da - $db) > 0.001) return $da <=> $db; }
            $sa = isset($areas[(string)$a['area']]) ? 0 : 1; $sb = isset($areas[(string)$b['area']]) ? 0 : 1; if ($sa !== $sb) return $sa <=> $sb;
            return [$a['due_on'], $a['shop_id']] <=> [$b['due_on'], $b['shop_id']];
        });
        foreach ($pool as $r) { if (count($chosen) >= $N) break; $chosen[] = $r; }
    }
    $ord = 0;
    foreach (dp_sh_route($chosen) as $r) { $pdo->prepare("INSERT IGNORE INTO dp_sh_plan (plan_day, city, shop_id, ord) VALUES (?,?,?,?)")->execute([$day, $city, (int)$r['shop_id'], ++$ord]); }
}
function dp_sh_today_data(PDO $pdo, $city, $day, $lat = null, $lng = null) {
    dp_sh_plan_make($pdo, $city, $day);
    $rows = dp_sh_rows($pdo, $city, $day);
    $st = $pdo->prepare("SELECT * FROM dp_sh_plan WHERE plan_day = ? AND city = ? ORDER BY ord, id"); $st->execute([$day, $city]); $plan = $st->fetchAll();
    $st = $pdo->prepare("SELECT v.* FROM dp_sh_visit v WHERE v.visit_day = ? AND v.city = ? ORDER BY v.id"); $st->execute([$day, $city]);
    $vis = []; foreach ($st->fetchAll() as $v) { $vis[(int)$v['shop_id']] = $v; }   // the latest attempt wins
    $items = [];
    foreach ($plan as $p) {
        $sid = (int)$p['shop_id']; $r = $rows[$sid] ?? null; if (!$r) { $sq = $pdo->prepare("SELECT s.shop_id, s.shop_name, s.owner_name, s.mobile, s.area, s.address, s.lat, s.lng FROM retail_shops s WHERE s.shop_id = ?"); $sq->execute([$sid]); $r = $sq->fetch(); if (!$r) continue; $r['last'] = null; $r['days'] = null; $r['due_on'] = $day; $r['overdue_days'] = 0; $r['lat'] = $r['lat'] !== null ? (float)$r['lat'] : null; $r['lng'] = $r['lng'] !== null ? (float)$r['lng'] : null; }
        $v = $vis[$sid] ?? null; $state = 'todo'; $vv = null;
        if ($v) { $state = $v['status'] === 'ok' ? 'done' : ($v['status'] === 'short' ? 'short' : 'todo'); $vv = dp_sh_visit_view($pdo, $v); }
        $items[] = ['shop_id' => $sid, 'ord' => (int)$p['ord'], 'extra' => (int)$p['extra'], 'name' => $r['shop_name'], 'owner' => $r['owner_name'], 'phone' => $r['mobile'], 'area' => $r['area'], 'address' => $r['address'],
            'lat' => $r['lat'], 'lng' => $r['lng'], 'pin' => $r['lat'] !== null, 'maps' => dp_sh_maps_link($r['lat'], $r['lng']), 'last' => $r['last'], 'days' => $r['days'], 'due_on' => $r['due_on'], 'overdue_days' => (int)$r['overdue_days'],
            'owes' => dp_sh_due($pdo, $sid), 'state' => $state, 'visit' => $vv];
    }
    // visits of shops that were not in the list (the visitor went somewhere else) still count
    $inPlan = array_flip(array_column($plan, 'shop_id'));
    foreach ($vis as $sid => $v) { if (!isset($inPlan[$sid]) && in_array($v['status'], ['ok', 'short'], true)) { $sn = $rows[$sid] ?? null; $items[] = ['shop_id' => $sid, 'ord' => 999, 'extra' => 1, 'name' => $sn ? $sn['shop_name'] : ('Shop #' . $sid), 'owner' => '', 'phone' => '', 'area' => $sn ? $sn['area'] : '', 'address' => '', 'lat' => null, 'lng' => null, 'pin' => false, 'maps' => '', 'last' => null, 'days' => null, 'due_on' => $day, 'overdue_days' => 0, 'owes' => dp_sh_due($pdo, $sid), 'state' => $v['status'] === 'ok' ? 'done' : 'short', 'visit' => dp_sh_visit_view($pdo, $v)]; } }
    // the route for what is still to do can start from where the visitor stands
    if ($lat !== null && $lng !== null) {
        $todo = array_values(array_filter($items, function ($i) { return $i['state'] !== 'done'; })); $done = array_values(array_filter($items, function ($i) { return $i['state'] === 'done'; }));
        $todo = dp_sh_route(array_map(function ($i) { return $i + ['area' => $i['area']]; }, $todo), (float)$lat, (float)$lng);
        $items = array_merge($todo, $done);
    }
    $n = count($items); $d = count(array_filter($items, function ($i) { return $i['state'] === 'done'; }));
    return ['city' => $city, 'day' => $day, 'items' => $items, 'total' => $n, 'done' => $d, 'full' => dp_sh_full(), 'min' => dp_sh_min()];
}
function dp_sh_visit_view(PDO $pdo, array $v) {
    return ['id' => (int)$v['id'], 'status' => $v['status'], 'at' => $v['visit_at'], 'ai_state' => $v['ai_state'], 'count' => $v['ai_count'] !== null ? (int)$v['ai_count'] : null, 'expected' => (int)$v['expected'],
        'flags' => $v['ai_flags'] ? array_values(array_filter(explode('|', $v['ai_flags']))) : [], 'note' => $v['ai_note'], 'short_confirmed' => (int)$v['short_confirmed'],
        'b20' => $v['b20'] !== null ? (int)$v['b20'] : null, 'b50' => $v['b50'] !== null ? (int)$v['b50'] : null, 'stock_value' => $v['stock_value'] !== null ? (float)$v['stock_value'] : null, 'due' => $v['due'] !== null ? (float)$v['due'] : null,
        'collect' => (float)$v['collect'], 'due_flag' => (int)$v['due_flag'], 'price_note' => $v['price_note'], 'distance_m' => $v['distance_m'] !== null ? (int)$v['distance_m'] : null, 'has_photo' => !empty($v['photo'])];
}

/* ============================== the photo: Claude counts the bottles ============================== */
/** [state, count, flags[], note]. state = pass | short | retake | skip (AI not available - a person looks later) */
function dp_sh_count_photo($absPath, $expected, $minOk) {
    if (dp_get('proof_ai', '1') !== '1' || !function_exists('convo_ai') || !function_exists('bd_b64_small')) return ['skip', null, [], 'The AI helper is not available, so a person will check this photo.'];
    $img = bd_b64_small($absPath); if (!$img) return ['skip', null, [], 'Could not read the photo.'];
    $sys = "You check the photo of a perfume bottle display at a retail shop. A FULL display holds $expected bottles (4 rows of 6). "
        . "Count the perfume bottles you can really see standing on the display ('bottles', a whole number; 0 if none). "
        . "Say not_a_display=true if the photo is not of the bottle display (a person, a wall, a screen, too dark or blurry to count). "
        . "Say broken=true if the display stand or a bottle is broken or fallen, sticker_missing=true if the brand sticker/label of the display is missing or removed, not_filled=true if there are visible empty gaps. "
        . "'reason' is ONE short plain-English sentence the salesperson can act on.";
    $schema = ['type' => 'OBJECT', 'properties' => ['bottles' => ['type' => 'INTEGER'], 'not_a_display' => ['type' => 'BOOLEAN'], 'broken' => ['type' => 'BOOLEAN'], 'sticker_missing' => ['type' => 'BOOLEAN'], 'not_filled' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['bottles', 'not_a_display']];
    $__shBrain = class_exists('BrainScope') ? new BrainScope('internal', 'shops') : null;
    try { $r = convo_ai($sys, [['text' => 'The display photo:'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]], $schema, false, 400); } catch (Throwable $e) { $r = null; }
    if (!is_array($r) || empty($r[0]) || !is_array($r[1]) || !array_key_exists('bottles', $r[1])) return ['skip', null, [], 'The AI could not check this photo, so a person will.'];
    $v = $r[1]; $flags = [];
    if (!empty($v['broken'])) $flags[] = 'broken display';
    if (!empty($v['sticker_missing'])) $flags[] = 'sticker removed';
    if (!empty($v['not_filled'])) $flags[] = 'display not filled';
    $note = mb_substr(trim((string)($v['reason'] ?? '')), 0, 240); $count = (int)$v['bottles'];
    if (!empty($v['not_a_display'])) return ['retake', $count, $flags, $note !== '' ? $note : 'This does not look like the bottle display. Stand back so the whole display is in the photo.'];
    if ($count < $minOk) return ['short', $count, $flags, $note];
    return ['pass', $count, $flags, $note];
}

/* ============================== a visit ============================== */
function dp_sh_shop_row(PDO $pdo, $shopId) {
    $cx = dp_sh_city_expr($pdo, 's');
    $st = $pdo->prepare("SELECT s.*, $cx AS city_x FROM retail_shops s WHERE s.shop_id = ?"); $st->execute([(int)$shopId]); $r = $st->fetch();
    if ($r) $r['city'] = $r['city_x'];
    return $r ?: null;
}
function dp_sh_save_photo_copy($srcAbs, $shopId) {
    $dir = __DIR__ . '/uploads/retail/'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'visit_' . (int)$shopId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.jpg';
    return is_dir($dir) && @copy($srcAbs, $dir . $name) ? 'uploads/retail/' . $name : '';
}
function dp_sh_refresh_day_item(PDO $pdo, $city, $day) {
    $seat = dp_sh_seat_for_city($pdo, $city); $ref = 'sh:day:' . $seat . ':' . $day;
    $it = $pdo->prepare("SELECT id, status FROM dp_items WHERE ref_key = ?"); $it->execute([$ref]); $row = $it->fetch(); if (!$row) return;
    $st = $pdo->prepare("SELECT COUNT(*) FROM dp_sh_plan WHERE plan_day = ? AND city = ?"); $st->execute([$day, $city]); $tot = (int)$st->fetchColumn();
    $done = count(dp_sh_visited_today($pdo, $city, $day)); $tot = max($tot, $done);
    $pdo->prepare("UPDATE dp_items SET title = ?, qty = ? WHERE id = ?")->execute(['Visit ' . $tot . ' displays today in ' . $city . ' (' . $done . ' done)', max(1, $tot), $row['id']]);
    if ($done >= $tot && $tot > 0 && $row['status'] === 'open') dp_item_done($pdo, (int)$row['id']);
}
function dp_sh_not_live_msg() { return 'Not live yet. Answer Claude\'s questions in Boss > Questions and press "This is right, make it live".'; }

/* ============================== the tick ============================== */
function dp_shops_tick(PDO $pdo, $now) {
    bd_ensure($pdo); dp_sh_ensure($pdo);
    if (!dp_sh_ready($pdo)) return 'retail tables not found';
    $res = ['synced' => dp_sh_sync($pdo)];
    $live = dp_live($pdo, 'shops');
    if (!$live) { $res['live'] = false; return $res; }
    $today = date('Y-m-d', $now);
    if (dp_get('sh_live_since', '') === '') dp_put('sh_live_since', $today);
    $res['live'] = true; $res['items'] = 0; $res['misses'] = 0;
    // 1. close yesterday's lists
    $pdo->prepare("UPDATE dp_items SET status = 'missed' WHERE kind = 'shops' AND status = 'open' AND task_date < ? AND (ref_key LIKE 'sh:day:%' OR ref_key LIKE 'sh:calls:%')")->execute([$today]);
    // 2. today's list per city + the item for the visitor
    foreach (dp_sh_cities($pdo, true) as $city => $n) {
        if ($n < 1) continue;
        $seat = dp_sh_seat_for_city($pdo, $city);
        if (date('N', $now) == 7 && dp_get('sh_sunday', '0') !== '1') continue;           // Sunday: no list (switch on in Setup if you work Sundays)
        dp_sh_plan_make($pdo, $city, $today);
        $st = $pdo->prepare("SELECT COUNT(*) FROM dp_sh_plan WHERE plan_day = ? AND city = ?"); $st->execute([$today, $city]); $tot = (int)$st->fetchColumn();
        $owner = dp_owner_for($pdo, $seat, $today);
        if ($tot > 0 && $owner) {
            $done = count(dp_sh_visited_today($pdo, $city, $today));
            $iid = dp_item_make($pdo, 'shops', null, 'sh:day:' . $seat . ':' . $today, $seat, 'Visit ' . $tot . ' displays today in ' . $city . ' (' . $done . ' done)', 'Open Retail displays: the list is ordered as a route.', $today, $today . ' 20:00:00', ['page' => 'dept_shops.html', 'city' => $city], $owner, $tot);
            if ($iid) $res['items']++;
            if ((int)date('G', $now) >= 16 && $done < $tot && dp_get('sh_nudge:' . $seat . ':' . $today, '') === '') { dp_put('sh_nudge:' . $seat . ':' . $today, '1'); if (bd_mode() !== 'shadow') bd_push($pdo, $owner, 'Displays still to visit', ($tot - $done) . ' displays are still on today\'s list in ' . $city . '.'); }
        }
        // 3. missed visits (day 13 without a visit): one item per city and day, so one penalty per day, not 40
        $missed = []; foreach (dp_sh_rows($pdo, $city, $today) as $r) { if ($r['missed']) $missed[] = $r; }
        $new = [];
        foreach ($missed as $r) {
            $ins = $pdo->prepare("INSERT IGNORE INTO dp_sh_miss (shop_id, cycle_due, found_on, city) VALUES (?,?,?,?)"); $ins->execute([(int)$r['shop_id'], $r['due_on'], $today, $city]);
            if ($ins->rowCount() > 0) $new[] = $r;
        }
        if ($new) {
            $names = array_map(function ($r) { return $r['shop_name'] . ($r['area'] ? ' (' . $r['area'] . ')' : ''); }, array_slice($new, 0, 6));
            $title = count($new) . ' display' . (count($new) === 1 ? '' : 's') . ' not visited in ' . dp_sh_round_days() . ' days in ' . $city;
            $bn = $pdo->prepare("SELECT COUNT(*) FROM dp_items WHERE ref_key LIKE ?"); $bn->execute(['sh:miss:' . dp_sh_slug($city) . ':' . $today . ':%']); $batch = (int)$bn->fetchColumn() + 1;
            $iid = dp_item_make($pdo, 'shops', 'visit_missed', 'sh:miss:' . dp_sh_slug($city) . ':' . $today . ':' . $batch, $seat, $title, implode(', ', $names) . (count($new) > 6 ? ' and ' . (count($new) - 6) . ' more' : ''), $today, date('Y-m-d H:i:s', $now), ['page' => 'dept_shops.html', 'city' => $city, 'shops' => array_map(function ($r) { return (int)$r['shop_id']; }, $new)], null, count($new));
            if ($iid) { dp_miss($pdo, $iid, 'visit_missed', count($new), count($new) . ' displays'); $res['misses'] += count($new); }
            bd_inbox('dp_note', $title, implode(', ', $names) . (count($new) > 6 ? ' and ' . (count($new) - 6) . ' more.' : '.') . ' The visitor was told. The penalty follows the "Display visit missed" rule.', ['city' => $city], null, null, 'dp_shmiss:' . dp_sh_slug($city) . ':' . $today . ':' . $batch);
        }
    }
    // 4. the manager's call item
    $mgr = dp_owner_for($pdo, 'retaildist.manager', $today);
    if ($mgr) {
        $calls = dp_sh_calls_data($pdo); $n = min(8, count($calls['prospects']));
        if ($n > 0) dp_item_make($pdo, 'shops', null, 'sh:calls:' . $today, 'retaildist.manager', 'Call ' . $n . ' shops to give a new display', 'Open Retail displays > Calls.', $today, $today . ' 18:00:00', ['page' => 'dept_shops.html', 'tab' => 'calls'], $mgr, $n);
    }
    return $res;
}

/* ============================== manager data ============================== */
function dp_sh_overview_data(PDO $pdo) {
    $day = bd_today(); $R = dp_sh_round_days(); $out = []; $red = []; $short = []; $look = []; $far = [];
    $cities = dp_sh_cities($pdo, true);
    foreach ($cities as $city => $n) {
        if ($n < 1) continue;
        $rows = dp_sh_rows($pdo, $city, $day); $total = count($rows);
        $visitedRound = 0; $overdue = 0; $never = 0; $missed = 0;
        foreach ($rows as $r) { if ($r['last'] !== null && $r['days'] !== null && $r['days'] < $R) $visitedRound++; if ($r['due_on'] < $day) $overdue++; if ($r['last'] === null) $never++; if ($r['missed']) $missed++; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM dp_sh_plan WHERE plan_day = ? AND city = ?"); $st->execute([$day, $city]); $plan = (int)$st->fetchColumn();
        $doneToday = count(dp_sh_visited_today($pdo, $city, $day));
        $seat = dp_sh_seat_code($city); $hold = bd_seat_id($pdo, $seat) ? bd_holders($pdo, bd_seat_id($pdo, $seat)) : [];
        $out[] = ['city' => $city, 'slug' => dp_sh_slug($city), 'seat' => $seat, 'visitors' => array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $hold), 'total' => $total, 'visited_round' => $visitedRound, 'pct' => $total ? (int)round(100 * $visitedRound / $total) : 0,
            'overdue' => $overdue, 'never' => $never, 'missed' => $missed, 'plan' => $plan, 'done_today' => $doneToday, 'pending_today' => max(0, $plan - $doneToday), 'days_left' => ($total - $visitedRound) > 0 ? (int)ceil(($total - $visitedRound) / dp_sh_per_day()) : 0];
    }
    // red shops: last good visit said "sold more, paid less" and the dues are still more than that stock
    $st = $pdo->prepare("SELECT v.*, s.shop_name, s.area, s.mobile FROM dp_sh_visit v JOIN retail_shops s ON s.shop_id = v.shop_id WHERE v.status = 'ok' AND v.due_flag = 1 AND v.visit_day >= ? ORDER BY v.visit_day DESC, v.id DESC");
    $st->execute([date('Y-m-d', strtotime($day . ' -' . $R . ' days'))]); $seen = [];
    foreach ($st->fetchAll() as $v) {
        if (isset($seen[$v['shop_id']])) continue; $seen[$v['shop_id']] = 1;
        $nowDue = dp_sh_due($pdo, $v['shop_id']); if ($nowDue <= (float)$v['stock_value'] + 0.5) continue;
        $red[] = ['shop_id' => (int)$v['shop_id'], 'name' => $v['shop_name'], 'area' => $v['area'], 'phone' => $v['mobile'], 'city' => $v['city'], 'visit_day' => $v['visit_day'], 'due_now' => $nowDue, 'stock' => (float)$v['stock_value'], 'collect' => round($nowDue - (float)$v['stock_value'], 2)];
    }
    $st = $pdo->prepare("SELECT v.*, s.shop_name, s.area FROM dp_sh_visit v JOIN retail_shops s ON s.shop_id = v.shop_id WHERE v.visit_day >= ? AND v.status = 'ok' ORDER BY v.id DESC LIMIT 200"); $st->execute([date('Y-m-d', strtotime($day . ' -3 days'))]);
    foreach ($st->fetchAll() as $v) {
        $row = ['id' => (int)$v['id'], 'shop_id' => (int)$v['shop_id'], 'name' => $v['shop_name'], 'area' => $v['area'], 'city' => $v['city'], 'day' => $v['visit_day'], 'count' => $v['ai_count'] !== null ? (int)$v['ai_count'] : null, 'expected' => (int)$v['expected'], 'flags' => $v['ai_flags'] ? array_values(array_filter(explode('|', $v['ai_flags']))) : [], 'note' => $v['short_note'], 'distance_m' => $v['distance_m'] !== null ? (int)$v['distance_m'] : null];
        if ((int)$v['short_confirmed'] === 1) $short[] = $row;
        elseif ($v['ai_state'] === 'skip') $look[] = $row;
        elseif ($row['flags']) $short[] = $row + ['flag_only' => 1];
        if ($v['distance_m'] !== null && (int)$v['distance_m'] > 500) $far[] = $row;
    }
    $miss = []; $st = $pdo->prepare("SELECT m.found_on, m.city, s.shop_name, s.area FROM dp_sh_miss m JOIN retail_shops s ON s.shop_id = m.shop_id WHERE m.found_on >= ? ORDER BY m.found_on DESC, s.shop_name LIMIT 60"); $st->execute([date('Y-m-d', strtotime($day . ' -14 days'))]);
    foreach ($st->fetchAll() as $m) $miss[] = ['day' => $m['found_on'], 'city' => $m['city'], 'name' => $m['shop_name'], 'area' => $m['area']];
    return ['day' => $day, 'cities' => $out, 'red' => $red, 'short' => $short, 'look' => $look, 'far' => $far, 'missed' => $miss, 'live' => dp_live($pdo, 'shops'), 'topic_state' => dp_topic_state($pdo, 'shops'), 'mode' => bd_mode(),
        'settings' => ['full' => dp_sh_full(), 'min' => dp_sh_min(), 'round_days' => $R, 'per_day' => dp_sh_per_day(), 'sunday' => dp_get('sh_sunday', '0') === '1', 'google' => dp_get('google_key', '') !== ''],
        'rule' => (function () use ($pdo) { $r = dp_rule($pdo, 'visit_missed'); return $r ? ['amount' => (float)$r['amount'], 'mode' => $r['mode'], 'active' => (int)$r['active']] : null; })()];
}
/** shops to call: (1) display shops that sold nothing / sold fast, (2) prospects (shops without a display, and places found on Google) */
function dp_sh_calls_data(PDO $pdo) {
    $day = bd_today(); $reorder = []; $prospects = [];
    $d30 = date('Y-m-d', strtotime($day . ' -30 days')); $d60 = date('Y-m-d', strtotime($day . ' -60 days')); $d45 = date('Y-m-d', strtotime($day . ' -45 days'));
    foreach (dp_sh_rows($pdo, '', $day) as $r) {
        try {
            $st = $pdo->prepare("SELECT MAX(order_date) AS last_o, SUM(CASE WHEN order_date >= ? THEN qty ELSE 0 END) AS q30, SUM(CASE WHEN order_date < ? AND order_date >= ? THEN qty ELSE 0 END) AS q60, SUM(order_date >= ?) AS n30
                FROM retail_orders WHERE shop_id = ? AND order_type <> 'return' AND order_type <> 'display'"); $st->execute([$d30, $d30, $d60, $d30, (int)$r['shop_id']]); $o = $st->fetch();
        } catch (Throwable $e) { continue; }
        $why = ''; $kind = '';
        $g = $pdo->prepare("SELECT MIN(order_date) FROM retail_orders WHERE shop_id = ? AND order_type = 'display'"); $g->execute([(int)$r['shop_id']]); $given = $g->fetchColumn();
        $since = strtotime($day) - strtotime($given ? $given : $r['added_at']);
        if ((int)$o['n30'] >= 2 || ((int)$o['q30'] >= 24 && (int)$o['q30'] >= 1.5 * (int)$o['q60'])) { $kind = 'fast'; $why = 'Sold fast: ' . (int)$o['q30'] . ' bottles ordered in 30 days. Offer a refill or a bigger display.'; }
        elseif (($o['last_o'] === null || $o['last_o'] < $d45) && $since > 20 * 86400) { $kind = 'slow'; $why = $o['last_o'] ? 'Sold nothing since ' . $o['last_o'] . '. Ask what is wrong.' : 'Never reordered since the display was given. Ask what is wrong.'; }
        if ($kind === '') continue;
        $reorder[] = ['shop_id' => (int)$r['shop_id'], 'kind' => $kind, 'name' => $r['shop_name'], 'area' => $r['area'], 'city' => $r['city'], 'phone' => $r['mobile'], 'why' => $why, 'call_state' => $r['call_state'], 'last_order' => $o['last_o']];
    }
    usort($reorder, function ($a, $b) { return [$a['kind'] === 'fast' ? 0 : 1, $a['name']] <=> [$b['kind'] === 'fast' ? 0 : 1, $b['name']]; });
    // shops that exist but have no display: the ones that ordered recently first
    $cx = dp_sh_city_expr($pdo, 's');
    try {
        $sq = $pdo->query("SELECT s.shop_id, s.shop_name, s.owner_name, s.mobile, s.area, $cx AS city, (SELECT MAX(o.order_date) FROM retail_orders o WHERE o.shop_id = s.shop_id AND o.order_type <> 'return') AS last_o,
            (SELECT COALESCE(SUM(o.qty),0) FROM retail_orders o WHERE o.shop_id = s.shop_id AND o.order_type = 'sale' AND o.order_date >= '$d60') AS q60, x.call_state, x.call_note
            FROM retail_shops s LEFT JOIN dp_sh_shop x ON x.shop_id = s.shop_id WHERE s.status = 'active' AND (x.shop_id IS NULL OR x.in_round = 0) ORDER BY q60 DESC, last_o DESC LIMIT 60");
        foreach ($sq->fetchAll() as $r) {
            $cs = $r['call_state'] ?: 'none'; if (in_array($cs, ['no', 'interested'], true)) continue;
            $prospects[] = ['key' => 's' . $r['shop_id'], 'shop_id' => (int)$r['shop_id'], 'prospect_id' => null, 'name' => $r['shop_name'], 'phone' => $r['mobile'], 'area' => $r['area'], 'city' => $r['city'], 'why' => ((int)$r['q60'] > 0 ? 'Bought ' . (int)$r['q60'] . ' bottles in 60 days, has no display yet.' : 'Existing shop with no display yet.'), 'call_state' => $cs, 'call_note' => $r['call_note'], 'source' => 'shop'];
        }
    } catch (Throwable $e) {}
    try {
        foreach ($pdo->query("SELECT * FROM dp_sh_prospect WHERE call_state IN ('none','later') AND shop_id IS NULL ORDER BY id DESC LIMIT 60")->fetchAll() as $p) {
            $prospects[] = ['key' => 'p' . $p['id'], 'shop_id' => null, 'prospect_id' => (int)$p['id'], 'name' => $p['name'], 'phone' => $p['phone'], 'area' => $p['area'], 'city' => $p['city'] ?: dp_sh_default_city($pdo), 'why' => $p['source'] === 'places' ? 'Found on Google Maps near your displays.' : 'Added by hand.', 'call_state' => $p['call_state'], 'call_note' => $p['call_note'], 'source' => $p['source'], 'maps' => dp_sh_maps_link($p['lat'] !== null ? (float)$p['lat'] : null, $p['lng'] !== null ? (float)$p['lng'] : null)];
        }
    } catch (Throwable $e) {}
    $fast = array_values(array_filter($reorder, function ($x) { return $x['kind'] === 'fast'; })); $slow = array_values(array_filter($reorder, function ($x) { return $x['kind'] !== 'fast'; }));
    usort($slow, function ($a, $b) { return strcmp((string)$a['last_order'], (string)$b['last_order']); });      // the longest silent first
    $show = array_merge(array_slice($fast, 0, 12), array_slice($slow, 0, 12));
    return ['reorder' => $show, 'reorder_more' => count($reorder) - count($show), 'prospects' => array_slice($prospects, 0, 60)];
}
function dp_sh_places_search(PDO $pdo, $q, $city) {
    $key = dp_get('google_key', '');
    if ($key === '') json_response(['error' => 'Add the Google key first (Boss > Setup > Maps and Places). Until then add shops by hand.'], 409);
    if (!function_exists('curl_init')) json_response(['error' => 'The server cannot reach Google (curl is missing).'], 500);
    $get = function ($url) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true]); $r = curl_exec($ch); curl_close($ch); return $r === false ? null : json_decode($r, true); };
    $j = $get('https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . rawurlencode($q . ($city ? ' in ' . $city : '')) . '&key=' . rawurlencode($key));
    if (!is_array($j)) json_response(['error' => 'Google did not answer. Try again.'], 502);
    if (!in_array($j['status'] ?? '', ['OK', 'ZERO_RESULTS'], true)) json_response(['error' => 'Google said: ' . ($j['status'] ?? '?') . ' ' . ($j['error_message'] ?? '')], 502);
    $out = [];
    foreach (array_slice($j['results'] ?? [], 0, 8) as $p) {
        $phone = '';
        $d = $get('https://maps.googleapis.com/maps/api/place/details/json?fields=formatted_phone_number,international_phone_number&place_id=' . rawurlencode($p['place_id']) . '&key=' . rawurlencode($key));
        if (is_array($d) && !empty($d['result'])) $phone = $d['result']['international_phone_number'] ?? ($d['result']['formatted_phone_number'] ?? '');
        $out[] = ['place_id' => $p['place_id'], 'name' => $p['name'] ?? '', 'address' => $p['formatted_address'] ?? '', 'phone' => $phone, 'lat' => $p['geometry']['location']['lat'] ?? null, 'lng' => $p['geometry']['location']['lng'] ?? null];
    }
    return $out;
}

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'dp.sh_') === 0) {
    bd_ensure($pdo); dp_ensure($pdo); dp_sh_ensure($pdo);
    $auth = require_auth();
    $w = bd_who($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $in = $isPost ? json_input() : [];
    if (!dp_sh_ready($pdo)) json_response(['error' => 'The retail shops are not set up yet. Open Retail once so its tables are created, then come back.'], 503);
    dp_tick_after($pdo);
    $today = bd_today();

    switch ($action) {

    case 'dp.sh_overview': {
        dp_sh_need_mgr($pdo, $auth);
        if (dp_get('sh_sync_at', '') < date('Y-m-d H:i:s', time() - 600)) { dp_sh_sync($pdo); dp_put('sh_sync_at', bd_now()); }
        json_response(dp_sh_overview_data($pdo));
    }
    case 'dp.sh_round': {
        dp_sh_need_mgr($pdo, $auth);
        $o = dp_sh_overview_data($pdo); json_response(['cities' => $o['cities'], 'settings' => $o['settings'], 'live' => $o['live']]);
    }
    case 'dp.sh_today': {
        $mine = dp_sh_my_cities($pdo, $auth);
        $cities = []; foreach (dp_sh_cities($pdo, false) as $c => $n) { if ($n > 0 || in_array($c, $mine, true)) $cities[] = ['city' => $c, 'shops' => $n, 'mine' => in_array($c, $mine, true)]; }
        if (!$mine) json_response(['error' => 'This page is for the display visitor and the retail distribution manager. Ask the Boss to give you the "Display visitor" seat.', 'no_seat' => true], 403);
        $city = (string)($_GET['city'] ?? '');
        if ($city === '' || !in_array($city, $mine, true)) { $inRound = dp_sh_cities($pdo, true); $city = $mine[0]; foreach ($mine as $c) { if ((int)($inRound[$c] ?? 0) > 0) { $city = $c; break; } } }
        dp_sh_sync($pdo);
        $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null; $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
        $d = dp_sh_today_data($pdo, $city, $today, $lat, $lng);
        $d['cities'] = $cities; $d['live'] = dp_live($pdo, 'shops'); $d['manager'] = dp_sh_is_mgr($pdo, $auth); $d['mode'] = bd_mode();
        json_response($d);
    }
    case 'dp.sh_more': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $city = (string)($in['city'] ?? ''); dp_sh_need_city($pdo, $auth, $city);
        if (!dp_live($pdo, 'shops')) json_response(['error' => dp_sh_not_live_msg(), 'not_live' => true], 409);
        dp_sh_plan_make($pdo, $city, $today);
        $inPlan = array_flip(array_map('intval', array_column($pdo->query("SELECT shop_id FROM dp_sh_plan WHERE plan_day = " . $pdo->quote($today))->fetchAll(), 'shop_id')));
        $done = array_flip(dp_sh_visited_today($pdo, $city, $today));
        $rows = array_values(array_filter(dp_sh_rows($pdo, $city, $today), function ($r) use ($inPlan, $done) { return !isset($inPlan[$r['shop_id']]) && !isset($done[$r['shop_id']]); }));
        // next 10 by nearness to the last list shop (or by due date when there are no pins)
        $last = $pdo->prepare("SELECT s.lat, s.lng FROM dp_sh_plan p JOIN retail_shops s ON s.shop_id = p.shop_id WHERE p.plan_day = ? AND p.city = ? AND s.lat IS NOT NULL ORDER BY p.ord DESC LIMIT 1"); $last->execute([$today, $city]); $l = $last->fetch();
        usort($rows, function ($a, $b) use ($l) {
            if ($l && $a['lat'] !== null && $b['lat'] !== null) { $da = dp_sh_km((float)$l['lat'], (float)$l['lng'], $a['lat'], $a['lng']); $db = dp_sh_km((float)$l['lat'], (float)$l['lng'], $b['lat'], $b['lng']); if (abs($da - $db) > 0.001) return $da <=> $db; }
            return [$a['due_on'], $a['shop_id']] <=> [$b['due_on'], $b['shop_id']];
        });
        $ord = (int)$pdo->query("SELECT COALESCE(MAX(ord),0) FROM dp_sh_plan WHERE plan_day = " . $pdo->quote($today) . " AND city = " . $pdo->quote($city))->fetchColumn();
        $n = 0; foreach (array_slice($rows, 0, 10) as $r) { $pdo->prepare("INSERT IGNORE INTO dp_sh_plan (plan_day, city, shop_id, ord, extra) VALUES (?,?,?,?,1)")->execute([$today, $city, (int)$r['shop_id'], ++$ord]); $n++; }
        dp_sh_refresh_day_item($pdo, $city, $today);
        json_response(['ok' => true, 'added' => $n]);
    }
    case 'dp.sh_visit': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $shopId = (int)($_POST['shop_id'] ?? 0); $shop = dp_sh_shop_row($pdo, $shopId); if (!$shop) json_response(['error' => 'Shop not found.'], 404);
        $city = (string)$shop['city']; dp_sh_need_city($pdo, $auth, $city);
        if (!dp_live($pdo, 'shops')) json_response(['error' => dp_sh_not_live_msg(), 'not_live' => true], 409);
        $emp = (int)($w['emp'] ?? 0);
        $st = $pdo->prepare("SELECT id FROM dp_sh_visit WHERE shop_id = ? AND visit_day = ? AND status = 'ok' LIMIT 1"); $st->execute([$shopId, $today]); if ($st->fetchColumn()) json_response(['error' => 'This display was already visited today.'], 409);
        if (!isset($_FILES['photo']) || (int)($_FILES['photo']['error'] ?? 1) !== 0 || (int)($_FILES['photo']['size'] ?? 0) <= 0) json_response(['error' => 'Take a photo of the display to finish the visit.'], 422);
        $hasB = (isset($_POST['b20']) && $_POST['b20'] !== '') || (isset($_POST['b50']) && $_POST['b50'] !== '');
        if (!$hasB) json_response(['error' => 'Ask the shop how many bottles it holds and type the number (0 if none).', 'field' => 'bottles'], 422);
        $b20 = max(0, min(5000, (int)($_POST['b20'] ?? 0))); $b50 = max(0, min(5000, (int)($_POST['b50'] ?? 0)));
        list($img, $err) = dp_save_image($_FILES['photo']['tmp_name'], 'shop' . $shopId); if (!$img) json_response(['error' => $err], 422);
        $abs = dp_dir() . $img; $sha = sha1_file($abs); $ah = function_exists('bd_ahash') ? bd_ahash($abs) : null;
        // the same picture again (also from another shop / re-saved) is not a new visit
        $q = $pdo->prepare("SELECT v.shop_id, v.visit_day, v.ahash, v.sha1, s.shop_name FROM dp_sh_visit v JOIN retail_shops s ON s.shop_id = v.shop_id WHERE v.visit_day >= ? AND v.status <> 'retaken'"); $q->execute([date('Y-m-d', strtotime($today . ' -60 days'))]);
        foreach ($q->fetchAll() as $o) {
            if (($o['sha1'] && $o['sha1'] === $sha) || ($ah && $o['ahash'] && bd_hamming($ah, $o['ahash']) <= 1)) {
                @unlink($abs);
                bd_inbox('dp_note', bd_emp_name($pdo, $emp) . ' sent an old display photo', 'For ' . $shop['shop_name'] . ' - it matches the photo of ' . $o['shop_name'] . ' from ' . $o['visit_day'] . '. Not accepted.', ['emp_id' => $emp], null, null, 'dp_shdup:' . $shopId . ':' . $today);
                json_response(['error' => 'This photo is the same as the one from ' . $o['shop_name'] . ' on ' . $o['visit_day'] . '. Take a new photo at this shop.', 'dup' => true], 422);
            }
        }
        list($state, $count, $flags, $note) = dp_sh_count_photo($abs, dp_sh_full(), dp_sh_min());
        $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null; $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
        $dist = ($lat !== null && $lng !== null && $shop['lat'] !== null) ? (int)round(dp_sh_km($lat, $lng, (float)$shop['lat'], (float)$shop['lng']) * 1000) : null;
        $pdo->prepare("UPDATE dp_sh_visit SET status = 'retaken' WHERE shop_id = ? AND visit_day = ? AND status = 'short'")->execute([$shopId, $today]);
        $nowS = bd_now();
        $ins = function ($status) use ($pdo, $shopId, $emp, $city, $today, $nowS, $img, $sha, $ah, $state, $count, $flags, $note, $b20, $b50, $lat, $lng, $dist) {
            $pdo->prepare("INSERT INTO dp_sh_visit (shop_id, emp_id, city, visit_day, visit_at, photo, sha1, ahash, status, ai_state, ai_count, expected, ai_flags, ai_note, b20, b50, lat, lng, distance_m, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$shopId, $emp ?: null, $city, $today, $nowS, $img, $sha, $ah, $status, $state, $count, dp_sh_full(), implode('|', $flags), $note, $b20, $b50, $lat, $lng, $dist, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255)]);
            return (int)$pdo->lastInsertId();
        };
        if ($state === 'retake') { $vid = $ins('retaken'); json_response(['ok' => false, 'retake' => true, 'visit_id' => $vid, 'count' => $count, 'expected' => dp_sh_full(), 'message' => 'Retake: ' . $note], 200); }
        if ($state === 'short') {
            $vid = $ins('short');
            json_response(['ok' => false, 'retake' => true, 'can_confirm' => true, 'visit_id' => $vid, 'count' => $count, 'expected' => dp_sh_full(), 'flags' => $flags, 'message' => 'Retake: I count ' . $count . ' of ' . dp_sh_full() . ($note !== '' ? ' - ' . rtrim($note, ". ") : '') . '. Fill the display or confirm it is short.'], 200);
        }
        $vid = $ins('ok');
        $res = dp_sh_finish_visit($pdo, $vid, $shop, $emp, $b20, $b50, $lat, $lng, $abs);
        json_response($res);
    }
    case 'dp.sh_visit_confirm_short': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $vid = (int)($in['visit_id'] ?? 0); $st = $pdo->prepare("SELECT * FROM dp_sh_visit WHERE id = ?"); $st->execute([$vid]); $v = $st->fetch();
        if (!$v) json_response(['error' => 'Visit not found.'], 404);
        $shop = dp_sh_shop_row($pdo, (int)$v['shop_id']); dp_sh_need_city($pdo, $auth, (string)$shop['city']);
        if (!$w['manager'] && (int)$v['emp_id'] !== (int)$w['emp'] && !dp_sh_is_mgr($pdo, $auth)) json_response(['error' => 'This is not your visit.'], 403);
        if (!dp_live($pdo, 'shops')) json_response(['error' => dp_sh_not_live_msg(), 'not_live' => true], 409);
        if ($v['status'] !== 'short') json_response(['error' => 'This visit is not waiting for a confirmation.'], 409);
        $why = mb_substr(trim((string)($in['note'] ?? '')), 0, 240);
        $pdo->prepare("UPDATE dp_sh_visit SET status = 'ok', short_confirmed = 1, short_note = ? WHERE id = ?")->execute([$why, $vid]);
        bd_inbox('dp_note', $shop['shop_name'] . ': display is really short (' . (int)$v['ai_count'] . ' of ' . (int)$v['expected'] . ')', bd_emp_name($pdo, $v['emp_id']) . ' confirmed it. ' . ($why !== '' ? 'Reason: ' . $why : 'Refill it on the next round.') . ' (' . $shop['city'] . ')', ['shop_id' => (int)$v['shop_id']], null, null, 'dp_shshort:' . $v['shop_id'] . ':' . $today);
        $res = dp_sh_finish_visit($pdo, $vid, $shop, (int)$v['emp_id'], $v['b20'], $v['b50'], $v['lat'], $v['lng'], dp_dir() . $v['photo']);
        json_response($res);
    }
    case 'dp.sh_pin': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $shopId = (int)($in['shop_id'] ?? 0); $shop = dp_sh_shop_row($pdo, $shopId); if (!$shop) json_response(['error' => 'Shop not found.'], 404);
        dp_sh_need_city($pdo, $auth, (string)$shop['city']);
        $lat = null; $lng = null;
        if (!empty($in['maps_link'])) { if (!dp_sh_is_mgr($pdo, $auth)) json_response(['error' => 'Only the manager can set a pin from a link. Stand at the shop and press "Pin this shop here".'], 403); $g = dp_sh_parse_geo((string)$in['maps_link']); if (!$g) json_response(['error' => 'Could not find a location in that link. Paste the full Google Maps link or "lat, lng".'], 422); $lat = $g['lat']; $lng = $g['lng']; }
        else { $lat = isset($in['lat']) ? (float)$in['lat'] : null; $lng = isset($in['lng']) ? (float)$in['lng'] : null; }
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) json_response(['error' => 'The phone did not give a location. Switch on location and try again.'], 422);
        $acc = isset($in['accuracy_m']) ? (int)$in['accuracy_m'] : null;
        if ($acc !== null && $acc > 150 && empty($in['maps_link'])) json_response(['error' => 'The location is not exact enough (' . $acc . ' m). Go outside / switch on GPS and try again.'], 422);
        $old = $shop['lat'] !== null ? round(dp_sh_km((float)$shop['lat'], (float)$shop['lng'], $lat, $lng) * 1000) : null;
        $pdo->prepare("UPDATE retail_shops SET lat = ?, lng = ?, accuracy_m = ?, pinned_by = ?, pinned_at = ?, updated_at = ? WHERE shop_id = ?")->execute([$lat, $lng, $acc, (int)$w['emp'] ?: null, bd_now(), bd_now(), $shopId]);
        bd_log('pin', 'shop' . $shopId, $shop['shop_name'] . ' pinned' . ($old !== null ? ' (moved ' . $old . ' m)' : ''), $by);
        json_response(['ok' => true, 'moved_m' => $old, 'maps' => dp_sh_maps_link($lat, $lng), 'message' => 'Pinned ' . $shop['shop_name'] . '.']);
    }
    case 'dp.sh_calls': {
        dp_sh_need_mgr($pdo, $auth);
        $d = dp_sh_calls_data($pdo); $d['google'] = dp_get('google_key', '') !== ''; $d['live'] = dp_live($pdo, 'shops'); json_response($d);
    }
    case 'dp.sh_call_note': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_sh_need_mgr($pdo, $auth);
        $state = (string)($in['state'] ?? ''); if (!in_array($state, ['called', 'interested', 'later', 'no', 'none'], true)) json_response(['error' => 'Choose called, interested, later or no.'], 422);
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 250);
        if (!empty($in['prospect_id'])) $pdo->prepare("UPDATE dp_sh_prospect SET call_state = ?, call_at = ?, call_note = ? WHERE id = ?")->execute([$state, bd_now(), $note, (int)$in['prospect_id']]);
        elseif (!empty($in['shop_id'])) { $pdo->prepare("INSERT INTO dp_sh_shop (shop_id, in_round, added_at, source, call_state, call_at, call_note) VALUES (?,0,?, 'manual', ?, ?, ?) ON DUPLICATE KEY UPDATE call_state = VALUES(call_state), call_at = VALUES(call_at), call_note = VALUES(call_note)")->execute([(int)$in['shop_id'], $today, $state, bd_now(), $note]); }
        else json_response(['error' => 'Which shop?'], 422);
        json_response(['ok' => true]);
    }
    case 'dp.sh_places': {
        dp_sh_need_mgr($pdo, $auth);
        $q = trim((string)($_GET['q'] ?? ($in['q'] ?? ''))); if (mb_strlen($q) < 2) json_response(['error' => 'Type what to look for, for example "perfume shop Adajan".'], 422);
        $city = trim((string)($_GET['city'] ?? ($in['city'] ?? '')));
        json_response(['places' => dp_sh_places_search($pdo, $q, $city)]);
    }
    case 'dp.sh_prospect_add': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_sh_need_mgr($pdo, $auth);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 140); if ($name === '') json_response(['error' => 'Write the shop name.'], 422);
        $pid = trim((string)($in['place_id'] ?? '')) ?: null;
        $lat = isset($in['lat']) && $in['lat'] !== '' && $in['lat'] !== null ? (float)$in['lat'] : null; $lng = isset($in['lng']) && $in['lng'] !== '' && $in['lng'] !== null ? (float)$in['lng'] : null;
        $pdo->prepare("INSERT INTO dp_sh_prospect (name, phone, address, area, city, lat, lng, place_id, source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone)")
            ->execute([$name, mb_substr(trim((string)($in['phone'] ?? '')), 0, 40), mb_substr(trim((string)($in['address'] ?? '')), 0, 250), mb_substr(trim((string)($in['area'] ?? '')), 0, 80), mb_substr(trim((string)($in['city'] ?? '')), 0, 60), $lat, $lng, $pid, $pid ? 'places' : 'manual', bd_now()]);
        json_response(['ok' => true]);
    }
    case 'dp.sh_new_display': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_sh_need_mgr($pdo, $auth);
        $shopId = (int)($in['shop_id'] ?? 0); $prospect = !empty($in['prospect_id']) ? (int)$in['prospect_id'] : 0; $nowS = bd_now();
        if ($shopId) {
            $s = dp_sh_shop_row($pdo, $shopId); if (!$s) json_response(['error' => 'Shop not found.'], 404);
        } else {
            $p = null; if ($prospect) { $q = $pdo->prepare("SELECT * FROM dp_sh_prospect WHERE id = ?"); $q->execute([$prospect]); $p = $q->fetch(); if (!$p) json_response(['error' => 'Prospect not found.'], 404); }
            $name = mb_substr(trim((string)($in['name'] ?? ($p['name'] ?? ''))), 0, 120); if ($name === '') json_response(['error' => 'Write the shop name.'], 422);
            $city = mb_substr(trim((string)($in['city'] ?? ($p['city'] ?? ''))), 0, 60); if ($city === '') $city = dp_sh_default_city($pdo);
            if (function_exists('retail_city_ensure')) { try { $city = retail_city_ensure($pdo, $city) ?: $city; } catch (Throwable $e) {} }
            else { try { $pdo->prepare("INSERT IGNORE INTO retail_cities (name, sort_order, created_at) VALUES (?,?,?)")->execute([$city, 99, $nowS]); } catch (Throwable $e) {} }
            $cols = "shop_name, owner_name, mobile, area, address, status, created_by, created_at" . (dp_sh_has_col($pdo, 'retail_shops', 'city') ? ', city' : '') . ($p && $p['lat'] !== null ? ', lat, lng' : '');
            $vals = [$name, mb_substr(trim((string)($in['owner'] ?? '')), 0, 80), mb_substr(trim((string)($in['phone'] ?? ($p['phone'] ?? ''))), 0, 20), mb_substr(trim((string)($in['area'] ?? ($p['area'] ?? ''))), 0, 60), mb_substr(trim((string)($in['address'] ?? ($p['address'] ?? ''))), 0, 250), 'active', (int)$w['emp'] ?: null, $nowS];
            if (dp_sh_has_col($pdo, 'retail_shops', 'city')) $vals[] = $city;
            if ($p && $p['lat'] !== null) { $vals[] = $p['lat']; $vals[] = $p['lng']; }
            $pdo->prepare("INSERT INTO retail_shops ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
            $shopId = (int)$pdo->lastInsertId(); $s = dp_sh_shop_row($pdo, $shopId);
            if ($p) $pdo->prepare("UPDATE dp_sh_prospect SET shop_id = ?, call_state = 'interested' WHERE id = ?")->execute([$shopId, $prospect]);
        }
        $first = date('Y-m-d', strtotime($today . ' +1 day'));
        $pdo->prepare("INSERT INTO dp_sh_shop (shop_id, in_round, added_at, seed_due, source, call_state, call_at) VALUES (?,1,?,?, 'new', 'interested', ?) ON DUPLICATE KEY UPDATE in_round = 1, source = 'new', added_at = VALUES(added_at), seed_due = VALUES(seed_due), call_state = 'interested', call_at = VALUES(call_at)")->execute([$shopId, $today, $first, $nowS]);
        bd_log('display', 'shop' . $shopId, 'new display for ' . $s['shop_name'] . ' (' . $s['city'] . ')', $by);
        $vs = dp_sh_seat_for_city($pdo, (string)$s['city']); $vh = dp_owner_for($pdo, $vs, $today); if ($vh && bd_mode() !== 'shadow') bd_push($pdo, $vh, 'New display to visit', $s['shop_name'] . ' joined your round. First visit by ' . $first . '.');
        json_response(['ok' => true, 'shop_id' => $shopId, 'message' => $s['shop_name'] . ' is in the visit round. Its first visit is due on ' . $first . '. Give the goods through a normal Retail "display" order.']);
    }
    case 'dp.sh_round_set': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        dp_sh_need_mgr($pdo, $auth);
        $sid = (int)($in['shop_id'] ?? 0); if (!dp_sh_shop_row($pdo, $sid)) json_response(['error' => 'Shop not found.'], 404);
        $on = !empty($in['in_round']) ? 1 : 0;
        $pdo->prepare("INSERT INTO dp_sh_shop (shop_id, in_round, added_at, seed_due, source) VALUES (?,?,?,?, 'manual') ON DUPLICATE KEY UPDATE in_round = VALUES(in_round)")->execute([$sid, $on, $today, date('Y-m-d', strtotime($today . ' +1 day'))]);
        json_response(['ok' => true]);
    }
    case 'dp.sh_pins': {
        dp_sh_need_mgr($pdo, $auth);
        $city = (string)($_GET['city'] ?? ''); $o = [];
        foreach (dp_sh_rows($pdo, $city, $today) as $r) $o[] = ['shop_id' => (int)$r['shop_id'], 'name' => $r['shop_name'], 'area' => $r['area'], 'city' => $r['city'], 'phone' => $r['mobile'], 'lat' => $r['lat'], 'lng' => $r['lng'], 'maps' => dp_sh_maps_link($r['lat'], $r['lng']), 'last' => $r['last'], 'due_on' => $r['due_on'], 'overdue_days' => (int)$r['overdue_days']];
        usort($o, function ($a, $b) { return [$a['lat'] === null ? 0 : 1, (string)$a['area'], $a['name']] <=> [$b['lat'] === null ? 0 : 1, (string)$b['area'], $b['name']]; });
        json_response(['shops' => $o, 'no_pin' => count(array_filter($o, function ($x) { return $x['lat'] === null; }))]);
    }
    case 'dp.sh_settings': {
        dp_sh_need_mgr($pdo, $auth);
        if ($isPost) {
            bd_need_admin($auth);
            $map = ['full' => ['sh_full', 1, 200], 'min' => ['sh_min', 1, 200], 'round_days' => ['sh_round_days', 2, 60], 'per_day' => ['sh_per_day', 1, 200]];
            $new = [];
            foreach ($map as $k => $m) { if (isset($in[$k])) { $v = (int)$in[$k]; if ($v < $m[1] || $v > $m[2]) json_response(['error' => $k . ' must be ' . $m[1] . ' to ' . $m[2] . '.'], 422); $new[$m[0]] = $v; } }
            if ((isset($new['sh_min']) ? $new['sh_min'] : dp_sh_min()) > (isset($new['sh_full']) ? $new['sh_full'] : dp_sh_full())) json_response(['error' => 'The "short" limit cannot be more than a full display.'], 422);
            foreach ($new as $k => $v) dp_put($k, (string)$v);
            if (array_key_exists('sunday', $in)) dp_put('sh_sunday', !empty($in['sunday']) ? '1' : '0');
        }
        $o = dp_sh_overview_data($pdo); json_response(['settings' => $o['settings']]);
    }
    case 'dp.sh_file': {
        $vid = (int)($_GET['visit_id'] ?? 0); $st = $pdo->prepare("SELECT * FROM dp_sh_visit WHERE id = ?"); $st->execute([$vid]); $v = $st->fetch();
        if (!$v || empty($v['photo'])) json_response(['error' => 'Photo not found.'], 404);
        if (!dp_sh_is_mgr($pdo, $auth) && (int)$v['emp_id'] !== (int)$w['emp']) json_response(['error' => 'Not allowed.'], 403);
        dp_file_out($v['photo']);
    }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Retail displays: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}

/** a visit that passed (or was confirmed short): the money rule, the old Retail visit record, the list item, the alerts. */
function dp_sh_finish_visit(PDO $pdo, $vid, array $shop, $emp, $b20, $b50, $lat, $lng, $abs) {
    $today = bd_today(); $shopId = (int)$shop['shop_id'];
    list($stock, $due, $collect, $flag, $text, $pnote) = dp_sh_money_rule($pdo, $shopId, $b20, $b50);
    $pdo->prepare("UPDATE dp_sh_visit SET stock_value = ?, due = ?, collect = ?, due_flag = ?, price_note = ? WHERE id = ?")->execute([$stock, $due, $collect, $flag, mb_substr($pnote, 0, 160), $vid]);
    $v = $pdo->prepare("SELECT * FROM dp_sh_visit WHERE id = ?"); $v->execute([$vid]); $v = $v->fetch();
    // the old Retail visit list (last visit, photo) keeps working
    try {
        $path = dp_sh_save_photo_copy($abs, $shopId);
        if ($path !== '' && dp_sh_has_table($pdo, 'retail_visits')) {
            $pdo->prepare("INSERT INTO retail_visits (shop_id, employee_id, visit_at, photo, note, lat, lng, distance_m) VALUES (?,?,?,?,?,?,?,?)")->execute([$shopId, $emp ?: null, $v['visit_at'], $path, mb_substr('Display visit (Claude counted ' . ($v['ai_count'] !== null ? $v['ai_count'] : '?') . ')', 0, 255), $lat, $lng, $v['distance_m']]);
            $pdo->prepare("UPDATE dp_sh_visit SET retail_visit_id = ? WHERE id = ?")->execute([(int)$pdo->lastInsertId(), $vid]);
        }
    } catch (Throwable $e) {}
    if ($shop['lat'] === null && $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
        try { $pdo->prepare("UPDATE retail_shops SET lat = ?, lng = ?, pinned_by = ?, pinned_at = ?, updated_at = ? WHERE shop_id = ?")->execute([$lat, $lng, $emp ?: null, bd_now(), bd_now(), $shopId]); } catch (Throwable $e) {}
    }
    $flags = $v['ai_flags'] ? array_values(array_filter(explode('|', $v['ai_flags']))) : [];
    if ($flags) bd_inbox('dp_note', $shop['shop_name'] . ': ' . implode(', ', $flags), 'Claude saw this on the display photo of ' . $shop['shop_name'] . ' (' . $shop['city'] . ', ' . $shop['area'] . '). Visit by ' . bd_emp_name($pdo, $emp) . '.', ['shop_id' => $shopId], null, null, 'dp_shflag:' . $shopId . ':' . $today);
    if ($flag) bd_inbox('dp_note', $shop['shop_name'] . ': sold more, paid less', $text . ' Visit by ' . bd_emp_name($pdo, $emp) . ' today.', ['shop_id' => $shopId], null, null, 'dp_shdue:' . $shopId . ':' . $today);
    if ($v['distance_m'] !== null && (int)$v['distance_m'] > 500) bd_inbox('dp_note', $shop['shop_name'] . ': photo taken ' . (int)$v['distance_m'] . ' m from the shop pin', 'Check if the visitor was really at the shop.', ['shop_id' => $shopId], null, null, 'dp_shfar:' . $shopId . ':' . $today);
    dp_sh_refresh_day_item($pdo, (string)$shop['city'], $today);
    return ['ok' => true, 'visit_id' => (int)$vid, 'message' => $shop['shop_name'] . ': visit saved.', 'count' => $v['ai_count'] !== null ? (int)$v['ai_count'] : null, 'expected' => (int)$v['expected'], 'flags' => $flags,
        'ai_skipped' => $v['ai_state'] === 'skip', 'money' => ['stock' => $stock, 'due' => $due, 'collect' => $collect, 'flag' => (int)$flag, 'text' => $text, 'price_note' => $pnote]];
}
