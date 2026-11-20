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

/** the same text however it was typed: lower case, one space between words, no stray punctuation at the ends */
function psum_norm(string $t): string { $t = mb_strtolower(trim($t)); $t = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $t) ?? $t; return trim((string)preg_replace('/\s+/u', ' ', $t), " \t\n\r.!?,"); }
/** 🩺 Where did the leads go? Customers whose WhatsApp message in the last $days days is one of the ad's ready-made messages ($msgs), or speaks of shower gel:
 *  how many, on which day, on which WhatsApp number, which message, who has the chat, whether a person answered, and whether they ordered. Read-only; phone numbers are masked. */
function psum_leads(PDO $pdo, array $msgs, int $days): string
{
    $days = max(1, min(60, $days)); $now = time(); $from = date('Y-m-d 00:00:00', $now - ($days - 1) * 86400); $to = date('Y-m-d H:i:s', $now);
    $pats = []; foreach ($msgs as $m) { $n = psum_norm((string)$m); if (mb_strlen($n) >= 8) $pats[$n] = trim((string)$m); } $pats = array_slice($pats, 0, 40, true);
    $L = ['=== SHOWER GEL LEADS DIAGNOSIS (read-only) ===', 'Made: ' . date('D j M Y, H:i', $now) . ' · customers who wrote from ' . date('j M', strtotime($from)) . ' to today (' . $days . ' day' . ($days === 1 ? '' : 's') . ')',
        'Counted: a customer whose message is one of the ' . count($pats) . ' ad message(s) given' . ' or mentions "shower gel". One customer is counted once (their first such message).', ''];
    try {
        $like = []; $par = [$from, $from, $to];
        foreach (array_keys($pats) as $n) { $like[] = "LOWER(m.body) LIKE ?"; $par[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($n, 0, 60)) . '%'; }
        $cond = "(LOWER(m.body) REGEXP 'sho?we?r ?gel' OR LOWER(m.body) LIKE '%body wash%'" . ($like ? ' OR ' . implode(' OR ', $like) : '') . ")";
        $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(30000) */ m.id, m.phone, m.body, m.ts, m.number_id, c.first_seen, c.assigned_emp, c.state, c.status, c.name FROM wa_contacts c JOIN wa_messages m ON m.phone = c.phone WHERE c.last_in_at >= ? AND m.dir = 'in' AND m.ts >= ? AND m.ts <= ? AND $cond ORDER BY m.id LIMIT 20000");
        $q->execute($par); $first = [];
        foreach ($q->fetchAll() as $r) if (!isset($first[$r['phone']])) $first[$r['phone']] = $r;
        $n = count($first); $L[] = 'CUSTOMERS FOUND: ' . $n; if (!$n) { $L[] = ''; $L[] = 'No customer wrote any of these messages, and nobody mentioned shower gel, in these days.'; $L[] = 'That means the ads are not bringing chats to these WhatsApp numbers: check the ad\'s WhatsApp number and the ready-made message in Meta Ads.'; $L[] = ''; $L[] = '=== END ==='; return implode("\n", $L); }
        $nums = []; try { foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $x) $nums[(int)$x['id']] = ($x['label'] !== '' ? $x['label'] : 'Number ' . $x['id']) . ($x['display'] !== '' ? ' (' . $x['display'] . ')' : ''); } catch (Throwable $e) { }
        $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        $byDay = []; $byNum = []; $byMsg = []; $byWho = []; $new = 0; $ans = 0; $bot = 0; $ord = 0; $gelOrd = 0; $noAns = []; $rows = [];
        $ao = $pdo->prepare("SELECT who, COUNT(*) n FROM wa_messages WHERE phone = ? AND dir = 'out' AND id > ? GROUP BY who");
        $oq = $pdo->prepare("SELECT xparts FROM shop_orders WHERE created_at >= ? AND RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 10) = ? AND COALESCE(tags, '') NOT LIKE '%wa-rx%'");
        $cap = 0;
        foreach ($first as $ph => $r) {
            $d = substr((string)$r['ts'], 0, 10); $byDay[$d] = ($byDay[$d] ?? 0) + 1;
            $nl = $nums[(int)$r['number_id']] ?? ('Number #' . (int)$r['number_id']); $byNum[$nl] = ($byNum[$nl] ?? 0) + 1;
            $nb = psum_norm((string)$r['body']); $mk = 'other wording that mentions shower gel'; foreach ($pats as $pn => $orig) if (mb_strpos($nb, mb_substr($pn, 0, 60)) !== false) { $mk = $orig; break; } $byMsg[$mk] = ($byMsg[$mk] ?? 0) + 1;
            $isNew = (string)$r['first_seen'] >= $from; if ($isNew) $new++;
            $ao->execute([$ph, (int)$r['id']]); $per = 0; $b2 = 0; foreach ($ao->fetchAll() as $o) { if (in_array($o['who'], ['emp', 'phone'], true)) $per += (int)$o['n']; elseif (in_array($o['who'], ['bot', 'flow'], true)) $b2 += (int)$o['n']; }
            if ($per > 0) $ans++; elseif ($b2 > 0) $bot++;
            $e = (int)$r['assigned_emp']; $who = $e > 0 ? (string)($names[$e] ?? ('#' . $e)) : ((string)$r['state'] === 'bot' ? 'Nobody (with the bot)' : 'Nobody (not assigned)');
            if (!isset($byWho[$who])) $byWho[$who] = [0, 0, 0]; $byWho[$who][0]++; if ($per > 0) $byWho[$who][1]++;
            $has = false; $hg = false; try { $oq->execute([$from, substr(preg_replace('/\D+/', '', (string)$ph), -10)]); foreach ($oq->fetchAll() as $o) { $has = true; $xp = json_decode((string)$o['xparts'], true); if (is_array($xp) && !empty($xp['gel']['pcs'])) $hg = true; } } catch (Throwable $e2) { }
            if ($has) { $ord++; $byWho[$who][2]++; } if ($hg) $gelOrd++;
            $line = substr((string)$r['ts'], 5, 11) . ' · …' . substr((string)$ph, -4) . ' · ' . $nl . ' · chat with ' . $who . ' · ' . ($per > 0 ? 'a person answered' : ($b2 > 0 ? 'ONLY the bot answered' : 'NOBODY answered')) . ($has ? ' · ORDERED' : '') . ' · "' . mb_substr(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 60) . '"';
            if ($per === 0) $noAns[] = $line; $rows[] = $line;
        }
        $pc = fn($a) => $n ? ' (' . round($a / $n * 100) . '%)' : '';
        $L[] = 'New numbers (first time on WhatsApp in these days): ' . $new . $pc($new);
        $L[] = 'A person answered after that message: ' . $ans . $pc($ans); $L[] = 'Only the bot / automatic messages answered: ' . $bot . $pc($bot); $L[] = 'Nobody answered at all: ' . ($n - $ans - $bot) . $pc($n - $ans - $bot);
        $L[] = 'Placed any order since: ' . $ord . $pc($ord) . ' · of them with shower gel in the order: ' . $gelOrd;
        $L[] = ''; $L[] = '--- BY DAY ---'; krsort($byDay); foreach ($byDay as $d => $c) $L[] = '   ' . date('D j M', strtotime($d)) . ': ' . $c;
        $L[] = ''; $L[] = '--- BY WHATSAPP NUMBER THE CUSTOMER WROTE TO ---'; arsort($byNum); foreach ($byNum as $k => $c) $L[] = '   ' . $k . ': ' . $c;
        $L[] = ''; $L[] = '--- BY MESSAGE ---'; arsort($byMsg); foreach ($byMsg as $k => $c) $L[] = '   ' . $c . ' × "' . mb_substr(preg_replace('/\s+/u', ' ', (string)$k), 0, 90) . '"'; foreach ($pats as $orig) if (!isset($byMsg[$orig])) $L[] = '   0 × "' . mb_substr(preg_replace('/\s+/u', ' ', $orig), 0, 90) . '"   <- nobody sent this one';
        $L[] = ''; $L[] = '--- WHO HAS THESE CHATS (customers · a person answered · ordered) ---'; uasort($byWho, fn($a, $b) => $b[0] <=> $a[0]); foreach ($byWho as $k => $v) $L[] = '   ' . str_pad($k, 30) . ' ' . $v[0] . ' · ' . $v[1] . ' · ' . $v[2];
        $L[] = ''; $L[] = '--- NOT ANSWERED BY A PERSON (newest 25 of ' . count($noAns) . ') ---'; foreach (array_slice(array_reverse($noAns), 0, 25) as $x) $L[] = '   ' . $x; if (!$noAns) $L[] = '   (none)';
        $L[] = ''; $L[] = '--- NEWEST 15 ---'; foreach (array_slice(array_reverse($rows), 0, 15) as $x) $L[] = '   ' . $x;
    } catch (Throwable $e) { $L[] = 'ERROR while reading: ' . mb_substr($e->getMessage(), 0, 200); }
    $L[] = ''; $L[] = '=== END ==='; return implode("\n", $L);
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
    if ($action === 'psum.leads') {   // 🩺 shower gel leads: POST {msgs: [...], days}
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $in = json_input(); @set_time_limit(120);
        json_response(['ok' => true, 'text' => psum_leads($pdo, is_array($in['msgs'] ?? null) ? $in['msgs'] : [], (int)($in['days'] ?? 7))]);
    }
    json_response(['error' => 'Unknown action.'], 404);
}

}
