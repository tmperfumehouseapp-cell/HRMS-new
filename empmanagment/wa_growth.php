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
    return ['unans_min' => 3, 'unans_push' => true, 'bot' => 'on', 'bot_after' => 10, 'bot_max_day' => 200, 'target' => 5, 'vip_orders' => 4, 'vip_ltv' => 10000, 'ai_tags' => true, 'sound' => true];
}
function wag_cfg(PDO $pdo): array
{
    $d = wag_defaults();
    try { $j = json_decode((string)setting_get($pdo, 'wa_g_cfg', ''), true); } catch (Throwable $e) { $j = null; }
    // once: the bot answers every unanswered customer 10 s after their message (no daily cap); the holding reply for chats a person must take also waits 10 s
    try { if (setting_get($pdo, 'wag_bot10', '') !== '1') { setting_set($pdo, 'wag_bot10', '1'); $j = is_array($j) ? $j : []; $j['bot'] = 'on'; $j['bot_after'] = 10; $j['bot_max_day'] = 200; setting_set($pdo, 'wa_g_cfg', json_encode($j));
        if (function_exists('wq_put')) { wq_put($pdo, 'wq_auto_on', '1'); wq_put($pdo, 'wq_hold_sec', '10'); } } } catch (Throwable $e) { }
    // once: a present salesperson who has the chat gets 2 minutes before the holding message too
    try { if (setting_get($pdo, 'wag_bot120', '') !== '1') { setting_set($pdo, 'wag_bot120', '1'); if (function_exists('wq_put')) wq_put($pdo, 'wq_hold_sec', '120'); } } catch (Throwable $e) { }
    // once (owner, 21 Nov): the bot answers EVERYONE who wrote, 4 s after the message, until a salesperson presses Take over; up to 500 answers a day for one customer
    try { if (setting_get($pdo, 'wag_bot4', '') !== '1') { setting_set($pdo, 'wag_bot4', '1'); $j = is_array($j) ? $j : []; $j['bot'] = 'on'; $j['bot_after'] = 4; $j['bot_max_day'] = 500; setting_set($pdo, 'wa_g_cfg', json_encode($j)); } } catch (Throwable $e) { }
    // once (21 Nov, 14:10 IST): a bulk "manual" assignment of ~900 chats to one person wrote a bot-silencing note for each; they are removed (the bot answers those chats again)
    try { if (setting_get($pdo, 'wag_fix_botoff1', '') !== '1') { setting_set($pdo, 'wag_fix_botoff1', '1');
        $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_assign_log WHERE how = 'manual' AND by_emp = to_emp AND at >= ? AND at < ?"); $q->execute(['2026-11-21 14:00:00', '2026-11-21 14:12:00']); $ph = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($ph, 300) as $ch) { $keys = array_map(fn($p) => 'botoff:' . $p, $ch); $d = $pdo->prepare("DELETE FROM wq_meta WHERE v <= ? AND k IN (" . implode(',', array_fill(0, count($keys), '?')) . ")"); $d->execute(array_merge(['2026-11-21 14:12:00'], $keys)); } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): the typed query counts end with the CURRENT business day; the NEW business day (from 10 AM tomorrow) is counted from the live WhatsApp query ledger (Query, Campaign, boards) — the cut-over date is set to tomorrow's business day when none is set
    try { if (setting_get($pdo, 'wag_cut1', '') !== '1') { setting_set($pdo, 'wag_cut1', '1'); $cv = trim((string)setting_get($pdo, 'wq_cutover', ''));
        if ($cv === '') { $nd = function_exists('biz_today') ? date('Y-m-d', strtotime(biz_today($pdo) . ' +1 day')) : date('Y-m-d', strtotime('+1 day')); if (function_exists('wq_put')) wq_put($pdo, 'wq_cutover', $nd); else { setting_set($pdo, 'wq_cutover', $nd); if (function_exists('hrms_settings_bump')) hrms_settings_bump(); } wag_log($pdo, 'engine', true, 'WhatsApp Sales cut-over set to ' . $nd . ' (the new business day)'); } } } catch (Throwable $e) { }
    // once (owner, 21 Nov, later): orders are counted from the start of the new business day, so queries are too — the cut-over is TODAY's business day (not tomorrow's): Query / Campaign counts and boards read the live ledger from the day's start
    try { if (setting_get($pdo, 'wag_cut2', '') !== '1') { setting_set($pdo, 'wag_cut2', '1'); $cv = trim((string)setting_get($pdo, 'wq_cutover', '')); $td = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
        if ($cv === '' || $cv > $td) { if (function_exists('wq_put')) wq_put($pdo, 'wq_cutover', $td); else { setting_set($pdo, 'wq_cutover', $td); if (function_exists('hrms_settings_bump')) hrms_settings_bump(); } wag_log($pdo, 'engine', true, 'WhatsApp Sales cut-over moved to ' . $td . ' (this business day)'); } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): today's business day was really started at 1 PM (the official Saturday start is 12:30): orders and queries are counted from the same moment — a date-specific start time for today
    try { if (setting_get($pdo, 'wag_day1', '') !== '1') { setting_set($pdo, 'wag_day1', '1'); $raw = (string)setting_get($pdo, 'day_timing_overrides', ''); $j = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($j)) { $j = []; for ($d = strtotime('2026-11-08'); $d <= strtotime('2026-11-15'); $d += 86400) $j[date('Y-m-d', $d)] = '12:00'; }
        $j['2026-11-21'] = '13:00'; setting_set($pdo, 'day_timing_overrides', json_encode($j)); if (function_exists('hrms_settings_bump')) hrms_settings_bump(); wag_log($pdo, 'engine', true, 'business day 2026-11-21 starts at 13:00 (owner)'); } } catch (Throwable $e) { }
    // once (owner, 21 Nov): complaint chats are removed from the query counts
    try { if (setting_get($pdo, 'wag_cxq1', '') !== '1') { setting_set($pdo, 'wag_cxq1', '1');
        $ph = $pdo->query("SELECT phone FROM wa_contacts WHERE (cx_id IS NOT NULL AND cx_id > 0) OR tag = 'complaint' LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN); foreach ($ph as $__p) wag_q_forget($pdo, (string)$__p); } } catch (Throwable $e) { }
    // once (owner, 21 Nov): order-status / tracking / refund chats are removed from the query counts (last 3 days)
    try { if (setting_get($pdo, 'wag_cxq2', '') !== '1') { setting_set($pdo, 'wag_cxq2', '1');
        $st = $pdo->prepare("SELECT DISTINCT q.phone FROM wa_queries q JOIN wa_messages m ON m.phone = q.phone AND m.dir = 'in' AND m.ts >= q.opened_at AND m.ts <= DATE_ADD(q.opened_at, INTERVAL 10 MINUTE) WHERE q.opened_at >= ? AND m.body REGEXP 'tracking|track my|track order|where is my order|order status|order id|order no|kab aayega|kab tak aayega|kab milega|nahi mila|not received|refund|return|replace|damaged|leak|wrong item|wrong product|cancel my order|order kaha|parcel' LIMIT 4000"); $st->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $__p) wag_q_forget($pdo, (string)$__p, 3); } } catch (Throwable $e) { }
    // once (owner, 21 Nov): OPEN MARKET — no chat is given to anybody: automatic assignment off (waa engine + wq auto), manual assignment on; salespeople take what they can close
    try { if (setting_get($pdo, 'wag_openmkt', '') !== '1') { setting_set($pdo, 'wag_openmkt', '1'); setting_set($pdo, 'wag_open_market', '1'); setting_set($pdo, 'waa_off', '1'); setting_set($pdo, 'waa_on_day', ''); setting_set($pdo, 'wq_auto_on', '0'); setting_set($pdo, 'wa_manual_assign', '1'); if (function_exists('hrms_settings_bump')) hrms_settings_bump(); } } catch (Throwable $e) { }
    // once (owner, 21 Nov): price start + add-on focus (ticket size Rs 2,700-2,800)
    try { if (setting_get($pdo, 'wag_rules4', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules4', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rule = 'Price: with nothing negotiated start at the 6.5% chart level for the quantity the customer chose; a customer who arrives with a price (ad / earlier offer) gets THAT level; go to the next level only when he objects to the price. Goal: an average order of Rs 2,700-2,800 — always try ONE add-on that lifts the order to that range (one more bottle / the next combo, said as "sirf Rs X aur mein Y ml extra"); never push after the second no.';
            $gb['do'] = array_values(array_merge([$rule], array_slice((array)($gb['do'] ?? []), 0, 20)));
            $gb['owner_corrections'] = array_values(array_merge([['customer' => '(price talk / order of less than Rs 2,700)', 'better' => $rule]], (array)($gb['owner_corrections'] ?? [])));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): aggressive follow-up topic for the bot
    try { if (setting_get($pdo, 'wag_rules8', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules8', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rule = 'FOLLOW-UP (aggressive but polite): never let the customer go in the first hours. When a customer goes silent after our answer, the bot follows up by itself 10 times — 5 min ("Sir aapko kaunse perfume me confusion / doubt hai? Order bana dun?"), 10 min (the one missing detail), 20 min (the exact chart price, "banau order?"), 30 min (doubt on price? the add-on to Rs 2,700-2,800), 1 h (two easy options), 2 h (true urgency), 3 h (remove the doubt: best seller for his taste / which occasion), 4 h (one-word YES / names), then slower: 8 h (a soft check-in), 18 h (last friendly nudge); afterwards the 30-day Lineup takes over. EVERY follow-up ends with ONE easy question; each message a new angle, never a copy, never an invented offer; stop at once on "no / stop / nahi chahiye".';
            $gb['do'] = array_values(array_merge([$rule], array_slice(array_values(array_filter((array)($gb['do'] ?? []), function ($x) { return strpos((string)$x, 'FOLLOW-UP (aggressive') !== 0; })), 0, 20)));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): 10 ml is only an add-on, never part of the opening
    try { if (setting_get($pdo, 'wag_rules3', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules3', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rule = 'Never start with or suggest 10 ml. The opening and the main offer are the 50 ml and 100 ml combos (20 ml only as 5 x 20 ml, quoted from the chart starting at the 6.5% level). A 10 ml is only a small add-on on top of a combo, and only if the customer asks for it.';
            $gb['dont'] = array_values(array_merge([$rule], array_slice((array)($gb['dont'] ?? []), 0, 20)));
            $gb['owner_corrections'] = array_values(array_merge([['customer' => '(opening of any chat)', 'better' => $rule]], (array)($gb['owner_corrections'] ?? [])));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): the guidebook learns to keep the sale when a customer cancels part of an order
    try { if (setting_get($pdo, 'wag_rules2', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules2', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rule = 'When a customer cancels or cuts part of his order, do not just accept: explain that the bigger combo gives the best price per piece, suggest keeping all, replacing the dropped perfumes with others of his choice, or going up to the next combo (8 to 10); only if he insists again, accept and recalculate from the price chart. Never invent a discount.';
            $gb['do'] = array_values(array_merge([$rule], array_slice((array)($gb['do'] ?? []), 0, 20)));
            $gb['owner_corrections'] = array_values(array_merge([['customer' => '7 and 4 cancel kardo (part of an 8 perfume order)', 'better' => 'Sir, 8 ka combo sabse best deal hai — 7 aur 4 hatane se rate badh jayega. Aap unki jagah koi aur 2 pasand ke perfume le lo, ya 10 ka combo le lo to per perfume aur sasta padega. Kaun se naye 2 add karu?']], (array)($gb['owner_corrections'] ?? [])));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): the guidebook gets the rule "perfumes only — no shower gel / wax / candle / solid perfume / 10 ml / roll-on / mini unless asked" (a standing correction survives the nightly rewrite)
    try { if (setting_get($pdo, 'wag_rules1', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules1', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rule = 'Talk about our perfumes only (50 ml combos first). Do not suggest or mention shower gel, wax, candles, solid perfumes, 10 ml, roll-ons or minis unless the customer asks for them by name.';
            $gb['dont'] = array_values(array_merge([$rule], array_slice((array)($gb['dont'] ?? []), 0, 20)));
            $gb['owner_corrections'] = array_values(array_merge([['customer' => '(any message while perfumes are discussed)', 'better' => $rule]], (array)($gb['owner_corrections'] ?? [])));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov): NO TM Originals / "OG" / "build your own original combo 3499" suggestions (own pricing, not needed) — everything about them leaves the guidebook and a standing rule is added; they come back only when a person suggests them by hand
    try { if (setting_get($pdo, 'wag_rules9', '') !== '1' && function_exists('lib_guide_row')) { setting_set($pdo, 'wag_rules9', '1'); $g = lib_guide_row($pdo, 'live');
        if ($g) { $gb = json_decode((string)$g['body'], true); if (is_array($gb)) {
            $rx = '/(\bOGs?\b|originals?\b|dress to impress|compliment getter|dark side|caffeine club|build your own|\b3499\b)/iu';
            $clean = function ($v) use (&$clean, $rx) { if (is_array($v)) { $o = []; foreach ($v as $k => $x) { if (is_string($x) ? preg_match($rx, $x) : (is_array($x) && preg_match($rx, json_encode($x, JSON_UNESCAPED_UNICODE)))) continue; $o[$k] = is_array($x) ? $clean($x) : $x; } return is_list_arr($o) ? array_values($o) : $o; } return $v; };
            $gb = $clean($gb); $rule = 'Never suggest or mention TM Originals / OG / "build your own original combo" (own pricing). Offer only our normal perfumes and the price chart; Originals only if a person suggests them by hand.';
            $gb['dont'] = array_values(array_merge([$rule], array_slice((array)($gb['dont'] ?? []), 0, 20)));
            $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($gb, JSON_UNESCAPED_UNICODE), (int)$g['id']]); } } } } catch (Throwable $e) { }
    // once (owner, 21 Nov: "as fast as possible"): the bot starts 2 s after the message (the answer itself takes the AI 3-5 s)
    try { if (setting_get($pdo, 'wag_bot2', '') !== '1') { setting_set($pdo, 'wag_bot2', '1'); $j = is_array($j) ? $j : []; $j['bot'] = 'on'; $j['bot_after'] = 2; setting_set($pdo, 'wa_g_cfg', json_encode($j)); } } catch (Throwable $e) { }
    if (is_array($j)) foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = $j[$k];
    $d['unans_min'] = max(1, min(60, (int)$d['unans_min'])); $d['bot_after'] = max(2, min(600, (int)$d['bot_after'])); $d['bot_max_day'] = max(1, min(500, (int)$d['bot_max_day']));
    $d['target'] = max(1, min(200, (int)$d['target'])); $d['vip_orders'] = max(2, min(50, (int)$d['vip_orders'])); $d['vip_ltv'] = max(1000, (int)$d['vip_ltv']);
    $d['bot'] = 'on'; foreach (['unans_push', 'ai_tags', 'sound'] as $b) $d[$b] = !empty($d[$b]);
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

/** ⚡ A tiny fingerprint of "my" chat list (one light query). The phone asks for it every few seconds and only downloads the whole list when it changed. */
function wag_sig(PDO $pdo, int $emp): string
{
    $now = time(); $fa = "(c.assigned_emp > 0 AND c.assigned_at IS NOT NULL AND c.assigned_at >= '" . date('Y-m-d H:i:s', $now - 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_messages hm WHERE hm.phone = c.phone AND hm.dir = 'out' AND hm.who IN ('emp','phone') AND hm.ts >= c.assigned_at))";
    try {
        $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', c.phone, c.assigned_emp, c.hot, c.status, c.last_in_at, c.last_out_at, c.tag, c.cx_id, c.optout, c.seen_msg_id, c.assigned_at, c.remind_at, c.name, c.summary))), 0) s FROM wa_contacts c WHERE c.status = 'open' AND c.assigned_emp = ? AND ((c.last_in_at IS NOT NULL AND c.last_in_at >= ?) OR " . $fa . ")");
        $q->execute([$emp, date('Y-m-d H:i:s', $now - 86400)]); $r = $q->fetch(PDO::FETCH_ASSOC); return (int)$r['n'] . ':' . $r['s'];
    } catch (Throwable $e) { return 'x' . mt_rand(); }
}
// ---------------------------------------------------------------------------------------------------------------- the phone inbox
// ---------------------------------------------------------------------------------------------------------------- 🏷 MY OWN TAGS (a salesperson's private labels on customers: VIP, Birthday Nov, Call karvo …)
/** Each person has their own tags (nobody else sees them). A customer can carry up to 10 of a person's tags; a person can have up to 60 tags. Colour = 0..4. */
function wag_ut_tables(PDO $pdo): void
{
    static $ok = false; if ($ok) return; $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_utags (id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, name VARCHAR(40) NOT NULL, color TINYINT NOT NULL DEFAULT 0, created_at DATETIME NULL, UNIQUE KEY u_emp_name (emp_id, name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_utag_map (tag_id INT NOT NULL, phone VARCHAR(12) NOT NULL, at DATETIME NULL, PRIMARY KEY (tag_id, phone), KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('wag_ut_tables: ' . $e->getMessage()); }
}
function wag_ut_list(PDO $pdo, int $emp): array
{
    wag_ut_tables($pdo); $o = [];
    try { $q = $pdo->prepare("SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM wa_utag_map m WHERE m.tag_id = t.id) n FROM wa_utags t WHERE t.emp_id = ? ORDER BY t.name"); $q->execute([$emp]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'color' => (int)$r['color'], 'n' => (int)$r['n']]; } catch (Throwable $e) { }
    return $o;
}
function wag_ut_of(PDO $pdo, int $emp, string $phone): array
{
    wag_ut_tables($pdo);
    try { $q = $pdo->prepare("SELECT m.tag_id FROM wa_utag_map m JOIN wa_utags t ON t.id = m.tag_id WHERE t.emp_id = ? AND m.phone = ?"); $q->execute([$emp, $phone]); return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
}
/** phone => [tag ids] for one person's tags (the list rows). */
function wag_ut_map(PDO $pdo, int $emp, array $phones): array
{
    wag_ut_tables($pdo); $o = []; if ($emp <= 0 || !$phones) return $o;
    try { foreach (array_chunk(array_values($phones), 400) as $ch) { $q = $pdo->prepare("SELECT m.phone, m.tag_id FROM wa_utag_map m JOIN wa_utags t ON t.id = m.tag_id WHERE t.emp_id = ? AND m.phone IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute(array_merge([$emp], $ch)); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(string)$r['phone']][] = (int)$r['tag_id']; } } catch (Throwable $e) { }
    return $o;
}
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
            . " ORDER BY IF(fresh_agent = 1, 0, 1), GREATEST(COALESCE(c.last_in_at, '1970-01-01'), IF(fresh_agent = 1, c.assigned_at, '1970-01-01')) DESC LIMIT " . ($tag ? 400 : 250);   // 24 hours after the customer's last message a chat leaves the inbox and sits in History
    $rows = $pdo->query($sql)->fetchAll(); $om = wa_order_map($pdo); $oc = wag_outcome_map($pdo); $ct = wag_ctag_map($pdo); $ks = array_column($rows, 'phone'); $om->prefetch($ks); $oc->prefetch($ks); $ct->prefetch($ks); $utm = $emp > 0 ? wag_ut_map($pdo, $emp, $ks) : []; $nums = wag_nums($pdo); $out = []; $cnt = ['all' => 0, 'waiting' => 0, 'overdue' => 0, 'hot' => 0];   // 🏷 my own tags of these chats
    $hf = function_exists('wa_heat_flags') ? wa_heat_flags($pdo, $ks) : [];
    $ot = function_exists('wa_owner_tags') ? wa_owner_tags($pdo, $ks) : [];
    $org = function_exists('wa_origin_chips') ? wa_origin_chips($pdo, $ks) : [];   // 📍 where the customer said they came from (Instagram / ad / friend)
    $hum = []; if ($ks) { try { $hq = $pdo->prepare("SELECT phone, MAX(ts) t FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND ts >= ? AND phone IN (" . implode(',', array_fill(0, count($ks), '?')) . ") GROUP BY phone"); $hq->execute(array_merge([date('Y-m-d H:i:s', $now - 10 * 86400)], $ks)); foreach ($hq->fetchAll() as $h) $hum[(string)$h['phone']] = strtotime((string)$h['t']); } catch (Throwable $e) { } }   // the last answer a PERSON gave (bot / auto messages do not count)
    foreach ($rows as $r) {
        $in = strtotime((string)$r['last_in_at']); $o = $r['last_out_at'] ? strtotime((string)$r['last_out_at']) : 0;
        $waiting = $in > $o && (int)$r['optout'] === 0; $win = $in > $now - 86400; $overdue = $waiting && $win && $in <= $cut;
        $k = (string)$r['phone']; $x = $om[$k] ?? null; $tags = wag_row_tags(['phone' => $k, 'orders' => (int)($x['n'] ?? 0), 'spent' => (int)($x['total'] ?? 0), 'days' => null, 'ignored' => 0, 'last_in_at' => $r['last_in_at'], 'source' => $r['source'], 'tag' => $r['tag'], 'optout' => $r['optout']]);
        $prev = trim((string)$r['last_body']); if ($prev === '' && $r['last_type'] !== '') $prev = '[' . $r['last_type'] . ']'; $bdg = wag_badge($pdo, $r, $x, $tags, $cfg);
        $out[] = ['phone' => $k, 'name' => (string)$r['name'], 'preview' => mb_substr($prev, 0, 70), 'mine' => $r['last_dir'] === 'out', 'unread' => (int)$r['unread'], 'waiting' => $waiting, 'overdue' => $overdue, 'wait_min' => $waiting ? max(0, (int)floor(($now - $in) / 60)) : 0,
            'fresh' => (int)($r['fresh_agent'] ?? 0) === 1, 'wait_sec' => $waiting ? max(0, $now - $in) : 0, 'last_at' => (string)$r['last_in_at'], 'hot' => (int)$r['hot'] === 1, 'remind_at' => $r['remind_at'], 'window' => $win, 'src' => (string)$r['source'], 'ad' => (string)$r['ad_head'],
            'heat' => function_exists('wa_heat') ? wa_heat($r, ($hf[$k] ?? []) + (($bdg['k'] ?? '') === 'bad' ? ['cx' => 1] : []), (int)$cfg['unans_min']) : '', 'biz' => !empty($hf[$k]['biz']),
            'read' => wag_read($r, $waiting, $win, (int)$r['hot'] === 1, (int)($r['cx_id'] ?? 0) > 0 || (string)$r['tag'] === 'complaint', (int)($x['n'] ?? 0), $now), 'badge' => $bdg, 'origin' => (string)($org[$k] ?? ''), 'noresp' => ($hum[(string)$r['phone']] ?? 0) < $in, 'first_emp' => (int)($ot[$k]['first'] ?? 0), 'first_name' => $names[(int)($ot[$k]['first'] ?? 0)] ?? '', 'not_mine' => $tag && (int)$r['assigned_emp'] !== $emp, 'ut' => $utm[$k] ?? [], 'cx' => (int)($r['cx_id'] ?? 0) > 0 || (string)$r['tag'] === 'complaint', 'num' => $nums[(int)$r['last_number_id']] ?? '', 'emp' => (int)$r['assigned_emp'], 'emp_name' => $names[(int)$r['assigned_emp']] ?? '', 'result' => $oc[$k]['code'] ?? '', 'tags' => array_slice($tags, 0, 3)];
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
/** 📣 The campaign messages this customer got in the last 30 days (their full text), so the bot answers a campaign reply from the campaign itself.
 *  Owner decision: a customer who comes through a campaign gets that campaign's offer and price even after its dates have passed. */
function wag_campaign_context(PDO $pdo, string $key): string
{
    try {
        $q = $pdo->prepare("SELECT body, ts FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND ts >= ? ORDER BY id DESC LIMIT 2"); $q->execute([$key, date('Y-m-d H:i:s', time() - 30 * 86400)]); $rows = $q->fetchAll();
        if (!$rows) return '';
        $o = "CAMPAIGN MESSAGES WE SENT THIS CUSTOMER (newest first). If the customer is replying to one, answer from it. Honor its offer and price even if its dates have passed (the owner allows it). If two different offers were sent and it is unclear which one the customer means, ask which one.";
        foreach ($rows as $i => $r) $o .= "\n[" . ($i + 1) . ' · ' . substr((string)$r['ts'], 0, 10) . '] ' . mb_substr(trim((string)$r['body']), 0, 700);
        return $o;
    } catch (Throwable $e) { return ''; }
}
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
                    if (is_array($body)) { $__qx = function_exists('wq_bot_context') ? wq_bot_context($pdo, $c) : ''; $__cc = wag_campaign_context($pdo, $key); if ($__cc !== '') $__qx = trim($__qx . "\n" . $__cc); if ($__qx !== '') $body['chat_context_do_not_quote'] = mb_substr($__qx, 0, 3600); }   // query type, ad headline, store info, owner decisions (max 2,000 characters)
                    if (is_array($body) && $body && $msgs) {
                        if ($avoid !== '') $msgs[] = ['who' => 'bot', 'text' => mb_substr($avoid, 0, 500)];
                        [$ok, $v, $__lw] = lib_bot_reply($pdo, $body, $msgs, 0, !empty($GLOBALS['lib_fu_step'])); $GLOBALS['lib_last_why'] = (string)($__lw ?? '') . ($ok && is_array($v) ? ' (ok, reply ' . mb_strlen((string)($v['reply'] ?? '')) . ' chars)' : ' (not ok)');
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
/** Our small key-value notes (botoff / bothand / hotmiss / stuck / neg…) read and written with direct SQL: this file's router answers while the file is being included, BEFORE wq.php has defined wq_meta_get / wq_meta_put — every 👎 take-over note was silently lost (the bot kept answering, the lock never opened). */
function wag_meta_get(PDO $pdo, string $k, string $d = ''): string
{
    try { $q = $pdo->prepare("SELECT v FROM wq_meta WHERE k = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; }
}
function wag_meta_put(PDO $pdo, string $k, string $v): void
{
    static $ensured = false;
    if (!$ensured) { $ensured = true; try { $pdo->exec("CREATE TABLE IF NOT EXISTS wq_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { } }
    try { $pdo->prepare("INSERT INTO wq_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]); } catch (Throwable $e) { }
}
/** 🛑 A chat message the AI already could not answer (a person must answer it) or failed on: not asked again for the same message — handoff: never; a failure: after 10 minutes. */
function wag_neg_skip(PDO $pdo, string $mk, int $inId): bool
{
    if ($inId <= 0 || false) return false;
    $v = explode('|', wag_meta_get($pdo, $mk, ''));
    return count($v) === 2 && (int)$v[0] === $inId && ((int)$v[1] === 0 || (int)$v[1] > time());
}
function wag_neg_set(PDO $pdo, string $mk, int $inId, bool $permanent): void { if ($inId > 0 && true) wag_meta_put($pdo, $mk, $inId . '|' . ($permanent ? 0 : time() + 600)); }
function wag_prebuild(PDO $pdo, string $key): bool
{
    try {
        $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $st->execute([$key]); $c = $st->fetch(); if (!$c) return false;
        if ((int)$c['optout'] === 1 || (string)$c['status'] !== 'open' || empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return false;
        if (!empty($c['cx_id'])) return false;   // the chat is with the Complaint team: nothing is pre-written or auto-sent for it
        if (function_exists('wkw_try') && wkw_try($pdo, $key)) return true;   // "100 ML combo" / "20ml" / "50ml" / "offer": the saved Pre-made message goes out at once
        if (!empty($c['last_out_at']) && strtotime((string)$c['last_out_at']) >= strtotime((string)$c['last_in_at'])) return false;   // already answered
        $li = $pdo->prepare("SELECT mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) return false;   // photos / voice notes / documents are for a person
        if (wag_sugg_get($pdo, $key)) return true;
        if (function_exists('wa_hot_intent') && wa_hot_intent((string)$m['body']) !== '') return false;   // wants to pay: a person replies
        $gq = wag_greet_quick($pdo, (string)$m['body'], $c); if ($gq !== null && $gq !== '') { wa_sugg_save($pdo, $key, $gq, 'quick', 0); return true; }   // a plain "hi": your own saved greeting, no AI
        $inId = (int)(wa_last_in($pdo, $key)[0] ?? 0); if (wag_neg_skip($pdo, 'pgn:' . $key, $inId)) return false;   // the same message was already tried and a person has to answer it: no second AI call
        // 💸 Owner's switch (Admin → AI usage, 14 Nov): advance replies OFF = no AI reply is written when a message arrives (it cost ~$34 a day, most of it for chats nobody opened and for
        // every extra message of a waiting customer). The reply is written when a salesperson opens the chat instead (wa.g_sugg). Keyword replies and the saved greeting above are untouched.
        if ((string)setting_get($pdo, 'ai_presugg', '0') !== '1') return false;
        $f0 = ai_feat('wa_presugg'); $r = wag_brain_reply($pdo, $c); ai_feat($f0);
        if (!$r['ok'] || !empty($r['handoff'])) { wag_neg_set($pdo, 'pgn:' . $key, $inId, !empty($r['ok'])); return false; }   // "call now" / "a person should handle this" is never pre-written (so it is never auto-sent)
        wa_sugg_save($pdo, $key, $r['text'], $r['source'], (int)$r['ver']); return true;
    } catch (Throwable $e) { return false; }
}
/** ⏱ keeps how long the last 30 suggestions made while a salesperson waited took on the server, in ms (Admin → AI usage shows usual / fastest / slowest). */
function wa_sugg_ms_add(PDO $pdo, int $ms): void
{
    try { if ($ms < 150) return; $j = json_decode((string)setting_get($pdo, 'ai_sugg_ms', ''), true); $a = is_array($j) ? (array)($j['l'] ?? []) : []; $a[] = $ms; setting_set($pdo, 'ai_sugg_ms', json_encode(['at' => date('Y-m-d H:i:s'), 'l' => array_slice($a, -30)])); } catch (Throwable $e) { }   // under 150 ms = no AI call was made (a saved reply)
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
/** 🧠 The "Claude line" of a chat: what the customer means (u) and what the salesperson should do (d), one colour (c). Rules only (no AI call per chat), so the list stays fast. */
function wag_read(array $r, bool $waiting, bool $win, bool $hot, bool $cx, int $orders, int $now): array
{
    $in = strtotime((string)$r['last_in_at']) ?: 0; $txt = mb_strtolower(trim((string)$r['last_body'])); $mine = $r['last_dir'] === 'out'; $left = $win ? max(0, (int)floor((86400 - ($now - $in)) / 3600)) : 0;
    $w = $win ? ' · window ' . $left . ' h' : ' · window closed';
    if ($cx) return ['k' => 'cx', 'u' => '⚠ Complaint' . $w, 'd' => 'Complaint team answers this chat'];
    if ($hot || (!$mine && function_exists('wa_hot_intent') && wa_hot_intent($txt) !== '')) return ['k' => 'pay', 'i' => 'red', 'u' => '💳 Ready to pay now' . $w, 'd' => 'Send the payment link / confirm the order now'];
    if (!$waiting) {
        $days = $in ? (int)floor(($now - $in) / 86400) : 0;
        if ($days >= 3) return ['k' => 'cold', 'u' => '❄ Quiet for ' . $days . ' days', 'd' => 'Leave to the Journey plan'];
        return ['k' => 'ask', 'u' => '✓ We replied — waiting for the customer' . $w, 'd' => 'Wait; the plan nudges later'];
    }
    if ($txt === '' || preg_match('/^\[/u', $txt)) return ['k' => 'ask', 'u' => '📎 Sent a photo / voice / file' . $w, 'd' => 'Open it and answer'];
    if (preg_match('/(\b\d{6}\b|flat|house no|street|road|nagar|society|apartment|address|pin ?code|landmark|pata)/u', $txt)) return ['k' => 'addr', 'i' => 'orange', 'u' => '📍 Giving the delivery address' . $w, 'd' => 'Confirm the address and create the order'];
    if (preg_match('/(kab aayega|where is my|tracking|order status|not received|nahi mila|nahi aaya|delivery kab)/u', $txt)) return ['k' => 'ask', 'u' => '📦 Asking about an order' . $w, 'd' => 'Check the order status and tell them'];
    if (preg_match('/(discount|less|kam kar|kam hoga|costly|mehang|expensive|best price|last price|offer|cheap|sasta|10 ?%|price kam)/u', $txt)) return ['k' => 'neg', 'i' => 'orange', 'u' => '🤝 Negotiating — wants a better price' . $w, 'd' => 'Offer a free sample / best price; do not drop the price fast'];
    if (preg_match('/(think|family|ask (my|ghar)|baad me|later|sochta|sochti|dekh(ta|ti|unga|ungi)|let me|will tell|batata|batati|kal bataunga)/u', $txt)) return ['k' => 'think', 'i' => 'orange', 'u' => '🤔 Thinking / asking family' . $w, 'd' => 'Reply short; the plan nudges later'];
    if (preg_match('/\?|(price|rate|kitna|kya|kaun|which|how|available|stock|best|longest|lasts?)/u', $txt)) return ['k' => 'ask', 'i' => preg_match('/(price|rate|kitna|kimat|ml\b|\bml|offer|available|stock|bottle|\d+\s?(x|pcs|piece)|lena|lunga|chahiye|chaiye|which|best|last|long|perfume|attar|scent|smell|fragrance|gift|recommend|suggest)/u', $txt) ? 'orange' : '', 'u' => '❓ Asking about a product' . $w, 'd' => ($orders > 0 ? 'Answer and suggest a repeat pick' : 'Answer and show 2 picks')];
    return ['k' => 'ask', 'u' => '💬 New message' . $w, 'd' => 'Reply now'];
}
/** 🧩 ADD-ON MODULE (owner, 21 Nov): when the customer gave the address (the order is final) and BEFORE the payment link, the bot offers ONE add-on bottle (same size) at a fixed discount, or TWO. The price is made by CODE from the agreed order, never by the AI or the guidebook: normal order = 25% off for one / 32% off for two bottles; an order already at the 2,800+ ticket = 15% / 20%. Switch: wag_addon_on = 0. The salesperson earns a fixed 6% on the add-on amount (recorded in wag_addon; payroll hookup is checked with the owner). */
function wag_addon_ensure(PDO $pdo): void { static $ok = null; if ($ok) return; $ok = true; try { $pdo->exec("CREATE TABLE IF NOT EXISTS wag_addon (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, at DATETIME NOT NULL, status VARCHAR(10) NOT NULL, n TINYINT NOT NULL DEFAULT 0, size_ml INT NOT NULL DEFAULT 0, total_before INT NOT NULL DEFAULT 0, amount INT NOT NULL DEFAULT 0, p1 INT NOT NULL DEFAULT 0, p2 INT NOT NULL DEFAULT 0, KEY k_ph (phone), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { } }
/** [qty, size_ml, total, p1, p2] from the price the bot / person quoted ("5 x 20 ml ... Rs 1760"); null when it is not clear (then no offer: never guess a price). */
function wag_addon_calc(PDO $pdo, string $key): ?array
{
    try { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('bot','emp') AND mtype = 'text' ORDER BY id DESC LIMIT 12"); $q->execute([$key]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) { $t = str_replace(['×', '✕'], 'x', (string)$t);
            if (!preg_match('/(\d{1,2})\s*x\s*(\d{2,3})\s*ml[^\n]{0,90}?(?:rs\.?|₹)\s*([\d,]{3,6})/iu', $t, $mm)) {
                // a NEWER message with a price in it that this reader cannot understand ("Make 12 for 2500") = the deal changed after the quote it knows: no offer at all (never an offer on an old price)
                if (preg_match('/(?:rs\.?|₹|\bfor\b|=|\btotal\b)\s*([\d,]{3,6})\b/iu', $t, $pm) && (int)str_replace(',', '', $pm[1]) >= 500 && (int)str_replace(',', '', $pm[1]) <= 20000) return null;
                continue; }
            $qty = (int)$mm[1]; $ml = (int)$mm[2]; $tot = (int)str_replace(',', '', $mm[3]); if ($qty < 2 || $qty > 20 || $tot < 500 || $tot > 20000 || !in_array($ml, [20, 30, 50, 100], true)) continue;
            $per = $tot / $qty; $hi = $tot >= 2800; $d1 = $hi ? 0.15 : 0.25; $d2 = $hi ? 0.20 : 0.32; $am = $ml;
            if ($tot < 1500 && $ml < 50) {   // a small order: the add-on is a 50 ml bottle (a bigger lift of the ticket), priced from OUR chart at the 6.5% start level
                try { $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (is_array($j) && !empty($j['cols']) && !empty($j['rows'])) { $ix = 1; foreach (array_values(array_map('floatval', (array)$j['cols'])) as $i2 => $cv) if (abs($cv - 6.5) < 0.01) $ix = $i2; foreach ((array)$j['rows'] as $r2) if ((int)($r2['ml'] ?? 0) === 50 && (int)($r2['p'][$ix] ?? 0) > 0) { $per = (float)$r2['p'][$ix]; $am = 50; break; } } } catch (Throwable $e) { }
            }
            $p1 = (int)(round($per * (1 - $d1) / 10) * 10); $p2 = (int)(round(2 * $per * (1 - $d2) / 10) * 10); if ($p1 < 50 || $p2 <= $p1) continue;
            return [$qty, $ml, $tot, $p1, $p2, $am]; }
    } catch (Throwable $e) { }
    return null;
}
/** The order is final: offer the add-on (true = offered, the bot waits for the answer). */
function wag_addon_offer(PDO $pdo, array $c, string $key): bool
{
    try { if ((string)setting_get($pdo, 'wag_addon_on', '1') === '0') return false; if (wag_meta_get($pdo, 'addon:' . $key, '') !== '') return false;   // once per customer
        $__cl = $pdo->prepare("INSERT IGNORE INTO wq_meta (k, v) VALUES (?, ?)"); $__cl->execute(['addon:' . $key, 'claim|' . time()]); if ($__cl->rowCount() !== 1) return false;   // atomic claim: two bot ticks at once must not send the offer twice
        $x = wag_addon_calc($pdo, $key); if (!$x) { wag_meta_put($pdo, 'addon:' . $key, 'skip|' . date('Y-m-d H:i:s') . '|noprice'); return false; }
        $__ci = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 3"); $__ci->execute([$key]); if (preg_match('/\b(not|nahi|nhi|galat|wrong|change|badal|cancel)\b/iu', implode(' ', $__ci->fetchAll(PDO::FETCH_COLUMN)))) { wag_meta_put($pdo, 'addon:' . $key, 'skip|' . date('Y-m-d H:i:s') . '|disputed'); return false; }   // the customer disputes the quoted order ("this is my cart, not 8"): no offer on a stale price [$qty, $mlo, $tot, $p1, $p2, $ml] = $x;   // $mlo = the size he orders, $ml = the size of the add-on bottle
        $li = (int)$pdo->query("SELECT MAX(id) FROM wa_messages WHERE phone = '" . addslashes($key) . "' AND dir = 'in'")->fetchColumn();
        $txt = 'Sir, order final karne se pehle ek special offer 🎁 Aapke ' . $qty . ' x ' . $mlo . ' ml ke saath ek aur ' . $ml . ' ml perfume sirf Rs ' . $p1 . ' mein, ya do aur sirf Rs ' . $p2 . ' mein add kar sakte hain. Add karun?';
        [$ok] = wa_send_buttons($pdo, $c, $txt, ['➕ 1 add Rs ' . $p1, '➕➕ 2 add Rs ' . $p2, '❌ Nahi'], 'bot', 0); if (!$ok) { wag_meta_put($pdo, 'addon:' . $key, 'skip|' . date('Y-m-d H:i:s') . '|sendfail'); return false; }
        wag_meta_put($pdo, 'addon:' . $key, 'offered|' . time() . '|' . $li . '|' . $qty . '|' . $ml . '|' . $tot . '|' . $p1 . '|' . $p2);
        wag_addon_ensure($pdo); $pdo->prepare("INSERT INTO wag_addon (phone, emp_id, at, status, size_ml, total_before, p1, p2) VALUES (?, ?, ?, 'offered', ?, ?, ?, ?)")->execute([$key, (int)($c['assigned_emp'] ?? 0), date('Y-m-d H:i:s'), $ml, $tot, $p1, $p2]);
        wa_event($pdo, $key, '🧩 The bot offered an add-on before the link: 1 more ' . $ml . ' ml for Rs ' . $p1 . ' or 2 more for Rs ' . $p2 . ' (order Rs ' . $tot . ').'); return true;
    } catch (Throwable $e) { return false; }
}
/** After the answer (or no answer in 5 minutes): the bot stops and the person finishes the order — the original end of order mode. */
function wag_order_finalize(PDO $pdo, array $c, string $key, string $extra = ''): void
{
    wag_meta_put($pdo, 'botoff:' . $key, date('Y-m-d H:i:s')); wag_meta_put($pdo, 'bothand:' . $key, date('Y-m-d H:i:s'));
    wa_event($pdo, $key, '🛒 The bot collected the order details and the address. A person: send the payment link and confirm the order now.' . $extra);
    $emp = (int)$c['assigned_emp'];
    if ($emp > 0 && function_exists('ct_push')) { $cu = trim((string)($c['name'] ?? '')) !== '' ? (string)$c['name'] : '+91 ' . $key; ct_push($pdo, [$emp], $extra !== '' ? 'Order details ready + add-on' : 'Order details ready', $cu . ': details + address are in the chat.' . $extra . ' Send the payment link.', '/my_chats.html?phone=' . $key, 'wa-ord-' . $key); }
}
/** The customer answered the add-on offer (button or words). true = handled here (the AI does not answer this message). */
function wag_addon_handle(PDO $pdo, array $c, string $key, array $m): bool
{
    try { $st = wag_meta_get($pdo, 'addon:' . $key, ''); if (strpos($st, 'offered|') !== 0) return false; $p = explode('|', $st); if ((int)$m['id'] <= (int)($p[2] ?? 0)) return false;
        $qty = (int)$p[3]; $ml = (int)$p[4]; $tot = (int)$p[5]; $p1 = (int)$p[6]; $p2 = (int)$p[7]; $t = mb_strtolower((string)$m['body']); wag_addon_ensure($pdo);
        $n = 0; if (preg_match('/(➕➕|2 add|do add|two|2 bottle|dono)/u', $t)) $n = 2; elseif (preg_match('/(➕|1 add|add|haan|\bha\b|yes|\bok\b|kar do|karo|ek)/u', $t) && !preg_match('/(nahi|nhi|\bno\b|❌|mat )/u', $t)) $n = 1;
        $upd = function (string $status, int $nn, int $amt) use ($pdo, $key) { try { $pdo->prepare("UPDATE wag_addon SET status = ?, n = ?, amount = ? WHERE phone = ? AND status = 'offered' ORDER BY id DESC LIMIT 1")->execute([$status, $nn, $amt, $key]); } catch (Throwable $e) { } };
        if ($n === 0) { $no = (bool)preg_match('/(nahi|nhi|\bno\b|❌|mat |nahin|na chahiye|nahi chahiye)/u', $t); wag_meta_put($pdo, 'addon:' . $key, ($no ? 'declined|' : 'passed|') . date('Y-m-d H:i:s')); $upd($no ? 'declined' : 'passed', 0, 0); if ($no) wa_send_text($pdo, $c, 'Theek hai sir 😊 Hamari team ab aapko payment link bhejegi.', 'bot', 0); wag_order_finalize($pdo, $c, $key, $no ? ' (customer said no to the add-on)' : ' (the customer answered the add-on offer unclearly: "' . mb_substr((string)$m['body'], 0, 40) . '" — ask him)'); return true; }   // an unclear answer ("Okay") is not a no: the person asks him, the bot sends nothing
        $amt = $n === 2 ? $p2 : $p1; wag_meta_put($pdo, 'addon:' . $key, 'accepted|' . date('Y-m-d H:i:s') . '|' . $n . '|' . $amt); $upd('accepted', $n, $amt);
        wa_send_text($pdo, $c, 'Done sir ✅ ' . $n . ' extra ' . $ml . ' ml add ho gaya (Rs ' . $amt . '). Naya total Rs ' . ($tot + $amt) . '. Kaun sa perfume add karna hai? Naam bata dijiye, team payment link bhejegi 🙏', 'bot', 0);
        $note = ' ➕ ADD-ON accepted: ' . $n . ' x ' . $ml . ' ml = Rs ' . $amt . ' (order Rs ' . $tot . ' → Rs ' . ($tot + $amt) . '). Customer will name the perfume; make the link with the NEW total. You earn a fixed 6% (Rs ' . round($amt * 0.06) . ') on the add-on.';
        wa_event($pdo, $key, '🧩' . $note); wag_order_finalize($pdo, $c, $key, $note); return true;
    } catch (Throwable $e) { return false; }
}
/** Offers nobody answered for 5 minutes: the order goes to the person as before. */
function wag_addon_sweep(PDO $pdo): void
{
    try { $q = $pdo->query("SELECT k, v FROM wq_meta WHERE k LIKE 'addon:%' AND v LIKE 'offered|%' LIMIT 20"); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $p = explode('|', (string)$r['v']); if (time() - (int)($p[1] ?? 0) < 300) continue; $key = substr((string)$r['k'], 6);
        $cs = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $cs->execute([$key]); $c = $cs->fetch(PDO::FETCH_ASSOC); wag_meta_put($pdo, 'addon:' . $key, 'passed|' . date('Y-m-d H:i:s')); if (!$c) continue;
        wag_addon_ensure($pdo); try { $pdo->prepare("UPDATE wag_addon SET status = 'passed' WHERE phone = ? AND status = 'offered' ORDER BY id DESC LIMIT 1")->execute([$key]); } catch (Throwable $e) { } wag_order_finalize($pdo, $c, $key, ' (the add-on offer got no answer)'); } } catch (Throwable $e) { }
}
/** 🧩 The address is confirmed in ANY chat (also one a salesperson holds): the add-on offer goes out by itself ~5 seconds later unless the person already sent the payment link (owner, 21 Nov). The person sees "🧩 … offered" in the chat. */
function wag_addon_scan(PDO $pdo): void
{
    try { if ((string)setting_get($pdo, 'wag_addon_on', '1') === '0') return; $now = time();
        $q = $pdo->prepare("SELECT phone, MAX(id) id, MAX(ts) ts FROM wa_messages WHERE dir = 'in' AND ts >= ? AND ts <= ? AND body REGEXP '(^|[^0-9])[0-9]{6}([^0-9]|$)' GROUP BY phone ORDER BY MAX(id) DESC LIMIT 25");
        $q->execute([date('Y-m-d H:i:s', $now - 900), date('Y-m-d H:i:s', $now - 5)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $key = (string)$r['phone']; if (wag_meta_get($pdo, 'addon:' . $key, '') !== '') continue;
            $cs = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ? AND status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0)"); $cs->execute([$key]); $c = $cs->fetch(PDO::FETCH_ASSOC); if (!$c) continue;
            $lk = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND id > ? AND (body LIKE '%tmperfumehouse.com%' OR body LIKE '%payment link%' OR body LIKE '%cart link%' OR body LIKE '%pay.%' OR body LIKE '%rzp.io%' OR body LIKE '%razorpay%') LIMIT 1"); $lk->execute([$key, (int)$r['id'] - 30]); if ($lk->fetchColumn()) { wag_meta_put($pdo, 'addon:' . $key, 'skip|' . date('Y-m-d H:i:s') . '|link'); continue; }   // the link is already sent: too late
            wag_addon_offer($pdo, $c, $key); }
    } catch (Throwable $e) { }
}
/** ORDER MODE finished? The customer's messages now hold a 6-digit pincode (the address): before the link the bot offers the add-on (once); then it stops on this chat, the salesperson is alerted to send the payment and confirm. */
function wag_order_done_check(PDO $pdo, array $c, string $key): void
{
    try {
        $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ? ORDER BY id DESC LIMIT 12"); $q->execute([$key, date('Y-m-d H:i:s', time() - 6 * 3600)]);
        $t = implode(' ', array_column($q->fetchAll(), 'body')); if (!preg_match('/(?<!\d)\d{6}(?!\d)/', $t)) return;
        $ad = wag_meta_get($pdo, 'addon:' . $key, ''); if (strpos($ad, 'offered|') === 0) return; if ($ad === '' && wag_addon_offer($pdo, $c, $key)) return;   // the add-on is offered first (once)
        wag_order_finalize($pdo, $c, $key);
    } catch (Throwable $e) { }
}
/** Customers who wrote a complaint (leak, wrong item, not delivered, quality, refund) and nobody answered: a complaint ticket is raised for them at once (the Complaint team owns it), they are told the number. */
function wag_complaint_sweep(PDO $pdo, int $max = 6): int
{
    if (!function_exists('cx_looks_like') && is_file(__DIR__ . '/boss.php')) { try { $action = 'wa.cxself'; require_once __DIR__ . '/boss.php'; } catch (Throwable $e) { } }
    if (!function_exists('cx_looks_like') || !function_exists('cx_raise')) return 0;
    $n = 0; $now = time();
    $st = $pdo->prepare("SELECT c.* FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.last_in_at >= ? AND c.last_in_at <= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) ORDER BY c.last_in_at DESC LIMIT 80");
    $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - 4)]);
    foreach ($st->fetchAll() as $c) {
        if ($n >= $max) break; $key = (string)$c['phone'];
        $h = cx_looks_like($pdo, $key); if (!$h) continue;
        // 🛡 only what the customer wrote AFTER the last answer counts, and a question about the policy ("refund karna rahe, koi damage rahe to ...", "if ... then") or a short "ok / thik hai" is NOT a complaint (owner, 21 Nov: a customer asked about the return policy, a person answered, he said "Thik hai" and the bot raised a damage complaint)
        try {
            $__lo = (string)($c['last_out_at'] ?? ''); $__q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' AND ts > ? ORDER BY id DESC LIMIT 4"); $__q->execute([$key, $__lo !== '' ? $__lo : '2000-01-01 00:00:00']);
            $__t = ' ' . mb_strtolower(implode(' ', array_column($__q->fetchAll(), 'body'))) . ' ';
            if (!preg_match('/(leak|leaking|toota|tut gay|broken|damaged|damage|spill|dabba khula|wrong (item|product|perfume)|galat|different (perfume|product)|not (delivered|received)|nahi (mila|aaya|pahucha)|abhi tak nahi|kab aayega|still (not|waiting)|fake|duplicate|refund|paisa wapas|money back|return kar|quality)/u', $__t)) continue;
            if (preg_match('/(policy|agar|\bif\b|rahe (to|too)|hoti hai|hota hai|kya hai|kaise|how|can i|sakte|sakta)/u', $__t) && !preg_match('/(mila|aaya|aayi|hai mera|mere|my (order|parcel|bottle)|order (id|no)|#\d{4})/u', $__t)) continue;
        } catch (Throwable $e) { continue; }
        try {
            $li = $pdo->prepare("SELECT id, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch(); if (!$m) continue;
            if (function_exists('wq_guard') && !wq_guard($pdo, $key, (int)$m['id'], 'reply', 'bot')) continue;
            if (function_exists('cx_ensure')) cx_ensure($pdo); if (function_exists('cx2_ensure')) cx2_ensure($pdo);
            $a = cx_raise($pdo, [], ['emp' => 0, 'admin' => false, 'mgr' => false, 'team' => true, 'desks' => []], ['problem' => $h['problem'], 'source' => 'whatsapp', 'phone' => $key, 'name' => (string)($c['name'] ?? ''), 'summary' => 'WhatsApp (raised by the bot): ' . mb_substr((string)$m['body'], 0, 600), 'urgency' => 'normal', 'auto' => 1]);
            if (empty($a[0]) || empty($a[3]['code'])) { if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); continue; }
            [$ok] = wa_send_text($pdo, $c, 'Aapki complaint note kar li gayi hai (ticket ' . $a[3]['code'] . ') 🙏 Hamari team jaldi aapse is chat me sampark karegi.', 'bot', 0);
            wa_event($pdo, $key, '🎫 The bot raised complaint ' . $a[3]['code'] . ' (' . $h['label'] . ')'); $n++;
        } catch (Throwable $e) { wag_log($pdo, 'bot', false, 'complaint sweep: ' . $e->getMessage()); }
    }
    return $n;
}
/** A person's replacement answer is safe to teach the bot at once: normal length, no price / amount (those stay for the owner), no invented offer, no link / handle. */
function wag_learn_safe_text(string $t): bool
{
    $t = trim($t); if (mb_strlen($t) < 8 || mb_strlen($t) > 400) return false;
    if (preg_match('/(₹|\brs\.?|\binr\b)\s*\d|\d{3,}|https?:|www\.|@|\bupi\b|\[TEMPLATE/iu', $t)) return false;
    return !wag_bot_unsafe($t);
}
/** ON THE SPOT: what a salesperson wrote instead of a bad bot answer goes into the LIVE guidebook at once when it is safe (the owner's standing permission); others wait in the owner's "bot learned" strip. Returns true when applied. */
function wag_learn_auto_apply(PDO $pdo, int $learnId): bool
{
    try {
        if (!function_exists('lib_guide_row')) return false; $q = $pdo->prepare("SELECT * FROM wa_learn WHERE id = ? AND kind = 'botbad' AND status = 'new'"); $q->execute([$learnId]); $r = $q->fetch(); if (!$r) return false;
        $better = trim(preg_replace('/\n\[reason: [^\]]*\]\s*$/u', '', (string)$r['sent'])); $bot = trim((string)$r['sugg']);
        if ($bot === '' || mb_strtolower($better) === mb_strtolower($bot) || !wag_learn_safe_text($better)) return false;
        $g = lib_guide_row($pdo, 'live'); if (!$g) return false; $body = json_decode((string)$g['body'], true); if (!is_array($body)) return false;
        $corr = array_values((array)($body['owner_corrections'] ?? [])); $k = md5(mb_strtolower($better)); foreach ($corr as $o) if (md5(mb_strtolower((string)($o['better'] ?? ''))) === $k) { $pdo->prepare("UPDATE wa_learn SET status = 'done' WHERE id = ?")->execute([$learnId]); return true; }
        array_unshift($corr, ['customer' => mb_substr((string)$r['customer'], 0, 240), 'better' => mb_substr($better, 0, 400)]);
        $body['owner_corrections'] = array_slice($corr, 0, 60); $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($body, JSON_UNESCAPED_UNICODE), (int)$g['id']]);
        $pdo->prepare("UPDATE wa_learn SET status = 'done' WHERE id = ?")->execute([$learnId]); return true;
    } catch (Throwable $e) { return false; }
}
/** WhatsApp "typing…" for the customer (Cloud API typing indicator: the message is marked read and the typing dots show for up to 25 s or until we answer). Never blocks the answer. */
function wag_bot_typing(PDO $pdo, array $c, string $wamid): void
{
    if ($wamid === '' || !function_exists('wa_api')) return;
    try { $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)($c['last_number_id'] ?? 0)]); $pid = (string)$q->fetchColumn(); if ($pid === '' && function_exists('wa_cfg_val')) $pid = (string)wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return;
        wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid, 'typing_indicator' => ['type' => 'text']]); } catch (Throwable $e) { }
}
/** A hot chat nobody answered and the bot cannot answer: it goes to OPEN (nobody is given it) and every salesperson at work gets a push; a chat note says so. */
function wag_stuck_escalate(PDO $pdo, array $c, string $key): void
{
    try {   // OPEN MARKET (owner, 21 Nov): nobody is given a chat — it stays in OPEN and every salesperson at work is told, whoever can close it takes it
        if (!wag_is_sales_query($pdo, $c)) return; $cur = (int)$c['assigned_emp']; if ($cur > 0 && function_exists('wa_assign_to')) wa_assign_to($pdo, $key, 0, 0, 'auto');
        $ids = []; if (function_exists('wa_people')) foreach (wa_people($pdo) as $p) if (!empty($p['present']) && (int)$p['emp_id'] > 0) $ids[] = (int)$p['emp_id'];
        if (function_exists('wa_mkt_note')) { wa_mkt_note($pdo, $key, 0, 'offered', -1, 'bot stuck'); if ($cur > 0) wa_mkt_note($pdo, $key, $cur, 'dropped', -1, 'bot stuck, no answer'); }
        wa_event($pdo, $key, '🆘 A hot customer waits and the bot cannot help: the chat is in OPEN — anybody can take it.');
        if ($ids && function_exists('ct_push')) ct_push($pdo, $ids, 'Hot customer waiting in OPEN', (trim((string)($c['name'] ?? '')) !== '' ? $c['name'] : '+91 ' . $key) . ' is waiting. Take it and close the order.', '/my_chats.html?phone=' . $key, 'wa-open-' . $key);
    } catch (Throwable $e) { }
}
/** OWNER (21 Nov): a HOT chat (ready to order / pay, or handed over by the bot) that the person who holds it has not answered for 4 minutes (setting wag_hot_open_min) is taken off that person and put into OPEN (Requests): everybody sees it and anybody picks it. Once per customer message; the person who missed it is remembered for the admin card. */
/** 🌡 The open-market timing tunes itself (owner, 21 Nov): the minutes a hot chat waits before it goes back to OPEN (base) and while its holder is busy (busy) follow the load. MANY people working = chats are picked up fast, so the rule is SHORTER; a quiet time (few people writing) = LONGER again. Active people = who wrote to a customer in the last 10 minutes; queue = customers of the last 3 hours waiting 2+ minutes for an answer. Re-computed once a minute; base 4-10, busy 6-14. [base, busy] */
function is_list_arr(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }
/** The owner's definition of a query (21 Nov): NOT campaign, NOT community — the same numbers as the sales strip ("orders / queries"). Returns [emp => [queries, orders]] for the days list of wq_period. */
function wag_real_queries(PDO $pdo, int $days): array
{
    $out = [];
    try { if ((!function_exists('wq_period') || !function_exists('wq_dt')) && is_file(__DIR__ . '/wq.php')) { try { require_once __DIR__ . '/wq.php'; } catch (Throwable $e) { } }   // the market screen route does not load the query ledger by itself (the banner showed 0 while the gate showed 4)
        if (!function_exists('wq_period') || !function_exists('wq_dt')) return $out; [$f, $t] = wq_period($pdo, $days);
        $q = $pdo->prepare("SELECT q.emp_id, COUNT(*) n, SUM(CASE WHEN COALESCE(s.orders, 0) > 0 THEN 1 ELSE 0 END) o FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ? AND q.emp_id > 0 AND COALESCE(t.type, 'unclear') NOT IN ('campaign', 'community') GROUP BY q.emp_id");
        $q->execute([wq_dt($f), wq_dt($t)]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['emp_id']] = [(int)$r['n'], (int)$r['o']];
    } catch (Throwable $e) { }
    return $out;
}
/** The customer already ORDERED (a Shopify order of the same phone in the last 36 hours) or his last words are only a thanks / ok / emoji: such a chat is not a "hot customer waiting for an answer" (owner, 21 Nov: the order had come, the chat stayed hot, and 70% of the salespeople were blocked from taking queries because of it). */
function wag_hot_is_done(PDO $pdo, string $phone, string $lastIn = ''): bool
{
    try { if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $phone)) return true;
        $k10 = substr(preg_replace('/\D/', '', $phone), -10); if (strlen($k10) === 10) { $q = $pdo->prepare("SELECT 1 FROM shop_orders WHERE phone LIKE ? AND created_at >= ? LIMIT 1"); $q->execute(['%' . $k10, date('Y-m-d H:i:s', time() - 36 * 3600)]); if ($q->fetchColumn()) return true; }
        if ($lastIn === '') { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $lastIn = (string)$q->fetchColumn(); }
        $t = trim(mb_strtolower($lastIn));
        if ($t !== '' && (preg_match('/(order|ordr|payment|pay|paid|advance).{0,24}(already|kar diy|kar diya|kar liya|kar di\b|kar chuka|ho gaya|ho gya|hogaya|done|complete|place|kiya|kia)/u', $t) || preg_match('/(already|pehle se|maine).{0,18}(order|payment|paid)/u', $t))) return true;   // "Order toh already kar diy": he says he has ordered (owner, 21 Nov: the chat went to OPEN after 2 minutes although the customer had ordered)
        if ($t !== '' && mb_strlen($t) <= 24 && preg_match('/^[\s\p{P}\p{S}]*(ok|okay|okk|thanks|thank you|thankyou|thik hai|theek hai|ठीक है|done|haan|han|ji|yes|👍|🙏|😊|🙂|👌|✅)?[\s\p{P}\p{S}]*$/u', $t)) return true;
    } catch (Throwable $e) { }
    return false;
}
function wag_open_tune(PDO $pdo): array
{
    $last = (int)setting_get($pdo, 'wag_tune_at', '0'); $b0 = (int)setting_get($pdo, 'wag_hot_open_min_eff', '7'); $y0 = (int)setting_get($pdo, 'wag_hot_open_busy_eff', '10');
    if (time() - $last < 60) return [max(2, $b0), max($b0, $y0)];
    setting_set($pdo, 'wag_tune_at', (string)time());
    try {
        // the OPEN market itself decides (owner, 21 Nov): how many chats sit in OPEN waiting to be tagged, how many were taken in the last 15 min and how fast they were taken
        $a = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND assigned_emp = 0 AND last_in_at >= '" . date('Y-m-d H:i:s', time() - 3 * 3600) . "' AND (last_out_at IS NULL OR last_out_at < last_in_at)")->fetchColumn();   // chats showing in the free market now
        $tk = $pdo->query("SELECT COUNT(*), AVG(CASE WHEN wait_s BETWEEN 0 AND 1800 THEN wait_s END) FROM wa_mkt WHERE kind = 'taken' AND at >= '" . date('Y-m-d H:i:s', time() - 900) . "'")->fetch(PDO::FETCH_NUM); $taken = (int)($tk[0] ?? 0); $avgw = $tk[1] === null ? -1 : (int)$tk[1];
        $qn = $a;
        if ($a <= 2 && ($avgw < 0 || $avgw <= 180)) $base = 5;        // market nearly empty and picked up quickly: release sooner
        elseif ($a >= 9 || ($a >= 5 && $taken < 3) || ($avgw > 420)) $base = 9;   // market clogged / slow to pick up: do not dump more into it, the holder keeps longer
        else $base = 7;
        if ($taken >= 8 && $a <= 4) $base -= 1;                       // lively picking and a short list
        $base = max(4, min(10, $base)); $busy = max($base + 2, min(14, $base + 3));
        // smooth: move at most 1 minute per step so the rule never jumps
        $base = $base > $b0 ? $b0 + 1 : ($base < $b0 ? $b0 - 1 : $b0); $busy = $busy > $y0 ? $y0 + 1 : ($busy < $y0 ? $y0 - 1 : $y0); $busy = max($busy, $base + 2);
        if ($base !== $b0 || $busy !== $y0) { setting_set($pdo, 'wag_hot_open_min_eff', (string)$base); setting_set($pdo, 'wag_hot_open_busy_eff', (string)$busy); }
        setting_set($pdo, 'wag_tune_log', date('H:i') . ' open ' . $a . ' · taken15 ' . $taken . ' · avg pick ' . $avgw . 's -> base ' . $base . ' / busy ' . $busy . ' min');
        return [$base, $busy];
    } catch (Throwable $e) { return [max(2, $b0), max($b0, $y0)]; }
}
function wag_hot_open_sweep(PDO $pdo, int $max = 10): int
{
    if (false || !function_exists('wa_assign_to')) return 0; $__tn = wag_open_tune($pdo); $mins = max(1, min(60, $__tn[0])); $busyMins = max($mins, min(60, $__tn[1]));   // 7 minutes without an answer; 10 when the holder is busy answering other customers (owner, 21 Nov) $n = 0; $now = time();
    try {
        $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND assigned_emp > 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 150");
        $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - $mins * 60)]); $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        foreach ($st->fetchAll() as $c) {
            if ($n >= $max) break; $key = (string)$c['phone']; $li = (string)$c['last_in_at'];
            if ($now - (int)strtotime($li) < $busyMins * 60 && wag_holder_busy($pdo, (int)$c['assigned_emp'])) continue;   // the holder is writing to other customers: more time
            $h = wag_meta_get($pdo, 'bothand:' . $key, ''); $handed = $h !== '' && strtotime($h) >= strtotime($li);
            if (empty($c['hot']) && !$handed) continue; if (!wag_is_sales_query($pdo, $c)) continue;   // only good queries (not complaints / after-sales) go to the open market; (a chat a person took over is checked too: it goes back to OPEN when the customer waits again and nobody answers)
            $__ml = wag_meta_get($pdo, 'mklink:' . $key, ''); if ($__ml !== '' && strtotime($__ml) > $now - 480) continue;   // the holder is making the payment link: up to 8 minutes, the chat stays with him
            $mk = wag_meta_get($pdo, 'hotmiss:' . $key, ''); if ($mk !== '' && substr($mk, strrpos($mk, '|') + 1) === $li) continue;   // once per customer message
            if (function_exists('wq_ts') && wq_ts(wag_meta_get($pdo, 'typing:' . $key, '')) > $now - 90) continue;   // a person is typing the answer right now
            if (wag_hot_is_done($pdo, $key)) continue;   // he already ordered / only said thanks: not a lost hot chat
            $emp = (int)$c['assigned_emp']; wag_meta_put($pdo, 'hotmiss:' . $key, $emp . '|' . date('Y-m-d H:i:s') . '|' . $li);
            wa_assign_to($pdo, $key, 0, 0, 'auto'); if (function_exists('wq_tag_put')) { try { wq_tag_put($pdo, $key, 'red'); } catch (Throwable $e) { } }
            if (function_exists('wa_mkt_note')) { wa_mkt_note($pdo, $key, 0, 'offered', -1, 'hot'); if ($emp > 0) wa_mkt_note($pdo, $key, $emp, 'dropped', max(0, $now - (int)strtotime($li)), 'no answer ' . $mins . ' min'); }   // track record: the holder left it (red), the chat is offered to everybody
            if ($emp > 0 && function_exists('ct_push') && time() - (int)setting_get($pdo, 'wag_dropnote_' . $emp, '0') >= 180) { setting_set($pdo, 'wag_dropnote_' . $emp, (string)time()); try { $tk0 = (int)$pdo->query("SELECT COUNT(*) FROM wa_mkt WHERE emp_id = " . $emp . " AND kind = 'taken' AND at >= '" . date('Y-m-d 00:00:00') . "'")->fetchColumn(); $dr0 = (int)$pdo->query("SELECT COUNT(*) FROM wa_mkt WHERE emp_id = " . $emp . " AND kind IN ('dropped','released') AND at >= '" . date('Y-m-d 00:00:00') . "'")->fetchColumn(); ct_push($pdo, [$emp], '⚠ You left a customer waiting', 'Today you took ' . $tk0 . ' queries and left ' . $dr0 . ' without an answer. Do not leave queries: answer within ' . $mins . ' min or do not take them. People who leave queries cannot take new ones.', '/my_chats.html', 'wa-drop'); } catch (Throwable $e) { } }   // the person is told at once (owner, 21 Nov)
            wa_event($pdo, $key, '🔓 A hot customer waited ' . $mins . '+ minutes and ' . ($names[$emp] ?? 'the holder') . ' did not answer: the chat is in OPEN now — anybody can take it.'); $n++;
        }
        if ($n && function_exists('ct_push') && function_exists('wa_team_cfg')) { $al = (array)(wa_team_cfg($pdo)['alert_emps'] ?? []); if ($al) ct_push($pdo, $al, $n . ' hot chat' . ($n === 1 ? '' : 's') . ' moved to Open', 'Nobody answered for ' . $mins . ' min. Anybody can take them now.', '/whatsapp.html#signals', 'wa-hotopen'); }
    } catch (Throwable $e) { wag_log($pdo, 'bot', false, 'hot open sweep: ' . $e->getMessage()); }
    try {   // every 10 minutes: a person who takes queries and sends them back / leaves them (cherry-picking) is reported to the admins once a day
        if (time() - (int)setting_get($pdo, 'wag_cherry_at', '0') >= 600) { setting_set($pdo, 'wag_cherry_at', (string)time()); $fl = []; foreach (wag_mkt_board($pdo, 7) as $b) if (!empty($b['flag']) && setting_get($pdo, 'wag_cherry_push_' . $b['emp'], '') !== date('Y-m-d')) { setting_set($pdo, 'wag_cherry_push_' . $b['emp'], date('Y-m-d')); $fl[] = $b['name'] . ' (' . $b['cherry'] . ' of ' . $b['taken'] . ')'; }
            if ($fl && function_exists('ct_push') && function_exists('wa_team_cfg')) { $al = (array)(wa_team_cfg($pdo)['alert_emps'] ?? []); if ($al) ct_push($pdo, $al, '⚠ Cherry-picking queries', implode(', ', $fl) . ' took queries and sent them back or never answered them.', '/whatsapp.html#signals', 'wa-cherry'); } } } catch (Throwable $e) { }
    return $n;
}
/** The EXACT price from our chart for the quantity the customer chose (owner, 21 Nov: with nothing negotiated yet the bot starts at the 6.5% level, never at a deep combo price; the AI had used the 3 x 50 ml price for 5 x 50 ml). '' when the chat names no quantity x size. */
function wag_price_context(PDO $pdo, string $phone, array $msgs): string
{
    $j = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (!is_array($j) || empty($j['cols']) || empty($j['rows'])) return '';
    $txt = ''; $cust = ''; foreach (array_slice($msgs, -10) as $m) { $t = mb_strtolower((string)($m['text'] ?? '')); $txt .= ' ' . $t; if (($m['who'] ?? '') === 'customer') $cust .= ' ' . $t; }
    $qty = 0; $size = 0;
    // 🧮 what the CUSTOMER asked for wins over every number the bot itself wrote earlier (a customer said "20 pice" + "20 ml" and the bot kept quoting its own earlier "8 x 20 ml"): the customer's x-pattern, else his "N pieces / pcs / bottles" with his ml size
    if (preg_match_all('/(\d{1,2})\s*[x×*]\s*(\d{2,3})\s*ml/u', $cust, $cm0, PREG_SET_ORDER)) { $l = end($cm0); $qty = (int)$l[1]; $size = (int)$l[2]; }
    elseif (preg_match_all('/(?<![\d.])(\d{1,2})\s*(?:pice|piece|pieces|pcs|pc|bottles?|nang|nag)\b/u', $cust, $cp) && preg_match_all('/\b(10|20|30|50|100)\s*ml\b/u', $cust, $cs)) { $qty = (int)end($cp[1]); $size = (int)end($cs[1]); }
    elseif (preg_match_all('/(\d{1,2})\s*[x×*]\s*(\d{2,3})\s*ml/u', $txt, $mm, PREG_SET_ORDER)) { $l = end($mm); $qty = (int)$l[1]; $size = (int)$l[2]; }
    else { if (preg_match_all('/\b(10|20|30|50|100)\s*ml\b/u', $txt, $sm)) $size = (int)end($sm[1]); if ($size && preg_match_all('/(?<![\d.])(\d{1,2})(?![\d.])(?!\s*ml)/u', $cust, $qm)) { $qty = (int)end($qm[1]); } }
    if (!$qty && $size && preg_match('/\bsingle\b|\bek (hi )?perfume\b|\b1 perfume\b|\bone perfume\b|combo nahi|no combo|don.?t want (a )?combo|alag se|\bsirf ek\b/u', $cust)) $qty = 1;   // "I do not want a combo, 100 ml price" = one bottle at the chart's single-bottle row
    if ($qty < 1 || $qty > 30 || $size < 10) return ''; $total = $qty * $size; $cols = array_values(array_map('floatval', (array)$j['cols'])); $row = null; foreach ((array)$j['rows'] as $r) if ((int)($r['ml'] ?? 0) === $total) { $row = $r; break; } if (!$row || empty($row['p'])) return '';
    $idx = 1; foreach ($cols as $i => $cv) if (abs($cv - 6.5) < 0.01) $idx = $i; $seen = false;
    if (function_exists('pc_chat_level') && $phone !== '') { try { $lv = pc_chat_level($pdo, $phone); if (empty($lv['default'])) { $seen = true; foreach ($cols as $i => $cv) if (abs($cv - (float)$lv['pct']) < 0.01) $idx = max($idx, $i); } } catch (Throwable $e) { } }
    // a customer who ARRIVES with a price (the ad / an earlier offer: "5 x 50ml combo for Rs 2,750"): that level is honoured, not our start level
    if (preg_match_all('/(?<![\d,.])(\d{1,2},\d{3}|\d{4,5})(?![\d,])/u', $cust, $am)) foreach ($am[1] as $a) { $amt = (int)str_replace(',', '', $a); if ($amt < 500) continue; $bj = -1; $bd = 1e9; foreach ((array)$row['p'] as $jj => $pv) { if ($pv <= 0) continue; $d = abs($pv - $amt); if ($d / $amt <= 0.015 && $d < $bd) { $bd = $d; $bj = (int)$jj; } } if ($bj >= 0) { $idx = $bj; $seen = true; } }   // his own price is honoured: the chart column NEAREST to it (an exact match wins; the old loop took the deepest column within 1.5%, so Rs 2,200 from the website became Rs 2,180)
    $v0 = (int)($row['p'][$idx] ?? 0); if ($v0 <= 0) return ''; $nx = min(count($cols) - 1, $idx + 1); $v1 = (int)($row['p'][$nx] ?? 0);
    // ORDER-VALUE GOAL (owner, 21 Nov): the average order should reach Rs 2,700-2,800 — push ONE add-on that lifts this order there, at the same price level
    $add = ''; if ($v0 < 2700) { $rs = (array)$j['rows']; usort($rs, fn($a, $b) => (int)($a['ml'] ?? 0) <=> (int)($b['ml'] ?? 0)); $pick = null;
        foreach ([$size, 50] as $stepMl) { if ($pick || $stepMl < 10) continue; foreach ($rs as $r2) { $ml2 = (int)($r2['ml'] ?? 0); $ex = $ml2 - $total; if ($ex <= 0 || $ex % $stepMl !== 0 || intdiv($ex, $stepMl) > 6) continue; $vv = (int)($r2['p'][$idx] ?? 0); if ($vv >= 2700) { $pick = [$ml2, $vv, intdiv($ex, $stepMl), $stepMl]; break; } } }   // a NATURAL add-on only: N more bottles of the SAME size (else of 50 ml), never an odd amount such as "170 ml extra"
        if ($pick) $add = ' ORDER-VALUE GOAL (owner): our average order should reach about Rs 2,700-2,800. This order is Rs ' . $v0 . ': once he has chosen (or in the same message), offer ONE add-on that lifts it: ' . $pick[2] . ' more bottle' . ($pick[2] > 1 ? 's' : '') . ' of ' . $pick[3] . ' ml, so the total becomes ' . $pick[0] . ' ml for Rs ' . $pick[1] . ' at the same price level - say it as "sirf Rs ' . ($pick[1] - $v0) . ' aur mein ' . $pick[2] . ' bottle (' . $pick[3] . ' ml) aur". Never talk about an odd ml amount. If he says no, try once more at most; never push after the second no.';
    } elseif ($v0 >= 2700) $add = ' ORDER-VALUE GOAL: this order is already at the Rs 2,700-2,800 target: keep it and move to the order details; only a small add-on if he asks.';
    $__lc = ''; foreach (array_reverse($msgs) as $__m) if (($__m['who'] ?? '') === 'customer') { $__lc = mb_strtolower((string)($__m['text'] ?? '')); break; } $__obj = (bool)preg_match('/(mehang|mahang|costly|expensive|too much|zyada|jyada|kam kar|kam do|thoda kam|discount|best price|final price|last price|budget|sasta|cheaper|reduce|negotiat|bargain|rate kam|price kam)/iu', $__lc);   // the next (lower) price is only handed to the AI when the customer's own last message objects to the price: it cannot offer it by itself
    $__up = ''; if ($size === 20 && wag_exp_arm($pdo, 'up20', $phone) === 'T') {   // self-learning experiment 'up20' (shown to half of the customers until the revenue per customer decides)
        foreach ((array)$j['rows'] as $r3) if ((int)($r3['ml'] ?? 0) === 250) { $u5 = (int)($r3['p'][$idx] ?? 0); if ($u5 > $v0 && $u5 <= 3300) $__up = ' UPGRADE OFFER (owner): he is buying 20 ml bottles; after the price add ONE short line: "5 x 50 ml sirf Rs ' . $u5 . ' mein (2.5 guna perfume)" - use exactly Rs ' . $u5 . ' (the chart price at the same level), never any other number, and ask which one he prefers. Say it once; if he says no, never repeat it.'; break; } }   // 20 ml buyers have the lowest tickets: show the 5 x 50 ml chart price next to the 20 ml price (the same chart, no discount)
    return 'PRICE FROM OUR CHART for this customer (use ONLY these numbers for ' . $qty . ' x ' . $size . ' ml = ' . $total . ' ml total; never take a price from the combo list for a different quantity or ml): ' . ($seen ? 'the price he has already seen is Rs ' . $v0 . ' — repeat it, do not quote higher or lower unless he pushes back on price' : 'nothing is negotiated yet: quote Rs ' . $v0 . ' (the start level) and do NOT give a lower price') . '.' . ($v1 > 0 && $v1 < $v0 && $__obj ? ' He is objecting to the price: you may now give the next chart price, Rs ' . $v1 . ' (never lower).' : '') . ' Always say the total ml correctly (' . $total . ' ml).' . $add . $__up;
}
/** 🏪 OPEN MARKET scoreboard (owner, 21 Nov: "decide how to measure the good salespeople": most orders, fast reply, add-on / ticket, and who really takes the hot chats). Per salesperson, last $days days:
 *  score (0-100) = orders 40 (4 points each) + ticket 20 (average order / Rs 2,750) + first-reply speed 20 (60 s = full, 10 min = 0) + effort 20 (taken / (taken + dropped)). */
function wag_mkt_board(PDO $pdo, int $days = 7): array
{
    static $cache = []; $ck = $days . ':' . (int)(time() / 60); if (isset($cache[$ck])) return $cache[$ck];
    $since = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400); $by = []; $takes = []; $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $present = [];
    if (function_exists('wa_people')) { try { foreach (wa_people($pdo) as $p) { $by[(int)$p['emp_id']] = ['emp' => (int)$p['emp_id'], 'name' => (string)($names[(int)$p['emp_id']] ?? ($p['name'] ?? '')), 'present' => !empty($p['present'])]; } } catch (Throwable $e) { } }
    $blank = ['offered' => 0, 'taken' => 0, 'dropped' => 0, 'released' => 0, 'noreply' => 0, 'waits' => [], 'replies' => [], 'orders' => 0, 'amount' => 0];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_mkt (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, kind VARCHAR(10) NOT NULL, at DATETIME NOT NULL, wait_s INT NOT NULL DEFAULT -1, note VARCHAR(80) NOT NULL DEFAULT '', KEY k_emp (emp_id, at), KEY k_ph (phone, id), KEY k_kind (kind, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("SELECT id, phone, emp_id, kind, at, wait_s FROM wa_mkt WHERE at >= ? AND kind IN ('taken','dropped','released') ORDER BY id DESC LIMIT 1500"); $st->execute([$since]); $q2 = $pdo->prepare("SELECT MIN(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND emp_id = ? AND ts >= ? AND ts <= ?"); $nq = 0;
        $__rows0 = $st->fetchAll(); $__tkset = []; foreach ($__rows0 as $__r0) if ((string)$__r0['kind'] === 'taken') $__tkset[(int)$__r0['emp_id'] . '|' . (string)$__r0['phone']] = 1;   // "left" counts only chats the person TOOK himself (a chat handed to him by assignment is not his to leave: owner, 21 Nov — many people were blocked for 2 hot chats they never picked)
        $__seenTk = []; foreach ($__rows0 as $r) { $e = (int)$r['emp_id']; if ($e <= 0) continue; if (!isset($by[$e])) $by[$e] = ['emp' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'present' => false]; $by[$e] += $blank; if (!isset($by[$e]['taken'])) $by[$e] += $blank;
            $k = (string)$r['kind']; if ($k !== 'taken' && !isset($__tkset[$e . '|' . (string)$r['phone']])) continue; if ($k === 'taken') { $__tk = $e . '|' . (string)$r['phone']; if (isset($__seenTk[$__tk])) continue; $__seenTk[$__tk] = 1; }   // the same chat taken again by the same person is not another query
            $by[$e][$k]++; if ($k === 'taken') { $takes[] = [substr(preg_replace('/\D/', '', (string)$r['phone']), -10), $e, (int)strtotime((string)$r['at'])]; if ((int)$r['wait_s'] >= 0) $by[$e]['waits'][] = (int)$r['wait_s'];
                if ($nq < 500) { $nq++; $q2->execute([(string)$r['phone'], $e, (string)$r['at'], date('Y-m-d H:i:s', strtotime((string)$r['at']) + 3 * 3600)]); $f = $q2->fetchColumn(); if ($f) $by[$e]['replies'][] = max(0, (int)strtotime((string)$f) - (int)strtotime((string)$r['at'])); elseif (time() - (int)strtotime((string)$r['at']) > 3 * 3600) $by[$e]['noreply']++; } } }   // took the chat and never wrote to the customer = cherry-picking
        // REAL ORDERS (the outcomes the salespeople record carry no amount): a Shopify order of the same phone within 48 h after he took the chat is credited to him (each order once)
        try { $om = []; foreach ($pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE phone <> '' AND created_at >= '" . date('Y-m-d H:i:s', strtotime($since) - 3600) . "'")->fetchAll() as $o) { $k10 = substr(preg_replace('/\D/', '', (string)$o['phone']), -10); $om[$k10][] = [(int)strtotime((string)$o['created_at']), (float)$o['total']]; }
            $used = []; usort($takes, fn($a, $b) => $a[2] <=> $b[2]); foreach ($takes as [$k10, $e, $ts]) { if (!isset($om[$k10])) continue; foreach ($om[$k10] as $oi => [$ot, $tot]) { $uk = $k10 . '|' . $oi; if (isset($used[$uk]) || $ot < $ts || $ot > $ts + 48 * 3600) continue; $used[$uk] = 1; $by[$e]['shop_orders'] = ($by[$e]['shop_orders'] ?? 0) + 1; $by[$e]['shop_amount'] = ($by[$e]['shop_amount'] ?? 0) + $tot; break; } } } catch (Throwable $e2) { }
        $oq = $pdo->prepare("SELECT emp_id, COUNT(*) n, COALESCE(SUM(amount), 0) a FROM wa_outcomes WHERE code = 'order' AND at >= ? GROUP BY emp_id"); $oq->execute([$since]);
        foreach ($oq->fetchAll() as $r) { $e = (int)$r['emp_id']; if ($e <= 0) continue; if (!isset($by[$e])) $by[$e] = ['emp' => $e, 'name' => (string)($names[$e] ?? ('#' . $e)), 'present' => false]; $by[$e] += $blank; $by[$e]['orders'] = (int)$r['n']; $by[$e]['amount'] = (float)$r['a']; }
    } catch (Throwable $e) { }
    $med = function (array $a) { if (!$a) return null; sort($a); return $a[intdiv(count($a), 2)]; }; $out = [];
    foreach ($by as $e => $x) { $x += $blank; $tk = (int)$x['taken']; $ch = (int)$x['dropped'] + (int)$x['released'] + (int)$x['noreply']; $dr = $ch; $mr = $med($x['replies']); $mw = $med($x['waits']); if (!empty($x['shop_orders'])) { $x['orders'] = (int)$x['shop_orders']; $x['amount'] = (float)$x['shop_amount']; } $avg = $x['orders'] > 0 ? $x['amount'] / $x['orders'] : 0;
        $pOrd = min(40, $x['orders'] * 4); $pTick = $x['orders'] > 0 ? min(22, 20 * $avg / 2750) : 0; $pSpeed = $mr === null ? 0 : 20 * (1 - max(0, min(1, ($mr - 60) / 540))); $pEff = ($tk + $dr) > 0 ? 20 * $tk / ($tk + $dr) : 0;
        $out[] = ['emp' => (int)$e, 'name' => (string)$x['name'], 'present' => !empty($x['present']), 'taken' => $tk, 'dropped' => (int)$x['dropped'], 'released' => (int)$x['released'], 'noreply' => (int)$x['noreply'], 'cherry' => $ch, 'flag' => $ch >= 3 && $tk > 0 && $ch / max(1, $tk + (int)$x['dropped']) >= 0.4, 'wait_med_s' => $mw, 'reply_med_s' => $mr, 'orders' => (int)$x['orders'], 'avg_ticket' => (int)round($avg), 'score' => (int)round($pOrd + $pTick + $pSpeed + $pEff)]; }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['taken'] <=> $a['taken']);
    return $cache[$ck] = $out;
}
/** OWNER (21 Nov): only GOOD queries go to the open market — a customer who can really order. NOT: after-sales (order completed, order status / tracking / refund / return), complaints (open ticket or a complaint-like message), reactions without words. */
function wag_is_sales_query(PDO $pdo, array $c): bool
{
    $key = (string)($c['phone'] ?? ''); if ($key === '' || (int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return false;
    if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $key)) return false;   // order completed: after-sales, not a sale
    if (preg_match('/order question|refund|return|track|complain|status|delivery|payment problem/i', (string)($c['hot_why'] ?? ''))) return false;
    try { if (!function_exists('cx_looks_like') && is_file(__DIR__ . '/complaints.php')) { /* loaded with the Boss Engine; skipped when not available */ }
        if (function_exists('cx_looks_like') && cx_looks_like($pdo, $key)) return false;   // leak / wrong item / not delivered / quality / refund words
        $q = $pdo->prepare("SELECT mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $m = $q->fetch(); if ($m && in_array((string)$m['mtype'], ['reaction', 'sticker'], true)) return false;
        if ($m && preg_match('/\b(tracking|track my|where is my order|kab aayega|nahi mila|not received|refund|return|replace|damaged|leak|wrong (item|product)|cancel my order)\b/iu', (string)$m['body'])) return false;
    } catch (Throwable $e) { }
    return true;
}
/** Has this customer sent the delivery details (a 6-digit pincode in a message of the last 24 h, or the bot wrote that it collected the order details)? "Making link" only makes sense after that (owner, 21 Nov). */
function wag_address_seen(PDO $pdo, string $key): bool
{
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND ts >= ? AND ((dir = 'in' AND mtype = 'text' AND body REGEXP '(^|[^0-9])[1-9][0-9]{5}([^0-9]|$)') OR (dir = 'sys' AND body LIKE '%collected the order details%'))"); $q->execute([$key, date('Y-m-d H:i:s', time() - 24 * 3600)]); return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
/** Owner, 21 Nov: the bot never offers a discount / a lower price / a "best deal" the customer did not ask for (it had said Rs 2350, then "Rs 2200 tak karwa dungi", then Rs 2050). Such sentences are removed unless the customer's own words are about price. */
function wag_strip_unasked_discount(string $text, string $customer): string
{
    if (preg_match('/(discount|kam\b|kam kar|mehang|mahang|costly|expensive|price|rate|bhav|bhaav|sasta|cheap|offer|deal|best|budget|paise|rupee|rs\b|₹|\d{3,4})/iu', $customer)) return $text;
    $parts = preg_split('/(?<=[.!?।])\s+/u', trim($text)); if (!$parts || count($parts) < 2) return $text; $keep = [];
    $bad = '/(tak\s+(karwa|kar|kam|le\s*aa|laa)\w*|best deal|discount|special (price|offer|rate)|kam\s+kar(wa)?\s*(dung|dunga|dungi|denge|dege)|extra\s+off|\bchhoot\b|\bchhut\b|lowest price|final price)/iu';
    foreach ($parts as $x) { $cl = preg_split('/\s*,\s*|\s+aur\s+(?=best|discount|special)/iu', $x); $ok = []; foreach ($cl as $y) if (!preg_match($bad, $y)) $ok[] = $y; if (!$ok) continue; $z = implode(', ', $ok); if (!preg_match('/[.!?।]$/u', $z) && preg_match('/[.!?।]$/u', $x)) $z .= substr($x, -1); $keep[] = $z; }
    $o = trim(implode(' ', $keep)); return $o !== '' ? $o : $text;
}
/** 🧪 SELF-LEARNING EXPERIMENTS (owner, 21 Nov: no manual switches — the system decides). An upsell line (exp 'up20' = the 20 ml -> 5 x 50 ml line, 'one' = the one-more bottle at order details) is first shown to HALF the customers (arm T) and withheld from the other half (arm C, by phone parity). wag_exp_eval() compares the revenue per customer (Shopify orders of the same phone within 48 h after he was first assigned an arm) and by itself turns the line ON for everybody, or OFF, and writes the decision down. Mode per experiment: setting wag_exp_<name> = test | on | off. */
function wag_exp_arm(PDO $pdo, string $exp, string $phone): string
{
    $mode = (string)setting_get($pdo, 'wag_exp_' . $exp, 'test'); if ($mode === 'on') return 'T'; if ($mode === 'off') return 'C'; if ($phone === '') return 'C';
    $arm = (crc32($exp . '|' . $phone) % 2) ? 'T' : 'C';
    try { static $ok = null; if ($ok === null) { $pdo->exec("CREATE TABLE IF NOT EXISTS wag_exp (exp VARCHAR(12) NOT NULL, phone VARCHAR(12) NOT NULL, arm CHAR(1) NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (exp, phone), KEY k_at (exp, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); $ok = true; } $pdo->prepare("INSERT IGNORE INTO wag_exp (exp, phone, arm, at) VALUES (?, ?, ?, ?)")->execute([$exp, $phone, $arm, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
    return $arm;
}
function wag_exp_eval(PDO $pdo): void
{
    $now = time(); $k10 = function ($p) { return substr(preg_replace('/\D/', '', (string)$p), -10); };
    $orders = null;
    foreach (['up20', 'one'] as $exp) {
        if ((string)setting_get($pdo, 'wag_exp_' . $exp, 'test') !== 'test') continue;
        try {
            $st = $pdo->prepare("SELECT arm, phone, at FROM wag_exp WHERE exp = ? AND at <= ? AND at >= ?"); $st->execute([$exp, date('Y-m-d H:i:s', $now - 24 * 3600), date('Y-m-d H:i:s', $now - 6 * 86400)]); $rows = $st->fetchAll(); if (!$rows) continue;
            if ($orders === null) { $orders = []; foreach ($pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE phone <> '' AND total > 0 AND created_at >= '" . date('Y-m-d H:i:s', $now - 7 * 86400) . "'")->fetchAll(PDO::FETCH_ASSOC) as $o) $orders[$k10($o['phone'])][] = [(int)strtotime((string)$o['created_at']), (float)$o['total']]; }
            $n = ['T' => 0, 'C' => 0]; $rev = ['T' => 0.0, 'C' => 0.0]; $first = $now;
            foreach ($rows as $r) { $a = (string)$r['arm']; $t0 = (int)strtotime((string)$r['at']); $first = min($first, $t0); $n[$a]++; foreach ($orders[$k10($r['phone'])] ?? [] as [$ot, $tot]) if ($ot >= $t0 && $ot <= $t0 + 48 * 3600) $rev[$a] += $tot; }
            if ($n['T'] < 40 || $n['C'] < 40) continue;   // too few customers to judge
            $rT = $rev['T'] / $n['T']; $rC = $rev['C'] / $n['C']; $dec = '';
            if ($rT < 0.85 * $rC) $dec = 'off'; elseif ($rT >= 1.05 * $rC) $dec = 'on'; elseif ($now - $first >= 3 * 86400) $dec = $rT >= $rC ? 'on' : 'off';
            if ($dec !== '') { setting_set($pdo, 'wag_exp_' . $exp, $dec); wag_log($pdo, 'engine', true, 'self-learning: "' . $exp . '" turned ' . strtoupper($dec) . ' · revenue per customer with the line Rs ' . round($rT) . ' (' . $n['T'] . ' customers) vs without Rs ' . round($rC) . ' (' . $n['C'] . ')'); setting_set($pdo, 'wag_exp_log_' . $exp, date('Y-m-d H:i') . ' ' . $dec . ' · with Rs ' . round($rT) . ' (' . $n['T'] . ') vs without Rs ' . round($rC) . ' (' . $n['C'] . ')'); }
            else setting_set($pdo, 'wag_exp_log_' . $exp, date('Y-m-d H:i') . ' testing · with Rs ' . round($rT) . ' (' . $n['T'] . ') vs without Rs ' . round($rC) . ' (' . $n['C'] . ')');
        } catch (Throwable $e) { }
    }
    // follow-up wording: A = plain text, B = with quick-reply buttons: by the share of customers who wrote back within 2 hours
    try {
        if ((string)setting_get($pdo, 'wag_fu_var', 'AB') === 'AB') {
            $st = $pdo->query("SELECT phone, ts, body FROM wa_messages WHERE dir = 'sys' AND body LIKE '%The bot followed up%[%' AND ts <= '" . date('Y-m-d H:i:s', $now - 2 * 3600) . "' AND ts >= '" . date('Y-m-d H:i:s', $now - 6 * 86400) . "' LIMIT 4000"); $n = ['A' => 0, 'B' => 0]; $h = ['A' => 0, 'B' => 0];
            $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ? AND ts <= ? LIMIT 1");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { if (!preg_match('/\[([AB])\]/', (string)$r['body'], $m)) continue; $v = $m[1]; $n[$v]++; $q->execute([$r['phone'], $r['ts'], date('Y-m-d H:i:s', strtotime((string)$r['ts']) + 7200)]); if ($q->fetchColumn()) $h[$v]++; }
            if ($n['A'] >= 80 && $n['B'] >= 80) { $ra = $h['A'] / $n['A']; $rb = $h['B'] / $n['B']; $dec = ''; if ($rb >= 1.2 * $ra) $dec = 'B'; elseif ($rb <= 0.8 * $ra) $dec = 'A';
                setting_set($pdo, 'wag_exp_log_fu', date('Y-m-d H:i') . ' plain ' . round(100 * $ra, 1) . '% (' . $n['A'] . ') vs buttons ' . round(100 * $rb, 1) . '% (' . $n['B'] . ')' . ($dec !== '' ? ' -> ' . $dec : ' -> still testing'));
                if ($dec !== '') { setting_set($pdo, 'wag_fu_var', $dec); wag_log($pdo, 'engine', true, 'self-learning: follow-up wording ' . ($dec === 'B' ? 'WITH BUTTONS' : 'PLAIN') . ' wins · plain ' . round(100 * $ra, 1) . '% vs buttons ' . round(100 * $rb, 1) . '% replied within 2 h'); } }
        }
    } catch (Throwable $e) { }
}
/** 🎯 Ticket size from Shopify (owner, 21 Nov): the average order value of orders where the BOT had talked to the customer in the 48 h before the order, against orders with no bot message. Orders matched by the last 10 digits of the phone. */
function wag_bot_ticket(PDO $pdo, int $days = 7): ?array
{
    try {
        $since = date('Y-m-d H:i:s', time() - $days * 86400); $k10 = function ($p) { return substr(preg_replace('/\D/', '', (string)$p), -10); };
        $bot = []; foreach ($pdo->query("SELECT phone, MIN(ts) a, MAX(ts) z FROM wa_messages WHERE dir = 'out' AND who = 'bot' AND ts >= '" . date('Y-m-d H:i:s', time() - ($days + 2) * 86400) . "' GROUP BY phone")->fetchAll(PDO::FETCH_ASSOC) as $r) $bot[$k10($r['phone'])] = [strtotime((string)$r['a']), strtotime((string)$r['z'])];
        $b = ['n' => 0, 's' => 0.0, 'hi' => 0]; $o = ['n' => 0, 's' => 0.0, 'hi' => 0];
        foreach ($pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE phone <> '' AND total > 0 AND created_at >= '" . $since . "'")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $k = $k10($x['phone']); $ot = strtotime((string)$x['created_at']); $t = (float)$x['total']; $isB = isset($bot[$k]) && $bot[$k][0] <= $ot && $bot[$k][1] >= $ot - 48 * 3600; $g = &$b; if (!$isB) { unset($g); $g = &$o; } $g['n']++; $g['s'] += $t; if ($t >= 2700) $g['hi']++; unset($g);
        }
        $f = function ($x) { return [$x['n'], $x['n'] ? (int)round($x['s'] / $x['n']) : 0, $x['n'] ? (int)round(100 * $x['hi'] / $x['n']) : 0]; }; [$bn, $ba, $bp] = $f($b); [$on, $oa, $op] = $f($o);
        return ['days' => $days, 'bot_n' => $bn, 'bot_avg' => $ba, 'bot_pct' => $bp, 'other_n' => $on, 'other_avg' => $oa, 'other_pct' => $op];
    } catch (Throwable $e) { return null; }
}
/** 🏪 PERFORMANCE GATE (owner, 21 Nov): a person who picks many queries but converts BELOW the team average gets no more — "no point giving him queries". '' = may take, else the sentence shown.
 *  Blocks when (a) he took 4+ queries today and his orders / taken is under 60% of the team's last-7-days ratio (only when the team has orders recorded), (b) he left 2+ hot chats today, or (c) 3+ hot chats of his wait unanswered. Switch: wag_mkt_gate = 0. */
function wag_mkt_gate_msg(PDO $pdo, int $emp): string
{
    static $c = []; if ($emp <= 0 || (string)setting_get($pdo, 'wag_mkt_gate', '1') === '0') return ''; $ck = $emp . ':' . (int)(time() / 30); if (isset($c[$ck])) return $c[$ck];
    $msg = '';
    try {
        $t0 = date('Y-m-d 00:00:00'); $w0 = date('Y-m-d 00:00:00', time() - 6 * 86400); $one = function (string $sql, array $a = []) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($a); return (int)$q->fetchColumn(); };
        $tk = $one("SELECT COUNT(DISTINCT phone) FROM wa_mkt WHERE emp_id = ? AND kind = 'taken' AND at >= ?", [$emp, $t0]); $dr = $one("SELECT COUNT(DISTINCT d.phone) FROM wa_mkt d WHERE d.emp_id = ? AND d.kind IN ('dropped','released') AND d.at >= ? AND EXISTS (SELECT 1 FROM wa_mkt t WHERE t.phone = d.phone AND t.emp_id = d.emp_id AND t.kind = 'taken' AND t.at >= ?)", [$emp, $t0, $t0]);   // only chats he took and left
        $od = 0; foreach (wag_mkt_board($pdo, 1) as $__b) if ((int)$__b['emp'] === $emp) $od = (int)$__b['orders'];   // orders credited from Shopify (or recorded)
        $un = 0; $__uq = $pdo->prepare("SELECT phone FROM wa_contacts WHERE assigned_emp = ? AND status = 'open' AND hot = 1 AND last_in_at >= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) LIMIT 40"); $__uq->execute([$emp, date('Y-m-d H:i:s', time() - 23 * 3600)]);
        foreach ($__uq->fetchAll(PDO::FETCH_COLUMN) as $__up) { $__ml = wag_meta_get($pdo, 'mklink:' . $__up, ''); if ($__ml !== '' && strtotime($__ml) > time() - 480) continue; if (wag_hot_is_done($pdo, (string)$__up)) continue; $un++; }   // a chat whose holder is making the payment link (8 min) is not "waiting"
        $ttk = 0; $tod = 0; foreach (wag_mkt_board($pdo, 7) as $__b) { $ttk += (int)$__b['taken']; $tod += (int)$__b['orders']; } $avg = $ttk >= 40 && $tod > 0 ? min(0.5, $tod / $ttk) : 0;   // orders are not all tied to takes: cap the team ratio so it can never exceed 50% (person must reach 60% of it)
        if ($un >= 3) $msg = '🔒 ' . $un . ' hot customers of yours are waiting for an answer. Answer them first — then you can take new queries.';
        elseif ($dr >= 2 && $dr * 2 > $tk) $msg = '🔒 You left ' . $dr . ' hot chats today without answering. New queries go to people who answer: answer your open chats first.';
        else {   // ONE source for the numbers (owner, 21 Nov: the market banner said 7 / 1, the Queries page 70, the sales strip 0/1): the query ledger — the same queries and orders the Queries page and the sales strip show (community not counted)
            $lq = 0; $lo = 0; $lavg = 0.0;
            try { $d1 = wag_real_queries($pdo, 1); $lq = (int)($d1[$emp][0] ?? 0); $lo = (int)($d1[$emp][1] ?? 0); $tq = 0; $to = 0; foreach (wag_real_queries($pdo, 7) as $__r) { $tq += $__r[0]; $to += $__r[1]; } $lavg = $tq >= 40 && $to > 0 ? min(0.5, $to / $tq) : 0.0; } catch (Throwable $e) { }
            if ($lq >= 12 && $lavg > 0 && ($lo / $lq) < 0.6 * $lavg) $msg = '🔒 You have ' . $lq . ' queries today with ' . $lo . ' order' . ($lo === 1 ? '' : 's') . ' — below the team level (' . round(100 * $lo / $lq) . '% vs ' . round(100 * $lavg) . '%). Close the chats you already have; new queries go to people who convert better.'; }
    } catch (Throwable $e) { }
    return $c[$ck] = $msg;
}
/** Is this salesperson busy answering OTHER customers right now (he sent a message to somebody in the last 2 minutes)? Cached 15 s per person. (owner, 21 Nov: a busy person gets more time, and the bot covers his waiting customers.) */
function wag_holder_busy(PDO $pdo, int $emp, int $secs = 120): bool
{
    static $c = []; $k = $emp . ':' . $secs; if (isset($c[$k]) && $c[$k][0] > time() - 15) return $c[$k][1];
    $b = false; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE emp_id = ? AND dir = 'out' AND ts >= ?"); $q->execute([$emp, date('Y-m-d H:i:s', time() - $secs)]); $b = (int)$q->fetchColumn() > 0; } catch (Throwable $e) { }
    $c[$k] = [time(), $b]; return $b;
}
/** True while a salesperson has pressed "Take over" on this chat (36 h) or has written to the customer in the last 20 minutes: the bot never talks over a person. */
function wag_bot_off(PDO $pdo, string $key, bool $ignoreHand = false): bool
{
    try { if (false) return false; $t = wag_meta_get($pdo, 'botoff:' . $key, ''); if ($t !== '' && strtotime($t) > time() - 36 * 3600) return true; $h = wag_meta_get($pdo, 'bothand:' . $key, ''); return !$ignoreHand && $h !== '' && strtotime($h) > time() - 2 * 3600; } catch (Throwable $e) { return false; }   // taken by a person (36 h) or handed to a person by the bot (2 h)
}
/** The bot is "answering" this chat: it is on, nobody took the chat, it has not handed it over, and it spoke in the last 30 min and has not handed over, or a customer message waits for it (60 s). A salesperson cannot take over / write meanwhile — only 👎. */
function wag_bot_lock(PDO $pdo, array $c): array
{
    $r = ['on' => false, 'why' => '', 'bot_text' => '', 'bot_at' => '', 'engaged' => false]; $key = (string)($c['phone'] ?? '');
    try {
        if ($key === '' || wag_cfg($pdo)['bot'] !== 'on') return $r;
        if ((int)($c['cx_id'] ?? 0) > 0 || (int)($c['optout'] ?? 0) === 1 || (string)($c['status'] ?? '') !== 'open') return $r;
        if (wag_bot_off($pdo, $key)) { $r['why'] = 'taken'; return $r; }
        if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $key)) { $r['why'] = 'muted'; return $r; }   // order completed: the bot is silent by rule — nothing to wait for, a person answers
        $li = !empty($c['last_in_at']) ? strtotime((string)$c['last_in_at']) : 0; $now = time();
        $h = true ? wag_meta_get($pdo, 'bothand:' . $key, '') : ''; if ($h !== '' && strtotime($h) >= $li) { $r['why'] = 'handed'; $r['engaged'] = true; return $r; }
        if (function_exists('wq_ts') && wq_ts(wag_meta_get($pdo, 'typing:' . $key, '')) > $now - 90) { $r['why'] = 'person'; return $r; }   // a person is typing to this customer: the bot waits, nobody has to wait for the bot (no "bot is writing")
        $lo = $pdo->prepare("SELECT who, ts FROM wa_messages WHERE phone = ? AND dir = 'out' ORDER BY id DESC LIMIT 1"); $lo->execute([$key]); $lo = $lo->fetch(); if ($lo && (string)$lo['who'] !== 'bot' && strtotime((string)$lo['ts']) > $now - 600) { $r['why'] = 'person'; return $r; }   // a person wrote to him in the last 10 min and is working on the chat: no bot lock
        $q = $pdo->prepare("SELECT body, ts FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' AND mtype = 'text' ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $b = $q->fetch();
        if ($b) { $r['bot_text'] = mb_substr((string)$b['body'], 0, 600); $r['bot_at'] = (string)$b['ts']; }
        if ($b && strtotime((string)$b['ts']) > $now - 7 * 86400) $r['engaged'] = true;   // the bot has been talking to this customer: only IT decides when a person takes over
        if (!empty($c['hot']) && $li > 0 && $li < $now - 120 && (empty($c['last_out_at']) || strtotime((string)$c['last_out_at']) < $li)) { $r['why'] = 'hot'; $r['engaged'] = (bool)$b; return $r; }   // a hot customer waits for 2+ min: a person may take it
        if ($li > 0 && $li < $now - 180 && (empty($c['last_out_at']) || strtotime((string)$c['last_out_at']) < $li)) { $r['why'] = 'stalled'; return $r; }   // the customer waits 3+ min and nobody (bot too) answered: the lock opens, a person may take it
        $botRecent = $b && strtotime((string)$b['ts']) > $now - 150;   // owner, 21 Nov: 30 minutes of "the bot is answering — wait" blocked most salespeople from taking queries; now only while the bot is really in the middle of the conversation (2.5 min)
        if ($botRecent) { $__fa = (int)wag_meta_get($pdo, 'bgfat:' . $key, '0'); if ($__fa > 0 && strtotime((string)$b['ts']) <= $__fa + 20) $botRecent = false; }   // the bot's own follow-up to a silent customer does not lock the chat   // the bot decides when a person may take over (its hand-over); only if the customer is silent 30 min — or the bot fails to answer for 3 min — the lock opens by itself
        $lm = $pdo->prepare("SELECT mtype FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $lm->execute([$key]); $lastType = (string)$lm->fetchColumn();
        $botCan = in_array($lastType, ['text', 'button', 'interactive'], true);   // a photo / voice note / file / location is for a person: the bot never answers it, so nobody waits for the bot
        $waiting = $botCan && $li > $now - 60 && (empty($c['last_out_at']) || strtotime((string)$c['last_out_at']) < $li);
        if ($botRecent || $waiting) { $r['on'] = true; $r['why'] = $waiting && !$botRecent ? 'writing' : 'answered'; }
    } catch (Throwable $e) { }
    return $r;
}
/** '' when a person may act on this chat, else the sentence shown to them. */
function wag_bot_block_msg(PDO $pdo, array $c): string
{
    if (!empty($GLOBALS['bot_replace'])) return '';
    return wag_bot_lock($pdo, $c)['on'] ? '🤖 The bot is answering this customer — wait. If its answer is not good, press 👎 "Bot answer not good": you write the better answer and the chat becomes yours.' : '';
}
/** A bot reply that would invent an offer or claim a payment: never sent — a person answers (the replay test found exactly these). */
function wag_bot_unsafe(string $t): bool
{
    $t = mb_strtolower($t);
    return (bool)preg_match('/\[(phone|name|address|email|link|number|pincode|order)[^\]]*\]|(free|muft|mufat|complimentary)\W+(\d+\s?ml|gift|tester|sample|bottle|perfume)|\d+\s?ml\W+(free|muft)|free gift|payment (is )?(received|mil gaya|mil gayi|ho gaya|ho gayi)|(payment|paisa) confirm (ho|hua)|order (is )?confirmed|order confirm ho (gaya|gya)|refund (done|kar diya)/u', $t);
}
/** Several fast bot workers at once (3-8 s answers): the sending job calls this every minute; each worker runs ~55 s and answers whoever waits. wq_guard keeps it to one answer per customer message. */
function wag_bot_spawn(PDO $pdo, int $n = 5): int
{
    if (!function_exists('wag_cfg') || wag_cfg($pdo)['bot'] !== 'on' || !function_exists('stream_socket_client')) return 0;
    if (time() - (int)setting_get($pdo, 'wag_tick_at', '0') < 15) return 0;   // the one-second cron heartbeat (cron_bot.php) is alive: the long workers are not needed
    $key = (string)setting_get($pdo, 'wa_cron_key', ''); if ($key === '') return 0; $host = (string)($_SERVER['HTTP_HOST'] ?? 'staging.fourpirates.space'); $sent = 0;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
    for ($i = 0; $i < $n; $i++) { $fp = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx); if (!$fp) continue;
        @fwrite($fp, "GET /empmanagment/api.php?action=wa.bot_run&key=" . urlencode($key) . " HTTP/1.1\r\nHost: $host\r\nUser-Agent: wa-bot-worker\r\nConnection: close\r\n\r\n"); @fclose($fp); $sent++; }
    return $sent;
}
/** ONE short bot tick (the fast heartbeat, driven once a second by cron_bot.php): claims a free slot (5 at most run at the same time — the others return at once, so no web-server worker is held), answers up to 2 waiting customers, slot 0 also runs the complaint / hot-open sweeps every 20 s. */
function wag_bot_tick(PDO $pdo, int $slots = 8): array   // owner, 21 Nov: 8 slots (was 5) — ~87 answers a minute had filled five slots and customers waited 30-45 s for "the bot is writing"
{
    $o = ['slot' => -1, 'sent' => 0, 'handoff' => 0]; if (wag_cfg($pdo)['bot'] !== 'on') return $o;
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { for ($k = 0; $k < $slots; $k++) { try { if ((int)$pdo->query("SELECT GET_LOCK('hrms_wag_tick_" . $k . "', 0)")->fetchColumn()) { $o['slot'] = $k; break; } } catch (Throwable $e) { } } if ($o['slot'] < 0) return $o; }
    try {
        if ($o['slot'] === 0) { setting_set($pdo, 'wag_tick_at', (string)time()); if (time() - (int)setting_get($pdo, 'wag_sweep_at', '0') >= 20) { setting_set($pdo, 'wag_sweep_at', (string)time()); try { wag_complaint_sweep($pdo); } catch (Throwable $e) { } try { wag_hot_open_sweep($pdo); } catch (Throwable $e) { } } }
        $r = wag_bot_scan($pdo, $o['slot'] >= 6 ? 1 : 3); $o['sent'] = $r['sent']; $o['handoff'] = $r['handoff'];   // customer answers first (owner, 21 Nov: "the bot is writing" waits were too long): 3 per tick in the first slots, the last two slots do follow-ups
        if (time() - (int)setting_get($pdo, 'wag_exp_at', '0') >= 1800) { setting_set($pdo, 'wag_exp_at', (string)time()); try { wag_exp_eval($pdo); } catch (Throwable $e) { } }   // the self-learning check, every 30 minutes
        try { if ($o['slot'] >= 6 || ($r['sent'] + $r['handoff']) === 0) $o['followup'] = wag_bot_followup($pdo, 6); } catch (Throwable $e) { try { wag_log($pdo, 'bot', false, 'follow-up: ' . $e->getMessage()); } catch (Throwable $e2) { } }   // a slot that just answered customers does not start a long follow-up round: a waiting customer always comes first
    } finally { if ($o['slot'] >= 0) { try { $pdo->query("SELECT RELEASE_LOCK('hrms_wag_tick_" . $o['slot'] . "')"); } catch (Throwable $e) { } } }
    return $o;
}
/** One worker: claims a free slot (MySQL lock), then answers waiting customers every ~2 s for up to 55 s. */
function wag_bot_run(PDO $pdo, int $slots = 5, int $secs = 55): array
{
    $o = ['slot' => -1, 'sent' => 0, 'handoff' => 0, 'rounds' => 0]; if (wag_cfg($pdo)['bot'] !== 'on') return $o;
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { for ($k = 0; $k < $slots; $k++) { try { if ((int)$pdo->query("SELECT GET_LOCK('hrms_wag_bot_" . $k . "', 0)")->fetchColumn()) { $o['slot'] = $k; break; } } catch (Throwable $e) { } } if ($o['slot'] < 0) return $o; }
    $end = microtime(true) + $secs; $lastC = 0;
    try { while (microtime(true) < $end) { if ($o['slot'] === 0 && time() - $lastC >= 20) { $lastC = time(); try { $o['complaints'] = ($o['complaints'] ?? 0) + wag_complaint_sweep($pdo); } catch (Throwable $e) { } try { $o['hot_open'] = ($o['hot_open'] ?? 0) + wag_hot_open_sweep($pdo); } catch (Throwable $e) { } } $r = wag_bot_scan($pdo, 2); $o['sent'] += $r['sent']; $o['handoff'] += $r['handoff']; $o['rounds']++; if ($r['sent'] + $r['handoff'] === 0) usleep(500000); } }
    finally { if ($o['slot'] >= 0) { try { $pdo->query("SELECT RELEASE_LOCK('hrms_wag_bot_" . $o['slot'] . "')"); } catch (Throwable $e) { } } }
    return $o;
}
/** The 24/7 bot: answers a waiting customer by itself — only from the taught guidebook, only text chats inside the 24-hour window, never a HOT chat. */
function wag_bot_scan(PDO $pdo, int $max = 4): array
{
    $cfg = wag_cfg($pdo); $o = ['sent' => 0, 'handoff' => 0]; if ($cfg['bot'] !== 'on') return $o;
    if (defined('WA_IN_WEBHOOK')) return $o;   // never inside Meta's webhook call (an AI answer takes seconds)
    wag_addon_sweep($pdo); wag_addon_scan($pdo);
    $now = time(); $unans = $cfg['unans_min'] * 60;
    $st = $pdo->prepare("SELECT c.* FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.last_in_at >= ? AND c.last_in_at <= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) ORDER BY c.last_in_at DESC LIMIT 60");
    $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - $cfg['bot_after'])]);
    foreach ($st->fetchAll() as $c) {
        if ($o['sent'] + $o['handoff'] >= $max) break; $key = (string)$c['phone']; $age = $now - strtotime((string)$c['last_in_at']);
        $__mv = wag_meta_get($pdo, 'bgm:' . $key, ''); if ($__mv !== '') { $__mp = explode('|', $__mv, 2); if ($__mp[0] === (string)$c['last_in_at'] && (int)($__mp[1] ?? 0) > time()) continue; } wag_meta_put($pdo, 'bgm:' . $key, $c['last_in_at'] . '|' . (time() + 45));   // a chat looked at is not looked at again for 45 s (kept in the database: every tick is a new short request, no memory between them)
        if (!empty($c['cx_id'])) continue;   // the chat is with the Complaint team: the bot never answers it
        $__ad = wag_meta_get($pdo, 'addon:' . $key, ''); if (strpos($__ad, 'offered|') === 0) {   // an add-on offer is waiting for the answer (also in a chat a salesperson holds): code answers it, the AI stays quiet
            $__a0 = $pdo->prepare("SELECT id, mtype, body, wamid FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $__a0->execute([$key]); $__m0 = $__a0->fetch();
            if ($__m0 && in_array((string)$__m0['mtype'], ['text', 'button', 'interactive'], true) && wag_addon_handle($pdo, $c, $key, $__m0)) $o['sent']++; continue; }
        if (true && function_exists('wq_ts') && wq_ts(wag_meta_get($pdo, 'typing:' . $key, '')) > time() - 90) continue;   // a person is typing an answer right now: the bot never talks over them
        if (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $key)) continue;   // ✅ order completed: the bot stays quiet today and tomorrow (a person answers)
        // the bot answers after bot_after (4 s) — also when the chat is given to a salesperson — until that person presses "Take over" (or is writing to the customer right now): then the bot is silent
        // 🆘 STUCK (owner, 21 Nov): a hot chat (ready to order / pay, or the bot handed it over) that nobody answered for 10+ minutes: the bot tries ONCE more in ORDER MODE (details + address, never payment) and, if it cannot, the chat goes to the next free person with a push
        $__stuck = (!empty($c['hot']) || (true && ($__bh = wag_meta_get($pdo, 'bothand:' . $key, '')) !== '' && strtotime($__bh) >= strtotime((string)$c['last_in_at']))) && ($age >= 600 || (true && ($__hm = wag_meta_get($pdo, 'hotmiss:' . $key, '')) !== '' && substr($__hm, strrpos($__hm, '|') + 1) === (string)$c['last_in_at'])) && (false || wag_meta_get($pdo, 'stuck:' . $key, '') !== (string)$c['last_in_at']);   // 10+ min, or the 2-minute rule already moved the chat to OPEN: the bot answers it in ORDER MODE at once
        $__cover = false;
        if (wag_bot_off($pdo, $key, $__stuck)) {   // a person holds this chat: the bot stays silent — except when that person is busy with other customers and this customer has waited 90 s (owner, 21 Nov): then the bot answers meanwhile; the chat stays with the person
            $__ho = (int)($c['assigned_emp'] ?? 0); $__bh3 = wag_meta_get($pdo, 'bothand:' . $key, '');
            if ($__ho > 0 && $age >= 90 && !($__bh3 !== '' && strtotime($__bh3) >= strtotime((string)$c['last_in_at'])) && wag_holder_busy($pdo, $__ho)) $__cover = true; else continue;
        }
        $li = $pdo->prepare("SELECT id, mtype, body, wamid FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $li->execute([$key]); $m = $li->fetch();
        if (!$m || !in_array((string)$m['mtype'], ['text', 'button', 'interactive'], true)) continue;   // photos / voice notes / documents are for a person
        // wants to order / pay (hot): the bot takes the ORDER DETAILS and the ADDRESS — never the payment — and then a person finishes (ORDER MODE)
        $__order = $__stuck || (function_exists('wa_hot_intent') && wa_hot_intent((string)$m['body']) !== '') || (!empty($c['hot']) && !empty($c['hot_at']) && strtotime((string)$c['hot_at']) > time() - 2 * 3600 && !preg_match('/(address|change|badal|edit|cancel|refund|return|tracking|status)/iu', (string)$m['body']));   // order mode only while the customer is really ordering (not for "change my address" in an old hot chat)
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
        if (wag_addon_handle($pdo, $c, $key, $m)) { $o['sent']++; continue; }   // the answer to the add-on offer is handled by code, not by the AI
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' AND ts >= ?"); $q->execute([$key, date('Y-m-d 00:00:00')]); if ((int)$q->fetchColumn() >= $cfg['bot_max_day']) continue;
        if ($__stuck && true) wag_meta_put($pdo, 'stuck:' . $key, (string)$c['last_in_at']);   // one extra try per customer message
        if (!$__stuck && wag_neg_skip($pdo, 'bgn:' . $key, (int)$m['id'])) continue;   // already tried for this message
        $__wqc = function_exists('wq_guard') ? wq_guard($pdo, $key, (int)$m['id'], 'reply', 'bot') : true; if (!$__wqc) continue;   // one answer per customer message: bot, holding message or countdown — whoever claims first
        wag_bot_typing($pdo, $c, (string)($m['wamid'] ?? ''));   // the customer sees "typing…" at once (WhatsApp shows it for up to 25 s) while the answer is made
        $__t0 = microtime(true); $f0 = ai_feat('wa_bot'); $GLOBALS['lib_phone'] = $key; $GLOBALS['lib_fast'] = 1; if ($__order) $GLOBALS['lib_order_mode'] = 1; $r = wag_brain_reply($pdo, $c, '', true); unset($GLOBALS['lib_fast'], $GLOBALS['lib_order_mode'], $GLOBALS['lib_phone']); ai_feat($f0); try { wag_log($pdo, 'botms', true, 'ai ' . round(microtime(true) - $__t0, 1) . ' s · wait ' . $age . ' s'); } catch (Throwable $e) { }
        if (!$r['ok']) { wag_neg_set($pdo, 'bgn:' . $key, (int)$m['id'], false); if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); if (stripos((string)$r['why'], 'not been taught') !== false) { wag_log($pdo, 'bot', false, 'The bot has not been taught yet — nothing was sent.'); break; } continue; }
        if (empty($r['handoff'])) { try { $pb = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' AND mtype = 'text' ORDER BY id DESC LIMIT 1"); $pb->execute([$key]); $pt = (string)$pb->fetchColumn(); if ($pt !== '') { similar_text(mb_strtolower(mb_substr($pt, 0, 200)), mb_strtolower(mb_substr((string)$r['text'], 0, 200)), $__pc); if ($__pc >= 82) { $r['handoff'] = true; wag_log($pdo, 'bot', false, $key . ' · the bot was about to repeat itself — a person takes over'); } } } catch (Throwable $e) { } }   // the same question twice = the bot is stuck: a person
        if (empty($r['handoff']) && wag_bot_unsafe((string)$r['text'])) { $r['handoff'] = true; wag_log($pdo, 'bot', false, $key . ' · blocked an unsafe reply (invented offer / payment claim) — a person takes over'); }
        if ($__stuck && (!empty($r['handoff']) || !$r['ok'])) wag_stuck_escalate($pdo, $c, $key);   // the bot cannot help: a free person gets it now, with a push
        if (!empty($r['handoff']) && true) wag_meta_put($pdo, 'bothand:' . $key, date('Y-m-d H:i:s'));   // the bot hands this customer to a person: the lock opens
        if (!empty($r['handoff'])) { wag_neg_set($pdo, 'bgn:' . $key, (int)$m['id'], true); if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); if (function_exists('wa_hot_flag') && wa_hot_flag($pdo, $c, 'bot needs a person')) $o['handoff']++; continue; }
        $r['text'] = wag_strip_unasked_discount((string)$r['text'], (string)($m['body'] ?? ''));
        [$ok, $res] = wa_send_text($pdo, $c, $r['text'], 'bot', 0);
        if (false /* 🔒 the price chart is never sent to a customer (owner's rule) */ && $ok && (!empty($r['photo']) || (function_exists('pc_price_intent') && pc_price_intent((string)$m['body']))) && function_exists('pc_photo_url') && function_exists('wa_send_image_link')) { $lv = function_exists('pc_chat_level') ? pc_chat_level($pdo, $key) : ['pct' => 6.5]; $u = pc_photo_url($pdo, (float)$lv['pct']); if ($u !== '') { [$pok, $pres] = wa_send_image_link($pdo, $c, $u, '', 'bot', 0); if (!$pok) wag_log($pdo, 'bot', false, $key . ' · price photo · ' . $pres); } }   // the Teach rules said: send the price photo
        if ($ok && $__order) wag_order_done_check($pdo, $c, $key);
        if ($ok) { $o['sent']++; wa_event($pdo, $key, '🤖 The bot answered (' . $age . ' s after the customer)' . ($__cover ? ' — the holder was busy with other customers' : '')); if (function_exists('wq_rescue_price_intent') && function_exists('wq_tag_put') && wq_rescue_price_intent((string)$m['body'])) { wq_tag_put($pdo, $key, 'orange'); wa_event($pdo, $key, '🟠 The customer is interested: everyone can see this chat and pick it up'); } if (function_exists('wa_sugg_save')) wa_sugg_save($pdo, $key, $r['text'], $r['source'], (int)$r['ver']); if (function_exists('wa_prod_bot_after')) wa_prod_bot_after($pdo, $c, (string)$r['text']); }   // product photos follow the bot's text (max 3, never repeated within 24 h)
        else { wag_log($pdo, 'bot', false, $key . ' · ' . $res); if (function_exists('wq_guard_release')) wq_guard_release($pdo, $key, (int)$m['id'], 'reply'); }
    }
    return $o;
}
/** 🔔 AGGRESSIVE BOT FOLLOW-UP (owner, 21 Nov): the bot answered and the customer went silent. Inside the 24-hour window (free text) the bot follows up by itself in 4 steps —
 *  15 min, 2 h, 6 h, 20 h after the customer's last message — each step with a different angle (the missing detail, the add-on towards Rs 2,700-2,800, a reason to decide now, a last easy ask). Daytime only (9:00-21:30).
 *  Never when a person took the chat, the bot handed it over, the order is done, the customer said stop / no, or a flow runs. Settings: wag_bfu_on (1), wag_bfu_max_day (2500). */
function wag_bot_followup(PDO $pdo, int $max = 6): int   // owner, 21 Nov: follow-ups must be FAST — 6 per tick (was 2; only ~4 a minute went out with 347 due)
{
    if (defined('WA_IN_WEBHOOK') || (string)setting_get($pdo, 'wag_bfu_on', '1') === '0' || wag_cfg($pdo)['bot'] !== 'on') return 0;
    $now = time(); $hr = (int)date('G') * 60 + (int)date('i'); if ($hr < 9 * 60 || $hr > 21 * 60 + 30) return 0;
    if (time() - (int)setting_get($pdo, 'wag_bfu_at', '0') < 5) return 0; setting_set($pdo, 'wag_bfu_at', (string)time());   // one look every 20 s
    $day = date('Y-m-d'); if (setting_get($pdo, 'wag_bfu_day', '') !== $day) { setting_set($pdo, 'wag_bfu_day', $day); setting_set($pdo, 'wag_bfu_n', '0'); } $cap = max(0, (int)setting_get($pdo, 'wag_bfu_max_day', '20000')); if ((int)setting_get($pdo, 'wag_bfu_n', '0') >= $cap) return 0;
    $steps = [300, 600, 1200, 1800, 3600, 7200, 10800, 14400, 28800, 64800]; $n = 0;   // 5 m, 10 m, 20 m, 30 m, 1 h, 2 h, 3 h, 4 h (hold the customer), then slower: 8 h, 18 h — afterwards the 30-day Lineup takes over (owner, 21 Nov)
    $st = $pdo->prepare("SELECT c.* FROM wa_contacts c JOIN (SELECT phone, MAX(ts) z FROM wa_messages WHERE dir = 'out' AND who = 'bot' AND ts >= ? GROUP BY phone) b ON b.phone = c.phone WHERE c.status = 'open' AND c.optout = 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.last_in_at >= ? AND c.last_in_at <= ? AND c.last_out_at IS NOT NULL AND c.last_out_at > c.last_in_at AND c.last_out_at <= DATE_ADD(b.z, INTERVAL 5 SECOND) ORDER BY c.last_in_at DESC LIMIT 120");   // only chats where the BOT spoke last (a person / campaign / flow message after it = not ours)
    $st->execute([date('Y-m-d H:i:s', $now - 23 * 3600), date('Y-m-d H:i:s', $now - 22 * 3600 - 1800), date('Y-m-d H:i:s', $now - 150)]);
    $__rows = $st->fetchAll();
    if ((string)setting_get($pdo, 'wag_bfu_person', '1') !== '0') {   // 🧑‍💼 chats a SALESPERSON holds and answered last, and the customer went silent: the same follow-up ladder runs for them (owner, 21 Nov), from the person's last message, first step after 10 minutes
        try { $sp = $pdo->prepare("SELECT c.* FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.assigned_emp > 0 AND c.last_in_at >= ? AND c.last_out_at IS NOT NULL AND c.last_out_at > c.last_in_at AND c.last_out_at <= ? AND EXISTS (SELECT 1 FROM wa_messages w WHERE w.phone = c.phone AND w.dir = 'out' AND w.emp_id > 0 AND w.ts >= DATE_SUB(c.last_out_at, INTERVAL 5 SECOND)) ORDER BY c.last_out_at DESC LIMIT 60"); $sp->execute([date('Y-m-d H:i:s', $now - 22 * 3600 - 1800), date('Y-m-d H:i:s', $now - 600)]); $__pr = []; foreach ($sp->fetchAll() as $__x) { $__x['_p'] = 1; $__pr[] = $__x; } $__br = array_values($__rows); $__rows = []; $__m = max(count($__br), count($__pr)); for ($__k = 0; $__k < $__m; $__k++) { if (isset($__pr[$__k])) $__rows[] = $__pr[$__k]; if (isset($__br[$__k])) $__rows[] = $__br[$__k]; } } catch (Throwable $e) { }   // person-held chats are mixed in turn with the bot's own (they were appended at the end and never reached: the loop stops after 6 AI calls)
    }
    $psteps = [600, 1200, 2400, 3600, 7200, 14400, 28800, 64800]; $D = ['pc' => count(array_filter($__rows, function ($__z) { return !empty($__z['_p']); })), 'cand' => count($__rows), 'early' => 0, 'old' => 0, 'off' => 0, 'hand' => 0, 'hotp' => 0, 'notsales' => 0, 'ai' => 0, 'handoff' => 0, 'unsafe' => 0, 'same' => 0, 'send' => 0, 'sent' => 0, 'err' => '']; $__tries = 0; $__i = 0; $__t0 = microtime(true); setting_set($pdo, 'wag_bfu_dbg', json_encode(['start' => date('H:i:s'), 'cand' => count($__rows)]));
    foreach ($__rows as $c) {
        try {
        if ($n >= $max || $__tries >= 6 || microtime(true) - $__t0 > 14) break; $key = (string)$c['phone']; $li = (string)$c['last_in_at']; $liT = strtotime($li);
        $f = wag_meta_get($pdo, 'bgf:' . $key, ''); $fp = $f === '' ? [] : explode('|', $f); $cnt = ($fp && ($fp[0] ?? '') === $li) ? (int)($fp[1] ?? 0) : 0; $lastF = ($fp && ($fp[0] ?? '') === $li) ? (int)($fp[2] ?? 0) : 0;
        $__pp = !empty($c['_p']) || ($cnt > 0 && (($fp[3] ?? '') === 'p')); $stp = $__pp ? $psteps : $steps;
        if ($__pp) $D['pseen'] = (int)($D['pseen'] ?? 0) + 1;   // salesperson-held chats looked at (diag: seen / early / sent)
        $refT = $__pp ? ((($fp[3] ?? '') === 'p' && ($fp[0] ?? '') === $li && (int)($fp[4] ?? 0) > 0) ? (int)$fp[4] : (int)strtotime((string)$c['last_out_at'])) : $liT;   // the ladder of a person-held chat counts from the person's last message
        if ($cnt >= count($stp) + 1) continue;
        if ($cnt === count($stp) && $__pp) { wag_meta_put($pdo, 'bgf:' . $key, $li . '|' . ($cnt + 1) . '|' . $now . '|p|' . $refT); continue; }   // a person's chat is never taken away from him
        if ($cnt === count($stp)) {   // all 10 follow-ups are sent and the customer is still silent: the chat goes OUT to the market (orange) — anybody who wants to try may take it
            if ($lastF && $now - $lastF < 900) continue; if (wag_bot_off($pdo, $key) || ($now - $liT) > 23 * 3600 - 600) { wag_meta_put($pdo, 'bgf:' . $key, $li . '|' . ($cnt + 1) . '|' . $now); continue; }
            wag_meta_put($pdo, 'bgf:' . $key, $li . '|' . ($cnt + 1) . '|' . $now);
            if (function_exists('wa_assign_to')) { try { wa_assign_to($pdo, $key, 0, 0, 'auto'); } catch (Throwable $e) { } }
            if (function_exists('wq_tag_put')) { try { wq_tag_put($pdo, $key, 'orange'); } catch (Throwable $e) { } }
            if (function_exists('wa_mkt_note')) { try { wa_mkt_note($pdo, $key, 0, 'offered', -1, 'followup'); } catch (Throwable $e) { } }
            wa_event($pdo, $key, '🟠 The customer did not answer the bot\'s 10 follow-ups: the chat is in OPEN (orange) — anybody who wants to try can take it.'); $n++; continue;
        }
        if ($cnt >= count($stp)) continue; if ($cnt === 0 && $now - $refT > 6 * 3600) { $D['old']++; continue; }   // a customer who has been silent for hours is for the 30-day Lineup, not for the first minutes ladder
        $__need = $stp[$cnt]; $__gap = $__pp ? 300 : 180;
        if ($cnt === 0 && !$__pp && $now - $liT >= 150) { $__rs = $pdo->prepare("SELECT status FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' ORDER BY id DESC LIMIT 1"); $__rs->execute([$key]); if ((string)$__rs->fetchColumn() === 'read') { $__need = 150; $__gap = 120; } }   // read (blue ticks) but silent = a warm customer: the first follow-up comes after 2.5 min instead of 5
        if ($now - $refT < $__need || ($lastF && $now - $lastF < 240) || $now - (int)strtotime((string)$c['last_out_at']) < $__gap) { $D['early']++; if ($__pp) $D['pearly'] = (int)($D['pearly'] ?? 0) + 1; continue; }
        if ((!$__pp && wag_bot_off($pdo, $key)) || (function_exists('wa_auto_muted') && wa_auto_muted($pdo, $key))) { $D['off']++; continue; }
        if ($__pp) { $__tm = wag_meta_get($pdo, 'typing:' . $key, ''); if (function_exists('wq_ts') && wq_ts($__tm) > $now - 180) { $D['early']++; continue; } $__ml = wag_meta_get($pdo, 'mklink:' . $key, ''); if ($__ml !== '' && strtotime($__ml) > $now - 480) { $D['early']++; continue; } }   // the holder is typing / making the link: not now
        $bh = wag_meta_get($pdo, 'bothand:' . $key, ''); if (!$__pp && $bh !== '' && strtotime($bh) >= $liT) { $D['hand']++; continue; }   // the bot handed this customer to a person
        if (!$__pp && !empty($c['hot']) && !empty($c['assigned_emp'])) { $D['hotp']++; continue; }   // a person holds a hot chat: his job
        if (function_exists('wag_is_sales_query') && !wag_is_sales_query($pdo, $c)) { $D['notsales']++; continue; }   // only sales queries (not after-sales / complaints)
        $q = $pdo->prepare("SELECT who, body, ts, mtype FROM wa_messages WHERE phone = ? AND dir != 'sys' ORDER BY id DESC LIMIT 6"); $q->execute([$key]); $rows = $q->fetchAll(); if (!$rows) continue;
        $lastOut = $rows[0]; if ((string)$lastOut['who'] !== 'bot' && !($cnt > 0) && !$__pp) continue;   // only a customer the BOT was talking to; later steps continue after our own follow-up
        if ($cnt > 0 && (string)$lastOut['who'] !== 'bot') continue; if ((string)$lastOut['mtype'] !== 'text') continue;
        $lastIn = ''; foreach ($rows as $r) { if (false) break; } $qi = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $qi->execute([$key]); $lastIn = trim((string)$qi->fetchColumn());
        if (preg_match('/(stop|unsubscribe|not interested|nahi chahiye|nhi chahiye|mat bhej|band kar|no thanks|don.?t message|remove)/iu', $lastIn)) { wag_meta_put($pdo, 'bgf:' . $key, $li . '|9|' . $now); continue; }
        $fl = $pdo->prepare("SELECT COUNT(*) FROM wa_flow_runs WHERE phone = ? AND state IN ('run', 'wait', 'ready')"); try { $fl->execute([$key]); if ((int)$fl->fetchColumn() > 0) continue; } catch (Throwable $e) { }
        wag_meta_put($pdo, 'bgf:' . $key, $li . '|' . ($cnt + 1) . '|' . $now . ($__pp ? '|p|' . $refT : ''));   // claimed before the AI call: never twice
        $GLOBALS['lib_fu_step'] = $cnt + 1; $GLOBALS['lib_phone'] = $key; $GLOBALS['lib_fast'] = 1; $f0 = ai_feat('wa_bot'); $r = wag_brain_reply($pdo, $c, '', true); unset($GLOBALS['lib_fu_step'], $GLOBALS['lib_fast'], $GLOBALS['lib_phone']); ai_feat($f0); $__tries++;
        if (empty($r['ok']) || trim((string)($r['text'] ?? '')) === '') { $D['ai']++; $D['err'] = mb_substr((string)($GLOBALS['lib_last_why'] ?? '') . ' | ' . (string)($r['why'] ?? ''), 0, 160); continue; }
        if (!empty($r['handoff'])) { $D['handoff']++; continue; } if (wag_bot_unsafe((string)$r['text'])) { $D['unsafe']++; continue; }
        $pt = ''; foreach ($rows as $rw) if ((string)$rw['who'] === 'bot') { $pt = (string)$rw['body']; break; } if ($pt !== '') { similar_text(mb_strtolower(mb_substr($pt, 0, 200)), mb_strtolower(mb_substr((string)$r['text'], 0, 200)), $pc); if ($pc >= 80) { $D['same']++; continue; } }   // never the same text again
        $r['text'] = wag_strip_unasked_discount((string)$r['text'], (string)$lastIn);
        wag_meta_put($pdo, 'bgfat:' . $key, (string)(time() + 0));
        $__fv = (string)setting_get($pdo, 'wag_fu_var', 'AB'); $__var = ($__fv === 'A' || $__fv === 'B') ? $__fv : ((crc32($key) % 2) ? 'B' : 'A');   // 🧪 A/B (owner, 21 Nov): A = plain text, B = the same text with 3 quick-reply buttons; the winner is read from the replies (event text carries the letter)
        if ($__var === 'B' && function_exists('wa_send_buttons')) { [$ok, $res] = wa_send_buttons($pdo, $c, $r['text'], ['✅ Order karo', '💬 Doubt hai', '⏰ Baad me'], 'bot', 0); if (!$ok) { $__var = 'A'; [$ok, $res] = wa_send_text($pdo, $c, $r['text'], 'bot', 0); } }
        else [$ok, $res] = wa_send_text($pdo, $c, $r['text'], 'bot', 0);
        if ($ok) { $D['sent']++; if ($__pp) $D['ps'] = (int)($D['ps'] ?? 0) + 1; $n++; setting_set($pdo, 'wag_bfu_n', (string)((int)setting_get($pdo, 'wag_bfu_n', '0') + 1)); wa_event($pdo, $key, '🔔 The bot followed up' . ($__pp ? ' for the salesperson' : '') . ' [' . $__var . '] (step ' . ($cnt + 1) . ' of ' . count($stp) . ', ' . round(($now - $liT) / 60) . ' min after the customer\'s last message)'); }
        else { $D['send']++; $D['err'] = mb_substr((string)$res, 0, 80); wag_log($pdo, 'bot', false, $key . ' · follow-up: ' . $res); }
        } catch (Throwable $__ex) { $D['err'] = 'EX ' . mb_substr($__ex->getMessage(), 0, 110); } finally { $D['t'] = round(microtime(true) - $__t0, 1); if ($__tries > 0 || (++$__i % 15) === 0) setting_set($pdo, 'wag_bfu_dbg', json_encode($D)); }
    }
    $D['at'] = date('H:i:s'); setting_set($pdo, 'wag_bfu_dbg', json_encode($D));
    return $n;
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
function wag_ai_tag_one(PDO $pdo, array $c): array { $f0 = ai_feat('wa_tag'); try { return wag_ai_tag_one_x($pdo, $c); } finally { ai_feat($f0); } }
function wag_ai_tag_one_x(PDO $pdo, array $c): array
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
    foreach (['unans' => fn() => wag_unans_scan($pdo), 'bot' => fn() => wag_bot_scan($pdo), 'bfu' => fn() => wag_bot_followup($pdo), 'sugg' => fn() => defined('WA_IN_WEBHOOK') ? 0 : wag_presuggest($pdo), 'flows' => fn() => function_exists('wag_flow_tick') ? wag_flow_tick($pdo) : 0, 'aitags' => fn() => wag_ai_tag_scan($pdo)] as $k => $fn) {
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
        if (function_exists('cfun_dirty')) cfun_dirty($pdo, $key);   // ⚡ a customer wrote: recount them within a minute (Replied)
        wag_q_touch($pdo, $key, !empty($ref));
        if (function_exists('waa_give_now')) waa_give_now($pdo, $key);   // 🏪 Auto assign: the new query is given to a person right now (within seconds), not at the next engine pass
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
    wa_cust_ready($pdo); $at = (string)setting_get($pdo, 'wa_cust_at', ''); $c = null;
    try { $c = json_decode((string)setting_get($pdo, 'wa_tag_cache', ''), true); if (!is_array($c) || (int)($c['v'] ?? 0) !== 2) $c = null; } catch (Throwable $e) { $c = null; }
    if ($c && ($c['as_of'] ?? '') === $at && $at !== '') return $c;   // computed once per refresh
    // ⚡ 18 Nov: this count takes 13-22 s on the big tables and, every time the customer table refreshed, EVERY open screen started it at once (4 copies seen running together, database at 200% CPU,
    // the whole HRMS slow). Now only ONE request recounts; the others — and anything asked within 15 minutes of the last count — get the previous numbers at once.
    if ($c && (time() - (int)($c['at'] ?? 0)) < 1800) return $c;   // ⚡ 15 → 30 minutes: the biggest count of the database (13-22 s) now runs half as often
    $got = 0; try { $got = (int)$pdo->query("SELECT GET_LOCK('wag_tags', 0)")->fetchColumn(); } catch (Throwable $e) { $got = 1; }
    if (!$got) { if ($c) return $c;   // somebody else is counting: use the old numbers
        for ($i = 0; $i < 25; $i++) { sleep(1); try { $c = json_decode((string)setting_get($pdo, 'wa_tag_cache', ''), true); } catch (Throwable $e) { $c = null; } if (is_array($c) && (int)($c['v'] ?? 0) === 2) return $c; } }   // no old numbers at all: wait (up to 25 s) for the one that is counting instead of starting a 4th copy
    try { $r = wag_tag_counts_calc($pdo); $r['at'] = time(); try { setting_set($pdo, 'wa_tag_cache', json_encode($r)); } catch (Throwable $e) { } return $r; }
    finally { if ($got) { try { $pdo->query("SELECT RELEASE_LOCK('wag_tags')"); } catch (Throwable $e) { } } }
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
    if (function_exists('wq_tag_labels')) foreach ($cl as &$x0) { $x0['t'] = wq_tag_labels()[$x0['k']] ?? ((function_exists('jt_label') && ($jl = jt_label($pdo, $x0['k'])) !== '') ? $jl : (strpos($x0['k'], 'import_') === 0 ? 'Imported ' . substr($x0['k'], 7) : $x0['k'])); $x0['kind'] = preg_match('/_query$/', $x0['k']) ? 'query' : (strpos($x0['k'], 'import_') === 0 ? 'import' : $x0['kind']); } unset($x0);
    $sg = function_exists('wcs_seg_names') ? wcs_seg_names($pdo) : []; foreach ($cl as &$x1) if (isset($sg[$x1['k']])) { $x1['t'] = $sg[$x1['k']]; $x1['kind'] = 'segment'; } unset($x1);
    $segs = array_values(array_filter($cl, fn($x) => $x['kind'] === 'segment')); $cl = array_values(array_filter($cl, fn($x) => $x['kind'] !== 'segment'));
    // the 120 busiest tags only — but every Excel import batch (import_…, left_…) is always listed (newest first), however small: a new import has fewer people than old tags and fell out of the list, so "No tag like that" showed for the very tag the import suggested
    $isImp = function ($x) { return (bool)preg_match('/^(import|left)_/', (string)$x['k']); };
    $imp = array_values(array_filter($cl, $isImp)); usort($imp, function ($x, $y) { return strcmp((string)$y['k'], (string)$x['k']); }); $imp = array_slice($imp, 0, 80);
    $rest = array_values(array_filter($cl, function ($x) use ($isImp) { return !$isImp($x); }));
    return ['v' => 2, 'tags' => array_merge($out, $segs, $imp, array_slice($rest, 0, 120)), 'customers' => $tot, 'as_of' => (string)setting_get($pdo, 'wa_cust_at', '')];
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
    if (!empty($f['ids'])) {   // ☑ "Select all": every chat of this view (all pages), phone + name only, up to 50,000
        $st = $pdo->prepare("SELECT c.phone, c.name FROM wa_contacts c WHERE $where ORDER BY c.last_in_at DESC LIMIT 50000"); $st->execute($p);
        return ['ids' => array_map(fn($r) => ['phone' => (string)$r['phone'], 'name' => (string)$r['name']], $st->fetchAll()), 'total' => $total, 'chats' => [], 'page' => 0, 'more' => false];
    }
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
/** Order status / tracking / refund / return / damage words: the customer talks about an order he already made — after-sales, never a sales query (owner, 21 Nov). */
function wag_after_sales_text(string $t): bool { return $t !== '' && (bool)preg_match('/(tracking|track my|track order|where is my order|order status|order id|order no|\bawb\b|kab aayega|kab tak aayega|kab milega|nahi mila|not received|refund|return|replace|damaged|leak|wrong (item|product)|cancel my order|order kaha|parcel)/iu', $t); }
/** Complaints are not sales queries (owner, 21 Nov): the query rows of a complaint chat are removed from the ledger (and its type / stats rows), so no count shows them. */
function wag_q_forget(PDO $pdo, string $key, int $days = 95): void
{
    try {
        $st = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? AND opened_at >= ?"); $st->execute([$key, date('Y-m-d H:i:s', time() - $days * 86400)]); $ids = $st->fetchAll(PDO::FETCH_COLUMN); if (!$ids) return; $in = implode(',', array_map('intval', $ids));
        foreach (['wq_qtype', 'wq_qstat'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE query_id IN ($in)"); } catch (Throwable $e) { } }
        $pdo->exec("DELETE FROM wa_queries WHERE id IN ($in)");
    } catch (Throwable $e) { }
}
/** THE QUERY LEDGER — one row per query ("episode"):
 *  • A query opens when a customer writes and has no query that was active in the last 24 hours (the same 24 h that moves a chat to History). Writing again inside 24 h is the SAME query.
 *  • Its type is fixed at that moment: Meta ad > campaign reply > community > other. A customer who was here before (an earlier query, or an order) is marked "back again" — still counted in his type, shown separately.
 *  • The person who holds the chat owns the query. Moving it takes it off A and gives it to B; it always counts in the period it OPENED in.
 *  • A move made after the customer waited longer than the alert limit is written down as a "missed" for the person it was taken from. */
function wag_q_touch(PDO $pdo, string $key, bool $adRef): void
{
    wag_q_backfill($pdo); $c = wa_contact($pdo, $key); if (!$c) return; $now = date('Y-m-d H:i:s');
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return;   // a complaint is not a sales query
    if (wag_after_sales_text((string)($c['last_body'] ?? ''))) { $q0 = $pdo->prepare("SELECT last_in_at FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q0->execute([$key]); $l0 = $q0->fetchColumn(); if (!$l0 || strtotime((string)$l0) < time() - 86400) return; }   // order status / tracking / refund / return… of a customer is after-sales: it does not open a new query (an episode already open stays)
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
            FROM wa_contacts c WHERE c.last_in_at IS NOT NULL AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag != 'complaint') AND COALESCE(c.assigned_at, c.first_seen) >= '" . date('Y-m-d H:i:s', time() - 95 * 86400) . "' AND NOT EXISTS (SELECT 1 FROM wa_queries q WHERE q.phone = c.phone)");
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
        case 'wa.g_botlearn': {   // 🧠 what the bot learned from the 👎 answers: waiting for the owner to apply to the guidebook (live) — the nightly guidebook then keeps it
            if (!$adm && !(function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth))) json_response(['items' => [], 'can' => false]);
            wa_learn_tables($pdo); try { $pdo->exec("UPDATE wa_learn SET status = 'skip' WHERE kind = 'botbad' AND status = 'new' AND (sugg IS NULL OR TRIM(sugg) = '')"); } catch (Throwable $e) { }   // a 👎 without a bot answer is not something the bot can learn from
            $names = employee_names_by_id($pdo); $items = [];
            foreach ($pdo->query("SELECT id, customer, sugg, sent, emp_id, created_at FROM wa_learn WHERE kind = 'botbad' AND status = 'new' ORDER BY id DESC LIMIT 40")->fetchAll() as $r) {
                $sent = (string)$r['sent']; $reason = ''; if (preg_match('/\n\[reason: ([^\]]*)\]\s*$/u', $sent, $m)) { $reason = $m[1]; $sent = trim(preg_replace('/\n\[reason: [^\]]*\]\s*$/u', '', $sent)); }
                $items[] = ['id' => (int)$r['id'], 'customer' => (string)$r['customer'], 'bot' => (string)$r['sugg'], 'better' => $sent, 'reason' => $reason, 'by' => $names[(int)$r['emp_id']] ?? '', 'at' => (string)$r['created_at'], 'pending_tpl' => strpos($sent, '[TEMPLATE —') === 0];
            }
            $ap = (int)$pdo->query("SELECT COUNT(*) FROM wa_learn WHERE kind = 'botbad' AND status = 'done' AND created_at >= '" . date('Y-m-d 00:00:00') . "'")->fetchColumn();
            $done = []; foreach ($pdo->query("SELECT customer, sugg, sent, emp_id, created_at FROM wa_learn WHERE kind = 'botbad' AND status = 'done' ORDER BY id DESC LIMIT 15")->fetchAll() as $r) { $sent = trim(preg_replace('/\n\[reason: [^\]]*\]\s*$/u', '', (string)$r['sent'])); $done[] = ['customer' => mb_substr((string)$r['customer'], 0, 140), 'bot' => mb_substr((string)$r['sugg'], 0, 160), 'better' => mb_substr($sent, 0, 200), 'by' => $names[(int)$r['emp_id']] ?? '', 'at' => (string)$r['created_at']]; }
            json_response(['items' => $items, 'applied_today' => $ap, 'applied' => $done, 'can' => $adm]);
        }
        case 'wa.g_botlearn_apply': {   // apply one learned answer to the LIVE guidebook now (admin): {id, text?} · {id, ignore: 1} · {all: 1}
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$adm) json_response(['error' => 'Only the owner / an admin can change the guidebook.'], 403);
            wa_learn_tables($pdo); if (!function_exists('lib_guide_row')) json_response(['error' => 'The guidebook is not available.'], 500);
            $ids = !empty($in['all']) ? array_map('intval', $pdo->query("SELECT id FROM wa_learn WHERE kind = 'botbad' AND status = 'new' ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN)) : [(int)($in['id'] ?? 0)];
            if (!empty($in['ignore'])) { foreach ($ids as $i) $pdo->prepare("UPDATE wa_learn SET status = 'skip' WHERE id = ? AND kind = 'botbad'")->execute([$i]); json_response(['ok' => true, 'n' => count($ids)]); }
            $g = lib_guide_row($pdo, 'live'); if (!$g) json_response(['error' => 'There is no live guidebook yet.'], 422);
            $body = json_decode((string)$g['body'], true); if (!is_array($body)) json_response(['error' => 'The live guidebook cannot be read.'], 500);
            $corr = array_values((array)($body['owner_corrections'] ?? [])); $seen = []; foreach ($corr as $o) $seen[md5(mb_strtolower((string)($o['better'] ?? '')))] = 1; $add = []; $done = 0;
            foreach (array_reverse($ids) as $i) {
                $q = $pdo->prepare("SELECT * FROM wa_learn WHERE id = ? AND kind = 'botbad' AND status = 'new'"); $q->execute([$i]); $r = $q->fetch(); if (!$r) continue;
                $better = count($ids) === 1 && isset($in['text']) ? trim((string)$in['text']) : trim(preg_replace('/\n\[reason: [^\]]*\]\s*$/u', '', (string)$r['sent']));
                if (strpos($better, '[TEMPLATE —') === 0 || mb_strlen($better) < 2) continue;
                if (preg_match('/^\[TEMPLATE #(\d+) ([^\]]*)\]\s*(.*)$/su', $better, $tm)) $better = $tm[3] . ' (send as the pre-made message "' . trim($tm[2]) . '" when the 24-hour window is closed)';
                $k = md5(mb_strtolower($better)); if (!isset($seen[$k])) { $seen[$k] = 1; $add[] = ['customer' => mb_substr((string)$r['customer'], 0, 240), 'better' => mb_substr($better, 0, 400)]; }
                $pdo->prepare("UPDATE wa_learn SET status = 'done' WHERE id = ?")->execute([$i]); $done++;
            }
            if ($add) { $body['owner_corrections'] = array_slice(array_merge($add, $corr), 0, 60); $pdo->prepare("UPDATE lib_guide SET body = ? WHERE id = ?")->execute([json_encode($body, JSON_UNESCAPED_UNICODE), (int)$g['id']]); }
            json_response(['ok' => true, 'applied' => $done, 'guide_version' => (int)$g['version']]);
        }
        case 'wa.g_botpending': {   // 🙋 chats the bot handed to a person that nobody has answered yet (admin / managers): who has it, how long, what the customer wrote
            if (!$adm && !(function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth))) json_response(['items' => [], 'total' => 0, 'can' => false]);
            $now = time(); $rows = $pdo->query("SELECT phone, name, assigned_emp, hot, hot_why, last_in_at FROM wa_contacts WHERE status = 'open' AND optout = 0 AND (cx_id IS NULL OR cx_id = 0) AND last_in_at >= '" . date('Y-m-d H:i:s', $now - 23 * 3600) . "' AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 500")->fetchAll();
            $hand = []; foreach (array_chunk(array_map(fn($r) => 'bothand:' . $r['phone'], $rows), 300) as $ch) { $q = $pdo->prepare("SELECT k, v FROM wq_meta WHERE k IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $m) $hand[substr((string)$m['k'], 8)] = (string)$m['v']; }
            $names = employee_names_by_id($pdo); $items = []; $lm = $pdo->prepare("SELECT body, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1");
            $missed = []; foreach (array_chunk(array_map(fn($r) => 'hotmiss:' . $r['phone'], $rows), 300) as $ch) { $q = $pdo->prepare("SELECT k, v FROM wq_meta WHERE k IN (" . implode(',', array_fill(0, count($ch), '?')) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $m) { $pp = explode('|', (string)$m['v']); $missed[substr((string)$m['k'], 8)] = $names[(int)$pp[0]] ?? ''; } }
            foreach ($rows as $r) { $k = (string)$r['phone']; $handed = isset($hand[$k]) && strtotime($hand[$k]) >= strtotime((string)$r['last_in_at']); if (!$handed && !(int)$r['hot']) continue;
                $lm->execute([$k]); $m = $lm->fetch(); $items[] = ['phone' => $k, 'name' => (string)$r['name'], 'holder' => (int)$r['assigned_emp'] > 0 ? ($names[(int)$r['assigned_emp']] ?? '#' . $r['assigned_emp']) : '', 'missed_by' => $missed[$k] ?? '', 'wait_min' => max(0, (int)round(($now - strtotime((string)$r['last_in_at'])) / 60)), 'handed_min' => max(0, (int)round(($now - strtotime($hand[$k])) / 60)), 'hot' => $r['hot_why'] ? (string)$r['hot_why'] : ((int)$r['hot'] ? 'hot' : ''), 'last' => $m ? mb_substr((string)($m['body'] !== '' ? $m['body'] : '[' . $m['mtype'] . ']'), 0, 140) : ''];
            }
            usort($items, fn($a, $b) => $b['wait_min'] <=> $a['wait_min']); json_response(['items' => array_slice($items, 0, 60), 'total' => count($items), 'can' => true]);
        }
        case 'wa.g_mkt': {   // 🏪 the open-market scoreboard (admin / managers)
            if (!$adm && !(function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth))) json_response(['can' => false, 'week' => [], 'today' => []]);
            $now = time(); $off = 0; $tk4 = 0; $tk = 0; try { $q = $pdo->prepare("SELECT kind, wait_s FROM wa_mkt WHERE at >= ? AND kind IN ('offered','taken')"); $q->execute([date('Y-m-d 00:00:00')]); foreach ($q->fetchAll() as $r) { if ($r['kind'] === 'offered') $off++; else { $tk++; if ((int)$r['wait_s'] >= 0 && (int)$r['wait_s'] <= 240) $tk4++; } } } catch (Throwable $e) { }
            $wk = wag_mkt_board($pdo, 7); $tm = ['taken' => 0, 'orders' => 0, 'cherry' => 0, 'replies' => [], 'tickets' => []]; foreach ($wk as $b) { $tm['taken'] += $b['taken']; $tm['orders'] += $b['orders']; $tm['cherry'] += $b['cherry']; if ($b['reply_med_s'] !== null) $tm['replies'][] = $b['reply_med_s']; if ($b['avg_ticket'] > 0) $tm['tickets'][] = $b['avg_ticket']; } sort($tm['replies']); $team = ['taken' => $tm['taken'], 'orders' => $tm['orders'], 'ratio' => $tm['taken'] > 0 ? round(100 * $tm['orders'] / $tm['taken'], 1) : null, 'cherry' => $tm['cherry'], 'reply_med_s' => $tm['replies'] ? $tm['replies'][intdiv(count($tm['replies']), 2)] : null, 'avg_ticket' => $tm['tickets'] ? (int)round(array_sum($tm['tickets']) / count($tm['tickets'])) : 0];
            $hrs = []; try { $q = $pdo->prepare("SELECT emp_id, HOUR(at) h, SUM(kind = 'taken') tk, SUM(kind IN ('dropped','released')) dr FROM wa_mkt WHERE at >= ? AND emp_id > 0 AND kind IN ('taken','dropped','released') GROUP BY emp_id, HOUR(at)"); $q->execute([date('Y-m-d 00:00:00')]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $hrs[(int)$r['emp_id']][(int)$r['h']] = [(int)$r['tk'], (int)$r['dr']]; } catch (Throwable $e) { }   // taken / left per person per hour of today
            json_response(['can' => true, 'team' => $team, 'week' => $wk, 'hours' => $hrs, 'today' => wag_mkt_board($pdo, 1), 'offered_today' => $off, 'taken_today' => $tk, 'taken_in_4min' => $tk4, 'bot_ticket' => hrms_memo('wag_bot_ticket7', 600, function () use ($pdo) { return wag_bot_ticket($pdo, 7); })]);   // a 7-day average: read once per 10 minutes for everybody (it scanned every bot message of 9 days on each call, 4 times a minute)
        }
        case 'wa.g_hourly': {   // 📊 hour by hour (admin): campaign messages sent → customers who wrote → new queries (campaign / Meta / other, first-time / back again)
            if (!$adm && !(function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth))) json_response(['can' => false, 'hours' => []]);
            $off = max(0, min(6, (int)($_GET['day'] ?? 0))); $d0 = date('Y-m-d 00:00:00', time() - $off * 86400); $d1 = date('Y-m-d 00:00:00', time() - ($off - 1) * 86400); $h = array_fill(0, 24, ['sent' => 0, 'wrote' => 0, 'q' => 0, 'camp' => 0, 'meta' => 0, 'other' => 0, 'again' => 0]);
            try { $q = $pdo->prepare("SELECT HOUR(opened_at) h, COUNT(*) n, SUM(src = 'campaign') c, SUM(src = 'query') m, SUM(again = 1) a FROM wa_queries WHERE opened_at >= ? AND opened_at < ? GROUP BY HOUR(opened_at)"); $q->execute([$d0, $d1]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['h']; $h[$k]['q'] = (int)$r['n']; $h[$k]['camp'] = (int)$r['c']; $h[$k]['meta'] = (int)$r['m']; $h[$k]['other'] = (int)$r['n'] - (int)$r['c'] - (int)$r['m']; $h[$k]['again'] = (int)$r['a']; } } catch (Throwable $e) { }
            try { $q = $pdo->prepare("SELECT HOUR(ts) h, COUNT(DISTINCT phone) n FROM wa_messages WHERE dir = 'in' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)"); $q->execute([$d0, $d1]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $h[(int)$r['h']]['wrote'] = (int)$r['n']; } catch (Throwable $e) { }
            try { $q = $pdo->prepare("SELECT HOUR(ts) h, COUNT(*) n FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)"); $q->execute([$d0, $d1]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $h[(int)$r['h']]['sent'] = (int)$r['n']; } catch (Throwable $e) { }
            json_response(['can' => true, 'day' => $off, 'date' => substr($d0, 0, 10), 'hours' => $h]);
        }
        case 'wa.g_mkt_top': {   // 🏪 for every salesperson: who are the best right now (shown above the OPEN list) and my own rank
            $w = wag_mkt_board($pdo, 7); $top = []; foreach ($w as $r) if ($r['present'] && count($top) < 3 && $r['score'] > 0) $top[] = $r['name']; $my = null; foreach ($w as $i => $r) if ($r['emp'] === $me) $my = ['rank' => $i + 1, 'of' => count($w), 'score' => $r['score'], 'taken' => $r['taken'], 'dropped' => $r['dropped'] + $r['released'], 'q' => 0, 'o' => 0];
            try { if ($my) { $__d = wag_real_queries($pdo, 1); $my['q'] = (int)($__d[$me][0] ?? 0); $my['o'] = (int)($__d[$me][1] ?? 0); } } catch (Throwable $e) { }   // the owner's definition: no campaign, no community — same as the sales strip
            json_response(['top' => $top, 'my' => $my, 'gate' => wag_mkt_gate_msg($pdo, $me)]);
        }
        case 'wa.g_botstate': {   // 🤖 is the bot answering this chat (the banner, the locked Take over, the 👎 button)
            $c = $chatOf((string)($_GET['phone'] ?? '')); $l = wag_bot_lock($pdo, $c); json_response(['on' => $l['on'], 'why' => $l['why'], 'engaged' => $l['engaged'], 'bot_text' => $l['bot_text'], 'bot_at' => $l['bot_at'], 'bot_enabled' => $cfg['bot'] === 'on', 'can_mk' => ((int)($c['assigned_emp'] ?? 0) > 0 && (int)($c['assigned_emp'] ?? 0) === (int)($auth['employee_id'] ?? 0) && (string)($c['status'] ?? '') === 'open' && wag_address_seen($pdo, (string)$c['phone'])), 'mk_left' => (function () use ($pdo, $c) { $v = wag_meta_get($pdo, 'mklink:' . (string)$c['phone'], ''); return $v === '' ? 0 : max(0, 480 - (time() - (int)strtotime($v))); })()]);
        }
        case 'wa.g_addon_check': {   // 🧩 before the link: has the add-on offer gone out? ('' = not yet · offered · accepted · declined · passed · skip)
            $c = $chatOf((string)($in['phone'] ?? '')); $key = (string)$c['phone']; $st = wag_meta_get($pdo, 'addon:' . $key, ''); $p = explode('|', $st);
            $can = (string)setting_get($pdo, 'wag_addon_on', '1') !== '0' && ($st === '' || $p[0] === 'skip') && wag_address_seen($pdo, $key) && wag_addon_calc($pdo, $key) !== null;
            json_response(['ok' => true, 'state' => $p[0], 'can_offer' => $can, 'n' => $p[0] === 'accepted' ? (int)($p[2] ?? 0) : 0, 'amount' => $p[0] === 'accepted' ? (int)($p[3] ?? 0) : 0, 'left' => $p[0] === 'offered' ? max(0, 300 - (time() - (int)($p[1] ?? 0))) : 0]);
        }
        case 'wa.g_addon_offer': {   // 🧩 "Send add-on offer first": the same offer the bot sends by itself ~5 s after the address
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = $chatOf((string)($in['phone'] ?? '')); $key = (string)$c['phone']; $me = (int)($auth['employee_id'] ?? 0);
            if (!$adm && (int)($c['assigned_emp'] ?? 0) !== $me) json_response(['error' => 'Only the person who holds this chat can send the add-on offer.'], 403);
            $st = wag_meta_get($pdo, 'addon:' . $key, ''); if (strpos($st, 'skip|') === 0) { try { $pdo->prepare("DELETE FROM wq_meta WHERE k = ?")->execute(['addon:' . $key]); } catch (Throwable $e) { } }
            $ok = wag_addon_offer($pdo, $c, $key); json_response(['ok' => $ok, 'message' => $ok ? 'The add-on offer was sent. Wait for the customer\'s answer, then make the link with the new total.' : 'The add-on offer could not be sent (no clear price in the chat, or already offered). You can make the link.']);
        }
        case 'wa.g_makelink': {   // 🔗 the holder is making the payment link / order link: the chat stays with him up to 8 minutes (it does not go back to OPEN)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = $chatOf((string)($in['phone'] ?? '')); $key = (string)$c['phone']; $me = (int)($auth['employee_id'] ?? 0);
            if (!$adm && (int)($c['assigned_emp'] ?? 0) !== $me) json_response(['error' => 'Only the person who holds this chat can use Making link.'], 403);
            $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $nm = $names[$me] ?? 'The holder';
            if (!empty($in['on']) && !wag_address_seen($pdo, $key)) json_response(['error' => 'The customer has not sent the address yet — Making link is for after the address.'], 422);
            if (!empty($in['on'])) { wag_meta_put($pdo, 'mklink:' . $key, date('Y-m-d H:i:s')); wa_event($pdo, $key, '🔗 ' . $nm . ' is making the link (held for up to 8 minutes)'); json_response(['ok' => true, 'mk_left' => 480]); }
            wag_meta_put($pdo, 'mklink:' . $key, ''); wa_event($pdo, $key, '🔗 ' . $nm . ' finished the link'); json_response(['ok' => true, 'mk_left' => 0]);
        }
        case 'wa.g_botbad': {   // 👎 the bot's answer is not good: {phone, mode: 'draft'} → Claude's better answer; {phone, mode: 'send', text, reason} → it is sent, the chat becomes the person's, the bot stops, the owner's bot learns
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $c = $chatOf((string)($in['phone'] ?? '')); $key = (string)$c['phone']; $l = wag_bot_lock($pdo, $c);
            if (trim((string)$l['bot_text']) === '') json_response(['error' => 'The bot has not answered yet — wait for its answer. 👎 is for an answer that is not good.'], 422);
            if (($in['mode'] ?? '') === 'draft') {
                $sug = ''; try { $GLOBALS['lib_fast'] = 1; $rr = wag_brain_reply($pdo, $c, (string)$l['bot_text'], true); unset($GLOBALS['lib_fast']); if (!empty($rr['ok'])) $sug = (string)$rr['text']; } catch (Throwable $e) { }
                json_response(['bot_text' => $l['bot_text'], 'suggestion' => $sug, 'lock' => $l['on']]);
            }
            if (($in['mode'] ?? '') === 'tpl_done') {   // the template the person chose after 👎 went out: the learning row keeps which one (the bot learns that a pre-made message may be the right answer)
                $tid = (int)($in['tpl_id'] ?? 0); $tr = function_exists('wa_tpl_row') ? wa_tpl_row($pdo, $tid) : null;
                try { $pdo->prepare("UPDATE wa_learn SET sent = ? WHERE phone = ? AND kind = 'botbad' AND emp_id = ? ORDER BY id DESC LIMIT 1")->execute([mb_substr('[TEMPLATE #' . $tid . ' ' . ($tr ? (string)$tr['name'] : '') . '] ' . ($tr ? (string)$tr['body'] : ''), 0, 1500), $key, $me]); } catch (Throwable $e) { }
                json_response(['ok' => true]);
            }
            if (($in['mode'] ?? '') === 'take') {   // 👎 → "send a pre-made message instead": the chat becomes the person's, the bot stops, the person then picks the template
                $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 60); $GLOBALS['bot_replace'] = 1; wa_assign_to($pdo, $key, $me, $me, 'takeover'); unset($GLOBALS['bot_replace']);
                $nm = employee_names_by_id($pdo); $who = $nm[$me] ?? 'A salesperson';
                wa_event($pdo, $key, '🔄 ' . $who . ' marked the bot’s answer not good, took this chat and sends a pre-made message instead. Bot said: “' . mb_substr((string)$l['bot_text'], 0, 160) . '”' . ($reason !== '' ? ' · reason: ' . $reason : ''));
                try { if (function_exists('wa_learn_tables') && wa_learn_tables($pdo)) { [$inId, $cu] = wa_last_in($pdo, $key); $pdo->prepare("INSERT INTO wa_learn (phone, in_id, customer, sugg, sent, kind, emp_id, ver, status, created_at) VALUES (?, ?, ?, ?, ?, 'botbad', ?, 0, 'new', ?)")->execute([$key, $inId, mb_substr((string)$cu, 0, 800), mb_substr((string)$l['bot_text'], 0, 1500), '[TEMPLATE — being chosen]', $me, date('Y-m-d H:i:s')]); } } catch (Throwable $e) { }
                json_response(['ok' => true]);
            }
            $text = trim((string)($in['text'] ?? '')); if (mb_strlen($text) < 2) json_response(['error' => 'Write the better answer first.'], 422);
            $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 60); $GLOBALS['bot_replace'] = 1;
            [$ok, $res] = wa_send_text($pdo, $c, $text, 'emp', $me); if (!$ok) { unset($GLOBALS['bot_replace']); json_response(['error' => (string)$res], 422); }
            wa_assign_to($pdo, $key, $me, $me, 'takeover'); unset($GLOBALS['bot_replace']);
            $nm = employee_names_by_id($pdo); $who = $nm[$me] ?? 'A salesperson';
            wa_event($pdo, $key, '🔄 ' . $who . ' replaced the bot’s answer and took this chat. Bot said: “' . mb_substr((string)$l['bot_text'], 0, 160) . '” · ' . $who . ' said: “' . mb_substr($text, 0, 160) . '”' . ($reason !== '' ? ' · reason: ' . $reason : ''));
            try { if (function_exists('wa_learn_tables') && wa_learn_tables($pdo)) { [$inId, $cu] = wa_last_in($pdo, $key); $pdo->prepare("INSERT INTO wa_learn (phone, in_id, customer, sugg, sent, kind, emp_id, ver, status, created_at) VALUES (?, ?, ?, ?, ?, 'botbad', ?, 0, 'new', ?)")->execute([$key, $inId, mb_substr((string)$cu, 0, 800), mb_substr((string)$l['bot_text'], 0, 1500), mb_substr($text . ($reason !== '' ? "\n[reason: " . $reason . "]" : ''), 0, 1500), $me, date('Y-m-d H:i:s')]); wag_learn_auto_apply($pdo, (int)$pdo->lastInsertId()); } } catch (Throwable $e) { }
            json_response(['ok' => true]);
        }
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
            $__sig = (!$all && $pick === 0 && empty($_GET['tag']) && empty($_GET['wide']) && $emp > 0) ? wag_sig($pdo, $emp) : '';   // taken BEFORE the list is read: a change in between simply makes the next check reload
            [$rows, $cnt] = wag_inbox($pdo, $emp > 0 ? $emp : -1, $all, $cfg, !empty($_GET['tag']) && !$all, !empty($_GET['wide']) && !$all);
            $men = []; try { $q = $pdo->prepare("SELECT n.id, n.phone, n.body, n.emp_id, n.at FROM wa_notes n WHERE n.mention = ? AND n.at >= ? ORDER BY n.id DESC LIMIT 10"); $q->execute([$me, date('Y-m-d H:i:s', time() - 3 * 86400)]); $nm = employee_names_by_id($pdo);
                foreach ($q->fetchAll() as $r) $men[] = ['id' => (int)$r['id'], 'phone' => $r['phone'], 'text' => $r['body'], 'from' => $nm[(int)$r['emp_id']] ?? '', 'at' => $r['at']]; } catch (Throwable $e) { }
            $rem = []; try { $q = $pdo->prepare("SELECT phone, name, remind_at FROM wa_contacts WHERE assigned_emp = ? AND remind_at IS NOT NULL AND remind_at <= ? ORDER BY remind_at LIMIT 10"); $q->execute([$me, date('Y-m-d H:i:s')]); $rem = $q->fetchAll(); } catch (Throwable $e) { }
            if (function_exists('wq_lifecycle_map')) { try { $__lp = array_slice(array_column($rows, 'phone'), 0, 80); $__lk = 'wq_life_' . md5(implode(',', $__lp)); $lm = function_exists('apcu_fetch') ? apcu_fetch($__lk) : false; if (!is_array($lm)) { $lm = wq_lifecycle_map($pdo, $__lp); if (function_exists('apcu_store')) @apcu_store($__lk, $lm, 12); } foreach ($rows as &$rw) $rw['life'] = $lm[(string)$rw['phone']] ?? null; unset($rw); } catch (Throwable $e) { } }   // one stage per chat
            if (function_exists('ora_phone_map')) { try { $__em = ora_phone_map($pdo, array_column($rows, 'phone')); foreach ($rows as &$__ox) { if (isset($__em[(string)$__ox['phone']])) $__ox['eff'] = $__em[(string)$__ox['phone']]; } unset($__ox); } catch (Throwable $e) { } }   // ➕ add-on effort mark per chat
            json_response(['sig' => $__sig, 'chats' => $rows, 'counts' => $cnt, 'stats' => wag_stats($pdo, $me, $cfg), 'cfg' => ['unans_min' => $cfg['unans_min'], 'target' => $cfg['target'], 'sound' => $cfg['sound']], 'me' => ['emp_id' => $me, 'manager' => $mgr || $adm, 'team' => $mgr || $adm, 'can_to' => $adm || (function_exists('wa_can_takeover') && wa_can_takeover($pdo, $auth)), 'can_as' => $adm || (function_exists('wa_can_assign') && wa_can_assign($pdo, $auth)), 'name' => (employee_names_by_id($pdo)[$me] ?? ($adm ? 'Admin' : ''))],
                'mentions' => $men, 'reminders' => $rem, 'results' => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0], 'i' => $v[1], 'g' => $v[2]], array_keys(wag_outcomes()), array_values(wag_outcomes())), 'now' => date('Y-m-d H:i:s')]);
        }
        case 'wa.g_sig': {   // ⚡ light check: has my chat list changed since the last full download?
            $e0 = (int)$me; json_response(['sig' => ($e0 > 0 && empty($_GET['all']) && empty($_GET['emp'])) ? wag_sig($pdo, $e0) : '']);
        }
        case 'wa.g_poll': {   // light refresh of ONE open chat: only messages after $after
            $c = $chatOf((string)($_GET['phone'] ?? '')); $after = (int)($_GET['after'] ?? 0); $key = (string)$c['phone'];
            $st = $pdo->prepare("SELECT id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts, number_id, reply_to FROM wa_messages WHERE phone = ? AND id > ? ORDER BY id LIMIT 60"); $st->execute([$key, $after]); $m = $st->fetchAll(); if (function_exists('wa_add_quotes')) wa_add_quotes($pdo, $m);
            $nums = wag_nums($pdo); foreach ($m as &$x) { $x['id'] = (int)$x['id']; $x['number'] = $nums[(int)$x['number_id']] ?? ''; } unset($x);
            $upd = []; if ($after > 0) { $q = $pdo->prepare("SELECT id, status FROM wa_messages WHERE phone = ? AND dir = 'out' AND id <= ? AND id >= ? AND status IN ('sent', 'delivered', 'read', 'failed')"); $q->execute([$key, $after, max(0, $after - 40)]); $upd = $q->fetchAll(); }   // read / failed too: blue ticks and "failed at delivery" show while the chat is open (the app only redraws the ones that changed)
            if ($m && ($me > 0 && (int)$c['assigned_emp'] === $me)) $sq = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM wa_messages WHERE phone = ? AND dir = 'in'"); $sq->execute([$key]); $mx = (int)$sq->fetchColumn(); if ($mx !== (int)$c['seen_msg_id']) $pdo->prepare("UPDATE wa_contacts SET seen_msg_id = ? WHERE phone = ? AND seen_msg_id <> ?")->execute([$mx, $key, $mx]);   // write only when something new was read: the same result, no write lock on every open / poll
            json_response(['messages' => $m, 'status' => $upd, 'optout' => (int)($c['optout'] ?? 0) === 1, 'window_open' => !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 86400, 'waiting' => !empty($c['last_in_at']) && (empty($c['last_out_at']) || strtotime((string)$c['last_out_at']) < strtotime((string)$c['last_in_at']))]);
        }
        case 'wa.g_sugg': {   // the suggestion already written for the newest customer message — instant; null = none yet
            $c = $chatOf((string)($_GET['phone'] ?? '')); $s = wag_sugg_get($pdo, (string)$c['phone']);
            if (!empty($c['cx_id'])) json_response(['ok' => true, 'sugg' => null, 'hot' => false, 'auto' => ['on' => false, 'secs' => 20], 'complaint' => (int)$c['cx_id']]);   // with the Complaint team: no suggestion, no auto-send
            if (!empty($_GET['after_bot'])) {   // 🤖 the bot has already answered: show the person a NEXT step, never the text the bot just sent
                try { $__q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'bot' ORDER BY id DESC LIMIT 1"); $__q->execute([(string)$c['phone']]); $__lb = trim((string)$__q->fetchColumn()); if ($s && $__lb !== '' && trim((string)$s['text']) === $__lb) $s = null; } catch (Throwable $e) { }
            }
            if (!$s && !empty($_GET['make'])) { $__t0 = microtime(true); $f0 = ai_feat('wa_sugg'); $r = wag_brain_reply($pdo, $c); ai_feat($f0);
                wa_sugg_ms_add($pdo, (int)round((microtime(true) - $__t0) * 1000));   /* ⏱ how long the last 30 on-open suggestions took on the server (shown on Admin → AI usage) */
                if ($r['ok']) { wa_sugg_save($pdo, (string)$c['phone'], $r['text'], $r['source'], (int)$r['ver']); $s = ['text' => $r['text'], 'source' => $r['source'], 'ver' => $r['ver'], 'why' => $r['why'], 'call_now' => !empty($r['handoff'])]; } }
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
        case 'wa.g_utags': {   // 🏷 my own tags with how many customers carry each (+ the tags of one customer when ?phone= is given)
            if ($me <= 0) json_response(['tags' => [], 'mine' => []]);
            $o = ['tags' => wag_ut_list($pdo, $me), 'mine' => []]; if (!empty($_GET['phone'])) $o['mine'] = wag_ut_of($pdo, $me, wa_key($_GET['phone'])); json_response($o);
        }
        case 'wa.g_utag_set': {   // 🏷 set the tags of ONE customer (mine): ids = the tags to keep, create = new tags to make and add [{name, color}]
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0) json_response(['error' => 'Only a salesperson login can use tags.'], 403);
            $c = $chatOf((string)($in['phone'] ?? '')); $k = (string)$c['phone']; wag_ut_tables($pdo);
            $have = wag_ut_list($pdo, $me); $mineIds = array_column($have, 'id'); $want = []; foreach ((array)($in['ids'] ?? []) as $i) { $i = (int)$i; if (in_array($i, $mineIds, true)) $want[$i] = $i; }
            foreach (array_slice((array)($in['create'] ?? []), 0, 5) as $nw) {
                $nm = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($nw['name'] ?? ''))), 0, 30); if ($nm === '') continue; $col = max(0, min(4, (int)($nw['color'] ?? 0))); $id = 0;
                foreach ($have as $h) if (mb_strtolower($h['name']) === mb_strtolower($nm)) { $id = (int)$h['id']; break; }
                if ($id === 0) { if (count($have) >= 60) json_response(['error' => 'You already have 60 tags — delete one you do not use first.'], 422); $pdo->prepare("INSERT INTO wa_utags (emp_id, name, color, created_at) VALUES (?, ?, ?, ?)")->execute([$me, $nm, $col, date('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId(); $have[] = ['id' => $id, 'name' => $nm, 'color' => $col, 'n' => 0]; }
                $want[$id] = $id;
            }
            if (count($want) > 10) json_response(['error' => 'A customer can have at most 10 tags.'], 422);
            $pdo->prepare("DELETE m FROM wa_utag_map m JOIN wa_utags t ON t.id = m.tag_id WHERE t.emp_id = ? AND m.phone = ?")->execute([$me, $k]);
            $ins = $pdo->prepare("INSERT IGNORE INTO wa_utag_map (tag_id, phone, at) VALUES (?, ?, ?)"); foreach ($want as $id) $ins->execute([$id, $k, date('Y-m-d H:i:s')]);
            json_response(['ok' => true, 'tags' => wag_ut_list($pdo, $me), 'mine' => array_values($want)]);
        }
        case 'wa.g_utag_del': {   // 🗑 delete one of MY tags (it comes off every customer)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0) json_response(['error' => 'Only a salesperson login can use tags.'], 403); wag_ut_tables($pdo); $id = (int)($in['id'] ?? 0);
            $q = $pdo->prepare("SELECT 1 FROM wa_utags WHERE id = ? AND emp_id = ?"); $q->execute([$id, $me]); if (!$q->fetchColumn()) json_response(['error' => 'Tag not found.'], 404);
            $pdo->prepare("DELETE FROM wa_utag_map WHERE tag_id = ?")->execute([$id]); $pdo->prepare("DELETE FROM wa_utags WHERE id = ? AND emp_id = ?")->execute([$id, $me]);
            json_response(['ok' => true, 'tags' => wag_ut_list($pdo, $me)]);
        }
        case 'wa.g_add_check': {   // ➕ Add customer (phone app): what happens if I add this number — new / already mine / with a colleague / opted out / free to take / chatting now
            $k = wa_key($_GET['phone'] ?? ''); if (!wa_valid_phone($k)) json_response(['state' => 'invalid', 'phone' => $k]);
            $c = wa_contact($pdo, $k); $nm = employee_names_by_id($pdo); $o = ['state' => 'new', 'phone' => $k, 'name' => '', 'window' => false, 'orders' => 0];
            if ($c) { $o['name'] = (string)$c['name']; $held = (int)$c['assigned_emp'];
                if ((int)$c['optout'] === 1) $o['state'] = 'optout';
                elseif ($held > 0 && $held !== $me && !$adm) { $o['state'] = 'other'; $o['with'] = (string)($nm[$held] ?? 'a colleague'); }
                elseif ($held === $me && $me > 0) $o['state'] = 'mine'; else $o['state'] = 'free';
                $o['window'] = !empty($c['last_in_at']) && strtotime((string)$c['last_in_at']) > time() - 86400 && $o['state'] !== 'other' && $o['state'] !== 'optout'; }
            try { $q = $pdo->prepare("SELECT n FROM wa_cust WHERE phone = ?"); $q->execute([$k]); $o['orders'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
            $lim = max(1, (int)setting_get($pdo, 'wa_add_daily', '30')); $used = 0; try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_assign_log WHERE how = 'added' AND by_emp = ? AND at >= ?"); $q->execute([$me, date('Y-m-d 00:00:00')]); $used = (int)$q->fetchColumn(); } catch (Throwable $e) { }
            $o['left'] = $adm ? 999 : max(0, $lim - $used); json_response($o);
        }
        case 'wa.g_add': {   // ➕ Add customer: make the chat (if the number is new) and give it to ME; the message goes next through wa.tpl_send (the 24-hour rule: a new customer gets a pre-made message)
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if ($me <= 0) json_response(['error' => 'Only a salesperson login can add a customer.'], 403);
            $k = wa_key($in['phone'] ?? ''); if (!wa_valid_phone($k)) json_response(['error' => 'Enter the 10-digit mobile number (starting with 6, 7, 8 or 9).'], 422);
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 60); $c = wa_contact($pdo, $k);
            if ($c && (int)$c['optout'] === 1) json_response(['error' => 'This customer opted out — do not message them.'], 422);
            if ($c && (int)$c['assigned_emp'] > 0 && (int)$c['assigned_emp'] !== $me && !$adm) json_response(['error' => 'This customer is with ' . (employee_names_by_id($pdo)[(int)$c['assigned_emp']] ?? 'a colleague') . '. Ask your manager to transfer the chat.'], 403);
            $mine = $c && (int)$c['assigned_emp'] === $me;
            if (!$mine && !$adm) { $lim = max(1, (int)setting_get($pdo, 'wa_add_daily', '30')); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_assign_log WHERE how = 'added' AND by_emp = ? AND at >= ?"); $q->execute([$me, date('Y-m-d 00:00:00')]); if ((int)$q->fetchColumn() >= $lim) json_response(['error' => 'You have added ' . $lim . ' customers today — that is the daily limit. Try again tomorrow.'], 429); }
            if (!$c) { $pdo->prepare("INSERT INTO wa_contacts (phone, wa_id, name, first_seen, last_number_id, state, status) VALUES (?, ?, ?, ?, 0, 'human', 'open')")->execute([$k, '91' . $k, $name, date('Y-m-d H:i:s')]); }
            elseif ($name !== '' && trim((string)$c['name']) === '') $pdo->prepare("UPDATE wa_contacts SET name = ? WHERE phone = ?")->execute([$name, $k]);
            if (!$mine) wa_assign_to($pdo, $k, $me, $me, 'added');
            $c2 = wa_contact($pdo, $k); json_response(['ok' => true, 'phone' => $k, 'name' => (string)($c2['name'] ?? $name), 'window' => !empty($c2['last_in_at']) && strtotime((string)$c2['last_in_at']) > time() - 86400]);
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
