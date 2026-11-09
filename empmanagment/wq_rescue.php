<?php
/**
 * wq_rescue.php — 🛟 RESCUE QUERIES ("effort-missed" queries). Loaded by wa_more.php right after wq.php. PHP 7.4 safe.
 *
 * Many WhatsApp queries are read by the bot (or by nobody): the customer was interested — asked the price, said "order kar do" — but no human pushed
 * and NO order was placed. Those queries get the tag  effort_missed  (wa_ctags, src 'wq') and show in the Chats section under the 🛟 Rescue chip.
 * The owner picks a few sales people (Rescue team); they see the Rescue chip, open a chat, and take it (the normal Take over / Assign buttons).
 * A rescued customer stays listed until a person has tried N times with no answer — then it goes silent. Nothing about pay or commission lives here.
 *
 * What counts as "effort missed" (one query, no order since it opened, customer not opted out, not a complaint):
 *   hot       the customer asked how to pay / said "order kar do" (wa_hot_intent)
 *   price     the customer asked the price / rate
 *   ignored   the customer wrote 2+ times and no person ever answered (only the bot, or nobody)
 *
 *   wq.rescue_list    GET  {days, status}      the Rescue control panel: every flagged query + numbers                              [admin]
 *   wq.rescue_give    POST {phone, emp_id}     give a flagged query to one person
 *   wq.rescue_skip    POST {phone, undo?}      this one is not a real chance (hides it)                                              [admin]
 *   wq.rescue_team    GET / POST {emp_ids[], day?, always?}   who sees the Rescue chip today (up to 40)                             [admin; GET: anyone]
 *   wq.rescue_cfg     GET / POST {on, days, wait_h, attempts} settings                                                               [admin]
 *   wq.rescue_scan    POST                      look at all recent queries now (throttled; WhatsApp calls it every time it opens)
 *   wq_rescue_chat_phones() feeds the 🛟 Rescue chip of the Chats list (wa.php).
 * Tables: wq_rescue, wq_rescue_seen, wq_rescue_team. Tick: wq_rescue_tick() is called from wq_tick() (full run).
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
            closed_at DATETIME NULL,
            UNIQUE KEY u_pq (phone, query_id), KEY k_status (status, flagged_at), KEY k_taken (taken_by, taken_at), KEY k_won (won_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_seen (query_id INT NOT NULL PRIMARY KEY, in_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('wq_meta_get') && wq_meta_get($pdo, 'rescue_nobonus', '') !== '1') {   // the bonus idea was removed: drop what it left behind (once)
            try { $pdo->exec("DROP TABLE IF EXISTS wq_rescue_orders"); } catch (Throwable $e) { }
            foreach (['bonus_pct', 'bonus_amt'] as $col) { try { $pdo->query("SELECT $col FROM wq_rescue LIMIT 0"); $pdo->exec("ALTER TABLE wq_rescue DROP COLUMN $col"); } catch (Throwable $e) { } }
            wq_meta_put($pdo, 'rescue_nobonus', '1');
        }
        try { $pdo->query("SELECT chk FROM wq_rescue_seen LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue_seen ADD COLUMN chk DATETIME NULL"); } catch (Throwable $e2) { } }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_team (day VARCHAR(10) NOT NULL, emp_id INT NOT NULL, added_by INT NOT NULL DEFAULT 0, PRIMARY KEY (day, emp_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { error_log('wq_rescue_tables: ' . $e->getMessage()); return $ok = false; }
}
function wq_rescue_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo): string { return function_exists('wq_get') ? (string)wq_get($pdo, $k, $d) : (string)setting_get($pdo, $k, $d); };
    $days = (int)$g('wq_rescue_days', '7'); $wait = (float)$g('wq_rescue_wait_h', '3'); $att = (int)$g('wq_rescue_attempts', '5');
    return ['on' => $g('wq_rescue_on', '1') !== '0', 'days' => $days >= 1 && $days <= 30 ? $days : 7, 'wait_h' => $wait >= 0.5 && $wait <= 48 ? $wait : 3.0, 'attempts' => $att >= 1 && $att <= 30 ? $att : 5];
}
function wq_rescue_save_cfg(PDO $pdo, array $in): void
{
    $m = ['on' => 'wq_rescue_on', 'days' => 'wq_rescue_days', 'wait_h' => 'wq_rescue_wait_h', 'attempts' => 'wq_rescue_attempts'];
    foreach ($m as $k => $key) {
        if (!array_key_exists($k, $in)) continue; $v = $k === 'on' ? (!empty($in[$k]) ? '1' : '0') : (string)(float)$in[$k];
        if ($k === 'days') $v = (string)max(1, min(30, (int)$in[$k])); if ($k === 'wait_h') $v = (string)max(0.5, min(48, (float)$in[$k])); if ($k === 'attempts') $v = (string)max(1, min(30, (int)$in[$k]));
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
function wq_rescue_scan(PDO $pdo, float $deadline = 0.0, int $beforeId = 0, bool $all = false): array
{
    $o = ['flagged' => 0, 'checked' => 0, 'candidates' => 0, 'silent' => 0, 'no_signal' => 0, 'all' => 0, 'quiet' => 0, 'ordered' => 0, 'partial' => false, 'error' => '']; if (!wq_rescue_tables($pdo)) { $o['error'] = 'storage'; return $o; } $cfg = wq_rescue_cfg($pdo); if (!$cfg['on']) return $o;
    $now = time(); $since = $all ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', $now - $cfg['days'] * 86400); $span = 2500; $idSql = $all ? ' AND q.id < ' . (int)$beforeId . ' AND q.id >= ' . max(0, (int)$beforeId - $span) : ''; $quiet = date('Y-m-d H:i:s', $now - (int)round($cfg['wait_h'] * 3600));
    $rows = [];
    try {
        if (!$all) { $c1 = $pdo->prepare("SELECT COUNT(*) FROM wa_queries WHERE opened_at >= ?"); $c1->execute([$since]); $o['all'] = (int)$c1->fetchColumn(); }
        $q = $pdo->prepare("SELECT q.id, q.phone, q.opened_at, c.last_in_at, c.last_out_at, c.assigned_emp, c.tag, c.cx_id FROM wa_queries q JOIN wa_contacts c ON c.phone = q.phone
            WHERE q.opened_at >= ? AND c.optout = 0 AND c.last_in_at IS NOT NULL AND c.last_in_at <= ?" . $idSql . " ORDER BY q.id DESC LIMIT 3000");
        try { $q->execute([$since, $quiet]); $rows = $q->fetchAll(); }
        catch (Throwable $e0) {   // an old install without the tag / cx_id columns
            $q = $pdo->prepare("SELECT q.id, q.phone, q.opened_at, c.last_in_at, c.last_out_at, c.assigned_emp FROM wa_queries q JOIN wa_contacts c ON c.phone = q.phone
                WHERE q.opened_at >= ? AND c.optout = 0 AND c.last_in_at IS NOT NULL AND c.last_in_at <= ?" . $idSql . " ORDER BY q.id DESC LIMIT 3000"); $q->execute([$since, $quiet]); $rows = $q->fetchAll();
        }
    } catch (Throwable $e) { $o['error'] = 'queries: ' . mb_substr($e->getMessage(), 0, 160); return $o; }
    $o['quiet'] = count($rows);
    // the small helper tables are read once and compared here (no slow cross-table joins, no collation clashes)
    $flagged = []; $seenM = []; $ordM = []; $doneM = [];
    try { foreach ($pdo->query("SELECT query_id FROM wq_rescue")->fetchAll(PDO::FETCH_COLUMN) as $x) $flagged[(int)$x] = 1; } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT query_id, in_at, chk FROM wq_rescue_seen WHERE chk >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchAll() as $x) $seenM[(int)$x['query_id']] = (string)$x['in_at']; } catch (Throwable $e) { }
    try { $st = $pdo->prepare("SELECT phone, MAX(created_at) m FROM wq_ord WHERE created_at >= ? GROUP BY phone"); $st->execute([$all ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', $now - ($cfg['days'] + 2) * 86400)]); foreach ($st->fetchAll() as $x) $ordM[substr(preg_replace('/\D+/', '', (string)$x['phone']), -10)] = (string)$x['m']; } catch (Throwable $e) { }
    try { $st = $pdo->prepare("SELECT phone, MAX(at) m FROM wa_order_done WHERE at >= ? GROUP BY phone"); $st->execute([$all ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', $now - ($cfg['days'] + 2) * 86400)]); foreach ($st->fetchAll() as $x) $doneM[substr(preg_replace('/\D+/', '', (string)$x['phone']), -10)] = (string)$x['m']; } catch (Throwable $e) { }
    $hasOpen = []; try { foreach ($pdo->query("SELECT DISTINCT phone FROM wq_rescue WHERE status IN ('open','taken','given')")->fetchAll(PDO::FETCH_COLUMN) as $x) $hasOpen[(string)$x] = 1; } catch (Throwable $e) { }
    $keep = [];
    foreach ($rows as $r) {
        $qid = (int)$r['id']; $k10 = substr(preg_replace('/\D+/', '', (string)$r['phone']), -10); $op = (string)$r['opened_at'];
        if (isset($flagged[$qid])) continue;
        if (isset($hasOpen[(string)$r['phone']])) continue;   // this customer is already on the list (an older query of theirs)
        if (isset($r['tag']) && (string)$r['tag'] === 'complaint') continue; if (!empty($r['cx_id'])) continue;
        if (isset($seenM[$qid]) && $seenM[$qid] === (string)$r['last_in_at']) continue;
        if (isset($ordM[$k10]) && $ordM[$k10] >= date('Y-m-d H:i:s', strtotime($op) - 3600)) { $o['ordered']++; continue; }
        if (isset($doneM[$k10]) && $doneM[$k10] >= $op) { $o['ordered']++; continue; }
        $keep[] = $r;
    }
    $rows = $keep;
    $o['candidates'] = count($rows);
    $msgs = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? ORDER BY id LIMIT 80");
    $ins = $pdo->prepare("INSERT IGNORE INTO wq_rescue (phone, query_id, flagged_at, why, why_text, last_in_at, last_text, status, orig_emp) VALUES (?,?,?,?,?,?,?, 'open', ?)");
    $seen = $pdo->prepare("INSERT INTO wq_rescue_seen (query_id, in_at, chk) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE in_at = VALUES(in_at), chk = NOW()");
    $nameOfWhy = ['hot' => 'wanted to pay / order', 'price' => 'asked the price, no order yet', 'ignored' => 'wrote 2+ times, no person answered'];
    foreach ($rows as $r) {
        if ($deadline > 0 && microtime(true) > $deadline) { $o['partial'] = true; break; } $o['checked']++;
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
        if (!$why) { $o['no_signal']++; continue; }
        $txt = implode('; ', array_map(fn($w) => $nameOfWhy[$w], $why));
        $ins->execute([(string)$r['phone'], (int)$r['id'], date('Y-m-d H:i:s'), implode(',', $why), $txt, (string)$r['last_in_at'], mb_substr(trim($lastText), 0, 200), (int)$r['assigned_emp']]);
        if ($ins->rowCount() > 0) { $o['flagged']++; $hasOpen[(string)$r['phone']] = 1; if (function_exists('wq_tag_put')) wq_tag_put($pdo, (string)$r['phone'], 'effort_missed'); }
    }
    return $o;
}
/** Close the loop: a rescue query somebody took (the chat was given / taken after it was flagged) → taken; an order after that → won (a count, nothing about pay);
 *  an order without anybody taking it → "ordered anyway"; N follow-up attempts with no answer → silent; the list never expires by age. */
