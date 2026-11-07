<?php
/**
 * wa_sales.php — the WhatsApp Sales section (Board, Give queries, Focus). Loaded by wa_more.php (no change to api.php).
 * It works ONLY on the new-number WhatsApp data (wa_contacts / wa_messages / wa_assign_log). It never reads or writes the AiSensy sales tables
 * (employee_query_log, campaign_queries, qb_plan …), so the existing Live / Give queries / Boards keep working exactly as before.
 * The only outside data it reads is Shopify orders (shop_orders), matched on the customer's last 10 digits.
 *
 *   wa.sales_board  GET {days: 1|7|30}   per person: queries, orders, conversion, vs team, goal, community/campaign, reply speed    [Admin / HR]
 *   wa.sales_give   GET                  waiting-to-be-assigned chats + the recommended person for each                          [Admin / HR]
 *   wa.sales_focus  GET                  what needs attention right now (unassigned too long, unanswered too long, call-now)      [Admin / HR]
 *   wa.sales_cfg    POST {goal, min_q, focus_min}   the numbers the Board and Focus use                                           [Admin / HR]
 */
if (!function_exists('wa_sales_cfg')) {
function wa_sales_cfg(PDO $pdo): array
{
    // "late" is the one panel-wide limit (Signals alert minutes); the goal stays the query → order % goal
    $g = (int)setting_get($pdo, 'wa_sales_goal', 23); $m = (int)setting_get($pdo, 'wa_sales_min_q', 5);
    $f = function_exists('wq_late_min') ? wq_late_min($pdo) : (function_exists('wag_cfg') ? (int)wag_cfg($pdo)['unans_min'] : (int)setting_get($pdo, 'wa_sales_focus_min', 10));
    return ['goal' => $g > 0 ? min(100, $g) : 23, 'min_q' => $m > 0 ? min(500, $m) : 5, 'focus_min' => $f > 0 ? min(1440, $f) : 10];
}
/** The period start: 1 = since midnight today, otherwise the last N days. */
function wa_sales_since(int $days): string { return $days <= 1 ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', time() - $days * 86400); }
/** Shopify orders since $since, by customer (last 10 digits): [key => [[created_at, total], …]]. Cancelled / refunded orders are not counted. */
function wa_sales_orders(PDO $pdo, string $since): array
{
    $o = [];
    try {
        $st = $pdo->prepare("SELECT SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), -10) AS k, created_at, total, financial_status FROM shop_orders WHERE created_at >= ? AND phone <> '' ORDER BY created_at LIMIT 100000");
        $st->execute([date('Y-m-d H:i:s', strtotime($since) - 86400)]);
        foreach ($st->fetchAll() as $r) {
            $fs = strtolower((string)$r['financial_status']); if ($fs === 'voided' || $fs === 'refunded') continue;
            $k = (string)$r['k']; if (strlen($k) === 10) $o[$k][] = [(string)$r['created_at'], (float)$r['total']];
        }
    } catch (Throwable $e) { }
    return $o;
}
function wa_sales_board(PDO $pdo, int $days): array
{
    $since = wa_sales_since($days); $cfg = wa_sales_cfg($pdo); $orders = wa_sales_orders($pdo, $since);
    $people = wa_people($pdo, 0); $stats = function_exists('wa_person_stats') ? wa_person_stats($pdo) : [];
    // every chat a person received in the period (first time given to them), with the customer's feed
    $first = [];   // emp => [phone => first assigned at]
    $q = $pdo->prepare("SELECT assigned_emp AS to_emp, phone, assigned_at AS at, source FROM wa_contacts WHERE assigned_emp > 0 AND assigned_at >= ?");   // counted by who holds the chat NOW: moving a chat from A to B takes 1 off A and gives 1 to B
    $q->execute([$since]);
    foreach ($q->fetchAll() as $r) $first[(int)$r['to_emp']][(string)$r['phone']] = [(string)$r['at'], (string)$r['source']];
    $wait = $pdo->query("SELECT assigned_emp, COUNT(*) AS n FROM wa_contacts WHERE assigned_emp > 0 AND status = 'open' AND last_in_at IS NOT NULL AND optout = 0 AND (last_out_at IS NULL OR last_out_at < last_in_at) GROUP BY assigned_emp")->fetchAll();
    $waiting = []; foreach ($wait as $w) $waiting[(int)$w['assigned_emp']] = (int)$w['n'];
    $rows = []; $tq = 0; $to = 0;
    foreach ($people as $p) {
        $e = (int)$p['emp_id']; $mine = $first[$e] ?? []; $qn = 0; $on = 0; $rev = 0.0; $cm = 0; $cp = 0;
        foreach ($mine as $ph => [$at, $src]) {
            if ($src === 'community') { $cm++; continue; }
            if ($src === 'campaign') { $cp++; continue; }
            if ($src !== 'query') continue;
            $qn++; $got = false;
            foreach ($orders[$ph] ?? [] as [$oat, $tot]) if ($oat >= $at) { $got = true; $rev += $tot; }
            if ($got) $on++;
        }
        $tq += $qn; $to += $on;
        $rows[] = ['emp_id' => $e, 'name' => (string)$p['name'], 'present' => (bool)$p['present'], 'open' => (int)$p['open'], 'queries' => $qn, 'orders' => $on, 'revenue' => (int)round($rev), 'community' => $cm, 'campaign' => $cp,
                   'waiting' => $waiting[$e] ?? 0, 'median' => $stats[$e]['median'] ?? null];
    }
    $team = $tq > 0 ? $to / $tq * 100 : 0.0;
    foreach ($rows as &$r) {
        $r['pct'] = $r['queries'] > 0 ? round($r['orders'] / $r['queries'] * 100, 1) : 0.0;
        if ($r['queries'] < $cfg['min_q']) $r['level'] = 'few';
        elseif ($team <= 0) $r['level'] = $r['orders'] > 0 ? 'good' : 'low';
        elseif ($r['pct'] >= $team) $r['level'] = 'good';
        elseif ($r['pct'] >= $team * 0.6) $r['level'] = 'mid';
        else $r['level'] = 'low';
        $r['goal_pct'] = $cfg['goal'] > 0 ? (int)min(100, round($r['pct'] / $cfg['goal'] * 100)) : 0;
    } unset($r);
    usort($rows, fn($a, $b) => [$b['orders'], $b['pct'], $b['queries']] <=> [$a['orders'], $a['pct'], $a['queries']]);
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1; unset($r);
    return ['days' => $days, 'since' => $since, 'rows' => $rows, 'team' => ['queries' => $tq, 'orders' => $to, 'pct' => round($team, 1)], 'cfg' => $cfg,
            'tips' => wa_sales_tips($rows, $team, $cfg)];
}
/** Plain-language tips under the Board. */
function wa_sales_tips(array $rows, float $team, array $cfg): array
{
    $t = [];
    if (!$rows) return ['No sales people found. They are the employees in a department with “sales” in its name.'];
    $act = array_filter($rows, fn($r) => $r['queries'] >= $cfg['min_q']);
    if (!$act) { $t[] = 'Not enough queries yet in this period to compare people (needs at least ' . $cfg['min_q'] . ' each).'; return $t; }
    $low = array_values(array_filter($act, fn($r) => $r['level'] === 'low'));
    if ($low) $t[] = implode(', ', array_map(fn($r) => $r['name'], array_slice($low, 0, 3))) . ' convert well below the team (' . round($team, 1) . '%). Read a few of their lost chats together.';
    if ($team > 0 && $team < $cfg['goal']) $t[] = 'The team converts ' . round($team, 1) . '% — the goal is ' . $cfg['goal'] . '%. Faster first replies help most.';
    elseif ($team >= $cfg['goal']) $t[] = 'The team is at or above the ' . $cfg['goal'] . '% goal. 🎉';
    $slow = array_values(array_filter($rows, fn($r) => $r['waiting'] >= 3));
    if ($slow) $t[] = implode(', ', array_map(fn($r) => $r['name'] . ' (' . $r['waiting'] . ')', array_slice($slow, 0, 3))) . ' have customers waiting for a reply right now.';
    return $t;
}
function wa_sales_mins(?string $at): int { $t = $at ? strtotime($at) : 0; return $t ? max(0, (int)floor((time() - $t) / 60)) : 0; }
}

$__ws = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__ws, ['wa.sales_board', 'wa.sales_give', 'wa.sales_focus', 'wa.sales_cfg'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    if (function_exists('wa_more_tables')) wa_more_tables($pdo);
    $names = employee_names_by_id($pdo);
    switch ($__ws) {
        case 'wa.sales_board': {
            $d = (int)($_GET['days'] ?? 7); if (!in_array($d, [1, 7, 30], true)) $d = 7;
            json_response(wa_sales_board($pdo, $d));
        }
        case 'wa.sales_cfg': {
            if (!$isPost) json_response(wa_sales_cfg($pdo));
            $in = json_input();
            foreach (['goal' => ['wa_sales_goal', 1, 100], 'min_q' => ['wa_sales_min_q', 1, 500], 'focus_min' => ['wa_sales_focus_min', 1, 1440]] as $k => [$key, $lo, $hi]) if (isset($in[$k])) setting_set($pdo, $key, (string)max($lo, min($hi, (int)$in[$k])));
            json_response(['ok' => true, 'cfg' => wa_sales_cfg($pdo)]);
        }
        case 'wa.sales_give': {
            $st = $pdo->query("SELECT c.*, (SELECT body FROM wa_messages m WHERE m.phone = c.phone AND m.dir = 'in' ORDER BY m.id DESC LIMIT 1) AS last_body FROM wa_contacts c WHERE c.assigned_emp = 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.status = 'open' AND c.last_in_at IS NOT NULL AND c.optout = 0 ORDER BY c.last_in_at LIMIT 80");
            $rows = $st->fetchAll(); $people = wa_people($pdo, 0); $items = [];
            foreach ($rows as $r) {
                $items[] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'source' => (string)$r['source'], 'ref_text' => (string)$r['ref_text'], 'preview' => mb_substr((string)$r['last_body'], 0, 120),
                            'waiting_min' => wa_sales_mins((string)$r['last_in_at']), 'tag_label' => WA_TAGS[$r['tag']] ?? '', 'number_id' => (int)$r['last_number_id']];
            }
            $best = null; foreach ($people as $p) if (!empty($p['best'])) { $best = $p; break; }
            json_response(['items' => $items, 'people' => $people, 'best' => $best ? ['emp_id' => $best['emp_id'], 'name' => $best['name'], 'why' => $best['why']] : null, 'cfg' => wa_sales_cfg($pdo), 'max_open' => function_exists('wa_team_cfg') ? (int)wa_team_cfg($pdo)['max_open'] : 30]);
        }
        case 'wa.sales_focus': {
            $cfg = wa_sales_cfg($pdo); $lim = $cfg['focus_min']; $out = ['unassigned' => [], 'unanswered' => [], 'callnow' => [], 'cfg' => $cfg];
            $q = $pdo->query("SELECT phone, name, source, last_in_at, assigned_emp, callnow, callnow_at, (SELECT body FROM wa_messages m WHERE m.phone = wa_contacts.phone AND m.dir = 'in' ORDER BY m.id DESC LIMIT 1) AS last_body
                              FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 300");
            foreach ($q->fetchAll() as $r) {
                $m = wa_sales_mins((string)$r['last_in_at']); $e = (int)$r['assigned_emp'];
                $row = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'source' => (string)$r['source'], 'minutes' => $m, 'person' => $e > 0 ? ($names[$e] ?? 'Someone') : '', 'preview' => mb_substr((string)$r['last_body'], 0, 100)];
                if ($e === 0 && $m >= $lim) $out['unassigned'][] = $row;
                elseif ($e > 0 && $m >= $lim) $out['unanswered'][] = $row;
            }
            $q = $pdo->query("SELECT phone, name, assigned_emp, callnow, callnow_at, summary FROM wa_contacts WHERE callnow IN (1, 2) AND status = 'open' ORDER BY callnow_at LIMIT 100");
            foreach ($q->fetchAll() as $r) $out['callnow'][] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'person' => $names[(int)$r['assigned_emp']] ?? '', 'minutes' => wa_sales_mins((string)$r['callnow_at']), 'escalated' => (int)$r['callnow'] === 2, 'summary' => (string)$r['summary']];
            usort($out['unanswered'], fn($a, $b) => $b['minutes'] <=> $a['minutes']); usort($out['unassigned'], fn($a, $b) => $b['minutes'] <=> $a['minutes']);
            $out['unassigned'] = array_slice($out['unassigned'], 0, 40); $out['unanswered'] = array_slice($out['unanswered'], 0, 60);
            json_response($out);
        }
    }
}
