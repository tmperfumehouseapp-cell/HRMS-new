<?php
/**
 * wq_rescue.php — 🛟 RESCUE QUERIES ("effort-missed" queries). Loaded by wa_more.php right after wq.php. PHP 7.4 safe.
 *
 * The idea: many WhatsApp queries are read by the bot (or by nobody) and the customer was interested — asked the price, said "order kar do" — but no human
 * pushed hard enough and NO order was placed. Those queries get the tag  effort_missed  (wa_ctags, src 'wq') and are listed here. The owner picks a few
 * sales people for the day (Rescue team); they see ONLY these queries, take one, work the customer — and if an order comes, that order earns the person an
 * EXTRA rescue bonus (default 2% of the order total, on top of the normal commission). The bonus is kept in its own ledger (wq_rescue.bonus_amt), so the
 * normal commission engine is never touched; payroll / My Sales read it through wq_rescue_bonus_between().
 *
 * What counts as "effort missed" (one query, no order since it opened, customer not opted out, not a complaint):
 *   hot       the customer asked how to pay / said "order kar do" (wa_hot_intent) and no order followed
 *   price     the customer asked the price / rate and no person answered the last message for rescue_wait_h hours (default 3)
 *   ignored   the customer wrote 2+ times and no person ever answered (only the bot, or nobody)
 *
 *   wq.rescue_list    GET  {days, status}      admin: every flagged query + numbers · team member: the open ones + their own      [today's team or admin]
 *   wq.rescue_take    POST {phone}             take one (the chat moves to you, the query is yours for the bonus)                  [today's team]
 *   wq.rescue_give    POST {phone, emp_id}     admin gives a flagged query to one person of the team
 *   wq.rescue_skip    POST {phone, undo?}      admin: this one is not a real chance (hides it)                                      [admin]
 *   wq.rescue_team    GET / POST {emp_ids[], day?, always?}   who sees the queries today (up to 40)                                [admin; GET: anyone]
 *   wq.rescue_cfg     GET / POST {on, pct, days, wait_h}      settings                                                            [admin]
 *   wq.rescue_bonus   GET  {from, to, emp_id?} the bonus earned (per person)                                                      [admin: all · others: own]
 *   wq.rescue_scan    POST                      scan now                                                                         [admin]
 * Tables: wq_rescue, wq_rescue_team. Tick: wq_rescue_tick() is called from wq_tick() (full run).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wq_rescue_tables')) {

function wq_rescue_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, query_id INT NOT NULL DEFAULT 0, flagged_at DATETIME NOT NULL, why VARCHAR(60) NOT NULL DEFAULT '',
            why_text VARCHAR(240) NOT NULL DEFAULT '', last_in_at DATETIME NULL, last_text VARCHAR(200) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'open', orig_emp INT NOT NULL DEFAULT 0,
            taken_by INT NOT NULL DEFAULT 0, taken_at DATETIME NULL, given_by INT NOT NULL DEFAULT 0, order_name VARCHAR(40) NOT NULL DEFAULT '', order_total DECIMAL(12,2) NOT NULL DEFAULT 0, won_at DATETIME NULL,
            bonus_pct DECIMAL(5,2) NOT NULL DEFAULT 0, bonus_amt DECIMAL(12,2) NOT NULL DEFAULT 0, closed_at DATETIME NULL,
            UNIQUE KEY u_pq (phone, query_id), KEY k_status (status, flagged_at), KEY k_taken (taken_by, taken_at), KEY k_won (won_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_seen (query_id INT NOT NULL PRIMARY KEY, in_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_orders (okey VARCHAR(90) NOT NULL PRIMARY KEY, rescue_id INT NOT NULL DEFAULT 0, emp_id INT NOT NULL DEFAULT 0, phone VARCHAR(12) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '', total DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus_pct DECIMAL(5,2) NOT NULL DEFAULT 0, bonus_amt DECIMAL(12,2) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY k_emp (emp_id, created_at), KEY k_resc (rescue_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->query("SELECT chk FROM wq_rescue_seen LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue_seen ADD COLUMN chk DATETIME NULL"); } catch (Throwable $e2) { } }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_team (day VARCHAR(10) NOT NULL, emp_id INT NOT NULL, added_by INT NOT NULL DEFAULT 0, PRIMARY KEY (day, emp_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('wq_rescue_tables: ' . $e->getMessage()); return $ok = false; }
}
function wq_rescue_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo): string { return function_exists('wq_get') ? (string)wq_get($pdo, $k, $d) : (string)setting_get($pdo, $k, $d); };
    $pct = (float)$g('wq_rescue_pct', '2'); $days = (int)$g('wq_rescue_days', '7'); $wait = (float)$g('wq_rescue_wait_h', '3'); $att = (int)$g('wq_rescue_attempts', '5');
    return ['on' => $g('wq_rescue_on', '1') !== '0', 'pct' => $pct >= 0 && $pct <= 20 ? $pct : 2.0, 'days' => $days >= 1 && $days <= 30 ? $days : 7, 'wait_h' => $wait >= 0.5 && $wait <= 48 ? $wait : 3.0, 'attempts' => $att >= 1 && $att <= 30 ? $att : 5];
}
function wq_rescue_save_cfg(PDO $pdo, array $in): void
{
    $m = ['on' => 'wq_rescue_on', 'pct' => 'wq_rescue_pct', 'days' => 'wq_rescue_days', 'wait_h' => 'wq_rescue_wait_h', 'attempts' => 'wq_rescue_attempts'];
    foreach ($m as $k => $key) {
        if (!array_key_exists($k, $in)) continue; $v = $k === 'on' ? (!empty($in[$k]) ? '1' : '0') : (string)(float)$in[$k];
        if ($k === 'pct') $v = (string)max(0, min(20, (float)$in[$k])); if ($k === 'days') $v = (string)max(1, min(30, (int)$in[$k])); if ($k === 'wait_h') $v = (string)max(0.5, min(48, (float)$in[$k])); if ($k === 'attempts') $v = (string)max(1, min(30, (int)$in[$k]));
        $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $v]);
        if (isset($GLOBALS['__wq_set'][$key])) unset($GLOBALS['__wq_set'][$key]);
    }
}
function wq_rescue_today(PDO $pdo): string { return function_exists('biz_today') ? (string)biz_today($pdo) : date('Y-m-d'); }
/** The people who see the rescue queries on $day: that day's own list, else the "every day" list (day = 'always'). */
function wq_rescue_team_ids(PDO $pdo, string $day = ''): array
{
    $day = $day !== '' ? $day : wq_rescue_today($pdo);
    $q = $pdo->prepare("SELECT emp_id FROM wq_rescue_team WHERE day = ?"); $q->execute([$day]); $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) { $q->execute(['always']); $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)); }
    return $ids;
}
function wq_rescue_is_member(PDO $pdo, int $me): bool { return $me > 0 && in_array($me, wq_rescue_team_ids($pdo), true); }

