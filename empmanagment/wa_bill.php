<?php
/**
 * wa_bill.php — 💰 Billing & spend (WhatsApp → Results → Billing). Admin only.
 *   wa.bill        GET from, to (Y-m-d), by = day | month, src = all | campaign | followup | order | flow | other
 *                  → what the paid WhatsApp messages (templates) cost per day / month and what they brought back:
 *                    sent, delivered, read, failed, paid marketing / utility, free, cost (₹, per source), replies within 2 days,
 *                    orders within 7 days (Shopify, matched by phone, credited to the LAST template that customer got before ordering), revenue,
 *                    return per ₹1 and cost per order; best / least effective day; every campaign in the range; Meta's own figures when Meta gives them.
 * How Meta bills: one charge per template message DELIVERED (marketing, utility, authentication; utility inside an open 24-hour chat is free).
 * The exact category / free flag Meta sends with each status (pricing) is kept on the message by the webhook (wa.php → wa_messages.bill_cat / billable);
 * older messages are estimated: order updates and the utility versions of campaigns = utility, everything else = marketing.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wab_report')) {

function wab_cols(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    foreach (['bill_cat' => "VARCHAR(16) NOT NULL DEFAULT ''", 'billable' => 'TINYINT NULL'] as $c => $def) {
        try { $pdo->query("SELECT $c FROM wa_messages LIMIT 0"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_messages ADD COLUMN $c $def"); } catch (PDOException $e2) { return $ok = false; } }
    }
    return $ok = true;
}
function wab_rates(PDO $pdo): array
{
    $c = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : [];
    $m = (float)($c['rate_marketing'] ?? 1.09); $u = (float)($c['rate_utility'] ?? 0.15);
    return ['marketing' => $m, 'utility' => $u, 'authentication' => $u, 'marketing_lite' => $m, 'service' => 0.0];
}
/** Meta's own cost per day (pricing analytics of every WhatsApp account), cached for an hour. ['days' => [Y-m-d => ₹], 'error' => ''] */
function wab_meta(PDO $pdo, string $from, string $to): array
{
    $key = 'wab_meta_' . md5($from . $to); $c = null; try { $c = json_decode((string)setting_get($pdo, $key, ''), true); } catch (Throwable $e) { }
    if (is_array($c) && (int)($c['at'] ?? 0) > time() - 3600) return $c;
    $o = ['at' => time(), 'days' => [], 'error' => '', 'currency' => ''];
    $tok = function_exists('wa_cfg_val') ? wa_cfg_val('WA_TOKEN') : ''; $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : [];
    if ($tok === '' || !$wabas || !function_exists('wa_http')) { $o['error'] = 'No WhatsApp account is connected.'; return $o; }
    $s = strtotime($from . ' 00:00:00'); $e = min(time(), strtotime($to . ' 23:59:59') + 1); $got = false;
    foreach ($wabas as $w) {
        $f = 'pricing_analytics.start(' . $s . ').end(' . $e . ').granularity(DAILY).dimensions(["PRICING_CATEGORY","PRICING_TYPE"])';
        try { [$code, $d] = wa_http('GET', 'https://graph.facebook.com/' . (function_exists('wa_graph') ? wa_graph() : 'v21.0') . '/' . rawurlencode($w) . '?fields=' . rawurlencode($f), ['Authorization: Bearer ' . $tok]); } catch (Throwable $ex) { $code = 0; $d = null; }
        if ($code < 200 || $code >= 300 || !is_array($d)) { $o['error'] = 'Meta did not share its cost figures' . (is_array($d) && !empty($d['error']['message']) ? ': ' . mb_substr((string)$d['error']['message'], 0, 160) : ' (HTTP ' . $code . ')'); continue; }
        foreach ((array)($d['pricing_analytics']['data'] ?? []) as $blk) foreach ((array)($blk['data_points'] ?? []) as $p) {
            $day = date('Y-m-d', (int)($p['start'] ?? 0)); $o['days'][$day] = round(($o['days'][$day] ?? 0) + (float)($p['cost'] ?? 0), 2); $got = true;
        }
    }
    if ($got) $o['error'] = '';
    try { setting_set($pdo, $key, json_encode($o)); } catch (Throwable $e2) { }
    return $o;
}
function wab_src(string $who): string { return in_array($who, ['campaign', 'followup', 'order', 'flow'], true) ? $who : 'other'; }

