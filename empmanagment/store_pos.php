<?php
/**
 * store_pos.php — Store inventory + billing API. Loaded by api.php for every action that starts with "st_".
 *
 *   st_inv.products     GET   perfumes with stock per size
 *   st_inv.sync         POST  pull all active perfumes from Shopify
 *   st_inv.rank_sync    POST  pull the online order lines (what sells) from Shopify; products come back best sellers first
 *   st_inv.generate     POST  {items:[{product_id, ml, qty, kind}]}  -> one NEW unique code per bottle (batch)
 *   st_inv.batches      GET   recent batches
 *   st_inv.batch        GET   ?batch=  the bottles of one batch (for printing labels)
 *   st_inv.scan_in      POST  {code}  a printed, stuck-on bottle is scanned -> goes into stock
 *   st_inv.stock        GET   stock by perfume and size, total ml
 *   st_inv.suggest      GET   ?days=14  what to make next: per perfume and size, from real sales (7 + 28 days), minus stock and unscanned labels
 *   st_inv.order_get / order_set / order_place / order_cancel / order_cycle   the saved order list (every change noted, order cycle)
 *   st_inv.mark_printed POST  {codes[]}  labels printed (again): marked, never removed
 *   st_inv.audit        POST  {codes:[]}  weekly audit: what is found / missing / not valid
 *   st_inv.void         POST  {code}  damaged / lost bottle leaves stock
 *   st_bill.scan        POST  {code, codes:[], emp_id}  checks the bottle AND returns the new total ml + prices (one call per scan)
 *   st_bill.quote       POST  {codes:[], emp_id}  same prices without a new bottle (after removing one)
 *   st_bill.create      POST  make the bill; bottles become SOLD
 *   st_bill.list        GET   ?date=
 *   st_bill.void        POST  {bill_id}  (manager) bottles go back to stock
 *   st_chain.list/save/remove/make/pieces/mark_printed   CHAINS: add by hand (photo, MRP, price), one barcode (8 digits) per piece; a piece is stock after st_inv.scan_in_many reads its label
 *   st_bill.create      also {chain_code}: chain at its own price, perfumes get the rest of the SAME total; numbered GST invoice (st_bill.invoice retries it)
 *   st_bill.staff       GET   who can be picked as "Billed by"
 *   st_duty.set         POST  (manager) tick / untick a salesperson
 *   st_dash.get         GET   dashboard: barcodes, stock, today's sales, average ticket, 7 days, and the REMARKS from cleaning, pantry, billing, stock and flyers
 *   st_set.get / st_set.save     label size
 *
 * Permissions (api.php action_permission): st_inv./st_set./st_duty. = rs_manage; st_bill. = rs_counter.
 * Price + commission use the ONE Price & Commission chart the online sales use (ml_slab_get / coach_slab_pct).
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) {
    http_response_code(403);
    exit('Not available.');
}
require_once __DIR__ . '/store_lib.php';
if (!ensure_st_tables($pdo)) json_response(['error' => 'Could not create the Store tables.'], 500);
st_brain_seed($pdo);      // the shared internal brain learns how the store works (once)

/**
 * Sales billed in the OLD POS (the pos_orders / pos_order_items / pos_returns tables, kept in this same database). While the store is still billing there,
 * the Store dashboard must count them too, or a busy day shows ₹0. Same rules as the old POS dashboard: bills = orders of the day, amount = their total minus
 * the day's returns, cash / online as billed. ml = the size in each item's name × quantity. Returns zeros (and logs) if the old tables are not there.
 * Result: ['days' => [date => [bills, amt, disc, cash, online]], 'ml' => ml sold on $to, 'by' => [name => [bills, amt, ml]] for $to].
 */
function st_oldpos_sales(PDO $pdo, string $from, string $to): array
{
    $out = ['days' => [], 'ml' => 0, 'by' => []];
    $h = st_day_hour(); $hh = sprintf(' %02d:00:00', $h); $dd = "DATE(DATE_SUB(created_at, INTERVAL $h HOUR))";      // the store's day: from 3 AM to 3 AM, like every other store number
    $lo = $from . $hh; $hi = date('Y-m-d', strtotime($to . ' +1 day')) . $hh;
    try {
        $q = $pdo->prepare("SELECT $dd d, COUNT(*) bills, COALESCE(SUM(total),0) amt, COALESCE(SUM(discount),0) disc,
                COALESCE(SUM(CASE WHEN cash_amt IS NOT NULL THEN cash_amt WHEN payment_mode = 'cash' THEN total ELSE 0 END),0) cash,
                COALESCE(SUM(CASE WHEN online_amt IS NOT NULL THEN online_amt WHEN payment_mode <> 'cash' THEN total ELSE 0 END),0) online
                FROM pos_orders WHERE created_at >= ? AND created_at < ? GROUP BY $dd");
        $q->execute([$lo, $hi]);
        foreach ($q->fetchAll() as $r) $out['days'][(string)$r['d']] = ['bills' => (int)$r['bills'], 'amt' => (float)$r['amt'], 'disc' => (float)$r['disc'], 'cash' => (float)$r['cash'], 'online' => (float)$r['online']];
    } catch (Throwable $e) { error_log('st_oldpos_sales orders: ' . $e->getMessage()); return $out; }
    try {      // returns come off the day's revenue and cash, like the old POS dashboard
        $q = $pdo->prepare("SELECT $dd d, COALESCE(SUM(amount),0) a FROM pos_returns WHERE created_at >= ? AND created_at < ? GROUP BY $dd");
        $q->execute([$lo, $hi]);
        foreach ($q->fetchAll() as $r) { $d = (string)$r['d']; if (!isset($out['days'][$d])) $out['days'][$d] = ['bills' => 0, 'amt' => 0.0, 'disc' => 0.0, 'cash' => 0.0, 'online' => 0.0]; $out['days'][$d]['amt'] -= (float)$r['a']; $out['days'][$d]['cash'] -= (float)$r['a']; }
    } catch (Throwable $e) { /* no returns table yet: nothing to take off */ }
    try {      // who billed, and the ml of the last day
        $q = $pdo->prepare("SELECT billed_by, COUNT(*) bills, COALESCE(SUM(total),0) amt FROM pos_orders WHERE created_at >= ? AND created_at < ? GROUP BY billed_by");
        $q->execute([$to . $hh, $hi]);
        foreach ($q->fetchAll() as $r) { $n = trim((string)$r['billed_by']); if ($n === '') $n = 'Old POS'; $out['by'][$n] = ['bills' => (int)$r['bills'], 'amt' => (float)$r['amt'], 'ml' => 0]; }
        $q = $pdo->prepare("SELECT o.billed_by, oi.variant_title vt, oi.title t, SUM(oi.qty) q FROM pos_order_items oi JOIN pos_orders o ON o.id = oi.order_id WHERE o.created_at >= ? AND o.created_at < ? GROUP BY o.billed_by, oi.variant_title, oi.title");
        $q->execute([$to . $hh, $hi]);
        foreach ($q->fetchAll() as $r) {
            $ml = 0; if (preg_match('/(\d+)\s*ml/i', (string)$r['vt'], $m) || preg_match('/(\d+)\s*ml/i', (string)$r['t'], $m)) $ml = (int)$m[1] * (int)$r['q'];
            $n = trim((string)$r['billed_by']); if ($n === '') $n = 'Old POS';
            $out['ml'] += $ml; if (isset($out['by'][$n])) $out['by'][$n]['ml'] += $ml;
        }
    } catch (Throwable $e) { error_log('st_oldpos_sales items: ' . $e->getMessage()); }
    return $out;
}

function st_bottle_out(array $b): array
{
    return [
        'bottle_id' => (int)$b['bottle_id'], 'code' => (string)$b['code'], 'product_id' => (int)$b['product_id'],
        'title' => (string)($b['title'] ?? ''), 'ml' => (int)$b['ml'], 'kind' => (string)$b['kind'], 'status' => (string)$b['status'],
        'batch' => (string)$b['batch'], 'mfg_date' => (string)$b['mfg_date'], 'exp_date' => (string)$b['exp_date'], 'print_count' => (int)($b['print_count'] ?? 0), 'printed_at' => (string)($b['printed_at'] ?? ''),
    ];
}

function st_find_bottles(PDO $pdo, array $codes, bool $lock = false): array
{
    $codes = array_values(array_unique(array_filter(array_map('st_clean_code', $codes), 'strlen')));
    if (!$codes) return [];
    $in = implode(',', array_fill(0, count($codes), '?'));
    if ($lock) { $lk = $pdo->prepare("SELECT bottle_id FROM st_bottles WHERE code IN ($in) FOR UPDATE"); $lk->execute($codes); }   // lock the bottles only, never the perfume rows
    $st = $pdo->prepare("SELECT b.*, p.title FROM st_bottles b JOIN st_products p ON p.product_id = b.product_id WHERE b.code IN ($in)");
    $st->execute($codes);
    $by = [];
    foreach ($st->fetchAll() as $r) $by[(string)$r['code']] = $r;
    return $by;
}

/** Price in every chart column for the TOTAL ml + the commission % each column pays (the same chart the online sales use). */
function st_quote(PDO $pdo, int $totalMl, int $empId): array
{
    $day = st_day();
    $chart = ml_slab_for(ml_slab_get($pdo), $day, $empId);
    $prices = st_chart_prices($chart, $totalMl);
    $cols = [];
    if ($prices) {
        foreach ($prices as $i => $p) {
            $pct = coach_slab_pct($chart, $totalMl, (float)$p, false);
            $cols[] = ['col' => $i, 'price' => (float)$p, 'pct' => $pct === null ? null : round($pct, 2), 'label' => (string)($chart['cols'][$i] ?? '')];
        }
    }
    // the OFFERS of Commission Rules (the same list the online sales use): an offer on this exact size is one more price to pick, with its own commission %
    $offers = []; $flash = null;
    if ($totalMl > 0 && budget_engine_ready()) {
        try { foreach (cm_offers($pdo) as $o) { if (empty($o['active']) || (int)$o['ml'] !== $totalMl) continue; if ($o['from_date'] && $day < $o['from_date']) continue; if ($o['to_date'] && $day > $o['to_date']) continue;
            $cols[] = ['col' => count($cols), 'price' => (float)$o['price'], 'pct' => round((float)$o['pct'], 2), 'label' => 'Offer', 'offer' => true, 'note' => (string)($o['note'] ?? '')]; $offers[] = (int)$o['id']; } } catch (Throwable $e) { error_log('st_quote offers: ' . $e->getMessage()); }
        try { if (!cm_is_live($pdo)) { $fp = flash_offer_pct_for(flash_offer_list_get($pdo), $totalMl, 0.0, time()); if ($fp !== null) $flash = ['pct' => (float)$fp['pct']]; } } catch (Throwable $e) { }
    }
    return ['total_ml' => $totalMl, 'in_chart' => $prices !== null || $offers, 'columns' => $cols, 'chart_on' => !empty($chart['on']), 'flash' => $flash];
}

/** Commission % this bill pays on its final price — the SAME order the online sales use: a matching Offer (Commission Rules: size + price) → a Flash Offer (only while the old engine pays) → the price chart. */
function st_pct_for(PDO $pdo, int $totalMl, float $amt, int $empId, bool $hasCode, ?string $day = null, ?int $ts = null): array
{
    $day = $day ?: st_day(); $ts = $ts ?: time();
    $chart = ml_slab_for(ml_slab_get($pdo), $day, $empId);
    if ($totalMl > 0 && budget_engine_ready()) {
        try { $of = cm_offer_match(cm_offers($pdo), $totalMl, $amt, $day, (float)cm_chart($pdo)['window']); if ($of) return ['pct' => (float)$of['pct'], 'source' => 'offer'];
            if (!cm_is_live($pdo)) { $flash = flash_offer_pct_for(flash_offer_list_get($pdo), $totalMl, $amt, $ts); if ($flash !== null) return ['pct' => (float)$flash['pct'], 'source' => 'flash offer']; } } catch (Throwable $e) { error_log('st_pct_for offers: ' . $e->getMessage()); }
    } else { $flash = flash_offer_pct_for(flash_offer_list_get($pdo), $totalMl, $amt, $ts); if ($flash !== null) return ['pct' => (float)$flash['pct'], 'source' => 'flash offer']; }
    $pc = !empty($chart['on']) ? coach_slab_pct($chart, $totalMl, $amt, false) : null;
    return ['pct' => $pc === null ? null : round($pc, 2), 'source' => 'price chart'];
}

/** Today's punch of each person: [id => ['in'=>'HH:MM','out'=>'HH:MM'|'']] (the attendance day, same rule as the Attendance page). */
function st_punch_map(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids))); if (!$ids) return [];
    $day = function_exists('attendance_day_now') ? attendance_day_now($pdo) : date('Y-m-d'); $out = [];
    try {
        $q = $pdo->prepare("SELECT employee_id, punch_in, punch_out FROM attendance WHERE punch_date = ? AND punch_in IS NOT NULL AND employee_id IN (" . implode(',', $ids) . ")");
        $q->execute([$day]);
        foreach ($q->fetchAll() as $r) $out[(int)$r['employee_id']] = ['in' => substr((string)$r['punch_in'], 11, 5), 'out' => $r['punch_out'] ? substr((string)$r['punch_out'], 11, 5) : ''];
    } catch (Throwable $e) { error_log('st_punch_map: ' . $e->getMessage()); }
    return $out;
}

/**
 * One store person's day: bills of the STORE day (3 AM to 3 AM), estimated commission per bill (same price chart as everywhere),
 * punch, and what the HRMS Salary says about today and this month (daily pay, penalties from the Boss engine, net).
 */
function st_staff_day(PDO $pdo, int $empId, ?array $punch): array
{
    $day = st_day(); $bills = []; $sum = 0.0; $ml = 0; $com = 0.0;
    // Commission = what the ONLINE sales commission rules pay for each bill (price chart, offers, order-code rule), no over-target extra: the same figures as
    // Total Budgeting → Store. If the commission engine is not available on this server, fall back to the older per-bill chart estimate.
    $eng = function_exists('budget_engine_ready') && budget_engine_ready();
    if ($eng) {
        try {
            $r = budget_store_calc($pdo, $day, $day); $times = [];
            foreach (array_reverse($r['bills']) as $b) {
                if ((int)$b['emp_id'] !== $empId) continue;
                $bills[] = ['bill_no' => $b['bill_no'], 'time' => $b['time'], 'ml' => (int)$b['ml'], 'total' => (float)$b['total'], 'pct' => $b['pct'], 'commission' => (float)$b['com'], 'code' => $b['code'] ? 'yes' : '', 'rule' => $b['rule'] . ($b['reduced'] ? ' (no code: reduced)' : '')];
                $sum += (float)$b['total']; $ml += (int)$b['ml']; $com += (float)$b['com'];
            }
        } catch (Throwable $e) { error_log('st_staff_day engine: ' . $e->getMessage()); $eng = false; $bills = []; $sum = 0.0; $ml = 0; $com = 0.0; }
    }
    if (!$eng) {
        $q = $pdo->prepare("SELECT bill_id, bill_no, created_at, total_ml, total, order_code FROM st_bills WHERE emp_id = ? AND biz_date = ? AND status = 'ok' ORDER BY bill_id DESC");
        $q->execute([$empId, $day]);
        foreach ($q->fetchAll() as $b) {
            $pc = st_pct_for($pdo, (int)$b['total_ml'], (float)$b['total'], $empId, trim((string)$b['order_code']) !== '');
            $c = $pc['pct'] === null ? 0.0 : round((float)$b['total'] * $pc['pct'] / 100, 2);
            $bills[] = ['bill_no' => $b['bill_no'], 'time' => substr((string)$b['created_at'], 11, 5), 'ml' => (int)$b['total_ml'], 'total' => (float)$b['total'], 'pct' => $pc['pct'], 'commission' => $c, 'code' => $b['order_code']];
            $sum += (float)$b['total']; $ml += (int)$b['total_ml']; $com += $c;
        }
    }
    $pay = null;
    try {
        $e = $pdo->prepare("SELECT employee_id, full_name, employee_code, monthly_salary FROM employees WHERE employee_id = ?"); $e->execute([$empId]); $emp = $e->fetch();
        if ($emp && (float)$emp['monthly_salary'] > 0 && function_exists('salary_calc')) {
            $c = salary_calc($pdo, $emp, date('Y-m'), salary_rules_load($pdo)); $today = null;
            foreach ($c['days'] as $d) if ($d['date'] === date('Y-m-d')) $today = $d;
            $pay = ['daily_rate' => $c['daily_rate'], 'today' => $today ? ['status' => $today['status'], 'amount' => $today['amount'], 'pct' => $today['pct']] : null, 'earned' => $c['earned'], 'penalty_total' => $c['penalty_total'], 'penalties' => array_slice((array)$c['penalties'], 0, 6), 'net_pay' => $c['net_pay'], 'counts' => $c['counts']];
        }
    } catch (Throwable $e) { error_log('st_staff_day pay: ' . $e->getMessage()); }
    return ['day' => $day, 'bills' => $bills, 'bills_n' => count($bills), 'sales' => round($sum, 2), 'ml' => $ml, 'commission' => round($com, 2), 'punch' => $punch, 'pay' => $pay];
}

