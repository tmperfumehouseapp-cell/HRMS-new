<?php
/**
 * tk_goal.php — TICKET-SIZE GOAL (one number, shown everywhere). Settings (hrms_settings):
 *   tk_on (1) · tk_goal (2700) · tk_bonus_pct (10: shown as an ESTIMATE only until the commission engine pays it) · tk_min_orders (3) · tk_hold (0: off) · tk_hold_below (2300) · tk_hold_orders (4) · tk_hold_wait (3) · tk_ratio_relief (3 points)
 * The ticket is the one the Control Tower already uses (clean ticket when known, else sales ÷ orders of the business day).
 */
require_once __DIR__ . '/tk_lib.php';

// the admin's two controls (Team performance → Auto assign): the goal, and the hold switch
$__tkA = (string)($action ?? ($_GET['action'] ?? ''));
if ($__tkA === 'wq.tk_me') {   // the salesperson's own ticket for the WhatsApp panel chip: reads the cached day rows only (cheap)
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $card = null;
    try { if ($me > 0 && function_exists('waa_cp')) { $r = (array)(waa_cp($pdo)[$me] ?? []); $card = tk_card($pdo, ['orders' => (int)($r['n'] ?? 0), 'sales' => (float)($r['s'] ?? 0), 'clean_ticket' => (float)($r['ctk'] ?? 0), 'ticket' => (float)($r['tk'] ?? 0), 'com_today' => 0]); } } catch (Throwable $e) { }
    $slow = null; try { if ($me > 0 && function_exists('waa_state')) { $x = (array)(waa_state($pdo)['e'][$me] ?? []); if ((int)($x['sh'] ?? 0) > time()) $slow = ['state' => 'hold', 'until' => (int)$x['sh'], 'mins' => (int)ceil(((int)$x['sh'] - time()) / 60)];
        elseif ((int)($x['sw'] ?? 0) > 0 && (int)($x['sw']) > time() - 3600) $slow = ['state' => 'warn', 'since' => (int)$x['sw']]; } } catch (Throwable $e) { }
    json_response(['ok' => true, 'tk' => $card, 'slow' => $slow]);
}
if ($__tkA === 'wq.ad_me') {   // ➕ the salesperson's OWN add-on effort today (read from the chats after the orders): four counts, stars, add-on rupees, the orders where nothing was offered, the words that worked. Only the person's own rows.
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    $out = ['ok' => true, 'n' => [0, 0, 0, 0], 'sz' => [0, 0, 0, 0], 'stars' => null, 'addon_rs' => 0, 'missed' => [], 'missed_big' => [], 'tip' => '', 'team' => [0, 0, 0, 0], 'team_sz' => [0, 0, 0, 0], 'yd' => ['n' => [0, 0, 0, 0], 'sz' => [0, 0, 0, 0], 'read' => 0], 'read' => 0];
    try {
        if ($me > 0 && function_exists('ora_tables') && ora_tables($pdo)) {
            $day = biz_today($pdo); $st = 0.0; $rows = [];
            $q = $pdo->prepare("SELECT order_key, order_name, rating, tried, worked, tries, sz_tried, sz_worked, sz_tries, reason, phone FROM order_rate_auto WHERE employee_id = ? AND day = ? AND rating IS NOT NULL AND sz_ok = 3 ORDER BY at DESC LIMIT 400"); $q->execute([$me, $day]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            try { if (function_exists('addons_attach')) addons_attach($pdo, $rows, 'order_name'); } catch (Throwable $e) { }
            foreach ($rows as $r) {
                $lv = ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries']); $out['n'][$lv]++; $out['sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; $st += (float)$r['rating'] / 2; $out['addon_rs'] += (float)($r['addon_total'] ?? 0);
                if ($lv === 0 && count($out['missed']) < 5) { $out['missed'][] = ['order' => (string)$r['order_name'], 'phone' => (string)$r['phone'], 'why' => mb_substr((string)$r['reason'], 0, 140)]; }
                if (ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries']) === 0 && count($out['missed_big']) < 5) { $out['missed_big'][] = ['order' => (string)$r['order_name'], 'phone' => (string)$r['phone'], 'why' => mb_substr((string)$r['reason'], 0, 140)]; }
            }
            $n = array_sum($out['n']); $out['read'] = $n; $out['stars'] = $n ? round($st / $n, 1) : null; $out['addon_rs'] = round($out['addon_rs']);
            $t = $pdo->prepare("SELECT tip FROM order_rate_auto WHERE employee_id = ? AND worked = 1 AND tip <> '' AND at >= ? ORDER BY at DESC LIMIT 1"); $t->execute([$me, date('Y-m-d H:i:s', time() - 14 * 86400)]); $out['tip'] = (string)$t->fetchColumn();
            $tm = $pdo->prepare("SELECT tried, worked, tries, sz_tried, sz_worked, sz_tries FROM order_rate_auto WHERE day = ? AND rating IS NOT NULL AND sz_ok = 3 LIMIT 3000"); $tm->execute([$day]); foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $r) { $out['team'][ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries'])]++; $out['team_sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; }
            $yd = date('Y-m-d', strtotime($day . ' -1 day')); $yq = $pdo->prepare("SELECT tried, worked, tries, sz_tried, sz_worked, sz_tries FROM order_rate_auto WHERE employee_id = ? AND day = ? AND rating IS NOT NULL AND sz_ok = 3 LIMIT 600"); $yq->execute([$me, $yd]);
            foreach ($yq->fetchAll(PDO::FETCH_ASSOC) as $r) { $out['yd']['n'][ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries'])]++; $out['yd']['sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; $out['yd']['read']++; }
        }
    } catch (Throwable $e) { error_log('wq.ad_me: ' . $e->getMessage()); }
    // 💡 ideas from the team's own winning chats (last 7 days, no names): what to say to sell bigger / add an item. Falls back to two fixed examples until enough chats are read.
    $ideas = ['big' => [], 'add' => []];
    try { if (function_exists('ora_tables') && ora_tables($pdo)) { $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        foreach (['big' => "sz_worked = 1 AND sz_tip <> ''", 'add' => "worked = 1 AND tip <> ''"] as $k => $w) { $col = $k === 'big' ? 'sz_tip' : 'tip';
            $q = $pdo->prepare("SELECT $col FROM order_rate_auto WHERE sz_ok = 3 AND $w AND at >= ? ORDER BY at DESC LIMIT 40"); $q->execute([$since]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) { $t = trim((string)$t); if ($t !== '' && !in_array($t, $ideas[$k], true)) $ideas[$k][] = $t; if (count($ideas[$k]) >= 3) break; } } } } catch (Throwable $e) { }
    if (!$ideas['big']) $ideas['big'][] = '50ml ki jagah 100ml le lo, per ml sasta padega';
    if (!$ideas['add']) $ideas['add'][] = '4 ki jagah 5 perfume le lo, 5th pe ₹100 off';
    $out['ideas'] = $ideas;
    json_response($out);
}
if ($__tkA === 'wq.grow_chat') {   // 📈 the live effort of ONE open chat (bigger + add-on), shown only while the order is being confirmed. Own chats only (Admin / manager: any). Read from the transcript by the same strict judge; cached 45 s per last message.
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $k = substr(preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')), -10);
    $empty = ['ok' => true, 'big' => null, 'add' => null];
    if (strlen($k) !== 10 || !function_exists('ora_judge_text')) json_response($empty);
    try {
        $c = $pdo->prepare("SELECT assigned_emp FROM wa_contacts WHERE phone = ? LIMIT 1"); $c->execute([$k]); $ae = (int)$c->fetchColumn();
        $adm = function_exists('wa_is_admin') && wa_is_admin($pdo, $auth); $mgr = function_exists('wa_is_manager') && wa_is_manager($pdo, $auth);
        if (!$adm && !$mgr && ($me <= 0 || $ae !== $me)) json_response(['error' => 'Not your chat.'], 403);
        $q = $pdo->prepare("SELECT id, dir, who, emp_id, mtype, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND dir IN ('in', 'out') ORDER BY id ASC LIMIT 300"); $q->execute([$k, date('Y-m-d H:i:s', time() - 3 * 86400)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) json_response($empty);
        $last = (int)end($rows)['id']; [$text, $nc, $ns] = ora_transcript($rows); if ($nc < 1 || $ns < 1) json_response($empty);
        $r = function_exists('hrms_memo') ? hrms_memo('growchat:' . $k . ':' . $last, 45, fn() => ora_judge_text($text, 'This chat is still running: the order is being confirmed now.')) : ora_judge_text($text, '');
        if (!empty($r['retry']) || !is_array($r)) json_response($empty);
        $mk = fn($tr, $wk, $wo, $rf) => ['tries' => (int)$tr, 'weak' => (int)$wk, 'worked' => (int)$wo, 'refused' => (int)$rf, 'level' => ora_effort((int)($tr >= 1 || $wo), (int)$wo, (int)$tr)];
        json_response(['ok' => true, 'big' => $mk($r['big_tries'] ?? 0, $r['big_weak'] ?? 0, $r['big_worked'] ?? 0, $r['big_refused'] ?? 0), 'add' => $mk($r['tries'] ?? 0, $r['weak'] ?? 0, $r['worked'] ?? 0, $r['refused'] ?? 0), 'last' => $last]);
    } catch (Throwable $e) { error_log('wq.grow_chat: ' . $e->getMessage()); json_response($empty); }
}

// ================= 🔗 Payment-link rule: no link before BOTH efforts (extra perfume + bigger size) were really offered =================
// Settings (hrms_settings): grow_gate_mode = off | warn | lock | auto (default auto: warning-only until the day before grow_lock_from, strict from that business day) · grow_lock_from (default 2026-11-19 = strict from the business day that starts tomorrow morning) · grow_link_re (regex of what counts as a payment link).
// Only the Employee role is held (Admin / HR / managers never are). A person is never blocked because OUR side failed (AI silent, no chat read, error). Effort, not result, unlocks: one proper offer per lane, or the customer clearly refused, or the cart already reaches the ticket goal, or a written reason (counted and shown to the owner).
function wq_grow_ready(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS grow_gate_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, day DATE NULL, emp INT NOT NULL DEFAULT 0, phone VARCHAR(20) NOT NULL DEFAULT '', mode VARCHAR(8) NOT NULL DEFAULT '', decision VARCHAR(12) NOT NULL DEFAULT '', add_lv TINYINT NOT NULL DEFAULT 0, big_lv TINYINT NOT NULL DEFAULT 0, reason VARCHAR(300) NOT NULL DEFAULT '', KEY k_day (day), KEY k_emp (emp)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('wq_grow_ready: ' . $e->getMessage()); return $ok = false; }
}
function wq_grow_cfg(PDO $pdo): array
{
    $m = (string)setting_get($pdo, 'grow_gate_mode', 'auto'); if (!in_array($m, ['off', 'warn', 'lock', 'auto'], true)) $m = 'auto';
    $from = (string)setting_get($pdo, 'grow_lock_from', '2026-11-19'); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '2026-11-19';
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    return ['mode' => $m, 'from' => $from, 'today' => $today, 'effective' => $m === 'auto' ? ($today >= $from ? 'lock' : 'warn') : $m];
}
function wq_grow_is_pay_link(PDO $pdo, string $text): bool
{
    if (!preg_match_all('~https?://[^\s]+~i', $text, $m)) return false;
    $re = (string)setting_get($pdo, 'grow_link_re', ''); if ($re === '' || @preg_match($re, '') === false) $re = '~(/invoices/|/checkouts/|/cart/c/|rzp\.io|razorpay\.com|paytm|payu|cashfree|/payment)~i';
    foreach ($m[0] as $u) if (preg_match($re, $u)) return true;
    return false;
}
/** The two efforts of the running chat, read by the same strict judge (cached 45 s, shared with wq.grow_chat). null = could not be read (never blocks). */
function wq_grow_levels(PDO $pdo, string $k): ?array
{
    if (strlen($k) !== 10 || !function_exists('ora_judge_text') || !function_exists('ora_transcript') || !function_exists('hrms_memo')) return null;
    try {
        $q = $pdo->prepare("SELECT id, dir, who, emp_id, mtype, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND dir IN ('in', 'out') ORDER BY id ASC LIMIT 300"); $q->execute([$k, date('Y-m-d H:i:s', time() - 3 * 86400)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null; $last = (int)end($rows)['id']; [$text, $nc, $ns] = ora_transcript($rows); if ($nc < 1 || $ns < 1) return null;
        $r = hrms_memo('growchat:' . $k . ':' . $last, 45, fn() => ora_judge_text($text, 'This chat is still running: the order is being confirmed now.'));
        if (!is_array($r) || !empty($r['retry']) || !array_key_exists('tries', $r)) return null;
        return ['add' => ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries']), 'big' => ora_effort((int)($r['big_tried'] ?? 0), (int)($r['big_worked'] ?? 0), (int)($r['big_tries'] ?? 0)), 'add_ref' => !empty($r['refused']), 'big_ref' => !empty($r['big_refused'])];
    } catch (Throwable $e) { return null; }
}
function wq_grow_ideas(PDO $pdo): array
{
    $o = ['big' => ['50ml ki jagah 100ml le lo, per ml sasta padega'], 'add' => ['4 ki jagah 5 perfume le lo, 5th pe ₹100 off']];
    try { if (function_exists('ora_tables') && ora_tables($pdo)) { $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        foreach (['big' => ['sz_tip', "sz_worked = 1 AND sz_tip <> ''"], 'add' => ['tip', "worked = 1 AND tip <> ''"]] as $k => $d) { $q = $pdo->prepare("SELECT {$d[0]} FROM order_rate_auto WHERE sz_ok = 3 AND {$d[1]} AND at >= ? ORDER BY at DESC LIMIT 1"); $q->execute([$since]); $t = trim((string)$q->fetchColumn()); if ($t !== '') $o[$k] = [$t]; } } } catch (Throwable $e) { }
    return $o;
}
function wq_grow_log(PDO $pdo, int $emp, string $phone, string $mode, string $decision, int $add, int $big, string $reason = ''): void
{
    if (!wq_grow_ready($pdo)) return;
    try { $day = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
        if (in_array($decision, ['blocked', 'warn_shown'], true)) { $q = $pdo->prepare("SELECT id FROM grow_gate_log WHERE emp = ? AND phone = ? AND decision = ? AND at >= ? LIMIT 1"); $q->execute([$emp, $phone, $decision, date('Y-m-d H:i:s', time() - 600)]); if ($q->fetchColumn()) return; }   // the same tap twice is one entry
        $pdo->prepare("INSERT INTO grow_gate_log (at, day, emp, phone, mode, decision, add_lv, big_lv, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([date('Y-m-d H:i:s'), $day, $emp, $phone, $mode, $decision, $add, $big, mb_substr($reason, 0, 300)]); } catch (Throwable $e) { }
}
/** 🛟 Safety brake: in the last 2 hours, if more than grow_breaker_pct % (default 40) of the link attempts that reached a decision would have been held back (blocked + warned vs passed; at least 10 attempts), the strict rule steps down to a warning by itself until the share is lower again — so the rule can never starve the orders. Returns [active, share %, attempts]. */
function wq_grow_breaker(PDO $pdo): array
{
    if (!wq_grow_ready($pdo)) return [false, 0, 0];
    try { $pct = max(10, min(90, (int)setting_get($pdo, 'grow_breaker_pct', '40')));
        $q = $pdo->prepare("SELECT SUM(decision IN ('blocked', 'warn_shown')) b, SUM(decision = 'passed') p FROM grow_gate_log WHERE at >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 7200)]); $r = $q->fetch(PDO::FETCH_ASSOC); $b = (int)($r['b'] ?? 0); $p = (int)($r['p'] ?? 0); $n = $b + $p;
        $share = $n > 0 ? (int)round($b * 100 / $n) : 0; return [$n >= 10 && $share > $pct, $share, $n]; } catch (Throwable $e) { return [false, 0, 0]; }
}
/** The rule. Returns ['gate' => ''] to let the link go, or ['gate' => 'warn'|'lock', 'missing' => ['add','big'], 'error' => text, 'ideas' => …]. $totalRs = the cart total in rupees when known (0 = unknown). */
function wq_grow_check(PDO $pdo, array $auth, string $phone, array $in, int $totalRs = 0): array
{
    $pass = ['gate' => '']; $cfg = wq_grow_cfg($pdo); $mode = $cfg['effective'];
    if ($mode === 'off' || strtolower(trim((string)($auth['role'] ?? ''))) !== 'employee') return $pass;
    $emp = (int)($auth['employee_id'] ?? 0); $k = substr(preg_replace('/\D+/', '', $phone), -10);
    $lv = wq_grow_levels($pdo, $k); if ($lv === null) return $pass;                                  // we could not read the chat: never block
    $goal = (int)setting_get($pdo, 'tk_goal', '2700'); if ($totalRs > 0 && $goal > 0 && $totalRs >= $goal) { wq_grow_log($pdo, $emp, $k, $mode, 'passed', (int)$lv['add'], (int)$lv['big'], 'goal'); return $pass; }   // the cart already reaches the ticket goal
    $addOk = $lv['add'] >= 1 || $lv['add_ref']; $bigOk = $lv['big'] >= 1 || $lv['big_ref'];
    if ($addOk && $bigOk) { wq_grow_log($pdo, $emp, $k, $mode, 'passed', (int)$lv['add'], (int)$lv['big']); return $pass; }
    if ($mode === 'lock' && wq_grow_breaker($pdo)[0]) $mode = 'warn';   // 🛟 too many links would be held: warning only for now
    $reason = trim((string)($in['grow_waive'] ?? '')); if (mb_strlen($reason) >= 4) { wq_grow_log($pdo, $emp, $k, $mode, 'waived', (int)$lv['add'], (int)$lv['big'], $reason); return $pass; }
    if ($mode === 'warn' && !empty($in['grow_ok'])) { wq_grow_log($pdo, $emp, $k, $mode, 'warned_sent', (int)$lv['add'], (int)$lv['big']); return $pass; }
    $miss = []; if (!$addOk) $miss[] = 'add'; if (!$bigOk) $miss[] = 'big';
    wq_grow_log($pdo, $emp, $k, $mode, $mode === 'lock' ? 'blocked' : 'warn_shown', (int)$lv['add'], (int)$lv['big']);
    $what = in_array('add', $miss, true) && in_array('big', $miss, true) ? 'વધારાનું પરફ્યુમ અને મોટી સાઇઝ, બંનેની' : (in_array('add', $miss, true) ? 'વધારાનું પરફ્યુમ (એડ ઓન) ની' : 'મોટી સાઇઝ (ટિકિટ સાઇઝ) ની');
    return ['gate' => $mode, 'missing' => $miss, 'error' => $what . ' સાચી ઓફર હજી ચેટમાં થઈ નથી.', 'ideas' => wq_grow_ideas($pdo)];
}
if ($__tkA === 'wq.grow_cfg') {   // Admin / HR: the rule's switch (off / warn / lock / auto from a date) + today's numbers
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can see or change the link rule.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { $in = json_input();
        if (isset($in['mode']) && in_array((string)$in['mode'], ['off', 'warn', 'lock', 'auto'], true)) setting_set($pdo, 'grow_gate_mode', (string)$in['mode']);
        if (isset($in['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['from'])) setting_set($pdo, 'grow_lock_from', (string)$in['from']);
        if (isset($in['breaker_pct'])) setting_set($pdo, 'grow_breaker_pct', (string)max(10, min(90, (int)$in['breaker_pct']))); }
    [$__ba, $__bs, $__bn] = wq_grow_breaker($pdo);
    $out = wq_grow_cfg($pdo) + ['breaker' => ['active' => $__ba, 'share' => $__bs, 'attempts' => $__bn, 'limit' => max(10, min(90, (int)setting_get($pdo, 'grow_breaker_pct', '40')))], 'today_counts' => ['blocked' => 0, 'warn_shown' => 0, 'warned_sent' => 0, 'waived' => 0, 'passed' => 0], 'waivers' => []];
    try { if (wq_grow_ready($pdo)) { $q = $pdo->prepare("SELECT decision, COUNT(*) n FROM grow_gate_log WHERE day = ? GROUP BY decision"); $q->execute([$out['today']]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out['today_counts'][(string)$r['decision']] = (int)$r['n'];
        $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $w = $pdo->prepare("SELECT emp, at, reason FROM grow_gate_log WHERE day = ? AND decision = 'waived' ORDER BY id DESC LIMIT 20"); $w->execute([$out['today']]);
        foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $r) $out['waivers'][] = ['emp' => $nm[(int)$r['emp']] ?? ('#' . $r['emp']), 'at' => substr((string)$r['at'], 11, 5), 'reason' => (string)$r['reason']]; } } catch (Throwable $e) { }
    json_response(['ok' => true] + $out);
}
if ($__tkA === 'wq.tk_cfg') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm && function_exists('wa_boss_ok') && wa_boss_ok($pdo, $auth)) $adm = true;
    if (!$adm) json_response(['error' => 'Only Admin, HR or a Sales Head can change the ticket goal.'], 403);
    if ($isPost) { $in = json_input();
        if (isset($in['goal'])) setting_set($pdo, 'tk_goal', (string)max(0, min(50000, (int)$in['goal'])));
        if (isset($in['hold'])) setting_set($pdo, 'tk_hold', !empty($in['hold']) ? '1' : '0');
        if (isset($in['pct'])) setting_set($pdo, 'tk_bonus_pct', (string)max(0, min(50, (float)$in['pct'])));
        if (isset($in['offer'])) setting_set($pdo, 'tk_offer', mb_substr(trim((string)$in['offer']), 0, 300));
        if (isset($in['cust'])) setting_set($pdo, 'tk_cust_goal', mb_substr(trim((string)$in['cust']), 0, 300));
        if (isset($in['pay'])) setting_set($pdo, 'tk_pay', !empty($in['pay']) ? '1' : '0');
    }
    $c = tk_cfg($pdo); json_response(['ok' => true, 'goal' => (int)$c['goal'], 'hold' => $c['hold'], 'pct' => $c['pct'], 'offer' => $c['offer'], 'cust' => $c['cust']]);
}