function wab_report(PDO $pdo, string $from, string $to, string $by = 'day', string $src = 'all'): array
{
    $hasCols = wab_cols($pdo); $R = wab_rates($pdo); $by = $by === 'month' ? 'month' : 'day';
    $f0 = $from . ' 00:00:00'; $t0 = $to . ' 23:59:59';
    $sql = "SELECT m.phone, m.status, m.who, m.ts, " . ($hasCols ? 'm.bill_cat, m.billable' : "'' AS bill_cat, NULL AS billable") . ", COALESCE(i.camp_id, 0) AS camp_id, COALESCE(i.variant, '') AS variant
            FROM wa_messages m LEFT JOIN wa_camp_items i ON i.wamid = m.wamid AND m.wamid <> '' WHERE m.dir = 'out' AND m.mtype = 'template' AND m.ts BETWEEN ? AND ? ORDER BY m.ts";
    try { $q = $pdo->prepare($sql); $q->execute([$f0, $t0]); $msgs = $q->fetchAll(); }
    catch (PDOException $e) { $q = $pdo->prepare(str_replace(["LEFT JOIN wa_camp_items i ON i.wamid = m.wamid AND m.wamid <> '' ", 'COALESCE(i.camp_id, 0)', "COALESCE(i.variant, '')"], ['', '0', "''"], $sql)); $q->execute([$f0, $t0]); $msgs = $q->fetchAll(); }
    if ($src !== 'all') $msgs = array_values(array_filter($msgs, fn($m) => wab_src((string)$m['who']) === $src));
    $exact = 0;
    // replies: the customer wrote within 2 days after the message
    $in = []; $q = $pdo->prepare("SELECT phone, ts FROM wa_messages WHERE dir = 'in' AND ts BETWEEN ? AND ? ORDER BY ts"); $q->execute([$f0, date('Y-m-d H:i:s', strtotime($t0) + 2 * 86400)]);
    foreach ($q->fetchAll() as $r) $in[(string)$r['phone']][] = strtotime((string)$r['ts']);
    // orders: within 7 days after a delivered message, credited to the last message before the order
    $byPhone = []; foreach ($msgs as $i => $m) { $msgs[$i]['t'] = strtotime((string)$m['ts']); $byPhone[(string)$m['phone']][] = $i; }
    $credit = [];
    try {
        $q = $pdo->prepare("SELECT phone, created_at, total, financial_status FROM shop_orders WHERE created_at BETWEEN ? AND ? AND phone <> ''"); $q->execute([$f0, date('Y-m-d H:i:s', strtotime($t0) + 7 * 86400)]);
        foreach ($q->fetchAll() as $o) {
            if (preg_match('/void|refund/i', (string)$o['financial_status'])) continue;
            $k = function_exists('wa_key') ? wa_key($o['phone']) : substr(preg_replace('/\D/', '', (string)$o['phone']), -10); if (!isset($byPhone[$k])) continue; $ot = strtotime((string)$o['created_at']); $best = -1;
            foreach ($byPhone[$k] as $i) { $m = $msgs[$i]; if (!in_array($m['status'], ['delivered', 'read'], true)) continue; if ($m['t'] < $ot && $m['t'] >= $ot - 7 * 86400) $best = $i; }
            if ($best >= 0) { $credit[$best]['n'] = ($credit[$best]['n'] ?? 0) + 1; $credit[$best]['rev'] = ($credit[$best]['rev'] ?? 0) + (float)$o['total']; }
        }
    } catch (PDOException $e) { }
    $blank = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'paid_m' => 0, 'paid_u' => 0, 'free' => 0, 'cost' => 0.0, 'cost_src' => ['campaign' => 0.0, 'followup' => 0.0, 'order' => 0.0, 'flow' => 0.0, 'other' => 0.0], 'replied' => 0, 'orders' => 0, 'revenue' => 0.0];
    $rows = []; $camps = []; $srcT = []; $tot = $blank;
    $add = function (array &$a, array $x) { foreach ($x as $k => $v) { if ($k === 'cost_src') { foreach ($v as $s => $c) $a['cost_src'][$s] += $c; } else $a[$k] += $v; } };
    foreach ($msgs as $i => $m) {
        $st = (string)$m['status']; $dl = in_array($st, ['delivered', 'read'], true); $s = wab_src((string)$m['who']);
        $cat = strtolower(trim((string)$m['bill_cat'])); if ($cat !== '') $exact++;
        if ($cat === '') $cat = ($s === 'order' || $m['variant'] === 'U') ? 'utility' : 'marketing';
        $free = $m['billable'] !== null && (int)$m['billable'] === 0; $paid = $dl && !$free; $cost = $paid ? (float)($R[$cat] ?? $R['marketing']) : 0.0;
        $rep = 0; foreach ($in[(string)$m['phone']] ?? [] as $t) { if ($t > $m['t'] && $t <= $m['t'] + 2 * 86400) { $rep = 1; break; } }
        $x = ['sent' => 1, 'delivered' => $dl ? 1 : 0, 'read' => $st === 'read' ? 1 : 0, 'failed' => $st === 'failed' ? 1 : 0, 'paid_m' => $paid && !in_array($cat, ['utility', 'authentication'], true) ? 1 : 0, 'paid_u' => $paid && in_array($cat, ['utility', 'authentication'], true) ? 1 : 0,
            'free' => $dl && !$paid ? 1 : 0, 'cost' => $cost, 'cost_src' => [$s => $cost], 'replied' => $rep, 'orders' => (int)($credit[$i]['n'] ?? 0), 'revenue' => (float)($credit[$i]['rev'] ?? 0)];
        $x['cost_src'] += ['campaign' => 0.0, 'followup' => 0.0, 'order' => 0.0, 'flow' => 0.0, 'other' => 0.0];
        $key = $by === 'month' ? substr((string)$m['ts'], 0, 7) : substr((string)$m['ts'], 0, 10);
        if (!isset($rows[$key])) $rows[$key] = $blank; $add($rows[$key], $x); $add($tot, $x);
        if (!isset($srcT[$s])) $srcT[$s] = $blank; $add($srcT[$s], $x);
        if ((int)$m['camp_id']) { $cid = (int)$m['camp_id']; if (!isset($camps[$cid])) $camps[$cid] = $blank + ['first' => (string)$m['ts']]; $add($camps[$cid], $x); }
    }
    $fin = function (array $a) { $a['cost'] = round($a['cost'], 2); foreach ($a['cost_src'] as $k => $v) $a['cost_src'][$k] = round($v, 2); $a['revenue'] = round($a['revenue']);
        $a['roas'] = $a['cost'] > 0 ? round($a['revenue'] / $a['cost'], 1) : null; $a['cpo'] = $a['orders'] > 0 ? round($a['cost'] / $a['orders'], 2) : null;
        $a['reply_pct'] = $a['delivered'] > 0 ? round(100 * $a['replied'] / $a['delivered'], 1) : null; $a['read_pct'] = $a['delivered'] > 0 ? round(100 * $a['read'] / $a['delivered'], 1) : null; return $a; };
    // every day / month of the range is shown, also the quiet ones
    $list = []; $cur = strtotime($from . ' 00:00:00'); $end = strtotime($to . ' 00:00:00'); $seen = [];
    while ($cur <= $end) { $k = $by === 'month' ? date('Y-m', $cur) : date('Y-m-d', $cur); if (!isset($seen[$k])) { $seen[$k] = 1; $list[] = ['key' => $k] + $fin($rows[$k] ?? $blank); } $cur = strtotime('+1 day', $cur); }
    // best / least effective: among the rows that really spent (at least ₹20 or a tenth of the average spend)
    $spent = array_values(array_filter($list, fn($r) => $r['cost'] > 0)); $avg = $spent ? array_sum(array_column($spent, 'cost')) / count($spent) : 0;
    $cand = array_values(array_filter($spent, fn($r) => $r['cost'] >= max(20, $avg * 0.1))); $best = $worst = null;
    if ($cand) {
        usort($cand, fn($a, $b) => [($b['revenue'] / $b['cost']), $b['orders'], $a['cost']] <=> [($a['revenue'] / $a['cost']), $a['orders'], $b['cost']]); $best = $cand[0];
        $w = $cand; usort($w, fn($a, $b) => [($a['revenue'] / $a['cost']), -$a['cost']] <=> [($b['revenue'] / $b['cost']), -$b['cost']]); $worst = count($cand) > 1 ? $w[0] : null;
    }
    $cl = [];
    if ($camps) {
        $ids = array_keys($camps); $nm = [];
        try { $q = $pdo->prepare("SELECT id, name, status FROM wa_campaigns WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $q->execute($ids); foreach ($q->fetchAll() as $r) $nm[(int)$r['id']] = $r; } catch (PDOException $e) { }
        foreach ($camps as $id => $c) $cl[] = ['id' => $id, 'name' => (string)($nm[$id]['name'] ?? ('Campaign #' . $id)), 'status' => (string)($nm[$id]['status'] ?? ''), 'first' => $c['first']] + $fin($c);
        usort($cl, fn($a, $b) => strcmp($b['first'], $a['first']));
    }
    $sl = []; foreach (['campaign' => 'Campaigns', 'followup' => 'Follow-ups', 'order' => 'Order updates', 'flow' => 'Flows', 'other' => 'Other templates'] as $k => $t) if (isset($srcT[$k])) $sl[] = ['key' => $k, 'label' => $t] + $fin($srcT[$k]);
    return ['ok' => true, 'from' => $from, 'to' => $to, 'by' => $by, 'src' => $src, 'rates' => $R, 'rows' => $list, 'total' => $fin($tot), 'best' => $best, 'worst' => $worst, 'campaigns' => $cl, 'sources' => $sl,
        'exact_share' => $msgs ? round(100 * $exact / count($msgs)) : 0];
}

}   // function_exists guard

$__wab = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wab === 'wa.bill') {
    $auth = require_auth();
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if (function_exists('wa_more_tables')) { try { wa_more_tables($pdo); } catch (Throwable $e) { } }
    @set_time_limit(120);
    $d = fn($v, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime((string)$v) ? (string)$v : $def;
    $to = $d($_GET['to'] ?? '', date('Y-m-d')); $from = $d($_GET['from'] ?? '', date('Y-m-01', strtotime($to)));
    if ($from > $to) [$from, $to] = [$to, $from];
    if (strtotime($to) - strtotime($from) > 731 * 86400) $from = date('Y-m-d', strtotime($to) - 731 * 86400);   // two years at most
    $src = in_array($_GET['src'] ?? 'all', ['all', 'campaign', 'followup', 'order', 'flow', 'other'], true) ? (string)$_GET['src'] : 'all';
    $r = wab_report($pdo, $from, $to, (string)($_GET['by'] ?? 'day'), $src);
    if (!empty($_GET['meta'])) { $m = wab_meta($pdo, $from, $to); $r['meta'] = ['error' => $m['error'], 'total' => round(array_sum($m['days']), 2), 'days' => $m['days']]; }
    json_response($r);
}
