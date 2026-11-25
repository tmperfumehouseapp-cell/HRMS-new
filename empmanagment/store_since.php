<?php
/**
 * Store → "Since the start": how many orders, bottles, ml and ₹ the retail store has sold, month by month, from the first bill to today.
 * Two sources, never counted twice:
 *   the OLD POS   (pos_orders + pos_order_items, kept in this database)
 *   the NEW store billing (st_bills): from the day it was really used (setting st_newpos_from, default 2026-11-05) a day that has a new bill counts ONLY the new bills;
 *   before that day a day counts BOTH, and a day with no new bill counts the old POS (the same rule as the commission).
 * Bottles / ml of the old POS are read from the item (variant or title "50 ml"); a bottle whose size is not written counts as a bottle but adds no ml (shown as "ml not read").
 * Testers are not counted. Cancelled new bills are not counted.
 */

function ss_report(PDO $pdo, ?string $from, ?string $to): array
{
    $h = function_exists('st_day_hour') ? st_day_hour() : 3; $today = function_exists('st_day') ? st_day() : date('Y-m-d');
    $to = ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ? $to : $today; $from = ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : '2000-01-01';
    if ($from > $to) { $x = $from; $from = $to; $to = $x; }
    $newFrom = function_exists('st_newpos_from') ? st_newpos_from($pdo) : '2026-11-05';
    $M = []; $first = ''; $noMl = 0;
    $add = function (string $day, string $src, int $orders, int $bottles, int $ml, float $rs) use (&$M, &$first) {
        $m = substr($day, 0, 7); if (!isset($M[$m])) $M[$m] = ['month' => $m, 'orders' => 0, 'bottles' => 0, 'ml' => 0, 'rs' => 0.0, 'old' => 0, 'new' => 0];
        $M[$m]['orders'] += $orders; $M[$m]['bottles'] += $bottles; $M[$m]['ml'] += $ml; $M[$m]['rs'] += $rs; $M[$m][$src] += $orders;
        if ($first === '' || $day < $first) $first = $day;
    };
    // new billing
    $newDays = [];
    try {
        if (function_exists('ensure_st_tables')) ensure_st_tables($pdo);
        $q = $pdo->prepare("SELECT biz_date, total_ml, bottles, total FROM st_bills WHERE status = 'ok' AND biz_date BETWEEN ? AND ?"); $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $b) { $d = (string)$b['biz_date']; if ($d >= $newFrom) $newDays[$d] = 1; $add($d, 'new', 1, (int)$b['bottles'], (int)$b['total_ml'], (float)$b['total']); }
    } catch (Throwable $e) { error_log('ss_report new: ' . $e->getMessage()); }
    // old POS
    try {
        $dd = "DATE(DATE_SUB(o.created_at, INTERVAL $h HOUR))";
        $lo = $from . sprintf(' %02d:00:00', $h); $hi = date('Y-m-d', strtotime($to . ' +1 day')) . sprintf(' %02d:00:00', $h);
        $q = $pdo->prepare("SELECT o.id, $dd d, o.total FROM pos_orders o WHERE o.created_at >= ? AND o.created_at < ?"); $q->execute([$lo, $hi]); $ord = [];
        foreach ($q->fetchAll() as $o) $ord[(int)$o['id']] = ['d' => (string)$o['d'], 'total' => (float)$o['total'], 'b' => 0, 'ml' => 0, 'nm' => 0];
        if ($ord) {
            $qi = $pdo->prepare("SELECT oi.order_id, oi.variant_title vt, oi.title t, oi.qty q FROM pos_order_items oi JOIN pos_orders o ON o.id = oi.order_id WHERE o.created_at >= ? AND o.created_at < ?"); $qi->execute([$lo, $hi]);
            foreach ($qi->fetchAll() as $r) {
                $id = (int)$r['order_id']; if (!isset($ord[$id])) continue; $qty = max(0, (int)$r['q']);
                if (stripos((string)$r['t'], 'tester') !== false || stripos((string)$r['vt'], 'tester') !== false) continue;
                $ord[$id]['b'] += $qty;
                if (preg_match('/(\d+)\s*ml/i', (string)$r['vt'], $m) || preg_match('/(\d+)\s*ml/i', (string)$r['t'], $m)) $ord[$id]['ml'] += (int)$m[1] * $qty; else $ord[$id]['nm'] += $qty;
            }
            foreach ($ord as $o) { if (isset($newDays[$o['d']])) continue; $add($o['d'], 'old', 1, $o['b'], $o['ml'], $o['total']); $noMl += $o['nm']; }
        }
    } catch (Throwable $e) { error_log('ss_report old: ' . $e->getMessage()); }
    // a plain look at the two sources (the whole tables, not the range): where each starts and ends, orders with 0 rupees, and days with NO order between the first and the last order
    $diag = ['old' => null, 'new' => null];
    try {
        $r = $pdo->query("SELECT MIN(created_at) f, MAX(created_at) l, COUNT(*) n, COALESCE(SUM(total = 0), 0) z, COALESCE(SUM(total), 0) amt FROM pos_orders")->fetch();
        if ($r && (int)$r['n'] > 0) {
            $days = []; foreach ($pdo->query("SELECT DATE(DATE_SUB(created_at, INTERVAL $h HOUR)) d, COUNT(*) c FROM pos_orders GROUP BY d")->fetchAll() as $x) $days[(string)$x['d']] = (int)$x['c'];
            $dmin = min(array_keys($days)); $dmax = max(array_keys($days)); $gaps = []; $run = null;
            for ($t = strtotime($dmin); $t <= strtotime($dmax); $t += 86400) { $d = date('Y-m-d', $t); if (!isset($days[$d])) { if ($run === null) $run = [$d, $d]; else $run[1] = $d; } elseif ($run !== null) { if ((strtotime($run[1]) - strtotime($run[0])) / 86400 >= 2) $gaps[] = $run[0] . ' → ' . $run[1]; $run = null; } }
            $diag['old'] = ['first' => $dmin, 'last' => $dmax, 'orders' => (int)$r['n'], 'zero_total' => (int)$r['z'], 'sum' => round((float)$r['amt'], 2), 'gaps' => array_slice($gaps, 0, 15), 'gap_count' => count($gaps)];
        }
    } catch (Throwable $e) { $diag['old'] = ['error' => 'The old POS table could not be read.']; }
    try {
        $r = $pdo->query("SELECT MIN(biz_date) f, MAX(biz_date) l, SUM(status = 'ok') n, SUM(status <> 'ok') v, COALESCE(SUM(CASE WHEN status = 'ok' THEN total END), 0) amt, COALESCE(SUM(status = 'ok' AND total = 0), 0) z FROM st_bills")->fetch();
        if ($r && ((int)$r['n'] + (int)$r['v']) > 0) $diag['new'] = ['first' => (string)$r['f'], 'last' => (string)$r['l'], 'orders' => (int)$r['n'], 'cancelled' => (int)$r['v'], 'zero_total' => (int)$r['z'], 'sum' => round((float)$r['amt'], 2)];
    } catch (Throwable $e) { $diag['new'] = ['error' => 'The new billing table could not be read.']; }
    krsort($M); $rows = array_values($M);
    $tot = ['orders' => 0, 'bottles' => 0, 'ml' => 0, 'rs' => 0.0];
    foreach ($rows as &$r) { $r['rs'] = round($r['rs'], 2); $r['from'] = $r['old'] > 0 && $r['new'] > 0 ? 'both' : ($r['new'] > 0 ? 'new' : 'old'); foreach (['orders', 'bottles', 'ml'] as $k) $tot[$k] += $r[$k]; $tot['rs'] += $r['rs']; } unset($r);
    $tot['rs'] = round($tot['rs'], 2);
    return ['rows' => $rows, 'total' => $tot, 'first_day' => $first, 'from' => $from, 'to' => $to, 'ml_not_read' => $noMl, 'new_from' => $newFrom, 'check' => $diag];
}

function ss_handle(PDO $pdo): void
{
    $auth = require_auth();
    if (!in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) && !user_can($auth, 'rs_manage')) json_response(['error' => 'Only the store manager can open this report.'], 403);
    json_response(ss_report($pdo, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')));
}