function wq_rescue_settle(PDO $pdo): array
{
    $o = ['taken' => 0, 'won' => 0, 'ordered' => 0, 'expired' => 0, 'silent' => 0]; if (!wq_rescue_tables($pdo)) return $o; $cfg = wq_rescue_cfg($pdo);
    try {
        $q = $pdo->query("SELECT * FROM wq_rescue WHERE (status IN ('open','taken','given') AND flagged_at >= DATE_SUB(NOW(), INTERVAL 40 DAY)) OR (status = 'won' AND taken_at >= DATE_SUB(NOW(), INTERVAL 40 DAY)) ORDER BY id LIMIT 800"); $rows = $q->fetchAll();
        $cs = $pdo->prepare("SELECT assigned_emp, assigned_at FROM wa_contacts WHERE phone = ?");
        $ords = $pdo->prepare("SELECT order_name, total, created_at FROM wq_ord WHERE phone = ? AND created_at >= ? ORDER BY created_at LIMIT 50");
        $upT = $pdo->prepare("UPDATE wq_rescue SET status = 'taken', taken_by = ?, taken_at = ? WHERE id = ? AND status = 'open'");
        $upW = $pdo->prepare("UPDATE wq_rescue SET status = 'won', order_name = ?, order_total = ?, won_at = ?, closed_at = COALESCE(closed_at, ?) WHERE id = ?");
        $upO = $pdo->prepare("UPDATE wq_rescue SET status = 'ordered', order_name = ?, order_total = ?, closed_at = ? WHERE id = ? AND status = 'open'");
        $att = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person') AND ts > ?");
        $upS = $pdo->prepare("UPDATE wq_rescue SET status = 'silent', closed_at = ? WHERE id = ? AND status IN ('open','taken','given')");
        $upE = $pdo->prepare("UPDATE wq_rescue SET status = 'expired', closed_at = ? WHERE id = ? AND status IN ('open','given')");
        foreach ($rows as $r) {
            $now = date('Y-m-d H:i:s');
            if ($r['status'] === 'open') {   // somebody was given / took the chat after it was flagged → it is taken (any way: Assign, Take over, Give)
                $cs->execute([(string)$r['phone']]); $c = $cs->fetch();
                if ($c && (int)$c['assigned_emp'] > 0 && (string)$c['assigned_at'] > (string)$r['flagged_at']) { $upT->execute([(int)$c['assigned_emp'], (string)$c['assigned_at'], (int)$r['id']]); if ($upT->rowCount()) { $r['status'] = 'taken'; $r['taken_by'] = (int)$c['assigned_emp']; $r['taken_at'] = (string)$c['assigned_at']; $o['taken']++; } }
            }
            if (in_array($r['status'], ['taken', 'given', 'won'], true) && (int)$r['taken_by'] > 0) {
                $ords->execute([(string)$r['phone'], (string)$r['taken_at']]); $all = $ords->fetchAll();
                if ($all) { $sum = 0.0; foreach ($all as $x) $sum += (float)$x['total']; $upW->execute([(string)$all[0]['order_name'], round($sum, 2), (string)$all[0]['created_at'], $now, (int)$r['id']]); if ($r['status'] !== 'won') $o['won']++; continue; }
            } elseif ($r['status'] === 'open') {
                $ords->execute([(string)$r['phone'], (string)$r['flagged_at']]); $x = $ords->fetch();
                if ($x) { $upO->execute([(string)$x['order_name'], (float)$x['total'], $now, (int)$r['id']]); $o['ordered'] += $upO->rowCount(); continue; }
            }
            if ($r['status'] === 'won') continue;
            if ($r['last_in_at']) { $att->execute([(string)$r['phone'], (string)$r['last_in_at']]); if ((int)$att->fetchColumn() >= $cfg['attempts']) { $upS->execute([$now, (int)$r['id']]); $o['silent'] += $upS->rowCount(); continue; } }
            // (no expiry: a customer stays on the list until an order, N unanswered attempts, or the owner hides it)
        }
    } catch (Throwable $e) { error_log('wq_rescue_settle: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $o;
}
/** Preload ALL earlier chats: walks every query ever made (newest first, in id windows), resumable, a few windows per call. State in wq_meta rescue_bf. */
function wq_rescue_backfill(PDO $pdo, float $deadline): array
{
    $st = json_decode(wq_meta_get($pdo, 'rescue_bf', 'null'), true);
    if (!is_array($st)) { $mx = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM wa_queries")->fetchColumn(); $st = ['cursor' => $mx + 1, 'total' => $mx, 'done' => false, 'flagged' => 0, 'checked' => 0, 'at' => date('Y-m-d H:i:s')]; }
    if (!empty($st['done'])) return $st;
    $guard = 0;
    while (!$st['done'] && microtime(true) < $deadline && $guard++ < 40) {
        $r = wq_rescue_scan($pdo, $deadline, (int)$st['cursor'], true);
        if ($r['error'] !== '') { $st['error'] = $r['error']; break; }
        $st['flagged'] += $r['flagged']; $st['checked'] += $r['checked']; unset($st['error']);
        if (!empty($r['partial'])) break;                       // out of time inside a window: it is done again next call
        $st['cursor'] = max(0, (int)$st['cursor'] - 2500); if ($st['cursor'] <= 1) $st['done'] = true;
    }
    $st['pct'] = $st['total'] > 0 ? (int)round(100 * ($st['total'] - min($st['total'], (int)$st['cursor'])) / $st['total']) : 100; if (!empty($st['done'])) $st['pct'] = 100;
    wq_meta_put($pdo, 'rescue_bf', json_encode($st)); return $st;
}
/** Look at every recent query now (throttled): WhatsApp calls it when it opens and every 5 minutes; earlier chats are preloaded in the same calls. */
function wq_rescue_refresh(PDO $pdo, int $minGap = 60): array
{
    if (!function_exists('wq_meta_get') || !wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on']) return [];
    if (strtotime(wq_meta_get($pdo, 'rescue_scan_at', '2000-01-01')) > time() - $minGap) return ['skipped' => 'recent'];
    $lk = 0; try { $lk = (int)$pdo->query("SELECT GET_LOCK('wq_rescue_scan', 0)")->fetchColumn(); } catch (Throwable $e) { $lk = 1; }
    if ($lk !== 1) return ['skipped' => 'busy'];
    try {
        wq_meta_put($pdo, 'rescue_scan_at', date('Y-m-d H:i:s')); $t0 = microtime(true);
        $r = ['scan' => wq_rescue_scan($pdo, $t0 + 3), 'settle' => wq_rescue_settle($pdo)];
        $r['backfill'] = wq_rescue_backfill($pdo, $t0 + 8);
        wq_meta_put($pdo, 'rescue_last', json_encode(['at' => date('Y-m-d H:i:s')] + $r)); return $r;
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wq_rescue_scan')"); } catch (Throwable $e) { } }
}
/** Called from wq_tick() (full run): scan at most every 10 minutes, settle every time. */
function wq_rescue_tick(PDO $pdo, float $deadline): array
{
    if (!wq_rescue_tables($pdo)) return []; if (!wq_rescue_cfg($pdo)['on']) return ['off' => true];
    return wq_rescue_refresh($pdo, 270);   // every 5 minutes (by the cron / WhatsApp tick, even when nobody has the screen open)
}

// ------------------------------------------------------------------------------------------------------------------ reads
/** Customer names for a list of phones (one indexed query, no join). */
function wq_rescue_cnames(PDO $pdo, array $phones): array
{
    $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones) return []; $o = [];
    try { $st = $pdo->prepare("SELECT phone, name FROM wa_contacts WHERE phone IN (" . implode(',', array_fill(0, count($phones), '?')) . ")"); $st->execute($phones); foreach ($st->fetchAll() as $x) $o[(string)$x['phone']] = (string)$x['name']; } catch (Throwable $e) { }
    return $o;
}
function wq_rescue_item(array $r, array $names): array
{
    $st = (string)$r['status'];
    return ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => (string)($r['cname'] ?? ''), 'why' => array_values(array_filter(explode(',', (string)$r['why']))), 'why_text' => (string)$r['why_text'],
        'last_in_at' => (string)$r['last_in_at'], 'last_text' => (string)$r['last_text'], 'status' => $st, 'flagged_at' => (string)$r['flagged_at'],
        'window_open' => !empty($r['last_in_at']) && strtotime((string)$r['last_in_at']) > time() - 86400,
        'orig' => ['emp_id' => (int)$r['orig_emp'], 'name' => (string)($names[(int)$r['orig_emp']] ?? '')], 'taken_by' => ['emp_id' => (int)$r['taken_by'], 'name' => (string)($names[(int)$r['taken_by']] ?? '')],
        'taken_at' => (string)$r['taken_at'], 'order_name' => (string)$r['order_name'], 'order_total' => round((float)$r['order_total'], 2)];
}
function wq_rescue_list(PDO $pdo, int $me, bool $adm, int $days, string $status): array
{
    $names = function_exists('wq_names') ? wq_names($pdo) : []; $cfg = wq_rescue_cfg($pdo); $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    $where = "r.flagged_at >= ? AND r.status <> 'skipped'"; if ($adm && $status !== 'silent') $where .= " AND r.status <> 'silent'"; $par = [$since];
    if (!$adm) {   // a team member sees the open chances + the ones they took; nothing else
        $where .= " AND (r.status = 'open' OR r.taken_by = ?) AND r.status NOT IN ('ordered','expired','silent')"; $par[] = $me;
    } elseif ($status !== '' && $status !== 'all') { $where .= " AND r.status = ?"; $par[] = $status; }
    $q = $pdo->prepare("SELECT r.* FROM wq_rescue r WHERE $where ORDER BY FIELD(r.status,'open','given','taken','won','ordered','expired','silent'), r.last_in_at DESC LIMIT 1000");
    $q->execute($par); $rowsL = $q->fetchAll(); $cn = wq_rescue_cnames($pdo, array_column($rowsL, 'phone')); foreach ($rowsL as &$rl) $rl['cname'] = $cn[(string)$rl['phone']] ?? ''; unset($rl);
    $items = array_map(fn($r) => wq_rescue_item($r, $names), $rowsL);
    $st = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(order_total),0) rev FROM wq_rescue WHERE flagged_at >= ? AND status <> 'skipped' GROUP BY status"); $st->execute([$since]);
    $sum = ['open' => 0, 'taken' => 0, 'won' => 0, 'ordered' => 0, 'expired' => 0, 'silent' => 0, 'revenue' => 0.0];
    foreach ($st->fetchAll() as $s) { $k = (string)$s['status']; if ($k === 'given') $k = 'taken'; if (isset($sum[$k])) $sum[$k] += (int)$s['n']; if ($k === 'won') $sum['revenue'] = round((float)$s['rev'], 2); }
    return ['ok' => true, 'cfg' => $cfg, 'days' => $days, 'admin' => $adm, 'member' => wq_rescue_is_member($pdo, $me), 'last_scan' => function_exists('wq_meta_get') ? json_decode(wq_meta_get($pdo, 'rescue_last', 'null'), true) : null, 'backfill' => function_exists('wq_meta_get') ? json_decode(wq_meta_get($pdo, 'rescue_bf', 'null'), true) : null, 'summary' => $sum, 'items' => $items];
}
/** The phones of the 🛟 Rescue chip: admin = every open / taken rescue chat; team member = the open pool + the ones they took. Empty for anybody else. */
function wq_rescue_chat_phones(PDO $pdo, int $me, bool $adm): array
{
    if (!wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on'] || (!$adm && !wq_rescue_is_member($pdo, $me))) return [];
    try {
        $q = $adm ? $pdo->query("SELECT phone FROM wq_rescue WHERE status IN ('open','taken','given') ORDER BY last_in_at DESC LIMIT 1500")
                  : $pdo->prepare("SELECT phone FROM wq_rescue WHERE status = 'open' OR (status IN ('taken','given') AND taken_by = ?) ORDER BY last_in_at DESC LIMIT 1500");
        if (!$adm) $q->execute([$me]); return array_values(array_unique(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) { return []; }
}
/** The number on the 🛟 Rescue chip, or null when this person does not see the chip. */
function wq_rescue_chip_count(PDO $pdo, int $me, bool $adm): ?int
{
    if (!wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on'] || (!$adm && !wq_rescue_is_member($pdo, $me))) return null;
    try { return (int)$pdo->query("SELECT COUNT(*) FROM wq_rescue WHERE status = 'open'")->fetchColumn(); } catch (Throwable $e) { return null; }
}
/** May this team member READ a rescue chat before taking it (wa.chat)? */
function wq_rescue_may_read(PDO $pdo, array $auth, string $key): bool
{
    $me = (int)($auth['employee_id'] ?? 0); if ($me <= 0 || !wq_rescue_tables($pdo) || !wq_rescue_is_member($pdo, $me)) return false;
    try { $q = $pdo->prepare("SELECT 1 FROM wq_rescue WHERE phone = ? AND status = 'open' LIMIT 1"); $q->execute([$key]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** Rescue chats for the salesperson app's request list (wq.req_list): the same shape as a request, flagged rescue. */
function wq_rescue_req_items(PDO $pdo, int $me): array
{
    $ph = wq_rescue_chat_phones($pdo, $me, false); if (!$ph) return [];
    try {
        $q = $pdo->prepare("SELECT r.phone, r.last_in_at, r.last_text, r.why_text FROM wq_rescue r WHERE r.status = 'open' ORDER BY r.last_in_at DESC LIMIT 100"); $q->execute(); $rows = $q->fetchAll(); $cn = wq_rescue_cnames($pdo, array_column($rows, 'phone')); $o = [];
        foreach ($rows as $r) $o[] = ['phone' => (string)$r['phone'], 'name' => (string)($cn[(string)$r['phone']] ?? ''), 'mins' => max(0, (int)floor((time() - strtotime((string)$r['last_in_at'])) / 60)), 'answered' => false, 'last_in' => mb_substr((string)$r['last_text'], 0, 120), 'src' => 'rescue', 'rescue' => true, 'why' => (string)$r['why_text']];
        return $o;
    } catch (Throwable $e) { return []; }
}
/** Take a flagged query: the chat moves to $me and checked again at the moment of taking. */
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
if (strpos($__wqr, 'wq.rescue_') === 0 && in_array($__wqr, ['wq.rescue_list', 'wq.rescue_take', 'wq.rescue_give', 'wq.rescue_skip', 'wq.rescue_team', 'wq.rescue_cfg', 'wq.rescue_scan'], true)) {
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
            if ($ids && function_exists('ct_push')) { try { ct_push($pdo, $ids, 'Rescue queries', 'You are on the rescue team: open WhatsApp → Chats → 🛟 Rescue and take the customers who were not followed up.', '/whatsapp.html#chats', 'wq-rescue-team'); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'day' => $day, 'emp_ids' => $ids]);
        }
        case 'wq.rescue_cfg': {
            if (!$isPost) json_response(['ok' => true, 'cfg' => $cfgR]); $needAdmin(); wq_rescue_save_cfg($pdo, $in); json_response(['ok' => true, 'cfg' => wq_rescue_cfg($pdo)]);
        }
        case 'wq.rescue_scan': {
            $needPost(); $r = wq_rescue_refresh($pdo, !empty($in['force']) ? 0 : 60); json_response(['ok' => true] + $r);
        }
    } } catch (Throwable $e) { error_log('wq_rescue: ' . $e->getMessage()); json_response(['error' => 'Rescue queries: ' . mb_substr($e->getMessage(), 0, 240)], 500); }
}
