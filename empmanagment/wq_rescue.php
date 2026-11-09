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
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // set up once per upload, not on every request (the chat list asks for it every few seconds)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, query_id INT NOT NULL DEFAULT 0, flagged_at DATETIME NOT NULL, why VARCHAR(60) NOT NULL DEFAULT '',
            why_text VARCHAR(240) NOT NULL DEFAULT '', last_in_at DATETIME NULL, last_text VARCHAR(200) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'open', orig_emp INT NOT NULL DEFAULT 0,
            taken_by INT NOT NULL DEFAULT 0, taken_at DATETIME NULL, given_by INT NOT NULL DEFAULT 0, order_name VARCHAR(40) NOT NULL DEFAULT '', order_total DECIMAL(12,2) NOT NULL DEFAULT 0, won_at DATETIME NULL,
            closed_at DATETIME NULL,
            UNIQUE KEY u_pq (phone, query_id), KEY k_status (status, flagged_at), KEY k_taken (taken_by, taken_at), KEY k_won (won_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_seen (query_id INT NOT NULL PRIMARY KEY, in_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('wq_meta_get') && wq_meta_get($pdo, 'rescue_prio1', '') !== '1') {   // priority columns (once)
            foreach (['score' => 'INT NOT NULL DEFAULT 0', 'tier' => "VARCHAR(8) NOT NULL DEFAULT 'week'", 'returns' => 'INT NOT NULL DEFAULT 0'] as $col => $def) { try { $pdo->query("SELECT $col FROM wq_rescue LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue ADD COLUMN $col $def"); } catch (Throwable $e2) { } } }
            wq_meta_put($pdo, 'rescue_prio1', '1');
        }
        if (function_exists('wq_meta_get') && wq_meta_get($pdo, 'rescue_ai1', '') !== '1') {   // why it was removed + the AI's second opinion (once)
            foreach (['skip_reason' => "VARCHAR(20) NOT NULL DEFAULT ''", 'ai_state' => "VARCHAR(10) NOT NULL DEFAULT ''", 'ai_note' => "VARCHAR(240) NOT NULL DEFAULT ''"] as $col => $def) { try { $pdo->query("SELECT $col FROM wq_rescue LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue ADD COLUMN $col $def"); } catch (Throwable $e2) { } } }
            wq_meta_put($pdo, 'rescue_ai1', '1');
        }
        if (function_exists('wq_meta_get') && wq_meta_get($pdo, 'rescue_skipby1', '') !== '1') {   // who pressed Not a chance (once)
            try { $pdo->query("SELECT skipped_by FROM wq_rescue LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue ADD COLUMN skipped_by INT NOT NULL DEFAULT 0"); } catch (Throwable $e2) { } }
            wq_meta_put($pdo, 'rescue_skipby1', '1');
        }
        if (function_exists('wq_meta_get') && wq_meta_get($pdo, 'rescue_nobonus', '') !== '1') {   // the bonus idea was removed: drop what it left behind (once)
            try { $pdo->exec("DROP TABLE IF EXISTS wq_rescue_orders"); } catch (Throwable $e) { }
            foreach (['bonus_pct', 'bonus_amt'] as $col) { try { $pdo->query("SELECT $col FROM wq_rescue LIMIT 0"); $pdo->exec("ALTER TABLE wq_rescue DROP COLUMN $col"); } catch (Throwable $e) { } }
            wq_meta_put($pdo, 'rescue_nobonus', '1');
        }
        try { $pdo->query("SELECT chk_at FROM wq_rescue LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue ADD COLUMN chk_at DATETIME NULL"); } catch (Throwable $e2) { } }
        try { $pdo->exec("ALTER TABLE wq_rescue ADD KEY k_open (status, tier, score, last_in_at)"); } catch (Throwable $e) { /* exists */ }
        try { $pdo->query("SELECT chk FROM wq_rescue_seen LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wq_rescue_seen ADD COLUMN chk DATETIME NULL"); } catch (Throwable $e2) { } }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rescue_team (day VARCHAR(10) NOT NULL, emp_id INT NOT NULL, added_by INT NOT NULL DEFAULT 0, PRIMARY KEY (day, emp_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
        return $ok = true;
    } catch (Throwable $e) { error_log('wq_rescue_tables: ' . $e->getMessage()); return $ok = false; }
}
function wq_rescue_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo): string { return function_exists('wq_get') ? (string)wq_get($pdo, $k, $d) : (string)setting_get($pdo, $k, $d); };
    $days = (int)$g('wq_rescue_days', '7'); $wait = (float)$g('wq_rescue_wait_h', '3'); $att = (int)$g('wq_rescue_attempts', '5'); $dd = (int)$g('wq_rescue_decide_days', '4'); $mo = (int)$g('wq_rescue_max_open', '10'); $vd = (int)$g('wq_rescue_view_days', '30'); $hl = $g('wq_rescue_hide_long', '1') !== '0'; $bf1 = $g('wq_rescue_bot_first', '1') !== '0'; $sd = (int)$g('wq_rescue_stale_days', '7');
    return ['on' => $g('wq_rescue_on', '1') !== '0', 'days' => $days >= 1 && $days <= 30 ? $days : 7, 'wait_h' => $wait >= 0.5 && $wait <= 48 ? $wait : 3.0, 'attempts' => $att >= 1 && $att <= 30 ? $att : 5, 'decide_days' => $dd >= 1 && $dd <= 30 ? $dd : 4, 'stale_days' => $sd >= 2 && $sd <= 60 ? $sd : 7, 'max_open' => $mo >= 1 && $mo <= 100 ? $mo : 10, 'view_days' => $vd >= 1 && $vd <= 365 ? $vd : 30, 'hide_long' => $hl, 'bot_first' => $bf1];
}
function wq_rescue_save_cfg(PDO $pdo, array $in): void
{
    $m = ['on' => 'wq_rescue_on', 'days' => 'wq_rescue_days', 'wait_h' => 'wq_rescue_wait_h', 'attempts' => 'wq_rescue_attempts', 'decide_days' => 'wq_rescue_decide_days', 'stale_days' => 'wq_rescue_stale_days', 'max_open' => 'wq_rescue_max_open', 'view_days' => 'wq_rescue_view_days', 'hide_long' => 'wq_rescue_hide_long', 'bot_first' => 'wq_rescue_bot_first'];
    foreach ($m as $k => $key) {
        if (!array_key_exists($k, $in)) continue; $v = ($k === 'on' || $k === 'hide_long' || $k === 'bot_first') ? (!empty($in[$k]) ? '1' : '0') : (string)(float)$in[$k];
        if ($k === 'days') $v = (string)max(1, min(30, (int)$in[$k])); if ($k === 'wait_h') $v = (string)max(0.5, min(48, (float)$in[$k])); if ($k === 'attempts') $v = (string)max(1, min(30, (int)$in[$k])); if ($k === 'decide_days') $v = (string)max(1, min(30, (int)$in[$k])); if ($k === 'stale_days') $v = (string)max(2, min(60, (int)$in[$k])); if ($k === 'max_open') $v = (string)max(1, min(100, (int)$in[$k])); if ($k === 'view_days') $v = (string)max(1, min(365, (int)$in[$k]));
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
/* --- priority + the 4-minute rule --- */
/** The automatic Follow-ups engine (wa_follow.php) and Rescue work as one line: the bot follows up first; a chat it is still following up is NOT offered to people; when its plan is finished
 *  with no answer the chat comes to Rescue. phone => ['status' => plan|ready|tpl|done|stopped|none, 'step' => n, 'due_at' => …, 'stop_why' => …]. Empty while the engine is OFF. */
function wq_rescue_fu_map(PDO $pdo, array $phones): array
{
    $o = []; $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones || !function_exists('fu_mode') || fu_mode($pdo) === 'off' || !wq_rescue_cfg($pdo)['bot_first']) return $o;
    try { foreach (array_chunk($phones, 400) as $ch) { $st = $pdo->prepare("SELECT phone, status, step, due_at, stop_why FROM wa_fu_state WHERE phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $st->execute($ch);
        foreach ($st->fetchAll() as $r) $o[(string)$r['phone']] = ['status' => (string)$r['status'], 'step' => (int)$r['step'], 'due_at' => (string)$r['due_at'], 'stop_why' => (string)$r['stop_why']]; } } catch (Throwable $e) { }
    return $o;
}
function wq_rescue_bot_active(?array $f): bool { return $f !== null && in_array((string)$f['status'], ['plan', 'ready', 'tpl'], true); }
/** The labels, in order of urgency. */
function wq_rescue_tiers(): array { return ['act' => ['🔴 Act now', 1], 'today' => ['🟠 Today', 2], 'week' => ['🟡 This week', 3], 'long' => ['⚪ Long shot', 4]]; }
/** Read a chat's messages (oldest first) → what the customer wanted and how often a person tried since their last message. */
function wq_rescue_eval(array $list): array
{
    $inN = 0; $hot = false; $price = false; $person = false; $lastText = ''; $attempts = 0; $lastInTs = 0;
    foreach ($list as $m) {
        if ($m['dir'] === 'in') {
            $inN++; $b = (string)$m['body']; $lastInTs = strtotime((string)$m['ts']); $lastText = $b !== '' ? $b : $lastText; $attempts = 0;      // the customer spoke: our attempts start again
            if (function_exists('wa_hot_intent') && wa_hot_intent($b) !== '') $hot = true; if (wq_rescue_price_intent($b)) $price = true;
        } elseif ($m['dir'] === 'out' && in_array((string)$m['who'], ['emp', 'person'], true)) { $person = true; $attempts++; }
    }
    $why = []; if ($hot) $why[] = 'hot'; if ($price) $why[] = 'price'; if ($inN >= 2 && !$person) $why[] = 'ignored';
    return ['inN' => $inN, 'person' => $person, 'lastText' => $lastText, 'attempts' => $attempts, 'lastInTs' => $lastInTs, 'why' => $why];
}
/** Priority: what the customer wanted + how fresh + how few follow-ups + chat window open + a returning buyer → [score, tier]. */
function wq_rescue_prio(array $why, string $lastIn, int $attempts, bool $repeat): array
{
    $ageH = $lastIn !== '' ? max(0.0, (time() - (int)strtotime($lastIn)) / 3600) : 9999.0; $hot = in_array('hot', $why, true);
    $sc = ($hot ? 60 : (in_array('price', $why, true) ? 35 : 20)) + 5 * max(0, count($why) - 1);
    $sc += $ageH < 1 ? 25 : ($ageH < 6 ? 20 : ($ageH < 24 ? 15 : ($ageH < 72 ? 8 : ($ageH < 168 ? 3 : 0))));
    if ($ageH < 24) $sc += 10; if ($repeat) $sc += 10; $sc -= 5 * min($attempts, 6);
    $tier = ($ageH < 24 && $hot) || $ageH < 6 ? 'act' : ($ageH < 72 ? 'today' : ($ageH < 168 ? 'week' : 'long'));
    return [$sc, $tier];
}
/** The 4-minute rule: chats a person is working right now (the customer wrote / a person wrote / the chat was given / someone is typing within the last N minutes). phone => reason. */
function wq_rescue_busy(PDO $pdo, array $phones): array
{
    $o = []; $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones) return $o;
    $min = function_exists('wq_late_min') ? wq_late_min($pdo) : 4; $cut = date('Y-m-d H:i:s', time() - $min * 60); $tcut = date('Y-m-d H:i:s', time() - 90);
    foreach (array_chunk($phones, 400) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?'));
        try { $q = $pdo->prepare("SELECT phone, last_in_at, assigned_at, assigned_emp FROM wa_contacts WHERE phone IN ($in)"); $q->execute($ch);
            foreach ($q->fetchAll() as $r) { if ((string)$r['last_in_at'] >= $cut) $o[(string)$r['phone']] = 'the customer just wrote'; elseif ((int)$r['assigned_emp'] > 0 && (string)$r['assigned_at'] >= $cut) $o[(string)$r['phone']] = 'the chat was just given to a person'; } } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT phone FROM wa_messages WHERE dir = 'out' AND who IN ('emp','person') AND ts >= ? AND phone IN ($in) GROUP BY phone"); $q->execute(array_merge([$cut], $ch)); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $o[(string)$x] = 'a person is talking with the customer'; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT k FROM wq_meta WHERE k IN ($in) AND v >= ?"); $q->execute(array_merge(array_map(fn($p) => 'typing:' . $p, $ch), [$tcut])); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $o[substr((string)$x, 7)] = 'a person is typing to the customer'; } catch (Throwable $e) { }
    }
    return $o;
}
function wq_rescue_scan(PDO $pdo, float $deadline = 0.0, int $beforeId = 0, bool $all = false): array
{
    $o = ['flagged' => 0, 'checked' => 0, 'candidates' => 0, 'silent' => 0, 'no_signal' => 0, 'all' => 0, 'quiet' => 0, 'ordered' => 0, 'partial' => false, 'bot' => 0, 'error' => '']; if (!wq_rescue_tables($pdo)) { $o['error'] = 'storage'; return $o; } $cfg = wq_rescue_cfg($pdo); if (!$cfg['on']) return $o;
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
    $hasOpen = []; try { foreach ($pdo->query("SELECT DISTINCT phone FROM wq_rescue WHERE status IN ('open','taken','given','stale') OR (status = 'skipped' AND closed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))")->fetchAll(PDO::FETCH_COLUMN) as $x) $hasOpen[(string)$x] = 1; } catch (Throwable $e) { }
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
    $rows = $keep; $fu = wq_rescue_fu_map($pdo, array_column($rows, 'phone'));
    $o['candidates'] = count($rows);
    $msgs = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? ORDER BY id LIMIT 80");
    $ins = $pdo->prepare("INSERT IGNORE INTO wq_rescue (phone, query_id, flagged_at, why, why_text, last_in_at, last_text, status, orig_emp, score, tier) VALUES (?,?,?,?,?,?,?, 'open', ?, ?, ?)"); $repQ = $pdo->prepare("SELECT 1 FROM wq_ord WHERE phone = ? LIMIT 1");
    $seen = $pdo->prepare("INSERT INTO wq_rescue_seen (query_id, in_at, chk) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE in_at = VALUES(in_at), chk = NOW()");
    $nameOfWhy = ['hot' => 'wanted to pay / order', 'price' => 'asked the price, no order yet', 'ignored' => 'wrote 2+ times, no person answered'];
    foreach ($rows as $r) {
        if ($deadline > 0 && microtime(true) > $deadline) { $o['partial'] = true; break; } $o['checked']++;
        $msgs->execute([(string)$r['phone'], (string)$r['opened_at']]); $list = $msgs->fetchAll(); try { $seen->execute([(int)$r['id'], (string)$r['last_in_at']]); } catch (Throwable $e) { } if (!$list) continue;
        $ev = wq_rescue_eval($list); if ($ev['inN'] < 1) continue;
        // The customer showed interest and no order came: it is an opportunity UNTIL a person has tried 'attempts' times since the customer's last message with no answer.
        if ($ev['attempts'] >= $cfg['attempts']) { $o['silent']++; continue; }
        $why = $ev['why']; $lastText = $ev['lastText']; if (!$why) { $o['no_signal']++; continue; }
        $f = $fu[(string)$r['phone']] ?? null;
        if (wq_rescue_bot_active($f)) { $o['bot']++; continue; }                                                  // the bot is still following this customer up: not yet
        if ($f && stripos((string)$f['stop_why'], 'frustrat') !== false) continue;                                  // the bot saw an upset customer: not a sales chance
        $txt = implode('; ', array_map(fn($w) => $nameOfWhy[$w], $why)); if ($f && (string)$f['status'] === 'done') $txt .= '; the bot follow-ups got no answer';
        $ins->execute([(string)$r['phone'], (int)$r['id'], date('Y-m-d H:i:s'), implode(',', $why), $txt, (string)$r['last_in_at'], mb_substr(trim($lastText), 0, 200), (int)$r['assigned_emp'], ...(function () use ($why, $r, $ev, $repQ) { $repQ->execute([(string)$r['phone']]); return wq_rescue_prio($why, (string)$r['last_in_at'], (int)$ev['attempts'], (bool)$repQ->fetchColumn()); })()]);
        if ($ins->rowCount() > 0) { $o['flagged']++; $hasOpen[(string)$r['phone']] = 1; if (function_exists('wq_tag_put')) wq_tag_put($pdo, (string)$r['phone'], 'effort_missed'); }
    }
    return $o;
}
/** Close the loop: a rescue query somebody took (the chat was given / taken after it was flagged) → taken; an order after that → won (a count, nothing about pay);
 *  the person who took it is NOT talking with the customer (nothing sent within the 4-minute limit, or the customer waits 4+ minutes again) → the chat is read again and goes back to the list;
 *  an order without anybody taking it → "ordered anyway"; N follow-up attempts with no answer → silent; the list never expires by age. */
