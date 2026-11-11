<?php
/**
 * wa_more.php — add-on for the WhatsApp console. Loaded by wa.php (no change to api.php). Loads wa_media.php and wa_camp.php too.
 *
 *   wa.numbers            GET   every number: health, who handles it, today's sending room, messages this week                       [Admin / HR]
 *   wa.num_profile        GET   {id}  the business profile at Meta: about, description, address, email, websites, photo               [Admin / HR]
 *   wa.num_profile_save   POST  {id, about, description, address, email, websites[], vertical}                                      [Admin / HR]
 *   wa.num_photo          POST  multipart {id, file}  the profile photo (JPG / PNG)                                                 [Admin / HR]
 *   wa.num_set            POST  {id, label, camp_ok, handlers[]}  name, "use for campaigns", the people who handle this number      [Admin / HR]
 *   wa.team               GET   routing rules, people with their reply speed, the engine (cron) command                             [Admin / HR]
 *   wa.team_save          POST  the routing rules                                                                                   [Admin / HR]
 *   wa.callnow            GET   "call this customer now" alerts for the logged-in salesperson (also keeps the routing engine running)
 *   wa.callnow_done       POST  {phone}
 *   wa.suggest            POST  {phone, avoid}  a private reply suggestion for ONE chat — shown only to staff, never sent by itself
 *   wa.contacts           GET   one list of every customer, all numbers together, with order tags                                   [Admin / HR]
 *   wa.contact_import     POST  {text, consent}  add customers from a pasted list                                                   [Admin / HR]
 *   wa.cron               GET   ?key=…  the engine: assignment, call-now alerts, follow-ups, campaign sending (Hostinger cron, every minute)
 *
 * Nothing here reads from or writes to AiSensy or to any older HRMS panel.
 */
