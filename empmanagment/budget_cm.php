<?php
/**
 * budget_cm.php — TOTAL BUDGETING's commission engine. Moved here, unchanged, from the Boss Engine file (boss2.php): the calculation is the same, line for line.
 * It needs nothing from the Boss Engine any more: api.php, budget.php and the Commission pages load THIS file.
 * Keep it in the SAME folder as api.php. PHP 7.4 safe.
 *
 *   Part 1  the calculation (cm_chart, cm_pct, cm_offers, cm_day, cm_paid_day, cm_recount … 34 functions)
 *   Part 2  the actions of the Commission screens:  budget.cm_view · cm_recount · cm_fix · cm_chart_save · cm_offer_add · cm_offer_stop · cm_offer_addons ·
 *           cm_addons_set · cm_bday_save · cm_boost_add · cm_boost_stop       (Admin, HR, or a role with the Commission / Commission rules tick)
 *           The old names (bd.b2_cm_*) still answer through the same code, so a page kept open in a browser does not break.
 */
if (!function_exists('cm_day')) {

function cm_now() { return date('Y-m-d H:i:s'); }
/** the change is written in the same history as before (bd_log), when that table is there */
function cm_log($kind, $ref, $detail, $actor = 'Total Budgeting') {
    global $pdo;
    try { $pdo->prepare("INSERT INTO bd_log (at, actor, kind, ref, detail) VALUES (?,?,?,?,?)")->execute([cm_now(), mb_substr((string)$actor, 0, 80), $kind, mb_substr((string)$ref, 0, 40), mb_substr((string)$detail, 0, 400)]); } catch (Throwable $e) {}
}

/* ============================== Commission 2: pricing ML by ML, offers, results, 10-day recount ==============================
 * A separate, PREVIEW-ONLY engine. It reads the orders through the HRMS functions (coach_sales_load, sales_target_fn) and works out
 * what each salesperson would earn with the new rules. It never pays, charges or changes anything the old engine shows.
 *   - Price → % : the order's price (before the prepaid 2% discount: that discount is the company's cost) goes to the NEAREST price column
 *     of its ML row. At or above the midpoint of two column prices = the higher %. Below the last column = the floor %.
 *   - An OFFER (ML + price + %, set by hand, with dates) wins over the chart: an order of that ML within +-window rupees of the offer
 *     price pays the offer's flat %.  No offer matches = the chart.
 *   - Code rule: when the day's code share reaches codepct, every order pays full; otherwise an order without a code pays nocode% of its commission.
 *   - Over target: +30% sales -> +30% extra on the chart commission, +40% -> +40%, +60% -> +50%; never above bonus_max. Offer orders are flat (no extra).
 *   - The commission base of a prepaid order is its price before the prepaid discount.
 */
/* Seeding from the old chart: old columns 7/6.5/6/4/3.5/3 -> new 7/6.5/6/5/4/3. The 5% price is NOT invented (left blank); the old 3.5% column is dropped. */
function cm_default_chart(PDO $pdo) {
    $rows = [];
    if (function_exists('ml_slab_get')) { try { $o = ml_slab_get($pdo); foreach ((array)($o['rows'] ?? []) as $r) { $p = array_pad(array_map('floatval', (array)$r['p']), 6, 0); $rows[] = ['ml' => (int)$r['ml'], 'p' => [$p[0], $p[1], $p[2], 0, $p[3], $p[5]]]; } } catch (Throwable $e) {} }
    return ['v' => 1, 'cols' => [7, 6.5, 6, 5, 4, 3], 'rows' => $rows, 'floor' => 2.8, 'codepct' => 70, 'nocode' => 50, 'prepaid' => 2, 'window' => 110,
        'tiers' => [[30, 30], [40, 40], [60, 50]], 'bonus_max' => 50, 'reviewed' => 0, 'seeded' => count($rows) ? 1 : 0, 'updated_at' => '', 'updated_by' => ''];
}
function cm_chart(PDO $pdo) {
    $d = cm_default_chart($pdo);
    $s = function_exists('setting_get') ? json_decode((string)setting_get($pdo, 'cm_chart', ''), true) : null;
    $c = (!is_array($s) || empty($s['rows'])) ? $d : array_merge($d, $s);      // the commission RULES (code limit, no-code share, prepaid, over-target steps, floor) live here
    $c['window'] = 110;      // an offer applies to orders within +-110 rupees of its price (a chart saved earlier still carried window = 20 and overrode the default)
    // the PRICES live only in the Price Chart (Learn & Setup → Pricing): once it is unified, the engine reads its columns and rows from there, there is no second price table
    if (function_exists('pc_perfume') && function_exists('setting_get') && (string)setting_get($pdo, 'price_unify_v1', '') === '1') { $pc = pc_perfume($pdo); if (!empty($pc['rows'])) { $c['cols'] = array_map('floatval', $pc['cols']); $c['rows'] = $pc['rows']; } }
    return $c;
}
/** the chart sent from the Pricing tab -> [clean chart | null, error] */
function cm_chart_clean(array $in, array $cur) {
    $rows = []; $seen = [];
    foreach ((array)($in['rows'] ?? []) as $r) {
        $ml = (int)($r['ml'] ?? 0); if ($ml <= 0 && !array_sum(array_map('floatval', (array)($r['p'] ?? [])))) continue;   // an empty new row is ignored
        if ($ml <= 0 || $ml > 5000) return [null, 'Every row needs an ML between 1 and 5000.'];
        if (isset($seen[$ml])) return [null, $ml . ' ml is in the chart twice.']; $seen[$ml] = 1;
        $p = array_map(function ($v) { return round((float)$v, 2); }, array_slice(array_values((array)($r['p'] ?? [])), 0, 6)); $p = array_pad($p, 6, 0);
        $rows[] = ['ml' => $ml, 'p' => $p];
    }
    if (!$rows) return [null, 'The chart has no ML rows.'];
    usort($rows, function ($a, $b) { return $a['ml'] <=> $b['ml']; });
    $n = function ($k, $lo, $hi, $def) use ($in, $cur) { $v = $in[$k] ?? ($cur[$k] ?? $def); return max($lo, min($hi, round((float)$v, 2))); };
    $tiers = [];
    foreach ((array)($in['tiers'] ?? $cur['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; }
    usort($tiers, function ($a, $b) { return $a[0] <=> $b[0]; });
    $c = $cur; $c['rows'] = $rows; $c['floor'] = $n('floor', 0, 10, 2.8); $c['codepct'] = $n('codepct', 0, 100, 70); $c['nocode'] = $n('nocode', 0, 100, 50); $c['prepaid'] = $n('prepaid', 0, 20, 2);
    $c['bonus_max'] = $n('bonus_max', 1, 100, 50); $c['tiers'] = $tiers ?: [[30, 30], [40, 40], [60, 50]]; $c['window'] = 110; $c['cols'] = [7, 6.5, 6, 5, 4, 3];
    return [$c, ''];
}
/** the six prices of one ML (a size between two rows is worked out between them) or null when the size is outside the chart */
function cm_row_prices(array $ch, $ml) {
    $rows = $ch['rows'] ?? []; $ml = (float)$ml; if (!$rows || $ml <= 0 || $ml < $rows[0]['ml'] || $ml > $rows[count($rows) - 1]['ml']) return null;
    foreach ($rows as $i => $a) {
        if ((float)$a['ml'] === $ml) return array_map('floatval', $a['p']);
        $b = $rows[$i + 1] ?? null;
        if ($b && $ml > $a['ml'] && $ml < $b['ml']) { $f = ($ml - $a['ml']) / ($b['ml'] - $a['ml']); $p = []; foreach ($a['p'] as $j => $v) $p[] = ((float)$v > 0 && (float)$b['p'][$j] > 0) ? (float)$v + ((float)$b['p'][$j] - (float)$v) * $f : 0.0; return $p; }   // never invent a price next to a blank one
    }
    return null;
}
/** the chart % for one order: the price goes to the NEAREST column. $gross = the price before the prepaid discount. null = ML not in the chart. */
function cm_pct(array $ch, $ml, $gross) {
    $p = cm_row_prices($ch, $ml); if ($p === null) return null;
    $cols = array_map('floatval', $ch['cols']); $gross = (float)$gross;
    $pairs = []; foreach ($p as $j => $v) if ($v > 0) $pairs[] = [(float)$v, $cols[$j]];       // blank columns are skipped
    if (!$pairs) return null;                                                                   // a row with no prices is not priced yet
    if ($gross >= $pairs[0][0]) return $pairs[0][1];
    for ($k = 0; $k < count($pairs) - 1; $k++)
        if ($gross >= $pairs[$k + 1][0]) return $gross >= ($pairs[$k][0] + $pairs[$k + 1][0]) / 2 ? $pairs[$k][1] : $pairs[$k + 1][1];
    return (float)($ch['floor'] ?? 0);
}
/** the offer an order falls in: same ML, price within +-window of the offer price, on a day the offer runs (a stopped offer: up to the day it was stopped). null = none. */
function cm_offer_match(array $offers, $ml, $gross, $day, $window = 20) {
    $ml = (int)round((float)$ml); if ($ml <= 0) return null;
    foreach ($offers as $o) {
        $ads = cm_offer_addons_all($o);   // ADD-ONS (owner, 20 Nov 2026): offer ml + add-on ml at offer price + add-on amount (3299 + 750 = 4049 for 480 ml) earns the same flat %; the offer's own list, then the global list for every order
        if ((int)$o['ml'] !== $ml) { $hit = false; foreach ($ads as $a) if ((int)$o['ml'] + (int)$a['ml'] === $ml) { $hit = true; break; } if (!$hit) continue; }
        if (empty($o['active'])) { $sa = substr((string)($o['stopped_at'] ?? ''), 0, 10); if ($sa === '' || $day > $sa) continue; }      // a STOPPED offer still counts for the days it was running (up to the day it was stopped)
        if ($o['from_date'] && $day < $o['from_date']) continue; if ($o['to_date'] && $day > $o['to_date']) continue;
        if ((int)$o['ml'] === $ml && abs((float)$gross - (float)$o['price']) <= $window + 1e-9) return $o;
        foreach ($ads as $a) if ((int)$o['ml'] + (int)$a['ml'] === $ml && abs((float)$gross - ((float)$o['price'] + (float)$a['amount'])) <= $window + 1e-9) { $o['addon'] = (float)$a['amount']; $o['addon_ml'] = (int)$a['ml']; return $o; }
    }
    return null;
}
/** The add-ons that apply to every order (Commission → Offers: "Add-ons for every order"), setting cm_addons. Loaded once per request; cm_global_addons($pdo, true) after a save. */
function cm_global_addons($pdo = null, bool $reload = false): array {
    static $m = null; if ($reload) $m = null; if ($m !== null) return $m;
    if (!$pdo instanceof PDO) { $pdo = $GLOBALS['pdo'] ?? null; } if (!$pdo instanceof PDO) return [];
    try { $m = cm_offer_addon_list((string)setting_get($pdo, 'cm_addons', '')); } catch (Throwable $e) { $m = []; } return $m;
}
/** One offer's add-ons: its own list first, then the global list (a global ml the offer already has is skipped). */
function cm_offer_addons_all(array $o): array {
    $own = cm_offer_addon_list($o['addons'] ?? null); $have = []; foreach ($own as $a) $have[(int)$a['ml']] = 1;
    foreach (cm_global_addons() as $a) if (!isset($have[(int)$a['ml']])) $own[] = $a;
    return $own;
}
/** CHART path of an add-on order: an order that looks UNDERPRICED as it is (below the lowest price of its ML row by more than the window, or an ML not in the chart) but is a normal chart price once a global add-on's ml and amount are taken out
 *  is paid the chart % of the base size, on the WHOLE amount. Returns [pct, base_ml, add-on] or null. */
function cm_addon_chart(array $ch, $ml, $gross, $window) {
    $gAdd = cm_global_addons(); if (!$gAdd) return null; $ml = (int)round((float)$ml); $gross = (float)$gross; if ($ml <= 0) return null;
    $low = function ($m) use ($ch) { $p = cm_row_prices($ch, $m); if ($p === null) return null; $p = array_filter($p, function ($v) { return $v > 0; }); return $p ? min($p) : null; };
    $lo = $low($ml); if ($lo !== null && $gross >= $lo - $window - 1e-9) return null;   // priced like a chart size already: no add-on story
    foreach ($gAdd as $a) {
        $bm = $ml - (int)$a['ml']; $bg = $gross - (float)$a['amount']; if ($bm <= 0 || $bg <= 0) continue;
        $lb = $low($bm); if ($lb === null || $bg < $lb - $window - 1e-9) continue;
        $pc = cm_pct($ch, $bm, $bg); if ($pc === null) continue;
        return [$pc, $bm, $a];
    }
    return null;
}
/** Add-ons of an offer: [{ml, amount}] from the stored JSON / an array / the typed text "80:750, 60:450". Clean: ml 1-5000, amount above 0, no repeated ml, at most 12. */
function cm_offer_addon_list($v): array {
    if (is_string($v)) { $t = trim($v); if ($t === '') return []; $j = json_decode($t, true); if (is_array($j)) $v = $j; else { $v = []; foreach (preg_split('/[,;\n]+/', $t) as $part) { if (preg_match('/^\s*(\d{1,4})\s*(?:ml)?\s*[:=@xX-]\s*(?:rs\.?|₹)?\s*(\d+(?:\.\d+)?)\s*$/iu', $part, $m)) $v[] = ['ml' => (int)$m[1], 'amount' => (float)$m[2]]; } } }
    if (!is_array($v)) return []; $out = []; $seen = [];
    foreach ($v as $a) { if (!is_array($a)) continue; $ml = (int)($a['ml'] ?? 0); $am = round((float)($a['amount'] ?? 0), 2); if ($ml <= 0 || $ml > 5000 || $am <= 0 || isset($seen[$ml])) continue; $seen[$ml] = 1; $out[] = ['ml' => $ml, 'amount' => $am]; if (count($out) >= 12) break; }
    return $out;
}
function cm_bonus(array $ch, $sales, $target, array $boost = null) {
    $sales = (float)$sales; $target = (float)$target; if (!($target > 0) || $sales + 1e-9 < $target) return 0.0;
    $over = ($sales / $target - 1) * 100; $b = 0.0;
    $tiers = $boost ? $boost['tiers'] : (array)($ch['tiers'] ?? []);
    foreach ($tiers as $t) if ($over + 1e-6 >= (float)$t[0]) $b = max($b, (float)$t[1]);
    return $boost ? min($b, 200.0) : min($b, (float)($ch['bonus_max'] ?? 50));      // a boost has no 50% cap: its last slab is the cap
}
/** BOOST = a temporary over-target rule: its own steps (e.g. +10% over -> 30% extra ... last slab 70%), for everyone or chosen people,
 *  from a date until 10:00 the next morning after its end date. While it runs it replaces the normal over-target steps for those people. */
function cm_boosts(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_boosts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, tiers TEXT NOT NULL, scope VARCHAR(10) NOT NULL DEFAULT 'all', emp_ids TEXT NULL,
        from_date DATE NOT NULL, to_date DATE NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, stopped_at DATETIME NULL)");
    $rows = $pdo->query("SELECT * FROM cm_boosts ORDER BY id DESC LIMIT 100")->fetchAll();
    foreach ($rows as &$r) { $r['tiers'] = json_decode((string)$r['tiers'], true) ?: []; $r['emp_ids'] = array_map('intval', json_decode((string)$r['emp_ids'], true) ?: []); $r['ends_at'] = date('Y-m-d', strtotime($r['to_date'] . ' +1 day')) . ' 10:00'; } unset($r);
    return $rows;
}
function cm_boost_for(array $boosts, $empId, $day) {
    foreach ($boosts as $b) {
        if (empty($b['active']) || $day < $b['from_date'] || $day > $b['to_date']) continue;
        if ($b['scope'] === 'people' && !in_array((int)$empId, $b['emp_ids'], true)) continue;
        return $b;
    }
    return null;
}
function cm_boost_save(PDO $pdo, array $in, $by) {
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80); $from = (string)($in['from_date'] ?? ''); $to = (string)($in['to_date'] ?? '');
    $scope = ($in['scope'] ?? 'all') === 'people' ? 'people' : 'all'; $ids = array_values(array_unique(array_map('intval', (array)($in['emp_ids'] ?? []))));
    $tiers = [];
    foreach ((array)($in['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; }
    usort($tiers, function ($a, $b) { return $a[0] <=> $b[0]; });
    if ($name === '') $name = 'Boost';
    if (!$tiers) return [false, 'Add at least one step: sales over target by % → extra commission %.', 422];
    foreach ($tiers as $t) if ($t[1] > 200) return [false, 'Extra commission cannot be above 200%.', 422];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) return [false, 'Enter the start date and an end date that is not before it.', 422];
    if ($scope === 'people' && !$ids) return [false, 'Choose at least one person, or pick “Everyone”.', 422];
    cm_boosts($pdo);
    $pdo->prepare("INSERT INTO cm_boosts (name, tiers, scope, emp_ids, from_date, to_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$name, json_encode($tiers), $scope, json_encode($ids), $from, $to, $by, cm_now()]);
    return [true, 'Boost “' . $name . '” added. It runs ' . $from . ' to ' . $to . ' and closes at 10:00 on ' . date('j M', strtotime($to . ' +1 day')) . '.', 200];
}
function cm_boost_stop(PDO $pdo, $id) { cm_boosts($pdo); $pdo->prepare("UPDATE cm_boosts SET active = 0, stopped_at = ? WHERE id = ?")->execute([cm_now(), (int)$id]); return [true, 'Boost stopped. The normal over-target steps apply again.', 200]; }
/** TARGET BONUS rules (Commission rules → Target bonus): the switched-on ones, oldest first. For the chosen people and dates, a day whose sales reach that day's target
 *  (or target + X% more) pays the step's extra % on the chart commission. It is compared with the normal over-target steps / a boost: the HIGHEST one counts, never both. */
function cm_tb_rules(PDO $pdo) {
    static $r = null; if ($r !== null) return $r; $r = [];
    try {
        if (function_exists('ensure_commission_bonus_table') && !ensure_commission_bonus_table($pdo)) return $r;
        foreach ($pdo->query("SELECT rule_id, label, who, tiers, date_from, date_to, all_sales FROM commission_bonus_rules WHERE enabled = 1 ORDER BY rule_id ASC")->fetchAll() as $x) {
            $t = []; foreach ((array)(json_decode((string)$x['tiers'], true) ?: []) as $s) if (is_array($s) && !empty($s['on'])) $t[] = [(float)($s['over'] ?? 0), (float)($s['bonus'] ?? 0)];
            if (!$t) continue; usort($t, function ($a, $b) { return $a[0] <=> $b[0]; });
            $d = function ($v) { $v = substr((string)$v, 0, 10); return preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/', $v) ? $v : ''; };
            $r[] = ['id' => (int)$x['rule_id'], 'label' => trim((string)$x['label']) !== '' ? trim((string)$x['label']) : 'Target bonus', 'all' => (int)($x['all_sales'] ?? 0) === 1,
                'who' => array_values(array_filter(array_map('intval', explode(',', (string)$x['who'])))), 'from' => $d($x['date_from']), 'to' => $d($x['date_to']), 'tiers' => $t];
        }
    } catch (Throwable $e) { $r = []; }
    return $r;
}
function cm_tb_for(array $rules, $empId, $day) {
    foreach ($rules as $r) {
        if (($r['from'] !== '' && $day < $r['from']) || ($r['to'] !== '' && $day > $r['to'])) continue;
        if (!$r['all'] && !in_array((int)$empId, $r['who'], true)) continue;
        return $r;
    }
    return null;
}
/** the rule's extra % for one day: the highest step reached; 0 below the target or with no target */
function cm_tb_bonus(array $rule, $sales, $target) {
    $sales = (float)$sales; $target = (float)$target; if (!($target > 0) || $sales + 1e-9 < $target) return 0.0;
    $over = ($sales / $target - 1) * 100; $b = 0.0;
    foreach ($rule['tiers'] as $t) if ($over + 1e-6 >= $t[0]) $b = max($b, $t[1]);
    return $b;
}
/** the extra % that counts for one person / day: [%, name of the Target bonus rule when it is the one that won ('' = normal steps or boost), boost row or null] */
function cm_bonus_pick(PDO $pdo, array $ch, array $boosts, $empId, $day, $sales, $target) {
    // Owner's rule (14 Nov): an over-target bonus is paid ONLY on the dates of a rule that has dates (a Target bonus rule or a Boost). The built-in "over-target extra" steps had no dates,
    // so they paid on every day (e.g. +50% on 1 Nov while the Target bonus rule ran 8–15 Nov). They are off unless the setting cm_builtin_extra is '1'.
    $bo = cm_boost_for($boosts, $empId, $day); $name = '';
    $bonus = ($bo || (string)setting_get($pdo, 'cm_builtin_extra', '0') === '1') ? cm_bonus($ch, $sales, $target, $bo) : 0.0;
    $tb = cm_tb_for(cm_tb_rules($pdo), $empId, $day);
    if ($tb) { $v = cm_tb_bonus($tb, $sales, $target); if ($v > $bonus + 1e-9) { $bonus = $v; $name = $tb['label']; } }
    return [$bonus, $name, $bo];
}
function cm_code_need($n, $pct) { return ($n <= 0 || $pct <= 0) ? 0 : (int)max(1, round($n * $pct / 100)); }

function cm_offers(PDO $pdo, $all = true) {
    static $made = false;   // the CREATE TABLE is DDL: in MySQL it silently COMMITS an open transaction, so it runs once per request (and before a bill's transaction starts, see st_engine_warm)
    if (!$made) { $made = true; $pdo->exec("CREATE TABLE IF NOT EXISTS cm_offers (id INT AUTO_INCREMENT PRIMARY KEY, ml INT NOT NULL, price DECIMAL(10,2) NOT NULL, pct DECIMAL(5,2) NOT NULL, from_date DATE NOT NULL, to_date DATE NULL,
        active TINYINT NOT NULL DEFAULT 1, note VARCHAR(120) NULL, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, stopped_at DATETIME NULL, INDEX (ml, active))");
        try { $pdo->query("SELECT addons FROM cm_offers LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE cm_offers ADD COLUMN addons TEXT NULL"); } catch (Throwable $e2) { } } }
    $rows = $pdo->query("SELECT * FROM cm_offers ORDER BY id DESC LIMIT 300")->fetchAll();
    foreach ($rows as &$__r) $__r['addons'] = cm_offer_addon_list($__r['addons'] ?? null); unset($__r);
    cm_global_addons($pdo);   // primes the global add-ons for cm_offer_match / cm_addon_chart
    return $rows;
}
function cm_offer_save(PDO $pdo, array $in, $by) {
    $ml = (int)($in['ml'] ?? 0); $price = round((float)($in['price'] ?? 0), 2); $pct = round((float)($in['pct'] ?? 0), 2);
    $from = (string)($in['from_date'] ?? ''); $to = (string)($in['to_date'] ?? '');
    if ($ml <= 0 || $ml > 5000) return [false, 'Enter the ML size.', 422];
    if ($price <= 0) return [false, 'Enter the offer price.', 422];
    if ($pct <= 0 || $pct > 15) return [false, 'Enter the commission % (0.1 to 15).', 422];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return [false, 'Enter the start date.', 422];
    if ($to !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from)) return [false, 'The end date cannot be before the start date.', 422];
    cm_offers($pdo);
    $ads = cm_offer_addon_list($in['addons'] ?? null);
    $pdo->prepare("INSERT INTO cm_offers (ml, price, pct, from_date, to_date, note, created_by, created_at, addons) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$ml, $price, $pct, $from, $to !== '' ? $to : null, mb_substr(trim((string)($in['note'] ?? '')), 0, 120) ?: null, $by, cm_now(), $ads ? json_encode($ads) : null]);
    return [true, 'Offer added: ' . $ml . ' ml at ₹' . number_format($price) . ' pays ' . $pct . '% (orders within ±₹20)' . ($ads ? ' · ' . count($ads) . ' add-on(s)' : '') . '.', 200];
}
/** Replaces the add-ons of one offer (ML + amount each): an order with that offer + one add-on earns the offer's % on the whole amount. */
function cm_offer_addons_set(PDO $pdo, $id, $v) {
    cm_offers($pdo); $ads = cm_offer_addon_list($v);
    $pdo->prepare("UPDATE cm_offers SET addons = ? WHERE id = ?")->execute([$ads ? json_encode($ads) : null, (int)$id]);
    return [true, $ads ? 'Add-ons saved (' . count($ads) . ').' : 'Add-ons cleared.', 200];
}
function cm_offer_stop(PDO $pdo, $id, $by) {
    cm_offers($pdo); $pdo->prepare("UPDATE cm_offers SET active = 0, stopped_at = ? WHERE id = ?")->execute([cm_now(), (int)$id]);
    return [true, 'Offer stopped.', 200];
}
/** people assigned to Retail wholesale or Store in Total Budgeting: they are paid by that rule (salary + collections % / store chart), so the online commission engine skips them */
function cm_offline_ids(PDO $pdo) {
    if (function_exists('pay_offline_ids')) return pay_offline_ids($pdo);
    $o = [];
    try { $j = json_decode((string)setting_get($pdo, 'budget_cfg', ''), true); foreach ((array)($j['people'] ?? []) as $id => $v) if (is_array($v) && in_array((string)($v['grp'] ?? ''), ['retail', 'store'], true)) $o[(int)$id] = 1; } catch (Throwable $e) {}
    return $o;
}
/** 🎂 Birthday rule (owner, 21 Nov): on a Sales person's birthday every order of that business day pays ONE flat commission % (instead of the chart / offers). setting cm_bday = {on, pct}. */
function cm_bday_cfg(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'cm_bday', ''), true); $j = is_array($j) ? $j : [];
    return ['on' => !empty($j['on']) ? 1 : 0, 'pct' => max(0.0, min(20.0, round((float)($j['pct'] ?? 0), 2)))];
}
function cm_is_bday($dob, $day): bool { $dob = substr((string)$dob, 0, 10); return preg_match('/^\d{4}-(\d{2}-\d{2})$/', $dob, $m) && substr((string)$day, 5, 5) === $m[1]; }
function cm_emps(PDO $pdo, $day = null) {
    $emps = []; $off = cm_offline_ids($pdo); $dayOff = ($day && function_exists('pay_day_assign')) ? (pay_day_assign($pdo)[$day] ?? []) : [];   // on a day moved to the store, no online commission
    foreach ($pdo->query("SELECT e.employee_id, e.full_name, e.sales_tag, e.basic_salary, e.joining_date, e.designation, e.date_of_birth, d.department_name
        FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex'")->fetchAll() as $e) {
        if (stripos((string)$e['department_name'], 'sales') === false || ($day && function_exists('pay_off_at') ? pay_off_at($pdo, (int)$e['employee_id'], (string)$day) : isset($off[(int)$e['employee_id']])) || isset($dayOff[(int)$e['employee_id']])) continue; $emps[(int)$e['employee_id']] = $e;
    }
    return $emps;
}
/** WHY a person's target is ₹0 on a day (shown next to "Target ₹0" on the Commission page): with no target there is no target bonus and no over-target extra, so the reason must be visible. */
function cm_target_why(PDO $pdo, $id, $day, array $marks, array $emp) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM daily_targets WHERE target_date = ? AND employee_id = ?"); $q->execute([$day, (int)$id]);
        if ((int)$q->fetchColumn() > 0) return 'a target of ₹0 was typed by hand for this day';
    } catch (Throwable $e) {}
    if (($marks[$day . '|' . $id] ?? '') === 'leave') return 'the day is marked Leave and no target was typed for it';
    try { if (function_exists('ct_day_targets') && isset(ct_day_targets($pdo, (string)$day)[(int)$id])) return 'the Orders page has no target for this day (not punched in, on leave, or switched to not working)'; } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM targets WHERE month = ? AND employee_id = ? AND manual_target IS NOT NULL"); $q->execute([substr((string)$day, 0, 7), (int)$id]);
        if ((int)$q->fetchColumn() > 0) return 'the month target typed by hand is ₹0';
    } catch (Throwable $e) {}
    return 'no target is set for this person (no day target, no month target, and no salary / ladder target)';
}
/** ✏️ Commission typed by hand for ONE order (Commission page → a day → Edit). order key "store:id" => ['amt' => ₹, 'note', 'by', 'at']. A typed amount is final: no code rule, no bonus on it. */
function cm_fixes(PDO $pdo): array {
    static $m = null; if ($m !== null) return $m; $m = [];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cm_order_fix (order_key VARCHAR(80) NOT NULL PRIMARY KEY, amount DECIMAL(12,2) NOT NULL, calc DECIMAL(12,2) NULL, day DATE NULL, emp_id INT NOT NULL DEFAULT 0, note VARCHAR(200) NOT NULL DEFAULT '', by_user VARCHAR(80) NOT NULL DEFAULT '', at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ($pdo->query("SELECT order_key, amount, note, by_user, at FROM cm_order_fix")->fetchAll() as $r) $m[(string)$r['order_key']] = ['amt' => (float)$r['amount'], 'note' => (string)$r['note'], 'by' => (string)$r['by_user'], 'at' => (string)$r['at']];
    } catch (Throwable $e) { }
    return $m;
}
/** one business day: per person target, sales, and the commission by the new rules. Returns ['rows' => [...], 'error' => ''] */
function cm_day(PDO $pdo, $day, array $ch = null, array $offers = null, array $boosts = null) {
    if (!function_exists('tk_cfg') && is_file(__DIR__ . '/tk_lib.php')) { try { require_once __DIR__ . '/tk_lib.php'; } catch (Throwable $e) { } }   // 🎯 ticket bonus settings
    $ch = $ch ?: cm_chart($pdo); if ($offers === null) $offers = cm_offers($pdo); if ($boosts === null) $boosts = cm_boosts($pdo);
    foreach (['coach_sales_load', 'sales_target_fn', 'day_marks_between'] as $f) if (!function_exists($f)) return ['rows' => [], 'error' => 'The HRMS order functions were not found (' . $f . ').'];
    try {
        $emps = cm_emps($pdo, $day); if (!$emps) return ['rows' => [], 'error' => ''];
        $marks = day_marks_between($pdo, $day, $day); $targetOf = sales_target_fn($pdo, $emps, $day, $day, $marks);
        $sale = coach_sales_load($pdo, $emps, $day, $day);
    } catch (Throwable $e) { return ['rows' => [], 'error' => 'Could not read the orders: ' . $e->getMessage()]; }
    // ONE target everywhere: the day's target is the one the Orders / Targets page shows (ct_day_targets: typed by hand, month target, the automatic rule, the ladder,
    // the profile target; Absent / Leave / not working = 0). It used to come from a simpler rule that does not know the automatic target, so a person with an automatic
    // ₹25,000 on the Orders page had ₹0 here and got no target bonus. A person the Orders page has no row for keeps the simpler rule.
    $ordT = []; if (function_exists('ct_day_targets')) { try { $ordT = ct_day_targets($pdo, (string)$day); } catch (Throwable $e) { $ordT = []; } }
    $pre = (float)$ch['prepaid']; $rows = []; $bdc = cm_bday_cfg($pdo);
    foreach ($emps as $id => $e) {
        $isB = $bdc['on'] && $bdc['pct'] > 0 && cm_is_bday($e['date_of_birth'] ?? '', $day);      // 🎂 this person's birthday: one flat % on every order of the day
        $list = $sale[$day . '|' . $id] ?? []; $target = isset($ordT[(int)$id]) ? (float)$ordT[(int)$id] : (float)$targetOf($id, $day);
        $n = count($list); $sales = 0.0; $nCode = 0; $noMl = 0; $noPrice = 0; $chartCom = 0.0; $offerCom = 0.0; $xcomTotal = 0.0; $lines = [];
        foreach ($list as $o) { if ($o['code'] !== '') $nCode++; }
        // manual orders / quick entries cannot carry a code: they count in the sales, but not in "how many orders need a code" (else adding them would push a person below the code limit)
        $nMan = 0; foreach ($list as $o) { if (strpos((string)($o['key'] ?? ''), 'manual|') === 0) $nMan++; }
        $need = cm_code_need($n - $nMan, (float)$ch['codepct']); $codeOk = $need > 0 && $nCode >= $need; $nocodeF = (float)$ch['nocode'] / 100;
        foreach ($list as $o) {
            $ok = preg_replace('/\|/', ':', (string)($o['key'] ?? ''), 1);      // the same order key the pages use: store:id
            $amt = (float)$o['amt']; $sales += $amt;
            $gross = (!empty($o['pre']) && $pre > 0) ? $amt / (1 - $pre / 100) : $amt;           // the prepaid discount is the company's cost
            $f = ($o['code'] !== '' || $codeOk || !empty($o['web'])) ? 1.0 : $nocodeF; $ml = $o['ml'] ?? null;   // 🛍 a website order cannot carry a code: the no-code half does not apply to it
            // shower gel / attar / others / combos are priced on their own price charts (Learn & Setup → Pricing, shared): a combo prices the WHOLE order (its price already includes the perfume),
            // otherwise that part is paid on top of the perfume commission. The code rule applies to it like to every order.
            if ($isB) { $v = $gross * (float)$bdc['pct'] / 100; $chartCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)($ml ?: 0), 'price' => round($gross), 'rule' => 'birthday', 'pct' => (float)$bdc['pct'], 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => 0]; continue; }      // 🎂 flat birthday % (no code rule, no chart, no offers, the whole order amount)
            $xc = (float)($o['xcom'] ?? 0) * $f; $xcomTotal += $xc;
            if (!empty($o['xall'])) { $lines[] = ['order' => $o['name'], 'ml' => (int)($ml ?: 0), 'price' => round($gross), 'rule' => 'combo', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => round($xc, 2), 'key' => $ok, 'note' => (string)($o['xnote'] ?? '')]; continue; }
            if (!empty($o['mp'])) {   // 🛍 Myntra / Amazon / Flipkart order of a sales person: a flat % (mp_flat_pct, 6) on the whole amount, no code needed — the code rule does not apply to it
                $v = (float)$o['amt'] * mp_flat_pct($pdo) / 100; $offerCom += $v; $lines[] = ['order' => $o['name'], 'ml' => 0, 'price' => round((float)$o['amt']), 'rule' => 'offer marketplace ' . (string)$o['mp'], 'pct' => mp_flat_pct($pdo), 'code' => true, 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2)]; continue; }
            $of = $ml ? cm_offer_match($offers, $ml, $gross, $day, (float)$ch['window']) : null;
            // an order with the free 10 ml add-on bottle(s) counts ml_total = perfume + 10 x ml10 (120 ml offer + 10 ml = 130): if the whole size has no offer, try the size without the 10 ml bottles
            if (!$of && $ml && (int)($o['ml10'] ?? 0) > 0 && $ml - 10 * (int)$o['ml10'] > 0) $of = cm_offer_match($offers, $ml - 10 * (int)$o['ml10'], $gross, $day, (float)$ch['window']);
            if ($of) { $v = $gross * (float)$of['pct'] / 100 * $f; $offerCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'offer #' . $of['id'], 'pct' => (float)$of['pct'], 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2)]; continue; }
            if (!$ml) { if ($xc <= 0) $noMl++; $lines[] = ['order' => $o['name'], 'ml' => 0, 'price' => round($gross), 'rule' => $xc > 0 ? 'gel / attar / others' : 'no ML', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => round($xc, 2), 'key' => $ok, 'note' => (string)($o['xnote'] ?? '') !== '' ? (string)$o['xnote'] : ($xc > 0 ? '' : 'No perfume ML and no shower gel / attar / other product was read from this order (its products could not be read from Shopify, or it has none).')]; continue; }
            $adc = cm_addon_chart($ch, $ml, $gross, (float)$ch['window']);   // 🧴 underpriced as it is, a normal chart price without the add-on: the base size's % on the whole amount
            if ($adc) { $v = $gross * $adc[0] / 100 * $f; $chartCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'chart + add-on ' . (int)$adc[2]['ml'] . ' ml', 'pct' => $adc[0], 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2), 'note' => 'priced as ' . $adc[1] . ' ml + add-on ₹' . round($adc[2]['amount'])]; continue; }
            $pc = cm_pct($ch, $ml, $gross);
            if ($pc === null) { $noPrice++; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'ML not priced', 'pct' => 0, 'code' => $o['code'] !== '', 'com' => 0, 'key' => $ok, 'x' => round($xc, 2)]; continue; }
            $v = $gross * $pc / 100 * $f; $chartCom += $v; $lines[] = ['order' => $o['name'], 'ml' => (int)$ml, 'price' => round($gross), 'rule' => 'chart', 'pct' => $pc, 'code' => $o['code'] !== '', 'com' => round($v, 2), 'key' => $ok, 'x' => round($xc, 2), 'note' => (string)($o['xnote'] ?? '')];
        }
        [$bonus, $tbName, $bo] = cm_bonus_pick($pdo, $ch, $boosts, $id, $day, $sales, $target);
        // A Target bonus rule raises the day's WHOLE commission (chart + offers + gel / attar / others): final = commission + commission × bonus %, one amount for the day,
        // not order by order. The normal over-target steps and a boost stay on the chart commission as before.
        $bonusOn = $tbName !== '' ? 'all' : 'chart';
        // ✏️ orders whose commission was typed by hand: the worked-out amount leaves its bucket (so no bonus is counted on it) and the typed amount is added as it is
        $fixTotal = 0.0; $nFix = 0; $__fx = cm_fixes($pdo);
        if ($__fx) { foreach ($lines as &$ln) { $fk = (string)($ln['key'] ?? ''); if ($fk === '' || !isset($__fx[$fk])) continue;
            $calc = (float)$ln['com']; $xx = (float)($ln['x'] ?? 0); $rl = (string)$ln['rule'];
            if ($rl === 'chart' || $rl === 'birthday') $chartCom -= $calc; elseif (strpos($rl, 'offer') === 0) $offerCom -= $calc; else $xcomTotal -= $calc;
            $xcomTotal -= $xx; $ln['calc'] = round($calc + $xx, 2); $ln['com'] = round($__fx[$fk]['amt'], 2); $ln['x'] = 0; $ln['fixed'] = 1; $ln['fix_note'] = $__fx[$fk]['note']; $ln['fix_by'] = $__fx[$fk]['by']; $fixTotal += $__fx[$fk]['amt']; $nFix++; } unset($ln);
            $chartCom = max(0.0, $chartCom); $offerCom = max(0.0, $offerCom); $xcomTotal = max(0.0, $xcomTotal); }
        $extra = ($bonusOn === 'all' ? $chartCom + $offerCom + $xcomTotal : $chartCom) * $bonus / 100;
        // 🎯 TICKET BONUS (owner, 17 Nov): a day whose CLEAN ticket (the Control Tower's: orders above the big-order limit left out) reaches the ticket goal pays +tk_bonus_pct % of the day's whole commission,
        // on top of (separate from) the target bonus. Needs tk_min_orders orders; switch tk_pay. Flat extra on the total, like the Target bonus rule.
        $tkPct = 0.0; $tkVal = 0.0; $tkc = function_exists('tk_cfg') ? tk_cfg($pdo) : null;
        if ($tkc && $tkc['on'] && $tkc['pay'] && $tkc['goal'] > 0 && $n >= $tkc['min']) {
            $big = function_exists('ct_cfg') ? (float)(ct_cfg($pdo)['big_order'] ?? 0) : 0.0; $cs = 0.0; $cn = 0;
            foreach ($list as $o2) { $a = (float)$o2['amt']; if ($big > 0 && $a > $big) continue; $cs += $a; $cn++; }
            $tkVal = $cn ? $cs / $cn : 0.0; if ($tkVal >= $tkc['goal']) $tkPct = (float)$tkc['pct'];
        }
        $tkExtra = $tkPct > 0 ? ($chartCom + $offerCom + $xcomTotal) * $tkPct / 100 : 0.0;
        // each order's own commission, WITHOUT the day's extra: the extra is one line on the day's total
        $__ad = []; foreach ($list as $__o) if (!empty($__o['addon'])) $__ad[preg_replace('/\|/', ':', (string)($__o['key'] ?? ''), 1)] = (float)$__o['addon'];
        foreach ($lines as &$ln) { $ln['paid'] = round((float)$ln['com'] + (float)($ln['x'] ?? 0), 2); if (isset($__ad[(string)($ln['key'] ?? '')])) $ln['addon'] = round($__ad[(string)$ln['key']]); } unset($ln);
        $old = null;
        if (function_exists('coach_day')) { try { $cd = coach_day($pdo, $day, false, true); $old = isset($cd[$id]) ? (float)$cd[$id]['com_today'] : null; } catch (Throwable $e) {} }
        $total = round($chartCom + $offerCom + $extra + $tkExtra + $xcomTotal + $fixTotal, 2);
        $rows[] = ['id' => $id, 'name' => trim((string)$e['full_name']), 'target' => round($target), 'target_why' => (!($target > 0) && $n > 0) ? cm_target_why($pdo, $id, $day, $marks, $e) : '', 'sales' => round($sales), 'pct' => $target > 0 ? round($sales / $target * 100, 1) : null, 'orders' => $n,
            'codes' => $nCode, 'code_orders' => $n - $nMan, 'manual_orders' => $nMan, 'code_pct' => ($n - $nMan) > 0 ? round($nCode / ($n - $nMan) * 100) : null, 'code_ok' => $codeOk, 'code_need' => $need, 'chart_com' => round($chartCom, 2), 'offer_com' => round($offerCom, 2), 'x_com' => round($xcomTotal, 2),
            'tk_pct' => $tkPct, 'tk_extra' => round($tkExtra, 2), 'tk_ticket' => round($tkVal), 'bonus' => $bonus, 'bonus_rule' => $tbName, 'bonus_on' => $bonusOn, 'boost' => $bo ? $bo['name'] : '', 'extra' => round($extra, 2), 'total' => $total, 'old' => $old, 'diff' => $old === null ? null : round($total - $old, 2), 'no_ml' => $noMl, 'not_priced' => $noPrice, 'fix_com' => round($fixTotal, 2), 'fix_n' => $nFix, 'lines' => $lines];
    }
    usort($rows, function ($a, $b) { return $b['sales'] <=> $a['sales']; });
    return ['rows' => $rows, 'error' => ''];
}
/** ONE figure for every page. What a day PAYS, per person and per order, by the live engine: the same number the Commission page shows. A day with an order the engine
 *  could not price (no ML, size not in the chart) shows ₹0 for that order, exactly as on the Commission page.
 *  Kept for a short while (today / yesterday 2 minutes, older days 30 minutes; a change of the rules is seen at once) so a month does not count 30 days on every page load.
 *  Returns ['emps' => [id => ['total' => ₹ with the day's extra, 'extra' => ₹, 'bonus' => %, 'bonus_name' => '', 'live' => bool]], 'ord' => [order key => [₹ of the order itself, how it was counted]]] */