// ------------------------------------------------------------------------------------------------------------------ detection
function wq_rescue_price_intent(string $t): bool
{
    $t = mb_strtolower($t); if ($t === '') return false;
    return (bool)preg_match('/(price|prize|rate\b|rates\b|cost|kitne|kitna|kimat|kimmat|keemat|bhav|bhaav|how much|\bmrp\b|\boffer\b|\bdiscount\b|\bcod\b|₹|\brs\.?\s*\d|\d+\s*ml\b|catalog|catalogue|list bhej|price list|ketla|ketlu|કિંમત|ભાવ|કેટલા|कीमत|कितना|कितने|भाव|दाम)/u', $t);
}
/** Scan recent queries and flag the effort-missed ones. Time-boxed; returns ['flagged' => n, 'checked' => n]. */
function wq_rescue_scan(PDO $pdo, float $deadline = 0.0): array
{
    $o = ['flagged' => 0, 'checked' => 0, 'candidates' => 0, 'silent' => 0, 'error' => '']; if (!wq_rescue_tables($pdo)) { $o['error'] = 'storage'; return $o; } $cfg = wq_rescue_cfg($pdo); if (!$cfg['on']) return $o;
    $now = time(); $since = date('Y-m-d H:i:s', $now - $cfg['days'] * 86400); $quiet = date('Y-m-d H:i:s', $now - (int)round($cfg['wait_h'] * 3600));
    try {
        $q = $pdo->prepare("SELECT q.id, q.phone, q.opened_at, q.emp_id, c.last_in_at, c.last_out_at, c.assigned_emp
            FROM wa_queries q JOIN wa_contacts c ON c.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci
            WHERE q.opened_at >= ? AND c.optout = 0 AND (c.tag IS NULL OR c.tag <> 'complaint') AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.last_in_at IS NOT NULL AND c.last_in_at <= ?
              AND NOT EXISTS (SELECT 1 FROM wq_rescue r WHERE r.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci AND r.query_id = q.id)
              AND NOT EXISTS (SELECT 1 FROM wq_ord o WHERE o.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci AND o.created_at >= DATE_SUB(q.opened_at, INTERVAL 1 HOUR))
              AND NOT EXISTS (SELECT 1 FROM wq_rescue_seen z WHERE z.query_id = q.id AND z.in_at <=> c.last_in_at AND z.chk >= DATE_SUB(NOW(), INTERVAL 30 MINUTE))
              AND NOT EXISTS (SELECT 1 FROM wa_order_done d WHERE d.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci AND d.at >= q.opened_at)
            ORDER BY q.id DESC LIMIT 1500");
        $q->execute([$since, $quiet]); $rows = $q->fetchAll();
    } catch (Throwable $e) {
        $o['error'] = 'first query: ' . mb_substr($e->getMessage(), 0, 160);
        // wa_order_done / wq_ord may not exist on an old install: try the simpler form
        try {
            $q = $pdo->prepare("SELECT q.id, q.phone, q.opened_at, q.emp_id, c.last_in_at, c.last_out_at, c.assigned_emp FROM wa_queries q JOIN wa_contacts c ON c.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci
                WHERE q.opened_at >= ? AND c.optout = 0 AND c.last_in_at IS NOT NULL AND c.last_in_at <= ? AND NOT EXISTS (SELECT 1 FROM wq_rescue r WHERE r.phone COLLATE utf8mb4_general_ci = q.phone COLLATE utf8mb4_general_ci AND r.query_id = q.id) ORDER BY q.id DESC LIMIT 1500");
            $q->execute([$since, $quiet]); $rows = $q->fetchAll();
        } catch (Throwable $e2) { error_log('wq_rescue_scan: ' . $e2->getMessage()); $o['error'] = 'scan: ' . mb_substr($e2->getMessage(), 0, 160); return $o; }
    }
    $o['candidates'] = count($rows);
    $msgs = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? ORDER BY id LIMIT 80");
    $ins = $pdo->prepare("INSERT IGNORE INTO wq_rescue (phone, query_id, flagged_at, why, why_text, last_in_at, last_text, status, orig_emp) VALUES (?,?,?,?,?,?,?, 'open', ?)");
    $seen = $pdo->prepare("INSERT INTO wq_rescue_seen (query_id, in_at, chk) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE in_at = VALUES(in_at), chk = NOW()");
    $nameOfWhy = ['hot' => 'wanted to pay / order', 'price' => 'asked the price, no order yet', 'ignored' => 'wrote 2+ times, no person answered'];
    foreach ($rows as $r) {
        if ($deadline > 0 && microtime(true) > $deadline) break; $o['checked']++;
        $msgs->execute([(string)$r['phone'], (string)$r['opened_at']]); $list = $msgs->fetchAll(); try { $seen->execute([(int)$r['id'], (string)$r['last_in_at']]); } catch (Throwable $e) { } if (!$list) continue;
        $inN = 0; $hot = false; $price = false; $person = false; $lastInTs = 0; $lastText = ''; $attempts = 0;
        foreach ($list as $m) {
            if ($m['dir'] === 'in') {
                $inN++; $b = (string)$m['body']; $lastInTs = strtotime((string)$m['ts']); $lastText = $b !== '' ? $b : $lastText; $attempts = 0;      // the customer spoke: our attempts start again
                if (function_exists('wa_hot_intent') && wa_hot_intent($b) !== '') $hot = true; if (wq_rescue_price_intent($b)) $price = true;
            } elseif ($m['dir'] === 'out' && in_array((string)$m['who'], ['emp', 'person'], true)) { $person = true; $attempts++; }
        }
        if ($inN < 1) continue;
        // The customer showed interest and no order came: it is an opportunity UNTIL a person has tried 'attempts' times since the customer's last message with no answer.
        if ($attempts >= $cfg['attempts']) { $o['silent']++; continue; }
        $why = [];
        if ($hot) $why[] = 'hot';                                              // wanted to pay / order
        if ($price) $why[] = 'price';                                          // asked the price
        if ($inN >= 2 && !$person) $why[] = 'ignored';                         // wrote 2+ times, no person ever answered
        if (!$why) continue;
        $txt = implode('; ', array_map(fn($w) => $nameOfWhy[$w], $why));
        $ins->execute([(string)$r['phone'], (int)$r['id'], date('Y-m-d H:i:s'), implode(',', $why), $txt, (string)$r['last_in_at'], mb_substr(trim($lastText), 0, 200), (int)$r['assigned_emp']]);
        if ($ins->rowCount() > 0) { $o['flagged']++; if (function_exists('wq_tag_put')) wq_tag_put($pdo, (string)$r['phone'], 'effort_missed'); }
    }
    return $o;
}
/** Close the loop: ordered after a rescue → win + bonus; ordered without anybody taking it → "ordered anyway"; too old → expired. */
function wq_rescue_settle(PDO $pdo): array
{
    $o = ['won' => 0, 'ordered' => 0, 'expired' => 0, 'silent' => 0, 'bonus_orders' => 0]; if (!wq_rescue_tables($pdo)) return $o; $cfg = wq_rescue_cfg($pdo);
    try {
        $q = $pdo->query("SELECT * FROM wq_rescue WHERE (status IN ('open','taken','given') AND flagged_at >= DATE_SUB(NOW(), INTERVAL 40 DAY)) OR (status = 'won' AND taken_at >= DATE_SUB(NOW(), INTERVAL 40 DAY)) ORDER BY id LIMIT 800");
        $ords = $pdo->prepare("SELECT okey, order_name, total, created_at FROM wq_ord WHERE phone = ? AND created_at >= ? ORDER BY created_at LIMIT 50");
        $insL = $pdo->prepare("INSERT IGNORE INTO wq_rescue_orders (okey, rescue_id, emp_id, phone, order_name, total, bonus_pct, bonus_amt, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
        $sumL = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(bonus_amt),0) b, MIN(created_at) f, MIN(order_name) nm FROM wq_rescue_orders WHERE rescue_id = ?");
        $upW = $pdo->prepare("UPDATE wq_rescue SET status = 'won', order_name = ?, order_total = ?, won_at = ?, bonus_pct = ?, bonus_amt = ?, closed_at = COALESCE(closed_at, ?) WHERE id = ?");
        $upO = $pdo->prepare("UPDATE wq_rescue SET status = 'ordered', order_name = ?, order_total = ?, closed_at = ? WHERE id = ? AND status = 'open'");
        $att = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person') AND ts > ?");
        $upS = $pdo->prepare("UPDATE wq_rescue SET status = 'silent', closed_at = ? WHERE id = ? AND status IN ('open','taken','given')");
        $upE = $pdo->prepare("UPDATE wq_rescue SET status = 'expired', closed_at = ? WHERE id = ? AND status IN ('open','given')");
        foreach ($q->fetchAll() as $r) {
            $now = date('Y-m-d H:i:s'); $mine = (int)$r['taken_by'] > 0 && in_array($r['status'], ['taken', 'given', 'won'], true);
            if ($mine) {
                // EVERY order the customer places after the person took the query earns the bonus (per order): one ledger line per order
                $ords->execute([(string)$r['phone'], (string)$r['taken_at']]); $new = 0;
                foreach ($ords->fetchAll() as $x) {
                    $amt = round((float)$x['total'] * $cfg['pct'] / 100, 2);
                    $insL->execute([(string)$x['okey'], (int)$r['id'], (int)$r['taken_by'], (string)$r['phone'], (string)$x['order_name'], (float)$x['total'], $cfg['pct'], $amt, (string)$x['created_at']]); $new += $insL->rowCount();
                }
                $o['bonus_orders'] += $new;
                $sumL->execute([(int)$r['id']]); $t = $sumL->fetch();
                if ($t && (int)$t['n'] > 0) { $upW->execute([(string)$t['nm'], (float)$t['t'], (string)$t['f'], $cfg['pct'], (float)$t['b'], $now, (int)$r['id']]); if ($r['status'] !== 'won') $o['won']++; continue; }
            } elseif ($r['status'] === 'open') {
                $ords->execute([(string)$r['phone'], (string)$r['flagged_at']]); $x = $ords->fetch();
                if ($x) { $upO->execute([(string)$x['order_name'], (float)$x['total'], $now, (int)$r['id']]); $o['ordered'] += $upO->rowCount(); continue; }
            }
            if ($r['status'] === 'won') continue;
            if ($r['last_in_at']) { $att->execute([(string)$r['phone'], (string)$r['last_in_at']]); if ((int)$att->fetchColumn() >= $cfg['attempts']) { $upS->execute([$now, (int)$r['id']]); $o['silent'] += $upS->rowCount(); continue; } }
            if (in_array($r['status'], ['open', 'given'], true) && strtotime((string)$r['flagged_at']) < time() - ($cfg['days'] + 2) * 86400) { $upE->execute([$now, (int)$r['id']]); $o['expired'] += $upE->rowCount(); }
        }
    } catch (Throwable $e) { error_log('wq_rescue_settle: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $o;
}
/** Look at every recent query now (throttled): used when WhatsApp / the Rescue screen opens. */
function wq_rescue_refresh(PDO $pdo, int $minGap = 60): array
{
    if (!function_exists('wq_meta_get') || !wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on']) return [];
    if (strtotime(wq_meta_get($pdo, 'rescue_scan_at', '2000-01-01')) > time() - $minGap) return ['skipped' => 'recent'];
    wq_meta_put($pdo, 'rescue_scan_at', date('Y-m-d H:i:s'));
    $r = ['scan' => wq_rescue_scan($pdo, microtime(true) + 6), 'settle' => wq_rescue_settle($pdo)]; wq_meta_put($pdo, 'rescue_last', json_encode(['at' => date('Y-m-d H:i:s')] + $r)); return $r;
}
/** Called from wq_tick() (full run): scan at most every 10 minutes, settle every time. */
function wq_rescue_tick(PDO $pdo, float $deadline): array
{
    $r = [];
    if (!wq_rescue_tables($pdo)) return $r; if (!wq_rescue_cfg($pdo)['on']) return ['off' => true];
    if (function_exists('wq_meta_get') && strtotime(wq_meta_get($pdo, 'rescue_scan_at', '2000-01-01')) < time() - 600) { $r['scan'] = wq_rescue_scan($pdo, $deadline); wq_meta_put($pdo, 'rescue_scan_at', date('Y-m-d H:i:s')); }
    $r['settle'] = wq_rescue_settle($pdo); return $r;
}

// ------------------------------------------------------------------------------------------------------------------ reads
function wq_rescue_item(array $r, array $names): array
{
    $st = (string)$r['status'];
    return ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => (string)($r['cname'] ?? ''), 'why' => array_values(array_filter(explode(',', (string)$r['why']))), 'why_text' => (string)$r['why_text'],
        'last_in_at' => (string)$r['last_in_at'], 'last_text' => (string)$r['last_text'], 'status' => $st, 'flagged_at' => (string)$r['flagged_at'],
        'window_open' => !empty($r['last_in_at']) && strtotime((string)$r['last_in_at']) > time() - 86400,
        'orig' => ['emp_id' => (int)$r['orig_emp'], 'name' => (string)($names[(int)$r['orig_emp']] ?? '')], 'taken_by' => ['emp_id' => (int)$r['taken_by'], 'name' => (string)($names[(int)$r['taken_by']] ?? '')],
        'taken_at' => (string)$r['taken_at'], 'order_name' => (string)$r['order_name'], 'order_total' => round((float)$r['order_total'], 2), 'bonus_pct' => (float)$r['bonus_pct'], 'bonus_amt' => round((float)$r['bonus_amt'], 2)];
}
function wq_rescue_list(PDO $pdo, int $me, bool $adm, int $days, string $status): array
{
    $names = function_exists('wq_names') ? wq_names($pdo) : []; $cfg = wq_rescue_cfg($pdo); $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    $where = "r.flagged_at >= ? AND r.status <> 'skipped'"; if ($adm && $status !== 'silent') $where .= " AND r.status <> 'silent'"; $par = [$since];
    if (!$adm) {   // a team member sees the open chances + the ones they took; nothing else
        $where .= " AND (r.status = 'open' OR r.taken_by = ?) AND r.status NOT IN ('ordered','expired','silent')"; $par[] = $me;
    } elseif ($status !== '' && $status !== 'all') { $where .= " AND r.status = ?"; $par[] = $status; }
    $q = $pdo->prepare("SELECT r.*, c.name AS cname FROM wq_rescue r LEFT JOIN wa_contacts c ON c.phone COLLATE utf8mb4_general_ci = r.phone COLLATE utf8mb4_general_ci WHERE $where ORDER BY FIELD(r.status,'open','given','taken','won','ordered','expired','silent'), r.last_in_at DESC LIMIT 400");
    $q->execute($par); $items = array_map(fn($r) => wq_rescue_item($r, $names), $q->fetchAll());
    $st = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(order_total),0) rev, COALESCE(SUM(bonus_amt),0) bonus FROM wq_rescue WHERE flagged_at >= ? AND status <> 'skipped' GROUP BY status"); $st->execute([$since]);
    $sum = ['open' => 0, 'taken' => 0, 'won' => 0, 'ordered' => 0, 'expired' => 0, 'silent' => 0, 'revenue' => 0.0, 'bonus' => 0.0];
    foreach ($st->fetchAll() as $s) { $k = (string)$s['status']; if ($k === 'given') $k = 'taken'; if (isset($sum[$k])) $sum[$k] += (int)$s['n']; if ($k === 'won') { $sum['revenue'] = round((float)$s['rev'], 2); $sum['bonus'] = round((float)$s['bonus'], 2); } }
    return ['ok' => true, 'cfg' => $cfg, 'days' => $days, 'admin' => $adm, 'member' => wq_rescue_is_member($pdo, $me), 'last_scan' => function_exists('wq_meta_get') ? json_decode(wq_meta_get($pdo, 'rescue_last', 'null'), true) : null, 'summary' => $sum, 'items' => $items];
}
function wq_rescue_bonus_between(PDO $pdo, int $emp, string $from, string $to): float
{
    if (!wq_rescue_tables($pdo)) return 0.0;
    try { $q = $pdo->prepare("SELECT COALESCE(SUM(bonus_amt),0) FROM wq_rescue_orders WHERE emp_id = ? AND created_at >= ? AND created_at <= ?"); $q->execute([$emp, $from . ' 00:00:00', $to . ' 23:59:59']); return round((float)$q->fetchColumn(), 2); }
    catch (Throwable $e) { return 0.0; }
}
/** Per-order bonus report: one line per order that earned the bonus. */
function wq_rescue_bonus_report(PDO $pdo, string $from, string $to, int $only = 0): array
{
    $names = function_exists('wq_names') ? wq_names($pdo) : []; $w = "o.created_at >= ? AND o.created_at <= ?"; $p = [$from . ' 00:00:00', $to . ' 23:59:59']; if ($only > 0) { $w .= " AND o.emp_id = ?"; $p[] = $only; }
    $q = $pdo->prepare("SELECT o.emp_id, COUNT(*) orders, COALESCE(SUM(o.total),0) revenue, COALESCE(SUM(o.bonus_amt),0) bonus FROM wq_rescue_orders o WHERE $w GROUP BY o.emp_id ORDER BY bonus DESC"); $q->execute($p); $rows = [];
    foreach ($q->fetchAll() as $r) $rows[] = ['emp_id' => (int)$r['emp_id'], 'name' => (string)($names[(int)$r['emp_id']] ?? ('#' . $r['emp_id'])), 'orders' => (int)$r['orders'], 'revenue' => round((float)$r['revenue'], 2), 'bonus' => round((float)$r['bonus'], 2)];
    $l = $pdo->prepare("SELECT o.phone, o.emp_id, o.order_name, o.total, o.bonus_pct, o.bonus_amt, o.created_at, c.name cname FROM wq_rescue_orders o LEFT JOIN wa_contacts c ON c.phone COLLATE utf8mb4_general_ci = o.phone COLLATE utf8mb4_general_ci WHERE $w ORDER BY o.created_at DESC LIMIT 300"); $l->execute($p);
    $orders = array_map(fn($r) => ['phone' => (string)$r['phone'], 'customer' => (string)$r['cname'], 'emp_id' => (int)$r['emp_id'], 'name' => (string)($names[(int)$r['emp_id']] ?? ''), 'order_name' => (string)$r['order_name'], 'total' => round((float)$r['total'], 2), 'pct' => (float)$r['bonus_pct'], 'bonus' => round((float)$r['bonus_amt'], 2), 'won_at' => (string)$r['created_at']], $l->fetchAll());
    return ['ok' => true, 'from' => $from, 'to' => $to, 'people' => $rows, 'orders' => $orders, 'total_bonus' => round(array_sum(array_column($rows, 'bonus')), 2)];
}
/** Take a flagged query: the chat moves to $me and the query becomes theirs for the bonus. Checked again at the moment of taking. */
function wq_rescue_take(PDO $pdo, string $phone, int $me, int $by = 0, string $how = 'rescue'): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can take a query.'];
    $q = $pdo->prepare("SELECT * FROM wq_rescue WHERE phone = ? AND status IN ('open','given') ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $r = $q->fetch();
    if (!$r) return ['error' => 'This query is not in the rescue list any more (taken, ordered or expired).'];
    $c = function_exists('wq_contact') ? wq_contact($pdo, $phone) : []; if (!$c) return ['error' => 'Chat not found.'];
    if ((int)($c['optout'] ?? 0) === 1) return ['error' => 'This customer opted out - nobody may message them.'];
    $now = date('Y-m-d H:i:s');
    $u = $pdo->prepare("UPDATE wq_rescue SET status = 'taken', taken_by = ?, taken_at = ?, given_by = ? WHERE id = ? AND status IN ('open','given')"); $u->execute([$me, $now, $by > 0 && $by !== $me ? $by : 0, (int)$r['id']]);
    if ($u->rowCount() === 0) return ['error' => 'Somebody else just took this query.'];
    if ((int)($c['assigned_emp'] ?? 0) !== $me && function_exists('wa_assign_to')) wa_assign_to($pdo, $phone, $me, $by > 0 ? $by : $me, $how);
    return ['ok' => true, 'phone' => $phone, 'window_open' => !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 86400];
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router
$__wqr = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wqr, 'wq.rescue_') === 0 && in_array($__wqr, ['wq.rescue_list', 'wq.rescue_take', 'wq.rescue_give', 'wq.rescue_skip', 'wq.rescue_team', 'wq.rescue_cfg', 'wq.rescue_bonus', 'wq.rescue_scan'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    if (function_exists('wq_deps')) wq_deps($pdo); if (function_exists('wq_tables') && !wq_tables($pdo)) json_response(['error' => 'WhatsApp Sales storage is not available.'], 500);
    if (!wq_rescue_tables($pdo)) json_response(['error' => 'Rescue storage is not available - check database permissions.'], 500);
    $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth); $cfgR = wq_rescue_cfg($pdo);
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needGet = function () use ($isPost) { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needAdmin = function () use ($adm) { if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403); };
    $phoneIn = function () use ($in) { $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); return strlen($k) >= 10 ? substr($k, -10) : ''; };
    $dateIn = function ($v, string $d): string { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $d; };
    try { switch ($__wqr) {
        case 'wq.rescue_list': {
            $needGet(); if (!$adm && !wq_rescue_is_member($pdo, $me)) json_response(['ok' => true, 'cfg' => $cfgR, 'admin' => false, 'member' => false, 'summary' => ['open' => 0, 'taken' => 0, 'won' => 0, 'ordered' => 0, 'expired' => 0, 'revenue' => 0, 'bonus' => 0], 'items' => []]);
            wq_rescue_refresh($pdo, 60);   // every time the screen opens: look at all recent queries first (at most once a minute)
            json_response(wq_rescue_list($pdo, $me, $adm, max(1, min(60, (int)($in['days'] ?? $cfgR['days']))), (string)($in['status'] ?? 'all')));
        }
        case 'wq.rescue_take': {
            $needPost(); if (!wq_rescue_is_member($pdo, $me) && !$adm) json_response(['error' => 'You are not on today\'s rescue team.'], 403);
            $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_rescue_take($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r);
        }
        case 'wq.rescue_give': {
            $needPost(); $needAdmin(); $k = $phoneIn(); $emp = (int)($in['emp_id'] ?? 0); if ($k === '' || $emp <= 0) json_response(['error' => 'Choose a chat and a person.'], 422);
            $r = wq_rescue_take($pdo, $k, $emp, $me, 'rescue'); if (isset($r['error'])) json_response($r, 409); json_response($r);
        }
        case 'wq.rescue_skip': {
            $needPost(); $needAdmin(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404);
            if (!empty($in['undo'])) $pdo->prepare("UPDATE wq_rescue SET status = 'open', closed_at = NULL WHERE phone = ? AND status = 'skipped'")->execute([$k]);
            else $pdo->prepare("UPDATE wq_rescue SET status = 'skipped', closed_at = ? WHERE phone = ? AND status IN ('open','given')")->execute([date('Y-m-d H:i:s'), $k]);
            json_response(['ok' => true]);
        }
        case 'wq.rescue_team': {
            if (!$isPost) {
                $day = $dateIn($in['day'] ?? '', wq_rescue_today($pdo)); $ids = wq_rescue_team_ids($pdo, $day); $names = function_exists('wq_names') ? wq_names($pdo) : [];
                $always = $pdo->query("SELECT COUNT(*) FROM wq_rescue_team WHERE day = 'always'")->fetchColumn() > 0; $own = $pdo->prepare("SELECT COUNT(*) FROM wq_rescue_team WHERE day = ?"); $own->execute([$day]);
                $people = $adm && function_exists('wq_sales_people') ? array_map(fn($p) => ['emp_id' => (int)$p['employee_id'], 'name' => (string)$p['full_name'], 'in' => in_array((int)$p['employee_id'], $ids, true)], wq_sales_people($pdo)) : [];
                json_response(['ok' => true, 'day' => $day, 'emp_ids' => $ids, 'names' => array_map(fn($i) => (string)($names[$i] ?? ('#' . $i)), $ids), 'people' => $people, 'always' => $always && (int)$own->fetchColumn() === 0, 'me_in' => in_array($me, $ids, true)]);
            }
            $needAdmin(); $day = !empty($in['always']) ? 'always' : $dateIn($in['day'] ?? '', wq_rescue_today($pdo)); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['emp_ids'] ?? [])), fn($i) => $i > 0)));
            if (count($ids) > 40) json_response(['error' => 'Up to 40 people.'], 422);
            $pdo->prepare("DELETE FROM wq_rescue_team WHERE day = ?")->execute([$day]);
            $ins = $pdo->prepare("INSERT IGNORE INTO wq_rescue_team (day, emp_id, added_by) VALUES (?,?,?)"); foreach ($ids as $i) $ins->execute([$day, $i, $me]);
            if ($ids && function_exists('ct_push')) { try { ct_push($pdo, $ids, 'Rescue queries', 'You are on the rescue team: take a missed query, win the order, earn ' . rtrim(rtrim(number_format($cfgR['pct'], 2), '0'), '.') . '% extra.', '/wa_rescue.html', 'wq-rescue-team'); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'day' => $day, 'emp_ids' => $ids]);
        }
        case 'wq.rescue_cfg': {
            if (!$isPost) json_response(['ok' => true, 'cfg' => $cfgR]); $needAdmin(); wq_rescue_save_cfg($pdo, $in); json_response(['ok' => true, 'cfg' => wq_rescue_cfg($pdo)]);
        }
        case 'wq.rescue_bonus': {
            $needGet(); $to = $dateIn($in['to'] ?? '', wq_rescue_today($pdo)); $from = $dateIn($in['from'] ?? '', substr($to, 0, 8) . '01');
            $only = $adm ? (int)($in['emp_id'] ?? 0) : $me; if (!$adm && $me <= 0) json_response(['ok' => true, 'from' => $from, 'to' => $to, 'people' => [], 'orders' => [], 'total_bonus' => 0]);
            json_response(wq_rescue_bonus_report($pdo, $from, $to, $only));
        }
        case 'wq.rescue_scan': {
            $needPost(); $r = wq_rescue_refresh($pdo, !empty($in['force']) ? 0 : 60); json_response(['ok' => true] + $r);
        }
    } } catch (Throwable $e) { error_log('wq_rescue: ' . $e->getMessage()); json_response(['error' => 'Rescue queries: ' . mb_substr($e->getMessage(), 0, 240)], 500); }
}