if (!function_exists('wa_more_tables')) {

function wa_more_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        foreach (['camp_ok' => 'TINYINT NOT NULL DEFAULT 1', 'handlers' => "VARCHAR(300) NOT NULL DEFAULT ''", 'camp_tag' => 'TINYINT NOT NULL DEFAULT 0'] as $col => $def) {   // camp_tag: 📢 every chat on this number is a Campaign chat
            try { $pdo->query("SELECT $col FROM wa_numbers LIMIT 0"); } catch (PDOException $e) { $pdo->exec("ALTER TABLE wa_numbers ADD COLUMN $col $def"); }
        }
        foreach (['callnow' => 'TINYINT NOT NULL DEFAULT 0', 'callnow_at' => 'DATETIME NULL', 'hot' => 'TINYINT NOT NULL DEFAULT 0', 'hot_at' => 'DATETIME NULL', 'hot_why' => "VARCHAR(40) NOT NULL DEFAULT ''", 'remind_at' => 'DATETIME NULL', 'pay_at' => 'DATETIME NULL', 'accepted_at' => 'DATETIME NULL', 'reassigns' => 'TINYINT NOT NULL DEFAULT 0'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_contacts LIMIT 0"); } catch (PDOException $e) { $pdo->exec("ALTER TABLE wa_contacts ADD COLUMN $col $def"); }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_media_lib (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(80) NOT NULL, kind VARCHAR(10) NOT NULL, mime VARCHAR(60) NOT NULL DEFAULT '', filename VARCHAR(120) NOT NULL DEFAULT '',
            size INT NOT NULL DEFAULT 0, path VARCHAR(120) NOT NULL DEFAULT '', mids TEXT NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_fu_rules (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL DEFAULT '', after_min INT NOT NULL DEFAULT 120, tpl_id INT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 0, tag VARCHAR(20) NOT NULL DEFAULT '', created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_fu_log (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, rule_id INT NOT NULL, in_at DATETIME NULL, sent_at DATETIME NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'sent', KEY k_pr (phone, rule_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_campaigns (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, tpl_id INT NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'draft', audience TEXT NULL, vars TEXT NULL,
            rate INT NOT NULL DEFAULT 60, win_end DATETIME NULL, start_at DATETIME NULL, freq_days INT NOT NULL DEFAULT 3, switch_after INT NOT NULL DEFAULT 2, created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL, started_at DATETIME NULL, finished_at DATETIME NULL, last_tick DATETIME NULL, note VARCHAR(200) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_camp_items (id BIGINT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, phone VARCHAR(12) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(8) NOT NULL DEFAULT 'queued', number_id INT NOT NULL DEFAULT 0, wamid VARCHAR(120) NOT NULL DEFAULT '', err VARCHAR(200) NOT NULL DEFAULT '', sent_at DATETIME NULL, KEY k_camp (camp_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // columns added by the new campaign engine (own send time, retries, exact wording sent) — each added once, only when missing
        foreach (['media_lib_id' => 'INT NOT NULL DEFAULT 0', 'media_folder' => "VARCHAR(40) NOT NULL DEFAULT ''", 'push' => "VARCHAR(10) NOT NULL DEFAULT 'normal'", 'retry' => 'TINYINT NOT NULL DEFAULT 1',
                  'own_hour' => 'TINYINT NOT NULL DEFAULT 0', 'def_hour' => 'TINYINT NOT NULL DEFAULT 19', 'extra' => 'TEXT NULL'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_campaigns LIMIT 0"); } catch (PDOException $e) { try { $pdo->exec("ALTER TABLE wa_campaigns ADD COLUMN $col $def"); } catch (PDOException $e2) { } }
        }
        foreach (['send_at' => 'DATETIME NULL', 'tries' => 'TINYINT NOT NULL DEFAULT 1', 'next_try' => 'DATETIME NULL', 'err_code' => 'INT NOT NULL DEFAULT 0', 'last_status' => "VARCHAR(12) NOT NULL DEFAULT ''", 'vars_json' => 'TEXT NULL',
                  'variant' => "VARCHAR(1) NOT NULL DEFAULT 'M'", 'tpl_used' => 'INT NOT NULL DEFAULT 0'] as $col => $def) {   // variant: M = the campaign's marketing message, U = its utility twin (tpl_used = the message really sent, 0 = the campaign's own)
            try { $pdo->query("SELECT $col FROM wa_camp_items LIMIT 0"); }
            catch (PDOException $e) {
                try { $pdo->exec("ALTER TABLE wa_camp_items ADD COLUMN $col $def"); } catch (PDOException $e2) { }
                try { if ($col === 'last_status') $pdo->exec("ALTER TABLE wa_camp_items ADD INDEX k_ls (last_status, status)"); if ($col === 'send_at') $pdo->exec("ALTER TABLE wa_camp_items ADD INDEX k_due (camp_id, status, send_at)"); } catch (PDOException $e3) { }
            }
        }
        try { $q = $pdo->query("SHOW INDEX FROM wa_camp_items WHERE Key_name = 'k_wamid'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_camp_items ADD INDEX k_wamid (wamid)"); } catch (PDOException $e) { }
        try { $q = $pdo->query("SHOW INDEX FROM wa_camp_items WHERE Key_name = 'k_var'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_camp_items ADD INDEX k_var (variant, number_id, sent_at)"); } catch (PDOException $e) { }   // the utility sends of a number (share judging, "what Claude learned")
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}

function wa_lock(PDO $pdo, string $name, int $sec): bool
{
    $k = 'wa_lock_' . $name; $t = (int)setting_get($pdo, $k, '0'); if ($t > time() - $sec) return false;
    setting_set($pdo, $k, (string)time()); return true;
}

// ---------------------------------------------------------------------------------------------------------------- rules (Team & routing)
function wa_team_defaults(): array
{
    return ['auto_assign' => true, 'max_open' => 8, 'after_msgs' => 4, 'after_min' => 3, 'callnow' => true, 'escalate_min' => 2, 'alert_emps' => [], 'community_kw' => '',
            'ramp' => true, 'rate_marketing' => 1.09, 'rate_utility' => 0.15];
}
function wa_team_cfg(PDO $pdo): array
{
    $d = wa_team_defaults();
    try { $j = json_decode((string)setting_get($pdo, 'wa_team', ''), true); } catch (Throwable $e) { $j = null; }
    if (is_array($j)) foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = $j[$k];
    $d['max_open'] = max(1, min(50, (int)$d['max_open'])); $d['after_msgs'] = max(1, min(20, (int)$d['after_msgs'])); $d['after_min'] = max(1, min(60, (int)$d['after_min'])); $d['escalate_min'] = max(1, min(30, (int)$d['escalate_min']));
    $d['alert_emps'] = array_values(array_unique(array_filter(array_map('intval', (array)$d['alert_emps']), fn($x) => $x > 0)));
    return $d;
}

/** First-reply speed per salesperson over the last N days: the minutes between a customer's message and the first answer. Kept 10 minutes for the usual 30 days. */
function wa_person_stats(PDO $pdo, int $days = 30): array
{
    if ($days === 30) { try { $c = json_decode((string)setting_get($pdo, 'wa_pstats', ''), true); } catch (Throwable $e) { $c = null; }
        if (is_array($c) && (int)($c['at'] ?? 0) > time() - 600) return $c['d'];
        try { if (is_array($c) && isset($c['d']) && (int)setting_get($pdo, 'wa_pstats_lock', '0') > time() - 120) return $c['d']; setting_set($pdo, 'wa_pstats_lock', (string)time()); } catch (Throwable $e) { } }   // one 30-day recount at a time (ten copies at once were freezing the database): the others use the last copy
    $st = $pdo->prepare("SELECT phone, dir, emp_id, who, ts FROM wa_messages WHERE dir IN ('in','out') AND ts >= ? ORDER BY phone, id LIMIT 400000"); $st->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
    $pend = []; $res = []; $sent = [];
    foreach ($st->fetchAll() as $m) {
        $p = $m['phone'];
        if ($m['dir'] === 'in') { if (!isset($pend[$p])) $pend[$p] = strtotime((string)$m['ts']); continue; }
        $e = (int)$m['emp_id']; if ($e > 0) $sent[$e] = ($sent[$e] ?? 0) + 1;
        if ($e > 0 && isset($pend[$p])) { $res[$e][] = max(0, (strtotime((string)$m['ts']) - $pend[$p]) / 60); }
        if ($m['who'] === 'emp' || $m['who'] === 'campaign' || $m['who'] === 'followup') unset($pend[$p]);   // someone answered: the next customer message starts a new wait
    }
    $out = [];
    foreach ($sent as $e => $n) {
        $a = $res[$e] ?? []; sort($a); $c = count($a);
        $out[$e] = ['replies' => $n, 'answered' => $c, 'median' => $c ? round($a[(int)floor(($c - 1) / 2)], 1) : null, 'avg' => $c ? round(array_sum(array_map(fn($x) => min($x, 1440), $a)) / $c, 1) : null, 'fast' => $c ? (int)round(100 * count(array_filter($a, fn($x) => $x <= 5)) / $c) : null];
    }
    if ($days === 30) { try { setting_set($pdo, 'wa_pstats', json_encode(['at' => time(), 'd' => $out])); } catch (Throwable $e) { } }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------- routing engine
/** A chat nobody has taken: after N customer messages or M minutes it goes to the best present salesperson (the number's own handlers first). */
function wa_auto_assign(PDO $pdo): int
{
    if (wa_manual_assign($pdo)) return 0;
    $cfg = wa_team_cfg($pdo); if (!$cfg['auto_assign']) return 0;
    $st = $pdo->prepare("SELECT phone, last_number_id, last_out_at, hot FROM wa_contacts WHERE assigned_emp = 0 AND (cx_id IS NULL OR cx_id = 0) AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 40");
    $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]); $n = 0;
    $cnt = $pdo->prepare("SELECT COUNT(*) n, MIN(ts) t FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ?");
    foreach ($st->fetchAll() as $r) {
        $cnt->execute([$r['phone'], $r['last_out_at'] ?: '1970-01-01 00:00:00']); $x = $cnt->fetch();
        $due = (int)$r['hot'] === 1 || (int)$x['n'] >= $cfg['after_msgs'] || ($x['t'] && time() - strtotime((string)$x['t']) >= $cfg['after_min'] * 60);
        if (!$due) continue;
        $pick = null; foreach (wa_people($pdo, (int)$r['last_number_id']) as $p) if (!empty($p['best'])) { $pick = $p; break; }
        if (!$pick) continue;   // nobody is present and free — the chat stays in the unassigned list and the Overview shows it
        wa_assign_to($pdo, (string)$r['phone'], (int)$pick['emp_id'], 0, 'auto'); $n++;
    }
    return $n;
}
/** The "call this customer now" alert opens when a chat is given to someone. */
function wa_callnow_open(PDO $pdo, string $key, int $emp, int $by): void
{
    try {
        $on = $emp > 0 && $emp !== $by && wa_team_cfg($pdo)['callnow'];
        $pdo->prepare("UPDATE wa_contacts SET callnow = ?, callnow_at = ? WHERE phone = ?")->execute([$on ? 1 : 0, $on ? date('Y-m-d H:i:s') : null, $key]);
    } catch (Throwable $e) { }
}
/** Closes alerts that were answered; pushes to the chosen people when nobody has reacted within the set minutes. */
function wa_callnow_scan(PDO $pdo): void
{
    $cfg = wa_team_cfg($pdo); $names = employee_names_by_id($pdo);
    $rows = $pdo->query("SELECT phone, name, assigned_emp, callnow, callnow_at, reassigns, cx_id, tag FROM wa_contacts WHERE callnow IN (1, 2)")->fetchAll();
    $out = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND ts >= ?");
    foreach ($rows as $r) {
        $out->execute([$r['phone'], $r['callnow_at'] ?: '1970-01-01 00:00:00']);
        if ((int)$out->fetchColumn() > 0 || (int)$r['assigned_emp'] <= 0) { $pdo->prepare("UPDATE wa_contacts SET callnow = 9 WHERE phone = ?")->execute([$r['phone']]); continue; }
        if ((int)$r['callnow'] === 1 && $r['callnow_at'] && time() - strtotime((string)$r['callnow_at']) >= $cfg['escalate_min'] * 60) {
            $isCx = (int)($r['cx_id'] ?? 0) > 0 || (string)($r['tag'] ?? '') === 'complaint';   // a complaint chat stays with its person — it is never moved by itself (the alert still goes up)
            if (!$isCx && !wa_manual_assign($pdo) && (string)setting_get($pdo, 'wa_reassign', '0') === '1' && (int)$r['reassigns'] < 2 && function_exists('wa_next_person')) {   // not accepted in time → the next present person gets it
                $nx = wa_next_person($pdo, (int)$r['assigned_emp']);
                if ($nx) {
                    $from = $names[(int)$r['assigned_emp']] ?? 'the salesperson'; wa_assign_to($pdo, (string)$r['phone'], (int)$nx['emp_id'], 0, 'auto');
                    $pdo->prepare("UPDATE wa_contacts SET reassigns = ? WHERE phone = ?")->execute([(int)$r['reassigns'] + 1, $r['phone']]);
                    wa_event($pdo, (string)$r['phone'], $from . ' did not accept in ' . $cfg['escalate_min'] . ' min — given to ' . $nx['name']); continue;
                }
            }
            $pdo->prepare("UPDATE wa_contacts SET callnow = 2 WHERE phone = ?")->execute([$r['phone']]);
            if (function_exists('ct_push')) {
                $who = $names[(int)$r['assigned_emp']] ?? 'the salesperson'; $cu = ($r['name'] !== '' ? $r['name'] : '+91 ' . $r['phone']);
                try {
                    if ($cfg['alert_emps']) ct_push($pdo, $cfg['alert_emps'], 'WhatsApp: nobody has reacted', $cu . ' was given to ' . $who . ' ' . $cfg['escalate_min'] . ' min ago and is still waiting.', '/whatsapp.html#chats', 'wa-esc-' . $r['phone']);
                    ct_push($pdo, [(int)$r['assigned_emp']], 'Call this customer NOW', $cu . ' is waiting for you.', '/my_chats.html?phone=' . $r['phone'], 'wa-call-' . $r['phone']);
                } catch (Throwable $e) { }
            }
            wa_event($pdo, (string)$r['phone'], 'No reaction for ' . $cfg['escalate_min'] . ' min — the admin was alerted');
        }
    }
}
/** 🤖 Orders → the salesperson who holds the customer's WhatsApp chat, by themselves (every few minutes, from wa_tick).
 *  Only orders nobody allocated yet; the customer wrote to us in the 7 days before the order; the chat is with a salesperson.
 *  Type: chat source query / '' (direct) → Query · campaign / community → Campaign. Panel: TM Perfume House. The rule itself is order_auto_alloc() in api.php (also run the moment an order arrives and when the Allocation page loads).
 *  ₹0 orders (replacements) are skipped. The order code stays manual. Returns how many were allocated. */
function wa_auto_alloc(PDO $pdo): int
{
    if ((int)setting_get($pdo, 'wa_alloc_at', '0') > time() - 240) return 0; setting_set($pdo, 'wa_alloc_at', (string)time());
    if (!function_exists('order_auto_alloc') || !function_exists('ensure_order_allocations_table')) return 0;
    try { ensure_order_allocations_table($pdo); if (function_exists('ensure_shop_orders_table')) ensure_shop_orders_table($pdo); } catch (Throwable $e) { return 0; }
    $since = date('Y-m-d H:i:s', time() - 2 * 86400);   // the last 2 days
    try { $st = $pdo->prepare("SELECT o.shopify_id AS id, o.store_key, o.order_name, o.created_at, o.phone, o.total, o.tags FROM shop_orders o LEFT JOIN order_allocations a ON a.source = o.store_key AND a.order_id = CAST(o.shopify_id AS CHAR)
            WHERE o.created_at >= ? AND a.order_id IS NULL ORDER BY o.created_at LIMIT 300"); $st->execute([$since]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return 0; }
    setting_set($pdo, 'wa_alloc_seen', (string)count($rows));
    $n = 0; foreach ($rows as $o) { try { if (is_array(order_auto_alloc($pdo, (string)$o['store_key'], $o))) $n++; } catch (Throwable $e) { } }   // the same rule as a new order (api.php)
    if ((string)setting_get($pdo, 'camp2_off_v2', '') !== '1') {   // 📢 owner (6 Nov, evening): the second number is NOT "everything = Campaign" — only a customer we really sent a campaign to is Campaign; website / ad texts are New queries
        setting_set($pdo, 'camp2_off_v2', '1');
        try { wa_camp_unmark($pdo); } catch (Throwable $e) { error_log('camp2 off: ' . $e->getMessage()); }
    }
    try { wa_camp_requery($pdo, 7); } catch (Throwable $e) { }
    return $n;
}
/** 📢 Did we really send this customer a campaign (our campaign template, or a sent campaign item) in the $days days before $before? */
function wa_got_campaign(PDO $pdo, string $key, string $before, int $days = 7): bool
{
    $from = date('Y-m-d H:i:s', strtotime($before) - $days * 86400);
    try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND ts BETWEEN ? AND ? LIMIT 1"); $q->execute([$key, $from, $before]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT 1 FROM wa_camp_items WHERE phone = ? AND status = 'sent' AND sent_at BETWEEN ? AND ? LIMIT 1"); $q->execute([$key, $from, $before]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    return false;
}
/** Undo "every chat on the campaign number = Campaign" (6 Nov): the switch goes off; a chat it marked Campaign WITHOUT a real campaign sent to it goes back to a New query,
 *  and the robot's Campaign allocations of those chats (last 30 days) go back to Query. Hand-made allocations are never touched. */
function wa_camp_unmark(PDO $pdo): array
{
    $ids = array_map('intval', $pdo->query("SELECT id FROM wa_numbers WHERE camp_tag = 1")->fetchAll(PDO::FETCH_COLUMN)); $back = 0; $alloc = 0;
    if (!$ids) return ['contacts' => 0, 'orders' => 0];
    $pdo->exec("UPDATE wa_numbers SET camp_tag = 0 WHERE id IN (" . implode(',', $ids) . ")");
    $st = $pdo->query("SELECT phone, COALESCE(last_in_at, NOW()) li FROM wa_contacts WHERE source = 'campaign' AND last_number_id IN (" . implode(',', $ids) . ")");
    $up = $pdo->prepare("UPDATE wa_contacts SET source = 'query' WHERE phone = ? AND source = 'campaign'");
    foreach ($st->fetchAll() as $r) { $k = (string)$r['phone']; if (wa_got_campaign($pdo, $k, (string)$r['li'], 30)) continue;
        $up->execute([$k]); if ($up->rowCount() > 0) { $back++; try { $pdo->prepare("DELETE FROM wa_ctags WHERE phone = ? AND tag = 'campaign' AND src = 'auto'")->execute([$k]); $pdo->prepare("DELETE FROM wa_tagidx WHERE phone = ? AND tag = 'campaign'")->execute([$k]); } catch (Throwable $e) { } } }
    try { $st = $pdo->prepare("SELECT a.alloc_id, o.phone, o.created_at FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND CAST(o.shopify_id AS CHAR) = a.order_id WHERE a.allocated_by = 'auto (WhatsApp)' AND a.order_type = 'campaign' AND o.created_at >= ?"); $st->execute([date('Y-m-d H:i:s', time() - 30 * 86400)]);
        $src = $pdo->prepare("SELECT source FROM wa_contacts WHERE phone = ?"); $fix = $pdo->prepare("UPDATE order_allocations SET order_type = 'query' WHERE alloc_id = ? AND allocated_by = 'auto (WhatsApp)' AND order_type = 'campaign'");
        foreach ($st->fetchAll() as $r) { $d = preg_replace('/\D+/', '', (string)$r['phone']); $k = strlen($d) > 10 ? substr($d, -10) : $d; if (strlen($k) !== 10) continue;
            $src->execute([$k]); if (in_array((string)$src->fetchColumn(), ['campaign', 'community'], true)) continue; if (wa_got_campaign($pdo, $k, (string)$r['created_at'], 7)) continue;
            $fix->execute([(int)$r['alloc_id']]); $alloc += $fix->rowCount(); } } catch (Throwable $e) { }
    return ['contacts' => $back, 'orders' => $alloc];
}
/** 📢 Orders the robot typed "Query" for a customer whose chat is Campaign / Community (a real campaign reply) become "Campaign".
 *  Only the robot's own allocations of the last $days days — an allocation made by a person is never changed. Returns how many were changed. */
function wa_camp_requery(PDO $pdo, int $days = 7): int
{
    $ids = []; try { $ids = array_map('intval', $pdo->query("SELECT id FROM wa_numbers WHERE camp_tag = 1")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
    try { $st = $pdo->prepare("SELECT a.alloc_id, a.source, a.order_id, o.phone, o.order_name, o.created_at FROM order_allocations a JOIN shop_orders o ON o.store_key = a.source AND CAST(o.shopify_id AS CHAR) = a.order_id
            WHERE a.allocated_by = 'auto (WhatsApp)' AND a.order_type = 'query' AND o.created_at >= ? LIMIT 500"); $st->execute([date('Y-m-d H:i:s', time() - $days * 86400)]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return 0; }
    if (!$rows) return 0; $since = date('Y-m-d H:i:s', time() - 60 * 86400); $n = 0;
    $src = $pdo->prepare("SELECT source FROM wa_contacts WHERE phone = ?");
    $cn = $ids ? $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'in' AND number_id IN (" . implode(',', $ids) . ") AND ts >= ? LIMIT 1") : null;
    $up = $pdo->prepare("UPDATE order_allocations SET order_type = 'campaign' WHERE alloc_id = ? AND order_type = 'query' AND allocated_by = 'auto (WhatsApp)'");
    foreach ($rows as $r) { $d = preg_replace('/\D+/', '', (string)$r['phone']); $k = strlen($d) > 10 ? substr($d, -10) : $d; if (strlen($k) !== 10) continue;
        $src->execute([$k]); $sv = (string)$src->fetchColumn(); $camp = in_array($sv, ['campaign', 'community'], true);
        if (!$camp && function_exists('order_chat_type')) { try { $camp = order_chat_type($pdo, ['phone' => $k, 'source' => $sv], (int)strtotime((string)$r['created_at'])) === 'campaign'; } catch (Throwable $e) { } }   // repeat customer / campaign sent / latest query typed Campaign
        if (!$camp) continue; $up->execute([(int)$r['alloc_id']]);
        if ($up->rowCount() > 0) { $n++; if (function_exists('wa_event')) { try { wa_event($pdo, $k, '📢 Order ' . $r['order_name'] . ' counted as Campaign (the customer came on the campaign number)'); } catch (Throwable $e) { } } } }
    return $n;
}
function wa_tick(PDO $pdo, bool $force = false): array
{
    if (!$force && !wa_lock($pdo, 'tick', 8)) return ['skipped' => true];
    if ($force && !defined('WA_IN_WEBHOOK') && (int)date('G') >= 3 && (string)setting_get($pdo, 'pvb_day', '') !== date('Y-m-d') && function_exists('wcr_pvb_run')) { try { wcr_pvb_run($pdo); } catch (Throwable $e) { } }   // person vs bot: once a day, early morning
    $o = ['assigned' => 0];
    try { if (function_exists('wa_hot_scan')) $o['hot'] = wa_hot_scan($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'hot chats: ' . $e->getMessage()); }   // a customer who asks to pay is given to a person at once
    try { if (function_exists('wa_tpl_autosync') && !defined('WA_IN_WEBHOOK')) $o['tpl'] = wa_tpl_autosync($pdo, 1800); } catch (Throwable $e) { }   // waiting templates: ask Meta every 30 min (Meta's webhook may not tell us)
    try { if (function_exists('wa_late_scan')) $o['late'] = wa_late_scan($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'late replies: ' . $e->getMessage()); }   // ⏰ mark chats waiting 10+ min
    try { if (function_exists('wa_mcache_prefetch') && !defined('WA_IN_WEBHOOK')) $o['media'] = wa_mcache_prefetch($pdo); } catch (Throwable $e) { }   // ⚡ new customer photos / videos saved on our server before anyone opens them
    try { if (function_exists('wa_done_scan') && !defined('WA_IN_WEBHOOK')) { $o['done'] = wa_done_scan($pdo); if ((int)date('G') >= 3) wa_done_learn($pdo); } } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'order completed: ' . $e->getMessage()); }   // ✅ Shopify orders → order completed
    try { $o['assigned'] = wa_auto_assign($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'auto-assign: ' . $e->getMessage()); }
    try { if (!defined('WA_IN_WEBHOOK')) $o['alloc'] = wa_auto_alloc($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'auto-allocate: ' . $e->getMessage()); }   // 🤖 orders → the salesperson of the chat
    try { wa_callnow_scan($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'call-now: ' . $e->getMessage()); }
    try { if (function_exists('wq_urgent_push') && !defined('WA_IN_WEBHOOK')) $o['urgent'] = wq_urgent_push($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'urgent: ' . $e->getMessage()); }   // 🔴 nobody answered a ready-to-pay chat in time: everyone present is told at once
    try { if (function_exists('wa_proof_scan')) { $pr = wa_proof_scan($pdo); $o['proofs'] = $pr['made']; } } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'order proof: ' . $e->getMessage()); }
    try { if (function_exists('wag_tick')) $o['growth'] = wag_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'growth: ' . $e->getMessage()); }
    try { if (function_exists('wq_tick')) $o['wq'] = wq_tick($pdo, ['light' => true]); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'whatsapp sales: ' . $e->getMessage()); }   // no-answer flow + typing of new queries
    try { if (function_exists('wch_tick')) $o['wch'] = wch_tick($pdo, ['light' => true]); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'chain cart: ' . $e->getMessage()); }   // chain cart: reminders
    try { if (function_exists('wa_ord_tick')) $o['orders'] = wa_ord_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'orders: ' . $e->getMessage()); }   // order-update messages (and the one-time template setup)
    try { if (function_exists('jt_tick') && $force && !defined('WA_IN_WEBHOOK')) $o['tags'] = jt_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'tags: ' . $e->getMessage()); }
    try { if (function_exists('wa_numq_refresh') && $force && !defined('WA_IN_WEBHOOK')) { $nq = json_decode((string)setting_get($pdo, 'wa_numq_cache', ''), true); if (!is_array($nq) || (int)($nq['t'] ?? 0) < time() - 600) wa_numq_refresh($pdo); } } catch (Throwable $e) { }   // ⚡ the per-number chip counts are renewed here, not while the chat list loads
    try { if (function_exists('wd_tick') && $force && !defined('WA_IN_WEBHOOK')) $o['drip'] = wd_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'drip: ' . $e->getMessage()); }   // 🔁 7-Day Drip
    try { if (function_exists('wj_tick') && $force && !defined('WA_IN_WEBHOOK')) $o['journey'] = wj_tick($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'journey: ' . $e->getMessage()); }   // 🗓 60-day Journey: today's messages, the night's topic learning, festival watch
    try { if (function_exists('wtp_gen_tick') && $force && !defined('WA_IN_WEBHOOK')) { $f0 = ai_feat('wa_topics'); try { wtp_gen_tick($pdo); } finally { ai_feat($f0); } } } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'topics: ' . $e->getMessage()); }   // 🧭 Claude writes the topic messages one topic at a time and sends them to Meta
    try { if (function_exists('wlr_nightly') && $force && !defined('WA_IN_WEBHOOK')) { $f0 = ai_feat('wa_learn'); try { wlr_nightly($pdo); } finally { ai_feat($f0); } } } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'learning: ' . $e->getMessage()); }   // 🧠 after 02:00, once a day: lost chats saved, guidebook rewritten from won + lost chats
    setting_set($pdo, 'wa_tick_at', date('Y-m-d H:i:s'));
    return $o;
}