function wq_rescue_settle(PDO $pdo): array
{
    $o = ['taken' => 0, 'won' => 0, 'ordered' => 0, 'returned' => 0, 'silent' => 0, 'prio' => 0, 'stale' => 0, 'deep' => 0]; if (!wq_rescue_tables($pdo)) return $o; $cfg = wq_rescue_cfg($pdo);
    $lim = function_exists('wq_late_min') ? wq_late_min($pdo) : 4;
    try {
        $rows = $pdo->query("SELECT * FROM wq_rescue WHERE (status IN ('open','taken','given') AND flagged_at >= DATE_SUB(NOW(), INTERVAL 400 DAY)) OR (status = 'won' AND taken_at >= DATE_SUB(NOW(), INTERVAL 40 DAY)) ORDER BY id LIMIT 1500")->fetchAll();
        if (!$rows) return $o;
        // ONE batch of queries for everything the rows need (contacts, orders, 'Order completed', returning buyers) — not 4 queries per row
        $phones = array_values(array_unique(array_map('strval', array_column($rows, 'phone')))); $cmap = []; $omap = []; $dmap = []; $repeat = []; $since = date('Y-m-d H:i:s', time() - 45 * 86400);
        foreach (array_chunk($phones, 400) as $ch) {
            $in = implode(',', array_fill(0, count($ch), '?'));
            $st = $pdo->prepare("SELECT phone, assigned_emp, assigned_at, last_in_at FROM wa_contacts WHERE phone IN ($in)"); $st->execute($ch); foreach ($st->fetchAll() as $x) $cmap[(string)$x['phone']] = $x;
            $st = $pdo->prepare("SELECT phone, order_name, total, created_at FROM wq_ord WHERE phone IN ($in) AND created_at >= ? ORDER BY created_at"); $st->execute(array_merge($ch, [$since])); foreach ($st->fetchAll() as $x) $omap[(string)$x['phone']][] = $x;
            try { $st = $pdo->prepare("SELECT phone, order_name, `at` FROM wa_order_done WHERE phone IN ($in) AND `at` >= ?"); $st->execute(array_merge($ch, [$since])); foreach ($st->fetchAll() as $x) $dmap[(string)$x['phone']] = $x; } catch (Throwable $e) { }
            $st = $pdo->prepare("SELECT DISTINCT phone FROM wq_ord WHERE phone IN ($in)"); $st->execute($ch); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $repeat[(string)$x] = 1;
        }
        // orders after $from: Shopify orders, else the 'Order completed' button (COD / UPI / manual orders that are not in Shopify)
        $ordersFor = function (string $phone, string $from) use ($omap, $dmap): array {
            $out = []; foreach ($omap[$phone] ?? [] as $x) if ((string)$x['created_at'] >= $from) $out[] = $x;
            if (!$out && isset($dmap[$phone]) && (string)$dmap[$phone]['at'] >= $from) $out[] = ['order_name' => (string)$dmap[$phone]['order_name'] !== '' ? (string)$dmap[$phone]['order_name'] : 'order completed', 'total' => 0, 'created_at' => (string)$dmap[$phone]['at']];
            return $out; };
        $upT = $pdo->prepare("UPDATE wq_rescue SET status = 'taken', taken_by = ?, taken_at = ? WHERE id = ? AND status = 'open'");
        $upW = $pdo->prepare("UPDATE wq_rescue SET status = 'won', order_name = ?, order_total = ?, won_at = ?, closed_at = COALESCE(closed_at, ?) WHERE id = ?");
        $upO = $pdo->prepare("UPDATE wq_rescue SET status = 'ordered', order_name = ?, order_total = ?, closed_at = ? WHERE id = ? AND status = 'open'");
        $att = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person') AND ts > ?");
        $lastP = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person')");
        $upS = $pdo->prepare("UPDATE wq_rescue SET status = 'silent', closed_at = ? WHERE id = ? AND status IN ('open','taken','given')");
        $upSt = $pdo->prepare("UPDATE wq_rescue SET status = 'stale' WHERE id = ? AND status IN ('taken','given')");
        $msgs = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? ORDER BY id DESC LIMIT 60");
        $upR = $pdo->prepare("UPDATE wq_rescue SET status = 'open', taken_by = 0, taken_at = NULL, given_by = 0, flagged_at = ?, returns = returns + 1, why = ?, why_text = ?, last_in_at = ?, last_text = ?, score = ?, tier = ?, chk_at = ? WHERE id = ? AND status IN ('taken','given')");
        $upP = $pdo->prepare("UPDATE wq_rescue SET score = ?, tier = ?, last_in_at = ?, chk_at = ? WHERE id = ?");
        $nameOfWhy = ['hot' => 'wanted to pay / order', 'price' => 'asked the price, no order yet', 'ignored' => 'wrote 2+ times, no person answered'];
        $recheck = date('Y-m-d H:i:s', time() - 30 * 60);
        foreach ($rows as $r) {
            $now = date('Y-m-d H:i:s'); $ph = (string)$r['phone']; $c = $cmap[$ph] ?? [];
            if ($r['status'] === 'open' && $c && (int)$c['assigned_emp'] > 0 && (string)$c['assigned_at'] > (string)$r['flagged_at']) {   // somebody was given / took the chat after it was flagged → taken (Assign, Take over or Give)
                $upT->execute([(int)$c['assigned_emp'], (string)$c['assigned_at'], (int)$r['id']]); if ($upT->rowCount()) { $r['status'] = 'taken'; $r['taken_by'] = (int)$c['assigned_emp']; $r['taken_at'] = (string)$c['assigned_at']; $o['taken']++; }
            }
            if (in_array($r['status'], ['taken', 'given', 'won'], true) && (int)$r['taken_by'] > 0) {
                $all = $ordersFor($ph, (string)$r['taken_at']);
                if ($all) { $sum = 0.0; foreach ($all as $x) $sum += (float)$x['total']; $upW->execute([(string)$all[0]['order_name'], round($sum, 2), (string)$all[0]['created_at'], $now, (int)$r['id']]); if ($r['status'] !== 'won') $o['won']++; continue; }
            } elseif ($r['status'] === 'open') {
                $all = $ordersFor($ph, (string)$r['flagged_at']);
                if ($all) { $x = $all[0]; $upO->execute([(string)$x['order_name'], (float)$x['total'], $now, (int)$r['id']]); $o['ordered'] += $upO->rowCount(); continue; }
            }
            if ($r['status'] === 'won') continue;
            $lin = (string)($c['last_in_at'] ?? $r['last_in_at']);
            // the slow checks (a query per chat) only for chats that changed, that somebody took, or that were not looked at for 30 minutes
            $deep = $r['status'] !== 'open' || empty($r['chk_at']) || (string)$r['chk_at'] < $recheck || $lin !== (string)$r['last_in_at'];
            if (!$deep) continue; $o['deep']++;
            $att->execute([$ph, $lin !== '' ? $lin : '2000-01-01']); $attempts = (int)$att->fetchColumn();
            if ($lin !== '' && $attempts >= $cfg['attempts']) { $upS->execute([$now, (int)$r['id']]); $o['silent'] += $upS->rowCount(); continue; }
            if (in_array($r['status'], ['taken', 'given'], true) && $r['taken_at'] && strtotime((string)$r['taken_at']) <= time() - $cfg['stale_days'] * 86400) { $upSt->execute([(int)$r['id']]); $o['stale']++; continue; }   // nobody decided for a week: it waits for the owner
            // the 4-minute check on a TAKEN chat: is the person really talking with the customer?
            if (in_array($r['status'], ['taken', 'given'], true) && $r['taken_at'] && strtotime((string)$r['taken_at']) <= time() - $lim * 60) {
                $lastP->execute([$ph]); $lp = (string)$lastP->fetchColumn();
                $silentHolder = $lp === '' || $lp < (string)$r['taken_at'];                                   // nothing sent since the chat was taken
                $waiting = $lin !== '' && $lin > $lp && strtotime($lin) <= time() - $lim * 60;                  // the customer wrote again and has waited past the limit
                if ($silentHolder || $waiting) {
                    $msgs->execute([$ph]); $ev = wq_rescue_eval(array_reverse($msgs->fetchAll()));   // read the chat again
                    $why = $ev['why'] ?: array_values(array_filter(explode(',', (string)$r['why'])));
                    [$sc, $tier] = wq_rescue_prio($why, $lin, (int)$ev['attempts'], isset($repeat[$ph]));
                    $upR->execute([$now, implode(',', $why), implode('; ', array_map(fn($w) => $nameOfWhy[$w] ?? $w, $why)), $lin, mb_substr(trim($ev['lastText'] !== '' ? $ev['lastText'] : (string)$r['last_text']), 0, 200), $sc, $tier, $now, (int)$r['id']]); $o['returned'] += $upR->rowCount(); continue;
                }
            }
            if ($r['status'] === 'open') {   // refresh the score (the customer may have written again / been tried)
                [$sc, $tier] = wq_rescue_prio(array_values(array_filter(explode(',', (string)$r['why']))), $lin, $attempts, isset($repeat[$ph]));
                $upP->execute([$sc, $tier, $lin !== '' ? $lin : null, $now, (int)$r['id']]); $o['prio']++;
            }
        }
        // time passes: the urgency label of every waiting chat is moved by ONE statement (no per-chat work)
        $pdo->exec("UPDATE wq_rescue SET tier = CASE WHEN last_in_at IS NULL THEN 'long' WHEN (last_in_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND FIND_IN_SET('hot', why)) OR last_in_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 'act'
            WHEN last_in_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR) THEN 'today' WHEN last_in_at >= DATE_SUB(NOW(), INTERVAL 168 HOUR) THEN 'week' ELSE 'long' END WHERE status = 'open'");
    } catch (Throwable $e) { error_log('wq_rescue_settle: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $o;
}
/** The AI's second opinion on a "Not a chance": it reads the chat; real scope → the chat goes back into Rescue (once); it agrees → it stays in Not a chance. At most $max per call. */
function wq_rescue_ai_review(PDO $pdo, float $deadline, int $max = 4): array
{
    $o = ['reviewed' => 0, 'returned' => 0, 'agreed' => 0, 'failed' => 0]; if (!function_exists('wq_ai')) return $o;
    try {
        $q = $pdo->query("SELECT * FROM wq_rescue WHERE status = 'skipped' AND ai_state = 'pending' ORDER BY id LIMIT " . (int)$max); $rows = $q->fetchAll();
        $msgs = $pdo->prepare("SELECT dir, who, body, ts FROM wa_messages WHERE phone = ? AND dir IN ('in','out') ORDER BY id DESC LIMIT 40");
        $sch = ['type' => 'OBJECT', 'properties' => ['chance' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['chance', 'reason']];
        $up = $pdo->prepare("UPDATE wq_rescue SET ai_state = ?, ai_note = ? WHERE id = ? AND status = 'skipped'");
        $back = $pdo->prepare("UPDATE wq_rescue SET status = 'open', taken_by = 0, taken_at = NULL, closed_at = NULL, flagged_at = ?, ai_state = 'returned', ai_note = ?, score = ?, tier = ? WHERE id = ? AND status = 'skipped'");
        $why = ['no_reply' => 'the customer stopped replying', 'price' => 'the price was the problem', 'other' => 'another reason'];
        foreach ($rows as $r) {
            if (microtime(true) > $deadline) break; $o['reviewed']++;
            $msgs->execute([(string)$r['phone']]); $list = array_reverse($msgs->fetchAll()); $lines = [];
            foreach ($list as $m) { $who = $m['dir'] === 'in' ? 'Customer' : (in_array((string)$m['who'], ['emp', 'person'], true) ? 'Salesperson' : 'Bot'); $lines[] = $who . ': ' . mb_substr(trim((string)$m['body']), 0, 220); }
            $ctx = 'The salesperson marked this customer "not a chance" (' . ($why[(string)$r['skip_reason']] ?? 'no reason given') . ").\nThe chat, oldest first:\n" . implode("\n", $lines);
            [$ok, $v] = wq_ai($pdo, "You are a careful sales coach for a perfume shop's WhatsApp. A salesperson gave up on a customer. Decide whether there is still a REALISTIC chance to win an order (for example the customer only asked the price and went quiet, said 'later', asked about something we can offer, or was never answered properly) or truly NO chance (the customer clearly said they are not interested, bought elsewhere, was rude, wrong number, or only wants a refund / complaint). Answer chance true or false and ONE short reason in plain English.", $ctx, $sch);
            if (!$ok || !is_array($v)) { $up->execute(['unreviewed', 'The AI could not check this one.', (int)$r['id']]); $o['failed']++; continue; }
            $note = mb_substr(trim((string)($v['reason'] ?? '')), 0, 230);
            if (!empty($v['chance'])) {
                $ev = wq_rescue_eval($list); $wy = $ev['why'] ?: ['price']; $rep = $pdo->prepare("SELECT 1 FROM wq_ord WHERE phone = ? LIMIT 1"); $rep->execute([(string)$r['phone']]);
                $cs = $pdo->prepare("SELECT last_in_at FROM wa_contacts WHERE phone = ?"); $cs->execute([(string)$r['phone']]); $lin = (string)$cs->fetchColumn();
                [$sc, $tier] = wq_rescue_prio($wy, $lin, 0, (bool)$rep->fetchColumn());
                $back->execute([date('Y-m-d H:i:s'), $note, $sc, $tier, (int)$r['id']]); $o['returned'] += $back->rowCount();
            } else { $up->execute(['agreed', $note, (int)$r['id']]); $o['agreed']++; }
        }
    } catch (Throwable $e) { error_log('wq_rescue_ai_review: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
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
/** The check of recent queries + the settle + the AI steps + the alerts run at most every 4 minutes ($minGap); earlier chats are preloaded in small steps ($bfOnly = only that step, quick).
 *  WhatsApp / the Rescue screen call it when they open and every 5 minutes; the server tick calls it too. A lock stops two runs at once. */
function wq_rescue_refresh(PDO $pdo, int $minGap = 240, bool $bfOnly = false): array
{
    if (!function_exists('wq_meta_get') || !wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on']) return [];
    $lk = 0; try { $lk = (int)$pdo->query("SELECT GET_LOCK('wq_rescue_scan', 0)")->fetchColumn(); } catch (Throwable $e) { $lk = 1; }
    if ($lk !== 1) return ['skipped' => 'busy'];
    try {
        $t0 = microtime(true); $r = [];
        if (!$bfOnly && strtotime(wq_meta_get($pdo, 'rescue_heavy_at', '2000-01-01')) <= time() - $minGap) {
            wq_meta_put($pdo, 'rescue_heavy_at', date('Y-m-d H:i:s'));
            $r['scan'] = wq_rescue_scan($pdo, $t0 + 3); $r['settle'] = wq_rescue_settle($pdo); $r['ai'] = wq_rescue_ai_review($pdo, $t0 + 6);
            if (function_exists('wq_rescue_alerts_tick')) $r['alerts'] = wq_rescue_alerts_tick($pdo, $t0 + 9);   // AI check of new chats, push alerts, the owner's end-of-day summary
            wq_meta_put($pdo, 'rescue_last', json_encode(['at' => date('Y-m-d H:i:s')] + $r));
        } elseif (!$bfOnly) $r['skipped'] = 'recent';
        $bf = json_decode(wq_meta_get($pdo, 'rescue_bf', 'null'), true);
        if (!is_array($bf) || empty($bf['done'])) $r['backfill'] = wq_rescue_backfill($pdo, microtime(true) + ($bfOnly ? 5 : 3));
        return $r;
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wq_rescue_scan')"); } catch (Throwable $e) { } }
}
/** Called from wq_tick() (full run): scan at most every 10 minutes, settle every time. */
function wq_rescue_tick(PDO $pdo, float $deadline): array
{
    if (!wq_rescue_tables($pdo)) return []; if (!wq_rescue_cfg($pdo)['on']) return ['off' => true];
    return wq_rescue_refresh($pdo, 240);   // every 5 minutes (by the cron / WhatsApp tick, even when nobody has the screen open)
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
        'bot' => !empty($r['bot']) && wq_rescue_bot_active($r['bot']) ? ['step' => (int)$r['bot']['step'], 'due_at' => (string)$r['bot']['due_at']] : null, 'bot_gave_up' => !empty($r['bot']) && (string)$r['bot']['status'] === 'done', 'skip_reason' => (string)($r['skip_reason'] ?? ''), 'ai_state' => (string)($r['ai_state'] ?? ''), 'ai_note' => (string)($r['ai_note'] ?? ''), 'skipped_by' => ['emp_id' => (int)($r['skipped_by'] ?? 0), 'name' => (string)($names[(int)($r['skipped_by'] ?? 0)] ?? '')], 'tier' => (string)($r['tier'] ?? 'week'), 'tier_label' => (wq_rescue_tiers()[(string)($r['tier'] ?? 'week')] ?? ['', 0])[0], 'score' => (int)($r['score'] ?? 0), 'returns' => (int)($r['returns'] ?? 0), 'taken_at' => (string)$r['taken_at'], 'order_name' => (string)$r['order_name'], 'order_total' => round((float)$r['order_total'], 2)];
}
function wq_rescue_list(PDO $pdo, int $me, bool $adm, int $days, string $status, int $page = 1): array
{
    $names = function_exists('wq_names') ? wq_names($pdo) : []; $cfg = wq_rescue_cfg($pdo); $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
    $where = "(r.flagged_at >= ? OR r.status IN ('open','taken','given','stale'))"; if (!($adm && $status === 'skipped')) $where .= " AND r.status <> 'skipped'"; if ($adm && $status !== 'silent') $where .= " AND r.status <> 'silent'"; $par = [$since];
    if (!$adm) {   // a team member sees the open chances + the ones they took; nothing else
        $where .= " AND (r.status = 'open' OR r.taken_by = ?) AND r.status NOT IN ('ordered','expired','silent','skipped')"; $par[] = $me;
    } elseif ($status !== '' && $status !== 'all') { $where .= " AND r.status = ?"; $par[] = $status; }
    $q = $pdo->prepare("SELECT r.* FROM wq_rescue r WHERE $where ORDER BY FIELD(r.status,'open','given','taken','stale','won','ordered','expired','silent','skipped'), FIELD(r.tier,'act','today','week','long'), r.score DESC, r.last_in_at DESC LIMIT " . ($PS = 100) . " OFFSET " . (max(1, $page) - 1) * 100);
    $q->execute($par); $rowsL = $q->fetchAll(); $cq = $pdo->prepare("SELECT COUNT(*) FROM wq_rescue r WHERE $where"); $cq->execute($par); $total = (int)$cq->fetchColumn(); $cn = wq_rescue_cnames($pdo, array_column($rowsL, 'phone')); foreach ($rowsL as &$rl) $rl['cname'] = $cn[(string)$rl['phone']] ?? ''; unset($rl);
    $fuL = wq_rescue_fu_map($pdo, array_column($rowsL, 'phone')); foreach ($rowsL as &$rl2) $rl2['bot'] = $fuL[(string)$rl2['phone']] ?? null; unset($rl2);
    $items = array_map(fn($r) => wq_rescue_item($r, $names), $rowsL);
    $st = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(order_total),0) rev FROM wq_rescue WHERE flagged_at >= ? OR status IN ('open','taken','given','stale') GROUP BY status"); $st->execute([$since]);
    $tc = ['act' => 0, 'today' => 0, 'week' => 0, 'long' => 0]; try { foreach ($pdo->query("SELECT tier, COUNT(*) n FROM wq_rescue WHERE status = 'open' GROUP BY tier")->fetchAll() as $t) $tc[(string)$t['tier']] = (int)$t['n']; } catch (Throwable $e) { }
    $sum = ['tiers' => $tc, 'open' => 0, 'taken' => 0, 'won' => 0, 'ordered' => 0, 'expired' => 0, 'silent' => 0, 'skipped' => 0, 'stale' => 0, 'revenue' => 0.0];
    foreach ($st->fetchAll() as $s) { $k = (string)$s['status']; if ($k === 'given') $k = 'taken'; if (isset($sum[$k])) $sum[$k] += (int)$s['n']; if ($k === 'won') $sum['revenue'] = round((float)$s['rev'], 2); }
    return ['ok' => true, 'cfg' => $cfg, 'days' => $days, 'admin' => $adm, 'member' => wq_rescue_is_member($pdo, $me), 'last_scan' => function_exists('wq_meta_get') ? json_decode(wq_meta_get($pdo, 'rescue_last', 'null'), true) : null, 'backfill' => function_exists('wq_meta_get') ? json_decode(wq_meta_get($pdo, 'rescue_bf', 'null'), true) : null, 'fu_mode' => function_exists('fu_mode') ? fu_mode($pdo) : 'off', 'summary' => $sum, 'items' => $items, 'page' => max(1, $page), 'page_size' => 100, 'total' => $total];
}
/** The phones of the 🛟 Rescue chip: admin = every open / taken rescue chat; team member = the open pool + the ones they took. Empty for anybody else. */
function wq_rescue_chat_phones(PDO $pdo, int $me, bool $adm): array
{
    if (!wq_rescue_tables($pdo) || !wq_rescue_cfg($pdo)['on'] || (!$adm && !wq_rescue_is_member($pdo, $me))) return [];
    try {
        $ord = "ORDER BY FIELD(tier,'act','today','week','long'), score DESC, last_in_at DESC LIMIT 1500"; $cfg = wq_rescue_cfg($pdo);
        // the team sees only recent chats (view_days) and, by default, not the ⚪ long shots — so the list stays small enough to work; the owner sees everything
        $vis = "(status = 'open' AND last_in_at >= '" . date('Y-m-d H:i:s', time() - $cfg['view_days'] * 86400) . "'" . ($cfg['hide_long'] ? " AND tier <> 'long'" : '') . ")";
        $q = $adm ? $pdo->query("SELECT phone, status FROM wq_rescue WHERE status IN ('open','taken','given') $ord")
                  : $pdo->prepare("SELECT phone, status FROM wq_rescue WHERE $vis OR (status IN ('taken','given') AND taken_by = ?) $ord");
        if (!$adm) $q->execute([$me]); $rows = $q->fetchAll();
        $busy = wq_rescue_busy($pdo, array_column($rows, 'phone')); $o = []; $fu = $adm ? [] : wq_rescue_fu_map($pdo, array_column($rows, 'phone'));   // the 4-minute rule: a chat somebody is working right now is not offered; nor one the bot is following up
        foreach ($rows as $r) { if ($r['status'] === 'open' && (isset($busy[(string)$r['phone']]) || wq_rescue_bot_active($fu[(string)$r['phone']] ?? null))) continue; $o[(string)$r['phone']] = 1; }
        return array_keys($o);
    } catch (Throwable $e) { return []; }
}
/** phone => ['tier' => act|today|week|long, 'label' => '🔴 Act now', 'score' => n] for the chip rows. */
function wq_rescue_tier_map(PDO $pdo, array $phones): array
{
    $o = []; $phones = array_values(array_unique(array_map('strval', $phones))); if (!$phones) return $o; $T = wq_rescue_tiers();
    try { foreach (array_chunk($phones, 400) as $ch) { $st = $pdo->prepare("SELECT phone, tier, score, status FROM wq_rescue WHERE status IN ('open','taken','given') AND phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $st->execute($ch);
        foreach ($st->fetchAll() as $r) $o[(string)$r['phone']] = ['tier' => (string)$r['tier'], 'label' => ($T[(string)$r['tier']] ?? ['', 0])[0], 'score' => (int)$r['score'], 'status' => (string)$r['status']]; } } catch (Throwable $e) { }
    return $o;
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
    try { $q = $pdo->prepare("SELECT 1 FROM wq_rescue WHERE phone = ? AND status = 'open' LIMIT 1"); $q->execute([$key]); return (bool)$q->fetchColumn() && !wq_rescue_busy($pdo, [$key]); } catch (Throwable $e) { return false; }
}
/** Rescue chats for the salesperson app's request list (wq.req_list): the same shape as a request, flagged rescue. */
function wq_rescue_req_items(PDO $pdo, int $me): array
{
    $ph = wq_rescue_chat_phones($pdo, $me, false); if (!$ph) return [];
    try {
        $in = implode(',', array_fill(0, count($ph), '?'));
        $q = $pdo->prepare("SELECT r.phone, r.last_in_at, r.last_text, r.why_text, r.tier, r.score FROM wq_rescue r WHERE r.status = 'open' AND r.phone IN ($in)"); $q->execute($ph); $byPhone = []; foreach ($q->fetchAll() as $r) $byPhone[(string)$r['phone']] = $r;
        $cn = wq_rescue_cnames($pdo, $ph); $T = wq_rescue_tiers(); $o = [];
        foreach ($ph as $p) { if (!isset($byPhone[$p])) continue; $r = $byPhone[$p];   // $ph is already in priority order
            $o[] = ['phone' => $p, 'name' => (string)($cn[$p] ?? ''), 'mins' => max(0, (int)floor((time() - strtotime((string)$r['last_in_at'])) / 60)), 'answered' => false, 'last_in' => mb_substr((string)$r['last_text'], 0, 120), 'src' => 'rescue', 'rescue' => true, 'tier' => (string)$r['tier'], 'tier_label' => ($T[(string)$r['tier']] ?? ['', 0])[0], 'why' => (string)$r['why_text']]; }
        return array_slice($o, 0, 100);
    } catch (Throwable $e) { return []; }
}
/** The rescue chats this person took (last 30 days): days since, order placed?, and "decide" once past the decide days with no order. */
function wq_rescue_mine_items(PDO $pdo, int $me): array
{
    if ($me <= 0) return []; $cfg = wq_rescue_cfg($pdo);
    try {
        $q = $pdo->prepare("SELECT phone, status, taken_at, order_name, order_total, why_text, tier FROM wq_rescue WHERE taken_by = ? AND status IN ('taken','given','stale','won') AND taken_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY FIELD(status,'stale','taken','given','won'), taken_at ASC LIMIT 200"); $q->execute([$me]); $rows = $q->fetchAll();
        $cn = wq_rescue_cnames($pdo, array_column($rows, 'phone')); $o = [];
        foreach ($rows as $r) { $d = (int)floor((time() - strtotime((string)$r['taken_at'])) / 86400);
            $o[] = ['phone' => (string)$r['phone'], 'name' => (string)($cn[(string)$r['phone']] ?? ''), 'status' => (string)$r['status'], 'days' => $d, 'ordered' => $r['status'] === 'won', 'order_name' => (string)$r['order_name'], 'decide' => $r['status'] !== 'won' && $d >= $cfg['decide_days'], 'why' => (string)$r['why_text']]; }
        return $o;
    } catch (Throwable $e) { return []; }
}
/** What the salesperson app asks every 5 seconds (is this person on the team + their lists), kept for 45 seconds per person so the poll costs one small query. Dropped when they take / remove a chat or the team changes. */
function wq_rescue_req_cached(PDO $pdo, int $me): array
{
    if ($me <= 0 || !wq_rescue_tables($pdo)) return [];
    $k = 'rescue_req:' . $me; $raw = function_exists('wq_meta_get') ? wq_meta_get($pdo, $k, '') : ''; $c = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($c) && (int)($c['t'] ?? 0) > time() - 45) return (array)($c['d'] ?? []);
    $d = [];
    if (wq_rescue_is_member($pdo, $me)) $d = ['rescue_member' => true, 'rescue' => wq_rescue_req_items($pdo, $me), 'rescue_mine' => wq_rescue_mine_items($pdo, $me), 'rescue_cfg' => ['decide_days' => wq_rescue_cfg($pdo)['decide_days']]];
    if (function_exists('wq_meta_put')) wq_meta_put($pdo, $k, json_encode(['t' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE));
    return $d;
}
function wq_rescue_req_forget(PDO $pdo, int $me = 0): void
{
    try { if ($me > 0) $pdo->prepare("DELETE FROM wq_meta WHERE k = ?")->execute(['rescue_req:' . $me]); else $pdo->exec("DELETE FROM wq_meta WHERE k LIKE 'rescue_req:%'"); } catch (Throwable $e) { }
}
/** Take a flagged query: the chat moves to $me and checked again at the moment of taking. */
function wq_rescue_take(PDO $pdo, string $phone, int $me, int $by = 0, string $how = 'rescue'): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can take a query.'];
    $q = $pdo->prepare("SELECT * FROM wq_rescue WHERE phone = ? AND status IN ('open','given') ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $r = $q->fetch();
    if (!$r) return ['error' => 'This query is not in the rescue list any more (taken, ordered or expired).'];
    $c = function_exists('wq_contact') ? wq_contact($pdo, $phone) : []; if (!$c) return ['error' => 'Chat not found.'];
    if ((int)($c['optout'] ?? 0) === 1) return ['error' => 'This customer opted out - nobody may message them.'];
    if ($by <= 0 && wq_rescue_bot_active(wq_rescue_fu_map($pdo, [$phone])[$phone] ?? null)) return ['error' => 'The bot is still following up this customer. If they do not answer, the chat comes back to Rescue.'];
    $busy = wq_rescue_busy($pdo, [$phone]); if ($busy && $by <= 0) return ['error' => 'Somebody is working with this customer right now (' . $busy[$phone] . ') — it comes back to the list if they stop for ' . (function_exists('wq_late_min') ? wq_late_min($pdo) : 4) . ' minutes.'];
    if ($by <= 0) {   // fair split: nobody hoards — a cap on the rescue chats one person has open at once (the owner's Give to… is not limited)
        $mo = wq_rescue_cfg($pdo)['max_open']; $oc = $pdo->prepare("SELECT COUNT(*) FROM wq_rescue WHERE taken_by = ? AND status IN ('taken','given')"); $oc->execute([$me]);
        if ((int)$oc->fetchColumn() >= $mo) return ['error' => 'You already have ' . $mo . ' rescue chats open. Reply to them, or close the ones that are not a chance, before you take another.'];
    }
    $now = date('Y-m-d H:i:s'); $origEmp = (int)($c['assigned_emp'] ?? 0);
    $u = $pdo->prepare("UPDATE wq_rescue SET status = 'taken', taken_by = ?, taken_at = ?, given_by = ? WHERE id = ? AND status IN ('open','given')"); $u->execute([$me, $now, $by > 0 && $by !== $me ? $by : 0, (int)$r['id']]);
    if ($u->rowCount() === 0) return ['error' => 'Somebody else just took this query.'];
    wq_rescue_req_forget($pdo, $me);
    if ((int)($c['assigned_emp'] ?? 0) !== $me && function_exists('wa_assign_to')) wa_assign_to($pdo, $phone, $me, $by > 0 ? $by : $me, $how);
    if ($origEmp > 0 && $origEmp !== $me && function_exists('ct_push')) {   // 🔔 tell the person who had the chat — no surprises
        try { $nm = function_exists('wq_names') ? wq_names($pdo) : []; ct_push($pdo, [$origEmp], '🛟 Rescue', trim(($c['name'] ?? '') !== '' ? (string)$c['name'] : '+91 ' . $phone) . ' had no follow-up, so ' . (string)($nm[$me] ?? 'a team member') . ' took the chat to win the order.', '/my_chats.html?phone=' . $phone, 'wq-rescue-moved-' . $phone); } catch (Throwable $e) { }
    }
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
            json_response(wq_rescue_list($pdo, $me, $adm, max(1, min(400, (int)($in['days'] ?? $cfgR['days']))), (string)($in['status'] ?? 'all'), (int)($in['page'] ?? 1)));
        }
        case 'wq.rescue_take': {
            $needPost(); if (!wq_rescue_is_member($pdo, $me) && !$adm) json_response(['error' => 'You are not on today\'s rescue team.'], 403);
            $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_rescue_take($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r);
        }
        case 'wq.rescue_give': {
            $needPost(); $needAdmin(); $k = $phoneIn(); $emp = (int)($in['emp_id'] ?? 0); if ($k === '' || $emp <= 0) json_response(['error' => 'Choose a chat and a person.'], 422);
            $r = wq_rescue_take($pdo, $k, $emp, $me, 'rescue'); if (isset($r['error'])) json_response($r, 409); json_response($r);
        }
        case 'wq.rescue_skip': {   // "Not a chance": the owner or a rescue-team member removes a chat from the list (the owner can see who did it and put it back)
            $needPost(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404);
            if (!empty($in['undo'])) { $needAdmin(); $pdo->prepare("UPDATE wq_rescue SET status = 'open', skipped_by = 0, ai_state = '', ai_note = '', closed_at = NULL, flagged_at = ? WHERE phone = ? AND status = 'skipped'")->execute([date('Y-m-d H:i:s'), $k]); json_response(['ok' => true]); }
            if (!$adm && !wq_rescue_is_member($pdo, $me)) json_response(['error' => 'You are not on the rescue team.'], 403);
            $rs = in_array((string)($in['reason'] ?? ''), ['price', 'no_reply', 'other'], true) ? (string)$in['reason'] : '';
            // a salesperson's "not a chance" gets the AI's second opinion once; the owner's is final; a second one on a chat the AI already put back is final
            $aiS = $adm ? "'final'" : "IF(ai_state = 'returned', 'final', 'pending')";
            $u = $adm ? $pdo->prepare("UPDATE wq_rescue SET status = 'skipped', skipped_by = ?, skip_reason = ?, ai_state = $aiS, ai_note = '', closed_at = ? WHERE phone = ? AND status IN ('open','given','taken','stale')")
                      : $pdo->prepare("UPDATE wq_rescue SET status = 'skipped', skipped_by = ?, skip_reason = ?, ai_state = $aiS, ai_note = '', closed_at = ? WHERE phone = ? AND (status = 'open' OR (status IN ('taken','given','stale') AND taken_by = " . (int)$me . "))");
            $u->execute([$me, $rs, date('Y-m-d H:i:s'), $k]); wq_rescue_req_forget($pdo, $adm ? 0 : $me); if ($u->rowCount() === 0) json_response(['error' => 'This customer is not in the rescue list any more.'], 409);
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
            $pdo->prepare("DELETE FROM wq_rescue_team WHERE day = ?")->execute([$day]); wq_rescue_req_forget($pdo, 0);
            $ins = $pdo->prepare("INSERT IGNORE INTO wq_rescue_team (day, emp_id, added_by) VALUES (?,?,?)"); foreach ($ids as $i) $ins->execute([$day, $i, $me]);
            if ($ids && function_exists('ct_push')) { try { ct_push($pdo, $ids, 'Rescue queries', 'You are on the rescue team: open WhatsApp → Chats → 🛟 Rescue and take the customers who were not followed up.', '/whatsapp.html#chats', 'wq-rescue-team'); } catch (Throwable $e) { } }
            json_response(['ok' => true, 'day' => $day, 'emp_ids' => $ids]);
        }
        case 'wq.rescue_cfg': {
            if (!$isPost) json_response(['ok' => true, 'cfg' => $cfgR]); $needAdmin(); wq_rescue_save_cfg($pdo, $in); json_response(['ok' => true, 'cfg' => wq_rescue_cfg($pdo)]);
        }
        case 'wq.rescue_scan': {
            $needPost(); $r = wq_rescue_refresh($pdo, 240, !empty($in['backfill'])); json_response(['ok' => true] + $r);
        }
    } } catch (Throwable $e) { error_log('wq_rescue: ' . $e->getMessage()); json_response(['error' => 'Rescue queries: ' . mb_substr($e->getMessage(), 0, 240)], 500); }
}