function cm_paid_day(PDO $pdo, $day) {
    static $mem = [], $ver = null; if (isset($mem[$day])) return $mem[$day];      // 'v2' below: the day's extra is one amount on the total (not inside each order)
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    if ($ver === null) {
        $p = [(string)setting_get($pdo, 'cm_chart', ''), (string)setting_get($pdo, 'price_unify_v1', ''), 'ad:' . md5((string)setting_get($pdo, 'cm_addons', '')) . 'mp:' . (string)setting_get($pdo, 'mp_flat_pct', '6') . 'web2:' . (string)setting_get($pdo, 'tms_commission', '1') . 'tk:' . (string)setting_get($pdo, 'tk_pay', '') . (string)setting_get($pdo, 'tk_goal', '') . (string)setting_get($pdo, 'tk_bonus_pct', '') . (string)setting_get($pdo, 'tk_min_orders', '')];   // a change of the ticket-bonus settings is seen at once
        foreach (["SELECT COUNT(*), COALESCE(MAX(updated_at), ''), COALESCE(SUM(enabled), 0) FROM commission_bonus_rules", "SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(active), 0) FROM cm_boosts", "SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(active), 0), COALESCE(SUM(CRC32(COALESCE(addons, ''))), 0) FROM cm_offers", "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM daily_targets WHERE target_date >= '" . date('Y-m-d', strtotime('-62 days')) . "'"] as $q) {
            try { $p[] = implode(',', (array)$pdo->query($q)->fetch(PDO::FETCH_NUM)); } catch (Throwable $e) { $p[] = '-'; }
        }
        $ver = substr(md5('v3w62|' . implode('|', $p)), 0, 12);
    }
    $ttl = $day >= date('Y-m-d', strtotime($today . ' -1 day')) ? 120 : 1800;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cm_paid_cache (day DATE NOT NULL PRIMARY KEY, ver VARCHAR(16) NOT NULL, at INT NOT NULL, data MEDIUMTEXT NOT NULL)");
        $q = $pdo->prepare("SELECT ver, at, data FROM cm_paid_cache WHERE day = ?"); $q->execute([$day]); $c = $q->fetch();
        if ($c && (string)$c['ver'] === $ver && (int)$c['at'] > time() - $ttl) { $d = json_decode((string)$c['data'], true); if (is_array($d)) return $mem[$day] = $d; }
    } catch (Throwable $e) {}
    $r = cm_day($pdo, $day); if (!empty($r['error'])) return ['emps' => [], 'ord' => [], 'error' => $r['error']];
    $out = ['emps' => [], 'ord' => []];
    foreach ((array)$r['rows'] as $x) {
        $gap = (int)$x['no_ml'] + (int)$x['not_priced'] > 0;      // an order the chart cannot price pays ₹0 (it is listed under Needs fixing on the Commission page)
        $bn = (float)$x['bonus'] > 0 ? ($x['bonus_rule'] !== '' ? $x['bonus_rule'] : ($x['boost'] !== '' ? $x['boost'] : 'Over-target extra')) : '';
        $tkp = (float)($x['tk_pct'] ?? 0); if ($tkp > 0) $bn = ($bn !== '' ? $bn . ' + ' : '') . 'Ticket bonus';
        $out['emps'][(int)$x['id']] = ['total' => round((float)$x['total'], 2), 'live' => !$gap, 'extra' => round((float)$x['extra'] + (float)($x['tk_extra'] ?? 0), 2), 'bonus' => (float)$x['bonus'] + $tkp, 'bonus_name' => $bn];
        foreach ((array)$x['lines'] as $l) {
            if ((string)($l['key'] ?? '') === '') continue;
            $why = $l['rule'] === 'chart' ? 'Chart ' . $l['pct'] . '%'
                : (strpos((string)$l['rule'], 'offer') === 0 ? 'Offer ' . $l['pct'] . '%' : ucfirst((string)$l['rule']));
            if ((float)($l['x'] ?? 0) > 0) $why .= ' + gel / attar / others';
            if (!$l['code'] && !$x['code_ok'] && (float)$l['com'] > 0) $why .= ' · no code: half';
            $out['ord'][(string)$l['key']] = [(float)$l['paid'], $why, (int)$x['id']];   // 3rd = whose order it is (the salesperson's own month sheet shows only his own orders)
        }
    }
    try { $pdo->prepare("REPLACE INTO cm_paid_cache (day, ver, at, data) VALUES (?, ?, ?, ?)")->execute([$day, $ver, time(), json_encode($out)]); } catch (Throwable $e) {}
    return $mem[$day] = $out;
}
/** the last N days with the new rules next to the old figures. Preview only. */
function cm_recount(PDO $pdo, $days = 10, $end = '') {
    $ch = cm_chart($pdo); $offers = cm_offers($pdo); $boosts = cm_boosts($pdo); $by = []; $err = ''; $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$end) && $end <= $today) $today = $end;   // the window ends on this day (a past month for Total Budgeting) instead of today
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day')); $r = cm_day($pdo, $d, $ch, $offers, $boosts); if ($r['error']) { $err = $r['error']; break; }
        foreach ($r['rows'] as $x) {
            $k = $x['id']; if (!isset($by[$k])) $by[$k] = ['id' => $k, 'name' => $x['name'], 'days' => 0, 'met' => 0, 'best_pct' => null, 'best_day' => '', 'new' => 0.0, 'old' => 0.0, 'old_known' => true, 'perday' => []];
            if (!$x['orders'] && !$x['target']) continue;
            $by[$k]['days']++; if ($x['target'] > 0 && $x['sales'] >= $x['target']) $by[$k]['met']++;
            if ($x['pct'] !== null && ($by[$k]['best_pct'] === null || $x['pct'] > $by[$k]['best_pct'])) { $by[$k]['best_pct'] = $x['pct']; $by[$k]['best_day'] = $d; }
            $by[$k]['new'] += $x['total']; if ($x['old'] === null) $by[$k]['old_known'] = false; else $by[$k]['old'] += $x['old'];
            $by[$k]['perday'][] = ['day' => $d, 'target' => $x['target'], 'sales' => $x['sales'], 'pct' => $x['pct'], 'new' => $x['total'], 'old' => $x['old']];
        }
    }
    $o = array_values(array_filter($by, function ($x) { return $x['days'] > 0; }));
    foreach ($o as &$x) { $x['new'] = round($x['new'], 2); $x['old'] = $x['old_known'] ? round($x['old'], 2) : null; $x['diff'] = $x['old'] === null ? null : round($x['new'] - $x['old'], 2); } unset($x);
    // 💰 each person's monthly salary (the people table is listed highest salary first, owner 18 Nov); basic salary when no monthly salary is set
    $sal = []; try { foreach ($pdo->query("SELECT employee_id, monthly_salary, basic_salary FROM employees")->fetchAll() as $e) $sal[(int)$e['employee_id']] = (float)$e['monthly_salary'] > 0 ? (float)$e['monthly_salary'] : (float)$e['basic_salary']; } catch (Throwable $e) { }
    foreach ($o as &$x) $x['salary'] = round($sal[(int)$x['id']] ?? 0, 2); unset($x);
    usort($o, function ($a, $b) { return $b['new'] <=> $a['new']; });
    return ['people' => $o, 'error' => $err, 'days' => $days, 'from' => date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day')), 'to' => $today];
}
/** what is missing or odd, for the "Needs fixing" box at the top of Result */
function cm_needs(PDO $pdo, array $ch, array $offers, array $dayRes) {
    $n = [];
    if (empty($ch['rows'])) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => 'The Pricing chart has no ML rows. Add them first.'];
    else {
        $empty = []; $bad = []; $gaps = [];
        foreach ($ch['rows'] as $r) {
            $p = array_map('floatval', $r['p']); if ($p[0] <= 0) { $empty[] = $r['ml']; continue; }
            if (in_array(0.0, $p, true)) $gaps[] = $r['ml'];
            $last = 1e9; foreach ($p as $v) { if ($v <= 0) continue; if ($v > $last) { $bad[] = $r['ml']; break; } $last = $v; }
        }
        if ($empty) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => count($empty) . ' ML size' . (count($empty) === 1 ? ' has' : 's have') . ' no prices: ' . implode(', ', array_slice($empty, 0, 8)) . (count($empty) > 8 ? '…' : '') . ' ml. Orders of these sizes earn nothing until priced.'];
        if ($gaps) $n[] = ['sev' => 'bad', 'tab' => 'pricing', 'text' => count($gaps) . ' ML size' . (count($gaps) === 1 ? ' has' : 's have') . ' a blank price column (usually the new 5% column): ' . implode(', ', array_slice($gaps, 0, 8)) . (count($gaps) > 8 ? '…' : '') . ' ml. Orders are priced from the columns that are filled, so type the missing prices.'];
        if ($bad) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => 'Prices must fall from the 7% column to the 3% column. Check: ' . implode(', ', array_slice($bad, 0, 8)) . ' ml.'];
        if (empty($ch['reviewed'])) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => 'Check the prices once, especially the 5% and 4% columns (the 5% column is new and starts blank; the 4% column starts from your old 4% price), then press “I checked these prices”.'];
    }
    if ($dayRes['error']) $n[] = ['sev' => 'bad', 'tab' => 'result', 'text' => $dayRes['error']];
    $noT = []; $noMl = 0; $noPr = 0;
    foreach ($dayRes['rows'] as $r) { if ($r['target'] <= 0) $noT[] = $r['name']; $noMl += $r['no_ml']; $noPr += $r['not_priced']; }
    if ($noT) $n[] = ['sev' => 'warn', 'tab' => 'result', 'text' => count($noT) . ' ' . (count($noT) === 1 ? 'person has' : 'people have') . ' no target today: ' . implode(', ', array_slice($noT, 0, 6)) . (count($noT) > 6 ? '…' : '') . '.'];
    if ($noMl) $n[] = ['sev' => 'warn', 'tab' => 'result', 'text' => $noMl . ' order' . ($noMl === 1 ? ' has' : 's have') . ' no ML, so no commission is worked out for ' . ($noMl === 1 ? 'it' : 'them') . '.'];
    if ($noPr) $n[] = ['sev' => 'warn', 'tab' => 'pricing', 'text' => $noPr . ' order' . ($noPr === 1 ? ' is' : 's are') . ' of an ML size outside the chart.'];
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    foreach ($offers as $o) if ((int)$o['active'] === 1 && $o['to_date'] && $o['to_date'] < $today && $o['to_date'] >= date('Y-m-d', strtotime($today . ' -3 day'))) $n[] = ['sev' => 'info', 'tab' => 'offers', 'text' => 'The ' . $o['ml'] . ' ml offer at ₹' . number_format((float)$o['price']) . ' ended on ' . date('j M', strtotime($o['to_date'])) . '. Main pricing applies again.'];
    foreach (cm_boosts($pdo) as $b) if ((int)$b['active'] === 1 && $b['to_date'] < $today && $b['to_date'] >= date('Y-m-d', strtotime($today . ' -3 day'))) $n[] = ['sev' => 'info', 'tab' => 'offers', 'text' => 'The boost “' . $b['name'] . '” ended (closed ' . date('j M', strtotime($b['to_date'] . ' +1 day')) . ' 10:00). Normal over-target steps apply again.'];
    return $n;
}