/** True for the ready-made text of our Meta ads: "Hi TMPH", "I want to buy …", "7 x 20 ml in 2170". */
function wa_is_ad_text(string $t): bool
{
    $t = mb_strtolower(trim($t)); if ($t === '' || mb_strlen($t) > 300) return false;
    return (bool)(preg_match('/more info(rmation)? (on|about) this|interested in this|saw (your|this) ad|\btmph\b|tm\s*perfume\s*house|\bwant to (buy|order)\b|placing my first order|free\s*10\s*ml|extra discount|\bi\s*want\b.*\bml\b/u', $t) || preg_match('/\d+\s*ml\b.*\b(in|at|for|@|rs\.?|₹)\s*\d{3,5}\b/u', $t));
}
/** Once: chats of the last 2 days whose first words were an ad's ready-made text, before this rule existed, are marked as ad queries too. */
function wa_ad_backfill(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    try { if ((string)setting_get($pdo, 'wa_ad_backfill', '') === '1') return; setting_set($pdo, 'wa_ad_backfill', '1');
        $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE (source IS NULL OR source = '') AND last_in_at >= ? LIMIT 3000"); $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]);
        $m = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ? ORDER BY id LIMIT 3"); $u = $pdo->prepare("UPDATE wa_contacts SET source = 'query' WHERE phone = ? AND (source IS NULL OR source = '')");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) { $m->execute([$ph, date('Y-m-d H:i:s', time() - 2 * 86400)]); foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $b) if (wa_is_ad_text((string)$b)) { $u->execute([$ph]); break; } }
    } catch (Throwable $e) { }
}
/** Where a new customer came from: a community-link keyword, or a reply to our broadcast. Called right after a customer message is stored. */
/** 📢 A "campaign number" (Numbers → the number → "Every chat on this number is Campaign"): every customer who writes to it is a Campaign customer. */
function wa_camp_number(PDO $pdo, int $numId): bool
{
    return false;   // owner (7 Nov): a number is NEVER "everything = Campaign". A customer who writes first (website, ad, offer text, anything) is a New query; only a real reply to a campaign we sent is Campaign.
    static $ids = null; if ($numId <= 0) return false;
    if ($ids === null) { $ids = []; try { foreach ($pdo->query("SELECT id FROM wa_numbers WHERE camp_tag = 1")->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = true; } catch (Throwable $e) { } }
    return isset($ids[$numId]);
}
/** Marks one chat as Campaign: source + the 'campaign' tag (the chips, the query type and the order allocation all read these). */
function wa_camp_mark(PDO $pdo, string $key, string $now): void
{
    $pdo->prepare("UPDATE wa_contacts SET source = 'campaign' WHERE phone = ? AND source <> 'campaign'")->execute([$key]);
    try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, 'campaign', 'auto', ?)")->execute([$key, $now]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, 'campaign')")->execute([$key]); } catch (Throwable $e) { }
}
/** The latest campaign template we sent this customer in the last 3 days: its time, or '' when none. */
function wa_camp_sent_ts(PDO $pdo, string $key): string
{
    try { $q = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND who = 'campaign' AND ts >= ?"); $q->execute([$key, date('Y-m-d H:i:s', time() - 3 * 86400)]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; }
}
/** True when the customer wrote in the 30 hours before that campaign (a query stays a New query for 30 hours): their query was still open, so the campaign does not make it a Campaign chat. */
function wa_camp_in_live_chat(PDO $pdo, string $key, string $campTs): bool
{
    if ($campTs === '') return false; $t = strtotime($campTs); if (!$t) return false;
    try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts BETWEEN ? AND ? LIMIT 1"); $q->execute([$key, date('Y-m-d H:i:s', $t - 30 * 3600), $campTs]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
function wa_source_hook(PDO $pdo, string $key, ?string $prevIn, string $text, string $now): void
{
    if (function_exists('wa_seg_touch')) { try { wa_seg_touch($pdo, $key, $prevIn, $now); } catch (Throwable $e) { } }
    if (function_exists('wa_util_offer_reply')) wa_util_offer_reply($pdo, $key);
    if (function_exists('wa_bday_hook')) { try { if (wa_bday_hook($pdo, $key, $text)) return; } catch (Throwable $e) { error_log('wa_bday: ' . $e->getMessage()); } }   // 🎂 the customer answered our birthday question
    if (function_exists('wa_review_hook')) { try { if (wa_review_hook($pdo, $key, $text)) return; } catch (Throwable $e) { error_log('wa_review: ' . $e->getMessage()); } }   // ⭐ the 1-5 answer to our rating request
    if (function_exists('wa_cxself_hook')) { try { wa_cxself_hook($pdo, $key, $text); } catch (Throwable $e) { error_log('wa_cxself: ' . $e->getMessage()); } }   // 🎫 "complaint" → issue list → description → ticket   // 🎁 a reply to a utility follow-up → the campaign's offer goes free in the open chat
    try {
        $c = wa_contact($pdo, $key); if (!$c) return;
        if ($prevIn === null || $prevIn === '') $pdo->prepare("UPDATE wa_contacts SET first_seen = ? WHERE phone = ?")->execute([$now, $key]);   // the first time THEY wrote to us
        if (wa_camp_number($pdo, (int)($c['last_number_id'] ?? 0))) { wa_camp_mark($pdo, $key, $now); return; }   // 📢 wrote to the campaign number → Campaign, before every other guess
        // 👥 the customer SAYS they come from our community ("community se order", "કમ્યુનિટી", "कम्युनिटी", typos) → Community, even when an ad / campaign guess was set before:
        //    their own words beat our guess. Also tags the chat 'community' so the query is typed Community (wq_decide).
        if ((string)$c['source'] !== 'community' && preg_match('/c[o0]m+[u]+n+[i1]t[iy]e?|comunity|kam+y?u+ni+t+i|कम्यु?निटी|कमुनिटी|કમ્યુ?નિટી|કોમ્યુનિટી/iu', $text)) {
            $pdo->prepare("UPDATE wa_contacts SET source = 'community' WHERE phone = ?")->execute([$key]);
            try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, 'community', 'auto', ?)")->execute([$key, $now]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, 'community')")->execute([$key]); } catch (Throwable $e) { }
            return;
        }
        // 📢 the words a Meta ad puts in the box ("I want to buy 7 × 20 ml in 2170 Hi TMPH") at the START of a new query (first message ever, or 24 h quiet) → a fresh ad query,
        //    even when this customer came from a campaign / an ad before; a campaign reply (our template in the last 3 days) stays Campaign
        $fresh = $prevIn === null || $prevIn === '' || strtotime((string)$prevIn) < time() - 86400;
        if ($fresh && (string)$c['source'] !== 'query' && wa_is_ad_text($text)) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND who = 'campaign' AND ts >= ?"); $q->execute([$key, date('Y-m-d H:i:s', time() - 3 * 86400)]);
            $sentCamp = (int)$q->fetchColumn() > 0;
            // a customer who tapped our promotion/ad link arrives with the ready-made text and is NOT swiping a reply to our template → a fresh query, never "Campaign",
            // even if we sent them a campaign in the last 3 days. Only a real reply (swipe-reply to a message of ours) stays Campaign.
            $isReply = false;
            if ($sentCamp) { $r = $pdo->prepare("SELECT reply_to FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $r->execute([$key]); $isReply = trim((string)$r->fetchColumn()) !== ''; }
            if (!$sentCamp || !$isReply) { $pdo->prepare("UPDATE wa_contacts SET source = 'query' WHERE phone = ?")->execute([$key]); return; }
        }
        if ((string)$c['source'] !== '') return;
        $kw = trim((string)wa_team_cfg($pdo)['community_kw']);
        if ($kw !== '' && stripos($text, $kw) !== false) { $pdo->prepare("UPDATE wa_contacts SET source = 'community' WHERE phone = ?")->execute([$key]); return; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND who = 'campaign' AND ts >= ?"); $q->execute([$key, date('Y-m-d H:i:s', time() - 3 * 86400)]);
        if ((int)$q->fetchColumn() > 0) {
            // a campaign we sent while their query was still inside its 24 hours does not turn it into a Campaign chat: it stays a Query
            $live = wa_camp_in_live_chat($pdo, $key, wa_camp_sent_ts($pdo, $key));
            $pdo->prepare("UPDATE wa_contacts SET source = ? WHERE phone = ?")->execute([$live ? 'query' : 'campaign', $key]);
        }
    } catch (Throwable $e) { }
}

// ---------------------------------------------------------------------------------------------------------------- customers & tags
/** Orders per phone (last 10 digits) from the Shopify order cache: count, last order day, total, name. */
function wa_order_map(PDO $pdo)   // lazy: $map[$phone10] → ['n','last','total','name'] (reads the customer summary, only the customers asked for)
{
    static $m = null; if ($m !== null) return $m; wa_cust_ready($pdo);
    return $m = new WaLazyMap(function (array $ks) use ($pdo) {
        $o = []; try { $st = $pdo->prepare("SELECT phone, n, last_order, spent, name FROM wa_cust WHERE n > 0 AND phone IN (" . wa_in($ks) . ")"); $st->execute($ks);
            foreach ($st->fetchAll() as $r) $o[(string)$r['phone']] = ['n' => (int)$r['n'], 'last' => (string)$r['last_order'], 'total' => (int)round((float)$r['spent']), 'name' => (string)$r['name']]; } catch (Throwable $e) { }
        return $o; });
}
function wa_valid_phone(string $k): bool { return (bool)preg_match('/^[6-9]\d{9}$/', $k); }
/** One phone cell from an Excel / CSV import -> the 10-digit Indian mobile, or '' when it is not one. Handles +91 / 91 / 0 / 0091 in front, spaces, dashes, dots,
 *  a trailing '.0' (Excel number), two numbers in one cell (first mobile wins). Never guesses: 9.19876E+11 (Excel cut the digits) and foreign numbers give ''. */
function wa_imp_phone($v): string
{
    $v = trim((string)$v); if ($v === '' || preg_match('/\d[.,]\d+e\+?\d+/i', $v)) return '';
    foreach (preg_split('/\s*[\/,;|&]\s*|\s+or\s+/i', $v) as $p) {
        $p = preg_replace('/\.0+$/', '', trim($p)) ?? ''; $d = preg_replace('/\D+/', '', $p) ?? '';
        if (strlen($d) === 14 && strpos($d, '0091') === 0) $d = substr($d, 4); elseif (strlen($d) === 13 && strpos($d, '091') === 0) $d = substr($d, 3);
        elseif (strlen($d) === 12 && strpos($d, '91') === 0) $d = substr($d, 2); elseif (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
        if (strlen($d) === 10 && wa_valid_phone($d)) return $d;
    }
    return '';
}
/** Tags from an import: "VIP, Diwali 2026; retail" -> ['vip', 'diwali_2026', 'retail'] (lower case, a-z 0-9 _, 28 letters, at most 8). */
function wa_imp_tags($v): array
{
    $a = is_array($v) ? $v : preg_split('/[,;|]/', (string)$v); $o = [];
    foreach ($a as $t) { $t = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$t))) ?? '', '_'); $t = rtrim(substr($t, 0, 28), '_'); if ($t !== '') $o[$t] = true; }
    return array_slice(array_keys($o), 0, 8);
}
/** How many of our template messages the customer has not answered (sent after their last message). */
function wa_ignored_map(PDO $pdo)   // lazy: $map[$phone10] → how many of our template messages got no answer
{
    static $m = null; if ($m !== null) return $m; wa_cust_ready($pdo);
    return $m = new WaLazyMap(function (array $ks) use ($pdo) {
        $o = []; try { $st = $pdo->prepare("SELECT phone, ign FROM wa_cust WHERE ign > 0 AND phone IN (" . wa_in($ks) . ")"); $st->execute($ks); foreach ($st->fetchAll() as $r) $o[(string)$r['phone']] = (int)$r['ign']; } catch (Throwable $e) { }
        return $o; });
}
/** The universe of customers: src = contacts (people who wrote to us) · all (+ every Shopify customer with a phone) · paste (a typed list). */
function wa_aud_rows(PDO $pdo, string $src, array $paste = [], string $q = ''): array
{
    // Only for a typed list (small). Contacts / Campaigns / Tags / Export read the customer summary with SQL (wa_aud_page / wa_aud_each) — nothing loads every customer into memory any more.
    wa_cust_ready($pdo); $rows = []; $now = time();
    if ($src === 'paste') {
        $want = []; foreach ($paste as $k) { $k = wa_key($k); if (wa_valid_phone($k)) $want[$k] = true; }
        foreach (wa_aud_each($pdo, 'all', [], '', array_keys($want)) as $r) { $rows[$r['phone']] = $r; }
        foreach (array_keys($want) as $k) if (!isset($rows[$k])) $rows[$k] = ['phone' => $k, 'name' => '', 'known' => false, 'orders' => 0, 'last_order' => '', 'days' => null, 'spent' => 0, 'ignored' => 0, 'optout' => false, 'last_in_at' => null, 'last_out_at' => null, 'last_number_id' => 0, 'source' => '', 'assigned_emp' => 0, 'status' => '', 'tag' => '', 'first_seen' => null];
        return $rows;
    }
    foreach (wa_aud_each($pdo, $src === 'all' ? 'all' : 'contacts', [], $q) as $r) $rows[$r['phone']] = $r;
    return $rows;
}
/** The filters used by Contacts and Campaigns: st · ord · dsl · ign · src · wrote. */
function wa_aud_apply(array $rows, array $f): array
{
    $now = time(); $out = [];
    foreach ($rows as $r) {
        $st = (string)($f['st'] ?? ''); $in = $r['last_in_at'] ? strtotime((string)$r['last_in_at']) : 0; $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
        if ($st === 'waiting' && !($in > 0 && $in > $o && !$r['optout'] && $r['status'] === 'open')) continue;
        if ($st === 'noreply' && $in > 0) continue;
        if ($st === 'optout' && !$r['optout']) continue;
        $ord = (string)($f['ord'] ?? ''); if ($ord !== '') { $n = $r['orders']; if ($ord === '0' && $n !== 0) continue; if ($ord === '1' && $n !== 1) continue; if ($ord === '2' && $n !== 2) continue; if ($ord === '3' && $n < 3) continue; }
        $dsl = (string)($f['dsl'] ?? ''); if ($dsl !== '') { $d = $r['days']; if ($d === null) continue; if ($dsl === '30' && $d > 30) continue; if ($dsl === '60' && ($d < 31 || $d > 60)) continue; if ($dsl === '90' && ($d < 61 || $d > 90)) continue; if ($dsl === '91' && $d <= 90) continue; }
        $ign = (int)($f['ign'] ?? 0); if ($ign > 0 && $r['ignored'] < $ign) continue;
        $gt = (string)($f['gtag'] ?? ''); if ($gt !== '' && function_exists('wag_row_tags') && !in_array($gt, wag_row_tags($r), true)) continue;   // a tag from the Tags screen (auto tags, people's tags, Claude's tags)
        $src = (string)($f['src'] ?? ''); if ($src !== '') { if ($src === 'none') { if ($r['source'] !== '') continue; } elseif ($r['source'] !== $src) continue; }
        $wr = (string)($f['wrote'] ?? ''); if ($wr === '30' && !($in > $now - 30 * 86400)) continue; if ($wr === '7' && !($in > $now - 7 * 86400)) continue; if ($wr === 'old' && ($in === 0 || $in > $now - 30 * 86400)) continue;
        $out[] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------- business profile at Meta
function wa_profile_fields(): string { return 'about,address,description,email,profile_picture_url,websites,vertical'; }
function wa_profile_get(array $n): array
{
    [$c, $d] = wa_api('GET', '/' . $n['phone_id'] . '/whatsapp_business_profile?fields=' . wa_profile_fields());
    if ($c < 200 || $c >= 300 || !is_array($d)) return [false, wa_err($d, 'Meta did not answer.')];
    $p = (array)($d['data'][0] ?? []);
    return [true, ['about' => (string)($p['about'] ?? ''), 'address' => (string)($p['address'] ?? ''), 'description' => (string)($p['description'] ?? ''), 'email' => (string)($p['email'] ?? ''), 'photo' => (string)($p['profile_picture_url'] ?? ''),
        'websites' => array_values(array_map('strval', (array)($p['websites'] ?? []))), 'vertical' => (string)($p['vertical'] ?? '')]];
}
function wa_verticals(): array
{
    return ['BEAUTY' => 'Beauty, spa and salon', 'RETAIL' => 'Shopping and retail', 'APPAREL' => 'Clothing and apparel', 'GROCERY' => 'Grocery and retail', 'OTHER' => 'Other', 'AUTO' => 'Automotive', 'EDU' => 'Education', 'ENTERTAIN' => 'Entertainment', 'EVENT_PLAN' => 'Event planning',
        'FINANCE' => 'Finance and banking', 'HEALTH' => 'Medical and health', 'HOTEL' => 'Hotel and lodging', 'NONPROFIT' => 'Non-profit', 'PROF_SERVICES' => 'Professional services', 'RESTAURANT' => 'Restaurant', 'TRAVEL' => 'Travel and transportation'];
}
/** Checks the profile fields against Meta's limits. Returns [clean, error]. */
function wa_profile_clean(array $in): array
{
    $about = trim((string)($in['about'] ?? '')); $desc = trim((string)($in['description'] ?? '')); $addr = trim((string)($in['address'] ?? '')); $mail = trim((string)($in['email'] ?? '')); $vert = strtoupper(trim((string)($in['vertical'] ?? '')));
    if ($about === '') return [null, 'The bio (About) cannot be empty — WhatsApp needs at least one character.'];
    if (mb_strlen($about) > 139) return [null, 'The bio can be up to 139 characters (now ' . mb_strlen($about) . ').'];
    if (mb_strlen($desc) > 512) return [null, 'The description can be up to 512 characters (now ' . mb_strlen($desc) . ').'];
    if (mb_strlen($addr) > 256) return [null, 'The address can be up to 256 characters.'];
    if ($mail !== '' && (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 128)) return [null, 'That email address does not look right.'];
    $web = []; foreach ((array)($in['websites'] ?? []) as $w) { $w = trim((string)$w); if ($w === '') continue; if (!preg_match('#^https?://[^\s]+\.[^\s]+$#i', $w) || mb_strlen($w) > 256) return [null, 'A website must start with http:// or https:// — “' . mb_substr($w, 0, 40) . '” does not.']; $web[] = $w; }
    if (count($web) > 2) return [null, 'WhatsApp allows up to 2 websites.'];
    if (!isset(wa_verticals()[$vert])) $vert = 'BEAUTY';
    return [['about' => $about, 'description' => $desc, 'address' => $addr, 'email' => $mail, 'websites' => $web, 'vertical' => $vert], ''];
}
/** The app id that the permanent token belongs to (needed for the photo upload). WA_APP_ID in config.php wins. */
function wa_app_id(PDO $pdo): string
{
    $c = wa_cfg_val('WA_APP_ID'); if ($c !== '') return $c;
    $s = trim((string)setting_get($pdo, 'wa_app_id', '')); if ($s !== '') return $s;
    [$cd, $d] = wa_api('GET', '/app'); $id = is_array($d) ? (string)($d['id'] ?? '') : '';
    if ($cd >= 200 && $cd < 300 && $id !== '') { setting_set($pdo, 'wa_app_id', $id); return $id; }
    return '';
}
/** Profile photo: Meta's resumable upload (3 calls), then the handle goes to the number. Returns [ok, message]. */
function wa_profile_photo(PDO $pdo, array $n, string $path, string $mime): array
{
    $app = wa_app_id($pdo); if ($app === '') return [false, 'Could not find the Meta app id for the token. Add  define(\'WA_APP_ID\', \'your app id\');  to config.php (Meta app → Settings → Basic → App ID).'];
    $bytes = (string)file_get_contents($path); $len = strlen($bytes); if ($len === 0) return [false, 'The photo is empty.'];
    $tok = wa_cfg_val('WA_TOKEN'); $base = 'https://graph.facebook.com/' . wa_graph();
    [$c1, $d1] = wa_http('POST', $base . '/' . $app . '/uploads?file_length=' . $len . '&file_type=' . rawurlencode($mime) . '&file_name=' . rawurlencode('profile.' . ($mime === 'image/png' ? 'png' : 'jpg')), ['Authorization: Bearer ' . $tok]);
    $uid = is_array($d1) ? (string)($d1['id'] ?? '') : ''; if ($c1 < 200 || $c1 >= 300 || $uid === '') return [false, 'Meta did not start the upload: ' . wa_err($d1)];
    [$c2, $d2] = wa_http('POST', $base . '/' . $uid, ['Authorization: OAuth ' . $tok, 'file_offset: 0', 'Content-Type: application/octet-stream'], $bytes);
    $h = is_array($d2) ? (string)($d2['h'] ?? '') : ''; if ($c2 < 200 || $c2 >= 300 || $h === '') return [false, 'Meta did not take the photo: ' . wa_err($d2)];
    [$c3, $d3] = wa_api('POST', '/' . $n['phone_id'] . '/whatsapp_business_profile', ['messaging_product' => 'whatsapp', 'profile_picture_handle' => $h]);
    if ($c3 < 200 || $c3 >= 300) return [false, 'Meta did not set the photo: ' . wa_err($d3)];
    return [true, ''];
}

// ---------------------------------------------------------------------------------------------------------------- sending room of each number
function wa_ramp_cap(int $ageDays): int { return $ageDays < 3 ? 250 : ($ageDays < 7 ? 500 : ($ageDays < 14 ? 1000 : ($ageDays < 21 ? 2500 : ($ageDays < 28 ? 5000 : ($ageDays < 42 ? 10000 : 1000000))))); }
require_once __DIR__ . '/wa_lim.php';
/** 📈 The owner's growing HOURLY cap for a trusted account (Meta's limit 10,000+): starts at 1,000 new people an hour; every hour it looks at the campaign sends of the
 *  hour before last (their delivery receipts have arrived by then) — enough sent (half the cap or more), 80%+ delivered, 10% or fewer failed and every connected number still
 *  High quality → the cap doubles (Meta's per-customer refusals — 131049 marketing limit, 131026 not on WhatsApp, 130472 experiment — say nothing about the account and are left out); 20%+ failed or a number dropped to Medium / Low → it halves (never below 1,000); otherwise it stays. Meta's 24-hour limit (90% of it) stays the ceiling.
 *  Setting wa_camp_auto = {cap, hour, hist[]}; wa_camp_auto_on = 0 turns it off. */
function wa_camp_auto_cap(PDO $pdo, int $tier, array $health): array
{
    // 📈 ONE hourly speed PER NUMBER (owner's rule: 1,000 an hour per number, campaigns on that number share it). Each number grows on its own:
    // every hour it looks at that number's campaign sends of the hour before last — Meta's per-customer refusals (131049 held, 131026 not on WhatsApp,
    // 130472 experiment) are left out: enough sent (half its speed or more), 80%+ delivered, 10% or fewer failed and the number still High → doubles;
    // the number dropped to Medium / Low or 20%+ failed → halves (never below 1,000); else it stays. Ceiling: 90% of Meta's daily limit.
    $max = (int)floor($tier * 0.9); $A = json_decode((string)setting_get($pdo, 'wa_camp_auto', ''), true); if (!is_array($A)) $A = [];
    $caps = is_array($A['caps'] ?? null) ? $A['caps'] : []; $hour = date('Y-m-d H'); $hist = array_values((array)($A['hist'] ?? []));
    $START = 15000;   // owner (11 Nov 2026): every number runs 15,000 new people an hour (was 5,000, then 10,000), the Medium one too — it does not grow while quality is not High
    $old0 = (int)($A['start'] ?? 0) === $START ? max($START, (int)($A['cap'] ?? $START)) : $START;
    if ((int)($A['start'] ?? 0) !== $START) { foreach ($caps as $k => $v) $caps[$k] = $START; $A['start'] = $START; $A['caps'] = $caps; $A['cap'] = $old0; try { setting_set($pdo, 'wa_camp_auto', json_encode($A)); } catch (Throwable $e) { } }   // once: lift the old 1,000 to 3,500
    $hq = []; foreach ($health as $h) $hq[(int)$h['id']] = $h;
    try { foreach ($pdo->query("SELECT id, label, display, camp_ok FROM wa_numbers")->fetchAll() as $n) if (isset($hq[(int)$n['id']])) { $hq[(int)$n['id']]['label'] = (string)$n['label'] !== '' ? (string)$n['label'] : (string)$n['display']; if ((int)$n['camp_ok'] !== 1) unset($hq[(int)$n['id']]); } } catch (Throwable $e) { }
    foreach ($hq as $nid => $h) if (($h['status'] ?? '') !== '' && $h['status'] !== 'CONNECTED') unset($hq[$nid]);   // a number that cannot send (pending at Meta / switched off for campaigns) is not listed
    if (($A['hour'] ?? '') !== $hour) {
        $from = date('Y-m-d H:i:s', time() - 7200); $to = date('Y-m-d H:i:s', time() - 3600); $st = [];
        try { $q = $pdo->prepare("SELECT number_id, status, COUNT(*) AS c FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND ts >= ? AND ts < ? AND err_code NOT IN (131049, 131026, 130472, 131042, 131031) GROUP BY number_id, status"); $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $r) { $nid = (int)$r['number_id']; $st[$nid] = $st[$nid] ?? [0, 0, 0]; $c = (int)$r['c']; $st[$nid][0] += $c; if (in_array($r['status'], ['delivered', 'read'], true)) $st[$nid][1] += $c; if ($r['status'] === 'failed') $st[$nid][2] += $c; } } catch (Throwable $e) { }
        foreach ($hq as $nid => $h) {
            if (($h['status'] ?? '') !== '' && $h['status'] !== 'CONNECTED') continue;
            $old = max($START, (int)($caps[$nid] ?? $old0)); $cap = $old; [$n, $del, $fail] = $st[$nid] ?? [0, 0, 0]; $why = 'kept';
            if (($h['quality'] ?? '') === 'RED') { $cap = max($START, (int)floor($old / 2)); $why = 'quality dropped to Low — halved'; }
            elseif ($n >= 50 && $fail / $n > 0.2) { $cap = max($START, (int)floor($old / 2)); $why = round($fail * 100 / $n) . '% failed — halved'; }
            elseif ($n >= $old * 0.5 && $del / max(1, $n) >= 0.8 && $fail / max(1, $n) <= 0.1) { $cap = $old * 2; $why = round($del * 100 / max(1, $n)) . '% delivered — doubled'; }
            elseif ($n < $old * 0.5) $why = $n . ' sent in that hour — kept (it grows after a full good hour)';
            $cap = min($cap, max(1000, $max)); $caps[$nid] = $cap;
            if (!empty($A['hour'])) $hist[] = ['hour' => $hour, 'nid' => $nid, 'sent' => $n, 'delivered' => $del, 'failed' => $fail, 'from' => $old, 'to' => $cap, 'why' => $why];
        }
        $A = ['caps' => $caps, 'cap' => $old0, 'start' => $START, 'hour' => $hour, 'hist' => array_slice($hist, -48)]; try { setting_set($pdo, 'wa_camp_auto', json_encode($A)); } catch (Throwable $e) { }
    }
    $usedBy = []; try { $q = $pdo->prepare("SELECT number_id, COUNT(DISTINCT phone) AS n FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND status <> 'failed' AND ts >= ? GROUP BY number_id"); $q->execute([date('Y-m-d H:i:s', time() - 3600)]); foreach ($q->fetchAll() as $r) $usedBy[(int)$r['number_id']] = (int)$r['n']; } catch (Throwable $e) { }
    $nums = []; foreach ($hq as $nid => $h) { $cap = min(max(1000, (int)($caps[$nid] ?? $old0)), max(1000, $max)); $u = $usedBy[$nid] ?? 0; $nums[$nid] = ['cap' => $cap, 'used_hour' => $u, 'left_hour' => max(0, $cap - $u), 'label' => (string)($h['label'] ?? ('Number ' . $nid))]; }
    $all = array_sum(array_column($nums, 'cap')); $usedAll = array_sum(array_column($nums, 'used_hour'));
    return ['nums' => $nums, 'cap' => $all, 'used_hour' => $usedAll, 'left_hour' => max(0, $all - $usedAll), 'max' => $max, 'hist' => $hist];
}
/** For each number: today's cap (slow ramp-up, quality, Meta's daily limit), what is used in the last 24 h and what is left. Also the shared portfolio limit. */
function wa_number_caps(PDO $pdo, array $health): array
{
    $tier = null; foreach ($health as $h) if ($h['tier'] !== null) { if ($h['tier'] === -1) { $tier = 1000000; break; } $tier = max((int)$tier, (int)$h['tier']); }
    $tierAssumed = $tier === null; if ($tier === null) $tier = 250;
    $cfg = wa_team_cfg($pdo); $since = date('Y-m-d H:i:s', time() - 86400);
    $lu = wa_lim_used($pdo, $since); $used = $lu['by'];
    // each number's first template send never moves, so it is looked up once and remembered (the full scan of wa_messages took seconds and ran every time the Campaign panel opened)
    $first = []; $fc = json_decode((string)setting_get($pdo, 'wa_first_tpl_cache', ''), true); $fc = is_array($fc) ? $fc : [];
    $needScan = false; foreach ($health as $h) { if (empty($fc[(int)$h['id']]) && (int)($fc['_t'] ?? 0) < time() - 3600) $needScan = true; }
    if ($needScan) {
        $fc['_t'] = time();
        foreach ($pdo->query("SELECT number_id, MIN(ts) AS t FROM wa_messages WHERE dir = 'out' AND mtype = 'template' GROUP BY number_id")->fetchAll() as $r) $fc[(int)$r['number_id']] = (string)$r['t'];
        try { setting_set($pdo, 'wa_first_tpl_cache', json_encode($fc)); } catch (Throwable $e) { }
    }
    foreach ($fc as $k => $v) if ($k !== '_t' && $v) $first[(int)$k] = (string)$v;
    $port = $lu['all'];
    $rows = $pdo->query("SELECT id, camp_ok FROM wa_numbers")->fetchAll(); $camp = []; foreach ($rows as $r) $camp[(int)$r['id']] = (int)$r['camp_ok'] === 1;
    $allBlocked = true; foreach ($health as $h0) { $pb0 = (string)setting_get($pdo, 'wa_payblock_' . (int)$h0['id'], ''); if ($pb0 === '' || strtotime($pb0) <= time() - 3 * 3600) { $allBlocked = false; break; } }   // EVERY number marked → none is skipped (each is still tried; Meta decides) so a campaign never sits idle behind my own rule
    $out = [];
    foreach ($health as $h) {
        $id = (int)$h['id']; $age = isset($first[$id]) ? (int)floor((time() - strtotime($first[$id])) / 86400) : 0;
        // slow start protects a NEW account only: once Meta itself trusts the account (limit 10,000 or more, shared by every number) Meta's own limit is the cap
        $ramp = $cfg['ramp'] && $tier < 10000;
        $cap = $ramp ? wa_ramp_cap($age) : 1000000; $why = $ramp && $cap < $tier ? 'slow start (day ' . ($age + 1) . ')' : '';
        $cap = min($cap, $tier);
        // owner (11 Nov 2026): a Medium quality number counts as High (no half speed); only Low stops it
        if ($h['quality'] === 'RED') { $cap = 0; $why = 'quality is Low — stopped'; }
        if ($h['status'] !== '' && $h['status'] !== 'CONNECTED') { $cap = 0; $why = 'not connected'; }
        if (empty($camp[$id])) { $cap = 0; $why = 'switched off for campaigns'; }
        try { $pb = (string)setting_get($pdo, 'wa_payblock_' . $id, ''); if ($allBlocked === false && $pb !== '' && strtotime($pb) > time() - 3 * 3600) { $cap = 0; $why = 'payment problem at Meta (131042) — campaigns go through the other number(s) now; tried again after 3 hours'; } } catch (Throwable $e) { }   // owner (9 Nov): when one number has the payment problem, everything moves to the other number by itself
        $u = $used[$id] ?? 0; $out[$id] = ['cap' => $cap, 'used' => $u, 'left' => max(0, $cap - $u), 'why' => $why, 'age' => $age];
    }
    $out['_port'] = ['tier' => $tier, 'assumed' => $tierAssumed, 'used' => $port, 'left' => max(0, (int)floor($tier * 0.9) - $port)];
    if ($tier >= 10000 && (string)setting_get($pdo, 'wa_camp_auto_on', '1') === '1') {   // trusted account: the owner's growing hourly cap (1,000 an hour → doubles each good hour)
        $au = wa_camp_auto_cap($pdo, $tier, $health); $out['_port']['auto'] = $au;   // this hour's speed is PER NUMBER (applied by the campaign sender only); order updates, tickets and follow-ups never wait for it
    }
    return $out;
}
}