/** What to make next, per perfume AND size, from what really sold. Deducts stock, labels printed-but-not-scanned, and bottles still to come from placed orders. */
function st_suggest_calc(PDO $pdo, int $days): array
{
    $days = max(3, min(60, $days));
    $today = st_day();
    $d7 = date('Y-m-d', strtotime($today . ' -6 day')); $d28 = date('Y-m-d', strtotime($today . ' -27 day'));
    $sold = [];
    $q = $pdo->prepare("SELECT b.product_id, b.ml, SUM(bl.biz_date >= ?) s7, COUNT(*) s28 FROM st_bill_items i JOIN st_bills bl ON bl.bill_id = i.bill_id AND bl.status = 'ok' JOIN st_bottles b ON b.bottle_id = i.bottle_id
        WHERE i.kind = 'sale' AND bl.biz_date >= ? GROUP BY b.product_id, b.ml");
    $q->execute([$d7, $d28]);
    foreach ($q->fetchAll() as $r) $sold[(int)$r['product_id'] . '|' . (int)$r['ml']] = [(int)$r['s7'], (int)$r['s28']];
    $have = []; $names = [];
    foreach ($pdo->query("SELECT p.product_id, p.title, b.ml, SUM(b.status = 'stock') stk, SUM(b.status = 'generated') wait FROM st_products p JOIN st_bottles b ON b.product_id = p.product_id WHERE p.active = 1 AND b.kind = 'sale' GROUP BY p.product_id, p.title, b.ml")->fetchAll() as $r) {
        $have[(int)$r['product_id'] . '|' . (int)$r['ml']] = [(int)$r['stk'], (int)$r['wait']];
    }
    foreach ($pdo->query("SELECT product_id, title FROM st_products WHERE active = 1")->fetchAll() as $r) $names[(int)$r['product_id']] = $r['title'];
    $onOrder = st_on_order_map($pdo); $draft = [];
    foreach ($pdo->query("SELECT i.product_id, i.ml, i.qty FROM st_order_items i JOIN st_orders o ON o.order_id = i.order_id AND o.status = 'draft'")->fetchAll() as $r) $draft[(int)$r['product_id'] . '|' . (int)$r['ml']] = (int)$r['qty'];
    $items = []; $totB = 0; $totMl = 0; $perf = [];
    foreach ($sold as $k => [$s7, $s28]) {
        [$pid, $ml] = array_map('intval', explode('|', $k));
        if (!isset($names[$pid]) || !in_array($ml, ST_SIZES, true)) continue;
        [$stk, $wait] = $have[$k] ?? [0, 0]; $oo = $onOrder[$k] ?? 0;
        $rate = 0.6 * ($s7 / 7) + 0.4 * ($s28 / 28);
        if ($rate <= 0) continue;
        $need = (int)ceil($rate * $days) - ($stk + $oo);                // only real stock (scanned bottles) and placed orders count; spare barcodes never do
        if ($need < 1) continue;
        $left = $stk / $rate;
        $prio = ($stk === 0 || $left < 3) ? 'urgent' : ($left < 7 ? 'soon' : 'plan');
        $why = 'Sold ' . $s28 . ' in 28 days (' . $s7 . ' this week) · ' . ($stk === 0 ? 'none in stock' : $stk . ' in stock ≈ ' . max(1, (int)round($left)) . ' day' . ((int)round($left) > 1 ? 's' : '')) . ($oo ? ' · ' . $oo . ' already on order' : '');
        $items[] = ['product_id' => $pid, 'title' => $names[$pid], 'ml' => $ml, 'qty' => $need, 'priority' => $prio, 'sold7' => $s7, 'sold28' => $s28, 'stock' => $stk, 'waiting' => $wait, 'on_order' => $oo, 'in_draft' => $draft[$k] ?? 0, 'days_left' => $stk === 0 ? 0 : round($left, 1), 'why' => $why];
        $totB += $need; $totMl += $need * $ml; $perf[$pid] = 1;
    }
    $rank = ['urgent' => 0, 'soon' => 1, 'plan' => 2];
    usort($items, fn($a, $b) => [$rank[$a['priority']], strtolower($a['title']), $a['ml']] <=> [$rank[$b['priority']], strtolower($b['title']), $b['ml']]);
    return ['days' => $days, 'items' => $items, 'total_bottles' => $totB, 'total_ml' => $totMl, 'perfumes' => count($perf),
        'urgent' => count(array_filter($items, fn($x) => $x['priority'] === 'urgent')),
        'method' => 'Blend of this week\'s and the last 28 days\' sales for each perfume and size, planned for ' . $days . ' days, minus the bottles really in stock (scanned) and what is already on order.'];
}

try {
    switch ($action) {

        // ====================================================== inventory
        case 'st_inv.products': {
            require_auth();
            $rows = $pdo->query("SELECT p.product_id, p.title, p.image,
                    SUM(b.status = 'stock' AND b.kind = 'sale') AS stock_sale, SUM(b.status = 'stock' AND b.kind = 'tester') AS stock_tester, SUM(b.status = 'generated') AS waiting
                FROM st_products p LEFT JOIN st_bottles b ON b.product_id = p.product_id
                WHERE p.active = 1 GROUP BY p.product_id ORDER BY p.title")->fetchAll();
            foreach ($rows as &$r) { $r['product_id'] = (int)$r['product_id']; $r['stock_sale'] = (int)$r['stock_sale']; $r['stock_tester'] = (int)$r['stock_tester']; $r['waiting'] = (int)$r['waiting']; }
            $rk = st_rank_cached($pdo); $srcOn = ['store' => 0, 'pos' => 0, 'online' => 0];
            foreach ($rows as &$r) { $x = $rk[$r['product_id']] ?? null; $r['score'] = $x ? $x['score'] : 0; $r['q30'] = $x ? $x['q30'] : 0; $r['q90'] = $x ? $x['q90'] : 0; $r['rank'] = $x ? $x['rank'] : 0; $r['badge'] = $x ? $x['badge'] : 'none'; $r['src'] = $x ? $x['src'] : $srcOn; if ($x) foreach ($x['src'] as $k => $v) $srcOn[$k] += $v; }
            unset($r);
            // best sellers first, then rising / new, steady, slow, and not-selling last (A-Z inside the last group)
            $order = ['top' => 0, 'new' => 1, 'rising' => 1, 'steady' => 2, 'slow' => 3, 'none' => 4];
            usort($rows, fn($a, $b) => [$order[$a['badge']], -$a['score'], strtolower($a['title'])] <=> [$order[$b['badge']], -$b['score'], strtolower($b['title'])]);
            json_response(['products' => $rows, 'sizes' => ST_SIZES, 'rank' => ['sources' => $srcOn, 'at' => setting_get($pdo, 'st_rank_at', ''), 'ok' => setting_get($pdo, 'st_rank_ok', '') === '1', 'message' => setting_get($pdo, 'st_rank_msg', '')], 'sync' => ['at' => setting_get($pdo, 'st_sync_at', ''), 'ok' => setting_get($pdo, 'st_sync_ok', '') === '1', 'message' => setting_get($pdo, 'st_sync_msg', '')]]);
            break;
        }

        case 'st_inv.rank_sync': {          // pull the online (Shopify) order lines now; the daily job does it every morning too
            require_auth(); @set_time_limit(150);
            $r = st_online_sync($pdo, 90); st_rank_cache_clear();
            json_response($r['ok'] ? $r : ['error' => $r['message']], $r['ok'] ? 200 : 502);
            break;
        }

        case 'st_inv.sync': {
            require_auth();
            $r = st_sync_run($pdo, 'button'); st_rank_cache_clear();
            json_response($r['ok'] ? $r : ['error' => $r['message']], $r['ok'] ? 200 : 502);
            break;
        }

        case 'st_inv.generate': {
            $auth = require_auth();
            $in = json_input();
            $items = is_array($in['items'] ?? null) ? $in['items'] : [];
            $plan = []; $n = 0;
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0); $ml = (int)($it['ml'] ?? 0); $qty = (int)($it['qty'] ?? 0);
                $kind = ($it['kind'] ?? 'sale') === 'tester' ? 'tester' : 'sale';
                if ($qty <= 0) continue;
                if (!$pid || !in_array($ml, ST_SIZES, true)) json_response(['error' => 'Pick a perfume and one of the sizes ' . implode(', ', ST_SIZES) . ' ml.'], 400);
                $plan[] = [$pid, $ml, $qty, $kind]; $n += $qty;
            }
            if (!$plan) json_response(['error' => 'Add at least one perfume with a quantity.'], 400);
            if ($n > 5000) json_response(['error' => 'At most 5000 barcodes in one go (you asked for ' . $n . '). Make the rest in a second go.'], 400);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM st_products WHERE product_id = ? AND active = 1");
            foreach ($plan as $p) { $chk->execute([$p[0]]); if (!$chk->fetchColumn()) json_response(['error' => 'A chosen perfume is no longer on the list. Press Sync from Shopify.'], 400); }
            $mfg = date('Y-m-d'); $exp = date('Y-m-d', strtotime($mfg . ' +4 years'));
            $batch = 'B' . date('ymd-His');
            $pdo->beginTransaction();
            $now = date('Y-m-d H:i:s'); $by = (int)($auth['employee_id'] ?? 0) ?: null;
            $rows = [];
            foreach ($plan as [$pid, $ml, $qty, $kind]) for ($i = 0; $i < $qty; $i++) $rows[] = [$pid, $ml, $kind];
            foreach (array_chunk($rows, 100) as $chunk) {      // 100 bottles per query instead of one query each
                $ph = []; $args = [];
                foreach ($chunk as [$pid, $ml, $kind]) { $ph[] = "(?, ?, ?, ?, 'generated', ?, ?, ?, ?, ?)"; array_push($args, 't' . bin2hex(random_bytes(5)), $pid, $ml, $kind, $batch, $mfg, $exp, $by, $now); }
                $pdo->prepare("INSERT INTO st_bottles (code, product_id, ml, kind, status, batch, mfg_date, exp_date, created_by, created_at) VALUES " . implode(',', $ph))->execute($args);
            }
            // every bottle's real code in ONE statement: its own id + 100000 (a different number each time, always 6 digits)
            $pdo->prepare("UPDATE st_bottles SET code = CAST(bottle_id + " . ST_CODE_BASE . " AS CHAR) WHERE batch = ? AND code LIKE 't%'")->execute([$batch]);
            $top = (int)$pdo->query("SELECT MAX(bottle_id) FROM st_bottles")->fetchColumn();
            if (ST_CODE_BASE + $top > 999999) { $pdo->rollBack(); json_response(['error' => 'The 6-digit code range is used up.'], 500); }
            $pdo->commit();
            $bs = $pdo->prepare("SELECT b.*, p.title FROM st_bottles b JOIN st_products p ON p.product_id = b.product_id WHERE b.batch = ? ORDER BY p.title, b.ml, b.bottle_id"); $bs->execute([$batch]);
            json_response(['ok' => true, 'batch' => $batch, 'count' => $n, 'bottles' => array_map('st_bottle_out', $bs->fetchAll()), 'message' => $n . ' barcodes made. Print the labels and stick them on the bottles.']);
            break;
        }

        case 'st_inv.batches': {
            require_auth();
            $rows = $pdo->query("SELECT batch, MIN(created_at) AS created_at, COUNT(*) AS bottles, SUM(status <> 'generated') AS scanned FROM st_bottles GROUP BY batch ORDER BY MIN(bottle_id) DESC LIMIT 30")->fetchAll();
            foreach ($rows as &$r) { $r['bottles'] = (int)$r['bottles']; $r['scanned'] = (int)$r['scanned']; $r['unused'] = $r['bottles'] - $r['scanned']; }
            json_response(['batches' => $rows]);
            break;
        }

        case 'st_inv.batch': {
            require_auth();
            $st = $pdo->prepare("SELECT b.*, p.title FROM st_bottles b JOIN st_products p ON p.product_id = b.product_id WHERE b.batch = ? ORDER BY p.title, b.ml, b.bottle_id");
            $st->execute([(string)($_GET['batch'] ?? '')]);
            json_response(['bottles' => array_map('st_bottle_out', $st->fetchAll())]);
            break;
        }

        case 'st_inv.scan_in': {
            require_auth();
            $code = st_clean_code(json_input()['code'] ?? '');
            $b = st_find_bottles($pdo, [$code])[$code] ?? null;
            if (!$b) json_response(['error' => 'Code ' . $code . ' is not one of our barcodes.'], 404);
            if ($b['status'] === 'stock') json_response(['ok' => false, 'dup' => true, 'bottle' => st_bottle_out($b), 'message' => 'Already in stock: ' . $b['title'] . ' ' . $b['ml'] . ' ml.']);
            if ($b['status'] !== 'generated') json_response(['error' => 'This bottle is ' . $b['status'] . ' — it cannot be added to stock.'], 409);
            $pdo->prepare("UPDATE st_bottles SET status = 'stock', stocked_at = ? WHERE bottle_id = ? AND status = 'generated'")->execute([date('Y-m-d H:i:s'), $b['bottle_id']]);
            $b['status'] = 'stock';
            json_response(['ok' => true, 'bottle' => st_bottle_out($b), 'message' => 'Added: ' . $b['title'] . ' ' . $b['ml'] . ' ml' . ($b['kind'] === 'tester' ? ' (tester)' : '')]);
            break;
        }

        // a burst of scans in ONE request (up to 120 codes): one answer per code, the same messages as a single scan
        case 'st_inv.scan_in_many': {
            require_auth();
            $in = json_input(); $codes = [];
            foreach (array_slice(is_array($in['codes'] ?? null) ? $in['codes'] : [], 0, 120) as $c) { $c = st_clean_code($c); if ($c !== '') $codes[$c] = 1; }
            $codes = array_map('strval', array_keys($codes)); $found = $codes ? st_find_bottles($pdo, $codes) : []; $res = []; $ids = [];
            $chainFound = [];      // codes that are not bottles may be chain labels (8 digits): the chain goes into stock the same way
            $rest = array_values(array_filter($codes, fn($c) => !isset($found[$c])));
            if ($rest) { $cq = $pdo->prepare("SELECT p.*, c.name FROM st_chain_pieces p JOIN st_chains c ON c.chain_id = p.chain_id WHERE p.code IN (" . implode(',', array_fill(0, count($rest), '?')) . ")"); $cq->execute($rest); foreach ($cq->fetchAll() as $r) $chainFound[(string)$r['code']] = $r; }
            $cOut = fn(array $r) => ['bottle_id' => 0, 'code' => (string)$r['code'], 'product_id' => 0, 'title' => 'Chain: ' . $r['name'], 'ml' => 0, 'kind' => 'chain', 'status' => 'stock', 'is_chain' => true];
            $cIds = [];
            foreach ($codes as $c) {
                if (isset($chainFound[$c])) {
                    $r = $chainFound[$c];
                    if ($r['status'] === 'stock') { $res[$c] = ['code' => (string)$c, 'ok' => false, 'dup' => true, 'bottle' => $cOut($r), 'message' => 'Already in stock: chain ' . $r['name'] . '.']; continue; }
                    if ($r['status'] !== 'generated') { $res[$c] = ['code' => (string)$c, 'ok' => false, 'bad' => true, 'message' => 'This chain is ' . $r['status'] . ' — it cannot be added to stock.']; continue; }
                    $cIds[] = (int)$r['piece_id']; $res[$c] = ['code' => (string)$c, 'ok' => true, 'bottle' => $cOut($r), 'message' => 'Added: chain ' . $r['name']]; continue;
                }
                $b = $found[$c] ?? null;
                if (!$b) { $res[$c] = ['code' => (string)$c, 'ok' => false, 'bad' => true, 'message' => 'Code ' . $c . ' is not one of our barcodes.']; continue; }
                if ($b['status'] === 'stock') { $res[$c] = ['code' => (string)$c, 'ok' => false, 'dup' => true, 'bottle' => st_bottle_out($b), 'message' => 'Already in stock: ' . $b['title'] . ' ' . $b['ml'] . ' ml.']; continue; }
                if ($b['status'] !== 'generated') { $res[$c] = ['code' => (string)$c, 'ok' => false, 'bad' => true, 'message' => 'This bottle is ' . $b['status'] . ' — it cannot be added to stock.']; continue; }
                $ids[] = (int)$b['bottle_id']; $found[$c]['status'] = 'stock';
            }
            if ($cIds) $pdo->prepare("UPDATE st_chain_pieces SET status = 'stock', stocked_at = ? WHERE status = 'generated' AND piece_id IN (" . implode(',', $cIds) . ")")->execute([date('Y-m-d H:i:s')]);
            if ($ids) {
                $pdo->prepare("UPDATE st_bottles SET status = 'stock', stocked_at = ? WHERE status = 'generated' AND bottle_id IN (" . implode(',', $ids) . ")")->execute([date('Y-m-d H:i:s')]);
                foreach ($codes as $c) if (!isset($res[$c])) { $b = $found[$c]; $res[$c] = ['code' => (string)$c, 'ok' => true, 'bottle' => st_bottle_out($b), 'message' => 'Added: ' . $b['title'] . ' ' . $b['ml'] . ' ml' . ($b['kind'] === 'tester' ? ' (tester)' : '')]; }
            }
            json_response(['results' => array_values($res)]);
            break;
        }

        case 'st_inv.stock': {
            require_auth();
            $rows = $pdo->query("SELECT p.product_id, p.title, b.ml, b.kind, SUM(b.status = 'stock') AS in_stock, SUM(b.status = 'generated') AS waiting
                FROM st_bottles b JOIN st_products p ON p.product_id = b.product_id
                WHERE b.status = 'stock' GROUP BY p.product_id, p.title, b.ml, b.kind ORDER BY p.title, b.ml")->fetchAll();
            $ml = 0; $out = [];
            foreach ($rows as $r) {
                $r['in_stock'] = (int)$r['in_stock']; $r['waiting'] = (int)$r['waiting']; $r['ml'] = (int)$r['ml']; $r['product_id'] = (int)$r['product_id'];
                if ($r['kind'] === 'sale') $ml += $r['in_stock'] * $r['ml'];
                $out[] = $r;
            }
            // ONE entry per perfume (also the ones with nothing left): stock per size, testers, labels waiting, and sales this week
            $since = date('Y-m-d H:i:s', time() - 7 * 86400); $prod = [];
            $q = $pdo->prepare("SELECT p.product_id, p.title, b.ml, b.kind, SUM(b.status = 'stock') in_stock, SUM(b.status = 'generated') waiting, SUM(b.status = 'sold' AND b.sold_at >= ?) sold7
                FROM st_products p JOIN st_bottles b ON b.product_id = p.product_id WHERE p.active = 1 AND b.status <> 'void' AND b.stocked_at IS NOT NULL GROUP BY p.product_id, p.title, b.ml, b.kind");
            $q->execute([$since]);
            foreach ($q->fetchAll() as $r) {
                $id = (int)$r['product_id'];
                if (!isset($prod[$id])) $prod[$id] = ['product_id' => $id, 'title' => $r['title'], 'sizes' => ['10' => 0, '20' => 0, '50' => 0, '100' => 0], 'bottles' => 0, 'ml' => 0, 'testers' => 0, 'waiting' => 0, 'sold7' => 0];
                $n = (int)$r['in_stock']; $prod[$id]['waiting'] += (int)$r['waiting'];
                if ($r['kind'] === 'tester') { $prod[$id]['testers'] += $n; continue; }
                $prod[$id]['sizes'][(string)(int)$r['ml']] = ($prod[$id]['sizes'][(string)(int)$r['ml']] ?? 0) + $n;
                $prod[$id]['bottles'] += $n; $prod[$id]['ml'] += $n * (int)$r['ml']; $prod[$id]['sold7'] += (int)$r['sold7'];
            }
            usort($prod, fn($a, $b) => strcasecmp($a['title'], $b['title']));
            json_response(['rows' => $out, 'total_ml' => $ml, 'products' => array_values($prod)]);
            break;
        }

        // ---- suggested order: what to make next, from what REALLY sold (per perfume AND size), not from guesses
        case 'st_inv.plan': {          // Order planner: "I want to order N bottles of X ml" -> the list (retail store only: old POS + new POS)
            require_auth();
            $sz = [];       // sizes=20:50,50:200  (ml:how many)
            foreach (explode(',', (string)($_GET['sizes'] ?? '')) as $part) { $x = explode(':', trim($part)); if (count($x) === 2) $sz[(int)$x[0]] = (int)$x[1]; }
            if (!$sz) $sz = [(int)($_GET['ml'] ?? 50) => (int)($_GET['total'] ?? 600)];
            json_response(st_plan_multi($pdo, $sz, (int)($_GET['days'] ?? 10)));
            break;
        }

        case 'st_inv.suggest': {
            require_auth();
            json_response(st_suggest_calc($pdo, (int)($_GET['days'] ?? 14)));
            break;
        }

        // ====================================================== CHAINS (added by hand, no stock at add; one barcode per piece; a piece is stock only after it is scanned in)
        case 'st_chain.list': {
            require_auth();
            $rows = $pdo->query("SELECT c.chain_id, c.name, c.mrp, c.price, c.photo, c.active, COUNT(p.piece_id) made, COALESCE(SUM(p.status='generated'),0) waiting, COALESCE(SUM(p.status='stock'),0) in_stock, COALESCE(SUM(p.status='sold'),0) sold,
                COALESCE(SUM(p.status='generated' AND p.print_count=0),0) unprinted FROM st_chains c LEFT JOIN st_chain_pieces p ON p.chain_id = c.chain_id AND p.status <> 'void' WHERE c.active = 1 GROUP BY c.chain_id ORDER BY c.name")->fetchAll();
            foreach ($rows as &$r) { foreach (['chain_id', 'made', 'waiting', 'in_stock', 'sold', 'unprinted', 'active'] as $k) $r[$k] = (int)$r[$k]; $r['mrp'] = (float)$r['mrp']; $r['price'] = (float)$r['price']; } unset($r);
            json_response(['chains' => $rows, 'in_stock' => array_sum(array_column($rows, 'in_stock'))]);
            break;
        }

        case 'st_chain.save': {          // add a chain (name, MRP, price, photo) or change it. No stock here: stock comes from scanning the stuck labels.
            $auth = require_auth(); $in = json_input();
            $id = (int)($in['chain_id'] ?? 0); $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
            $mrp = round((float)($in['mrp'] ?? 0), 2); $price = round((float)($in['price'] ?? 0), 2);
            if ($name === '') json_response(['error' => 'Give the chain a name.'], 400);
            if ($price <= 0) $price = $mrp;      // the price is only a note now: at the counter the chain gets its share of the bill
            if ($mrp <= 0) json_response(['error' => 'Type the MRP of the chain (the bill gives the chain 50% of its total, and never more than the MRP).'], 400);
            $photo = (string)($in['photo'] ?? '');
            if ($photo !== '' && (strpos($photo, 'data:image/') !== 0 || strlen($photo) > 400000)) json_response(['error' => 'The photo is not valid or too big. Choose it again.'], 400);
            if ($id) {
                $ex = $pdo->prepare("SELECT COUNT(*) FROM st_chains WHERE chain_id = ?"); $ex->execute([$id]); if (!$ex->fetchColumn()) json_response(['error' => 'Chain not found.'], 404);
                $pdo->prepare("UPDATE st_chains SET name = ?, mrp = ?, price = ?" . ($photo !== '' ? ", photo = ?" : "") . " WHERE chain_id = ?")->execute(array_merge([$name, $mrp, $price], $photo !== '' ? [$photo] : [], [$id]));
            } else {
                $pdo->prepare("INSERT INTO st_chains (name, mrp, price, photo, active, created_at, created_by) VALUES (?,?,?,?,1,?,?)")->execute([$name, $mrp, $price, $photo, date('Y-m-d H:i:s'), (string)($auth['username'] ?? '')]);
                $id = (int)$pdo->lastInsertId();
            }
            json_response(['ok' => true, 'chain_id' => $id, 'message' => 'Chain saved.']);
            break;
        }

        case 'st_chain.remove': {          // a chain with no pieces made is deleted; otherwise it is only hidden (old bills keep their chain)
            require_auth(); $id = (int)(json_input()['chain_id'] ?? 0);
            $n = $pdo->prepare("SELECT COUNT(*) FROM st_chain_pieces WHERE chain_id = ? AND status IN ('generated','stock')"); $n->execute([$id]);
            if ((int)$n->fetchColumn()) json_response(['error' => 'This chain still has pieces in stock or waiting to be scanned. Remove those first.'], 409);
            $pdo->prepare("UPDATE st_chains SET active = 0 WHERE chain_id = ?")->execute([$id]);
            json_response(['ok' => true, 'message' => 'Chain removed from the list.']);
            break;
        }

        case 'st_chain.make': {          // one NEW barcode per piece
            $auth = require_auth(); $in = json_input(); $id = (int)($in['chain_id'] ?? 0); $qty = (int)($in['qty'] ?? 0);
            if ($qty < 1 || $qty > 200) json_response(['error' => 'Type how many labels to make (1 to 200).'], 400);
            $c = $pdo->prepare("SELECT * FROM st_chains WHERE chain_id = ? AND active = 1"); $c->execute([$id]); $ch = $c->fetch();
            if (!$ch) json_response(['error' => 'Chain not found.'], 404);
            $batch = 'C' . date('ymd-His'); $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO st_chain_pieces (chain_id, code, status, batch, created_at) VALUES (?, ?, 'generated', ?, ?)");
            for ($i = 0; $i < $qty; $i++) $ins->execute([$id, 'c' . bin2hex(random_bytes(5)), $batch, $now]);
            $pdo->prepare("UPDATE st_chain_pieces SET code = CAST(piece_id + " . ST_CHAIN_CODE_BASE . " AS CHAR) WHERE batch = ? AND code LIKE 'c%'")->execute([$batch]);
            $pdo->commit();
            $q = $pdo->prepare("SELECT piece_id, code, status, print_count FROM st_chain_pieces WHERE batch = ? ORDER BY piece_id"); $q->execute([$batch]);
            json_response(['ok' => true, 'batch' => $batch, 'chain' => ['chain_id' => (int)$ch['chain_id'], 'name' => $ch['name'], 'price' => (float)$ch['price'], 'mrp' => (float)$ch['mrp']], 'pieces' => $q->fetchAll(), 'message' => $qty . ' chain label' . ($qty === 1 ? '' : 's') . ' made. Print them and stick one on each chain.']);
            break;
        }

        case 'st_chain.pieces': {          // the labels of one chain that are not in stock yet (to print or print again)
            require_auth(); $id = (int)($_GET['chain_id'] ?? 0);
            $c = $pdo->prepare("SELECT chain_id, name, price, mrp FROM st_chains WHERE chain_id = ?"); $c->execute([$id]); $ch = $c->fetch();
            if (!$ch) json_response(['error' => 'Chain not found.'], 404);
            $q = $pdo->prepare("SELECT piece_id, code, status, print_count FROM st_chain_pieces WHERE chain_id = ? AND status = 'generated' ORDER BY piece_id"); $q->execute([$id]);
            json_response(['chain' => ['chain_id' => (int)$ch['chain_id'], 'name' => $ch['name'], 'price' => (float)$ch['price'], 'mrp' => (float)$ch['mrp']], 'pieces' => $q->fetchAll()]);
            break;
        }

        case 'st_chain.mark_printed': {
            require_auth();
            $codes = array_values(array_unique(array_filter(array_map('st_clean_code', (array)(json_input()['codes'] ?? [])), 'strlen')));
            if (!$codes) json_response(['ok' => true, 'marked' => 0]);
            $u = $pdo->prepare("UPDATE st_chain_pieces SET printed_at = COALESCE(printed_at, ?), print_count = print_count + 1 WHERE status <> 'void' AND code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")");
            $u->execute(array_merge([date('Y-m-d H:i:s')], $codes));
            json_response(['ok' => true, 'marked' => $u->rowCount()]);
            break;
        }

        // ---- labels: printed (and printed again) are only marked, never removed: a batch can always be opened and re-printed
        case 'st_inv.mark_printed': {
            require_auth();
            $codes = array_values(array_unique(array_filter(array_map('st_clean_code', (array)(json_input()['codes'] ?? [])), 'strlen')));
            if (!$codes) json_response(['ok' => true, 'marked' => 0]);
            $in = implode(',', array_fill(0, count($codes), '?'));
            $u = $pdo->prepare("UPDATE st_bottles SET printed_at = COALESCE(printed_at, ?), print_count = print_count + 1 WHERE code IN ($in) AND status <> 'void'");
            $u->execute(array_merge([date('Y-m-d H:i:s')], $codes));
            json_response(['ok' => true, 'marked' => $u->rowCount()]);
            break;
        }

        // ---- ORDER LIST: kept on the server, every change noted, follows an order cycle (default every 2 days)
        case 'st_inv.order_get': {
            $auth = require_auth(); $who = (string)($auth['username'] ?? '');
            $draftId = st_draft_id($pdo, $who);
            $sug = []; foreach (st_suggest_calc($pdo, (int)($_GET['days'] ?? 14))['items'] as $x) $sug[$x['product_id'] . '|' . $x['ml']] = $x['qty'];
            $st = $pdo->prepare("SELECT i.product_id, i.ml, i.qty, i.sug_qty, i.source, p.title FROM st_order_items i JOIN st_products p ON p.product_id = i.product_id WHERE i.order_id = ? ORDER BY p.title, i.ml");
            $st->execute([$draftId]);
            $items = []; $tb = 0; $tm = 0;
            foreach ($st->fetchAll() as $r) {
                $k = (int)$r['product_id'] . '|' . (int)$r['ml'];
                $items[] = ['product_id' => (int)$r['product_id'], 'title' => $r['title'], 'ml' => (int)$r['ml'], 'qty' => (int)$r['qty'], 'source' => $r['source'], 'sug_qty' => $r['sug_qty'] === null ? null : (int)$r['sug_qty'], 'sug_now' => $sug[$k] ?? 0];
                $tb += (int)$r['qty']; $tm += (int)$r['qty'] * (int)$r['ml'];
            }
            $hist = [];
            foreach ($pdo->query("SELECT o.order_id, o.status, o.ordered_at, o.ordered_by, COALESCE(SUM(i.qty), 0) bottles, COALESCE(SUM(i.qty * i.ml), 0) ml FROM st_orders o LEFT JOIN st_order_items i ON i.order_id = o.order_id WHERE o.status IN ('ordered', 'done', 'cancelled') GROUP BY o.order_id ORDER BY o.order_id DESC LIMIT 8")->fetchAll() as $r) $hist[] = ['order_id' => (int)$r['order_id'], 'status' => $r['status'], 'ordered_at' => $r['ordered_at'], 'ordered_by' => $r['ordered_by'], 'bottles' => (int)$r['bottles'], 'ml' => (int)$r['ml']];
            $log = [];
            foreach ($pdo->query("SELECT l.at, l.who, l.action, l.ml, l.from_qty, l.to_qty, p.title FROM st_order_log l LEFT JOIN st_products p ON p.product_id = l.product_id ORDER BY l.log_id DESC LIMIT 25")->fetchAll() as $r) $log[] = ['at' => $r['at'], 'who' => $r['who'], 'action' => $r['action'], 'title' => $r['title'], 'ml' => $r['ml'] === null ? null : (int)$r['ml'], 'from' => $r['from_qty'] === null ? null : (int)$r['from_qty'], 'to' => $r['to_qty'] === null ? null : (int)$r['to_qty']];
            json_response(['draft' => ['order_id' => $draftId, 'items' => $items, 'total_bottles' => $tb, 'total_ml' => $tm], 'open' => array_values(st_open_orders($pdo)), 'history' => $hist, 'log' => $log, 'cycle' => st_order_cycle($pdo)]);
            break;
        }

        case 'st_inv.order_set': {
            $auth = require_auth(); $who = (string)($auth['username'] ?? ''); $in = json_input();
            $pid = (int)($in['product_id'] ?? 0); $ml = (int)($in['ml'] ?? 0); $qty = max(0, min(500, (int)($in['qty'] ?? 0)));
            if (!$pid || !in_array($ml, ST_SIZES, true)) json_response(['error' => 'Pick a perfume and one of the sizes ' . implode(', ', ST_SIZES) . ' ml.'], 400);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM st_products WHERE product_id = ? AND active = 1"); $chk->execute([$pid]);
            if (!$chk->fetchColumn()) json_response(['error' => 'That perfume is not on the list (press Sync from Shopify).'], 400);
            $draftId = st_draft_id($pdo, $who);
            $ex = $pdo->prepare("SELECT qty FROM st_order_items WHERE order_id = ? AND product_id = ? AND ml = ?"); $ex->execute([$draftId, $pid, $ml]); $old = $ex->fetchColumn();
            $src = ($in['source'] ?? '') === 'suggest' ? 'suggest' : 'manual';
            if ($qty === 0) {
                if ($old !== false) { $pdo->prepare("DELETE FROM st_order_items WHERE order_id = ? AND product_id = ? AND ml = ?")->execute([$draftId, $pid, $ml]); st_order_log($pdo, $draftId, $who, 'remove', $pid, $ml, (int)$old, 0); }
            } elseif ($old === false) {
                $pdo->prepare("INSERT INTO st_order_items (order_id, product_id, ml, qty, sug_qty, source, added_at) VALUES (?,?,?,?,?,?,?)")->execute([$draftId, $pid, $ml, $qty, isset($in['sug_qty']) ? (int)$in['sug_qty'] : null, $src, date('Y-m-d H:i:s')]);
                st_order_log($pdo, $draftId, $who, 'add', $pid, $ml, 0, $qty);
            } elseif ((int)$old !== $qty) {
                $pdo->prepare("UPDATE st_order_items SET qty = ?" . (isset($in['sug_qty']) ? ", sug_qty = " . (int)$in['sug_qty'] : "") . " WHERE order_id = ? AND product_id = ? AND ml = ?")->execute([$qty, $draftId, $pid, $ml]);
                st_order_log($pdo, $draftId, $who, 'qty', $pid, $ml, (int)$old, $qty);
            }
            json_response(['ok' => true]);
            break;
        }

        case 'st_inv.order_place': {
            $auth = require_auth(); $who = (string)($auth['username'] ?? '');
            $draftId = st_draft_id($pdo, $who);
            $n = $pdo->prepare("SELECT COUNT(*) FROM st_order_items WHERE order_id = ?"); $n->execute([$draftId]);
            if (!(int)$n->fetchColumn()) json_response(['error' => 'The order list is empty. Add something first.'], 400);
            $pdo->prepare("UPDATE st_orders SET status = 'ordered', ordered_at = ?, ordered_by = ? WHERE order_id = ? AND status = 'draft'")->execute([date('Y-m-d H:i:s'), mb_substr($who, 0, 80), $draftId]);
            st_order_log($pdo, $draftId, $who, 'ordered');
            json_response(['ok' => true, 'message' => 'Order placed. It counts as "on order" until the barcodes for it are made, so it is not suggested twice.']);
            break;
        }

        case 'st_inv.order_cancel': {
            $auth = require_auth(); $who = (string)($auth['username'] ?? '');
            $id = (int)(json_input()['order_id'] ?? 0);
            $u = $pdo->prepare("UPDATE st_orders SET status = 'cancelled' WHERE order_id = ? AND status = 'ordered'"); $u->execute([$id]);
            if (!$u->rowCount()) json_response(['error' => 'That order is not open.'], 409);
            st_order_log($pdo, $id, $who, 'cancelled');
            json_response(['ok' => true]);
            break;
        }

        case 'st_inv.order_create': {          // the planner: the WHOLE order in ONE request (own new order, the draft list is not touched); pending items of earlier orders can be moved into it
            $auth = require_auth(); $who = (string)($auth['username'] ?? ''); $in = json_input(); $lines = [];
            foreach ((array)($in['lines'] ?? []) as $l) {
                $pid = (int)($l['product_id'] ?? 0); $ml = (int)($l['ml'] ?? 0); $q = (int)($l['qty'] ?? 0);
                if ($pid > 0 && $q > 0 && in_array($ml, ST_SIZES, true)) { $k = $pid . '|' . $ml; if (!isset($lines[$k])) $lines[$k] = ['pid' => $pid, 'ml' => $ml, 'qty' => 0, 'src' => ($l['manual'] ?? false) ? 'manual' : 'suggest']; $lines[$k]['qty'] = min(100000, $lines[$k]['qty'] + $q); }
            }
            if (!$lines) json_response(['error' => 'The order is empty. Add at least one perfume.'], 400);
            if (count($lines) > 600) json_response(['error' => 'Too many lines in one order (' . count($lines) . '). Keep it under 600.'], 400);
            $ids = array_values(array_unique(array_map(fn($x) => $x['pid'], $lines)));
            $chk = $pdo->prepare("SELECT COUNT(*) FROM st_products WHERE active = 1 AND product_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $chk->execute($ids);
            if ((int)$chk->fetchColumn() !== count($ids)) json_response(['error' => 'One of the perfumes is not on the list any more (press Sync from Shopify).'], 400);
            $now = date('Y-m-d H:i:s'); $closed = 0; $bottles = 0;
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO st_orders (status, created_at, created_by, ordered_at, ordered_by) VALUES ('ordered', ?, ?, ?, ?)")->execute([$now, mb_substr($who, 0, 80), $now, mb_substr($who, 0, 80)]);
                $oid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare("INSERT INTO st_order_items (order_id, product_id, ml, qty, sug_qty, source, added_at) VALUES (?,?,?,?,NULL,?,?)");
                foreach ($lines as $l) { $ins->execute([$oid, $l['pid'], $l['ml'], $l['qty'], $l['src'], $now]); $bottles += $l['qty']; }
                st_order_log($pdo, $oid, $who, 'ordered');
                foreach ((array)($in['carry_order_ids'] ?? []) as $cid) {
                    $u = $pdo->prepare("UPDATE st_orders SET status = 'cancelled' WHERE order_id = ? AND status = 'ordered' AND order_id <> ?"); $u->execute([(int)$cid, $oid]);
                    if ($u->rowCount()) { st_order_log($pdo, (int)$cid, $who, 'carried'); $closed++; }
                }
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('st_inv.order_create: ' . $e->getMessage()); json_response(['error' => 'The order could not be saved. Nothing was placed. Try again.'], 500); }
            json_response(['ok' => true, 'order_id' => $oid, 'lines' => count($lines), 'bottles' => $bottles, 'closed' => $closed, 'ordered_by' => $who, 'ordered_at' => $now,
                'message' => 'Order #' . $oid . ' placed: ' . $bottles . ' bottles, ' . count($lines) . ' lines.' . ($closed ? ' Pending items of ' . $closed . ' earlier order(s) moved into it.' : '')]);
            break;
        }

        case 'st_inv.order_carry': {          // the pending (not yet arrived) lines of these open orders were moved into a NEW order: the old orders are closed
            $auth = require_auth(); $who = (string)($auth['username'] ?? ''); $n = 0;
            foreach ((array)(json_input()['order_ids'] ?? []) as $id) {
                $u = $pdo->prepare("UPDATE st_orders SET status = 'cancelled' WHERE order_id = ? AND status = 'ordered'"); $u->execute([(int)$id]);
                if ($u->rowCount()) { st_order_log($pdo, (int)$id, $who, 'carried'); $n++; }
            }
            json_response(['ok' => true, 'closed' => $n]);
            break;
        }

        case 'st_inv.order_cycle': {
            require_auth();
            setting_set($pdo, 'st_order_cycle', max(1, min(30, (int)(json_input()['days'] ?? 2))));
            json_response(['ok' => true, 'cycle' => st_order_cycle($pdo)]);
            break;
        }

        case 'st_inv.audit': {
            require_auth();
            $codes = array_values(array_unique(array_filter(array_map('st_clean_code', (array)(json_input()['codes'] ?? [])), 'strlen')));
            $found = st_find_bottles($pdo, $codes);
            $ok = []; $bad = [];
            foreach ($codes as $c) {
                $b = $found[$c] ?? null;
                if ($b && $b['status'] === 'stock') $ok[] = $c;
                else $bad[] = ['code' => $c, 'why' => !$b ? 'not our barcode' : ('already ' . $b['status'])];
            }
            $missing = []; $okSet = array_flip($ok);
            foreach ($pdo->query("SELECT b.code, b.ml, b.kind, p.title FROM st_bottles b JOIN st_products p ON p.product_id = b.product_id WHERE b.status = 'stock' ORDER BY p.title, b.ml")->fetchAll() as $r) {
                if (!isset($okSet[$r['code']])) $missing[] = ['code' => $r['code'], 'title' => $r['title'], 'ml' => (int)$r['ml'], 'kind' => $r['kind']];
            }
            json_response(['scanned' => count($codes), 'ok' => count($ok), 'not_valid' => $bad, 'missing' => $missing]);
            break;
        }

        case 'st_inv.void': {
            require_auth();
            $code = st_clean_code(json_input()['code'] ?? '');
            $b = st_find_bottles($pdo, [$code])[$code] ?? null;
            if (!$b) json_response(['error' => 'Code ' . $code . ' not found.'], 404);
            if ($b['status'] === 'sold') json_response(['error' => 'This bottle is already sold.'], 409);
            $pdo->prepare("UPDATE st_bottles SET status = 'void' WHERE bottle_id = ?")->execute([$b['bottle_id']]);
            json_response(['ok' => true, 'message' => $code . ' removed from stock.']);
            break;
        }

        // ====================================================== billing
        case 'st_bill.scan': {
            // ONE call per scan: checks the new bottle AND returns the new total ml + prices for everything scanned so far.
            require_auth();
            $in = json_input();
            $code = st_clean_code($in['code'] ?? '');
            $have = array_values(array_filter(array_map('st_clean_code', (array)($in['codes'] ?? [])), 'strlen'));
            $found = st_find_bottles($pdo, array_merge($have, [$code]));
            $b = $found[$code] ?? null;
            if (!$b) {      // not a bottle: is it a CHAIN label? (8 digits). The chain is added by itself; the customer does not choose on screen.
                $cq = $pdo->prepare("SELECT p.piece_id, p.code, p.status, c.chain_id, c.name, c.mrp, c.price, c.photo FROM st_chain_pieces p JOIN st_chains c ON c.chain_id = p.chain_id WHERE p.code = ?"); $cq->execute([$code]); $cp = $cq->fetch();
                if ($cp) {
                    if ($cp['status'] !== 'stock') json_response(['error' => ($cp['status'] === 'sold' ? 'Already SOLD' : ($cp['status'] === 'generated' ? 'Not in stock yet — scan it into inventory first' : 'Not available (' . $cp['status'] . ')')) . ': chain ' . $cp['name']], 409);
                    $gate = st_chain_gate($pdo); if ($gate !== '') json_response(['error' => $gate], 409);
                    json_response(['chain' => ['piece_id' => (int)$cp['piece_id'], 'chain_id' => (int)$cp['chain_id'], 'code' => (string)$cp['code'], 'name' => (string)$cp['name'], 'mrp' => (float)$cp['mrp'], 'price' => (float)$cp['price'], 'photo' => (string)$cp['photo'], 'split_pct' => st_chain_pct($pdo)]]);
                }
                json_response(['error' => 'Code ' . $code . ' is not one of our barcodes.'], 404);
            }
            if ($b['status'] !== 'stock') json_response(['error' => ($b['status'] === 'sold' ? 'Already SOLD' : ($b['status'] === 'generated' ? 'Not in stock yet — scan it into inventory first' : 'Not available (' . $b['status'] . ')')) . ': ' . $b['title'] . ' ' . $b['ml'] . ' ml'], 409);
            $t = st_totals($found);
            json_response(['bottle' => st_bottle_out($b), 'quote' => array_merge($t, st_quote($pdo, $t['total_ml'], (int)($in['emp_id'] ?? 0)))]);
            break;
        }

        case 'st_bill.quote': {
            require_auth();
            $in = json_input();
            $found = st_find_bottles($pdo, (array)($in['codes'] ?? []));
            $giftWant = array_values(array_unique(array_filter(array_map('st_clean_code', (array)($in['gift_codes'] ?? [])), 'strlen')));
            foreach ($giftWant as $gc) { if (isset($found[$gc])) $found[$gc]['kind'] = 'gift'; }
            $t = st_totals($found);
            json_response(array_merge($t, st_quote($pdo, $t['total_ml'], (int)($in['emp_id'] ?? 0))));
            break;
        }

        case 'st_bill.create': {
            $auth = require_auth();
            $in = json_input();
            $empId = (int)($in['emp_id'] ?? 0);
            $en = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND status != 'ex'");
            $en->execute([$empId]);
            $empName = (string)$en->fetchColumn();
            if (!$empId || $empName === '') json_response(['error' => 'Choose who is billing ("Billed by"). The sale and commission go to that person.'], 400);
            if ((int)setting_get($pdo, 'st_bill_needs_punch', 0) && empty(st_punch_map($pdo, [$empId])[$empId])) json_response(['error' => $empName . ' has not punched in today. Punch in first (Attendance), or ask a manager to switch off "only punched-in people can bill" in Store > Team.'], 400);
            $name = trim((string)($in['customer_name'] ?? '')); $phone = preg_replace('/\D/', '', (string)($in['customer_phone'] ?? ''));
            if ($phone !== '' && strlen($phone) === 10) $phone = '91' . $phone;
            // CUSTOMERS on this bill: one row per person who came (name + mobile), each gets their own bill page, WhatsApp message and follow-up.
            $cs = st_cs_get($pdo); $people = [];
            foreach ((array)($in['people'] ?? []) as $x) {
                if (count($people) >= 8) break; if (!is_array($x)) continue;
                $people[] = ['name' => mb_substr(trim((string)($x['name'] ?? '')), 0, 120), 'phone' => st_phone_clean($x['phone'] ?? ''), 'raw' => trim((string)($x['phone'] ?? '')), 'declined' => empty($x['declined']) ? 0 : 1, 'reason' => mb_substr(trim((string)($x['reason'] ?? '')), 0, 60), 'bday' => (preg_match('/^(\d\d)-(\d\d)$/', (string)($x['bday'] ?? ''), $bm) && checkdate((int)$bm[1], (int)$bm[2], 2000)) ? $bm[0] : ''];
            }
            if (!$people && ($name !== '' || $phone !== '')) $people[] = ['name' => $name, 'phone' => st_phone_clean($phone), 'raw' => $phone, 'declined' => 0, 'reason' => '', 'bday' => ''];      // an older screen sends one customer the old way
            $seen = [];
            foreach ($people as $i => $pp) {
                if ($pp['declined']) continue;
                if ($pp['phone'] === '') { if ($cs['require_phone'] || $pp['raw'] !== '') json_response(['error' => 'Customer ' . ($i + 1) . ($pp['name'] !== '' ? ' (' . $pp['name'] . ')' : '') . ' needs a valid 10-digit mobile number, or tick "declined".'], 400); continue; }
                if (isset($seen[$pp['phone']])) json_response(['error' => 'The number ' . substr($pp['phone'], -10) . ' is entered twice on this bill. Each customer needs their own number.'], 400);
                $seen[$pp['phone']] = 1;
            }
            if (!$people && $cs['require_phone']) json_response(['error' => 'Add the customer: name and mobile number (each person who came with this bill).'], 400);
            foreach ($people as $i => $pp) { $lk = ($pp['declined'] || $pp['phone'] === '') ? null : st_customer_lookup($pdo, $pp['phone']); $people[$i]['returning'] = $lk && $lk['returning'] ? 1 : 0; $people[$i]['visit_no'] = $lk ? $lk['visits'] + 1 : 1; }
            foreach ($people as $pp) if (!$pp['declined'] && $pp['phone'] !== '') { if ($pp['name'] !== '') $name = $pp['name']; $phone = $pp['phone']; break; }      // the first customer with a number is "the" customer of the bill
            $code = strtoupper(trim((string)($in['order_code'] ?? '')));
            $discount = max(0.0, (float)($in['discount'] ?? 0));
            $payMode = in_array(($in['pay_mode'] ?? ''), ['cash', 'online', 'split'], true) ? $in['pay_mode'] : 'cash';

            $chainCode = st_clean_code($in['chain_code'] ?? '');
            if ($chainCode !== '') { $gate = st_chain_gate($pdo); if ($gate !== '') json_response(['error' => $gate], 409); }      // never sell a chain we cannot give a GST invoice for
            $pdo->beginTransaction();
            $found = st_find_bottles($pdo, (array)($in['codes'] ?? []), true);
            $want = array_values(array_unique(array_filter(array_map('st_clean_code', (array)($in['codes'] ?? [])), 'strlen')));
            if (!$want) { $pdo->rollBack(); json_response(['error' => 'Scan at least one bottle.'], 400); }
            foreach ($want as $c) {
                $b = $found[$c] ?? null;
                if (!$b) { $pdo->rollBack(); json_response(['error' => 'Code ' . $c . ' is not one of our barcodes.'], 400); }
                if ($b['status'] !== 'stock') { $pdo->rollBack(); json_response(['error' => 'Code ' . $c . ' (' . $b['title'] . ' ' . $b['ml'] . ' ml) is ' . $b['status'] . ', not in stock.'], 409); }
            }
            $giftWant = array_values(array_unique(array_filter(array_map('st_clean_code', (array)($in['gift_codes'] ?? [])), 'strlen')));
            foreach ($giftWant as $gc) { if (isset($found[$gc])) $found[$gc]['kind'] = 'gift'; }
            $giftFor = [];
            if ($giftWant) {      // BIRTHDAY GIFT: one free 50 ml for a customer ON their birthday, once a year per mobile number
                $todayMD = date('m-d', strtotime(st_day())); $isMgr = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage');
                foreach ($giftWant as $gc) { $gb = $found[$gc] ?? null; if (!$gb || (int)$gb['ml'] !== 50 || ($gb['status'] ?? '') !== 'stock') { $pdo->rollBack(); json_response(['error' => 'A birthday gift must be a 50 ml bottle that is in stock.'], 400); } }
                $bdCode = trim((string)($in['bday_code'] ?? ''));
                if ($bdCode !== '') {      // the customer showed the birthday code from WhatsApp: one gift, whoever brought it
                    $chk = st_bday_check($pdo, $bdCode, st_day()); if (empty($chk['ok'])) { $pdo->rollBack(); json_response(['error' => $chk['error']], 400); }
                    if (count($giftWant) !== 1) { $pdo->rollBack(); json_response(['error' => 'One birthday code gives one free 50 ml.'], 400); }
                    $giftFor[] = ['phone' => (string)$chk['voucher']['phone'], 'name' => (string)$chk['voucher']['name'], 'voucher_id' => (int)$chk['voucher']['voucher_id']];
                } else
                foreach ($people as $pp) {
                    if (count($giftFor) >= count($giftWant)) break;
                    if ($pp['declined'] || $pp['phone'] === '' || ($pp['bday'] ?? '') !== $todayMD) continue;
                    if (st_gift_given($pdo, $pp['phone'], (int)date('Y'))) continue;
                    $lk = st_customer_lookup($pdo, $pp['phone']);
                    if (($lk['bday'] ?? '') !== $todayMD && !$isMgr) continue;      // a birthday first told today needs a manager to approve the gift
                    $giftFor[] = $pp;
                }
                if (count($giftFor) < count($giftWant)) { $pdo->rollBack(); json_response(['error' => 'No customer on this bill is eligible for a birthday gift (birthday must be today, saved earlier, and not gifted already this year).'], 400); }
            }
            $t = st_totals($found);
            if ($t['sale_bottles'] < 1) { $pdo->rollBack(); json_response(['error' => 'A bill needs at least one bottle for sale (testers alone cannot be billed).'], 400); }

            // PRICE: from the chart by TOTAL ml. The cashier picks a chart column; a manager may type a price instead.
            $q = st_quote($pdo, $t['total_ml'], $empId);
            $col = isset($in['price_col']) && $in['price_col'] !== '' && $in['price_col'] !== null ? (int)$in['price_col'] : null;
            $list = 0.0;
            if ($col !== null && isset($q['columns'][$col])) $list = (float)$q['columns'][$col]['price'];
            elseif ((float)($in['custom_price'] ?? 0) > 0) {
                if (!user_can($auth, 'rs_manage')) { $pdo->rollBack(); json_response(['error' => 'Pick a price from the chart. Only a manager can type a different price.'], 403); }
                $list = round((float)$in['custom_price'], 2); $col = null;
            } else { $pdo->rollBack(); json_response(['error' => $q['in_chart'] ? 'Pick a price column.' : ('The price chart has no row for ' . $t['total_ml'] . ' ml — a manager must type the price.')], 400); }
            if ($discount > $list) $discount = $list;
            $total = round($list - $discount, 2);
            $chainRow = null; $chainSplit = null;
            if ($chainCode !== '') {      // a chain on the bill: it is billed at its own price, the perfumes get the rest of the SAME total
                $cq = $pdo->prepare("SELECT p.piece_id, p.status, c.chain_id, c.name, c.price, c.mrp FROM st_chain_pieces p JOIN st_chains c ON c.chain_id = p.chain_id WHERE p.code = ? FOR UPDATE"); $cq->execute([$chainCode]); $chainRow = $cq->fetch();
                if (!$chainRow) { $pdo->rollBack(); json_response(['error' => 'Chain code ' . $chainCode . ' is not one of our barcodes.'], 400); }
                if ($chainRow['status'] !== 'stock') { $pdo->rollBack(); json_response(['error' => 'Chain ' . $chainRow['name'] . ' (' . $chainCode . ') is ' . $chainRow['status'] . ', not in stock.'], 409); }
                $saleBots = array_values(array_filter($found, fn($b) => $b['kind'] === 'sale'));
                $chainSplit = st_chain_split($total, st_chain_share($total, st_chain_pct($pdo)), $saleBots, (float)$chainRow['mrp']);      // 50% of the bill is the chain, the rest the perfumes
                if (isset($chainSplit['error'])) { $pdo->rollBack(); json_response(['error' => $chainSplit['error']], 400); }
            }

            $cash = max(0.0, (float)($in['cash_amt'] ?? 0)); $online = max(0.0, (float)($in['online_amt'] ?? 0));
            if ($payMode === 'cash') { $cash = $total; $online = 0.0; }
            elseif ($payMode === 'online') { $online = $total; $cash = 0.0; }
            elseif (abs($cash + $online - $total) > 0.5) { $pdo->rollBack(); json_response(['error' => 'Cash + online (' . ($cash + $online) . ') must equal the total (' . $total . ').'], 400); }

            // a sale made EARLIER TODAY can be billed now with its real time ("Sold at"); empty = now. Never in the future, never more than 20 hours back.
            $ts = time(); $stime = trim((string)($in['sold_time'] ?? ''));
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $stime, $mm)) { $tt = strtotime(date('Y-m-d') . ' ' . sprintf('%02d:%02d', $mm[1], $mm[2]) . ':00'); if ($tt > time()) $tt -= 86400; if (time() - $tt <= 20 * 3600) $ts = $tt; }
            $now = date('Y-m-d H:i:s', $ts);
            // the day this sale counts for: normally the store day of the moment; the cashier may choose the day before (the store was still open after the day turned over)
            $bizDay = st_day($ts); $want = (string)($in['biz_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $want) && strtotime($want) !== false && $want <= $bizDay) {      // never a day that has not come; yesterday for everybody; older (45 days back) for a manager
                $isMgr = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage');
                if ($want === date('Y-m-d', strtotime($bizDay . ' -1 day')) || ($isMgr && $want >= date('Y-m-d', strtotime($bizDay . ' -45 day')))) $bizDay = $want;
            }
            if ($want !== '' && $want !== st_day($ts) && $bizDay !== $want) { $pdo->rollBack(); json_response(['error' => 'You cannot count this bill on ' . $want . '. A cashier can pick today or yesterday; a manager up to 45 days back.'], 400); }
            $pdo->prepare("INSERT INTO st_bills (biz_date, created_at, emp_id, emp_name, customer_name, customer_phone, total_ml, bottles, testers, price_col, list_price, discount, total, order_code, pay_mode, cash_amt, online_amt, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ok', ?)")
                ->execute([$bizDay, $now, $empId, $empName, mb_substr($name, 0, 120), mb_substr($phone, 0, 20), $t['total_ml'], $t['sale_bottles'], $t['testers'], $col, $list, $discount, $total, mb_substr($code, 0, 20), $payMode, $cash, $online, (int)($auth['employee_id'] ?? 0) ?: null]);
            $billId = (int)$pdo->lastInsertId();
            $billNo = 'ST-' . str_pad((string)$billId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE st_bills SET bill_no = ? WHERE bill_id = ?")->execute([$billNo, $billId]);
            $srcKey = (string)($in['source'] ?? ''); if (!isset(ST_SOURCES[$srcKey])) $srcKey = '';      // where the customer came from (optional)
            $areaIn = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['area'] ?? ''))), 0, 80);      // which area the customer is from (optional)
            if ($areaIn !== '') $pdo->prepare("UPDATE st_bills SET area = ? WHERE bill_id = ?")->execute([$areaIn, $billId]);
            // a SECOND salesperson who also helped with this customer (optional): only shown / tracked, the sale and commission stay with "Billed by"
            $helperId = (int)($in['helper_emp_id'] ?? 0);
            if ($helperId && $helperId !== $empId) {
                $hn = $pdo->prepare("SELECT full_name FROM employees WHERE employee_id = ? AND status != 'ex'"); $hn->execute([$helperId]); $helperName = (string)$hn->fetchColumn();
                if ($helperName !== '') $pdo->prepare("UPDATE st_bills SET helper_emp_id = ?, helper_emp_name = ? WHERE bill_id = ?")->execute([$helperId, mb_substr($helperName, 0, 120), $billId]);
            }
            if ($srcKey !== '') $pdo->prepare("UPDATE st_bills SET source = ?, source_note = ? WHERE bill_id = ?")->execute([$srcKey, mb_substr(trim((string)($in['source_note'] ?? '')), 0, 120), $billId]);
            $item = $pdo->prepare("INSERT INTO st_bill_items (bill_id, bottle_id, ml, kind) VALUES (?, ?, ?, ?)");
            $sold = $pdo->prepare("UPDATE st_bottles SET status = 'sold', sold_at = ?, bill_id = ? WHERE bottle_id = ? AND status = 'stock'");
            foreach ($found as $b) {
                $item->execute([$billId, $b['bottle_id'], $b['ml'], $b['kind']]);
                $sold->execute([$now, $billId, $b['bottle_id']]);
                if ($sold->rowCount() !== 1) { $pdo->rollBack(); json_response(['error' => 'Code ' . $b['code'] . ' was just sold on another bill.'], 409); }
            }
            if ($chainRow) {
                $pdo->prepare("UPDATE st_bills SET chain_amt = ?, chain_name = ? WHERE bill_id = ?")->execute([round($chainSplit['chain_p'] / 100, 2), mb_substr((string)$chainRow['name'], 0, 120), $billId]);
                $cs2 = $pdo->prepare("UPDATE st_chain_pieces SET status = 'sold', sold_at = ?, bill_id = ? WHERE piece_id = ? AND status = 'stock'"); $cs2->execute([$now, $billId, (int)$chainRow['piece_id']]);
                if ($cs2->rowCount() !== 1) { $pdo->rollBack(); json_response(['error' => 'The chain was just sold on another bill.'], 409); }
            }
            $ip = $pdo->prepare("INSERT INTO st_bill_people (bill_id, person_no, name, phone, token, declined, reason, returning_c, visit_no, created_at, bday) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($people as $i => $pp) $ip->execute([$billId, $i + 1, $pp['name'], $pp['phone'], bin2hex(random_bytes(10)), $pp['declined'], $pp['reason'], $pp['returning'], $pp['visit_no'], $now, $pp['bday'] ?? '']);
            $gi = 0;
            foreach ($giftFor as $gp) { if (!empty($gp['voucher_id'])) { $vu = $pdo->prepare("UPDATE st_bday_vouchers SET status = 'redeemed', redeemed_at = ?, redeemed_bill_id = ?, redeemed_by = ? WHERE voucher_id = ? AND status = 'active'"); $vu->execute([$now, $billId, $empName, $gp['voucher_id']]); if ($vu->rowCount() !== 1) { $pdo->rollBack(); json_response(['error' => 'This birthday code was just used on another bill.'], 409); } }
                $gb = $found[$giftWant[$gi++]]; $pdo->prepare("INSERT INTO st_bday_gifts (phone, name, gift_year, bill_id, bottle_id, given_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$gp['phone'], $gp['name'], (int)date('Y'), $billId, (int)$gb['bottle_id'], $empName, $now]); }
            if (array_filter(array_column($people, 'bday'))) register_shutdown_function(function () use ($pdo) { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); @ignore_user_abort(true); @set_time_limit(60); try { st_bday_ensure_template($pdo); if ((int)date('G') >= 8 && (int)date('G') < 21) st_bday_run($pdo, true); } catch (Throwable $e) { error_log('bday after bill: ' . $e->getMessage()); } });      // a birthday saved with this bill that is close gets its code and WhatsApp message right away
            $pdo->commit();
            $pc = st_pct_for($pdo, $t['total_ml'], $total, $empId, $code !== '');
            // The bill's commission by the SAME rules as online sales, incl. the order-code ("no code") rule: a bill without an order code pays the reduced share
            // unless enough of the day's bills have one. Shown to the biller right after saving. Falls back to the older chart estimate if the engine is not here.
            $cmx = null; $codeRule = null;
            try {
                if (function_exists('budget_engine_ready') && budget_engine_ready()) {
                    $rr = budget_store_calc($pdo, st_day(), st_day());
                    foreach ($rr['bills'] as $bb) if ((int)$bb['bill_id'] === (int)$billId) { $cmx = $bb; break; }
                    $chc = cm_chart($pdo); $codeRule = ['codepct' => (float)$chc['codepct'], 'nocode' => (float)$chc['nocode']];
                }
            } catch (Throwable $e) { error_log('st_bill.create commission: ' . $e->getMessage()); }
            if ($cmx && $cmx['pct'] !== null) { $pc = ['pct' => round((float)$cmx['pct'] * ($cmx['reduced'] ? $codeRule['nocode'] / 100 : 1), 2), 'source' => 'online rules' . ($cmx['reduced'] ? ' · no order code: reduced' : '')]; }
            $invOut = [];
            if ($chainRow) {      // the numbered GST invoice (same series as the online orders); if it fails the bill stays and the bill list offers "Make GST invoice"
                $iv = st_chain_invoice($pdo, $billId);
                $invOut = ['chain' => (string)$chainRow['name'], 'chain_amt' => round($chainSplit['chain_p'] / 100, 2), 'perfume_amt' => round($chainSplit['perfume_p'] / 100, 2), 'inv_ok' => $iv['ok'], 'inv_err' => $iv['error']];
                if ($iv['ok']) $invOut += ['inv_no' => (string)$iv['inv']['inv_no'], 'inv_url' => wch_view_url($iv['inv']['token']), 'inv_pdf' => wch_pdf_url($iv['inv']['token'])];
            }
            $autoWa = st_wa_auto($pdo, $billId, $billNo, (float)$total, $cs);      // the bill goes to the customer's WhatsApp by itself (bill link + community + review); a failure only leaves the manual button
            $ppl = st_people_status($pdo, [$billId])[$billId] ?? [];
            foreach ($ppl as &$ax) { $ax['auto'] = $autoWa[$ax['person_id']] ?? ''; } unset($ax);
            foreach ($ppl as &$x) { if ($x['phone'] !== '') { $tok = substr($x['link'], strrpos($x['link'], '=') + 1); $m = st_wa_message(['name' => $x['name'], 'phone' => $x['phone'], 'token' => $tok, 'returning' => $x['returning']], ['bill_no' => $billNo, 'total' => $total], $cs); $x['wa_url'] = $m['url']; $x['wa_url2'] = $m['url2']; } }
            unset($x);
            json_response(['ok' => true, 'bill_id' => $billId, 'bill_no' => $billNo, 'total' => $total, 'total_ml' => $t['total_ml'], 'commission_pct' => $pc['pct'], 'commission_from' => $pc['source'], 'commission_amount' => $cmx ? (float)$cmx['com'] : null, 'no_code' => $code === '', 'code_rule' => $codeRule, 'people' => $ppl] + $invOut + [
                'message' => 'Bill ' . $billNo . ' saved: ' . $t['total_ml'] . ' ml, ₹' . number_format($total) . '.']);
            break;
        }

        case 'st_bday.check': {           // the cashier typed the birthday code: whose is it, is it good today?
            require_auth();
            $in = json_input(); $chk = st_bday_check($pdo, (string)($in['code'] ?? ($_GET['code'] ?? '')), st_day());
            json_response($chk['ok'] ? ['ok' => true, 'voucher' => st_bday_public($chk['voucher'])] : ['ok' => false, 'error' => $chk['error']]);
            break;
        }

        case 'st_bday.list': {            // codes made / sent / used (newest first) + the settings and whether the WhatsApp template is approved
            $auth = require_auth();
            $cfg = st_bday_cfg($pdo);
            try { if (!is_file(__DIR__ . '/cust_bday.php') || (require_once __DIR__ . '/cust_bday.php') || true) st_bday_run($pdo, false); } catch (Throwable $e) { error_log('st_bday.list run: ' . $e->getMessage()); }   // today's codes exist before the cashier opens the list (nothing is sent here)
            $onl = []; if (function_exists('cbd_all')) foreach (cbd_all($pdo) as $cb) $onl[(string)$cb['phone']] = 1;
            $rows = array_map(function ($v) use ($onl) { $r = st_bday_public($v); $r['online'] = isset($onl[(string)$v['phone']]); return $r; }, $pdo->query("SELECT * FROM st_bday_vouchers ORDER BY (status = 'active') DESC, valid_to ASC, voucher_id DESC LIMIT 100")->fetchAll());
            $tpl = st_wa_status($pdo)['templates']['tmph_store_bday'] ?? ['status' => 'missing', 'reason' => ''];
            $kq = $pdo->prepare("SELECT COUNT(DISTINCT p.phone) FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE p.bday <> '' AND p.declined = 0 AND p.phone <> '' AND b.created_at >= ?"); $kq->execute([st_bday_start($pdo)]); $known = (int)$kq->fetchColumn();
            if (function_exists('cbd_all')) { $k2 = []; foreach (cbd_all($pdo) as $cb) $k2[(string)$cb['phone']] = 1; $known += count($k2); }   // + birthdays asked when an order was made
            json_response(['msg' => st_bday_msg($pdo), 'cfg' => $cfg, 'vouchers' => $rows, 'template' => $tpl, 'customers_with_birthday' => $known, 'can_manage' => in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage')]);
            break;
        }

        case 'st_bill.image_get': {       // the picture that goes with the bill message (the cashier copies it and pastes it in the WhatsApp chat). kind=back: the returning customer's own picture (falls back to the new customer's). The picture itself only when asked.
            require_auth(); $back = (($_GET['kind'] ?? '') === 'back'); $get = function (string $k) use ($pdo): string { try { $x = $pdo->query("SELECT svalue FROM st_cs_settings WHERE skey = '" . $k . "'")->fetchColumn(); $j = $x ? json_decode((string)$x, true) : null; return is_array($j) ? (string)($j['photo'] ?? '') : ''; } catch (Throwable $e) { return ''; } };
            $v = $back ? $get('bill_image_back') : $get('bill_image'); $custom = $v !== '';
            if (!$custom && $back) $v = $get('bill_image');       // no returning-customer picture: the new customer's picture is used
            if ($v === '') { $df = dirname(__DIR__) . '/assets/img/store_bill_default.jpg'; if (is_file($df)) $v = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents($df)); }   // no picture uploaded: the default picture that comes with the HRMS
            json_response(['has_image' => $v !== '', 'custom' => $custom] + (!empty($_GET['photo']) ? ['photo' => $v] : []));
            break;
        }
        case 'st_bill.image_save': {      // managers: set or remove that picture (kind=back: the returning customer's)
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can change the bill picture.'], 403);
            $in = json_input(); $p = (string)($in['photo'] ?? ''); $key = (($in['kind'] ?? '') === 'back') ? 'bill_image_back' : 'bill_image';
            if ($p !== '' && !(preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $p) && strlen($p) <= 900000)) json_response(['error' => 'The picture must be a JPG, PNG or WEBP under about 600 KB.'], 422);
            $pdo->prepare("INSERT INTO st_cs_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")->execute([$key, json_encode(['photo' => $p], JSON_UNESCAPED_UNICODE)]);
            json_response(['ok' => true, 'has_image' => $p !== '']);
            break;
        }
        case 'st_bday.msg_get': {         // the wording + the photo (the photo only when asked: it is a big picture)
            require_auth(); json_response(st_bday_msg($pdo, !empty($_GET['photo'])));
            break;
        }
        case 'st_bday.msg_save': {        // managers: change the wording / the photo
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can change the birthday message.'], 403);
            $r = st_bday_msg_save($pdo, json_input()); if (!empty($r['error'])) json_response(['error' => $r['error']], 422); json_response(['ok' => true] + st_bday_msg($pdo));
            break;
        }
        case 'st_msgs.get': {             // the wording of the order thank-you the cashier sends by hand after a bill
            require_auth(); json_response(['msgs' => st_msgs_get($pdo), 'defaults' => st_msgs_defaults(), 'cs' => ['store_name' => st_cs_get($pdo)['store_name']]]);
            break;
        }
        case 'st_msgs.save': {            // managers: change the order thank-you wording
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can change the messages.'], 403);
            $r = st_msgs_save($pdo, json_input()); if (!empty($r['error'])) json_response(['error' => $r['error']], 422); json_response($r);
            break;
        }
        case 'st_bday.mark_sent': {       // the cashier sent the wish by hand (web.whatsapp): it leaves the "to send today" list
            $auth = require_auth(); $in = json_input(); $who = (string)($auth['username'] ?? 'cashier');
            $u = $pdo->prepare("UPDATE st_bday_vouchers SET wa_status = 'sent', wa_note = ?, wa_at = ? WHERE voucher_id = ? AND wa_status <> 'sent'"); $u->execute([mb_substr('sent by hand by ' . $who, 0, 200), date('Y-m-d H:i:s'), (int)($in['id'] ?? 0)]);
            json_response(['ok' => true]);
            break;
        }
        case 'st_bday.gifts': {           // a customer's other gifts (review / goodwill, in ml) by phone or code: the cashier checks and marks them used
            require_auth(); $q = trim((string)($_GET['q'] ?? '')); $ph = substr(preg_replace('/\D+/', '', $q), -10); $out = [];
            if (is_file(__DIR__ . '/complaints_rewards.php') && !function_exists('cxrw_ensure')) { /* loaded with the Complaints module only */ }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS rw_reward (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'goodwill', ml INT NOT NULL DEFAULT 20, min_order INT NOT NULL DEFAULT 0, code VARCHAR(14) NOT NULL DEFAULT '', status VARCHAR(6) NOT NULL DEFAULT 'ok', src VARCHAR(10) NOT NULL DEFAULT 'case', src_ref VARCHAR(40) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '', reason VARCHAR(300) NOT NULL DEFAULT '', choice VARCHAR(120) NOT NULL DEFAULT '', expires_at DATETIME NULL, by_emp INT NOT NULL DEFAULT 0, ok_by INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, used_at DATETIME NULL, used_ref VARCHAR(60) NOT NULL DEFAULT '', used_by INT NOT NULL DEFAULT 0, UNIQUE KEY uq_code (code), KEY k_phone (phone, id), KEY k_status (status, expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $s = $pdo->prepare("SELECT * FROM rw_reward WHERE status = 'ok' AND (expires_at IS NULL OR expires_at >= ?) AND (" . ($ph !== '' && strlen($ph) === 10 ? 'phone = ? OR ' : '') . "code = ?) ORDER BY expires_at LIMIT 20");
                $s->execute(array_merge([date('Y-m-d H:i:s')], $ph !== '' && strlen($ph) === 10 ? [$ph] : [], [strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $q))]));
                foreach ($s->fetchAll() as $r) $out[] = ['id' => (int)$r['id'], 'kind' => 'rw', 'ml' => (int)$r['ml'], 'code' => (string)$r['code'], 'phone' => (string)$r['phone'], 'min_order' => (int)$r['min_order'], 'valid_to' => $r['expires_at'] ? substr((string)$r['expires_at'], 0, 10) : '', 'what' => ['review' => 'Review gift', 'birthday' => 'Birthday gift', 'goodwill' => 'Goodwill gift'][$r['kind']] ?? 'Gift', 'reason' => (string)$r['reason']];
                $v = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE status = 'active' AND valid_from <= ? AND valid_to >= ? AND (" . ($ph !== '' && strlen($ph) === 10 ? 'phone = ? OR ' : '') . "code = ?) LIMIT 5");
                $v->execute(array_merge([date('Y-m-d'), date('Y-m-d')], $ph !== '' && strlen($ph) === 10 ? [$ph] : [], [st_bday_norm($q)]));
                foreach ($v->fetchAll() as $r) $out[] = ['id' => (int)$r['voucher_id'], 'kind' => 'bd', 'ml' => 50, 'code' => (string)$r['code'], 'phone' => (string)$r['phone'], 'min_order' => 1500, 'valid_to' => (string)$r['valid_to'], 'what' => 'Birthday gift', 'reason' => 'Free 50 ml. At the store: bill it with the birthday code. Online: order above ₹1,500.'];
            } catch (Throwable $e) { error_log('st_bday.gifts: ' . $e->getMessage()); }
            json_response(['gifts' => $out]);
            break;
        }
        case 'st_bday.gift_use': {        // a gift was handed over / applied online: record it so it cannot be used twice
            $auth = require_auth(); $in = json_input(); $who = (string)($auth['username'] ?? 'cashier'); $ref = mb_substr(trim((string)($in['ref'] ?? '')), 0, 60); $choice = mb_substr(trim((string)($in['choice'] ?? '')), 0, 120);
            if ($ref === '') json_response(['error' => 'Write the bill or order number it was used on.'], 422);
            $id = (int)($in['id'] ?? 0); $now = date('Y-m-d H:i:s');
            if (($in['kind'] ?? '') === 'bd') {
                $q = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE voucher_id = ? AND status = 'active'"); $q->execute([$id]); $v = $q->fetch(); if (!$v) json_response(['error' => 'This birthday code is not active.'], 404);
                if (st_gift_given($pdo, (string)$v['phone'], (int)$v['bday_year'])) json_response(['error' => 'This customer already took this year’s birthday gift.'], 409);
                $pdo->prepare("UPDATE st_bday_vouchers SET status = 'redeemed', redeemed_at = ?, redeemed_by = ? WHERE voucher_id = ? AND status = 'active'")->execute([$now, mb_substr($who . ' (online/hand: ' . $ref . ')', 0, 80), $id]);
                try { $pdo->prepare("INSERT IGNORE INTO st_bday_gifts (phone, name, gift_year, bill_id, bottle_id, given_by, created_at) VALUES (?,?,?,0,0,?,?)")->execute([(string)$v['phone'], (string)$v['name'], (int)$v['bday_year'], mb_substr($who, 0, 80), $now]); } catch (Throwable $e) { }
                json_response(['ok' => true, 'message' => 'Birthday gift marked as used.']);
            }
            $u = $pdo->prepare("UPDATE rw_reward SET status = 'used', used_at = ?, used_ref = ?, choice = ? WHERE id = ? AND status = 'ok' AND (expires_at IS NULL OR expires_at >= ?)"); $u->execute([$now, $ref, $choice, $id, $now]);
            if ($u->rowCount() !== 1) json_response(['error' => 'This gift is not active (used, cancelled or expired).'], 409);
            json_response(['ok' => true, 'message' => 'Gift marked as used.']);
            break;
        }
        case 'st_bday.send': {            // send (or send again) one code on WhatsApp now
            require_auth();
            $in = json_input(); $q = $pdo->prepare("SELECT * FROM st_bday_vouchers WHERE voucher_id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $v = $q->fetch();
            if (!$v) json_response(['error' => 'Code not found.'], 404);
            if ($v['status'] !== 'active') json_response(['error' => 'This code is already used or cancelled.'], 400);
            [$ok, $why] = st_bday_send($pdo, $v);
            json_response(['ok' => $ok, 'message' => $ok ? 'Sent on WhatsApp.' : 'Not sent: ' . $why]);
            break;
        }

        case 'st_bday.cfg_save': {        // managers: on/off, days before the birthday, days it stays valid after it, automatic WhatsApp on/off
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can change this.'], 403);
            json_response(['ok' => true, 'cfg' => st_bday_cfg_save($pdo, json_input())]);
            break;
        }

        case 'st_bday.run': {             // managers: make the codes and send them now (otherwise it happens by itself once a day after 9 AM)
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can do this.'], 403);
            $tn = st_bday_ensure_template($pdo); $r = st_bday_run($pdo, true); @unlink(sys_get_temp_dir() . '/hrms_st_bday_day');
            json_response(['ok' => true] + $r + ['message' => $r['off'] ? 'The birthday gift is switched off.' : $r['made'] . ' new code(s), ' . $r['sent'] . ' sent' . ($r['waiting'] ? ', ' . $r['waiting'] . ' waiting for the WhatsApp template to be approved' : '') . ($r['failed'] ? ', ' . $r['failed'] . ' not sent' : '') . '. WhatsApp message: ' . $tn . '.']);
            break;
        }

        case 'st_bday.seed': {            // managers: make the WhatsApp birthday template and send it to Meta for approval
            $auth = require_auth(); if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage'))) json_response(['error' => 'Only a manager can do this.'], 403);
            $r = st_wa_seed($pdo, ['tmph_store_bday']);
            json_response(['ok' => empty($r['failed']) && empty($r['error']), 'result' => $r, 'message' => !empty($r['error']) ? (string)$r['error'] : ($r['failed'] ? 'Meta said: ' . mb_substr((string)reset($r['failed']), 0, 160) : ($r['submitted'] ? 'Sent to Meta for approval (usually a few minutes to a day).' : 'Already with Meta.'))]);
            break;
        }

        case 'st_bill.sources': {         // how many bills came from each source on a day (default: today's store day)
            require_auth();
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : st_day();
            $q = $pdo->prepare("SELECT source, COUNT(*) n, COALESCE(SUM(total),0) amt FROM st_bills WHERE biz_date = ? AND status = 'ok' GROUP BY source"); $q->execute([$d]);
            $by = []; $all = 0; foreach ($q->fetchAll() as $r) { $by[(string)$r['source']] = ['n' => (int)$r['n'], 'amt' => round((float)$r['amt'])]; $all += (int)$r['n']; }
            $rows = []; foreach (ST_SOURCES as $k => $lab) if (isset($by[$k])) $rows[] = ['key' => $k, 'label' => $lab] + $by[$k];
            $aq = $pdo->prepare("SELECT MIN(area) area, COUNT(*) n, COALESCE(SUM(total),0) amt FROM st_bills WHERE biz_date = ? AND status = 'ok' AND area <> '' GROUP BY LOWER(area) ORDER BY n DESC, amt DESC"); $aq->execute([$d]);
            $areas = array_map(fn($r) => ['area' => (string)$r['area'], 'n' => (int)$r['n'], 'amt' => round((float)$r['amt'])], $aq->fetchAll());
            json_response(['date' => $d, 'total' => $all, 'asked' => $all - ($by['']['n'] ?? 0), 'rows' => $rows, 'areas' => $areas, 'area_asked' => array_sum(array_column($areas, 'n')), 'list' => ST_SOURCES]);
            break;
        }

        case 'st_bill.list': {
            require_auth();
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : st_day();
            $st = $pdo->prepare("SELECT bill_id, bill_no, created_at, emp_name, helper_emp_name, customer_name, total_ml, bottles, testers, total, order_code, pay_mode, cash_amt, online_amt, status, chain_name, chain_amt, inv_no, inv_token, inv_err FROM st_bills WHERE biz_date = ? ORDER BY bill_id DESC");
            $st->execute([$d]);
            $rows = $st->fetchAll();
            $sum = 0.0; $ml = 0;
            foreach ($rows as &$r) { $r['bill_id'] = (int)$r['bill_id']; $r['total'] = (float)$r['total']; $r['total_ml'] = (int)$r['total_ml']; if ($r['status'] === 'ok') { $sum += $r['total']; $ml += $r['total_ml']; } }
            $cs = st_cs_get($pdo); $pp = st_people_status($pdo, array_map(fn($r) => $r['bill_id'], $rows));
            $hasInv = false; foreach ($rows as $r0) if ($r0['inv_token'] !== '') $hasInv = true; if ($hasInv) st_wch_load($pdo);
            foreach ($rows as &$r) {
                $r['chain_amt'] = (float)$r['chain_amt']; $r['cash_amt'] = (float)$r['cash_amt']; $r['online_amt'] = (float)$r['online_amt']; $r['inv_url'] = ($r['inv_token'] !== '' && function_exists('wch_view_url')) ? wch_view_url($r['inv_token']) : ''; $r['inv_pdf'] = ($r['inv_token'] !== '' && function_exists('wch_pdf_url')) ? wch_pdf_url($r['inv_token']) : ''; unset($r['inv_token']);
                $r['people'] = $pp[$r['bill_id']] ?? [];
                foreach ($r['people'] as &$x) if ($x['phone'] !== '') { $tok = substr($x['link'], strrpos($x['link'], '=') + 1); $wm = st_wa_message(['name' => $x['name'], 'phone' => $x['phone'], 'token' => $tok, 'returning' => $x['returning']], ['bill_no' => $r['bill_no'], 'total' => $r['total']], $cs); $x['wa_url'] = $wm['url']; $x['wa_url2'] = $wm['url2']; }
                unset($x);
            }
            unset($r);
            json_response(['date' => $d, 'today' => st_day(), 'day_start' => ST_DAY_START_HOUR, 'bills' => $rows, 'total' => round($sum, 2), 'total_ml' => $ml]);
            break;
        }

        case 'st_bill.set_pay': {          // changed his mind / pressed the wrong one: Cash <-> Online <-> Cash + Online. A manager any day; a cashier only the bills of today.
            $auth = require_auth(); $in = json_input(); $id = (int)($in['bill_id'] ?? 0);
            $q = $pdo->prepare("SELECT bill_no, biz_date, status, total, pay_mode, cash_amt, online_amt FROM st_bills WHERE bill_id = ?"); $q->execute([$id]); $b = $q->fetch();
            if (!$b) json_response(['error' => 'Bill not found.'], 404);
            if ($b['status'] !== 'ok') json_response(['error' => 'This bill is cancelled; its payment cannot be changed.'], 409);
            $isMgr = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'rs_manage');
            if (!$isMgr && $b['biz_date'] !== st_day()) json_response(['error' => 'Only a manager can change the payment of an earlier day. Today’s bills you can change yourself.'], 403);
            $mode = (string)($in['pay_mode'] ?? ''); if (!in_array($mode, ['cash', 'online', 'split'], true)) json_response(['error' => 'Choose Cash, Online or Cash + online.'], 400);
            $total = (float)$b['total']; $cash = max(0.0, (float)($in['cash_amt'] ?? 0)); $online = max(0.0, (float)($in['online_amt'] ?? 0));
            if ($mode === 'cash') { $cash = $total; $online = 0.0; }
            elseif ($mode === 'online') { $online = $total; $cash = 0.0; }
            elseif (abs($cash + $online - $total) > 0.5) json_response(['error' => 'Cash + online (₹' . number_format($cash + $online) . ') must equal the bill (₹' . number_format($total) . ').'], 400);
            $name = ['cash' => 'Cash', 'online' => 'Online', 'split' => 'Cash + online'];
            $was = $name[$b['pay_mode']] ?? $b['pay_mode']; if ($b['pay_mode'] === 'split') $was .= ' (₹' . number_format((float)$b['cash_amt']) . ' + ₹' . number_format((float)$b['online_amt']) . ')';
            $now = $name[$mode] . ($mode === 'split' ? ' (₹' . number_format($cash) . ' + ₹' . number_format($online) . ')' : '');
            $pdo->prepare("UPDATE st_bills SET pay_mode = ?, cash_amt = ?, online_amt = ? WHERE bill_id = ? AND status = 'ok'")->execute([$mode, $cash, $online, $id]);
            if (function_exists('bd_log')) { try { bd_log('store', $id, 'Bill ' . $b['bill_no'] . ' payment changed: ' . $was . ' -> ' . $now, (string)($auth['username'] ?? '')); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'message' => $b['bill_no'] . ': ' . $was . ' → ' . $now . '.']);
            break;
        }

        case 'st_bill.invoice': {          // the GST invoice of a chain bill could not be made at billing time: try again
            require_auth(); $id = (int)(json_input()['bill_id'] ?? 0);
            $q = $pdo->prepare("SELECT status, chain_name FROM st_bills WHERE bill_id = ?"); $q->execute([$id]); $b = $q->fetch();
            if (!$b || $b['status'] !== 'ok' || $b['chain_name'] === '') json_response(['error' => 'This bill has no chain, or it was cancelled.'], 400);
            $iv = st_chain_invoice($pdo, $id);
            json_response($iv['ok'] ? ['ok' => true, 'inv_no' => $iv['inv']['inv_no'], 'inv_url' => wch_view_url($iv['inv']['token']), 'inv_pdf' => wch_pdf_url($iv['inv']['token']), 'message' => 'GST invoice ' . $iv['inv']['inv_no'] . ' is ready.'] : ['error' => $iv['error']], $iv['ok'] ? 200 : 409);
            break;
        }

        case 'st_bill.mark_sent': {          // the cashier pressed "Send on WhatsApp" for this customer
            $auth = require_auth();
            $pid = (int)(json_input()['person_id'] ?? 0);
            $q = $pdo->prepare("SELECT bill_id FROM st_bill_people WHERE person_id = ?"); $q->execute([$pid]); $bid = (int)$q->fetchColumn();
            if (!$bid) json_response(['error' => 'Customer not found.'], 404);
            st_event($pdo, $pid, $bid, 'sent', (string)($auth['username'] ?? ''));
            json_response(['ok' => true]);
            break;
        }

        case 'st_bill.next_code': {          // the Order code box of a new bill: the next free code of the person who bills
            require_auth();
            $eid = (int)($_GET['emp_id'] ?? 0);
            json_response($eid ? st_next_order_code($pdo, $eid) : ['prefix' => '', 'code' => '']);
            break;
        }

        case 'st_cust.lookup': {          // typed a mobile number at billing: is this a returning customer?
            require_auth();
            $ph = st_phone_clean($_GET['phone'] ?? '');
            json_response($ph === '' ? ['phone' => '', 'returning' => false] : st_customer_lookup($pdo, $ph));
            break;
        }

        case 'st_cust.birthdays': {       // customers with a birthday in the next N days (default 7)
            require_auth();
            json_response(['days' => max(1, min(60, (int)($_GET['days'] ?? 7))), 'list' => st_birthdays($pdo, max(1, min(60, (int)($_GET['days'] ?? 7))))]);
            break;
        }

        // ====================================================== the store person's own day (My Sales) and the manager's Team view
        case 'st_my.today': {
            $auth = require_auth();
            $me = (int)($auth['employee_id'] ?? 0);
            if (!$me) json_response(['is_store' => false]);
            $on = (int)$pdo->query("SELECT COUNT(*) FROM st_duty WHERE emp_id = " . $me)->fetchColumn() > 0;
            $had = (int)$pdo->query("SELECT COUNT(*) FROM st_bills WHERE emp_id = " . $me . " AND status = 'ok'")->fetchColumn() > 0;
            if (!$on && !$had) json_response(['is_store' => false]);
            $d = st_staff_day($pdo, $me, st_punch_map($pdo, [$me])[$me] ?? null);
            json_response(['is_store' => true, 'day_start' => ST_DAY_START_HOUR, 'needs_punch' => (int)setting_get($pdo, 'st_bill_needs_punch', 0)] + $d);
            break;
        }

        case 'st_team.get': {
            require_auth();
            $ids = array_map('intval', $pdo->query("SELECT emp_id FROM st_duty")->fetchAll(PDO::FETCH_COLUMN));
            if (function_exists('pay_day_assign')) foreach ((pay_day_assign($pdo)[st_day()] ?? []) as $dEid => $dG) if ($dG === 'store' && !in_array((int)$dEid, $ids, true)) $ids[] = (int)$dEid;
            $punch = st_punch_map($pdo, $ids); $rows = [];
            foreach ($ids as $id) {
                $n = $pdo->prepare("SELECT full_name, employee_code FROM employees WHERE employee_id = ? AND status != 'ex'"); $n->execute([$id]); $e = $n->fetch(); if (!$e) continue;
                $rule = '';
                try { $m = emp_shift_map($pdo, function_exists('attendance_day_now') ? attendance_day_now($pdo) : date('Y-m-d')); $s = $m[$id] ?? null;
                    if ($s) $rule = ($s['mode'] ?? 'fixed') === 'free' ? 'No punch rule' : (($s['mode'] ?? '') === 'hours' ? rtrim(rtrim(number_format((float)$s['hours_target'], 2, '.', ''), '0'), '.') . ' h a day' : 'In ' . $s['shift_start'] . ' · late after ' . (int)$s['late_after'] . ' min'); } catch (Throwable $e2) {}
                $rows[] = ['employee_id' => $id, 'name' => (string)$e['full_name'], 'code' => (string)$e['employee_code'], 'rule' => $rule] + st_staff_day($pdo, $id, $punch[$id] ?? null);
            }
            usort($rows, fn($a, $b) => $b['sales'] <=> $a['sales']);
            json_response(['day' => st_day(), 'day_start' => ST_DAY_START_HOUR, 'needs_punch' => (int)setting_get($pdo, 'st_bill_needs_punch', 0), 'people' => $rows,
                'totals' => ['sales' => round(array_sum(array_column($rows, 'sales')), 2), 'bills' => array_sum(array_column($rows, 'bills_n')), 'commission' => round(array_sum(array_column($rows, 'commission')), 2), 'in' => count(array_filter($rows, fn($r) => !empty($r['punch']))), 'people' => count($rows)]]);
            break;
        }

        case 'st_team.save': {
            require_auth();
            $in = json_input();
            setting_set($pdo, 'st_bill_needs_punch', !empty($in['needs_punch']) ? 1 : 0);
            json_response(['ok' => true, 'needs_punch' => !empty($in['needs_punch']) ? 1 : 0]);
            break;
        }

        case 'st_price.card': {           // the price chart + every offer running now + the commission rules, read-only, for the Store pages (same figures as Learning → Pricing and Commission Rules)
            $auth = require_auth(); $out = ['cols' => [], 'rows' => [], 'offers' => [], 'flash' => [], 'live' => true];
            if (budget_engine_ready()) {
                try { $ch = cm_chart($pdo); $out['cols'] = array_map('floatval', (array)$ch['cols']); $out['rows'] = array_map(fn($r) => ['ml' => (int)$r['ml'], 'p' => array_map('floatval', (array)$r['p'])], (array)$ch['rows']); $out['window'] = (float)($ch['window'] ?? 20); $out['floor'] = (float)($ch['floor'] ?? 0); $out['rules'] = ['codepct' => (float)($ch['codepct'] ?? 0), 'nocode' => (float)($ch['nocode'] ?? 0), 'prepaid' => (float)($ch['prepaid'] ?? 0)]; } catch (Throwable $e) { }
                try { $day = st_day(); foreach (cm_offers($pdo) as $o) { if (empty($o['active'])) continue; if ($o['from_date'] && $day < $o['from_date']) continue; if ($o['to_date'] && $day > $o['to_date']) continue; $out['offers'][] = ['ml' => (int)$o['ml'], 'price' => (float)$o['price'], 'pct' => (float)$o['pct'], 'from' => (string)$o['from_date'], 'to' => (string)($o['to_date'] ?? ''), 'note' => (string)($o['note'] ?? '')]; } usort($out['offers'], fn($a, $b) => $a['ml'] <=> $b['ml']); } catch (Throwable $e) { }
                try { $out['live'] = cm_is_live($pdo); if (!$out['live']) foreach (flash_offer_running(flash_offer_list_get($pdo)) as $x) $out['flash'][] = ['pct' => (float)$x['pct'], 'ends_at' => (string)$x['ends_at'], 'sizes' => array_values(array_map(fn($i) => (int)($i['ml'] ?? 0), (array)($x['items'] ?? [])))]; } catch (Throwable $e) { }
            }
            try { $ids = st_pool_ids($pdo); if ($ids) { $in = implode(',', array_map('intval', $ids)); $out['team'] = array_map(fn($r) => (string)$r['full_name'], $pdo->query("SELECT full_name FROM employees WHERE employee_id IN ($in) AND status != 'ex' ORDER BY full_name")->fetchAll()); } } catch (Throwable $e) { }
            $out['can_edit'] = in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || user_can($auth, 'commission');
            json_response($out);
            break;
        }
        case 'st_cs.team_numbers': {      // the mobile numbers already saved on the store team (Store → Team) and the store's own number, to fill the contact cards
            require_auth(); $out = ['store' => '', 'people' => []];
            try { $co = st_company($pdo); $sp = st_phone_clean((string)($co['phone'] ?? '')); if ($sp !== '' && strlen($sp) <= 12) $out['store'] = $sp; } catch (Throwable $e) { }
            try {
                $ids = array_map('intval', $pdo->query("SELECT emp_id FROM st_duty")->fetchAll(PDO::FETCH_COLUMN));
                $q = $pdo->query("SELECT employee_id, full_name, mobile FROM employees WHERE status != 'ex'");
                foreach ($q->fetchAll() as $e) { $ph = st_phone_clean((string)$e['mobile']); $out['people'][] = ['employee_id' => (int)$e['employee_id'], 'name' => (string)$e['full_name'], 'phone' => $ph, 'on_team' => in_array((int)$e['employee_id'], $ids, true)]; }
            } catch (Throwable $e) { error_log('st_cs.team_numbers: ' . $e->getMessage()); }
            json_response($out);
            break;
        }
        case 'st_cs.get': {
            require_auth();
            json_response(['settings' => st_cs_get($pdo), 'sample' => st_bill_link('SAMPLE'), 'short' => st_short_link('SAMPLE')]);
            break;
        }

        case 'st_cs.wa_status': {          // are the two automatic-bill templates approved by Meta?
            require_auth();
            json_response(st_wa_status($pdo));
            break;
        }

        case 'st_cs.wa_setup': {          // makes the two templates and sends them to Meta for approval (safe to press again)
            require_auth();
            $r = st_wa_seed($pdo); setting_set($pdo, 'st_wa_seed_at', (string)time());
            json_response(['seed' => $r] + st_wa_status($pdo));
            break;
        }

        case 'st_cs.save': {
            require_auth();
            $r = st_cs_save($pdo, json_input());
            json_response($r, isset($r['error']) ? 400 : 200);
            break;
        }

        case 'st_bill.set_day': {          // move a bill to another business day (e.g. a sale made after 3 AM that belongs to the previous day). The real time of the bill stays as it was.
            $auth = require_auth();
            require_role(['Admin', 'HR'], $auth);
            $in = json_input(); $id = (int)($in['bill_id'] ?? 0); $to = (string)($in['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($to) === false) json_response(['error' => 'Pick a valid date.'], 400);
            if ($to > st_day()) json_response(['error' => 'A bill cannot be moved to a day that has not come yet.'], 400);
            if ($to < date('Y-m-d', strtotime(st_day() . ' -45 day'))) json_response(['error' => 'That day is too old (limit: 45 days back).'], 400);
            $q = $pdo->prepare("SELECT bill_no, biz_date, status FROM st_bills WHERE bill_id = ?"); $q->execute([$id]); $b = $q->fetch();
            if (!$b) json_response(['error' => 'Bill not found.'], 404);
            if ($b['biz_date'] === $to) json_response(['ok' => true, 'message' => 'Already on that day.']);
            $pdo->prepare("UPDATE st_bills SET biz_date = ? WHERE bill_id = ?")->execute([$to, $id]);
            if (function_exists('bd_log')) { try { bd_log('store', $id, 'Bill ' . $b['bill_no'] . ' moved from day ' . $b['biz_date'] . ' to ' . $to, (string)($auth['username'] ?? '')); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'message' => $b['bill_no'] . ' now counts on ' . $to . '.']);
            break;
        }

        case 'st_bill.void': {
            $auth = require_auth();
            require_role(['Admin', 'HR'], $auth);
            $id = (int)(json_input()['bill_id'] ?? 0);
            $pdo->beginTransaction();
            $b = $pdo->prepare("SELECT status, inv_id, inv_no, bill_no FROM st_bills WHERE bill_id = ? FOR UPDATE"); $b->execute([$id]);
            $row = $b->fetch(); $s = $row ? $row['status'] : '';
            if ($s !== 'ok') { $pdo->rollBack(); json_response(['error' => 'That bill is not open to cancel.'], 409); }
            $pdo->prepare("UPDATE st_bills SET status = 'void' WHERE bill_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE st_bottles SET status = 'stock', sold_at = NULL, bill_id = NULL WHERE bill_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE st_chain_pieces SET status = 'stock', sold_at = NULL, bill_id = NULL WHERE bill_id = ?")->execute([$id]);      // the chain piece goes back to stock
            $pdo->commit();
            if ((int)$row['inv_id'] > 0) st_chain_invoice_cancel($pdo, (int)$row['inv_id'], (string)$row['bill_no']);      // the invoice keeps its number and is marked CANCELLED
            json_response(['ok' => true, 'message' => 'Bill cancelled. Its bottles' . ((int)$row['inv_id'] > 0 ? ' and chain are' : ' are') . ' back in stock and the sale no longer counts.' . ((int)$row['inv_id'] > 0 ? ' GST invoice ' . $row['inv_no'] . ' is kept and marked CANCELLED.' : '')]);
            break;
        }

        // ====================================================== salespeople who can bill
        case 'st_bill.staff': {
            require_auth();
            $on = array_map('intval', $pdo->query("SELECT emp_id FROM st_duty")->fetchAll(PDO::FETCH_COLUMN));
            if (function_exists('pay_day_assign')) foreach ((pay_day_assign($pdo)[st_day()] ?? []) as $dEid => $dG) if ($dG === 'store' && !in_array((int)$dEid, $on, true)) $on[] = (int)$dEid;   // moved to the store for today
            $rows = []; $punched = st_punch_map($pdo, $on); $needsPunch = (int)setting_get($pdo, 'st_bill_needs_punch', 0);
            foreach ($pdo->query("SELECT employee_id, full_name FROM employees WHERE status != 'ex' ORDER BY full_name")->fetchAll() as $e) {
                $rows[] = ['employee_id' => (int)$e['employee_id'], 'name' => (string)$e['full_name'], 'on' => in_array((int)$e['employee_id'], $on, true), 'punched' => !empty($punched[(int)$e['employee_id']])];
            }
            json_response(['employees' => $rows, 'needs_punch' => $needsPunch, 'cs' => ['require_phone' => (int)st_cs_get($pdo)['require_phone']]]);
            break;
        }

        case 'st_duty.set': {
            $in = json_input();
            $id = (int)($in['employee_id'] ?? 0);
            if (!$id) json_response(['error' => 'Choose an employee.'], 400);
            if (!empty($in['on'])) $pdo->prepare("INSERT IGNORE INTO st_duty (emp_id, added_at) VALUES (?, ?)")->execute([$id, date('Y-m-d H:i:s')]);
            else $pdo->prepare("DELETE FROM st_duty WHERE emp_id = ?")->execute([$id]);
            json_response(['ok' => true]);
            break;
        }

        // ====================================================== dashboard (numbers + remarks from cleaning, pantry, billing, flyers, stock)
        case 'st_dash.get': {
            require_auth();
            $today = st_day(); $yday = date('Y-m-d', strtotime($today . ' -1 day'));
            $one = function (string $sql, array $a = []) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetch() ?: []; };
            $all = function (string $sql, array $a = []) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(); };

            // ---- barcodes + stock
            $bc = ['made' => 0, 'waiting' => 0, 'in_stock' => 0, 'sold' => 0, 'void' => 0, 'testers_in_stock' => 0, 'made_today' => 0];
            foreach ($all("SELECT status, kind, COUNT(*) c FROM st_bottles GROUP BY status, kind") as $r) {
                $c = (int)$r['c']; $bc['made'] += $c;
                if ($r['status'] === 'generated') $bc['waiting'] += $c; elseif ($r['status'] === 'stock') { $bc['in_stock'] += $c; if ($r['kind'] === 'tester') $bc['testers_in_stock'] += $c; } elseif ($r['status'] === 'sold') $bc['sold'] += $c; else $bc['void'] += $c;
            }
            $bc['made_today'] = (int)($one("SELECT COUNT(*) c FROM st_bottles WHERE created_at >= ?", [$today . ' 00:00:00'])['c'] ?? 0);
            $sizes = []; $stockMl = 0; $stockBottles = 0; $stockProducts = (int)($one("SELECT COUNT(DISTINCT product_id) c FROM st_bottles WHERE status = 'stock' AND kind = 'sale'")['c'] ?? 0);
            foreach ($all("SELECT ml, COUNT(*) c FROM st_bottles WHERE status = 'stock' AND kind = 'sale' GROUP BY ml ORDER BY ml") as $r) { $sizes[] = ['ml' => (int)$r['ml'], 'bottles' => (int)$r['c']]; $stockMl += (int)$r['ml'] * (int)$r['c']; $stockBottles += (int)$r['c']; }

            // ---- sales
            $t = $one("SELECT COUNT(*) bills, COALESCE(SUM(total),0) amt, COALESCE(SUM(total_ml),0) ml, COALESCE(SUM(discount),0) disc, COALESCE(SUM(testers),0) testers, COALESCE(SUM(cash_amt),0) cash, COALESCE(SUM(online_amt),0) online FROM st_bills WHERE biz_date = ? AND status = 'ok'", [$today]);
            $y = $one("SELECT COUNT(*) bills, COALESCE(SUM(total),0) amt FROM st_bills WHERE biz_date = ? AND status = 'ok'", [$yday]);
            // + the sales billed in the OLD POS (still in use): today, yesterday, the 7 days and who billed them
            $op = st_oldpos_sales($pdo, date('Y-m-d', strtotime($today . ' -6 day')), $today);
            $ot = $op['days'][$today] ?? ['bills' => 0, 'amt' => 0.0, 'disc' => 0.0, 'cash' => 0.0, 'online' => 0.0]; $oy = $op['days'][$yday] ?? ['bills' => 0, 'amt' => 0.0];
            $t['bills'] = (int)$t['bills'] + $ot['bills']; $t['amt'] = (float)$t['amt'] + $ot['amt']; $t['ml'] = (int)$t['ml'] + $op['ml']; $t['disc'] = (float)$t['disc'] + $ot['disc'];
            $t['cash'] = (float)$t['cash'] + $ot['cash']; $t['online'] = (float)$t['online'] + $ot['online'];
            $y['bills'] = (int)$y['bills'] + $oy['bills']; $y['amt'] = (float)$y['amt'] + $oy['amt'];
            $bills = (int)$t['bills']; $amt = (float)$t['amt'];
            $by = []; foreach ($all("SELECT emp_name, COUNT(*) bills, SUM(total) amt, SUM(total_ml) ml FROM st_bills WHERE biz_date = ? AND status = 'ok' GROUP BY emp_id, emp_name ORDER BY amt DESC", [$today]) as $r) $by[] = ['name' => $r['emp_name'], 'bills' => (int)$r['bills'], 'amount' => (float)$r['amt'], 'ml' => (int)$r['ml'], 'avg' => (int)$r['bills'] ? round((float)$r['amt'] / (int)$r['bills']) : 0];
            foreach ($op['by'] as $nm => $o) {      // the same person billed in both: one row
                $hit = false; foreach ($by as &$row) if (mb_strtolower(trim((string)$row['name'])) === mb_strtolower($nm)) { $row['bills'] += $o['bills']; $row['amount'] += $o['amt']; $row['ml'] += $o['ml']; $row['avg'] = $row['bills'] ? round($row['amount'] / $row['bills']) : 0; $hit = true; break; } unset($row);
                if (!$hit) $by[] = ['name' => $nm, 'bills' => $o['bills'], 'amount' => $o['amt'], 'ml' => $o['ml'], 'avg' => $o['bills'] ? round($o['amt'] / $o['bills']) : 0];
            }
            usort($by, fn($a, $b) => $b['amount'] <=> $a['amount']);
            $week = []; $map = []; foreach ($all("SELECT biz_date d, SUM(total) amt, COUNT(*) bills FROM st_bills WHERE status = 'ok' AND biz_date >= ? GROUP BY biz_date", [date('Y-m-d', strtotime($today . ' -6 day'))]) as $r) $map[$r['d']] = $r;
            for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime($today . ' -' . $i . ' day')); $week[] = ['date' => $d, 'amount' => (float)($map[$d]['amt'] ?? 0) + (float)($op['days'][$d]['amt'] ?? 0), 'bills' => (int)($map[$d]['bills'] ?? 0) + (int)($op['days'][$d]['bills'] ?? 0)]; }

            // ---- remarks: each source is separate, so one broken source never hides the others
            $rem = ['cleaning' => [], 'pantry' => [], 'billing' => [], 'customers' => [], 'flyers' => [], 'stock' => []];
            $add = function (string $area, string $level, string $text) use (&$rem) { $rem[$area][] = ['level' => $level, 'text' => $text]; };
            $storeOffice = 0;
            try { $storeOffice = (int)$pdo->query("SELECT id FROM of_offices WHERE kind = 'store' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable $e) { $storeOffice = 0; }
            if ($storeOffice) {
                try {
                    foreach ($all("SELECT i.remark, i.days, z.name zone FROM of_issues i LEFT JOIN of_zones z ON z.id = i.zone_id WHERE i.office_id = ? AND i.status = 'open' ORDER BY i.days DESC, i.last_at DESC LIMIT 6", [$storeOffice]) as $r)
                        $add('cleaning', (int)$r['days'] >= 3 ? 'bad' : 'warn', trim(($r['zone'] ? $r['zone'] . ': ' : '') . $r['remark']) . ((int)$r['days'] > 1 ? ' · ' . (int)$r['days'] . ' days running' : ''));
                    $rv = (int)($one("SELECT COUNT(*) c FROM of_checks WHERE office_id = ? AND review = 1 AND human IS NULL", [$storeOffice])['c'] ?? 0); if ($rv) $add('cleaning', 'info', $rv . ' cleaning photo' . ($rv > 1 ? 's' : '') . ' waiting for a check');
                    $tk = (int)($one("SELECT COUNT(*) c FROM of_tickets WHERE office_id = ? AND status = 'open'", [$storeOffice])['c'] ?? 0); if ($tk) $add('cleaning', 'warn', $tk . ' repair ticket' . ($tk > 1 ? 's' : '') . ' open');
                } catch (Throwable $e) { error_log('st_dash cleaning: ' . $e->getMessage()); }
                try {
                    foreach ($all("SELECT i.name, i.last_count, i.min_qty, i.equip_state FROM dp_su_items i JOIN of_zones z ON z.id = i.zone_id WHERE z.office_id = ? AND i.status = 'active' AND ((i.min_qty IS NOT NULL AND i.last_count IS NOT NULL AND i.last_count < i.min_qty) OR LOWER(COALESCE(i.equip_state, '')) IN ('missing', 'broken')) ORDER BY i.name LIMIT 10", [$storeOffice]) as $r) {
                        $es = strtolower((string)$r['equip_state']);
                        if ($es === 'missing' || $es === 'broken') $add('pantry', 'warn', $r['name'] . ' is ' . $es);
                        else $add('pantry', 'warn', $r['name'] . ': ' . rtrim(rtrim(number_format((float)$r['last_count'], 2, '.', ''), '0'), '.') . ' left (needs at least ' . rtrim(rtrim(number_format((float)$r['min_qty'], 2, '.', ''), '0'), '.') . ')');
                    }
                } catch (Throwable $e) { error_log('st_dash pantry: ' . $e->getMessage()); }
            }
            // billing (worked out from today's bills; nothing to type)
            $nc = (int)($one("SELECT COUNT(*) c FROM st_bills WHERE biz_date = ? AND status = 'ok' AND order_code = ''", [$today])['c'] ?? 0); if ($nc) $add('billing', 'warn', $nc . ' bill' . ($nc > 1 ? 's' : '') . ' today with no order code (commission is cut without one)');
            $vd = (int)($one("SELECT COUNT(*) c FROM st_bills WHERE biz_date = ? AND status = 'void'", [$today])['c'] ?? 0); if ($vd) $add('billing', 'warn', $vd . ' bill' . ($vd > 1 ? 's' : '') . ' cancelled today');
            $mp = (int)($one("SELECT COUNT(*) c FROM st_bills WHERE biz_date = ? AND status = 'ok' AND price_col IS NULL", [$today])['c'] ?? 0); if ($mp) $add('billing', 'info', $mp . ' bill' . ($mp > 1 ? 's' : '') . ' with a manager-typed price');
            if ((float)$t['disc'] > 0) { $dn = (int)($one("SELECT COUNT(*) c FROM st_bills WHERE biz_date = ? AND status = 'ok' AND discount > 0", [$today])['c'] ?? 0); $add('billing', 'info', '₹' . number_format((float)$t['disc']) . ' discount given on ' . $dn . ' bill' . ($dn > 1 ? 's' : '')); }
            foreach ($all("SELECT p.title, SUM(b.status = 'stock' AND b.kind = 'sale') s FROM st_products p JOIN st_bottles b ON b.product_id = p.product_id AND b.stocked_at IS NOT NULL WHERE p.active = 1 GROUP BY p.product_id, p.title HAVING s <= 1 ORDER BY s, p.title LIMIT 6") as $r) $add('stock', (int)$r['s'] === 0 ? 'bad' : 'warn', $r['title'] . ((int)$r['s'] === 0 ? ' is out of stock' : ' has only 1 bottle left'));
            try {      // stock order reminder (only once an order has ever been placed)
                $oc = st_order_cycle($pdo);
                if ($oc['last_ordered_at'] && $oc['due_in_days'] <= 0) $add('stock', $oc['due_in_days'] < 0 ? 'warn' : 'info', $oc['due_in_days'] < 0 ? 'Stock order is ' . abs($oc['due_in_days']) . ' day' . (abs($oc['due_in_days']) > 1 ? 's' : '') . ' overdue (you order every ' . $oc['cycle_days'] . ' days)' : 'Order day: time to place your stock order (every ' . $oc['cycle_days'] . ' days)');
            } catch (Throwable $e) { error_log('st_dash order: ' . $e->getMessage()); }
            // flyers + flyer map
            try {
                foreach ($all("SELECT flag, COUNT(*) c FROM fl_drops WHERE created_at >= ? AND flag IS NOT NULL AND flag_ok = 0 GROUP BY flag", [$today . ' 00:00:00']) as $r) $add('flyers', 'warn', (int)$r['c'] . ' flagged flyer drop' . ((int)$r['c'] > 1 ? 's' : '') . ' today (' . $r['flag'] . ')');
                foreach ($all("SELECT a.emp_name, a.area_name, a.target, a.note, (SELECT COUNT(*) FROM fl_drops d WHERE d.assign_id = a.id AND (d.flag IS NULL OR d.flag_ok = 1)) done FROM fl_assign a WHERE a.assign_date = ? ORDER BY a.id", [$today]) as $r) {
                    $line = $r['emp_name'] . ' · ' . $r['area_name'] . ': ' . (int)$r['done'] . ' of ' . (int)$r['target'] . ' drops';
                    if (trim((string)$r['note']) !== '') $add('flyers', 'info', $line . ' — ' . trim((string)$r['note']));
                    elseif ((int)date('G') >= 16 && (int)$r['done'] < (int)$r['target']) $add('flyers', 'warn', $line . ' (behind target)');
                }
            } catch (Throwable $e) { error_log('st_dash flyers: ' . $e->getMessage()); }
            // customers: numbers, bill sent, bill page opened, contacts saved, community / review tapped (today, 7 days) + what is still open
            $cust = ['today' => null, 'week' => null];
            try {
                $ev = function (string $k, string $extra = '') { return "EXISTS(SELECT 1 FROM st_bill_events e WHERE e.person_id = p.person_id AND e.kind = '$k'$extra)"; };
                $agg = function (string $from, string $to) use ($pdo, $ev) {
                    $q = $pdo->prepare("SELECT COUNT(*) people, COALESCE(SUM(p.declined),0) declined, COALESCE(SUM(p.phone <> '' AND p.declined = 0),0) numbers, COALESCE(SUM(" . $ev('sent') . "),0) sent, COALESCE(SUM(" . $ev('opened') . "),0) opened,
                        COALESCE(SUM(" . $ev('contact') . " AND p.returning_c = 0),0) contact, COALESCE(SUM(" . $ev('community') . " AND p.returning_c = 0),0) community, COALESCE(SUM(" . $ev('review') . " AND p.returning_c = 0),0) review, COALESCE(SUM(p.returning_c = 1 AND p.declined = 0),0) returning_n, COALESCE(SUM(p.returning_c = 0 AND p.declined = 0 AND p.phone <> ''),0) new_n, COALESCE(SUM(p.reason = '" . ST_GUEST . "'),0) friends
                        FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE b.biz_date BETWEEN ? AND ?");
                    $q->execute([$from, $to]); $r = $q->fetch() ?: []; foreach ($r as $k => $v) $r[$k] = (int)$v; return $r;
                };
                $cust['today'] = $agg($today, $today); $cust['week'] = $agg(date('Y-m-d', strtotime($today . ' -6 day')), $today);
                $t15 = date('Y-m-d H:i:s', time() - 900); $t24 = date('Y-m-d H:i:s', time() - 86400); $t48 = date('Y-m-d H:i:s', time() - 172800);
                $q = $pdo->prepare("SELECT COALESCE(SUM(p.phone <> '' AND p.declined = 0 AND p.reason <> '" . ST_GUEST . "' AND b.created_at < ? AND NOT " . $ev('sent') . "),0) not_sent,
                        COALESCE(SUM(" . $ev('sent', " AND e.at < '$t24'") . " AND NOT " . $ev('opened') . "),0) not_opened,
                        COALESCE(SUM(p.returning_c = 0 AND " . $ev('opened', " AND e.at < '$t24'") . " AND NOT " . $ev('contact') . "),0) not_saved,
                        COALESCE(SUM(p.returning_c = 0 AND " . $ev('opened', " AND e.at < '$t48'") . " AND NOT " . $ev('community') . "),0) no_comm,
                        COALESCE(SUM(p.returning_c = 0 AND " . $ev('opened', " AND e.at < '$t48'") . " AND NOT " . $ev('review') . "),0) no_review
                        FROM st_bill_people p JOIN st_bills b ON b.bill_id = p.bill_id AND b.status = 'ok' WHERE b.biz_date >= ?");
                $q->execute([$t15, date('Y-m-d', strtotime($today . ' -13 day'))]); $o = $q->fetch() ?: [];
                if ((int)($cust['today']['declined'] ?? 0)) $add('customers', 'info', $cust['today']['declined'] . ' customer' . ($cust['today']['declined'] > 1 ? 's' : '') . ' did not share a number today');
                if ((int)($o['not_sent'] ?? 0)) $add('customers', 'warn', $o['not_sent'] . ' customer' . ($o['not_sent'] > 1 ? 's were' : ' was') . ' not sent their bill on WhatsApp yet');
                if ((int)($o['not_opened'] ?? 0)) $add('customers', 'info', $o['not_opened'] . ' customer' . ($o['not_opened'] > 1 ? 's have' : ' has') . 'n\'t opened their bill (sent over a day ago)');
                if ((int)($o['not_saved'] ?? 0)) $add('customers', 'warn', $o['not_saved'] . ' customer' . ($o['not_saved'] > 1 ? 's' : '') . ' opened the bill but did not save our contacts');
                if ((int)($o['no_comm'] ?? 0)) $add('customers', 'info', $o['no_comm'] . ' customer' . ($o['no_comm'] > 1 ? 's have' : ' has') . ' not tapped the community link');
                if ((int)($o['no_review'] ?? 0)) $add('customers', 'info', $o['no_review'] . ' customer' . ($o['no_review'] > 1 ? 's have' : ' has') . ' not tapped the Google review link');
            } catch (Throwable $e) { error_log('st_dash customers: ' . $e->getMessage()); }
            $nrem = 0; foreach ($rem as $l) $nrem += count($l);

            json_response([
                'date' => $today, 'day_start' => ST_DAY_START_HOUR,
                'barcodes' => $bc,
                'stock' => ['bottles' => $stockBottles, 'products' => $stockProducts, 'ml' => $stockMl, 'sizes' => $sizes, 'testers' => $bc['testers_in_stock']],
                'today' => ['old_pos_bills' => (int)$ot['bills'], 'old_pos_amount' => round((float)$ot['amt']), 'bills' => $bills, 'amount' => $amt, 'ml' => (int)$t['ml'], 'avg_ticket' => $bills ? round($amt / $bills) : 0, 'avg_ml' => $bills ? round((int)$t['ml'] / $bills) : 0, 'discount' => (float)$t['disc'], 'testers_given' => (int)$t['testers'], 'cash' => (float)$t['cash'], 'online' => (float)$t['online'],
                    'yesterday_amount' => (float)$y['amt'], 'yesterday_bills' => (int)$y['bills']],
                'by_person' => $by, 'week' => $week,
                'remarks' => $rem, 'remarks_total' => $nrem, 'store_registered' => $storeOffice > 0, 'customers' => $cust,
            ]);
            break;
        }

        // ====================================================== label size
        case 'st_set.get': {
            require_auth();
            $w = (float)setting_get($pdo, 'st_label_w', 0); $h = (float)setting_get($pdo, 'st_label_h', 0); $from = 'saved here';
            if ($w < 15 || $h < 10) {      // the POS keeps its label size in pos_settings (same database): use it, so both print on the same roll
                $w = $h = 0; $from = 'POS settings';
                try { $q = $pdo->prepare("SELECT svalue FROM pos_settings WHERE skey = ?"); $q->execute(['label_w']); $w = (float)$q->fetchColumn(); $q->execute(['label_h']); $h = (float)$q->fetchColumn(); } catch (Throwable $e) { $w = $h = 0; }
            }
            if ($w < 15 || $h < 10) { $w = 31.0; $h = 21.0; $from = 'default 31 x 21 mm'; }
            json_response(['label_w' => $w, 'label_h' => $h, 'from' => $from]);
            break;
        }

        case 'st_set.save': {
            $in = json_input();
            $w = (float)($in['label_w'] ?? 0); $h = (float)($in['label_h'] ?? 0);
            if ($w < 15 || $w > 200 || $h < 10 || $h > 200) json_response(['error' => 'Label size must be between 15 and 200 mm wide and 10 and 200 mm tall.'], 400);
            setting_set($pdo, 'st_label_w', $w); setting_set($pdo, 'st_label_h', $h);
            json_response(['ok' => true]);
            break;
        }
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
}