/* ============================== Part 2: the actions of the Commission screens ============================== */
/** $name = the action without its prefix ('cm_view', 'cm_recount' …). Always answers (json_response exits). */
function cm_action(PDO $pdo, string $name, array $auth, array $in, bool $isPost) {
    $by = (string)($auth['username'] ?? 'user');
    $post = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $adm = function () use ($auth) {
        if (in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true)) return;
        foreach (['commission', 'commission_rules'] as $perm) if (function_exists('user_can') && user_can($auth, $perm)) return;
        json_response(['error' => 'Only Admin or HR can do this.'], 403);
    };
    $gid = function () use ($in) { return (int)($in['id'] ?? $_GET['id'] ?? 0); };
    $out = function ($r) { $ok = $r[0]; $msg = $r[1]; $code = $r[2] ?? 200; $extra = $r[3] ?? []; if (!$ok) json_response(['error' => $msg], $code); json_response(array_merge(['ok' => true, 'message' => $msg], $extra), $code); };
    switch ($name) {
    case 'cm_fix': {   // ✏️ type the commission of ONE order by hand (amount = '' or remove = 1 takes the typed amount away: the rules count it again)
        $adm(); $post(); cm_fixes($pdo);
        $key = mb_substr(trim((string)($in['key'] ?? '')), 0, 80); if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]+:[A-Za-z0-9_\-]+$/', $key)) json_response(['error' => 'This order cannot be edited (no order key).'], 422);
        if (!empty($in['remove']) || trim((string)($in['amount'] ?? '')) === '') { $pdo->prepare("DELETE FROM cm_order_fix WHERE order_key = ?")->execute([$key]); json_response(['ok' => true, 'removed' => true]); }
        if (!is_numeric($in['amount'])) json_response(['error' => 'Type the commission as a number.'], 422);
        $amt = round((float)$in['amount'], 2); if ($amt < 0 || $amt > 500000) json_response(['error' => 'The commission must be between ₹0 and ₹5,00,000.'], 422);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['day'] ?? '')) ? (string)$in['day'] : null;
        $pdo->prepare("INSERT INTO cm_order_fix (order_key, amount, calc, day, emp_id, note, by_user, at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), calc = VALUES(calc), day = VALUES(day), emp_id = VALUES(emp_id), note = VALUES(note), by_user = VALUES(by_user), at = VALUES(at)")
            ->execute([$key, $amt, is_numeric($in['calc'] ?? null) ? round((float)$in['calc'], 2) : null, $day, (int)($in['emp_id'] ?? 0), mb_substr(trim((string)($in['note'] ?? '')), 0, 200), (string)($auth['username'] ?? ''), date('Y-m-d H:i:s')]);
        json_response(['ok' => true, 'key' => $key, 'amount' => $amt]);
    }
    case 'cm_view': { $adm(); if (function_exists('price_unify_once')) price_unify_once($pdo); $ch = cm_chart($pdo); $off = cm_offers($pdo); $bo = cm_boosts($pdo); $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['day'] ?? '')) ? (string)$_GET['day'] : date('Y-m-d');
        $dr = cm_day($pdo, $day, $ch, $off, $bo); $ppl = []; foreach (cm_emps($pdo) as $id => $e) $ppl[] = ['id' => $id, 'name' => trim((string)$e['full_name'])];
        json_response(['bday' => cm_bday_cfg($pdo), 'addons' => cm_global_addons($pdo, true), 'unified' => (string)setting_get($pdo, 'price_unify_v1', '') === '1', 'day' => $day, 'chart' => $ch, 'offers' => $off, 'boosts' => $bo, 'people' => $ppl, 'result' => $dr, 'needs' => cm_needs($pdo, $ch, $off, $dr)]); }
    case 'cm_recount': { $adm(); json_response(cm_recount($pdo, max(1, min(31, (int)($_GET['days'] ?? $in['days'] ?? 10))), (string)($_GET['end'] ?? $in['end'] ?? ''))); }
    case 'cm_chart_save': {   // ONE price chart: prices are edited only in Learn & Setup → Pricing; this saves the commission RULES (and the "I checked" tick)
        $adm(); $post(); $cur = cm_chart($pdo);
        if ((string)setting_get($pdo, 'price_unify_v1', '') === '1') {
            $raw = json_decode((string)setting_get($pdo, 'cm_chart', ''), true); if (!is_array($raw)) $raw = [];
            $n = function ($k, $lo, $hi, $def) use ($in, $cur) { $v = $in[$k] ?? ($cur[$k] ?? $def); return max($lo, min($hi, round((float)$v, 2))); };
            $tiers = []; foreach ((array)($in['tiers'] ?? $cur['tiers'] ?? []) as $t) { $o = (float)($t[0] ?? 0); $x = (float)($t[1] ?? 0); if ($o > 0 && $x > 0) $tiers[] = [$o, $x]; } usort($tiers, function ($a2, $b2) { return $a2[0] <=> $b2[0]; });
            $c = $raw; $c['floor'] = $n('floor', 0, 10, 2.8); $c['codepct'] = $n('codepct', 0, 100, 70); $c['nocode'] = $n('nocode', 0, 100, 50); $c['prepaid'] = $n('prepaid', 0, 20, 2); $c['bonus_max'] = $n('bonus_max', 1, 100, 50); $c['tiers'] = $tiers ?: [[30, 30], [40, 40], [60, 50]];
            $c['reviewed'] = !empty($in['reviewed']) ? 1 : (int)($cur['reviewed'] ?? 0); $c['updated_at'] = cm_now(); $c['updated_by'] = $by; setting_set($pdo, 'cm_chart', json_encode($c));
            cm_log('commission', 'chart', 'commission rules saved', $by); if (function_exists('kb_touch')) kb_touch($pdo, 'Commission rules');
            json_response(['ok' => true, 'message' => 'Commission rules saved. The prices come from the Price Chart (Learn & Setup → Pricing).', 'chart' => cm_chart($pdo)]);
        }
        $r = cm_chart_clean((array)($in['chart'] ?? $in), $cur); if (!$r[0]) json_response(['error' => $r[1]], 422);
        $c = $r[0]; $c['reviewed'] = !empty($in['reviewed']) ? 1 : (int)($cur['reviewed'] ?? 0); $c['updated_at'] = cm_now(); $c['updated_by'] = $by; setting_set($pdo, 'cm_chart', json_encode($c)); cm_log('commission', 'chart', 'pricing chart saved', $by);
        json_response(['ok' => true, 'message' => 'Pricing saved.', 'chart' => $c]); }
    case 'cm_offer_add': { $adm(); $post(); $out(cm_offer_save($pdo, $in, $by)); }
    case 'cm_offer_stop': { $adm(); $post(); $out(cm_offer_stop($pdo, $gid(), $by)); }
    case 'cm_addons_set': { $adm(); $post(); $ads = cm_offer_addon_list($in['addons'] ?? ''); setting_set($pdo, 'cm_addons', json_encode($ads)); cm_global_addons($pdo, true); $out([true, $ads ? 'Add-ons for every order saved (' . count($ads) . ').' : 'Add-ons for every order cleared.', 200]); }
    case 'cm_offer_addons': { $adm(); $post(); $out(cm_offer_addons_set($pdo, $gid(), $in['addons'] ?? '')); }
    case 'cm_bday_save': { $adm(); $post(); $on = !empty($in['on']); $pc = max(0, min(20, round((float)($in['pct'] ?? 0), 2))); if ($on && $pc <= 0) json_response(['error' => 'Type the birthday commission % (for example 7).'], 422);
        setting_set($pdo, 'cm_bday', json_encode(['on' => $on ? 1 : 0, 'pct' => $pc])); cm_log('commission', 'birthday', 'birthday commission ' . ($on ? $pc . '%' : 'off'), $by); json_response(['ok' => true, 'message' => $on ? 'Birthday rule saved: ' . $pc . '% on every order of a Sales person\'s birthday.' : 'Birthday rule is off.', 'bday' => cm_bday_cfg($pdo)]); }
    case 'cm_boost_add': { $adm(); $post(); $out(cm_boost_save($pdo, $in, $by)); }
    case 'cm_boost_stop': { $adm(); $post(); $out(cm_boost_stop($pdo, $gid())); }
    }
    json_response(['error' => 'Unknown commission action.'], 404);
}

}   // function_exists

/* budget.cm_* — routed here by api.php */
if (isset($action, $pdo) && is_string($action) && strpos($action, 'budget.cm_') === 0 && empty($GLOBALS['cm_funcs_only'])) {
    $cmAuth = require_auth(); $cmPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    cm_action($pdo, substr($action, 7), $cmAuth, $cmPost ? json_input() : [], $cmPost);
}