// ---------------------------------------------------------------------------------------------------------------- add-ons (media library, campaigns / follow-ups / reports)
require_once __DIR__ . '/wa_scale.php';   // speed at 100,000+ customers: indexes, customer summary, lazy maps
require_once __DIR__ . '/wa_media.php';
require_once __DIR__ . '/wa_camp.php';
require_once __DIR__ . '/wa_learn.php';   // shared brain: guidebook suggestions + the learning queue
require_once __DIR__ . '/wa_proof.php';   // Stage C: automatic order proof + chat library feed
require_once __DIR__ . '/wa_hand.php';    // hand-over: HOT chats, Accept, reminders, payment link
require_once __DIR__ . '/wa_sales.php';   // WhatsApp Sales: Board, Give queries, Focus (new-number data only)
require_once __DIR__ . '/wa_growth.php';  // growth engine: phone panel, results, tags, History, signals, bot, builder, flows (wa_flows.php)
require_once __DIR__ . '/wa_brain.php';   // follow-up plans + engine (wa_follow.php), template ladder, quality guard, Learning & Improvement endpoints (learn.*)
require_once __DIR__ . '/wa_fu_coach.php';   // 🔁 Learning → Follow-ups: Claude builds the whole follow-up plan from one summary (fu.coach_*) — after wa_brain.php (needs the follow-up engine)
require_once __DIR__ . '/wa_autopilot.php';   // 🔁 Follow-up Autopilot: 6 paths, offers on day 2/4/7/10, festival box, teach each day, results (fu.ap_*) — after wa_fu_coach.php
require_once __DIR__ . '/wa_journey.php';   // 🗓 60-day Journey: two tracks, rolling entry / exit, central offer registry, nightly topics, festival watcher, general brain (fu.wj_*) — after wa_autopilot.php
require_once __DIR__ . '/wa_orders.php';   // live orders in the chat, Needs attention cases for Dispatch, order-update messages, Store info (wa.ord_*, wa.store_*)
if (is_file(__DIR__ . '/cust_bday.php')) require_once __DIR__ . '/cust_bday.php';   // 🎂 birthdays collected at ordering
if (is_file(__DIR__ . '/wa_review.php')) require_once __DIR__ . '/wa_review.php';   // ⭐ rating request 5 days after delivery → Judge.me (5) or a complaint (lower)
require_once __DIR__ . '/wa_smart.php';    // Smart campaigns (Claude plans a campaign from one line) + the Normal-campaign upgrade (wa.smart_*, wa.camp_suggest POST, wa.camp_speed)
require_once __DIR__ . '/wa_util.php';
require_once __DIR__ . '/wa_done.php';
require_once __DIR__ . '/wa_cxself.php';   // 🎫 a customer raises a complaint ticket themselves on WhatsApp    // ✅ order completed: one thank-you, then no automatic messages today and tomorrow (wa.order_done)    // ✨ utility versions of a campaign message (wa.tpl_make_utility, wa.tpl_utility_list)
require_once __DIR__ . '/wa_diag.php';    // 🩺 Diagnose a campaign: why it failed, from the database + Meta live (wa.camp_diagnose)
require_once __DIR__ . '/wa_origin.php';  // 📍 "Where did you find us?" asked when a new enquiry starts; the answer tags the chat (wa.origin_save)
require_once __DIR__ . '/wa_greet.php';   // Greetings: decided greeting per customer type + fixed replies, Claude writes from the Teach brain, synced into the Selling rules (wa.greet_*)
require_once __DIR__ . '/wa_lists.php';   // 📋 Lists: ready perfume lists from live sales (Top 5 winter, Best sellers…), Claude builds and edits them (wl.*)
require_once __DIR__ . '/wa_cust.php';    // 👥 Customers screen: date range first, tags any / all / not, pages, select all, bulk tag, 📁 segments, picked people for campaigns (wa.cs_*)
require_once __DIR__ . '/wa_kflow.php';   // 🔑 Key flows: fixed answers for fixed messages, built on a canvas (wa.kf_*); answers before the old flows, greetings and the bot
require_once __DIR__ . '/wa_camp_report.php';   // 📊 campaign report card + learnings of all campaigns + person-vs-bot replies that win orders (wa.camp_report, wa.camp_advice, wa.camp_learn, wa.pvb_run)
require_once __DIR__ . '/wa_late.php';     // ⏰ late replies: every chat that waits 10+ minutes for its salesperson is marked (wa_late)
require_once __DIR__ . '/wa_bill.php';     // 💰 Billing & spend: what WhatsApp cost per day / month and what it brought back (wa.bill)
require_once __DIR__ . '/wq.php';         // WhatsApp Sales (query types, tags, no-answer flow, board, cut-over adapter): wq.* — BEFORE wa_seg.php
require_once __DIR__ . '/wq_rescue.php';   // 🛟 Rescue queries: effort-missed queries, a daily rescue team, +2% bonus on won orders (wq.rescue_*) - BEFORE wa_seg.php
require_once __DIR__ . '/wq_rescue_more.php';   // 🛟 Rescue part 2: ready messages for Meta, AI draft, per-person table, alerts, AI check (wq.rescue_tpl_*, wq.rescue_stats …) - BEFORE wa_seg.php
require_once __DIR__ . '/wq_topics.php';   // 🧭 follow-up topics, time ladder, Ask Claude for every message (wq.topics*)
require_once __DIR__ . '/wq_learn.php';   // 🧠 lost chats saved for the guidebook, the nightly study pass from the engine, learning status
require_once __DIR__ . '/wq_speed.php';   // ⚡ timing of the WhatsApp calls, server health, live switch (wq.speed, wa.g_wait)
require_once __DIR__ . '/wq_tags.php';   // 🏷 One tag system: registry, automatic tags with why / until, patterns of staff-made tags, Claude's own new tags (wq.tags_*)
require_once __DIR__ . '/wq_pace.php';   // ⚡ Reply pace of every salesperson: per-message pace, 7-day comparison, after-taking-a-query pace, fast reply vs order closing (wq.pace) - BEFORE wa_seg.php
require_once __DIR__ . '/wq_manager.php';   // 🧭 Team manager (Autobot): Claude decides who sees new orange / red chats first, from every number together (wq.mgr_*)
require_once __DIR__ . '/team_board.php';   // 👥 Team board: one row per salesperson with everything in one place (team.board)
require_once __DIR__ . '/wch.php';        // Chain + perfume cart, GST bill, catalog sync (wch.*) - BEFORE wa_seg.php
require_once __DIR__ . '/wa_planner.php';   // 🗓 Campaign Planner: files + messages planned the day before, started by themselves one after another (wa.pl_*)
require_once __DIR__ . '/wa_plan.php';   // 🎯 Daily budget planner (wa.plan_get) - needs wa_bill.php and wa_camp.php
require_once __DIR__ . '/wa_drip.php';   // 🔁 7-Day Drip: a pre-made message per day for new queries / campaign repliers (wa.drip_*) - BEFORE wa_seg.php
require_once __DIR__ . '/wmo.php';        // 🧾 Manual order from a chat: QR / UPI payment first, customer address link, the order lands in Orders + Dispatch (wmo.*) - BEFORE wa_seg.php
require_once __DIR__ . '/wa_seg.php';      // customer journey, stage / value filters, Claude context pack, decisions (wa.seg_*, wa.dec_*) — MUST stay the LAST require: its router runs at include time

