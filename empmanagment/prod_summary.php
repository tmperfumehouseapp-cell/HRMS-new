<?php
/**
 * prod_summary.php — 🧴 Shower gel and ⭐ TM Originals (Dress to Impress) summary of the Orders page.   (action "psum.day", routed from api.php)
 *
 * Read-only. Nothing here changes how an order is counted: these numbers are a VIEW INSIDE the total collection, not a second collection.
 *   - shower gel: the orders that carry shower gel, its pieces and its money (shop_orders.xparts.gel, written when the order is read), gel-only or with perfume, Query / Campaign;
 *     and the shower gel QUERIES: customers whose WhatsApp message in those days speaks of shower gel (the ad's ready-made first message: "… 2 Shower gels combo offer …"),
 *     how many of them are new numbers and how many ordered shower gel.
 *   - TM Originals: orders, bottles, set of 3 / set of 2 / single (the same rule as the Dress to Impress box) and the value of those orders.
 * Cancelled orders and RTO resend / Exchange orders are left out. PHP 7.4 safe.
 */
if (!function_exists('psum_route')) {

/** all 3 perfumes = a set of 3, any 2 = a set of 2, one = single (quantity 2 of each = 2 sets) — the rule of dtiSplit() on the Orders page */
function psum_sets(array $d): array
{
    $q = ['ds' => (int)($d['ds'] ?? 0), 'cg' => (int)($d['cg'] ?? 0), 'cc' => (int)($d['cc'] ?? 0)];
    $s3 = min($q['ds'], $q['cg'], $q['cc']); foreach ($q as $k => $v) $q[$k] = $v - $s3;
    $s2 = 0;
    for ($g = 0; $g < 500; $g++) { arsort($q); $k = array_keys(array_filter($q, function ($v) { return $v > 0; })); if (count($k) < 2) break; $q[$k[0]]--; $q[$k[1]]--; $s2++; }
    return ['s3' => $s3, 's2' => $s2, 's1' => array_sum($q)];
}
function psum_p10($p): string { $d = preg_replace('/\D+/', '', (string)$p); return strlen($d) >= 10 ? substr($d, -10) : ''; }
/** does this text speak of shower gel? ("shower gel", "showr gels", "showergel", "body wash") */
function psum_is_gel(string $t): bool { return (bool)preg_match('/sho?we?r\s*gel|body\s*wash/iu', $t); }

function psum_day(PDO $pdo, string $from, string $to): array
{
    [$ws, $we] = biz_window($pdo, $from, $to);
    $gel = ['orders' => 0, 'pcs' => 0, 'amt' => 0.0, 'only' => 0, 'with_perfume' => 0, 'query' => 0, 'campaign' => 0, 'other' => 0, 'order_value' => 0.0];
    $org = ['orders' => 0, 'bottles' => 0, 's3' => 0, 's2' => 0, 's1' => 0, 'ds' => 0, 'cg' => 0, 'cc' => 0, 'order_value' => 0.0, 'query' => 0, 'campaign' => 0, 'other' => 0];
    $all = ['orders' => 0, 'amt' => 0.0]; $gelPhones = [];
    $cancel = function_exists('order_cancels_map') ? order_cancels_map($pdo) : [];
    $type = [];
    try {
        $q = $pdo->prepare("SELECT a.source, a.order_id, a.order_type FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND o.shopify_id = a.order_id WHERE o.created_at >= ? AND o.created_at <= ?"); $q->execute([$ws, $we]);
        foreach ($q->fetchAll() as $a) $type[$a['source'] . '|' . $a['order_id']] = strtolower((string)$a['order_type']);
    } catch (Throwable $e) { }
    $q = $pdo->prepare("SELECT shopify_id, store_key, phone, total, ml_total, dti, xparts, tags FROM shop_orders WHERE created_at >= ? AND created_at <= ?"); $q->execute([$ws, $we]);
    foreach ($q->fetchAll() as $o) {
        $k = $o['store_key'] . '|' . $o['shopify_id'];
        if (isset($cancel[$k]) || stripos((string)$o['tags'], 'wa-rx') !== false) continue;
        $tot = (float)$o['total']; $all['orders']++; $all['amt'] += $tot;
        $ty = $type[$k] ?? ''; $tk = strpos($ty, 'campaign') !== false || strpos($ty, 'community') !== false ? 'campaign' : ($ty !== '' ? 'query' : 'other');
        $xp = json_decode((string)($o['xparts'] ?? ''), true); $g = is_array($xp) && isset($xp['gel']) && is_array($xp['gel']) ? $xp['gel'] : null;
        if ($g && (int)($g['pcs'] ?? 0) > 0) {
            $gel['orders']++; $gel['pcs'] += (int)$g['pcs']; $gel['amt'] += (float)($g['amt'] ?? 0); $gel['order_value'] += $tot; $gel[$tk]++;
            if ((int)$o['ml_total'] > 0) $gel['with_perfume']++; else $gel['only']++;
            $p = psum_p10($o['phone']); if ($p !== '') $gelPhones[$p] = 1;
        }
        $d = json_decode((string)($o['dti'] ?? ''), true);
        if (is_array($d) && ((int)($d['ds'] ?? 0) + (int)($d['cg'] ?? 0) + (int)($d['cc'] ?? 0)) > 0) {
            $s = psum_sets($d); $org['orders']++; $org['order_value'] += $tot; $org[$tk]++;
            foreach (['s3', 's2', 's1'] as $x) $org[$x] += $s[$x];
            foreach (['ds', 'cg', 'cc'] as $x) { $org[$x] += (int)($d[$x] ?? 0); $org['bottles'] += (int)($d[$x] ?? 0); }
        }
    }
    // 💬 shower gel queries: customers who wrote about shower gel in these days (the chats that were active are looked at, each through its own message index)
    $qr = ['asked' => 0, 'new' => 0, 'ordered' => 0, 'ok' => true];
    try {
        $st = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(15000) */ c.phone, c.first_seen FROM wa_contacts c WHERE c.last_in_at >= ? AND EXISTS (SELECT 1 FROM wa_messages m WHERE m.phone = c.phone AND m.dir = 'in' AND m.ts >= ? AND m.ts <= ? AND (LOWER(m.body) REGEXP 'sho?we?r ?gel' OR LOWER(m.body) LIKE '%body wash%'))");
        $st->execute([$ws, $ws, $we]);
        foreach ($st->fetchAll() as $c) { $qr['asked']++; if ((string)$c['first_seen'] >= $ws && (string)$c['first_seen'] <= $we) $qr['new']++; if (isset($gelPhones[psum_p10($c['phone'])])) $qr['ordered']++; }
    } catch (Throwable $e) { $qr['ok'] = false; }
    $gel['amt'] = round($gel['amt'], 2); $gel['order_value'] = round($gel['order_value'], 2); $org['order_value'] = round($org['order_value'], 2); $all['amt'] = round($all['amt'], 2);
    return ['from' => $from, 'to' => $to, 'gel' => $gel, 'gel_queries' => $qr, 'originals' => $org, 'all' => $all];
}

function psum_route(PDO $pdo, string $action): void
{
    $auth = require_auth();
    if (!(in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true) || (function_exists('user_can') && user_can($auth, 'orders')))) json_response(['error' => 'This summary is for Admin, HR and roles with the Orders page.'], 403);
    if ($action === 'psum.day') {
        $ok = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
        $from = $ok($_GET['from'] ?? null) ? (string)$_GET['from'] : biz_today($pdo); $to = $ok($_GET['to'] ?? null) ? (string)$_GET['to'] : $from; if ($to < $from) $to = $from;
        if ((strtotime($to) - strtotime($from)) > 62 * 86400) $from = date('Y-m-d', strtotime($to) - 62 * 86400);
        $run = function () use ($pdo, $from, $to) { return psum_day($pdo, $from, $to); };
        json_response(['ok' => true] + (function_exists('hrms_memo') ? hrms_memo('psum:' . $from . ':' . $to, 90, $run) : $run()));
    }
    json_response(['error' => 'Unknown action.'], 404);
}

}
