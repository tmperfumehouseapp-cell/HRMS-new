<?php
/**
 * wa_growth.php — the "growth engine" of the HRMS WhatsApp section. Loaded by wa_more.php (nothing to change in api.php).
 *
 * WHAT IS HERE
 *   Phone panel for salespeople   wa.g_inbox · wa.g_poll · wa.g_sugg · wa.g_customer · wa.g_note_add · wa.g_gallery · wa.g_thumb · wa.g_me · wa.g_push_state
 *   Every chat ends with a result  wa.g_outcome (and wa.close refuses to close a chat that has no result — see wag_close_guard)
 *   Not answered in N minutes      wa.g_signals · wa.g_reassign · wa.g_reassign_all   (a chat nobody answered jumps to the top; admins get a signal)
 *   Tags                           wa.g_tags (counts 30/60/90 days) · wa.g_tag_set · wa.g_tag_claude · wa.g_tag_batch
 *   Message library (fixed sets)   wa.g_lib · wa.g_lib_save · wa.g_lib_status · wa.g_lib_words · wa.g_lib_check
 *   Message builder (Utility first) wa.g_build  → drafts, submitted with the existing wa.tpl_submit
 *   Claude growth engine           wa.g_claude (what needs fixing, what is not built yet) · wa.g_claude_ask
 *   Settings / 24-7 bot            wa.g_cfg · wa.g_cfg_save · bot_scan runs inside wa_tick
 *   Ads · CSV · Claude button      wa.g_ads · wa.g_export · wa.g_hq_me · wa.g_hq_get · wa.g_hq_set
 *   Flows (wa_flows.php)           wa.g_flows · wa.g_flow_save · wa.g_flow_toggle · wa.g_flow_del · wa.g_flow_test · wa.g_flow_stats
 *
 * RULES THAT ARE KEPT HERE
 *   - A salesperson only ever sees / touches chats assigned to them (wa_can_chat). Bulk sending stays Admin only (nothing here sends bulk).
 *   - Order of the chat list: a chat the customer wrote to and nobody answered for N minutes (default 3) is on top (longest wait first),
 *     then chats waiting for a reply (newest first), then the rest by the customer's last message. OUR sends (campaigns too) never move a chat.
 *   - Everything defensive: a failure here is written to the WhatsApp log and never breaks the chat screens.
 */