// ---------------------------------------------------------------------------------------------------------------- router
$__wm = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wm, ['wa.numbers', 'wa.num_profile', 'wa.num_profile_save', 'wa.num_photo', 'wa.num_set', 'wa.team', 'wa.team_save', 'wa.callnow', 'wa.callnow_done', 'wa.suggest', 'wa.contacts', 'wa.contact_import', 'wa.contact_fix', 'wa.cron', 'wa.alloc_why', 'wa.alloc_run'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($__wm === 'wa.cron') {   // Hostinger cron: no login, a secret key instead
        ignore_user_abort(true); @set_time_limit(180);   // the caller may hang up (the heartbeat does): the run still finishes
        if (!wa_tables($pdo) || !wa_more_tables($pdo)) json_response(['error' => 'storage'], 500);
        $key = (string)setting_get($pdo, 'wa_cron_key', ''); if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) json_response(['error' => 'Wrong key.'], 403);
        // speed indexes, added ONCE by the cron (never inside someone's page request): template counts, the call-now scan, per-customer
        // "since" counts and campaign look-ups by phone. Online (no table lock where the database allows it); an index that exists is skipped.
        if ((string)setting_get($pdo, 'wa_idx_v1', '') !== '1') {
            setting_set($pdo, 'wa_idx_v1', '1'); @set_time_limit(600); ignore_user_abort(true);   // marked first: a slow index build is never started twice by the next minute's cron
            foreach (["ALTER TABLE wa_messages ADD INDEX k_dir_type_ts (dir, mtype, ts, phone)", "ALTER TABLE wa_contacts ADD INDEX k_callnow (callnow, assigned_emp)",
                      "ALTER TABLE wa_messages ADD INDEX k_pdt (phone, dir, ts)", "ALTER TABLE wa_camp_items ADD INDEX k_phone (phone)"] as $ix) {
                try { $pdo->exec($ix . ", ALGORITHM=INPLACE, LOCK=NONE"); } catch (Throwable $e) { if (stripos($e->getMessage(), 'Duplicate key name') === false) { try { $pdo->exec($ix); } catch (Throwable $e2) { } } }
            }
        }
        // ⚡ one cron at a time: a slow run (Meta, Shopify) is never joined by the next minute's run — they used to pile up and slow every page
        try { if (!(int)$pdo->query("SELECT GET_LOCK('hrms_wa_cron', 0)")->fetchColumn()) {
            // 🛟 watchdog: a run that has not finished for 15+ minutes is stuck (a hung call to Meta / AI never ends by itself and blocked every later run) — its database connection is closed, the lock is free for the next minute
            $st0 = (int)setting_get($pdo, 'wa_cron_start', '0'); $fin = (int)strtotime((string)setting_get($pdo, 'wa_cron_at', ''));
            if ($st0 > 0 && $st0 > $fin && time() - $st0 > 900) { try { $cid = (int)$pdo->query("SELECT IS_USED_LOCK('hrms_wa_cron')")->fetchColumn(); if ($cid > 0) { $pdo->exec('KILL ' . $cid); wa_log($pdo, 'engine', false, 'A cron run was stuck for ' . (int)((time() - $st0) / 60) . ' minutes — it was stopped.'); setting_set($pdo, 'wa_cron_start', '0'); } } catch (Throwable $e) { } }
            json_response(['ok' => true, 'skipped' => 'the previous cron run is still working']); } } catch (Throwable $e) { }
        setting_set($pdo, 'wa_cron_start', (string)time());
        if ((string)setting_get($pdo, 'wa_idx_v3', '') !== '1') {   // ⚡ every index the chat screens need — also the first set, which was skipped when the cron ran before it (both used the flag wa_idx_v1)
            setting_set($pdo, 'wa_idx_v3', '1'); @set_time_limit(900); ignore_user_abort(true);
            foreach ([['wa_contacts', 'k_status_in', '(status, last_in_at)'], ['wa_contacts', 'k_emp_status_in', '(assigned_emp, status, last_in_at)'], ['wa_contacts', 'k_source', '(source)'],
                      ['wa_contacts', 'k_out', '(last_out_at)'], ['wa_contacts', 'k_assigned_at', '(assigned_at)'], ['wa_messages', 'k_ts', '(ts)'], ['wa_messages', 'k_phone_dir', '(phone, dir, id)'],
                      ['wa_messages', 'k_media', '(media_id)'], ['wa_outcomes', 'k_code', '(code, at)'], ['wa_assign_log', 'k_at', '(at)'], ['wa_assign_log', 'k_to_at', '(to_emp, at)'], ['shop_orders', 'k_phone', '(phone)']] as [$t, $n, $c]) {
                try { $q = $pdo->query("SHOW INDEX FROM $t WHERE Key_name = '$n'"); if ($q && $q->fetch()) continue; } catch (Throwable $e) { continue; }   // no such table on this install
                try { $pdo->exec("ALTER TABLE $t ADD INDEX $n $c, ALGORITHM=INPLACE, LOCK=NONE"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE $t ADD INDEX $n $c"); } catch (Throwable $e2) { } }
            }
        }
        // ⏱ the time of every step is kept (wa_cron_steps) and the step in progress (wa_cron_step), so a slow step can be found
        $__st = []; $__T = function (string $k, callable $f) use (&$__st, $pdo) { $t0 = microtime(true); try { setting_set($pdo, 'wa_cron_step', $k . ' @ ' . date('H:i:s')); } catch (Throwable $e) { } try { return $f(); } finally { $__st[$k] = round(microtime(true) - $t0, 1); } };
        $r = ['tick' => $__T('tick', fn() => wa_tick($pdo, true))];
        try { $__T('cust_refresh', fn() => wa_cust_refresh($pdo, 600)); } catch (Throwable $e) { }   // customer summary + tag index (every 10 minutes)
        if (wa_lock($pdo, 'fu', 240)) { try { $r['followups'] = wa_fu_scan($pdo); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'follow-ups: ' . $e->getMessage()); $r['followups'] = 'error'; } }
        try { $r['fu'] = $__T('fu_engine', fn() => fu_engine($pdo)); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'follow-up engine: ' . $e->getMessage()); $r['fu'] = 'error'; }   // the new follow-up plans (does nothing while the mode is Off)
        try { $r['brain'] = $__T('housekeeping', fn() => fu_housekeeping($pdo)); } catch (Throwable $e) { $r['brain'] = 'error'; }
        try { if (function_exists('wq_tick')) $r['wq'] = $__T('wq_tick', fn() => wq_tick($pdo)); } catch (Throwable $e) { }   // WhatsApp Sales: backfill, summaries, orders, no-answer flow, daily summary, questions
        try { if (function_exists('wch_tick')) $r['wch'] = $__T('wch_tick', fn() => wch_tick($pdo)); } catch (Throwable $e) { }   // chain cart: reminders, catalog refresh, payment polling, bills
        try { if (function_exists('wa_seg_tick')) $r['segments'] = $__T('seg_tick', fn() => wa_seg_tick($pdo, ['inline' => true])); } catch (Throwable $e) { }
        try { if (function_exists('wcs_tick')) $r['csegs'] = $__T('cust_segs', fn() => wcs_tick($pdo)); } catch (Throwable $e) { }   // live segments take in new matches (hourly)
        try { $r['campaigns'] = $__T('campaigns', fn() => wa_camp_tick($pdo)); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'campaigns: ' . $e->getMessage()); $r['campaigns'] = 'error'; }
        try { $r['recover'] = $__T('recovery', fn() => wa_rec_tick($pdo)); } catch (Throwable $e) { wa_log($pdo, 'engine', false, 'recovery: ' . $e->getMessage()); }   // 🔁 failed messages from another number / an alternative wording, slowly
        try { setting_set($pdo, 'wa_cron_steps', json_encode(['at' => date('Y-m-d H:i:s'), 's' => $__st])); } catch (Throwable $e) { }
        setting_set($pdo, 'wa_cron_at', date('Y-m-d H:i:s')); json_response(['ok' => true] + $r);
    }
    $auth = require_auth();
    if (!wa_tables($pdo) || !wa_more_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0);
    switch ($__wm) {
        case 'wa.alloc_why':   // 🤖 Allocation page: why the robot left each pending order for a person
        case 'wa.alloc_run': { // 🤖 Allocation page button: run the auto-allocation now (same people who may allocate orders)
            require_role(['Admin', 'HR'], $auth);
            $ran = null;
            if ($__wm === 'wa.alloc_run') { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); 
                // first bring today's + yesterday's Shopify orders into the order copy, so a brand-new order is never "not checked yet"
                if (function_exists('orders_sync_range') && function_exists('biz_today')) { $e0 = []; try { $bt = biz_today($pdo); orders_sync_range($pdo, date('Y-m-d', strtotime($bt . ' -1 day')), $bt, 8, $e0); } catch (Throwable $e) { } }
                setting_set($pdo, 'wa_alloc_at', '0'); $ran = wa_auto_alloc($pdo); }
            $out = []; try { foreach ($pdo->query("SELECT source, order_id, why, at FROM wa_auto_alloc_why WHERE at >= '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "'")->fetchAll() as $r) $out[$r['source'] . '|' . $r['order_id']] = ['why' => (string)$r['why'], 'at' => (string)$r['at']]; } catch (Throwable $e) { }
            json_response(['ok' => true, 'allocated' => $ran, 'checked' => $ran === null ? null : (int)setting_get($pdo, 'wa_alloc_seen', '0'), 'reasons' => $out, 'last_run' => (int)setting_get($pdo, 'wa_alloc_at', '0'), 'cron_at' => (string)setting_get($pdo, 'wa_cron_at', '')]);
        }
        case 'wa.callnow': {
            $cronOk = (string)setting_get($pdo, 'wa_cron_at', '') >= date('Y-m-d H:i:s', time() - 150);   // the minute cron is running: pages never run the engine
            if ($me <= 0) { wa_reply_early(['items' => []]); if (!$cronOk) wa_tick($pdo); exit; }
            $st = $pdo->prepare("SELECT phone, name, summary, callnow, callnow_at FROM wa_contacts WHERE assigned_emp = ? AND callnow IN (1, 2) AND status = 'open' ORDER BY callnow_at"); $st->execute([$me]);
            $items = array_map(fn($r) => ['phone' => $r['phone'], 'name' => $r['name'], 'summary' => $r['summary'], 'tel' => '+91' . $r['phone'], 'since_sec' => max(0, time() - strtotime((string)$r['callnow_at'])), 'escalated' => (int)$r['callnow'] === 2], $st->fetchAll());
            try {   // reminders that are due ("Remind me in 1 h")
                $rq = $pdo->prepare("SELECT phone, name, summary, remind_at FROM wa_contacts WHERE assigned_emp = ? AND remind_at IS NOT NULL AND remind_at <= ? ORDER BY remind_at LIMIT 5"); $rq->execute([$me, date('Y-m-d H:i:s')]);
                foreach ($rq->fetchAll() as $r) $items[] = ['phone' => $r['phone'], 'name' => $r['name'], 'summary' => $r['summary'], 'tel' => '+91' . $r['phone'], 'since_sec' => max(0, time() - (int)strtotime((string)$r['remind_at'])), 'escalated' => false, 'remind' => true];
            } catch (Throwable $e) { }
            wa_reply_early(['items' => $items, 'escalate_min' => wa_team_cfg($pdo)['escalate_min']]);
            if (!$cronOk) wa_tick($pdo);   // only when the cron is NOT running (a backup) — and AFTER the answer, so the page never waits for it
            exit;
        }
        case 'wa.suggest': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            if ((int)$c['optout'] === 1) json_response(['error' => 'This customer opted out.'], 422);
            if (function_exists('wa_suggest_guided')) { wa_learn_tables($pdo); $f0 = ai_feat('wa_sugg'); try { wa_suggest_guided($pdo, $c, $key, $in); } finally { ai_feat($f0); } }   // answers and exits when an approved guidebook exists; otherwise the generic suggestion below
            if (!function_exists('convo_ai')) json_response(['error' => 'The AI is not available.'], 502);
            $st = $pdo->prepare("SELECT dir, body, ts, who FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT 25"); $st->execute([$key]); $rows = array_reverse($st->fetchAll());
            $txt = ''; foreach ($rows as $r) $txt .= ($r['dir'] === 'in' ? 'CUSTOMER' : 'SHOP') . ': ' . mb_substr((string)$r['body'], 0, 400) . "\n";
            $o = wa_order_map($pdo)[$key] ?? null;
            $facts = 'Orders so far: ' . ($o ? $o['n'] . ' (last on ' . substr($o['last'], 0, 10) . ', total Rs ' . $o['total'] . ')' : 'none') . '. ' . ($c['source'] === 'query' ? 'Came from a Meta ad: ' . $c['ref_text'] . '.' : '');
            $qk = ''; foreach ($pdo->query("SELECT title, body FROM wa_quick ORDER BY sort_order, id LIMIT 20")->fetchAll() as $q) $qk .= '- ' . $q['title'] . ': ' . mb_substr(str_replace("\n", ' ', (string)$q['body']), 0, 300) . "\n";
            $avoid = trim(mb_substr((string)($in['avoid'] ?? ''), 0, 500));
            $sys = "You help a salesperson at a perfume shop (perfumes, attars, shower gel, wax; Surat, India) reply on WhatsApp. Write ONE short reply the salesperson can send now, in the customer's own style — Hinglish (Hindi in English letters) by default, English if the customer writes English, Gujarati if they write Gujarati. Warm, 1–3 short lines, at most one emoji, ask one clear next question. NEVER invent a price, discount, stock, delivery date or link: use only what is in the chat or in the shop's saved quick replies below; if a price is needed and not known, ask which product and size. why = one short plain-English sentence on why this reply. call_now = true only when a phone call would clearly work better (angry customer, big order, long confusing back-and-forth, customer asks to call).";
            $ctx = "CUSTOMER FACTS: " . $facts . "\n\nTHE SHOP'S SAVED QUICK REPLIES (facts you may use):\n" . ($qk !== '' ? $qk : "(none)\n") . "\nTHE CHAT:\n" . $txt . ($avoid !== '' ? "\nDO NOT repeat this earlier suggestion, write a different one:\n" . $avoid . "\n" : '');
            $sch = ['type' => 'OBJECT', 'properties' => ['suggestion' => ['type' => 'STRING'], 'why' => ['type' => 'STRING'], 'call_now' => ['type' => 'BOOLEAN']], 'required' => ['suggestion', 'why', 'call_now']];
            ignore_user_abort(true);
            // what the owner taught in Learn & Setup (rules / facts from "Teach by talking", limits, lessons) - the same knowledge the main bot uses
            $taughtT = ''; try { require_once __DIR__ . '/wa_hist.php';
                $lastIn = ''; foreach (array_reverse($rows) as $r0) if ($r0['dir'] === 'in') { $lastIn = (string)$r0['body']; break; }
                if (function_exists('teach_facts_prompt')) { $tm = []; foreach ($rows as $r0) $tm[] = ['who' => $r0['dir'] === 'in' ? 'customer' : 'bot', 'text' => (string)$r0['body']]; $taughtT .= trim((string)teach_facts_prompt($pdo, $tm)[0]) . "\n"; }
                if (function_exists('teach_limits_text')) $taughtT .= trim((string)teach_limits_text($pdo)) . "\n";
                if (function_exists('teach_lessons') && function_exists('teach_lessons_text') && $lastIn !== '') $taughtT .= trim((string)teach_lessons_text(teach_lessons($pdo, $lastIn, false))) . "\n";
            } catch (Throwable $e) { }
            if (trim($taughtT) !== '') $ctx = "THE OWNER'S RULES, LIMITS AND LESSONS (always follow these; they win over everything else):\n" . mb_substr(trim($taughtT), 0, 6000) . "\n\n" . $ctx;
            try { [$ok, $v, $why] = convo_ai($sys, [['text' => $ctx]], $sch, false, 1200, 'claude'); } catch (Throwable $e) { json_response(['error' => 'The AI could not read this chat.'], 502); }
            if ($ok && is_array($v) && function_exists('learn_enforce_v')) { try { $ev = learn_enforce_v($pdo, ['reply' => (string)($v['suggestion'] ?? '')] + $v); $v['suggestion'] = (string)($ev['reply'] ?? $v['suggestion']); } catch (Throwable $e) { } }   // the owner's hard limits are checked in code, as for the main bot
            if (!$ok || !is_array($v) || trim((string)($v['suggestion'] ?? '')) === '') json_response(['error' => (string)($why ?: 'The AI could not write a reply.')], 502);
            $sgT = mb_substr(trim((string)$v['suggestion']), 0, 1500); if (function_exists('wa_sugg_save')) wa_sugg_save($pdo, $key, $sgT, 'basic', 0);
            json_response(['ok' => true, 'suggestion' => $sgT, 'why' => mb_substr(trim((string)($v['why'] ?? '')), 0, 300), 'call_now' => !empty($v['call_now']), 'source' => 'basic', 'ver' => 0]);
        }
        case 'wa.callnow_done': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $key = wa_key(json_input()['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $pdo->prepare("UPDATE wa_contacts SET callnow = 9 WHERE phone = ?")->execute([$key]); wa_event($pdo, $key, 'Called by ' . (employee_names_by_id($pdo)[$me] ?? 'the salesperson')); json_response(['ok' => true]);
        }
    }
    wa_guard_admin($pdo, $auth);
    switch ($__wm) {
        case 'wa.numbers': {
            $GLOBALS['__wa_lim_cache_ok'] = empty($_GET['live']);   // ⚡ screen only: the heavy 24-hour count is remembered for 30 s (the campaign sender never sets this and counts fresh)
            $health = wa_health_all($pdo, !empty($_GET['live'])); $caps = wa_number_caps($pdo, $health); $names = employee_names_by_id($pdo); $week = date('Y-m-d H:i:s', time() - 7 * 86400);
            $rows = $pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll(); $by = []; foreach ($rows as $r) $by[(int)$r['id']] = $r; $out = [];
            foreach ($health as $h) {
                $r = $by[(int)$h['id']] ?? null; if (!$r) continue; $id = (int)$r['id'];
                $m = null; $mk = 'wa_num7_' . $id; if (empty($_GET['live'])) { try { $hit = json_decode((string)setting_get($pdo, $mk, ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 120) $m = ['i' => $hit['i'], 'o' => $hit['o']]; elseif (is_array($hit) && isset($hit['i']) && (int)setting_get($pdo, $mk . '_lk', '0') > time() - 60) $m = ['i' => $hit['i'], 'o' => $hit['o']]; /* another request is already counting: use the last copy */ } catch (Throwable $e) { } }   // ⚡ the 7-day count of a big table: remembered for 2 minutes, one counter at a time
                if ($m === null) { try { setting_set($pdo, $mk . '_lk', (string)time()); } catch (Throwable $e) { } $q = $pdo->prepare("SELECT SUM(CASE WHEN dir = 'in' THEN 1 ELSE 0 END) AS i, SUM(CASE WHEN dir = 'out' THEN 1 ELSE 0 END) AS o FROM wa_messages WHERE number_id = ? AND ts >= ?"); $q->execute([$id, $week]); $m = $q->fetch(); try { setting_set($pdo, $mk, json_encode(['t' => time(), 'i' => (int)$m['i'], 'o' => (int)$m['o']])); } catch (Throwable $e) { } }
                $hid = array_values(array_filter(array_map('intval', explode(',', (string)$r['handlers'])))); $c = $caps[$id] ?? ['cap' => 0, 'used' => 0, 'left' => 0, 'why' => '', 'age' => 0];
                $out[] = ['id' => $id, 'label' => $h['label'], 'raw_label' => (string)$r['label'], 'display' => $h['display'] ?: (string)$r['display'], 'phone_id' => (string)$r['phone_id'], 'waba_id' => wa_waba_of($r), 'verified_name' => $h['verified_name'], 'name_status' => $h['name_status'],
                    'quality' => $h['quality'], 'status' => $h['status'], 'subscribed' => $h['subscribed'], 'error' => $h['error'], 'tier' => $h['tier'], 'level' => $h['level'] ?? 'ok', 'checked_at' => $h['at'] ?? '', 'stale' => !empty($h['stale']),
                    'camp_ok' => (int)$r['camp_ok'] === 1, 'camp_tag' => (int)($r['camp_tag'] ?? 0) === 1, 'handlers' => $hid, 'handler_names' => array_values(array_filter(array_map(fn($x) => $names[$x] ?? '', $hid))), 'in_7d' => (int)$m['i'], 'out_7d' => (int)$m['o'], 'cap' => $c, 'is_main' => (string)$r['phone_id'] === wa_cfg_val('WA_PHONE_ID')];
            }
            json_response(['numbers' => $out, 'portfolio' => $caps['_port'] ?? null, 'people' => array_map(fn($p) => ['emp_id' => $p['emp_id'], 'name' => $p['name']], wa_people($pdo))]);
        }
        case 'wa.num_profile': {
            $n = wa_number_row($pdo, (int)($_GET['id'] ?? 0)); if (!$n) json_response(['error' => 'Number not found.'], 404);
            [$ok, $p] = wa_profile_get($n); if (!$ok) json_response(['error' => $p], 502);
            json_response(['profile' => $p, 'verticals' => wa_verticals()]);
        }
        case 'wa.num_profile_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $n = wa_number_row($pdo, (int)($in['id'] ?? 0)); if (!$n) json_response(['error' => 'Number not found.'], 404);
            [$p, $err] = wa_profile_clean($in); if ($err !== '') json_response(['error' => $err], 422);
            [$c, $d] = wa_api('POST', '/' . $n['phone_id'] . '/whatsapp_business_profile', ['messaging_product' => 'whatsapp'] + $p);
            if ($c < 200 || $c >= 300) { $e = wa_err($d); wa_log($pdo, 'profile', false, $n['phone_id'] . ' · ' . $e); json_response(['error' => $e], 502); }
            wa_log($pdo, 'profile', true, 'Profile of ' . ($n['label'] ?: $n['phone_id']) . ' saved at Meta.'); json_response(['ok' => true]);
        }
        case 'wa.num_photo': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $n = wa_number_row($pdo, (int)($_POST['id'] ?? 0)); if (!$n) json_response(['error' => 'Number not found.'], 404);
            $f = $_FILES['file'] ?? null; if (!$f || (int)($f['error'] ?? 0) !== UPLOAD_ERR_OK || !wa_is_upload((string)$f['tmp_name'])) json_response(['error' => 'Pick a photo first (JPG or PNG).'], 422);
            $tmp = (string)$f['tmp_name']; $det = ''; if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $det = (string)finfo_file($fi, $tmp); finfo_close($fi); } }
            if (!in_array($det, ['image/jpeg', 'image/png'], true)) json_response(['error' => 'The profile photo must be a JPG or PNG.'], 422);
            if (filesize($tmp) > 5 * 1048576) json_response(['error' => 'The photo is over 5 MB.'], 422);
            $is = @getimagesize($tmp); if ($is && ($is[0] < 192 || $is[1] < 192)) json_response(['error' => 'The photo is too small. Use at least 192 × 192 pixels (640 × 640 is best).'], 422);
            ignore_user_abort(true); [$ok, $e] = wa_profile_photo($pdo, $n, $tmp, $det);
            if (!$ok) { wa_log($pdo, 'profile', false, $n['phone_id'] . ' · photo · ' . $e); json_response(['error' => $e], 502); }
            wa_log($pdo, 'profile', true, 'Profile photo of ' . ($n['label'] ?: $n['phone_id']) . ' changed.'); json_response(['ok' => true]);
        }
        case 'wa.num_set': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $id = (int)($in['id'] ?? 0); $n = wa_number_row($pdo, $id); if (!$n) json_response(['error' => 'Number not found.'], 404);
            $label = array_key_exists('label', $in) ? trim(mb_substr((string)$in['label'], 0, 60)) : (string)$n['label']; if ($label === '') json_response(['error' => 'Give the number a name.'], 422);
            $camp = array_key_exists('camp_ok', $in) ? (empty($in['camp_ok']) ? 0 : 1) : (int)$n['camp_ok'];
            $ctag = array_key_exists('camp_tag', $in) ? (empty($in['camp_tag']) ? 0 : 1) : (int)($n['camp_tag'] ?? 0);
            $valid = array_column(wa_people($pdo), 'emp_id'); $h = array_key_exists('handlers', $in) ? array_values(array_unique(array_filter(array_map('intval', (array)$in['handlers']), fn($x) => in_array($x, $valid, true)))) : array_values(array_filter(array_map('intval', explode(',', (string)$n['handlers']))));
            $pdo->prepare("UPDATE wa_numbers SET label = ?, camp_ok = ?, handlers = ?, camp_tag = ? WHERE id = ?")->execute([$label, $camp, implode(',', $h), $ctag, $id]);
            $marked = 0;
            if ($ctag && !(int)($n['camp_tag'] ?? 0)) {   // just switched on: the chats already on this number (the customer's last message came to it) become Campaign too
                $now = date('Y-m-d H:i:s'); $st = $pdo->prepare("SELECT phone FROM wa_contacts WHERE last_number_id = ? AND last_in_at IS NOT NULL"); $st->execute([$id]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) { wa_camp_mark($pdo, (string)$ph, $now); $marked++; }
                try { wa_camp_requery($pdo, 30); } catch (Throwable $e) { }   // their orders of the last 30 days the robot typed "Query" become Campaign too
            }
            json_response(['ok' => true, 'marked' => $marked]);
        }
        case 'wa.team': {
            $key = (string)setting_get($pdo, 'wa_cron_key', ''); if ($key === '') { $key = bin2hex(random_bytes(12)); setting_set($pdo, 'wa_cron_key', $key); }
            $stats = wa_person_stats($pdo); $people = wa_people($pdo); $names = employee_names_by_id($pdo);
            foreach ($people as &$p) { $s = $stats[$p['emp_id']] ?? null; $p['median'] = $s['median'] ?? null; $p['fast'] = $s['fast'] ?? null; $p['replies'] = $s['replies'] ?? 0; } unset($p);
            $host = $_SERVER['HTTP_HOST'] ?? 'fourpirates.space'; $url = 'https://' . $host . '/empmanagment/api.php?action=wa.cron&key=' . $key;
            $all = []; foreach ($names as $i => $nm) $all[] = ['emp_id' => (int)$i, 'name' => (string)$nm];
            json_response(['cfg' => wa_team_cfg($pdo), 'people' => $people, 'employees' => $all, 'cron' => ['url' => $url, 'command' => 'wget -q -O /dev/null "' . $url . '"', 'last' => (string)setting_get($pdo, 'wa_cron_at', ''), 'tick' => (string)setting_get($pdo, 'wa_tick_at', '')]]);
        }
        case 'wa.team_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $c = wa_team_cfg($pdo);
            foreach (['max_open', 'after_msgs', 'after_min', 'escalate_min'] as $k) if (isset($in[$k])) $c[$k] = (int)$in[$k];
            foreach (['auto_assign', 'callnow', 'ramp'] as $k) if (array_key_exists($k, $in)) $c[$k] = !empty($in[$k]);
            if (isset($in['alert_emps'])) $c['alert_emps'] = array_values(array_filter(array_map('intval', (array)$in['alert_emps'])));
            if (isset($in['community_kw'])) $c['community_kw'] = trim(mb_substr((string)$in['community_kw'], 0, 60));
            foreach (['rate_marketing', 'rate_utility'] as $k) if (isset($in[$k])) $c[$k] = max(0, min(50, round((float)$in[$k], 3)));
            setting_set($pdo, 'wa_team', json_encode($c)); setting_set($pdo, 'wa_pstats', ''); json_response(['ok' => true, 'cfg' => wa_team_cfg($pdo)]);
        }
        case 'wa.contacts': {
            $f = ['st' => (string)($_GET['st'] ?? ''), 'ord' => (string)($_GET['ord'] ?? ''), 'dsl' => (string)($_GET['dsl'] ?? ''), 'ign' => (int)($_GET['ign'] ?? 0), 'src' => (string)($_GET['src'] ?? ''), 'wrote' => (string)($_GET['wrote'] ?? ''), 'gtag' => (string)($_GET['gtag'] ?? '')];
            foreach (['in_from', 'in_to', 'added_from', 'added_to'] as $k0) { $v0 = (string)($_GET[$k0] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v0)) $f[$k0] = $v0; }
            if (isset($_GET['cf']) && function_exists('wcs_clean')) { $cf = json_decode((string)$_GET['cf'], true); $f = array_merge(array_filter($f, fn($v) => $v !== '' && $v !== 0), wcs_clean(is_array($cf) ? $cf : [])); }   // the Customers screen: date range first, tags any / all / not, ranges
            $off = max(0, (int)($_GET['off'] ?? 0)); $lim = max(1, min(500, (int)($_GET['lim'] ?? 50))); $names = employee_names_by_id($pdo);
            [$pageRows, $total, $allN] = wa_aud_page($pdo, 'contacts', $f, trim((string)($_GET['q'] ?? '')), $off, $lim, (string)($_GET['sort'] ?? ''));
            $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nums[(int)$n['id']] = $n['label'] !== '' ? $n['label'] : $n['display'];
            $page = array_map(function ($r) use ($names, $nums) { $r['assigned_name'] = $names[$r['assigned_emp']] ?? ''; $r['last_number'] = $nums[$r['last_number_id']] ?? ''; return $r; }, $pageRows);
            // every stored tag of the customers on this page (imported list tags, campaign, query types, people's and Claude's tags) — one query
            $ph = array_values(array_unique(array_map(fn($r) => (string)$r['phone'], $page))); $tg = [];
            if ($ph) { try { $tq = $pdo->prepare("SELECT phone, tag FROM wa_ctags WHERE phone IN (" . implode(',', array_fill(0, count($ph), '?')) . ") ORDER BY tag"); $tq->execute($ph); foreach ($tq->fetchAll() as $t) $tg[(string)$t['phone']][] = (string)$t['tag']; } catch (Throwable $e) { } }
            $lab = (function_exists('wq_tag_labels') ? wq_tag_labels() : []) + (function_exists('wcs_seg_names') ? wcs_seg_names($pdo) : []);
            foreach ($page as &$r) { $r['tags'] = array_map(fn($t) => ['k' => $t, 't' => $lab[$t] ?? (strpos($t, 'import_') === 0 ? 'Imported ' . substr($t, 7) : str_replace('_', ' ', $t))], $tg[(string)$r['phone']] ?? []); } unset($r);
            json_response(['contacts' => $page, 'total' => $total, 'all' => $allN]);
        }
        case 'wa.contact_import': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); if (empty($in['consent'])) json_response(['error' => 'Tick the box to confirm these customers agreed to get WhatsApp messages from you.'], 422);
            // every imported customer gets 'campaign' (we reach them with campaigns) + one tag for this import (so a campaign can pick exactly this file) + the tags typed for everyone + the tags in their own row
            $batch = 'import_' . strtolower(date('jMy')); $common = array_merge(['campaign', $batch], wa_imp_tags($in['tags'] ?? []));
            $rows = [];
            if (is_array($in['rows'] ?? null)) foreach (array_slice($in['rows'], 0, 20000) as $r) $rows[] = ['phone' => (string)($r['phone'] ?? ''), 'name' => (string)($r['name'] ?? ''), 'tags' => wa_imp_tags($r['tags'] ?? [])];
            else foreach (preg_split('/\r?\n/', (string)($in['text'] ?? '')) as $line) { $line = trim($line); if ($line === '') continue; preg_match_all('/\+?\d[\d\s\-()]{8,}\d/', $line, $m); $ph = $m[0][0] ?? ''; $rows[] = ['phone' => $ph, 'name' => preg_replace('/[\d+()\-,;:|]+/', ' ', str_replace($ph, '', $line)), 'tags' => []]; }
            $add = 0; $dup = 0; $bad = 0; $tagged = 0; $now = date('Y-m-d H:i:s'); $seen = [];
            $tagQ = $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'import', ?)"); $idxQ = null; try { $idxQ = $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)"); } catch (Throwable $e) { }
            foreach ($rows as $r) {
                $k = wa_imp_phone($r['phone']); if ($k === '') { $bad++; continue; } if (isset($seen[$k])) { $dup++; continue; } $seen[$k] = true;
                $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['name']) ?? ''), 0, 120);
                if (($ex = wa_contact($pdo, $k))) { $dup++; if ($name !== '' && trim((string)($ex['name'] ?? '')) === '') $pdo->prepare("UPDATE wa_contacts SET name = ? WHERE phone = ?")->execute([$name, $k]); }
                else { $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, state, status, source) VALUES (?, ?, ?, ?, 'unassigned', 'open', '')")->execute([$k, '91' . $k, $name, $now]); $add++; }
                foreach (array_unique(array_merge($common, $r['tags'])) as $t) { try { $tagQ->execute([$k, $t, $now]); if ($idxQ) $idxQ->execute([$k, $t]); } catch (Throwable $e) { } }
                $tagged++;
            }
            try { setting_set($pdo, 'wa_tag_cache', ''); } catch (Throwable $e) { }
            json_response(['ok' => true, 'added' => $add, 'already' => $dup, 'invalid' => $bad, 'tagged' => $tagged, 'batch_tag' => $batch, 'tags' => array_values(array_unique($common))]);
        }
        case 'wa.contact_fix': {   // rows the import could not read: Claude finds the Indian mobile number + name in each, the server checks every number again
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $bad = array_slice(is_array($in['rows'] ?? null) ? $in['rows'] : [], 0, 150); if (!$bad) json_response(['fixed' => [], 'left' => []]);
            $lines = []; foreach ($bad as $i => $r) $lines[] = $i . ': ' . mb_substr(implode(' | ', array_map('strval', (array)$r)), 0, 240);
            $sys = "You clean a customer list for WhatsApp. Each line is one spreadsheet row (cells split by |). Find the customer's INDIAN mobile number (10 digits, starts with 6, 7, 8 or 9; it may be written with +91, 91, 0, spaces, dashes, dots, '.0' at the end, or two numbers in one cell - take the first mobile) and their name. "
                 . "Never invent digits: if the number is cut short or shown like 9.19876E+11 (Excel scientific format), or it is a landline or a foreign number, leave phone empty and say why in a few words. Answer {\"rows\":[{\"i\":0,\"phone\":\"9876543210\",\"name\":\"\",\"why\":\"\"}]} with one item per line.";
            $j = function_exists('brain_ai_json') ? brain_ai_json('internal', 'whatsapp', $sys, implode("\n", $lines), 3000, ['ctx' => false]) : null;
            if (!is_array($j)) json_response(['error' => 'Claude could not check these rows right now. Fix them in the file and upload again.'], 502);
            $fixed = []; $left = []; $done = [];
            foreach ((array)($j['rows'] ?? []) as $x) {
                $i = (int)($x['i'] ?? -1); if (!isset($bad[$i]) || isset($done[$i])) continue; $done[$i] = true;
                $k = wa_imp_phone((string)($x['phone'] ?? ''));
                $raw = preg_replace('/\D+/', '', implode(' ', array_map('strval', (array)$bad[$i]))) ?? '';
                if ($k !== '' && strpos($raw, $k) !== false) $fixed[] = ['i' => $i, 'phone' => $k, 'name' => mb_substr(trim((string)($x['name'] ?? '')), 0, 120)];   // the 10 digits really are in the row - nothing made up
                else $left[] = ['i' => $i, 'why' => mb_substr(trim((string)($x['why'] ?? '')) ?: 'no Indian mobile number found', 0, 120)];
            }
            foreach ($bad as $i => $r) if (!isset($done[$i])) $left[] = ['i' => $i, 'why' => 'not checked'];
            json_response(['fixed' => $fixed, 'left' => $left]);
        }
    }
}