if (!function_exists('wag_tables')) {

function wag_defaults(): array
{
    return ['unans_min' => 3, 'unans_push' => true, 'bot' => 'off', 'bot_after' => 12, 'bot_max_day' => 8, 'target' => 5, 'vip_orders' => 4, 'vip_ltv' => 10000, 'ai_tags' => true, 'sound' => true];
}
function wag_cfg(PDO $pdo): array
{
    $d = wag_defaults();
    try { $j = json_decode((string)setting_get($pdo, 'wa_g_cfg', ''), true); } catch (Throwable $e) { $j = null; }
    if (is_array($j)) foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = $j[$k];
    $d['unans_min'] = max(1, min(60, (int)$d['unans_min'])); $d['bot_after'] = max(5, min(600, (int)$d['bot_after'])); $d['bot_max_day'] = max(1, min(40, (int)$d['bot_max_day']));
    $d['target'] = max(1, min(200, (int)$d['target'])); $d['vip_orders'] = max(2, min(50, (int)$d['vip_orders'])); $d['vip_ltv'] = max(1000, (int)$d['vip_ltv']);
    $d['bot'] = $d['bot'] === 'on' ? 'on' : 'off'; foreach (['unans_push', 'ai_tags', 'sound'] as $b) $d[$b] = !empty($d[$b]);
    return $d;
}

function wag_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_outcomes (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, code VARCHAR(12) NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0, at DATETIME NULL, KEY k_phone (phone, id), KEY k_emp (emp_id, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ctags (phone VARCHAR(12) NOT NULL, tag VARCHAR(40) NOT NULL, src VARCHAR(8) NOT NULL DEFAULT 'person', at DATETIME NULL, PRIMARY KEY (phone, tag), KEY k_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_notes (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, body VARCHAR(600) NOT NULL, mention INT NOT NULL DEFAULT 0, at DATETIME NULL, KEY k_phone (phone, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_msgset (id INT AUTO_INCREMENT PRIMARY KEY, purpose VARCHAR(24) NOT NULL, title VARCHAR(80) NOT NULL DEFAULT '', body TEXT NULL, use_words TEXT NULL, avoid_words TEXT NULL,
            category VARCHAR(10) NOT NULL DEFAULT 'UTILITY', tpl_id INT NOT NULL DEFAULT 0, status VARCHAR(8) NOT NULL DEFAULT 'draft', approved_by INT NOT NULL DEFAULT 0, approved_at DATETIME NULL, created_at DATETIME NULL, KEY k_p (purpose, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_exports (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL DEFAULT 0, kind VARCHAR(12) NOT NULL, filt VARCHAR(300) NOT NULL DEFAULT '', rows_n INT NOT NULL DEFAULT 0, at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_queries (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, opened_at DATETIME NOT NULL, last_in_at DATETIME NULL, src VARCHAR(10) NOT NULL DEFAULT '', again TINYINT NOT NULL DEFAULT 0,
            emp_id INT NOT NULL DEFAULT 0, first_emp INT NOT NULL DEFAULT 0, KEY k_phone (phone, id), KEY k_emp (emp_id, opened_at), KEY k_open (opened_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_query_moves (id INT AUTO_INCREMENT PRIMARY KEY, query_id INT NOT NULL, from_emp INT NOT NULL DEFAULT 0, to_emp INT NOT NULL DEFAULT 0, late TINYINT NOT NULL DEFAULT 0, at DATETIME NOT NULL, KEY k_at (at), KEY k_q (query_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['ad_id' => "VARCHAR(40) NOT NULL DEFAULT ''", 'ad_head' => "VARCHAR(200) NOT NULL DEFAULT ''", 'unans_at' => 'DATETIME NULL', 'ctag_at' => 'DATETIME NULL'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_contacts LIMIT 0"); } catch (PDOException $e) { $pdo->exec("ALTER TABLE wa_contacts ADD COLUMN $col $def"); }
        }
        try { $pdo->query("SELECT folder FROM wa_media_lib LIMIT 0"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_media_lib ADD COLUMN folder VARCHAR(20) NOT NULL DEFAULT ''"); } catch (PDOException $e2) { } }
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wag_log(PDO $pdo, string $kind, bool $ok, string $info): void { if (function_exists('wa_log')) wa_log($pdo, $kind, $ok, $info); }
function wag_mins(?string $at): int { $t = $at ? strtotime($at) : 0; return $t ? max(0, (int)floor((time() - $t) / 60)) : 0; }
function wag_nums(PDO $pdo): array
{
    static $m = null; if ($m !== null) return $m; $m = [];
    foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) $m[(int)$n['id']] = $n['label'] !== '' ? (string)$n['label'] : ($n['display'] !== '' ? (string)$n['display'] : 'Number ' . $n['id']);
    return $m;
}

// ---------------------------------------------------------------------------------------------------------------- results (outcomes)
function wag_outcomes(): array
{
    return ['order' => ['Order placed', '🛒', 'win'], 'later' => ['Will order later', '⏰', 'open'], 'price' => ['Price too high', '💸', 'lost'], 'no_interest' => ['Not interested', '🙅', 'lost'],
            'wrong' => ['Wrong number', '📞', 'junk'], 'complaint' => ['Complaint', '⚠️', 'issue'], 'info' => ['Only asked for info', 'ℹ️', 'open'], 'no_reply' => ['Stopped replying', '🔇', 'lost']];
}
function wag_outcome_map(PDO $pdo)   // lazy: $map[$phone] → ['code','at'] of the latest result
{
    static $m = null; if ($m !== null) return $m;
    return $m = new WaLazyMap(function (array $ks) use ($pdo) {
        $o = []; try { $st = $pdo->prepare("SELECT o.phone, o.code, o.at FROM wa_outcomes o JOIN (SELECT phone, MAX(id) mid FROM wa_outcomes WHERE phone IN (" . wa_in($ks) . ") GROUP BY phone) x ON x.mid = o.id"); $st->execute($ks);
            foreach ($st->fetchAll() as $r) $o[(string)$r['phone']] = ['code' => (string)$r['code'], 'at' => (string)$r['at']]; } catch (Throwable $e) { }
        return $o; });
}
/** wa.close for a salesperson needs a result recorded after the customer's last message. Returns an error text or ''. */
function wag_close_guard(PDO $pdo, array $auth, array $c): string
{
    if (wa_is_admin($pdo, $auth)) return '';
    if (empty($c['last_in_at'])) return '';
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_outcomes WHERE phone = ? AND at >= ?"); $q->execute([$c['phone'], $c['last_in_at']]);
        if ((int)$q->fetchColumn() > 0) return '';
    } catch (Throwable $e) { return ''; }
    return 'Choose how this chat ended first (order placed, will order later, price too high …).';
}
function wag_stats(PDO $pdo, int $emp, array $cfg): array
{
    $o = ['orders_today' => 0, 'revenue_today' => 0, 'target' => $cfg['target'], 'streak' => 0, 'rank' => 0, 'team' => 0, 'chats_today' => 0, 'week_orders' => 0];
    if ($emp <= 0) return $o;
    try {
        $d0 = date('Y-m-d 00:00:00');
        $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM wa_outcomes WHERE emp_id = ? AND code = 'order' AND at >= ?"); $q->execute([$emp, $d0]); $r = $q->fetch(); $o['orders_today'] = (int)$r['n']; $o['revenue_today'] = (int)round((float)$r['s']);
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_outcomes WHERE emp_id = ? AND at >= ?"); $q->execute([$emp, $d0]); $o['chats_today'] = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_outcomes WHERE emp_id = ? AND code = 'order' AND at >= ?"); $q->execute([$emp, date('Y-m-d 00:00:00', time() - 6 * 86400)]); $o['week_orders'] = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT DISTINCT DATE(at) d FROM wa_outcomes WHERE emp_id = ? AND code = 'order' AND at >= ? ORDER BY d DESC"); $q->execute([$emp, date('Y-m-d', time() - 60 * 86400)]); $days = array_column($q->fetchAll(), 'd');
        $want = date('Y-m-d'); if (!in_array($want, $days, true)) $want = date('Y-m-d', time() - 86400);   // today may not have an order yet: the streak is still alive until tomorrow
        $s = 0; while (in_array($want, $days, true)) { $s++; $want = date('Y-m-d', strtotime($want . ' -1 day')); } $o['streak'] = $s;
        $b = $pdo->prepare("SELECT emp_id, COUNT(*) n FROM wa_outcomes WHERE code = 'order' AND at >= ? GROUP BY emp_id ORDER BY n DESC"); $b->execute([$d0]); $rows = $b->fetchAll(); $o['team'] = count($rows);
        $rank = 0; foreach ($rows as $i => $r) if ((int)$r['emp_id'] === $emp) { $rank = $i + 1; break; } $o['rank'] = $rank;
    } catch (Throwable $e) { }
    return $o;
}
function wag_outcome_save(PDO $pdo, array $c, string $code, string $note, float $amount, int $emp, int $remindMins): array
{
    $defs = wag_outcomes(); if (!isset($defs[$code])) return [false, 'Choose one of the results.'];
    $key = (string)$c['phone']; $now = date('Y-m-d H:i:s'); $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $who = $emp > 0 ? ($names[$emp] ?? 'Someone') : 'Admin';
    // the same result saved twice (a retry after a network hiccup) is ONE result — never a second order on the target / leaderboard
    $dq = $pdo->prepare("SELECT COUNT(*) FROM wa_outcomes WHERE phone = ? AND emp_id = ? AND code = ? AND at >= ?"); $dq->execute([$key, $emp, $code, date('Y-m-d H:i:s', time() - 180)]); $dupe = (int)$dq->fetchColumn() > 0;
    if (!$dupe) $pdo->prepare("INSERT INTO wa_outcomes (phone, emp_id, code, note, amount, at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$key, $emp, $code, mb_substr($note, 0, 300), max(0, $amount), $now]);
    $pdo->prepare("UPDATE wa_contacts SET callnow = IF(callnow IN (1, 2), 9, callnow) WHERE phone = ?")->execute([$key]);
    wa_event($pdo, $key, $defs[$code][1] . ' ' . $defs[$code][0] . ' — ' . $who . ($note !== '' ? ': ' . mb_substr($note, 0, 120) : ''));
    if ($code === 'order') { if (function_exists('wa_hot_clear')) wa_hot_clear($pdo, $key); }
    // "Remind me" works for every result that offers it (later / price / info / no reply) — "No" means no reminder. The chat closes either way;
    // the reminder brings it back to the salesperson's list at that time (reminders are read without the open/closed filter).
    $pdo->prepare("UPDATE wa_contacts SET remind_at = ? WHERE phone = ?")->execute([$remindMins > 0 ? date('Y-m-d H:i:s', time() + max(30, min(60 * 24 * 30, $remindMins)) * 60) : null, $key]);
    $pdo->prepare("UPDATE wa_contacts SET status = 'closed', closed_at = ? WHERE phone = ?")->execute([$now, $key]);
    if ($code === 'complaint' && function_exists('ct_push')) { try { $al = wa_team_cfg($pdo)['alert_emps']; if ($al) ct_push($pdo, $al, 'WhatsApp: complaint', ($c['name'] !== '' ? $c['name'] : '+91 ' . $key) . ' — ' . ($note !== '' ? mb_substr($note, 0, 80) : 'see the chat'), '/whatsapp.html#chats', 'wa-cmp-' . $key); } catch (Throwable $e) { } }
    wag_flow_hook_tag($pdo, $key, $code === 'order' ? 'bought' : ($code === 'price' ? 'price_issue' : ($code === 'no_interest' ? 'not_interested' : '')));
    $cfg = wag_cfg($pdo); return [true, wag_stats($pdo, $emp, $cfg)];
}

// ---------------------------------------------------------------------------------------------------------------- customer: orders, badge, numbers
/** Last orders of one customer from the Shopify order cache. */
function wag_orders(PDO $pdo, string $key, int $n = 6): array
{
    try {
        $st = $pdo->prepare("SELECT order_name, created_at, total, financial_status, fulfillment_status, outstanding FROM shop_orders WHERE phone <> '' AND SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), -10) = ? ORDER BY created_at DESC LIMIT " . (int)$n);
        $st->execute([$key]);
        return array_map(fn($r) => ['name' => (string)$r['order_name'], 'at' => substr((string)$r['created_at'], 0, 10), 'total' => (int)round((float)$r['total']), 'pay' => (string)$r['financial_status'], 'ship' => (string)$r['fulfillment_status'], 'due' => $r['outstanding'] === null ? null : (int)round((float)$r['outstanding'])], $st->fetchAll());
    } catch (Throwable $e) { return []; }
}
function wag_tag_catalog(): array
{
    return ['bought' => 'Bought', 'repeat' => 'Repeat buyer', 'vip' => 'Very frequent buyer', 'not_ordered' => 'Not ordered yet', 'wants_buy' => 'Wants to buy', 'price_issue' => 'Price objection', 'not_interested' => 'Not interested at all',
            'never_replied' => 'Never replied', 'cold' => 'Gone quiet 30+ days', 'complaint' => 'Complaint', 'wrong_number' => 'Wrong number', 'from_ad' => 'From a Meta ad', 'cod_pending' => 'COD pending', 'came_back' => 'Replied to a campaign', 'campaign' => 'Campaign customer (chat closed 24h+ / imported)'];
}
function wag_ctag_map(PDO $pdo)   // lazy: $map[$phone] → [tag => src] (stored tags: people + Claude)
{
    static $m = null; if ($m !== null) return $m;
    return $m = new WaLazyMap(function (array $ks) use ($pdo) {
        $o = []; try { $st = $pdo->prepare("SELECT phone, tag, src FROM wa_ctags WHERE phone IN (" . wa_in($ks) . ")"); $st->execute($ks); foreach ($st->fetchAll() as $r) $o[(string)$r['phone']][(string)$r['tag']] = (string)$r['src']; } catch (Throwable $e) { }
        return $o; });
}
function wag_cod_map(PDO $pdo): array   // customers with an unpaid COD / pending order in the last 12 days
{
    static $m = null; if ($m !== null) return $m; $m = [];
    try {
        foreach ($pdo->query("SELECT SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), -10) k FROM shop_orders WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 12 * 86400) . "' AND financial_status IN ('pending', 'partially_paid') AND (outstanding IS NULL OR outstanding > 0) AND phone <> ''")->fetchAll() as $r) $m[(string)$r['k']] = true;
    } catch (Throwable $e) { }
    return $m;
}
/** Tags worked out live from the customer row used by Contacts / Campaigns (keys: phone, orders, days, ignored, last_in_at, source, tag, optout). Always fresh, nothing to sync. */
function wag_row_tags(array $r, array $cfg = []): array
{
    static $cf = null; if ($cf === null) $cf = $cfg ?: wag_defaults(); $cfg = $cf;
    $ph = (string)$r['phone']; $oc = wag_outcome_map($GLOBALS['pdo'])[$ph]['code'] ?? ''; $o = (int)($r['orders'] ?? 0); $days = $r['days'] ?? null; $t = [];
    if ($o >= 1) $t[] = 'bought'; if ($o >= 2) $t[] = 'repeat';
    if ($o >= (int)$cfg['vip_orders'] || (int)($r['spent'] ?? 0) >= (int)$cfg['vip_ltv']) $t[] = 'vip';
    $in = !empty($r['last_in_at']) ? strtotime((string)$r['last_in_at']) : 0;
    if ($o === 0 && $in > 0) $t[] = 'not_ordered';
    if (($r['tag'] ?? '') === 'buy' || $oc === 'later') $t[] = 'wants_buy';
    if ($oc === 'price' || ($r['tag'] ?? '') === 'price') $t[] = 'price_issue';
    if ($oc === 'no_interest') $t[] = 'not_interested';
    if ($oc === 'wrong') $t[] = 'wrong_number';
    if ($oc === 'complaint' || ($r['tag'] ?? '') === 'complaint') $t[] = 'complaint';
    if ($in === 0 && (int)($r['ignored'] ?? 0) >= 1) $t[] = 'never_replied';
    if ($in > 0 && $in < time() - 30 * 86400) $t[] = 'cold';
    if (($r['source'] ?? '') === 'query') $t[] = 'from_ad';
    if (($r['source'] ?? '') === 'campaign') $t[] = 'came_back';
    if (isset(wag_cod_map($GLOBALS['pdo'])[$ph])) $t[] = 'cod_pending';
    foreach (array_keys(wag_ctag_map($GLOBALS['pdo'])[$ph] ?? []) as $x) $t[] = $x;
    return array_values(array_unique($t));
}
/** The small badge next to a customer's name. */
function wag_badge(PDO $pdo, array $c, ?array $om, array $tags, array $cfg): array
{
    $o = (int)($om['n'] ?? 0); $ltv = (int)($om['total'] ?? 0);
    if (in_array('complaint', $tags, true)) return ['k' => 'bad', 't' => '⚠️ Complaint', 'why' => 'Had a complaint — be careful'];
    if (in_array('vip', $tags, true)) return ['k' => 'vip', 't' => '⭐ VIP · ' . $o . ' orders', 'why' => $o . ' orders, ₹' . number_format($ltv) . ' in total'];
    if ((int)($c['hot'] ?? 0) === 1) return ['k' => 'hot', 't' => '🔥 Ready to pay', 'why' => (string)($c['hot_why'] ?? '')];
    if ($o >= 2) return ['k' => 'rep', 't' => '🔁 Repeat · ' . $o . ' orders', 'why' => 'Ordered ' . $o . ' times before'];
    if ($o === 1) return ['k' => 'buy', 't' => '✔ Bought once', 'why' => 'One order of ₹' . number_format($ltv)];
    if (in_array('never_replied', $tags, true)) return ['k' => 'quiet', 't' => '🔇 Never replied', 'why' => 'Did not answer our messages'];
    if (($c['source'] ?? '') === 'query') return ['k' => 'new', 't' => '🆕 New · from ad', 'why' => 'First time — came from a Meta ad'];
    return ['k' => 'new', 't' => '🆕 New', 'why' => 'No order yet'];
}
/** Which number the customer used, which one we answer from, and which one a Utility message should use next. */
function wag_number_info(PDO $pdo, array $c): array
{
    $nums = wag_nums($pdo); $key = (string)$c['phone']; $earlier = [];
    try {
        $st = $pdo->prepare("SELECT number_id, MAX(ts) last_any, SUM(dir = 'in') n_in, SUM(dir = 'out') n_out, SUM(dir = 'out' AND mtype = 'template' AND ts > ?) tpl_after FROM wa_messages WHERE phone = ? AND number_id > 0 GROUP BY number_id");
        $st->execute([$c['last_in_at'] ?: '1970-01-01 00:00:00', $key]);
        foreach ($st->fetchAll() as $r) $earlier[] = ['id' => (int)$r['number_id'], 'label' => $nums[(int)$r['number_id']] ?? ('Number ' . $r['number_id']), 'last' => substr((string)$r['last_any'], 0, 10), 'in' => (int)$r['n_in'], 'out' => (int)$r['n_out'], 'ignored' => (int)$r['tpl_after']];
    } catch (Throwable $e) { }
    $last = (int)$c['last_number_id']; $from = $nums[$last] ?? '';
    $next = $from; $why = $last ? 'They wrote to ' . $from . ' last — answer from there.' : 'No chat yet.';
    foreach ($earlier as $e) if ($e['id'] === $last && $e['ignored'] >= 2) {
        foreach ($earlier as $o) if ($o['id'] !== $last && $o['ignored'] < 2) { $next = $o['label']; $why = $from . ' got ' . $e['ignored'] . ' messages with no answer — send the next Utility message from ' . $next . '.'; break; }
        if ($next === $from) { foreach ($nums as $id => $lb) if ($id !== $last) { $next = $lb; $why = $from . ' got ' . $e['ignored'] . ' messages with no answer — try another number next.'; break; } }
    }
    if ($last && $next === $from && $earlier) $why = 'They answer on ' . $from . ' — keep sending Utility messages from there.';
    return ['reply_from' => $from, 'earlier' => $earlier, 'next_utility' => $next, 'why' => $why, 'switch' => $next !== $from && $from !== ''];
}

// ---------------------------------------------------------------------------------------------------------------- the phone inbox
function wag_inbox(PDO $pdo, int $emp, bool $all, array $cfg, bool $tag = false, bool $wide = false): array
{
    $now = time(); $cut = $now - $cfg['unans_min'] * 60; $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
    // the last message is looked up ONCE per chat (it was three identical look-ups per chat, every 4 seconds per phone)
    // 🟢 "new for the agent": the chat was given to this person in the last 7 days and NO PERSON has written in it since (the bot's / automatic replies do not count)
    $fa = "(c.assigned_emp > 0 AND c.assigned_at IS NOT NULL AND c.assigned_at >= '" . date('Y-m-d H:i:s', $now - 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_messages hm WHERE hm.phone = c.phone AND hm.dir = 'out' AND hm.who IN ('emp','phone') AND hm.ts >= c.assigned_at))";
    $sql = "SELECT c.*, IF(" . $fa . ", 1, 0) AS fresh_agent, lm.body AS last_body, lm.mtype AS last_type, lm.dir AS last_dir,
            (SELECT COUNT(*) FROM wa_messages u WHERE u.phone = c.phone AND u.dir = 'in' AND u.id > c.seen_msg_id) AS unread
            FROM wa_contacts c LEFT JOIN wa_messages lm ON lm.id = (SELECT m.id FROM wa_messages m WHERE m.phone = c.phone AND m.dir != 'sys' ORDER BY m.id DESC LIMIT 1) WHERE " . ($tag ? "c.last_in_at IS NOT NULL AND c.last_in_at >= '" . date('Y-m-d H:i:s', $now - 30 * 86400) . "' AND (c.assigned_emp = " . (int)$emp . " OR c.phone IN (SELECT l.phone FROM wa_assign_log l WHERE l.to_emp = " . (int)$emp . "))"   // 🏷 my tag: every chat that was ever mine (30 days), wherever it is now
                : ($all ? "c.status = 'open' AND c.last_in_at IS NOT NULL AND c.last_in_at >= '" . date('Y-m-d H:i:s', $now - 86400) . "' AND c.assigned_emp > 0"
                    : "c.status = 'open' AND c.assigned_emp = " . (int)$emp . " AND ((c.last_in_at IS NOT NULL AND c.last_in_at >= '" . ($wide ? date('Y-m-d 00:00:00', strtotime('-1 day')) : date('Y-m-d H:i:s', $now - 86400)) . "') OR " . $fa . ")"))   // 📥 a chat given to me that no person has answered yet shows here even if the customer wrote long ago
            . " ORDER BY IF(" . $fa . ", 0, 1), GREATEST(COALESCE(c.last_in_at, '1970-01-01'), IF(" . $fa . ", c.assigned_at, '1970-01-01')) DESC LIMIT " . ($tag ? 400 : 250);   // 24 hours after the customer's last message a chat leaves the inbox and sits in History
    $rows = $pdo->query($sql)->fetchAll(); $om = wa_order_map($pdo); $oc = wag_outcome_map($pdo); $ct = wag_ctag_map($pdo); $ks = array_column($rows, 'phone'); $om->prefetch($ks); $oc->prefetch($ks); $ct->prefetch($ks); $nums = wag_nums($pdo); $out = []; $cnt = ['all' => 0, 'waiting' => 0, 'overdue' => 0, 'hot' => 0];
    $hf = function_exists('wa_heat_flags') ? wa_heat_flags($pdo, $ks) : [];
    $ot = function_exists('wa_owner_tags') ? wa_owner_tags($pdo, $ks) : [];
    $org = function_exists('wa_origin_chips') ? wa_origin_chips($pdo, $ks) : [];   // 📍 where the customer said they came from (Instagram / ad / friend)
    $hum = []; if ($ks) { try { $hq = $pdo->prepare("SELECT phone, MAX(ts) t FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND phone IN (" . implode(',', array_fill(0, count($ks), '?')) . ") GROUP BY phone"); $hq->execute($ks); foreach ($hq->fetchAll() as $h) $hum[(string)$h['phone']] = strtotime((string)$h['t']); } catch (Throwable $e) { } }   // the last answer a PERSON gave (bot / auto messages do not count)
    foreach ($rows as $r) {
        $in = strtotime((string)$r['last_in_at']); $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
        $waiting = $in > $o && (int)$r['optout'] === 0; $win = $in > $now - 86400; $overdue = $waiting && $win && $in <= $cut;
        $k = (string)$r['phone']; $x = $om[$k] ?? null; $tags = wag_row_tags(['phone' => $k, 'orders' => (int)($x['n'] ?? 0), 'spent' => (int)($x['total'] ?? 0), 'days' => null, 'ignored' => 0, 'last_in_at' => $r['last_in_at'], 'source' => $r['source'], 'tag' => $r['tag'], 'optout' => $r['optout']]);
        $prev = trim((string)$r['last_body']); if ($prev === '' && $r['last_type'] !== '') $prev = '[' . $r['last_type'] . ']'; $bdg = wag_badge($pdo, $r, $x, $tags, $cfg);
        $out[] = ['phone' => $k, 'name' => (string)$r['name'], 'preview' => mb_substr($prev, 0, 70), 'mine' => $r['last_dir'] === 'out', 'unread' => (int)$r['unread'], 'waiting' => $waiting, 'overdue' => $overdue, 'wait_min' => $waiting ? max(0, (int)floor(($now - $in) / 60)) : 0,
            'fresh' => (int)($r['fresh_agent'] ?? 0) === 1, 'wait_sec' => $waiting ? max(0, $now - $in) : 0, 'last_at' => (string)$r['last_in_at'], 'hot' => (int)$r['hot'] === 1, 'remind_at' => $r['remind_at'], 'window' => $win, 'src' => (string)$r['source'], 'ad' => (string)$r['ad_head'],
            'heat' => function_exists('wa_heat') ? wa_heat($r, ($hf[$k] ?? []) + (($bdg['k'] ?? '') === 'bad' ? ['cx' => 1] : []), (int)$cfg['unans_min']) : '', 'biz' => !empty($hf[$k]['biz']),
            'badge' => $bdg, 'origin' => (string)($org[$k] ?? ''), 'noresp' => ($hum[(string)$r['phone']] ?? 0) < $in, 'first_emp' => (int)($ot[$k]['first'] ?? 0), 'first_name' => $names[(int)($ot[$k]['first'] ?? 0)] ?? '', 'not_mine' => $tag && (int)$r['assigned_emp'] !== $emp, 'cx' => (int)($r['cx_id'] ?? 0) > 0 || (string)$r['tag'] === 'complaint', 'num' => $nums[(int)$r['last_number_id']] ?? '', 'emp' => (int)$r['assigned_emp'], 'emp_name' => $names[(int)$r['assigned_emp']] ?? '', 'result' => $oc[$k]['code'] ?? '', 'tags' => array_slice($tags, 0, 3)];
        if ($tag && (int)$r['assigned_emp'] !== $emp) continue;   // someone else's now: in the list, not in my counters
        $cnt['all']++; if ($waiting) $cnt['waiting']++; if ($overdue) $cnt['overdue']++; if ((int)$r['hot'] === 1) $cnt['hot']++;
    }
    usort($out, function ($a, $b) {
        $ba = $a['overdue'] ? 0 : ($a['waiting'] && $a['window'] ? 1 : 2); $bb = $b['overdue'] ? 0 : ($b['waiting'] && $b['window'] ? 1 : 2);
        if ($ba !== $bb) return $ba <=> $bb;
        return $ba === 0 ? strcmp($a['last_at'], $b['last_at']) : strcmp($b['last_at'], $a['last_at']);   // overdue: longest wait first · the others: newest customer message first
    });
    return [$out, $cnt];
}

// ---------------------------------------------------------------------------------------------------------------- the brain (suggestions + the 24/7 bot)
/** A reply for one chat. Uses the taught guidebook first; $guideOnly = never fall back to a generic AI answer (used by the bot). */
function wag_brain_reply(PDO $pdo, array $c, string $avoid = '', bool $guideOnly = false): array
{
    $key = (string)$c['phone'];
    if (!function_exists('wa_ord_bot_answer') && is_file(__DIR__ . '/wa_orders.php')) { try { require_once __DIR__ . '/wa_orders.php'; } catch (Throwable $e) { } }   // this file's router can run before wa_more.php has loaded wa_orders.php
    if (function_exists('wa_ord_bot_answer') && function_exists('wa_last_in')) {   // an order question is answered from the order data only (or handed to a person) — a pre-written reply never guesses an order status
        try { $li0 = wa_last_in($pdo, $key); $oa0 = wa_ord_bot_answer($pdo, $key, (string)($li0[1] ?? '')); }
        catch (Throwable $e) { $oa0 = null; }
        if ($oa0 !== null) return ['ok' => true, 'text' => (string)$oa0['text'], 'why' => 'Order question: answered from the order data', 'handoff' => !empty($oa0['handover']), 'source' => 'orders', 'ver' => 0];
    }
    if (function_exists('wa_learn_tables')) wa_learn_tables($pdo);
    if (function_exists('lib_guide_row') && function_exists('lib_bot_reply') && function_exists('ensure_lib_tables')) {
        try {
            if (ensure_lib_tables($pdo)) {
                $g = lib_guide_row($pdo, 'live');
                if (!$g) {
                    require_once __DIR__ . '/wa_hist.php'; $taught = false;
                    try { $taught = (function_exists('teach_facts_prompt') && teach_facts_prompt($pdo, [])[2]) || (function_exists('teach_limits_text') && teach_limits_text($pdo) !== '')
                        || (function_exists('ensure_teach_table') && ensure_teach_table($pdo) && (int)$pdo->query("SELECT COUNT(*) FROM lib_teach WHERE status = 'taught'")->fetchColumn() > 0); } catch (Throwable $e) { }
                    if ($taught && function_exists('lib_stub_guide')) $g = ['body' => json_encode(lib_stub_guide(), JSON_UNESCAPED_UNICODE), 'version' => 0];
                }
                if ($g) {
                    $body = json_decode((string)$g['body'], true); $msgs = wa_learn_msgs($pdo, $key);
                    if (is_array($body) && function_exists('wq_bot_context')) { $__qx = wq_bot_context($pdo, $c); if ($__qx !== '') $body['chat_context_do_not_quote'] = $__qx; }   // query type, ad headline, store info, owner decisions (max 2,000 characters)
                    if (is_array($body) && $body && $msgs) {
                        if ($avoid !== '') $msgs[] = ['who' => 'bot', 'text' => mb_substr($avoid, 0, 500)];
                        [$ok, $v] = lib_bot_reply($pdo, $body, $msgs);
                        if ($ok && is_array($v) && trim((string)($v['reply'] ?? '')) !== '') {
                            require_once __DIR__ . '/wa_hist.php'; $v = learn_enforce_v($pdo, $v);
                            if (!empty($v['handoff'])) { $lc = ''; foreach (array_reverse($msgs) as $m) if ($m['who'] === 'customer') { $lc = $m['text']; break; } if (function_exists('learn_gap_log')) learn_gap_log($pdo, 'unsure', $lc, (string)($v['handoff_reason'] ?? '')); }
                            return ['ok' => true, 'photo' => !empty($v['price_photo']), 'text' => mb_substr(trim((string)$v['reply']), 0, 1500), 'why' => mb_substr(trim((string)($v['why'] ?? '')), 0, 300), 'handoff' => !empty($v['handoff']), 'source' => 'guide', 'ver' => (int)$g['version']];
                        }
                    }
                }
            }
        } catch (Throwable $e) { wag_log($pdo, 'brain', false, 'guide: ' . $e->getMessage()); }
    }
    if ($guideOnly) return ['ok' => false, 'why' => 'The bot has not been taught yet (Learning & Improvement).'];
    if (!function_exists('convo_ai')) return ['ok' => false, 'why' => 'The AI is not available.'];
    $st = $pdo->prepare("SELECT dir, body FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT 25"); $st->execute([$key]); $txt = '';
    foreach (array_reverse($st->fetchAll()) as $r) $txt .= ($r['dir'] === 'in' ? 'CUSTOMER' : 'SHOP') . ': ' . mb_substr((string)$r['body'], 0, 400) . "\n";
    $o = wa_order_map($pdo)[$key] ?? null; $facts = 'Orders so far: ' . ($o ? $o['n'] . ' (last ' . substr($o['last'], 0, 10) . ', total Rs ' . $o['total'] . ')' : 'none') . '.';
    $qk = ''; foreach ($pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id LIMIT 20")->fetchAll() as $q) $qk .= '- ' . $q['title'] . ': ' . mb_substr(str_replace("\n", ' ', (string)$q['body']), 0, 300) . "\n";
    $sys = "You help a salesperson at a perfume shop (Surat, India) reply on WhatsApp. Write ONE short reply in the customer's own style (Hinglish by default). 1–3 short lines, at most one emoji, one clear next question. NEVER invent a price, discount, stock, date or link: use only the chat or the saved quick replies. why = one short sentence. call_now = true only when a phone call would clearly work better.";
    $sch = ['type' => 'OBJECT', 'properties' => ['suggestion' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'], 'call_now' => ['type' => 'BOOLEAN']], 'required' => ['suggestion', 'why', 'call_now']];
    try { [$ok, $v, $why] = convo_ai($sys, [['text' => "CUSTOMER FACTS: " . $facts . "\n\nQUICK REPLIES:\n" . $qk . "\nTHE CHAT:\n" . $txt . ($avoid !== '' ? "\nDO NOT repeat: " . $avoid : '')]], $sch, false, 1200, 'claude'); } catch (Throwable $e) { return ['ok' => false, 'why' => 'The AI could not read this chat.']; }
    if (!$ok || !is_array($v) || trim((string)($v['suggestion'] ?? '')) === '') return ['ok' => false, 'why' => (string)($why ?: 'The AI could not write a reply.')];
    return ['ok' => true, 'text' => mb_substr(trim((string)$v['suggestion']), 0, 1500), 'why' => mb_substr(trim((string)($v['why'] ?? '')), 0, 300), 'handoff' => !empty($v['call_now']), 'source' => 'basic', 'ver' => 0];
}
/** The stored suggestion for the customer's newest message, if one was already written (instant display). */
function wag_sugg_get(PDO $pdo, string $key): ?array
{
    try {
        if (function_exists('wa_learn_tables')) wa_learn_tables($pdo); [$inId] = wa_last_in($pdo, $key); if ($inId === 0) return null;
        $q = $pdo->prepare("SELECT body, source, ver FROM wa_sugg WHERE phone = ? AND in_id = ?"); $q->execute([$key, $inId]); $r = $q->fetch();
        return $r && trim((string)$r['body']) !== '' ? ['text' => (string)$r['body'], 'source' => (string)$r['source'], 'ver' => (int)$r['ver']] : null;
    } catch (Throwable $e) { return null; }
}
/** A message that is only a greeting ("hi", "hello", "good morning sir"): the answer is the saved greeting quick reply — instant, no AI. null when it is more than a greeting or no greeting quick reply exists. */
function wag_greet_quick(PDO $pdo, string $text, array $c): ?string
{
    $t = trim($text); if ($t === '' || mb_strlen($t) > 40) return null;
    if (!preg_match('/^(?:hi+|hello+|hey+|hlo|helo|namaste|namaskar|good\s*(?:morning|afternoon|evening|night)|gm|jsk|jai\s*shri\s*krishna|kem\s*cho|sir|mam|madam|bhai|team|there|all|everyone|[^\p{L}\p{N}]+)+$/iu', $t)) return null;
    $pick = null;
    if (function_exists('wa_greet_pick') && (string)setting_get($pdo, 'wa_greet', '') !== '') { $g = wa_greet_pick($pdo, $t, $c); if ($g !== null) $pick = ['body' => $g]; }   // the Greetings screen decides first
    try { $rows = $pick ? [] : $pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id")->fetchAll(); } catch (Throwable $e) { return null; }
    $sp = ['/morning|\bgm\b/i' => '/morning/i', '/afternoon/i' => '/afternoon/i', '/evening|night/i' => '/evening|night/i', '/namaste|namaskar|jsk|jai\s*shri/i' => '/namaste|namaskar/i'];
    if (!$pick) foreach ($sp as $m => $tt) if (preg_match($m, $t)) { foreach ($rows as $r) if (preg_match($tt, (string)$r['title'])) { $pick = $r; break 2; } break; }
    if (!$pick) foreach ($rows as $r) if (preg_match('/\bhi\b|hello|hey|greet|welcome/i', (string)$r['title']) && !preg_match('/morning|afternoon|evening|night|namaste/i', (string)$r['title'])) { $pick = $r; break; }
    if (!$pick) return null;
    $nm = trim((string)($c['name'] ?? '')); $fn = preg_split('/\s+/u', $nm)[0] ?? ''; if ($fn === '' || !preg_match('/^\p{L}[\p{L}\p{M}.\'-]*$/u', $fn)) $fn = '';
    $b = (string)$pick['body']; $b = $fn !== '' ? str_ireplace('{name}', $fn, $b) : preg_replace('/[ ]*\{name\}/i', '', $b);
    return trim((string)preg_replace('/[ ]+([!,.?])/', '$1', $b));
}
/** Writes the reply for ONE waiting customer in advance (text-type last message, window open, not opted out, no reply stored yet). Skips anything that needs a person. */
function wag_prebuild(PDO $pdo, string $key): bool
{
    try {
        $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $st->execute([$key]); $c = $st->fetch(); if (!$c) return false;
        if ((int)$c['optout'] === 1 || (string)$c['status'] !== 'open' || empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return false;
        if (!empty($c['cx_id'])) return false;   // the chat is with the Complaint team: nothing is pre-written or auto-sent for it
        if (!empty($c['last_out_at']) && strtotime((string)$c['last_out_at']) >= strtotime((string)$c['last_in_at'])) return false;   // already answered
        $li = $pdo->prepare("SELECT mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) return false;   // photos / voice notes / documents are for a person
        if (wag_sugg_get($pdo, $key)) return true;
        if (function_exists('wa_hot_intent') && wa_hot_intent((string)$m['body']) !== '') return false;   // wants to pay: a person replies
        $gq = wag_greet_quick($pdo, (string)$m['body'], $c); if ($gq !== null && $gq !== '') { wa_sugg_save($pdo, $key, $gq, 'quick', 0); return true; }   // a plain "hi": your own saved greeting, no AI
        $r = wag_brain_reply($pdo, $c); if (!$r['ok'] || !empty($r['handoff'])) return false;   // "call now" / "a person should handle this" is never pre-written (so it is never auto-sent)
        wa_sugg_save($pdo, $key, $r['text'], $r['source'], (int)$r['ver']); return true;
    } catch (Throwable $e) { return false; }
}
/** Writes suggestions in advance for chats a customer is waiting in (engine tick — catches any the webhook missed). */
function wag_presuggest(PDO $pdo, int $max = 6): int
{
    $n = 0;
    try {
        $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at DESC LIMIT 20");
        $st->execute([date('Y-m-d H:i:s', time() - 6 * 3600)]);
        foreach ($st->fetchAll() as $c) {
            if ($n >= $max) break; if (wag_sugg_get($pdo, (string)$c['phone'])) continue;
            if (wag_prebuild($pdo, (string)$c['phone'])) $n++;
        }
    } catch (Throwable $e) { wag_log($pdo, 'engine', false, 'suggestions: ' . $e->getMessage()); }
    return $n;
}
/** The 24/7 bot: answers a waiting customer by itself — only from the taught guidebook, only text chats inside the 24-hour window, never a HOT chat. */
function wag_bot_scan(PDO $pdo, int $max = 4): array
{
    $cfg = wag_cfg($pdo); $o = ['sent' => 0, 'handoff' => 0]; if ($cfg['bot'] !== 'on') return $o;
    if (defined('WA_IN_WEBHOOK')) return $o;   // never inside Meta's webhook call (an AI answer takes seconds)
    $now = time(); $unans = $cfg['unans_min'] * 60;
    $st = $pdo->prepare("SELECT c.* FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.hot = 0 AND c.last_in_at >= ? AND c.last_in_at <= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) ORDER BY c.last_in_at LIMIT 30");
    $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - $cfg['bot_after'])]);
    $people = null;
    foreach ($st->fetchAll() as $c) {
        if ($o['sent'] + $o['handoff'] >= $max) break; $key = (string)$c['phone']; $age = $now - strtotime((string)$c['last_in_at']);
        if (!empty($c['cx_id'])) continue;   // the chat is with the Complaint team: the bot never answers it
        if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $key)) continue;   // ✅ order completed: the bot stays quiet today and tomorrow (a person answers)
        if ((int)$c['assigned_emp'] > 0) {   // a present salesperson gets a fair chance first
            if ($people === null) { $people = []; foreach (wa_people($pdo) as $p) $people[(int)$p['emp_id']] = !empty($p['present']); }
            if (!empty($people[(int)$c['assigned_emp']]) && $age < $unans) continue;
        }
        $li = $pdo->prepare("SELECT id, mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) continue;   // photos / voice notes / documents are for a person
        if (function_exists('wa_hot_intent') && wa_hot_intent((string)$m['body']) !== '') continue;      // wants to pay: a person, not the bot (wa_hot_scan flags it)
        // a Flow is running for this customer (sending, waiting for a time or for this answer): the flow answers, never the bot as well — order answers included
        $f = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready')"); try { $f->execute([$key]); if ((int)$f->fetchColumn() > 0) continue; } catch (Throwable $e) { }
        // order questions (status / where is my order / kab aayega): the answer comes from the order data, never from the AI; a manual link or another number is never revealed
        if (!function_exists('wa_ord_bot_answer') && is_file(__DIR__ . '/wa_orders.php')) { try { require_once __DIR__ . '/wa_orders.php'; } catch (Throwable $e) { } }
        if (function_exists('wa_ord_bot_answer')) {
            $oa = wa_ord_bot_answer($pdo, $key, (string)$m['body']);
            if ($oa !== null) {
                if (function_exists('wq_guard') && !wq_guard($pdo, $key, (int)$m['id'], 'reply', 'bot')) continue;   // the holding message / browser countdown already answers this message
                [$ook, $ores] = wa_send_text($pdo, $c, $oa['text'], 'bot', 0);
                if (!$ook && function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply');
                if ($ook) { $o['sent']++; wa_event($pdo, $key, '🤖 The bot told the order status' . (!empty($oa['handover']) ? ' and asked a person to check' : '')); }
                else wag_log($pdo, 'bot', false, $key . ' · ' . $ores);
                if (!empty($oa['handover']) && function_exists('wa_ord_bot_handover')) { if (wa_ord_bot_handover($pdo, $key)) $o['handoff']++; }
                continue;
            }
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' AND ts >= ?"); $q->execute([$key, date('Y-m-d 00:00:00')]); if ((int)$q->fetchColumn() >= $cfg['bot_max_day']) continue;
        $__wqc = function_exists('wq_guard') ? wq_guard($pdo, $key, (int)$m['id'], 'reply', 'bot') : true; if (!$__wqc) continue;   // one answer per customer message: bot, holding message or countdown — whoever claims first
        $r = wag_brain_reply($pdo, $c, '', true); if (!$r['ok']) { if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); if (stripos((string)$r['why'], 'not been taught') !== false) { wag_log($pdo, 'bot', false, 'The bot has not been taught yet — nothing was sent.'); break; } continue; }
        if (!empty($r['handoff'])) { if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); if (function_exists('wa_hot_flag') && wa_hot_flag($pdo, $c, 'bot needs a person')) $o['handoff']++; continue; }
        [$ok, $res] = wa_send_text($pdo, $c, $r['text'], 'bot', 0);
        if ($ok && !empty($r['photo']) && function_exists('pc_photo_url') && function_exists('wa_send_image_link')) { $u = pc_photo_url($pdo); if ($u !== '') { [$pok, $pres] = wa_send_image_link($pdo, $c, $u, '', 'bot', 0); if (!$pok) wag_log($pdo, 'bot', false, $key . ' · price photo · ' . $pres); } }   // the Teach rules said: send the price photo
        if ($ok) { $o['sent']++; wa_event($pdo, $key, '🤖 The bot answered (' . $age . ' s after the customer)'); if (function_exists('wa_sugg_save')) wa_sugg_save($pdo, $key, $r['text'], $r['source'], (int)$r['ver']); if (function_exists('wa_prod_bot_after')) wa_prod_bot_after($pdo, $c); }   // product photos follow the bot's text (max 3, never repeated within 24 h)
        else { wag_log($pdo, 'bot', false, $key . ' · ' . $res); if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); }
    }
    return $o;
}
/** Chats a customer wrote to and nobody answered for N minutes: one note in the chat, one push to the person and to the admins. */
function wag_unans_scan(PDO $pdo): int
{
    $cfg = wag_cfg($pdo); $now = time(); $n = 0;
    $st = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at FROM wa_contacts WHERE assigned_emp > 0 AND status = 'open' AND optout = 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) AND (unans_at IS NULL OR unans_at < last_in_at) ORDER BY last_in_at LIMIT 30");
    $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - $cfg['unans_min'] * 60)]); $rows = $st->fetchAll(); if (!$rows) return 0;
    $names = employee_names_by_id($pdo); $lines = [];
    foreach ($rows as $r) {
        $pdo->prepare("UPDATE wa_contacts SET unans_at = ? WHERE phone = ?")->execute([$r['last_in_at'], $r['phone']]); $n++;
        $cu = $r['name'] !== '' ? $r['name'] : '+91 ' . $r['phone']; $lines[] = $cu . ' (' . ($names[(int)$r['assigned_emp']] ?? '?') . ')';
        wa_event($pdo, (string)$r['phone'], 'Not answered for ' . $cfg['unans_min'] . ' min');
        if ($cfg['unans_push'] && function_exists('ct_push')) { try { ct_push($pdo, [(int)$r['assigned_emp']], 'Customer is waiting', $cu . ' wrote ' . $cfg['unans_min'] . ' min ago. Reply now.', '/my_chats.html?phone=' . $r['phone'], 'wa-late-' . $r['phone']); } catch (Throwable $e) { } }
    }
    if ($cfg['unans_push'] && function_exists('ct_push')) { try { $al = wa_team_cfg($pdo)['alert_emps']; if ($al) ct_push($pdo, $al, 'WhatsApp: ' . $n . ' not answered in ' . $cfg['unans_min'] . ' min', implode(', ', array_slice($lines, 0, 3)) . ($n > 3 ? ' …' : ''), '/whatsapp.html#signals', 'wa-late-all'); } catch (Throwable $e) { } }
    return $n;
}
/** Claude gives chats a few short tags (e.g. "likes oud", "gift buyer"). Two chats per tick, only chats with something new. */
function wag_ai_tag_one(PDO $pdo, array $c): array
{
    if (!function_exists('convo_ai')) return [false, 'The AI is not available.'];
    $key = (string)$c['phone']; $st = $pdo->prepare("SELECT dir, body FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT 30"); $st->execute([$key]); $rows = array_reverse($st->fetchAll()); if (!$rows) return [false, 'No messages yet.'];
    $txt = ''; foreach ($rows as $r) $txt .= ($r['dir'] === 'in' ? 'CUSTOMER' : 'SHOP') . ': ' . mb_substr((string)$r['body'], 0, 300) . "\n";
    $o = wa_order_map($pdo)[$key] ?? null; $facts = 'Orders: ' . ($o ? $o['n'] . ', last ' . substr($o['last'], 0, 10) . ', total Rs ' . $o['total'] : 'none');
    $sys = "You label customers of a perfume shop (perfumes, attars, shower gel, wax) from their WhatsApp chat and order facts. Give 1 to 4 SHORT tags (2–3 words each, lower case, English) that help choose who to send which offer: for example 'likes oud', 'gift buyer', 'asks bulk price', 'price sensitive', 'wants sample', 'attar lover', 'office buyer', 'asked for COD', 'wants fresh scent'. Only what the chat or the facts clearly show. No names, no phone numbers.";
    $sch = ['type' => 'OBJECT', 'properties' => ['tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['tags']];
    try { [$ok, $v, $why] = convo_ai($sys, [['text' => $facts . "\n\nCHAT:\n" . $txt]], $sch, false, 800, 'claude'); } catch (Throwable $e) { return [false, 'The AI could not read this chat.']; }
    if (!$ok || !is_array($v)) return [false, (string)($why ?: 'The AI could not read this chat.')];
    $out = []; foreach ((array)($v['tags'] ?? []) as $t) { $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', (string)$t) ?? '')); if (mb_strlen($t) >= 3 && mb_strlen($t) <= 28) $out[] = $t; }
    $out = array_slice(array_values(array_unique($out)), 0, 4); $now = date('Y-m-d H:i:s');
    $pdo->prepare("DELETE FROM wa_ctags WHERE phone = ? AND src = 'claude'")->execute([$key]);
    foreach ($out as $t) $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'claude', ?)")->execute([$key, $t, $now]);
    $pdo->prepare("UPDATE wa_contacts SET ctag_at = ? WHERE phone = ?")->execute([$now, $key]);
    return [true, $out];
}
function wag_ai_tag_scan(PDO $pdo, int $max = 2): int
{
    $cfg = wag_cfg($pdo); if (!$cfg['ai_tags'] || defined('WA_IN_WEBHOOK')) return 0; $n = 0;
    $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE last_in_at >= ? AND (ctag_at IS NULL OR ctag_at < last_in_at) ORDER BY last_in_at DESC LIMIT " . (int)$max);
    $st->execute([date('Y-m-d H:i:s', time() - 45 * 86400)]);
    foreach ($st->fetchAll() as $c) { [$ok] = wag_ai_tag_one($pdo, $c); if (!$ok) $pdo->prepare("UPDATE wa_contacts SET ctag_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $c['phone']]); else $n++; }
    return $n;
}
function wag_tick(PDO $pdo): array
{
    $o = []; if (!wag_tables($pdo)) return $o;
    foreach (['unans' => fn() => wag_unans_scan($pdo), 'bot' => fn() => wag_bot_scan($pdo), 'sugg' => fn() => defined('WA_IN_WEBHOOK') ? 0 : wag_presuggest($pdo), 'flows' => fn() => function_exists('wag_flow_tick') ? wag_flow_tick($pdo) : 0, 'aitags' => fn() => wag_ai_tag_scan($pdo)] as $k => $fn) {
        try { $o[$k] = $fn(); } catch (Throwable $e) { wag_log($pdo, 'engine', false, $k . ': ' . $e->getMessage()); }
    }
    return $o;
}
/** An instant WhatsApp Business auto-reply ("Thank you for contacting…", "we will get back to you", "currently closed") to a campaign / follow-up we just sent.
 *  It is not a customer asking anything: the chat is closed quietly, tagged business_autoreply, and no query is opened or counted. A real message later re-opens it as usual. */
function wag_biz_autoreply(PDO $pdo, string $key, array $m): bool
{
    try {
        $body = function_exists('wa_parse_msg') ? (string)(wa_parse_msg($m)[1] ?? '') : (string)($m['text']['body'] ?? ''); $low = mb_strtolower(trim($body));
        if ($low === '' || mb_strlen($low) < 12) return false;
        $strong = (bool)preg_match('/this is an? (automated|auto[- ]?generated|automatic)|\bauto[- ]?reply\b|\bautomated (message|response|reply)|away message/u', $low);
        $soft = (bool)preg_match('/thank(s| you) for (contacting|reaching|your message|messaging|writing|getting in touch|connecting)|(we|i)( will|\'ll) (get back|respond|reply|revert|contact you)|get back to you (shortly|soon|as soon)|currently (unavailable|closed|away|not available)|(outside|out of) (our )?(business|working|office) hours|business hours|we are (closed|away)|our (team|executive|representative) will|welcome to .{2,40}(how (can|may) (we|i) help)|for (faster|quick) (support|response)/u', $low);
        if (!$strong && !$soft) return false;
        $q = $pdo->prepare("SELECT who, mtype, ts FROM wa_messages WHERE phone = ? AND dir = 'out' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $o = $q->fetch();
        $fromUs = $o && (in_array((string)$o['who'], ['campaign', 'followup', 'flow'], true) || (string)$o['mtype'] === 'template');
        $quick = $o && strtotime((string)$o['ts']) >= time() - 15 * 60;
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ?"); $q->execute([$key, date('Y-m-d H:i:s', time() - 30 * 86400)]); $ins = (int)$q->fetchColumn();
        if (!($fromUs && $ins <= 2 && ($strong || $quick))) return false;   // only right after OUR message, and never for someone already chatting with us
        $pdo->prepare("UPDATE wa_contacts SET status = 'closed', closed_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $key]);
        if (function_exists('wq_tag_put')) { try { wq_tag_put($pdo, $key, 'business_autoreply'); } catch (Throwable $e) { } }
        else { try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, 'business_autoreply', 'wq', ?)")->execute([$key, date('Y-m-d H:i:s')]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, 'business_autoreply')")->execute([$key]); } catch (Throwable $e) { } }
        if (function_exists('wa_event')) wa_event($pdo, $key, 'Auto-reply from another business’s WhatsApp — not counted as a query');
        return true;
    } catch (Throwable $e) { return false; }
}
/** Called by wa_store_in after every customer message: remembers the ad, starts / continues flows. Never throws. */
function wag_after_in(PDO $pdo, string $key, array $m, bool $isNew, array $ref): void
{
    try {
        wag_tables($pdo);
        if ($ref) { $ad = mb_substr((string)($ref['source_id'] ?? $ref['ctwa_clid'] ?? ''), 0, 40); $head = mb_substr(trim((string)($ref['headline'] ?? $ref['body'] ?? $ref['source_url'] ?? '')), 0, 200);
            $pdo->prepare("UPDATE wa_contacts SET ad_id = IF(ad_id = '', ?, ad_id), ad_head = IF(ad_head = '', ?, ad_head) WHERE phone = ?")->execute([$ad, $head, $key]); }
        if (!$ref && wag_biz_autoreply($pdo, $key, $m)) return;   // another business's WhatsApp auto-reply to our campaign: not a customer query
        wag_q_touch($pdo, $key, !empty($ref));
        if (function_exists('wkf_on_in') && wkf_on_in($pdo, $key, $m, $isNew, $ref)) return;   // 🔑 a key flow answered (button tap or start text): the old flows stay quiet
        if (function_exists('wag_flow_on_in')) wag_flow_on_in($pdo, $key, $m, $isNew, $ref);
    } catch (Throwable $e) { wag_log($pdo, 'engine', false, 'after-in: ' . $e->getMessage()); }
}
function wag_flow_hook_tag(PDO $pdo, string $key, string $tag): void { if ($tag !== '' && function_exists('wag_flow_on_tag')) { try { wag_flow_on_tag($pdo, $key, $tag); } catch (Throwable $e) { } } }
/** Extra info for the chat header (used by wa.chat). */
function wag_chat_info(PDO $pdo, array $c): array
{
    $cfg = wag_cfg($pdo); $key = (string)$c['phone']; $om = wa_order_map($pdo)[$key] ?? null;
    $tags = wag_row_tags(['phone' => $key, 'orders' => (int)($om['n'] ?? 0), 'spent' => (int)($om['total'] ?? 0), 'days' => null, 'ignored' => 0, 'last_in_at' => $c['last_in_at'], 'source' => $c['source'], 'tag' => $c['tag'], 'optout' => $c['optout']], $cfg);
    return ['badge' => wag_badge($pdo, $c, $om, $tags, $cfg), 'numbers' => wag_number_info($pdo, $c), 'orders_n' => (int)($om['n'] ?? 0), 'ltv' => (int)($om['total'] ?? 0)];
}

// ---------------------------------------------------------------------------------------------------------------- tags: counts for choosing an audience
/** Every tag with how many customers carry it in total and how many were active in the last 30 / 60 / 90 days (a message, a reply or an order). */
function wag_tag_counts(PDO $pdo): array
{
    wa_cust_ready($pdo); $at = (string)setting_get($pdo, 'wa_cust_at', '');
    try { $c = json_decode((string)setting_get($pdo, 'wa_tag_cache', ''), true); if (is_array($c) && ($c['as_of'] ?? '') === $at && $at !== '') return $c; } catch (Throwable $e) { }   // computed once per refresh
    $r = wag_tag_counts_calc($pdo); try { setting_set($pdo, 'wa_tag_cache', json_encode($r)); } catch (Throwable $e) { } return $r;
}
function wag_tag_counts_calc(PDO $pdo): array
{
    $cat = wag_tag_catalog(); $now = time(); $d = fn(int $n) => date('Y-m-d H:i:s', $now - $n * 86400);
    $st = $pdo->prepare("SELECT t.tag, COUNT(*) a, SUM(s.act_at >= ?) d30, SUM(s.act_at >= ?) d60, SUM(s.act_at >= ?) d90 FROM wa_tagidx t JOIN wa_contacts c ON c.phone = t.phone AND c.optout = 0 LEFT JOIN wa_cust s ON s.phone = t.phone GROUP BY t.tag"); $st->execute([$d(30), $d(60), $d(90)]);
    $cnt = []; foreach ($st->fetchAll() as $r) $cnt[(string)$r['tag']] = ['all' => (int)$r['a'], 'd30' => (int)$r['d30'], 'd60' => (int)$r['d60'], 'd90' => (int)$r['d90']];
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE optout = 0")->fetchColumn();
    $out = []; foreach ($cat as $k => $label) if (isset($cnt[$k])) $out[] = ['k' => $k, 't' => $label, 'kind' => 'auto'] + $cnt[$k];
    $cl = []; foreach ($cnt as $k => $v) if (!isset($cat[$k])) $cl[] = ['k' => $k, 't' => $k, 'kind' => 'claude'] + $v;
    usort($cl, fn($a, $b) => $b['all'] <=> $a['all']);
    if (function_exists('wq_tag_labels')) foreach ($cl as &$x0) { $x0['t'] = wq_tag_labels()[$x0['k']] ?? (strpos($x0['k'], 'import_') === 0 ? 'Imported ' . substr($x0['k'], 7) : $x0['k']); $x0['kind'] = preg_match('/_query$/', $x0['k']) ? 'query' : (strpos($x0['k'], 'import_') === 0 ? 'import' : $x0['kind']); } unset($x0);
    $sg = function_exists('wcs_seg_names') ? wcs_seg_names($pdo) : []; foreach ($cl as &$x1) if (isset($sg[$x1['k']])) { $x1['t'] = $sg[$x1['k']]; $x1['kind'] = 'segment'; } unset($x1);
    $segs = array_values(array_filter($cl, fn($x) => $x['kind'] === 'segment')); $cl = array_values(array_filter($cl, fn($x) => $x['kind'] !== 'segment'));
    return ['tags' => array_merge($out, $segs, array_slice($cl, 0, 120)), 'customers' => $tot, 'as_of' => (string)setting_get($pdo, 'wa_cust_at', '')];
}

// ---------------------------------------------------------------------------------------------------------------- fixed message sets (library)
function wag_purposes(): array
{
    return ['first_ad' => ['First reply — came from an ad', 'When a customer writes after clicking a Meta ad', 'UTILITY'], 'first_new' => ['First reply — new chat', 'When someone writes to us for the first time', 'UTILITY'],
            'order_shipped' => ['Order shipped', 'Parcel handed to the courier, with tracking', 'UTILITY'], 'order_delivered' => ['Order delivered', 'After delivery, ask if everything is fine', 'UTILITY'],
            'payment_pending' => ['Payment pending', 'The order is waiting for payment', 'UTILITY'], 'cod_confirm' => ['COD confirmation', 'Confirm a cash-on-delivery order (and offer prepaid)', 'UTILITY'],
            'no_reply' => ['Gentle reminder', 'The customer stopped replying', 'MARKETING'], 'refill' => ['Refill / reorder', 'A perfume must be about to finish', 'MARKETING'],
            'offer' => ['Offer', 'A discount or festival offer', 'MARKETING'], 'review_ask' => ['Ask for a review', 'After a good delivery', 'MARKETING']];
}
function wag_default_words(string $p): array
{
    $m = ['first_ad' => [['thanks for your message', 'your enquiry', 'which perfume', 'size'], ['offer', 'discount', '% off', 'limited time', 'buy now']],
          'first_new' => [['thanks for writing', 'how can we help', 'which perfume'], ['offer', 'discount', 'sale', 'buy now']],
          'order_shipped' => [['order', 'shipped', 'tracking', 'courier'], ['offer', 'discount', 'sale', 'buy more']],
          'order_delivered' => [['order', 'delivered', 'received'], ['offer', 'discount', 'sale']],
          'payment_pending' => [['order', 'payment', 'pending', 'link'], ['offer', 'discount', 'sale', 'last chance']],
          'cod_confirm' => [['order', 'confirm', 'cash on delivery', 'address'], ['offer', 'sale']],
          'no_reply' => [['your enquiry', 'still interested', 'reply'], ['hurry', 'last chance']],
          'refill' => [['your', 'refill', 'last order'], []], 'offer' => [['offer', 'valid till'], ['guaranteed', 'free money']], 'review_ask' => [['your order', 'review', 'feedback'], ['offer', 'discount']]];
    return $m[$p] ?? [[], []];
}
function wag_msg_check(PDO $pdo, string $purpose, string $text): array
{
    $q = $pdo->prepare("SELECT use_words, avoid_words FROM wa_msgset WHERE purpose = ? AND status = 'approved' ORDER BY id DESC LIMIT 1"); $q->execute([$purpose]); $r = $q->fetch();
    if (!$r) return ['approved' => false, 'missing' => [], 'bad' => []];
    $low = mb_strtolower($text); $miss = []; $bad = [];
    foreach ((array)json_decode((string)$r['use_words'], true) as $w) { $w = trim((string)$w); if ($w !== '' && mb_strpos($low, mb_strtolower($w)) === false) $miss[] = $w; }
    foreach ((array)json_decode((string)$r['avoid_words'], true) as $w) { $w = trim((string)$w); if ($w !== '' && mb_strpos($low, mb_strtolower($w)) !== false) $bad[] = $w; }
    return ['approved' => true, 'missing' => $miss, 'bad' => $bad];
}
function wag_lib_public(array $r): array
{
    return ['id' => (int)$r['id'], 'purpose' => (string)$r['purpose'], 'title' => (string)$r['title'], 'body' => (string)$r['body'], 'use' => (array)json_decode((string)$r['use_words'], true), 'avoid' => (array)json_decode((string)$r['avoid_words'], true),
            'category' => (string)$r['category'], 'tpl_id' => (int)$r['tpl_id'], 'status' => (string)$r['status'], 'approved_at' => $r['approved_at']];
}
function wag_clean_words($a): array
{
    $o = []; foreach ((array)$a as $w) { $w = trim(mb_substr((string)$w, 0, 40)); if ($w !== '') $o[] = $w; } return array_slice(array_values(array_unique($o)), 0, 12);
}

// ---------------------------------------------------------------------------------------------------------------- Claude growth engine
/** What the HRMS can and cannot do today. A suggestion can list "needs" — the screen then shows which system is not built yet. */
function wag_systems(PDO $pdo): array
{
    $gem = function_exists('convo_gemini_key') ? convo_gemini_key() !== '' : false;
    return ['bot' => ['label' => '24/7 bot', 'status' => 'built', 'note' => 'Switch in Signals. Answers only from what you taught it.'],
            'push' => ['label' => 'Phone alerts', 'status' => 'built', 'note' => 'Each person taps Allow once (iPhone: Add to Home Screen first).'],
            'flows' => ['label' => 'Flows', 'status' => 'built', 'note' => 'Triggers, steps, waits and branches.'],
            'tags' => ['label' => 'Auto tags', 'status' => 'built', 'note' => 'From chats, results and orders.'],
            'numbers' => ['label' => 'Number choice', 'status' => 'built', 'note' => 'Same number while they answer, another one when they do not.'],
            'voice_text' => ['label' => 'Voice note → text', 'status' => $gem ? 'built' : 'partial', 'note' => $gem ? 'Tap a voice note in a chat to read it.' : 'Needs GEMINI_API_KEY in config.php.'],
            'cod_nudge' => ['label' => 'COD → prepaid nudge', 'status' => 'partial', 'note' => 'The audience tag “COD pending” works. Sending is a Campaign with a Utility message; no automatic send yet.'],
            'one_tap_order' => ['label' => 'One-tap order + payment link', 'status' => 'missing', 'note' => 'Waits for your own PHP website.'],
            'meta_purchase' => ['label' => 'Send “Purchase” back to Meta ads', 'status' => 'missing', 'note' => 'Not built. Needs the order system of the new website.'],
            'last_perfume' => ['label' => '“Last perfume” in messages', 'status' => 'missing', 'note' => 'Order line items are not stored yet, only order totals.'],
            'rewards' => ['label' => 'Reward points in chat', 'status' => 'missing', 'note' => 'The reward site is not connected to WhatsApp yet.']];
}
function wag_claude_items(PDO $pdo): array
{
    $cfg = wag_cfg($pdo); $it = []; $add = function (string $lvl, string $text, string $go = '', array $needs = [], array $on = ['home']) use (&$it) { $it[] = ['level' => $lvl, 'text' => $text, 'go' => $go, 'needs' => $needs, 'on' => $on]; };
    $cronAt = (string)setting_get($pdo, 'wa_cron_at', ''); $tickAt = (string)setting_get($pdo, 'wa_tick_at', '');
    if ($cronAt === '' || strtotime($cronAt) < time() - 600) $add('bad', 'The scheduled engine (cron) is not running' . ($cronAt !== '' ? ' — last run ' . wag_mins($cronAt) . ' min ago' : ' yet') . '. Follow-ups, flows with waits and the bot only work while someone has the page open. Set it up in Team & routing.', 'team', [], ['home', 'flows', 'signals']);
    $cut = date('Y-m-d H:i:s', time() - $cfg['unans_min'] * 60);
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at)"); $q->execute([date('Y-m-d H:i:s', time() - 86400), $cut]); $late = (int)$q->fetchColumn();
    if ($late > 0) $add('bad', $late . ' customer' . ($late === 1 ? ' is' : 's are') . ' waiting more than ' . $cfg['unans_min'] . ' minutes. Give them to someone free.', 'signals', [], ['home', 'chats', 'signals']);
    try { $lost = wag_lost_count($pdo); if ($lost > 0) $add('bad', $lost . ' customer' . ($lost === 1 ? '' : 's') . ' wrote in the last 7 days and never got a reply — they are only in History now. Send each a Utility follow-up or a gentle reminder.', 'history', [], ['home', 'chats', 'signals', 'history']); } catch (Throwable $e) { }
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts c WHERE c.status = 'closed' AND c.last_in_at >= ? AND NOT EXISTS (SELECT 1 FROM wa_outcomes o WHERE o.phone = c.phone AND o.at >= c.last_in_at)"); $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]); $nr = (int)$q->fetchColumn();
        if ($nr > 0) $add('warn', $nr . ' chats were closed in the last 7 days with no result noted. Without it, tags and reports are blind for them.', 'chats', [], ['home', 'chats']);
    } catch (Throwable $e) { }
    foreach (['first_ad', 'first_new'] as $p) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_msgset WHERE purpose = ? AND status = 'approved'"); $q->execute([$p]); if ((int)$q->fetchColumn() === 0) $add('warn', 'No approved “' . wag_purposes()[$p][0] . '” message yet. Let Claude suggest the words and approve them.', 'library', [], ['home', 'library']); }
    if ($cfg['bot'] !== 'on') $add('tip', 'The 24/7 bot is off. Customers who write at night or while everyone is busy wait for a person.', 'signals', ['bot'], ['home', 'signals']);
    else { try { $taught = function_exists('lib_guide_row') && function_exists('ensure_lib_tables') && ensure_lib_tables($pdo) && lib_guide_row($pdo, 'live'); } catch (Throwable $e) { $taught = false; } if (!$taught) $add('warn', 'The bot is ON but has no approved guidebook; it only answers from what you taught it, so it may stay silent. Teach it in Learning & Improvement.', 'learn', [], ['home', 'signals']); }
    try {
        $q = $pdo->query("SELECT SUM(status = 'REJECTED') rej, SUM(status = 'PENDING') pen FROM wa_tpl_waba"); $r = $q->fetch();
        if ((int)($r['rej'] ?? 0) > 0) $add('warn', (int)$r['rej'] . ' pre-made message(s) were rejected by Meta. Open them to see the reason.', 'premade', [], ['home', 'library', 'builder']);
        if ((int)($r['pen'] ?? 0) > 0) $add('tip', (int)$r['pen'] . ' pre-made message(s) are waiting for Meta.', 'premade', [], ['library', 'builder']);
    } catch (Throwable $e) { }
    try {
        $nf = (int)$pdo->query("SELECT COUNT(*) FROM wa_flows WHERE active = 1")->fetchColumn(); $nn = (int)$pdo->query("SELECT COUNT(*) FROM wa_flows WHERE active = 1 AND trig = 'no_reply'")->fetchColumn();
        if ($nf === 0) $add('tip', 'No flow is on. A first flow (new chat → greeting → ask what they want) saves your people the same typing.', 'flows', ['flows'], ['home', 'flows']);
        elseif ($nn === 0) $add('tip', 'No flow handles “customer did not reply”. Most lost orders are silence, not “no”.', 'flows', [], ['home', 'flows']);
    } catch (Throwable $e) { }
    try {
        $t = wag_tag_counts($pdo); $by = []; foreach ($t['tags'] as $x) $by[$x['k']] = $x;
        if (!empty($by['wants_buy']) && $by['wants_buy']['d30'] > 0) $add('idea', $by['wants_buy']['d30'] . ' customers said they want to buy in the last 30 days and have not ordered. Follow up with the “Reminder” message.', 'campaigns', [], ['home', 'campaign', 'tags']);
        if (!empty($by['never_replied']) && $by['never_replied']['all'] > 20) $add('idea', $by['never_replied']['all'] . ' customers never replied to any message. Send the next Utility message from the other number.', 'campaigns', [], ['home', 'campaign', 'tags']);
        if (!empty($by['cod_pending'])) $add('idea', $by['cod_pending']['all'] . ' COD orders are still unpaid. A short “confirm / pay now and skip the COD charge” Utility message turns many into prepaid.', 'campaigns', ['cod_nudge'], ['home', 'campaign', 'tags']);
        if (!empty($by['vip']) && $by['vip']['all'] > 0) $add('idea', $by['vip']['all'] . ' very frequent buyers. Give them a small thank-you before any offer; they cost nothing to reach in the 24-hour window.', 'campaigns', ['rewards'], ['campaign', 'tags']);
        if (!empty($by['bought']) && $by['bought']['d90'] > 50) $add('idea', 'Customers who bought 60–90 days ago are due for a refill message.', 'campaigns', ['last_perfume'], ['campaign', 'tags']);
    } catch (Throwable $e) { }
    $add('tip', 'Replying inside 24 hours of the customer\'s last message is free. A campaign should go to people who wrote recently first; the rest cost per message.', 'campaigns', [], ['campaign', 'builder']);
    $add('tip', 'Customers who click a Meta ad get 72 free hours. Answer ad chats fast and send the price and photos inside that window.', 'chats', [], ['chats', 'home']);
    $add('tip', 'Order-related messages (shipped, payment pending, COD confirm) can be Utility and cost a fraction of Marketing. Offer messages are Marketing. Check Meta\'s current rates in Team & routing.', 'builder', [], ['builder', 'library', 'campaign']);
    if (function_exists('wag_flow_items')) foreach (wag_flow_items($pdo) as $x) $it[] = $x;
    return $it;
}

// ---------------------------------------------------------------------------------------------------------------- gallery
function wag_use_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_media_use (id INT AUTO_INCREMENT PRIMARY KEY, lib_id INT NOT NULL, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, at DATETIME NULL, KEY k_lib (lib_id, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__); } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wag_folders(): array
{
    return ['packaging' => 'Packaging', 'bottles' => 'Bottles', 'sizes' => 'Sizes', 'attars' => 'Attars', 'gel' => 'Gel', 'offers' => 'Offers', 'proof' => 'Proof', 'reviews' => 'Reviews', 'videos' => 'Videos', 'prices' => 'Price list', 'gifts' => 'Gifts', 'store' => 'Store', 'other' => 'Other'];
}
function wag_folder_of(array $r): string
{
    $f = trim((string)($r['folder'] ?? '')); if ($f !== '' && isset(wag_folders()[$f])) return $f;
    $map = ['perfume' => 'bottles', 'sizes' => 'sizes', 'pricelist' => 'prices', 'packaging' => 'packaging', 'gel' => 'gel', 'candle' => 'other', 'gift' => 'gifts', 'offer' => 'offers', 'store' => 'store', 'registration' => 'proof', 'delivery' => 'proof', 'quality' => 'proof', 'reviews' => 'reviews', 'bulk' => 'other'];
    $s = (string)($r['section'] ?? ''); if ($s !== '' && isset($map[$s])) return $map[$s];
    return (string)$r['kind'] === 'video' ? 'videos' : 'other';
}
function wag_gallery(PDO $pdo): array
{
    wag_use_tables($pdo); $use = []; $last = [];
    try { foreach ($pdo->query("SELECT lib_id, COUNT(*) n, MAX(at) la FROM wa_media_use GROUP BY lib_id")->fetchAll() as $r) { $use[(int)$r['lib_id']] = (int)$r['n']; $last[(int)$r['lib_id']] = (string)$r['la']; } } catch (Throwable $e) { }
    try { $rows = $pdo->query("SELECT l.*, p.section FROM wa_media_lib l LEFT JOIN ph_photos p ON p.lib_id = l.id ORDER BY l.id DESC")->fetchAll(); }
    catch (Throwable $e) { $rows = $pdo->query("SELECT l.*, '' AS section FROM wa_media_lib l ORDER BY l.id DESC")->fetchAll(); }
    $items = []; $seen = []; $folders = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; if (isset($seen[$id])) continue; $seen[$id] = 1; $f = wag_folder_of($r); $folders[$f] = ($folders[$f] ?? 0) + 1;
        $items[] = ['id' => $id, 'title' => (string)$r['title'], 'kind' => (string)$r['kind'], 'size' => (int)$r['size'], 'folder' => $f, 'section' => (string)($r['section'] ?? ''), 'filename' => (string)$r['filename'], 'sent' => $use[$id] ?? 0, 'last_sent' => $last[$id] ?? '', 'created' => (string)$r['created_at']];
    }
    $fl = []; foreach (wag_folders() as $k => $t) if (!empty($folders[$k])) $fl[] = ['k' => $k, 't' => $t, 'n' => $folders[$k]];
    return ['folders' => $fl, 'items' => $items];
}
/** Claude's clean-up list: photos nobody uses, old offers, duplicates. Only suggestions — the admin removes them. */
/** Of several copies of one file, the one to keep: the one sitting in a proper folder, else the oldest. */
function wag_dup_keeper(array $g): int { usort($g, fn($a, $b) => [$a['folder'] === 'other' ? 1 : 0, $a['id']] <=> [$b['folder'] === 'other' ? 1 : 0, $b['id']]); return (int)$g[0]['id']; }
function wag_gallery_clean(PDO $pdo): array
{
    $g = wag_gallery($pdo); $out = []; $now = time(); $dup = [];
    foreach ($g['items'] as $it) { $k = mb_strtolower($it['filename'] . '|' . $it['size']); $dup[$k][] = $it; }
    foreach ($g['items'] as $it) {
        $age = $it['created'] ? (int)floor(($now - strtotime($it['created'])) / 86400) : 0; $why = '';
        if ($it['folder'] === 'proof' && $it['section'] === 'registration') continue;   // never touched: sent by a person only
        $k = mb_strtolower($it['filename'] . '|' . $it['size']);
        if (count($dup[$k] ?? []) > 1 && $it['id'] !== wag_dup_keeper($dup[$k])) $why = 'Same file is saved twice (keep the one in a folder)';
        elseif ($it['folder'] === 'offers' && $age > 45) $why = 'An offer poster older than 45 days — the offer has probably ended';
        elseif ($it['sent'] === 0 && $age > 30) $why = 'Saved ' . $age . ' days ago and never sent to a customer';
        elseif ($it['sent'] > 0 && $it['last_sent'] && $now - strtotime($it['last_sent']) > 120 * 86400 && $age > 120) $why = 'Not sent for more than 4 months';
        if ($why !== '') $out[] = ['id' => $it['id'], 'title' => $it['title'], 'folder' => $it['folder'], 'kind' => $it['kind'], 'why' => $why, 'sent' => $it['sent'], 'age' => $age];
    }
    return ['suggest' => $out, 'total' => count($g['items'])];
}
function wag_thumb(PDO $pdo, int $id): ?string
{
    $r = function_exists('wa_lib_row') ? wa_lib_row($pdo, $id) : null; if (!$r || (string)$r['kind'] !== 'image') return null;
    $src = wa_lib_dir() . '/' . basename((string)$r['path']); if (!is_file($src)) return null;
    $dst = wa_lib_dir() . '/th_' . $id . '.jpg'; if (is_file($dst) && filemtime($dst) >= filemtime($src)) return $dst;
    if (!function_exists('imagecreatefromstring')) return $src;
    $im = @imagecreatefromstring((string)file_get_contents($src)); if (!$im) return $src;
    $w = imagesx($im); $h = imagesy($im); $s = 220 / max(1, max($w, $h)); if ($s >= 1) { imagedestroy($im); return $src; }
    $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s)); $t = imagecreatetruecolor($nw, $nh); imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($t, $dst, 72); imagedestroy($t); imagedestroy($im); return $dst;
}
function wag_csv_out(string $name, array $head, iterable $rows): int
{
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '"'); header('Cache-Control: no-store');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head); $n = 0; foreach ($rows as $r) { fputcsv($o, $r); $n++; } fclose($o); return $n;
}

// ---------------------------------------------------------------------------------------------------------------- History (window closed: 24 h after the customer's last message, or the chat was closed)
function wag_history(PDO $pdo, int $emp, bool $adm, array $f, array $cfg): array
{
    $now = time(); $lim = 30; $pg = max(0, (int)($f['page'] ?? 0)); $cut = date('Y-m-d H:i:s', $now - 86400);
    $w = ['c.last_in_at IS NOT NULL', "(c.status = 'closed' OR c.last_in_at < ?)"]; $p = [$cut];
    if (!$adm) { $w[] = 'c.assigned_emp = ?'; $p[] = $emp > 0 ? $emp : -1; }
    elseif (isset($f['emp']) && (int)$f['emp'] !== 0) { $w[] = 'c.assigned_emp = ?'; $p[] = (int)$f['emp'] < 0 ? 0 : (int)$f['emp']; }
    if (!empty($f['number'])) { $w[] = 'c.last_number_id = ?'; $p[] = (int)$f['number']; }
    $q = trim((string)($f['q'] ?? '')); if ($q !== '') { $w[] = '(c.phone LIKE ? OR c.name LIKE ?)'; $l = '%' . addcslashes($q, '%_\\') . '%'; $p[] = function_exists('wa_q_like') ? wa_q_like($q) : $l; $p[] = $l; }
    if (in_array($f['src'] ?? '', ['query', 'community', 'campaign'], true)) { $w[] = 'c.source = ?'; $p[] = $f['src']; }
    if (!empty($f['days'])) { $w[] = 'c.last_in_at >= ?'; $p[] = date('Y-m-d H:i:s', $now - max(1, min(365, (int)$f['days'])) * 86400); }
    if (!empty($f['noreply'])) $w[] = '(c.last_out_at IS NULL OR c.last_out_at < c.last_in_at)';
    if (!empty($f['result'])) { $w[] = '(SELECT o.code FROM wa_outcomes o WHERE o.phone = c.phone ORDER BY o.id DESC LIMIT 1) = ?'; $p[] = (string)$f['result']; }
    if (!empty($f['noresult'])) $w[] = 'NOT EXISTS (SELECT 1 FROM wa_outcomes o WHERE o.phone = c.phone)';
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts c WHERE $where"); $st->execute($p); $total = (int)$st->fetchColumn();
    $sql = "SELECT c.*, (SELECT body FROM wa_messages m WHERE m.phone = c.phone AND m.dir != 'sys' ORDER BY m.id DESC LIMIT 1) AS last_body,
            (SELECT mtype FROM wa_messages m WHERE m.phone = c.phone AND m.dir != 'sys' ORDER BY m.id DESC LIMIT 1) AS last_type FROM wa_contacts c WHERE $where ORDER BY c.last_in_at DESC LIMIT $lim OFFSET " . ($pg * $lim);
    $st = $pdo->prepare($sql); $st->execute($p); $rows = $st->fetchAll();
    $om = wa_order_map($pdo); $oc = wag_outcome_map($pdo); $ks = array_column($rows, 'phone'); $om->prefetch($ks); $oc->prefetch($ks); wag_ctag_map($pdo)->prefetch($ks); $nums = wag_nums($pdo); $names = employee_names_by_id($pdo); $defs = wag_outcomes(); $out = [];
    foreach ($rows as $r) {
        $k = (string)$r['phone']; $x = $om[$k] ?? null; $in = strtotime((string)$r['last_in_at']); $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
        $prev = trim((string)$r['last_body']); if ($prev === '' && $r['last_type'] !== '') $prev = '[' . $r['last_type'] . ']';
        $tags = wag_row_tags(['phone' => $k, 'orders' => (int)($x['n'] ?? 0), 'spent' => (int)($x['total'] ?? 0), 'days' => null, 'ignored' => 0, 'last_in_at' => $r['last_in_at'], 'source' => $r['source'], 'tag' => $r['tag'], 'optout' => $r['optout']], $cfg);
        $res = $oc[$k]['code'] ?? '';
        $out[] = ['phone' => $k, 'name' => (string)$r['name'], 'preview' => mb_substr($prev, 0, 70), 'last_at' => (string)$r['last_in_at'], 'ago_days' => (int)floor(($now - $in) / 86400), 'closed' => $r['status'] === 'closed', 'unanswered' => $in > $o,
            'result' => $res, 'result_t' => $res !== '' ? ($defs[$res][0] ?? $res) : '', 'result_i' => $res !== '' ? ($defs[$res][1] ?? '') : '', 'tags' => array_slice($tags, 0, 3), 'num' => $nums[(int)$r['last_number_id']] ?? '', 'src' => (string)$r['source'],
            'emp' => (int)$r['assigned_emp'], 'emp_name' => $names[(int)$r['assigned_emp']] ?? '', 'orders_n' => (int)($x['n'] ?? 0), 'remind_at' => $r['remind_at']];
    }
    return ['chats' => $out, 'total' => $total, 'page' => $pg, 'more' => ($pg + 1) * $lim < $total];
}
/** THE QUERY LEDGER — one row per query ("episode"):
 *  • A query opens when a customer writes and has no query that was active in the last 24 hours (the same 24 h that moves a chat to History). Writing again inside 24 h is the SAME query.
 *  • Its type is fixed at that moment: Meta ad > campaign reply > community > other. A customer who was here before (an earlier query, or an order) is marked "back again" — still counted in his type, shown separately.
 *  • The person who holds the chat owns the query. Moving it takes it off A and gives it to B; it always counts in the period it OPENED in.
 *  • A move made after the customer waited longer than the alert limit is written down as a "missed" for the person it was taken from. */
function wag_q_touch(PDO $pdo, string $key, bool $adRef): void
{
    wag_q_backfill($pdo); $c = wa_contact($pdo, $key); if (!$c) return; $now = date('Y-m-d H:i:s');
    $q = $pdo->prepare("SELECT id, last_in_at FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $r = $q->fetch();
    if ($r && $r['last_in_at'] && strtotime((string)$r['last_in_at']) >= time() - 86400) { $pdo->prepare("UPDATE wa_queries SET last_in_at = ? WHERE id = ?")->execute([$now, $r['id']]); return; }
    $src = $adRef || $c['source'] === 'query' ? 'query' : (in_array($c['source'], ['campaign', 'community'], true) ? $c['source'] : '');
    $again = $r ? 1 : 0; if (!$again) { try { $om = wa_order_map($pdo)[$key] ?? null; if ($om && (int)$om['n'] > 0) $again = 1; } catch (Throwable $e) { } }
    $pdo->prepare("INSERT INTO wa_queries (phone, opened_at, last_in_at, src, again, emp_id, first_emp) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$key, $now, $now, $src, $again, (int)$c['assigned_emp'], (int)$c['assigned_emp']]);
}
/** Called from wa_assign_to: the newest query follows the chat to its new holder. */
function wag_q_moved(PDO $pdo, string $key, int $from, int $to): void
{
    try {
        wag_tables($pdo); $q = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $id = (int)$q->fetchColumn(); if ($id <= 0) return;
        $pdo->prepare("UPDATE wa_queries SET emp_id = ? WHERE id = ?")->execute([$to, $id]);
        if ($from > 0 && $to !== $from) {
            $c = wa_contact($pdo, $key); $cfg = wag_cfg($pdo); $late = 0;
            if ($c && $c['last_in_at'] && (!$c['last_out_at'] || $c['last_out_at'] < $c['last_in_at']) && strtotime((string)$c['last_in_at']) <= time() - $cfg['unans_min'] * 60 && strtotime((string)$c['last_in_at']) >= time() - 86400) $late = 1;
            $pdo->prepare("INSERT INTO wa_query_moves (query_id, from_emp, to_emp, late, at) VALUES (?, ?, ?, ?, ?)")->execute([$id, $from, $to, $late, date('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) { }
}
/** Once: chats that already exist become queries, so the counts do not start from zero. */
function wag_q_backfill(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    if ((string)setting_get($pdo, 'wa_q_backfill', '') !== '') return;
    try {
        $pdo->exec("INSERT INTO wa_queries (phone, opened_at, last_in_at, src, again, emp_id, first_emp)
            SELECT c.phone, COALESCE(c.assigned_at, c.first_seen), c.last_in_at, IF(c.source IN ('query','campaign','community'), c.source, ''), 0, c.assigned_emp, c.assigned_emp
            FROM wa_contacts c WHERE c.last_in_at IS NOT NULL AND COALESCE(c.assigned_at, c.first_seen) >= '" . date('Y-m-d H:i:s', time() - 95 * 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_queries q WHERE q.phone = c.phone)");
        setting_set($pdo, 'wa_q_backfill', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { }
}
function wag_qload(PDO $pdo, string $since): array
{
    wag_q_backfill($pdo); $names = employee_names_by_id($pdo); $rows = []; $tot = ['query' => 0, 'community' => 0, 'campaign' => 0, 'other' => 0, 'total' => 0, 'again' => 0, 'orders' => 0, 'missed' => 0];
    $blank = fn(int $e) => ['emp_id' => $e, 'name' => $names[$e] ?? ('#' . $e), 'query' => 0, 'community' => 0, 'campaign' => 0, 'other' => 0, 'total' => 0, 'again' => 0, 'orders' => 0, 'rate' => 0, 'moved_in' => 0, 'moved_out' => 0, 'missed' => 0];
    $st = $pdo->prepare("SELECT q.emp_id, q.src, COUNT(*) n, SUM(q.again) ag, SUM(EXISTS(SELECT 1 FROM wa_outcomes o WHERE o.phone = q.phone AND o.code = 'order' AND o.at >= q.opened_at)) ord
        FROM wa_queries q WHERE q.emp_id > 0 AND q.opened_at >= ? GROUP BY q.emp_id, q.src"); $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        $e = (int)$r['emp_id']; if (!isset($rows[$e])) $rows[$e] = $blank($e); $s = in_array($r['src'], ['query', 'community', 'campaign'], true) ? $r['src'] : 'other'; $n = (int)$r['n'];
        $rows[$e][$s] += $n; $rows[$e]['total'] += $n; $rows[$e]['again'] += (int)$r['ag']; $rows[$e]['orders'] += (int)$r['ord']; $tot[$s] += $n; $tot['total'] += $n; $tot['again'] += (int)$r['ag']; $tot['orders'] += (int)$r['ord'];
    }
    $st = $pdo->prepare("SELECT from_emp, to_emp, late FROM wa_query_moves WHERE at >= ?"); $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        foreach ([['from_emp', 'moved_out'], ['to_emp', 'moved_in']] as [$c, $f]) { $e = (int)$r[$c]; if ($e <= 0) continue; if (!isset($rows[$e])) $rows[$e] = $blank($e); $rows[$e][$f]++; }
        if ((int)$r['late'] === 1 && (int)$r['from_emp'] > 0) { $rows[(int)$r['from_emp']]['missed']++; $tot['missed']++; }
    }
    foreach ($rows as &$x) $x['rate'] = $x['total'] ? round(100 * $x['orders'] / $x['total'], 1) : 0; unset($x);
    $tot['rate'] = $tot['total'] ? round(100 * $tot['orders'] / $tot['total'], 1) : 0;
    $rows = array_values($rows); usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
    return ['rows' => $rows, 'team' => $tot];
}
/** Claude flag: customers who wrote, got no reply within 24 hours and are now only in History. */
function wag_lost_count(PDO $pdo, int $days = 7): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at >= ? AND last_in_at < ? AND (last_out_at IS NULL OR last_out_at < last_in_at)");
    $q->execute([date('Y-m-d H:i:s', time() - $days * 86400), date('Y-m-d H:i:s', time() - 86400)]); return (int)$q->fetchColumn();
}

}   // function_exists guard

require_once __DIR__ . '/wa_flows.php';   // the flow builder + its engine

// ---------------------------------------------------------------------------------------------------------------- actions
$__wg = (string)($action ?? ($_GET['action'] ?? ''));

// ---------------------------------------------------------------------------------------------------------------- Go-live check
/** Live readiness list for Admin: every item is measured from real data, not typed in. state: ok | warn | bad. */
function wag_golive(PDO $pdo, array $cfg): array
{
    $now = time(); $C = [];
    $add = function (string $k, string $t, string $st, string $now_, string $fix = '', string $go = '') use (&$C) { $C[] = ['k' => $k, 'title' => $t, 'state' => $st, 'now' => $now_, 'fix' => $fix, 'go' => $go]; };
    $age = function (?string $at) use ($now): int { $t = $at ? strtotime($at) : 0; return $t ? max(0, $now - $t) : -1; };
    $one = function (string $sql, array $p = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchColumn(); } catch (Throwable $e) { return null; } };
    $ago = function (int $s): string { return $s < 0 ? 'never' : ($s < 90 ? $s . ' sec ago' : ($s < 5400 ? round($s / 60) . ' min ago' : ($s < 172800 ? round($s / 3600) . ' h ago' : round($s / 86400) . ' days ago'))); };

    // 1 · the engine (cron)
    $ca = $age((string)setting_get($pdo, 'wa_cron_at', ''));
    $add('cron', 'Scheduled engine (cron) runs every minute', $ca >= 0 && $ca <= 180 ? 'ok' : ($ca >= 0 && $ca <= 1800 ? 'warn' : 'bad'), 'Last ran ' . $ago($ca) . '.',
        'Hostinger → Advanced → Cron Jobs → add one job, every minute, that opens the wa.cron address (with your key) — the same way your other crons run. Without it the bot, follow-ups, campaigns and the summaries stop.', 'signals');

    // 2 · secrets and numbers
    $sec = ['WA_TOKEN', 'WA_APP_SECRET', 'WA_VERIFY_TOKEN']; $miss = [];
    foreach ($sec as $k) if (!function_exists('wa_cfg_val') || wa_cfg_val($k) === '') $miss[] = $k;
    $add('secrets', 'Meta keys are in config.php (token, app secret, verify token)', $miss ? 'bad' : 'ok', $miss ? 'Missing: ' . implode(', ', $miss) . '.' : 'All three are set. Messages from Meta are only accepted with the matching signature.', $miss ? 'Add the missing line(s) to config.php on the server, then reload this page.' : '', 'numbers');
    $nums = []; try { $nums = $pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll(); } catch (Throwable $e) { }
    $nl = []; $dead = 0;
    foreach ($nums as $n) { $t = $one("SELECT ts FROM wa_messages WHERE number_id = ? AND dir = 'in' ORDER BY id DESC LIMIT 1", [(int)$n['id']]); $a = $age($t ? (string)$t : ''); if ($a < 0 || $a > 3 * 86400) $dead++; $nl[] = ($n['label'] !== '' ? $n['label'] : $n['display']) . ' — last customer message ' . $ago($a); }
    $add('numbers', 'Every number is added and has received a customer message', !$nums ? 'bad' : ($dead ? 'warn' : 'ok'), !$nums ? 'No number is added.' : implode(' · ', $nl),
        $dead ? 'A number with no message for 3+ days is either new (send it a test message from another phone) or its webhook is not connected — open Numbers and press the check.' : ($nums ? '' : 'Open Numbers and add the number.'), 'numbers');

    // 3 · templates
    $tq = []; try { foreach ($pdo->query("SELECT t.category, w.status, COUNT(*) n FROM wa_templates t JOIN wa_tpl_waba w ON w.tpl_id = t.id GROUP BY t.category, w.status")->fetchAll() as $r) $tq[strtoupper((string)$r['category'])][strtoupper((string)$r['status'])] = (int)$r['n']; } catch (Throwable $e) { }
    $ut = (int)($tq['UTILITY']['APPROVED'] ?? 0); $mk = (int)($tq['MARKETING']['APPROVED'] ?? 0); $pend = 0; foreach ($tq as $c => $m) $pend += (int)($m['PENDING'] ?? 0);
    $add('templates', 'Approved Utility templates exist (outside the 24-hour window you can only send templates)', $ut >= 2 ? 'ok' : ($ut === 1 ? 'warn' : 'bad'), $ut . ' Utility + ' . $mk . ' Marketing approved' . ($pend ? ', ' . $pend . ' waiting for Meta' : '') . '.',
        'Open Pre-made, write at least 2 Utility follow-ups (order update / reply to your question), submit them, and wait for Meta’s approval (usually minutes to a day).', 'premade');

    // 4 · bot
    $taught = false; try { $taught = function_exists('lib_guide_row') && function_exists('ensure_lib_tables') && ensure_lib_tables($pdo) && (bool)lib_guide_row($pdo, 'live'); } catch (Throwable $e) { }
    $add('bot', 'Answering bot is taught before it is switched on', $cfg['bot'] === 'on' ? ($taught ? 'ok' : 'bad') : ($taught ? 'ok' : 'warn'), 'Bot is ' . strtoupper($cfg['bot']) . '; ' . ($taught ? 'an approved guidebook exists.' : 'no approved guidebook yet.'),
        $taught ? '' : 'Open Learning, answer 10–15 real customer questions, approve the guidebook. Keep the bot OFF until then (it is OFF by default).', 'learn');

    // 5 · the team and their phones
    $ppl = []; try { $ppl = wa_people($pdo); } catch (Throwable $e) { }
    $subs = []; try { foreach ($pdo->query("SELECT employee_id, COUNT(*) n FROM push_subscriptions GROUP BY employee_id")->fetchAll() as $r) $subs[(int)$r['employee_id']] = (int)$r['n']; } catch (Throwable $e) { }
    $dev = []; $rows = [];
    foreach ($ppl as $p) {
        $e = (int)$p['emp_id']; $d = json_decode((string)setting_get($pdo, 'wa_dev_' . $e, ''), true); $d = is_array($d) ? $d : [];
        $g = (string)setting_get($pdo, 'wa_guide_' . $e, '');
        $rows[] = ['emp_id' => $e, 'name' => (string)$p['name'], 'present' => !empty($p['present']), 'push' => ($subs[$e] ?? 0) > 0, 'tested' => !empty($d['at']), 'tested_at' => (string)($d['at'] ?? ''), 'device' => (string)($d['device'] ?? ''), 'pass' => (int)($d['pass'] ?? 0), 'of' => (int)($d['of'] ?? 0), 'standalone' => !empty($d['standalone']), 'guide' => $g !== ''];
    }
    $tot = count($rows); $nPush = count(array_filter($rows, fn($r) => $r['push'])); $nTest = count(array_filter($rows, fn($r) => $r['tested'])); $nGuide = count(array_filter($rows, fn($r) => $r['guide']));
    $add('phones', 'Staff phones tested on their own device (alerts, home-screen install, sound)', !$tot ? 'bad' : ($nTest >= $tot ? 'ok' : ($nTest >= 2 ? 'warn' : 'bad')), $tot ? $nTest . ' of ' . $tot . ' salespeople ran “Check my phone”; ' . $nPush . ' have alerts on.' : 'No salespeople found.',
        'Ask each person to open My Chats on their phone → Me → “Check my phone” (2 minutes). iPhone: Share → Add to Home Screen first. You can then press “Send test buzz” below.', 'golive');
    $add('training', 'Staff have read the short “How it works” guide', !$tot ? 'warn' : ($nGuide >= $tot ? 'ok' : ($nGuide >= 2 ? 'warn' : 'bad')), $tot ? $nGuide . ' of ' . $tot . ' opened the guide in the app.' : '—', 'The guide opens by itself the first time each person enters My Chats (Me → How it works). Run a 15-minute walkthrough with the first group.', 'golive');
    $present = count(array_filter($rows, fn($r) => $r['present']));
    $add('team', 'At least 2 people are present to receive queries', $present >= 2 ? 'ok' : ($present === 1 ? 'warn' : 'bad'), $present . ' present now.', 'Open Team and mark who is on duty; queries go only to present people.', 'team');

    // 6 · pilot: real use by real people
    $pl = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM (SELECT emp_id FROM wa_messages WHERE ts >= ? AND dir = 'out' AND who NOT IN ('bot','flow','campaign','system') AND emp_id > 0 GROUP BY emp_id HAVING COUNT(*) >= 10) x"); $q->execute([date('Y-m-d H:i:s', $now - 86400)]); $pl = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    $add('pilot', 'Pilot: at least 2 people have really answered 10+ messages in the last 24 hours', $pl >= 2 ? 'ok' : ($pl === 1 ? 'warn' : 'bad'), $pl . ' people did.', 'Start with 2 people on the new numbers for one day before giving it to everyone.', 'queries');

    // 7 · security
    $adm = 0; try { $adm = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('Admin','HR')")->fetchColumn(); } catch (Throwable $e) { }
    $ex7 = (int)$one("SELECT COUNT(*) FROM wa_exports WHERE at >= ?", [date('Y-m-d H:i:s', $now - 7 * 86400)]);
    $add('security', 'Only Admin / HR can see all chats, export, run campaigns or change settings', 'ok', 'Checked in the server: a salesperson login gets “no permission” on every Admin action and sees only their own chats. ' . ($adm ? $adm . ' Admin/HR logins exist. ' : '') . $ex7 . ' export(s) in the last 7 days (each is logged).', 'Remove Admin/HR from anyone who should not export customer lists. Rotate the Shopify secret in api.php if it was ever shared.', 'export');

    // 8 · opt-outs
    $oo = (int)$one("SELECT COUNT(*) FROM wa_contacts WHERE optout = 1");
    $add('optout', 'STOP / unsubscribe is honoured and opted-out people can never be messaged', 'ok', $oo . ' customer(s) have opted out. Replies like “stop”, “unsubscribe”, “band karo” switch it on at once; sending to them is blocked by the server.', '', 'contacts');

    // 9 · failed sends / webhook errors
    $t24 = date('Y-m-d H:i:s', $now - 86400); $f24 = (int)$one("SELECT COUNT(*) FROM wa_messages WHERE status = 'failed' AND ts >= ? AND dir = 'out'", [$t24]); $o24 = (int)$one("SELECT COUNT(*) FROM wa_messages WHERE ts >= ? AND dir = 'out'", [$t24]);
    $e24 = (int)$one("SELECT COUNT(*) FROM wa_log WHERE ok = 0 AND at >= ?", [date('Y-m-d H:i:s', $now - 86400)]);
    $pc = $o24 > 0 ? $f24 / $o24 : 0;
    $add('fails', 'Messages are being delivered (few failed sends, no errors)', $pc > 0.1 || $e24 > 20 ? 'bad' : ($f24 > 0 || $e24 > 0 ? 'warn' : 'ok'), $f24 . ' of ' . $o24 . ' sends failed and ' . $e24 . ' error(s) were logged in the last 24 hours.', 'Open Setup → the log shows the reason (window closed, number blocked, template not approved).', 'setup');

    // 10 · backup
    $ba = $age((string)setting_get($pdo, 'wa_backup_at', ''));
    $add('backup', 'A database backup was taken today, before the new numbers go live', $ba >= 0 && $ba <= 86400 ? 'ok' : ($ba >= 0 ? 'warn' : 'bad'), $ba < 0 ? 'Nobody has confirmed a backup yet.' : 'Backup confirmed ' . $ago($ba) . '.', 'Hostinger → Databases → phpMyAdmin → Export (Quick, SQL) or hPanel → Backups → Generate. Then press “I took the backup” below.', 'golive');

    // 11 · speed health
    $cu = $age((string)setting_get($pdo, 'wa_cust_at', ''));
    $add('speed', 'Customer summary (tags & audiences) is fresh', $cu >= 0 && $cu <= 1200 ? 'ok' : ($cu >= 0 && $cu <= 7200 ? 'warn' : 'bad'), 'Last built ' . $ago($cu) . '.', 'It rebuilds itself every 10 minutes through the cron above.', 'tags');

    $bad = count(array_filter($C, fn($c) => $c['state'] === 'bad')); $warn = count(array_filter($C, fn($c) => $c['state'] === 'warn')); $ok = count($C) - $bad - $warn;
    return ['checks' => $C, 'people' => $rows, 'score' => (int)round(100 * ($ok + 0.5 * $warn) / max(1, count($C))), 'bad' => $bad, 'warn' => $warn, 'ok' => $ok, 'ready' => $bad === 0, 'at' => date('Y-m-d H:i:s')];
}

if (strpos($__wg, 'wa.g_') === 0 && strpos($__wg, 'wa.g_flow') !== 0) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth();
    if (!wa_tables($pdo) || !wa_more_tables($pdo) || !wag_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0); $cfg = wag_cfg($pdo); $adm = wa_is_admin($pdo, $auth); $in = $isPost ? json_input() : [];
    $chatOf = function (string $phone) use ($pdo, $auth, $__wg): array {   // the chat, or an error response
        $k = wa_key($phone); $c = $k !== '' ? wa_contact($pdo, $k) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
        $read = in_array($__wg, ['wa.g_poll', 'wa.g_customer'], true) && function_exists('wa_can_watch');   // the Team list may READ a colleague's chat (replying needs Take over)
        if (!($read ? (wa_can_watch($pdo, $auth, $c) || (function_exists('wa_req_peek') && wa_req_peek($pdo, $c) !== '') || (function_exists('wa_was_mine') && wa_was_mine($pdo, $k, (int)($auth['employee_id'] ?? 0)))) : wa_can_chat($pdo, $auth, $c))) json_response(['error' => 'This chat is assigned to someone else.'], 403); return $c;   // 🙋 a Requests chat may be read before Take over
    };
    switch ($__wg) {
        case 'wa.g_find': {   // 🔍 search ANY customer by number or name (top of the salesperson's Chats): who has the chat, may I open it
            $qq = trim((string)($_GET['q'] ?? '')); if (mb_strlen($qq) < 3) json_response(['items' => []]);
            $like = wa_q_like($qq); $names = employee_names_by_id($pdo); $canAll = function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth);
            $st = $pdo->prepare("SELECT phone, name, assigned_emp, source, last_in_at, last_out_at, cx_id FROM wa_contacts WHERE phone LIKE ? OR name LIKE ? ORDER BY COALESCE(last_in_at, last_out_at) DESC LIMIT 30"); $st->execute([$like, $like]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $emp = (int)$r['assigned_emp']; $mine = $emp === $me;
                $ok = $adm || $mine || $canAll || (function_exists('wa_was_mine') && wa_was_mine($pdo, (string)$r['phone'], $me)) || (function_exists('wa_req_peek') && wa_req_peek($pdo, $r) !== '');
                $out[] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'with' => $emp > 0 ? (string)($names[$emp] ?? '') : '', 'mine' => $mine, 'open' => $ok, 'src' => (string)$r['source'],
                    'last' => (string)($r['last_in_at'] ?: $r['last_out_at']), 'cx' => (int)($r['cx_id'] ?? 0) > 0];
            }
            json_response(['items' => $out]);
        }
        // ---------------------------------------------------------------- salesperson phone panel
        case 'wa.g_inbox': {
            if ((string)setting_get($pdo, 'wa_cron_at', '') < date('Y-m-d H:i:s', time() - 150)) register_shutdown_function(function () use ($pdo) { if (function_exists('litespeed_finish_request')) @litespeed_finish_request(); elseif (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); try { if ((int)$pdo->query("SELECT GET_LOCK('hrms_wa_cron', 0)")->fetchColumn()) wa_tick($pdo); } catch (Throwable $e) { } });   // ⚡ only ONE stand-in engine run at a time (15 phones polling used to start many together) · the page keeps the engine (assignment, alerts, bot) running even without cron — but AFTER the answer has been sent, so nobody waits for it
            $mgr = function_exists('wa_is_manager') && wa_is_manager($pdo, $auth);   // a WhatsApp manager sees the whole team (filter by person), like an admin
            $pick = ($adm || $mgr) && !empty($_GET['emp']) ? (int)$_GET['emp'] : 0;   // one person picked in the filter → ONLY their chats ('all' used to win and showed everyone)
            $all = ($adm || $mgr) && !empty($_GET['all']) && $pick === 0; $emp = $pick !== 0 ? $pick : $me;
            [$rows, $cnt] = wag_inbox($pdo, $emp > 0 ? $emp : -1, $all, $cfg, !empty($_GET['tag']) && !$all, !empty($_GET['wide']) && !$all);
            $men = []; try { $q = $pdo->prepare("SELECT n.id, n.phone, n.body, n.emp_id, n.at FROM wa_notes n WHERE n.mention = ? AND n.at >= ? ORDER BY n.id DESC LIMIT 10"); $q->execute([$me, date('Y-m-d H:i:s', time() - 3 * 86400)]); $nm = employee_names_by_id($pdo);
                foreach ($q->fetchAll() as $r) $men[] = ['id' => (int)$r['id'], 'phone' => $r['phone'], 'text' => $r['body'], 'from' => $nm[(int)$r['emp_id']] ?? '', 'at' => $r['at']]; } catch (Throwable $e) { }
            $rem = []; try { $q = $pdo->prepare("SELECT phone, name, remind_at FROM wa_contacts WHERE assigned_emp = ? AND remind_at IS NOT NULL AND remind_at <= ? ORDER BY remind_at LIMIT 10"); $q->execute([$me, date('Y-m-d H:i:s')]); $rem = $q->fetchAll(); } catch (Throwable $e) { }
            json_response(['chats' => $rows, 'counts' => $cnt, 'stats' => wag_stats($pdo, $me, $cfg), 'cfg' => ['unans_min' => $cfg['unans_min'], 'target' => $cfg['target'], 'sound' => $cfg['sound']], 'me' => ['emp_id' => $me, 'manager' => $mgr || $adm, 'team' => $mgr || $adm, 'name' => (employee_names_by_id($pdo)[$me] ?? ($adm ? 'Admin' : ''))],
                'mentions' => $men, 'reminders' => $rem, 'results' => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0], 'i' => $v[1], 'g' => $v[2]], array_keys(wag_outcomes()), array_values(wag_outcomes())), 'now' => date('Y-m-d H:i:s')]);
        }
        case 'wa.g_poll': {   // light refresh of ONE open chat: only messages after $after
            $c = $chatOf((string)($_GET['phone'] ?? '')); $after = (int)($_GET['after'] ?? 0); $key = (string)$c['phone'];
            $st = $pdo->prepare("SELECT id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts, number_id, reply_to FROM wa_messages WHERE phone = ? AND id > ? ORDER BY id LIMIT 60"); $st->execute([$key, $after]); $m = $st->fetchAll(); if (function_exists('wa_add_quotes')) wa_add_quotes($pdo, $m);
            $nums = wag_nums($pdo); foreach ($m as &$x) { $x['id'] = (int)$x['id']; $x['number'] = $nums[(int)$x['number_id']] ?? ''; } unset($x);
            $upd = []; if ($after > 0) { $q = $pdo->prepare("SELECT id, status FROM wa_messages WHERE phone = ? AND dir = 'out' AND id <= ? AND id >= ? AND status IN ('sent', 'delivered', 'read', 'failed')"); $q->execute([$key, $after, max(0, $after - 40)]); $upd = $q->fetchAll(); }   // read / failed too: blue ticks and "failed at delivery" show while the chat is open (the app only redraws the ones that changed)
            if ($m && ($me > 0 && (int)$c['assigned_emp'] === $me)) $pdo->prepare("UPDATE wa_contacts SET seen_msg_id = (SELECT COALESCE(MAX(id), 0) FROM wa_messages WHERE phone = ? AND dir = 'in') WHERE phone = ?")->execute([$key, $key]);
            json_response(['messages' => $m, 'status' => $upd, 'optout' => (int)($c['optout'] ?? 0) === 1, 'window_open' => !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 86400, 'waiting' => !empty($c['last_in_at']) && (empty($c['last_out_at']) || strtotime((string)$c['last_out_at']) < strtotime((string)$c['last_in_at']))]);
        }
        case 'wa.g_sugg': {   // the suggestion already written for the newest customer message — instant; null = none yet
            $c = $chatOf((string)($_GET['phone'] ?? '')); $s = wag_sugg_get($pdo, (string)$c['phone']);
            if (!empty($c['cx_id'])) json_response(['ok' => true, 'sugg' => null, 'hot' => false, 'auto' => ['on' => false, 'secs' => 20], 'complaint' => (int)$c['cx_id']]);   // with the Complaint team: no suggestion, no auto-send
            if (!$s && !empty($_GET['make'])) { $r = wag_brain_reply($pdo, $c); if ($r['ok']) { wa_sugg_save($pdo, (string)$c['phone'], $r['text'], $r['source'], (int)$r['ver']); $s = ['text' => $r['text'], 'source' => $r['source'], 'ver' => $r['ver'], 'why' => $r['why'], 'call_now' => !empty($r['handoff'])]; } }
            $__li = wa_last_in($pdo, (string)$c['phone']); json_response(['ok' => true, 'sugg' => $s, 'hot' => !empty($c['hot']) || (function_exists('wa_hot_intent') && wa_hot_intent((string)$__li[1]) !== ''), 'auto' => function_exists('wa_auto_state') ? wa_auto_state($pdo) : ['on' => false, 'secs' => 20]]);
        }
        case 'wa.g_customer': {
            $c = $chatOf((string)($_GET['phone'] ?? '')); $key = (string)$c['phone']; $om = wa_order_map($pdo)[$key] ?? null; $names = employee_names_by_id($pdo);
            $notes = []; $q = $pdo->prepare("SELECT id, emp_id, body, mention, at FROM wa_notes WHERE phone = ? ORDER BY id DESC LIMIT 15"); $q->execute([$key]);
            foreach ($q->fetchAll() as $n) $notes[] = ['id' => (int)$n['id'], 'by' => $names[(int)$n['emp_id']] ?? '', 'text' => $n['body'], 'to' => $names[(int)$n['mention']] ?? '', 'at' => $n['at']];
            $res = []; $q = $pdo->prepare("SELECT code, note, emp_id, at FROM wa_outcomes WHERE phone = ? ORDER BY id DESC LIMIT 6"); $q->execute([$key]); $defs = wag_outcomes();
            foreach ($q->fetchAll() as $r) $res[] = ['k' => $r['code'], 't' => $defs[$r['code']][0] ?? $r['code'], 'i' => $defs[$r['code']][1] ?? '', 'note' => $r['note'], 'by' => $names[(int)$r['emp_id']] ?? '', 'at' => $r['at']];
            $tags = wag_row_tags(['phone' => $key, 'orders' => (int)($om['n'] ?? 0), 'spent' => (int)($om['total'] ?? 0), 'days' => null, 'ignored' => 0, 'last_in_at' => $c['last_in_at'], 'source' => $c['source'], 'tag' => $c['tag'], 'optout' => $c['optout']], $cfg);
            $cat = wag_tag_catalog(); $own = wag_ctag_map($pdo)[$key] ?? [];
            json_response(['name' => $c['name'], 'phone' => $key, 'orders' => wag_orders($pdo, $key), 'orders_n' => (int)($om['n'] ?? 0), 'ltv' => (int)($om['total'] ?? 0), 'last_order' => $om['last'] ?? '', 'badge' => wag_badge($pdo, $c, $om, $tags, $cfg),
                'tags' => array_map(fn($t) => ['k' => $t, 't' => $cat[$t] ?? $t, 'own' => isset($own[$t])], $tags), 'numbers' => wag_number_info($pdo, $c), 'notes' => $notes, 'results' => $res, 'summary' => $c['summary'], 'ad' => $c['ad_head'] !== '' ? $c['ad_head'] : $c['ref_text'],
                'first_seen' => $c['first_seen'], 'colleagues' => array_values(array_filter(array_map(fn($p) => $p['emp_id'] !== $me ? ['emp_id' => $p['emp_id'], 'name' => $p['name']] : null, wa_people($pdo))))]);
        }
        case 'wa.g_note_add': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $chatOf((string)($in['phone'] ?? '')); $t = trim(mb_substr((string)($in['text'] ?? ''), 0, 600)); if ($t === '') json_response(['error' => 'Write the note first.'], 422);
            $mn = (int)($in['mention'] ?? 0); if ($mn > 0 && !isset(employee_names_by_id($pdo)[$mn])) $mn = 0;
            $pdo->prepare("INSERT INTO wa_notes (phone, emp_id, body, mention, at) VALUES (?, ?, ?, ?, ?)")->execute([$c['phone'], $me, $t, $mn, date('Y-m-d H:i:s')]);
            if ($mn > 0 && function_exists('ct_push')) { try { $nm = employee_names_by_id($pdo); ct_push($pdo, [$mn], ($nm[$me] ?? 'A colleague') . ' mentioned you', mb_substr($t, 0, 100), '/my_chats.html', 'wa-men-' . $c['phone']); } catch (Throwable $e) { } }
            json_response(['ok' => true]);
        }
        case 'wa.g_outcome': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $chatOf((string)($in['phone'] ?? ''));
            [$ok, $r] = wag_outcome_save($pdo, $c, (string)($in['code'] ?? ''), trim((string)($in['note'] ?? '')), (float)($in['amount'] ?? 0), $me, (int)($in['remind_mins'] ?? 0));
            if (!$ok) json_response(['error' => $r], 422); json_response(['ok' => true, 'stats' => $r, 'code' => (string)$in['code']]);
        }
        case 'wa.g_me': {
            $nm = employee_names_by_id($pdo); $b = []; $d0 = date('Y-m-d 00:00:00'); $w0 = date('Y-m-d 00:00:00', time() - 6 * 86400);
            foreach ([['today', $d0], ['week', $w0]] as [$k, $since]) { $q = $pdo->prepare("SELECT emp_id, COUNT(*) n, COALESCE(SUM(amount), 0) s FROM wa_outcomes WHERE code = 'order' AND at >= ? AND emp_id > 0 GROUP BY emp_id ORDER BY n DESC, s DESC LIMIT 8"); $q->execute([$since]);
                $b[$k] = array_map(fn($r) => ['name' => $nm[(int)$r['emp_id']] ?? '?', 'n' => (int)$r['n'], 'amount' => (int)round((float)$r['s']), 'me' => (int)$r['emp_id'] === $me], $q->fetchAll()); }
            json_response(['stats' => wag_stats($pdo, $me, $cfg), 'board' => $b]);
        }
        case 'wa.g_history': {   // chats whose 24-hour window is over — salespeople see their own, Admin sees everyone's with filters
            $team = $adm || (function_exists('wa_is_manager') && wa_is_manager($pdo, $auth));   // a WhatsApp manager sees everyone's history and can pick a person, like an admin
            $f = $_GET; if (!$team) unset($f['emp']);
            json_response(wag_history($pdo, $me, $team, $f, $cfg) + ['admin' => $team, 'results' => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0], 'i' => $v[1]], array_keys(wag_outcomes()), array_values(wag_outcomes()))]);
        }
        case 'wa.g_push_state': {   // does THIS person's phone already allow alerts?
            $n = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE employee_id = ?"); $q->execute([$me]); $n = (int)$q->fetchColumn(); } catch (Throwable $e) { }
            json_response(['subscribed' => $n > 0, 'devices' => $n]);
        }
        case 'wa.g_hq_me': {   // the floating Claude button: nobody has it unless the Admin ticked them
            $ids = array_values(array_filter(array_map('intval', (array)json_decode((string)setting_get($pdo, 'hq_btn_emps', '[]'), true)))); json_response(['on' => $me > 0 && in_array($me, $ids, true)]);
        }
        case 'wa.g_gallery': { wag_use_tables($pdo); json_response(['gallery' => wag_gallery($pdo), 'can_edit' => $adm]); }
        case 'wa.g_thumb': {
            $p = wag_thumb($pdo, (int)($_GET['id'] ?? 0)); if (!$p) json_response(['error' => 'No preview.'], 404);
            header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize($p)); readfile($p); exit;
        }
        case 'wa.g_gallery_send': {   // = wa.lib_send, plus a note of which photo went to whom (so Claude can tell which photos are never used)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $chatOf((string)($in['phone'] ?? ''));
            if ((int)$c['optout'] === 1) json_response(['error' => 'This customer opted out — do not message them.'], 422);
            if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) json_response(['error' => 'The 24-hour reply window is closed. Only an approved pre-made message can be sent now.'], 422);
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0]))))), 0, 6); if (!$ids) json_response(['error' => 'Pick a photo first.'], 422);
            $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') json_response(['error' => 'WA_PHONE_ID is missing in config.php.'], 422);
            ignore_user_abort(true); wag_use_tables($pdo); $sent = 0; $err = '';
            foreach ($ids as $id) {
                $r = wa_lib_row($pdo, $id); if (!$r) continue; [$mid, $e] = wa_lib_media_id($pdo, $r, $pid); if ($mid === '') { $err = $e; continue; }
                [$ok, $res] = wa_send_media($pdo, $c, (string)$r['kind'], $mid, (string)$r['mime'], $sent === 0 ? trim(mb_substr((string)($in['caption'] ?? ''), 0, 1000)) : '', (string)$r['filename'], $me);
                if (!$ok && preg_match('/media|expired|not found|131053|131052/i', (string)$res)) { [$mid, $e] = wa_lib_media_id($pdo, $r, $pid, true); if ($mid !== '') [$ok, $res] = wa_send_media($pdo, $c, (string)$r['kind'], $mid, (string)$r['mime'], '', (string)$r['filename'], $me); }
                if ($ok) { $sent++; $pdo->prepare("INSERT INTO wa_media_use (lib_id, phone, emp_id, at) VALUES (?, ?, ?, ?)")->execute([$id, $c['phone'], $me, date('Y-m-d H:i:s')]); } else $err = is_string($res) ? $res : $err;
            }
            if ($sent === 0) json_response(['error' => $err ?: 'Nothing was sent.'], 422); json_response(['ok' => true, 'sent' => $sent, 'partial' => $err !== '']);
        }
        case 'wa.g_transcribe': {   // a customer's voice note as text (Hindi / Gujarati / English). Needs the Gemini key.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $chatOf((string)($in['phone'] ?? '')); $mid = preg_replace('/\D/', '', (string)($in['media_id'] ?? ''));
            $q = $pdo->prepare("SELECT id FROM wa_messages WHERE phone = ? AND media_id = ? AND mtype = 'audio' LIMIT 1"); $q->execute([$c['phone'], $mid]); if (!$q->fetchColumn()) json_response(['error' => 'Voice note not found.'], 404);
            if (!function_exists('convo_gemini_key') || convo_gemini_key() === '') json_response(['error' => 'Voice-to-text needs GEMINI_API_KEY in config.php.'], 422);
            [$cd, $d] = wa_api('GET', '/' . $mid); if ($cd < 200 || $cd >= 300 || empty($d['url'])) json_response(['error' => 'Meta could not give this voice note (it may have expired).'], 502);
            [$c2, , $raw] = wa_http('GET', (string)$d['url'], ['Authorization: Bearer ' . wa_cfg_val('WA_TOKEN')], null, true); if ($c2 < 200 || $c2 >= 300 || $raw === '' || strlen($raw) > 8000000) json_response(['error' => 'Could not download the voice note.'], 502);
            $mime = preg_match('#^audio/[a-z0-9.+-]+#i', (string)($d['mime_type'] ?? '')) ? strtolower(preg_replace('/;.*/', '', (string)$d['mime_type'])) : 'audio/ogg'; $sch = ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING'], 'language' => ['type' => 'STRING']], 'required' => ['text']];
            ignore_user_abort(true); try { [$ok, $v, $why] = convo_ai('Write down exactly what the speaker says, in the language spoken (Hindi, Gujarati or English). Hindi or Gujarati may be written in English letters if that is how people text. No commentary.', [['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($raw)]], ['text' => 'Transcribe this WhatsApp voice note.']], $sch, true, 1500); } catch (Throwable $e) { json_response(['error' => 'The AI could not read this voice note.'], 502); }
            if (!$ok || !is_array($v) || trim((string)($v['text'] ?? '')) === '') json_response(['error' => (string)($why ?: 'Nothing could be heard in this voice note.')], 502);
            json_response(['ok' => true, 'text' => mb_substr(trim((string)$v['text']), 0, 1500)]);
        }
        case 'wa.g_dev_report': {   // the phone's own self-check, so the Admin can see who tested on a real device
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0) json_response(['error' => 'No employee on this login.'], 403);
            $d = ['at' => date('Y-m-d H:i:s'), 'device' => mb_substr(trim((string)($in['device'] ?? '')), 0, 80), 'pass' => max(0, (int)($in['pass'] ?? 0)), 'of' => max(0, (int)($in['of'] ?? 0)), 'standalone' => !empty($in['standalone']), 'push' => !empty($in['push']), 'sw' => !empty($in['sw']), 'sound' => !empty($in['sound']), 'w' => (int)($in['w'] ?? 0)];
            setting_set($pdo, 'wa_dev_' . $me, json_encode($d)); json_response(['ok' => true]);
        }
        case 'wa.g_guide_seen': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me > 0 && (string)setting_get($pdo, 'wa_guide_' . $me, '') === '') setting_set($pdo, 'wa_guide_' . $me, date('Y-m-d H:i:s'));
            json_response(['ok' => true]);
        }
        case 'wa.g_push_self': {   // "send a test buzz to this phone" from the phone's own check
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0 || !function_exists('push_send_employee')) json_response(['ok' => false, 'note' => 'Alerts are not available for this login.']);
            [$sent, $failed, $err] = push_send_employee($pdo, $me, ['title' => 'Test buzz 🔔', 'body' => 'Alerts work on this phone.', 'url' => 'my_chats.html', 'tag' => 'wa-test']);
            json_response(['ok' => $sent > 0, 'note' => $sent > 0 ? 'Buzz sent — it should arrive in a few seconds.' : 'No alert could be sent: allow alerts first (step 3).']);
        }
        case 'wa.g_guide_state': { json_response(['seen' => $me > 0 && (string)setting_get($pdo, 'wa_guide_' . $me, '') !== '']); }
    }
    // ---------------------------------------------------------------- everything below is for Admin / HR
    wa_guard_admin($pdo, $auth);
    switch ($__wg) {
        case 'wa.g_qload': {   // the one Queries section: automatic counts from who holds each chat now
            $d = (int)($_GET['days'] ?? 1); if (!in_array($d, [1, 7, 30], true)) $d = 1; $since = $d === 1 ? date('Y-m-d 00:00:00') : date('Y-m-d 00:00:00', time() - ($d - 1) * 86400);
            $o = wag_qload($pdo, $since); $o['days'] = $d; $o['since'] = $since; $o['unassigned'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE status = 'open' AND assigned_emp = 0 AND optout = 0 AND last_in_at IS NOT NULL")->fetchColumn(); $o['lost'] = wag_lost_count($pdo); json_response($o);
        }
        case 'wa.g_signals': {
            $cut = date('Y-m-d H:i:s', time() - $cfg['unans_min'] * 60); $names = employee_names_by_id($pdo);
            $st = $pdo->prepare("SELECT c.phone, c.name, c.assigned_emp, c.last_in_at, c.source, c.ad_head, c.hot, (SELECT body FROM wa_messages m WHERE m.phone = c.phone AND m.dir = 'in' ORDER BY m.id DESC LIMIT 1) last_body
                FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.last_in_at >= ? AND c.last_in_at <= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) ORDER BY c.last_in_at LIMIT 100");
            $st->execute([date('Y-m-d H:i:s', time() - 86400), $cut]);
            $rows = array_map(fn($r) => ['phone' => $r['phone'], 'name' => $r['name'], 'emp' => (int)$r['assigned_emp'], 'emp_name' => $names[(int)$r['assigned_emp']] ?? '', 'wait_min' => wag_mins((string)$r['last_in_at']), 'text' => mb_substr((string)$r['last_body'], 0, 90), 'src' => $r['source'], 'ad' => $r['ad_head'], 'hot' => (int)$r['hot'] === 1], $st->fetchAll());
            $ppl = array_map(fn($p) => ['emp_id' => $p['emp_id'], 'name' => $p['name'], 'present' => $p['present'], 'open' => $p['open'], 'full' => $p['full'], 'median' => $p['median'], 'best' => !empty($p['best'])], wa_people($pdo));
            $bot = ['on' => $cfg['bot'] === 'on']; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE who = 'bot' AND dir = 'out' AND ts >= ?"); $q->execute([date('Y-m-d 00:00:00')]); $bot['today'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
            json_response(['late' => $rows, 'people' => $ppl, 'cfg' => $cfg, 'bot' => $bot, 'cron_at' => (string)setting_get($pdo, 'wa_cron_at', '')]);
        }
        case 'wa.g_reassign': case 'wa.g_reassign_all': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $one = function (string $key, int $to) use ($pdo, $me): string {
                $c = wa_contact($pdo, $key); if (!$c) return ''; $cur = (int)$c['assigned_emp'];
                if ($to <= 0 && ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint')) return '';   // "give to the next free person" never moves a complaint chat
                if ($to <= 0) { $nx = wa_next_person($pdo, $cur, (int)$c['last_number_id']); if (!$nx) return ''; $to = (int)$nx['emp_id']; }
                if ($to === $cur) return ''; wa_assign_to($pdo, $key, $to, $me, 'manual'); $pdo->prepare("UPDATE wa_contacts SET unans_at = NULL WHERE phone = ?")->execute([$key]); return (string)(employee_names_by_id($pdo)[$to] ?? ('#' . $to));
            };
            if ($__wg === 'wa.g_reassign') { $n = $one(wa_key($in['phone'] ?? ''), (int)($in['emp_id'] ?? 0)); if ($n === '') json_response(['error' => 'Nobody else is free right now.'], 422); json_response(['ok' => true, 'to' => $n]); }
            $cut = date('Y-m-d H:i:s', time() - $cfg['unans_min'] * 60); $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE status = 'open' AND optout = 0 AND assigned_emp > 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) LIMIT 40");
            $st->execute([date('Y-m-d H:i:s', time() - 86400), $cut]); $n = 0; foreach ($st->fetchAll() as $r) if ($one((string)$r['phone'], 0) !== '') $n++;
            json_response(['ok' => true, 'moved' => $n]);
        }
        case 'wa.g_cfg': {
            $taught = false; try { $taught = function_exists('lib_guide_row') && function_exists('ensure_lib_tables') && ensure_lib_tables($pdo) && (bool)lib_guide_row($pdo, 'live'); } catch (Throwable $e) { }
            json_response(['cfg' => $cfg, 'taught' => $taught, 'cron_at' => (string)setting_get($pdo, 'wa_cron_at', ''), 'tick_at' => (string)setting_get($pdo, 'wa_tick_at', '')]);
        }
        case 'wa.g_cfg_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = $cfg;
            foreach (['unans_min', 'bot_after', 'bot_max_day', 'target', 'vip_orders', 'vip_ltv'] as $k) if (isset($in[$k])) $c[$k] = (int)$in[$k];
            foreach (['unans_push', 'ai_tags', 'sound'] as $k) if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]);
            if (isset($in['bot'])) $c['bot'] = $in['bot'] === 'on' || $in['bot'] === true ? 'on' : 'off';
            setting_set($pdo, 'wa_g_cfg', json_encode($c)); wag_log($pdo, 'bot', true, 'Settings saved — bot ' . $c['bot'] . ', unanswered limit ' . $c['unans_min'] . ' min.'); json_response(['ok' => true, 'cfg' => $c]);   // the saved values (the settings reader caches within a request)
        }
        case 'wa.g_tags': { $t = wag_tag_counts($pdo); $t['catalog'] = wag_tag_catalog(); json_response($t); }
        case 'wa.g_tag_set': {   // a person adds / removes a tag on one chat
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $key = wa_key($in['phone'] ?? ''); if (!$key || !wa_contact($pdo, $key)) json_response(['error' => 'Chat not found.'], 404);
            $t = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', (string)($in['tag'] ?? '')) ?? '')); if (mb_strlen($t) < 2) json_response(['error' => 'Write a tag (2+ letters).'], 422);
            if (!empty($in['on'])) { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'person', ?)")->execute([$key, mb_substr($t, 0, 28), date('Y-m-d H:i:s')]); wag_flow_hook_tag($pdo, $key, mb_substr($t, 0, 28)); }
            else $pdo->prepare("DELETE FROM wa_ctags WHERE phone = ? AND tag = ?")->execute([$key, $t]);
            json_response(['ok' => true]);
        }
        case 'wa.g_tag_claude': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $c = wa_contact($pdo, wa_key($in['phone'] ?? '')); if (!$c) json_response(['error' => 'Chat not found.'], 404);
            ignore_user_abort(true); [$ok, $r] = wag_ai_tag_one($pdo, $c); if (!$ok) json_response(['error' => (string)$r], 502); json_response(['ok' => true, 'tags' => $r]);
        }
        case 'wa.g_tag_batch': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); ignore_user_abort(true); @set_time_limit(120); $n = 0; $left = 0;
            $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE last_in_at >= ? AND (ctag_at IS NULL OR ctag_at < last_in_at) ORDER BY last_in_at DESC LIMIT 10"); $st->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]);
            foreach ($st->fetchAll() as $c) { [$ok] = wag_ai_tag_one($pdo, $c); if ($ok) $n++; else $pdo->prepare("UPDATE wa_contacts SET ctag_at = ? WHERE phone = ?")->execute([date('Y-m-d H:i:s'), $c['phone']]); }
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE last_in_at >= ? AND (ctag_at IS NULL OR ctag_at < last_in_at)"); $q->execute([date('Y-m-d H:i:s', time() - 90 * 86400)]); $left = (int)$q->fetchColumn();
            json_response(['ok' => true, 'tagged' => $n, 'left' => $left]);
        }
        case 'wa.g_lib': {
            $rows = $pdo->query("SELECT * FROM wa_msgset ORDER BY purpose, id DESC")->fetchAll(); $pu = []; foreach (wag_purposes() as $k => $v) $pu[] = ['k' => $k, 't' => $v[0], 'when' => $v[1], 'cat' => $v[2]];
            json_response(['purposes' => $pu, 'sets' => array_map('wag_lib_public', $rows)]);
        }
        case 'wa.g_lib_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $id = (int)($in['id'] ?? 0); $p = (string)($in['purpose'] ?? ''); if (!isset(wag_purposes()[$p])) json_response(['error' => 'Choose what this message is for.'], 422);
            $body = trim(mb_substr((string)($in['body'] ?? ''), 0, 1024)); $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 80)) ?: wag_purposes()[$p][0]; $cat = ($in['category'] ?? '') === 'MARKETING' ? 'MARKETING' : 'UTILITY';
            $use = wag_clean_words($in['use'] ?? []); $avoid = wag_clean_words($in['avoid'] ?? []); $now = date('Y-m-d H:i:s');
            if ($id > 0) $pdo->prepare("UPDATE wa_msgset SET purpose = ?, title = ?, body = ?, use_words = ?, avoid_words = ?, category = ?, tpl_id = ?, status = IF(status = 'approved', 'draft', status) WHERE id = ?")->execute([$p, $title, $body, json_encode($use, JSON_UNESCAPED_UNICODE), json_encode($avoid, JSON_UNESCAPED_UNICODE), $cat, (int)($in['tpl_id'] ?? 0), $id]);
            else { $pdo->prepare("INSERT INTO wa_msgset (purpose, title, body, use_words, avoid_words, category, tpl_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)")->execute([$p, $title, $body, json_encode($use, JSON_UNESCAPED_UNICODE), json_encode($avoid, JSON_UNESCAPED_UNICODE), $cat, (int)($in['tpl_id'] ?? 0), $now]); $id = (int)$pdo->lastInsertId(); }
            json_response(['ok' => true, 'id' => $id]);
        }
        case 'wa.g_lib_status': {   // approve = this is THE fixed message of its purpose from now on (an older approved one of the same purpose is retired)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $id = (int)($in['id'] ?? 0); $st = (string)($in['status'] ?? ''); if (!in_array($st, ['approved', 'draft', 'retired'], true)) json_response(['error' => 'Unknown status.'], 422);
            $q = $pdo->prepare("SELECT * FROM wa_msgset WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Not found.'], 404);
            if ($st === 'approved') { if (trim((string)$r['body']) === '') json_response(['error' => 'Write the message text first.'], 422); $pdo->prepare("UPDATE wa_msgset SET status = 'retired' WHERE purpose = ? AND status = 'approved' AND id <> ?")->execute([$r['purpose'], $id]); }
            $pdo->prepare("UPDATE wa_msgset SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?")->execute([$st, $me, $st === 'approved' ? date('Y-m-d H:i:s') : null, $id]); json_response(['ok' => true]);
        }
        case 'wa.g_lib_check': { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); json_response(wag_msg_check($pdo, (string)($in['purpose'] ?? ''), (string)($in['text'] ?? ''))); }
        case 'wa.g_lib_words': {   // Claude suggests the words that belong in (and stay out of) the fixed message of one purpose
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $p = (string)($in['purpose'] ?? ''); if (!isset(wag_purposes()[$p])) json_response(['error' => 'Choose what this message is for.'], 422);
            [$use, $avoid] = wag_default_words($p); $res = ['use' => $use, 'avoid' => $avoid, 'example' => '', 'why' => 'Standard starting words for this kind of message.', 'ai' => false];
            if (function_exists('convo_ai')) {
                $sch = ['type' => 'OBJECT', 'properties' => ['use' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'avoid' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'example' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['use', 'avoid', 'example', 'why']];
                $sys = "You help the owner of a perfume shop (WhatsApp, India, Hinglish) fix the wording of ONE kind of message. Meta decides Utility (about the customer's own order or enquiry) versus Marketing (offers, promotions) from the words. Give: use = 4–7 short words or phrases that must be in this message so it stays honest and, where possible, Utility; avoid = words that push it into Marketing or sound spammy; example = one short Hinglish message using the {{1}} first-name variable not at the very start or end; why = one plain sentence. Never promise a Utility result — say when it will probably be Marketing.";
                try { [$ok, $v] = convo_ai($sys, [['text' => 'Kind of message: ' . wag_purposes()[$p][0] . ' — ' . wag_purposes()[$p][1] . '. Expected category: ' . wag_purposes()[$p][2] . '.' . (trim((string)($in['note'] ?? '')) !== '' ? "\nOwner's note: " . mb_substr((string)$in['note'], 0, 400) : '')]], $sch, false, 1500, 'claude');
                    if ($ok && is_array($v)) $res = ['use' => wag_clean_words($v['use'] ?? []) ?: $use, 'avoid' => wag_clean_words($v['avoid'] ?? []) ?: $avoid, 'example' => mb_substr(trim((string)($v['example'] ?? '')), 0, 600), 'why' => mb_substr(trim((string)($v['why'] ?? '')), 0, 300), 'ai' => true]; } catch (Throwable $e) { }
            }
            json_response(['ok' => true] + $res);
        }
        case 'wa.g_build': {   // the owner says what he wants → Utility-first variants, with honest category guesses and cheaper routes
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $want = trim(mb_substr((string)($in['want'] ?? ''), 0, 1200)); $purpose = (string)($in['purpose'] ?? ''); if ($want === '') json_response(['error' => 'Tell me what you want to send.'], 422);
            if (!function_exists('convo_ai')) json_response(['error' => 'The AI is not available.'], 502); $tc = wa_team_cfg($pdo); $ru = (float)$tc['rate_utility']; $rm = (float)$tc['rate_marketing'];
            $words = ''; if (isset(wag_purposes()[$purpose])) { $q = $pdo->prepare("SELECT use_words, avoid_words FROM wa_msgset WHERE purpose = ? AND status = 'approved' ORDER BY id DESC LIMIT 1"); $q->execute([$purpose]); $r = $q->fetch(); if ($r) $words = "\nThe owner approved these words for this kind of message — use: " . $r['use_words'] . " — avoid: " . $r['avoid_words'] . '.'; }
            $sch = ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'STRING'], 'utility_possible' => ['type' => 'BOOLEAN'], 'utility_note' => ['type' => 'STRING'],
                'variants' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['label' => ['type' => 'STRING'], 'category' => ['type' => 'STRING', 'enum' => ['UTILITY', 'MARKETING']], 'why' => ['type' => 'STRING'], 'name' => ['type' => 'STRING'], 'language' => ['type' => 'STRING', 'enum' => ['en', 'hi', 'gu']],
                    'header_text' => ['type' => 'STRING'], 'body' => ['type' => 'STRING'], 'footer' => ['type' => 'STRING'], 'buttons' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING']], 'required' => ['text']]],
                    'variable_examples' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'variable_names' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['label', 'category', 'why', 'name', 'language', 'body', 'variable_examples', 'variable_names']]]], 'required' => ['understood', 'utility_possible', 'utility_note', 'variants']];
            $sys = "You help the owner of a perfume shop (Surat, India) write WhatsApp pre-made messages (templates) that Meta must approve. FIRST say, in 'understood', how you understood the request. Then decide honestly in 'utility_possible' whether the GOAL can be a Utility message: Utility only if it is about something the customer already did or asked for (their order, payment, delivery, enquiry, a reminder tied to their own order). A discount, offer, festival greeting or 'come buy again' message is Marketing and you must say so — never pretend; Marketing is acceptable. Write 3 variants with DIFFERENT wording and angles; make the first one the most Utility-like version of the goal, and mark each variant's category as your honest best guess (the final decision is Meta's). Hinglish by default (language 'en'), friendly, short, at most 2 emojis. Variables: {{1}} is ALWAYS the customer's first name (never the very first or very last thing in the text); later variables {{2}}, {{3}} are things like order number, tracking link, amount, size — list a short name for each in variable_names in order (first is 'name') and an example value for each in variable_examples. Do not use variables that the shop cannot fill: the shop knows name, order number, order amount, tracking link, size. Names are lower case a-z and underscore. Buttons only if useful, max 2, short." . $words;
            ignore_user_abort(true); try { [$ok, $v, $why] = convo_ai($sys, [['text' => "WHAT THE OWNER WANTS:\n" . $want . ($purpose !== '' && isset(wag_purposes()[$purpose]) ? "\nKind of message: " . wag_purposes()[$purpose][0] : '')]], $sch, false, 4000, 'claude'); } catch (Throwable $e) { json_response(['error' => 'The AI could not write the messages.'], 502); }
            if (!$ok || !is_array($v)) json_response(['error' => (string)($why ?: 'The AI could not write the messages.')], 502);
            $out = [];
            foreach (array_slice((array)($v['variants'] ?? []), 0, 4) as $x) {
                if (!is_array($x)) continue; $d = ['name' => (string)($x['name'] ?? $x['label'] ?? 'msg') . '_' . date('md'), 'category' => (string)$x['category'], 'language' => (string)($x['language'] ?? 'en'), 'header_text' => (string)($x['header_text'] ?? ''), 'body' => (string)($x['body'] ?? ''), 'footer' => (string)($x['footer'] ?? ''),
                    'buttons' => (array)($x['buttons'] ?? []), 'variable_examples' => (array)($x['variable_examples'] ?? []), 'purpose' => mb_substr($want, 0, 600), 'understood' => mb_substr((string)($v['understood'] ?? ''), 0, 900)];
                [$clean, $err] = wa_tpl_clean($d); $clean['purpose'] = $d['purpose']; $clean['understood'] = $d['understood'];
                $chk = $purpose !== '' ? wag_msg_check($pdo, $purpose, (string)$clean['body']) : null;
                $out[] = ['label' => mb_substr((string)($x['label'] ?? 'Variant'), 0, 40), 'category' => $clean['category'], 'why' => mb_substr((string)($x['why'] ?? ''), 0, 300), 'draft' => $clean, 'problem' => $err, 'vars' => array_values(array_map('strval', (array)($x['variable_names'] ?? []))), 'rate' => $clean['category'] === 'UTILITY' ? $ru : $rm, 'check' => $chk];
            }
            json_response(['ok' => true, 'understood' => mb_substr((string)($v['understood'] ?? ''), 0, 900), 'utility_possible' => !empty($v['utility_possible']), 'utility_note' => mb_substr((string)($v['utility_note'] ?? ''), 0, 500), 'variants' => $out, 'rates' => ['utility' => $ru, 'marketing' => $rm],
                'routes' => ['Inside 24 hours of the customer\'s last message you can write freely: no pre-made message, no per-message charge.', 'A customer who came from a Click-to-WhatsApp ad has a 72-hour free window.', 'Order updates (shipped, payment pending, COD confirm) can be Utility; offers cannot.', 'Rates shown are the ones saved in Team & routing — check Meta\'s current rate card.']]);
        }
        case 'wa.g_claude': {
            $screen = (string)($_GET['screen'] ?? 'home'); $items = array_values(array_filter(wag_claude_items($pdo), fn($x) => in_array($screen, $x['on'], true) || $screen === 'all')); $sys = wag_systems($pdo);
            foreach ($items as &$x) { $x['needs'] = array_values(array_map(fn($k) => ['k' => $k, 'label' => $sys[$k]['label'] ?? $k, 'status' => $sys[$k]['status'] ?? 'missing', 'note' => $sys[$k]['note'] ?? ''], $x['needs'])); unset($x['on']); } unset($x);
            $ord = ['bad' => 0, 'warn' => 1, 'idea' => 2, 'tip' => 3]; usort($items, fn($a, $b) => ($ord[$a['level']] ?? 9) <=> ($ord[$b['level']] ?? 9));
            json_response(['items' => $items, 'systems' => $sys]);
        }
        case 'wa.g_claude_ask': {   // the same facts, thought through by Claude: three concrete next steps. Counts only — no phone numbers or chat text go to the AI.
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!function_exists('convo_ai')) json_response(['error' => 'The AI is not available.'], 502);
            $screen = (string)($in['screen'] ?? 'home'); $items = array_values(array_filter(wag_claude_items($pdo), fn($x) => in_array($screen, $x['on'], true) || $screen === 'all')); $facts = ''; foreach ($items as $x) $facts .= '- [' . $x['level'] . '] ' . $x['text'] . "\n";
            $t = wag_tag_counts($pdo); $tg = ''; foreach (array_slice($t['tags'], 0, 14) as $x) $tg .= $x['t'] . ': ' . $x['all'] . ' (30d ' . $x['d30'] . ', 90d ' . $x['d90'] . ")\n";
            $sysm = wag_systems($pdo); $sl = ''; foreach ($sysm as $k => $s) $sl .= $k . ' = ' . $s['label'] . ' [' . $s['status'] . "]\n";
            $sch = ['type' => 'OBJECT', 'properties' => ['ideas' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'text' => ['type' => 'STRING'], 'needs' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['title', 'text', 'needs']]]], 'required' => ['ideas']];
            $sys = "You are the growth engine of a D2C perfume shop's WhatsApp system (India). Using ONLY the facts below, give the 3 most valuable next actions to get more orders at lower cost. Each: title (4–7 words), text (2 short sentences: what to do and why it should work; no invented numbers), needs = the keys of any system from the SYSTEMS list that the action depends on and that is not 'built' (otherwise empty). Prefer free 24-hour-window replies and Utility messages over Marketing. Be honest when a thing cannot be done yet.";
            ignore_user_abort(true); try { [$ok, $v, $why] = convo_ai($sys, [['text' => "WHAT THE SYSTEM SEES NOW:\n" . $facts . "\nCUSTOMER TAGS:\n" . $tg . "\nSYSTEMS:\n" . $sl . (trim((string)($in['question'] ?? '')) !== '' ? "\nTHE OWNER ASKS: " . mb_substr((string)$in['question'], 0, 400) : '')]], $sch, false, 2000, 'claude'); } catch (Throwable $e) { json_response(['error' => 'The AI could not think this through.'], 502); }
            if (!$ok || !is_array($v)) json_response(['error' => (string)($why ?: 'The AI could not think this through.')], 502);
            $ideas = []; foreach (array_slice((array)($v['ideas'] ?? []), 0, 4) as $x) { if (!is_array($x)) continue; $ideas[] = ['title' => mb_substr((string)($x['title'] ?? ''), 0, 80), 'text' => mb_substr((string)($x['text'] ?? ''), 0, 400), 'needs' => array_values(array_map(fn($k) => ['k' => (string)$k, 'label' => $sysm[$k]['label'] ?? (string)$k, 'status' => $sysm[$k]['status'] ?? 'missing', 'note' => $sysm[$k]['note'] ?? ''], array_filter((array)($x['needs'] ?? []), fn($k) => isset($sysm[$k]) && $sysm[$k]['status'] !== 'built')))]; }
            json_response(['ok' => true, 'ideas' => $ideas]);
        }
        case 'wa.g_gallery_clean': { json_response(wag_gallery_clean($pdo)); }
        case 'wa.g_gallery_folder': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $f = (string)($in['folder'] ?? ''); if ($f !== '' && !isset(wag_folders()[$f])) json_response(['error' => 'Unknown folder.'], 422);
            $pdo->prepare("UPDATE wa_media_lib SET folder = ? WHERE id = ?")->execute([$f, (int)($in['id'] ?? 0)]); json_response(['ok' => true]);
        }
        case 'wa.g_ads': {   // which ads bring chats, and how many of those customers ordered afterwards (matched by phone, from the Shopify order cache)
            $days = max(1, min(180, (int)($_GET['days'] ?? 30))); $since = date('Y-m-d H:i:s', time() - $days * 86400); $orders = wa_sales_orders($pdo, $since);
            $st = $pdo->prepare("SELECT phone, first_seen, ad_id, ad_head, ref_text FROM wa_contacts WHERE source = 'query' AND first_seen >= ?"); $st->execute([$since]); $ads = [];
            foreach ($st->fetchAll() as $r) {
                $k = $r['ad_id'] !== '' ? $r['ad_id'] : mb_substr((string)$r['ref_text'], 0, 60); if ($k === '') $k = 'unknown'; $h = $r['ad_head'] !== '' ? $r['ad_head'] : $r['ref_text'];
                if (!isset($ads[$k])) $ads[$k] = ['key' => $k, 'title' => mb_substr((string)$h, 0, 90), 'chats' => 0, 'orders' => 0, 'revenue' => 0];
                $ads[$k]['chats']++; foreach ($orders[(string)$r['phone']] ?? [] as [$oat, $tot]) if ($oat >= $r['first_seen']) { $ads[$k]['orders']++; $ads[$k]['revenue'] += (int)round($tot); break; }
            }
            $rows = array_values($ads); usort($rows, fn($a, $b) => $b['chats'] <=> $a['chats']); foreach ($rows as &$x) $x['rate'] = $x['chats'] ? round(100 * $x['orders'] / $x['chats'], 1) : 0; unset($x);
            json_response(['ads' => $rows, 'days' => $days, 'purchase_event' => 'not_built']);
        }
        case 'wa.g_export': {   // chats or messages as CSV; every download is written to the log
            $kind = ($_GET['kind'] ?? 'chats') === 'messages' ? 'messages' : 'chats'; $from = trim((string)($_GET['from'] ?? '')); $to = trim((string)($_GET['to'] ?? '')); $tag = trim((string)($_GET['tag'] ?? '')); $numId = (int)($_GET['number'] ?? 0); $emp = (int)($_GET['emp'] ?? 0); $res = trim((string)($_GET['result'] ?? ''));
            $nums = wag_nums($pdo); $names = employee_names_by_id($pdo); $oc = wag_outcome_map($pdo); $defs = wag_outcomes();
            $f2 = $from !== '' && strtotime($from) ? date('Y-m-d 00:00:00', strtotime($from)) : '1970-01-01 00:00:00'; $t2 = $to !== '' && strtotime($to) ? date('Y-m-d 23:59:59', strtotime($to)) : '2999-01-01 00:00:00'; $filt = json_encode(['from' => $from, 'to' => $to, 'tag' => $tag, 'number' => $numId, 'emp' => $emp, 'result' => $res]);
            @set_time_limit(300); ignore_user_abort(true);
            // the customers that match — streamed from the database, never all in memory
            $match = function () use ($pdo, $emp, $numId, $res, $tag, $oc, $f2, $t2) {
                foreach (wa_aud_each($pdo, 'contacts', $tag !== '' ? ['gtag' => $tag] : []) as $r) {
                    $k = $r['phone']; if ($emp > 0 && (int)$r['assigned_emp'] !== $emp) continue; if ($numId > 0 && (int)$r['last_number_id'] !== $numId) continue;
                    $la = (string)($r['last_in_at'] ?: $r['last_out_at'] ?: $r['first_seen']); if ($la < $f2 || $la > $t2) continue;
                    yield $r;
                }
            };
            if ($kind === 'chats') {
                $gen = (function () use ($match, $names, $nums, $oc, $defs, $res) { $buf = []; $flush = function () use (&$buf, $oc, $res, $names, $nums, $defs) { $oc->prefetch(array_column($buf, 'phone')); wag_ctag_map($GLOBALS['pdo'])->prefetch(array_column($buf, 'phone')); foreach ($buf as $r) { $k = $r['phone']; $code = $oc[$k]['code'] ?? ''; if ($res !== '' && $code !== $res) continue;
                        yield [$k, $r['name'], $names[(int)$r['assigned_emp']] ?? '', $r['status'], implode(' | ', wag_row_tags($r)), $r['orders'], $r['spent'], $r['first_seen'], $r['last_in_at'], $r['last_out_at'], $nums[(int)$r['last_number_id']] ?? '', $r['source'], $code !== '' ? ($defs[$code][0] ?? $code) : '']; } $buf = []; };
                    foreach ($match() as $r) { $buf[] = $r; if (count($buf) >= 500) yield from $flush(); } yield from $flush(); })();
                $n = wag_csv_out('whatsapp_chats_' . date('Ymd') . '.csv', ['phone', 'name', 'assigned_to', 'status', 'tags', 'orders', 'order_total', 'first_seen', 'last_customer_message', 'last_our_message', 'last_number', 'source', 'result'], $gen);
            } else {
                $gen = (function () use ($pdo, $match, $f2, $t2, $nums, $names, $oc, $res) { $buf = [];
                    $flush = function () use (&$buf, $pdo, $f2, $t2, $nums, $names, $oc, $res) { if (!$buf) return; $oc->prefetch(array_keys($buf)); $ks = array_keys($buf); if ($res !== '') $ks = array_values(array_filter($ks, fn($k) => ($oc[$k]['code'] ?? '') === $res)); if (!$ks) { $buf = []; return; }
                        $q = $pdo->prepare("SELECT ts, phone, number_id, dir, who, mtype, body, emp_id FROM wa_messages WHERE phone IN (" . wa_in($ks) . ") AND ts >= ? AND ts <= ? AND dir != 'sys' ORDER BY phone, id"); $q->execute(array_merge($ks, [$f2, $t2]));
                        $rows = $q->fetchAll(); $nm = $buf; $buf = []; foreach ($rows as $m) yield [$m['ts'], $m['phone'], $nm[$m['phone']] ?? '', $nums[(int)$m['number_id']] ?? '', $m['dir'] === 'in' ? 'customer' : 'us', $m['who'], $m['mtype'], $m['body'], $names[(int)$m['emp_id']] ?? '']; };
                    foreach ($match() as $r) { $buf[$r['phone']] = $r['name']; if (count($buf) >= 300) yield from $flush(); } yield from $flush(); })();
                $n = wag_csv_out('whatsapp_messages_' . date('Ymd') . '.csv', ['time', 'phone', 'name', 'number', 'from', 'sent_by', 'type', 'text', 'person'], $gen);
            }
            try { $pdo->prepare("INSERT INTO wa_exports (emp_id, kind, filt, rows_n, at) VALUES (?, ?, ?, ?, ?)")->execute([$me, $kind, mb_substr($filt, 0, 300), $n, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
            wag_log($pdo, 'export', true, (employee_names_by_id($pdo)[$me] ?? 'Admin') . ' downloaded ' . $n . ' ' . $kind . ' as CSV'); exit;
        }
        case 'wa.g_exports': { $q = $pdo->query("SELECT emp_id, kind, filt, rows_n, at FROM wa_exports ORDER BY id DESC LIMIT 20"); $nm = employee_names_by_id($pdo); json_response(['log' => array_map(fn($r) => ['by' => $nm[(int)$r['emp_id']] ?? 'Admin', 'kind' => $r['kind'], 'filter' => $r['filt'], 'rows' => (int)$r['rows_n'], 'at' => $r['at']], $q->fetchAll())]); }
        case 'wa.g_golive': { json_response(wag_golive($pdo, $cfg)); }
        case 'wa.g_backup_mark': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); setting_set($pdo, 'wa_backup_at', date('Y-m-d H:i:s')); wag_log($pdo, 'golive', true, 'Backup confirmed by Admin.'); json_response(['ok' => true]);
        }
        case 'wa.g_push_test': {   // a test buzz to one person's phone(s)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $e = (int)($in['emp_id'] ?? 0); if ($e <= 0) json_response(['error' => 'Choose a person.'], 422);
            if (!function_exists('push_send_employee')) json_response(['error' => 'Alerts are not available on this server.'], 500);
            [$sent, $failed, $err] = push_send_employee($pdo, $e, ['title' => 'Test buzz 🔔', 'body' => 'If you can see this, query alerts will reach you.', 'url' => 'my_chats.html', 'tag' => 'wa-test']);
            json_response(['ok' => $sent > 0, 'sent' => (int)$sent, 'failed' => (int)$failed, 'note' => $sent > 0 ? 'Buzz sent to ' . $sent . ' device(s).' : ($failed > 0 ? 'The phone did not accept it: ' . mb_substr((string)$err, 0, 120) : 'This person has no phone with alerts on yet.')]);
        }
        case 'wa.g_hq_get': {   // who gets the floating Claude button — nobody until you say so
            $ids = array_values(array_filter(array_map('intval', (array)json_decode((string)setting_get($pdo, 'hq_btn_emps', '[]'), true)))); $nm = employee_names_by_id($pdo); $all = [];
            foreach ($nm as $i => $n) $all[] = ['emp_id' => (int)$i, 'name' => (string)$n, 'on' => in_array((int)$i, $ids, true)]; usort($all, fn($a, $b) => strcmp($a['name'], $b['name'])); json_response(['people' => $all]);
        }
        case 'wa.g_hq_set': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['emp_ids'] ?? [])), fn($x) => $x > 0)));
            setting_set($pdo, 'hq_btn_emps', json_encode($ids)); json_response(['ok' => true, 'count' => count($ids)]);
        }
    }
    json_response(['error' => 'Unknown WhatsApp action.'], 404);
}
