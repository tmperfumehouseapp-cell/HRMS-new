<?php
/**
 * wq.php — "WhatsApp Sales" backend (module wq): one query = one record, typed automatically, counted by current holder.
 *
 * Put this file in the SAME folder as api.php (empmanagment/). It is loaded by wa_more.php (BEFORE wa_seg.php, which must stay the last require),
 * creates its own tables (wq_*) the first time it runs and never changes wa_contacts / wa_messages. PHP 7.4 safe.
 * The query ledger itself is the existing wa_queries / wa_query_moves (wa_growth.php); wq_* tables only hang off wa_queries.id.
 *
 * What it does
 *   typing      every new query gets a type (meta | campaign | community | regenerated | new | other) from rules the owner taught, the ad, a campaign
 *               we sent, the community tag, a long silence or a first message; when nothing fits it becomes a QUESTION for the owner (Query types page).
 *   tags        wa_ctags rows with src 'wq': meta_query / campaign_query / community_query / regenerated_query / new_query / other_query,
 *               arrive_morning|afternoon|evening|night, premium_vip|premium_high (never wiped by Claude's re-tagging).
 *   no answer   if the holder is off duty or silent for wq_hold_sec the customer gets ONE automatic reply (who='auto'); if still nobody answers after
 *               wq_esc_min a second one, the query goes to "Attention needed" and the admins get a push. Max 2 automatic messages per query.
 *   board       per person: queries by type, transfers, reply time, orders, conversion, hours, behind badge. Bot vs person comparison. What won.
 *   adapter     wq_qcount* : old typed counts before the cut-over date, WhatsApp Sales counts from it, never both for one day.
 *   tick        wq_tick(): backfill, typing of untyped queries, summaries, orders, no-answer flow, daily summary, questions mirror. Idempotent, time-boxed,
 *               GET_LOCK('wq_tick',0), never throws. Called from wa.cron and (gated) from wa_tick.
 *
 * Actions: wq.board wq.mine wq.attention wq.assign wq.on_duty wq.queries wq.query wq.retype wq.tags_for wq.types_state wq.types_next wq.types_answer wq.rules
 *          wq.compare wq.whatwon wq.whatwon_act wq.summary wq.settings wq.cutover wq.status wq.tick wq.typing   (contract: /work/wa/Q_J_api.md)
 * Hooks (all gated with function_exists in the other files): wq_after_in, wq_after_assign, wq_bypass_move, wq_handover_pre/post, wq_guard, wq_send_guard,
 *          wq_bot_context, wq_tick.
 * Kill switch: setting wq_on = 0 makes every hook a no-op (read actions still answer with "on":false).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wq_tables')) {

// ------------------------------------------------------------------------------------------------------------------ small helpers
/** "Now" (unix). A test can define WQ_NOW to move the clock. */
function wq_now(): int { return defined('WQ_NOW') ? (int)WQ_NOW : time(); }
function wq_dt(int $ts = 0): string { return date('Y-m-d H:i:s', $ts > 0 ? $ts : wq_now()); }
function wq_ts($s): int { $t = $s === null || $s === '' ? 0 : strtotime((string)$s); return $t ? (int)$t : 0; }
function wq_in(array $a): string { return implode(',', array_fill(0, max(1, count($a)), '?')); }
function wq_name(array $names, int $e): string { return $e > 0 ? (string)($names[$e] ?? '') : ''; }
function wq_person(array $names, int $e): array { return ['emp_id' => $e, 'name' => wq_name($names, $e)]; }
function wq_names(PDO $pdo): array { static $m = null; if ($m === null) { try { $m = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; } catch (Throwable $e) { $m = []; } } return $m; }
function wq_num($v, int $d = 1) { return $v === null ? null : round((float)$v, $d); }
function wq_median(array $a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? (float)$a[intdiv($n, 2)] : ((float)$a[$n / 2 - 1] + (float)$a[$n / 2]) / 2; }
function wq_pct($a, $b) { return $b > 0 ? round(100 * $a / $b, 1) : null; }
function wq_log(PDO $pdo, string $info, bool $ok = true): void { if (function_exists('wa_log')) { try { wa_log($pdo, 'wq', $ok, $info); } catch (Throwable $e) { } } }

// ------------------------------------------------------------------------------------------------------------------ settings
function wq_defaults(): array
{
    return ['wq_on' => '1', 'wq_auto_on' => '0', 'wq_hold_sec' => '20', 'wq_esc_min' => '5', 'wq_attr_days' => '7', 'wq_camp_days' => '3', 'wq_min_n' => '30', 'wq_goal_queries' => '23', 'wq_goal_orders' => '', 'wq_goal_reply_min' => '3',
        'wq_hold_text' => "Namaste! Aapka message mil gaya hai, hamari team ka koi member yahin aapko reply karega.\nThank you for writing to TM Perfume House - our team will reply here shortly.",
        'wq_esc_text' => "Aapko wait karna pad raha hai, maaf kijiye. Humne aapka message team ko dobara bhej diya hai.\nSorry to keep you waiting - we have passed your message to our team again.",
        'wq_cutover' => ''];
}
function wq_limits(): array { return ['wq_hold_sec' => [5, 600], 'wq_esc_min' => [1, 120], 'wq_attr_days' => [1, 30], 'wq_camp_days' => [1, 30], 'wq_min_n' => [5, 500], 'wq_goal_queries' => [1, 500], 'wq_goal_reply_min' => [1, 120]]; }
/** One setting, read straight from hrms_settings (never the per-request cache of setting_get, so a value saved a moment ago is seen). */
function wq_get(PDO $pdo, string $k, $d = null)
{
    $m = &$GLOBALS['__wq_set'];
    if (!isset($m[$k])) { try { $q = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $q->execute([$k]); $v = $q->fetchColumn(); $m[$k] = [$v === false ? null : $v]; } catch (Throwable $e) { $m[$k] = [null]; } }
    return $m[$k][0] === null ? $d : $m[$k][0];
}
function wq_put(PDO $pdo, string $k, $v): void
{
    try { $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, (string)$v]); } catch (Throwable $e) { return; }
    $GLOBALS['__wq_set'][$k] = [(string)$v];
}
/** All wq settings with defaults and clamps. */
function wq_cfg(PDO $pdo): array
{
    $o = []; foreach (wq_defaults() as $k => $d) { $v = wq_get($pdo, $k, null); $o[$k] = $v === null || ($v === '' && $k !== 'wq_cutover') ? $d : (string)$v; }
    foreach (wq_limits() as $k => [$lo, $hi]) $o[$k] = max($lo, min($hi, (int)$o[$k]));
    $go = trim((string)wq_get($pdo, 'wq_goal_orders', '')); $o['wq_goal_orders'] = ctype_digit($go) && (int)$go > 0 ? min(500, (int)$go) : null;   // empty = no orders bar
    $o['wq_on'] = (string)$o['wq_on'] === '0' ? 0 : 1; $o['wq_auto_on'] = (string)$o['wq_auto_on'] === '1' ? 1 : 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$o['wq_cutover'])) $o['wq_cutover'] = '';
    return $o;
}
function wq_on(PDO $pdo): bool { return (string)wq_get($pdo, 'wq_on', '1') !== '0'; }
function wq_meta_get(PDO $pdo, string $k, string $d = ''): string { try { $q = $pdo->prepare("SELECT v FROM wq_meta WHERE k = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function wq_meta_put(PDO $pdo, string $k, string $v): void { try { $pdo->prepare("INSERT INTO wq_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]); } catch (Throwable $e) { } }

// ------------------------------------------------------------------------------------------------------------------ tables
function wq_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT NULL) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_qtype (query_id INT NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL, type VARCHAR(12) NOT NULL DEFAULT 'unclear', reason VARCHAR(220) NOT NULL DEFAULT '',
            auto TINYINT NOT NULL DEFAULT 1, rule_id INT NOT NULL DEFAULT 0, camp_id INT NOT NULL DEFAULT 0, camp_emp INT NOT NULL DEFAULT 0, set_by INT NOT NULL DEFAULT 0, at DATETIME NULL,
            opened_at DATETIME NOT NULL, st VARCHAR(6) NOT NULL DEFAULT 'ok', KEY k_open (opened_at, type), KEY k_phone (phone), KEY k_st (st, opened_at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_tlog (id INT AUTO_INCREMENT PRIMARY KEY, query_id INT NOT NULL, type VARCHAR(12) NOT NULL, reason VARCHAR(220) NOT NULL DEFAULT '', set_by INT NOT NULL DEFAULT 0, auto TINYINT NOT NULL DEFAULT 1, at DATETIME NULL, KEY k_q (query_id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_rules (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, val VARCHAR(190) NOT NULL, type VARCHAR(12) NOT NULL, active TINYINT NOT NULL DEFAULT 1,
            hits INT NOT NULL DEFAULT 0, last_hit DATETIME NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, KEY k_act (active, id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_qstat (query_id INT NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL, opened_at DATETIME NOT NULL, first_in_at DATETIME NULL, first_out_at DATETIME NULL, first_person_at DATETIME NULL,
            first_person_min DOUBLE NULL, first_by VARCHAR(8) NOT NULL DEFAULT '', person_n INT NOT NULL DEFAULT 0, bot_n INT NOT NULL DEFAULT 0, grp VARCHAR(16) NOT NULL DEFAULT '', answered TINYINT NOT NULL DEFAULT 0,
            stopped TINYINT NOT NULL DEFAULT 0, orders INT NOT NULL DEFAULT 0, revenue DECIMAL(12,2) NOT NULL DEFAULT 0, first_order VARCHAR(40) NOT NULL DEFAULT '', first_order_at DATETIME NULL, first_order_amt DECIMAL(12,2) NOT NULL DEFAULT 0,
            final TINYINT NOT NULL DEFAULT 0, stat_at DATETIME NULL, KEY k_open (opened_at), KEY k_todo (final, stat_at), KEY k_phone (phone)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_ord (okey VARCHAR(90) NOT NULL PRIMARY KEY, phone VARCHAR(10) NOT NULL, order_name VARCHAR(40) NOT NULL DEFAULT '', total DECIMAL(12,2) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
            KEY k_phone (phone, created_at), KEY k_created (created_at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_guard (phone VARCHAR(12) NOT NULL, msg_id BIGINT NOT NULL, kind VARCHAR(10) NOT NULL, who VARCHAR(10) NOT NULL DEFAULT '', at DATETIME NULL, PRIMARY KEY (phone, msg_id, kind), KEY k_at (at)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_attention (query_id INT NOT NULL PRIMARY KEY, phone VARCHAR(12) NOT NULL, in_id BIGINT NOT NULL DEFAULT 0, holder INT NOT NULL DEFAULT 0, state VARCHAR(10) NOT NULL DEFAULT 'held',
            stage TINYINT NOT NULL DEFAULT 0, hold_at DATETIME NULL, esc_at DATETIME NULL, since DATETIME NULL, auto_msgs TINYINT NOT NULL DEFAULT 0, hold_msg_id BIGINT NOT NULL DEFAULT 0, cleared_at DATETIME NULL, cleared_by INT NOT NULL DEFAULT 0,
            why VARCHAR(200) NOT NULL DEFAULT '', re_at DATETIME NULL, upd DATETIME NULL, KEY k_state (state, since), KEY k_phone (phone)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_daily (day DATE NOT NULL PRIMARY KEY, js MEDIUMTEXT NULL, built_at DATETIME NULL) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_win (id INT AUTO_INCREMENT PRIMARY KEY, query_id INT NOT NULL, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, type VARCHAR(12) NOT NULL DEFAULT '', customer_text TEXT NULL, reply_text TEXT NULL,
            order_name VARCHAR(40) NOT NULL DEFAULT '', amount DECIMAL(12,2) NOT NULL DEFAULT 0, order_at DATETIME NULL, status VARCHAR(8) NOT NULL DEFAULT 'new', learn_id INT NOT NULL DEFAULT 0, at DATETIME NULL,
            UNIQUE KEY uq_q (query_id), KEY k_st (status, id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wq_boss (code VARCHAR(20) NOT NULL PRIMARY KEY, title VARCHAR(160) NOT NULL DEFAULT '', body VARCHAR(400) NOT NULL DEFAULT '', x INT NOT NULL DEFAULT 0, state VARCHAR(10) NOT NULL DEFAULT 'proposed',
            asked_at DATETIME NULL, decided_at DATETIME NULL, decided_by VARCHAR(40) NOT NULL DEFAULT '', pen_rule_id INT NOT NULL DEFAULT 0) $E");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { error_log('wq_tables: ' . $e->getMessage()); $ok = false; }
    return $ok;
}
/** Creates the tables this module reads from other modules when they do not exist yet (fresh install / tests); never changes existing ones. */
function wq_deps(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    foreach (['wa_tables', 'wa_more_tables', 'wag_tables'] as $f) { if (function_exists($f)) { try { $f($pdo); } catch (Throwable $e) { } } }
}

// ------------------------------------------------------------------------------------------------------------------ labels
function wq_types(): array { return ['meta' => 'Meta', 'campaign' => 'Campaign', 'community' => 'Community', 'regenerated' => 'Regenerated', 'new' => 'New', 'other' => 'Other']; }
function wq_type_label(string $t): string { return wq_types()[$t] ?? ($t === 'unclear' ? 'To decide' : ucfirst($t)); }
function wq_type_tag(string $t): string { return $t . '_query'; }
function wq_tag_labels(): array
{
    return ['ignored_fu' => '❄ Ignored our follow-ups (cold group)', 'hot' => 'Ready to pay now', 'interested' => 'Interested', 'considering' => 'Thinking / asking family', 'premium_buyer' => 'Premium buyer', 'budget_buyer' => 'Budget buyer', 'order_query' => 'Order from a query', 'order_campaign' => 'Order from a campaign', 'returning_enquiry' => 'Asking again after ordering', 'meta_query' => 'Meta query', 'campaign_query' => 'Campaign query', 'community_query' => 'Community query', 'regenerated_query' => 'Regenerated query', 'new_query' => 'New query', 'other_query' => 'Other query',
        'arrive_morning' => 'Morning (5-12)', 'arrive_afternoon' => 'Afternoon (12-17)', 'arrive_evening' => 'Evening (17-22)', 'arrive_night' => 'Night (22-5)', 'premium_vip' => 'Premium: VIP', 'premium_high' => 'Premium: high', 'campaign' => 'Campaign (chat closed 24h+ / imported)', 'business_autoreply' => '🏢 Business (auto-reply, not a customer)'];
}
function wq_tag_group(string $tag): string { return preg_match('/_query$/', $tag) ? 'type' : (strpos($tag, 'arrive_') === 0 ? 'time' : (strpos($tag, 'premium_') === 0 ? 'value' : 'other')); }
/** morning 05-12 / afternoon 12-17 / evening 17-22 / night 22-05 (IST, from the time of the query). */
function wq_arrived(int $ts): string { $h = (int)date('G', $ts); return $h >= 5 && $h < 12 ? 'morning' : ($h >= 12 && $h < 17 ? 'afternoon' : ($h >= 17 && $h < 22 ? 'evening' : 'night')); }
function wq_today_label(int $ts): string { return date('j M', $ts); }

// ------------------------------------------------------------------------------------------------------------------ tags (src 'wq')
function wq_tag_put(PDO $pdo, string $phone, string $tag, int $ts = 0): void
{
    $now = wq_dt($ts);
    try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'wq', ?)")->execute([$phone, $tag, $now]); } catch (Throwable $e) { return; }
    try { $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)")->execute([$phone, $tag]); } catch (Throwable $e) { }   // visible to audience filters at once (the 10-minute index rebuild also merges every wa_ctags row)
}
function wq_tag_drop(PDO $pdo, string $phone, string $tag): void
{
    try { $d = $pdo->prepare("DELETE FROM wa_ctags WHERE phone = ? AND tag = ? AND src = 'wq'"); $d->execute([$phone, $tag]); if ($d->rowCount() > 0) $pdo->prepare("DELETE FROM wa_tagidx WHERE phone = ? AND tag = ?")->execute([$phone, $tag]); } catch (Throwable $e) { }
}
function wq_tags_of(PDO $pdo, string $phone, string $src = 'wq'): array
{
    try { $q = $pdo->prepare("SELECT tag FROM wa_ctags WHERE phone = ? AND src = ? ORDER BY tag"); $q->execute([$phone, $src]); return $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
}
/** Writes the tags of one typed query: type tag (kept for every type the customer ever had), arrival-time tag (latest query), premium tag (current value tier). */
function wq_tags_apply(PDO $pdo, string $phone, string $type, int $openedTs): void
{
    if (isset(wq_types()[$type])) wq_tag_put($pdo, $phone, wq_type_tag($type), $openedTs);
    foreach (['morning', 'afternoon', 'evening', 'night'] as $a) wq_tag_drop($pdo, $phone, 'arrive_' . $a);
    wq_tag_put($pdo, $phone, 'arrive_' . wq_arrived($openedTs), $openedTs);
    $tier = ''; try { $q = $pdo->prepare("SELECT value_tier FROM wa_cust_story WHERE phone = ?"); $q->execute([$phone]); $tier = (string)$q->fetchColumn(); } catch (Throwable $e) { }
    foreach (['premium_vip', 'premium_high'] as $p) wq_tag_drop($pdo, $phone, $p);
    if ($tier === 'vip') wq_tag_put($pdo, $phone, 'premium_vip', $openedTs); elseif ($tier === 'high') wq_tag_put($pdo, $phone, 'premium_high', $openedTs);
}

// ------------------------------------------------------------------------------------------------------------------ typing engine
function wq_query_row(PDO $pdo, int $qid): ?array { $q = $pdo->prepare("SELECT * FROM wa_queries WHERE id = ?"); $q->execute([$qid]); $r = $q->fetch(); return $r ?: null; }
function wq_contact(PDO $pdo, string $phone): array { $q = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone = ?"); $q->execute([$phone]); return $q->fetch() ?: []; }
/** The customer's words that opened the query: first inbound message from (opened_at - 2 min). [body, ts, id] */
function wq_first_msg(PDO $pdo, string $phone, string $openedAt): array
{
    $q = $pdo->prepare("SELECT id, body, ts, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts >= ? ORDER BY id LIMIT 1"); $q->execute([$phone, wq_dt(wq_ts($openedAt) - 120)]); $r = $q->fetch();
    if (!$r) { $q = $pdo->prepare("SELECT id, body, ts, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $r = $q->fetch(); }
    return $r ? [trim((string)$r['body']), (string)$r['ts'], (int)$r['id']] : ['', '', 0];
}
function wq_norm_words(string $s): string { return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)))); }
function wq_ctag_list(PDO $pdo, string $phone): array { try { $q = $pdo->prepare("SELECT tag, src, at FROM wa_ctags WHERE phone = ?"); $q->execute([$phone]); return $q->fetchAll(); } catch (Throwable $e) { return []; } }

/** The campaign / smart / template broadcast we sent this customer in the $days before $ts: ['ts','camp_id','name','emp'] or null. */
function wq_campaign_before(PDO $pdo, string $phone, int $ts, int $days): ?array
{
    $from = wq_dt($ts - $days * 86400); $to = wq_dt($ts + 60);
    try {
        $q = $pdo->prepare("SELECT i.camp_id, i.sent_at, k.name, k.created_by FROM wa_camp_items i LEFT JOIN wa_campaigns k ON k.id = i.camp_id WHERE i.phone = ? AND i.status = 'sent' AND i.sent_at BETWEEN ? AND ? ORDER BY i.sent_at DESC LIMIT 1");
        $q->execute([$phone, $from, $to]); $r = $q->fetch();
        if ($r) { if (wq_chat_was_live($pdo, $phone, wq_ts($r['sent_at']))) return null; return ['ts' => wq_ts($r['sent_at']), 'camp_id' => (int)$r['camp_id'], 'name' => (string)($r['name'] ?? ''), 'emp' => (int)($r['created_by'] ?? 0)]; }
    } catch (Throwable $e) { }
    try {
        $q = $pdo->prepare("SELECT ts FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts BETWEEN ? AND ? ORDER BY id DESC LIMIT 1"); $q->execute([$phone, $from, $to]); $r = $q->fetch();
        if ($r) { if (wq_chat_was_live($pdo, $phone, wq_ts($r['ts']))) return null; return ['ts' => wq_ts($r['ts']), 'camp_id' => 0, 'name' => 'a broadcast', 'emp' => 0]; }
    } catch (Throwable $e) { }
    return null;
}
/** True when the customer had written to us in the 30 hours BEFORE $ts (a query stays a New query for 30 hours): their query was still open, so a campaign we send then does not turn it into a Campaign. */
function wq_chat_was_live(PDO $pdo, string $phone, int $ts): bool
{
    try { $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts BETWEEN ? AND ? LIMIT 1"); $q->execute([$phone, wq_dt($ts - 30 * 3600), wq_dt($ts)]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}
/** Context of one query for typing and rules. */
function wq_ctx(PDO $pdo, array $q, array $opt = []): array
{
    $phone = (string)$q['phone']; $T = wq_ts($q['opened_at']); $c = wq_contact($pdo, $phone); [$body, $bts, $mid] = wq_first_msg($pdo, $phone, (string)$q['opened_at']);
    $cfg = wq_cfg($pdo);
    $earlierQ = $pdo->prepare("SELECT COUNT(*) FROM wa_queries WHERE phone = ? AND id < ?"); $earlierQ->execute([$phone, (int)$q['id']]);
    $prev = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts < ?"); $prev->execute([$phone, wq_dt($T - 120)]); $prevIn = wq_ts($prev->fetchColumn());
    $story = []; try { $s = $pdo->prepare("SELECT first_source, first_seen, woke_at, woke_gap_days FROM wa_cust_story WHERE phone = ?"); $s->execute([$phone]); $story = $s->fetch() ?: []; } catch (Throwable $e) { }
    return ['q' => $q, 'T' => $T, 'phone' => $phone, 'c' => $c, 'body' => $body, 'body_ts' => $bts, 'ref_now' => !empty($opt['ref_now']), 'ref_head' => (string)($opt['ref_head'] ?? ''),
        'first_query' => (int)$earlierQ->fetchColumn() === 0, 'prev_in' => $prevIn, 'story' => $story, 'tags' => wq_ctag_list($pdo, $phone), 'camp_days' => (int)$cfg['wq_camp_days'],
        'wake_days' => max(1, (int)wq_get($pdo, 'wa_seg_wake_days', 60)), 'camp' => wq_campaign_before($pdo, $phone, $T, (int)$cfg['wq_camp_days'])];
}
function wq_rule_label(array $r): string
{
    $k = (string)$r['kind']; $v = (string)$r['val']; $t = wq_type_label((string)$r['type']);
    $w = ['ad' => 'Ad id ' . $v, 'source' => 'Came by: ' . $v, 'tag' => 'Tag "' . $v . '"', 'campaign' => 'Campaign #' . $v, 'words' => 'First words "' . $v . '"', 'ref' => 'Ad headline contains "' . $v . '"'];
    return ($w[$k] ?? $k . ' ' . $v) . ' → ' . $t;
}
function wq_rule_hit(array $r, array $ctx): bool
{
    $v = wq_norm_words((string)$r['val']); if ($v === '') return false; $c = $ctx['c'];
    switch ((string)$r['kind']) {
        case 'ad': return (string)($c['ad_id'] ?? '') !== '' && wq_norm_words((string)$c['ad_id']) === $v;
        case 'ref': return mb_strpos(wq_norm_words((string)($c['ad_head'] ?? '') . ' ' . (string)($c['ref_text'] ?? '') . ' ' . $ctx['ref_head']), $v) !== false;
        case 'source': return wq_norm_words((string)($c['source'] ?? '')) === $v || wq_norm_words((string)($ctx['story']['first_source'] ?? '')) === $v;
        case 'tag': foreach ($ctx['tags'] as $t) if (wq_norm_words((string)$t['tag']) === $v) return true; return false;
        case 'campaign': return !empty($ctx['camp']) && (int)$ctx['camp']['camp_id'] > 0 && (string)$ctx['camp']['camp_id'] === $v;
        case 'words': return $ctx['body'] !== '' && mb_strpos(wq_norm_words($ctx['body']), $v) === 0;
    }
    return false;
}
function wq_rule_match(PDO $pdo, array $ctx): ?array
{
    try { foreach ($pdo->query("SELECT * FROM wq_rules WHERE active = 1 ORDER BY id")->fetchAll() as $r) if (wq_rule_hit($r, $ctx)) return $r; } catch (Throwable $e) { }
    return null;
}
/**
 * The decision: a) owner rules, then the signals meta / campaign / community — the MOST RECENT trigger wins (ties: meta, campaign, community) —
 * then regenerated (silent >= wake days, wrote by themselves), new (first ever), else unclear (a question).
 * Returns ['type','reason','rule_id','camp_id','camp_emp','st'].
 */
function wq_decide(PDO $pdo, array $ctx): array
{
    $out = ['type' => 'unclear', 'reason' => 'no sign - I need your answer', 'rule_id' => 0, 'camp_id' => 0, 'camp_emp' => 0, 'st' => 'ask'];
    $T = $ctx['T']; $c = $ctx['c']; $camp = $ctx['camp'];
    if ($camp) { $out['camp_id'] = (int)$camp['camp_id']; $out['camp_emp'] = (int)$camp['emp']; }
    if (function_exists('wa_camp_number') && wa_camp_number($pdo, (int)($c['last_number_id'] ?? 0)))   // 📢 the customer wrote to the campaign number: always a Campaign query
        return ['type' => 'campaign', 'reason' => 'wrote to the campaign number', 'rule_id' => 0, 'camp_id' => $out['camp_id'], 'camp_emp' => $out['camp_emp'], 'st' => 'ok'];
    if (($r = wq_rule_match($pdo, $ctx))) {
        try { $pdo->prepare("UPDATE wq_rules SET hits = hits + 1, last_hit = ? WHERE id = ?")->execute([wq_dt(), (int)$r['id']]); } catch (Throwable $e) { }
        return ['type' => (string)$r['type'], 'reason' => 'your rule: ' . wq_rule_label($r), 'rule_id' => (int)$r['id'], 'camp_id' => $out['camp_id'], 'camp_emp' => $out['camp_emp'], 'st' => 'ok'];
    }
    $sig = [];
    // meta: a Meta ad referral on this very message, or (first query of a customer who came from an ad) the contact's ad data
    $hasAd = (string)($c['source'] ?? '') === 'query' && ((string)($c['ad_id'] ?? '') !== '' || (string)($c['ad_head'] ?? '') !== '' || (string)($c['ref_text'] ?? '') !== '');
    if ($ctx['ref_now'] || ($hasAd && $ctx['first_query'])) {
        $head = trim($ctx['ref_head'] !== '' ? $ctx['ref_head'] : ((string)($c['ad_head'] ?? '') !== '' ? (string)$c['ad_head'] : (string)($c['ref_text'] ?? '')));
        $sig['meta'] = ['ts' => $T, 'reason' => 'ad: ' . ($head !== '' ? mb_substr($head, 0, 90) : 'Meta ad') . ', ' . wq_today_label($T)];
    }
    if ($camp) $sig['campaign'] = ['ts' => (int)$camp['ts'], 'reason' => 'campaign: ' . ($camp['name'] !== '' ? mb_substr($camp['name'], 0, 80) : 'broadcast') . ', sent ' . wq_today_label((int)$camp['ts'])];
    foreach ($ctx['tags'] as $t) if (wq_norm_words((string)$t['tag']) === 'community' && (string)$t['src'] !== 'wq') { $sig['community'] = ['ts' => wq_ts($t['at']) ?: $T - 1, 'reason' => 'community: carries the community tag']; break; }
    if (!isset($sig['community']) && (string)($c['source'] ?? '') === 'community' && $ctx['first_query']) $sig['community'] = ['ts' => $T - 1, 'reason' => 'community: came through the community link'];
    // campaign customer: the chat window had closed (24h+ since their last message) or they were imported from Excel for campaigns -> every new query is a campaign query
    if (!isset($sig['campaign'])) {
        foreach ($ctx['tags'] as $t) if ((string)$t['tag'] === 'campaign' && wq_ts($t['at']) > 0 && wq_ts($t['at']) <= $T) { $sig['campaign'] = ['ts' => wq_ts($t['at']), 'reason' => 'campaign customer: chat window had closed / imported for campaigns']; break; }
        if (!isset($sig['campaign']) && $ctx['prev_in'] > 0 && $T - $ctx['prev_in'] >= 86400) { $sig['campaign'] = ['ts' => $ctx['prev_in'] + 86400, 'reason' => 'campaign customer: wrote again after the 24-hour chat window closed']; wq_tag_put($pdo, (string)$ctx['phone'], 'campaign', $ctx['prev_in'] + 86400); }
    }
    if ($sig) {
        $best = null; $rank = ['meta' => 3, 'campaign' => 2, 'community' => 1];
        foreach ($sig as $t => $s) if ($best === null || $s['ts'] > $sig[$best]['ts'] || ($s['ts'] === $sig[$best]['ts'] && $rank[$t] > $rank[$best])) $best = $t;
        $out['type'] = $best; $out['reason'] = $sig[$best]['reason']; $out['st'] = 'ok';
        if (count($sig) > 1) $out['reason'] .= ' (latest of: ' . implode(', ', array_keys($sig)) . ')';
        if ($best !== 'campaign') { $out['camp_id'] = 0; $out['camp_emp'] = 0; }
        return $out;
    }
    $wake = $ctx['wake_days'];
    $silent = $ctx['prev_in'] > 0 ? (int)floor(($T - $ctx['prev_in']) / 86400) : 0;
    $woke = !empty($ctx['story']['woke_at']) && abs(wq_ts($ctx['story']['woke_at']) - $T) <= 86400;
    if (($ctx['prev_in'] > 0 && $silent >= $wake) || $woke) {
        $d = $silent >= $wake ? $silent : (int)($ctx['story']['woke_gap_days'] ?? $wake);
        return ['type' => 'regenerated', 'reason' => 'silent ' . $d . ' days, then wrote again by themselves', 'rule_id' => 0, 'camp_id' => 0, 'camp_emp' => 0, 'st' => 'ok'];
    }
    if ($ctx['first_query'] && $ctx['prev_in'] === 0) return ['type' => 'new', 'reason' => 'first message ever, no other sign', 'rule_id' => 0, 'camp_id' => 0, 'camp_emp' => 0, 'st' => 'ok'];
    $out['camp_id'] = 0; $out['camp_emp'] = 0;
    return $out;
}
function wq_qtype_get(PDO $pdo, int $qid): ?array { $q = $pdo->prepare("SELECT * FROM wq_qtype WHERE query_id = ?"); $q->execute([$qid]); $r = $q->fetch(); return $r ?: null; }
/** Writes (or replaces) the type of one query, its history row and its tags. */
function wq_qtype_set(PDO $pdo, array $q, array $d, int $setBy = 0, bool $auto = true): void
{
    $now = wq_dt(); $qid = (int)$q['id'];
    $pdo->prepare("INSERT INTO wq_qtype (query_id, phone, type, reason, auto, rule_id, camp_id, camp_emp, set_by, at, opened_at, st) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE type = VALUES(type), reason = VALUES(reason), auto = VALUES(auto), rule_id = VALUES(rule_id), camp_id = VALUES(camp_id), camp_emp = VALUES(camp_emp), set_by = VALUES(set_by), at = VALUES(at), st = VALUES(st)")
        ->execute([$qid, (string)$q['phone'], $d['type'], mb_substr((string)$d['reason'], 0, 220), $auto ? 1 : 0, (int)($d['rule_id'] ?? 0), (int)($d['camp_id'] ?? 0), (int)($d['camp_emp'] ?? 0), $setBy, $now, (string)$q['opened_at'], $d['st'] ?? 'ok']);
    $pdo->prepare("INSERT INTO wq_tlog (query_id, type, reason, set_by, auto, at) VALUES (?,?,?,?,?,?)")->execute([$qid, $d['type'], mb_substr((string)$d['reason'], 0, 220), $setBy, $auto ? 1 : 0, $now]);
    if ($d['type'] !== 'unclear') wq_tags_apply($pdo, (string)$q['phone'], $d['type'], wq_ts($q['opened_at']));
}
/** Types one query (when it has no type yet or $redo). Never throws. Returns the decision or null. */
function wq_type_query(PDO $pdo, int $qid, array $opt = []): ?array
{
    try {
        if (!wq_tables($pdo)) return null; $q = wq_query_row($pdo, $qid); if (!$q) return null;
        if (!empty($opt['redo']) === false && wq_qtype_get($pdo, $qid)) return null;
        $d = wq_decide($pdo, wq_ctx($pdo, $q, $opt)); wq_qtype_set($pdo, $q, $d, 0, true); return $d;
    } catch (Throwable $e) { error_log('wq_type_query: ' . $e->getMessage()); return null; }
}
/** Remove an old type tag only when no other query of the phone still has that type. */
function wq_retag_after_change(PDO $pdo, string $phone, string $oldType): void
{
    if (!isset(wq_types()[$oldType])) return;
    $q = $pdo->prepare("SELECT COUNT(*) FROM wq_qtype WHERE phone = ? AND type = ?"); $q->execute([$phone, $oldType]);
    if ((int)$q->fetchColumn() === 0) wq_tag_drop($pdo, $phone, wq_type_tag($oldType));
}
/** Owner changes a type by hand. */
function wq_retype(PDO $pdo, int $qid, string $type, string $reason, int $by): array
{
    $q = wq_query_row($pdo, $qid); if (!$q) return [false, 'Query not found.'];
    if (!isset(wq_types()[$type])) return [false, 'Choose one of: ' . implode(', ', array_keys(wq_types())) . '.'];
    $old = wq_qtype_get($pdo, $qid); $oldType = $old ? (string)$old['type'] : '';
    wq_qtype_set($pdo, $q, ['type' => $type, 'reason' => $reason !== '' ? $reason : 'set by hand', 'rule_id' => 0, 'camp_id' => (int)($old['camp_id'] ?? 0), 'camp_emp' => (int)($old['camp_emp'] ?? 0), 'st' => 'ok'], $by, false);
    if ($oldType !== '' && $oldType !== $type) wq_retag_after_change($pdo, (string)$q['phone'], $oldType);
    return [true, $type];
}

// ---- the inbound hook (after the message is stored; cheap; never blocks the message flow)
function wq_after_in(PDO $pdo, string $key, array $m = [], bool $isNew = false, array $ref = []): void
{
    try {
        if (!wq_on($pdo) || !wq_tables($pdo)) return;
        $q = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $qid = (int)$q->fetchColumn(); if ($qid <= 0) return;
        if (wq_qtype_get($pdo, $qid)) return;
        $head = $ref ? trim((string)($ref['headline'] ?? $ref['body'] ?? $ref['source_url'] ?? '')) : '';
        wq_type_query($pdo, $qid, ['ref_now' => !empty($ref), 'ref_head' => $head]);
    } catch (Throwable $e) { error_log('wq_after_in: ' . $e->getMessage()); }
}

// ---- rule options and learning from an answer
function wq_rule_options(PDO $pdo, array $ctx): array
{
    $o = []; $c = $ctx['c'];
    if ((string)($c['ad_id'] ?? '') !== '') $o[] = ['kind' => 'ad', 'val' => (string)$c['ad_id'], 'label' => 'Same ad (id ' . $c['ad_id'] . ')'];
    $h = trim((string)($c['ad_head'] ?? '')); if ($h !== '') $o[] = ['kind' => 'ref', 'val' => mb_substr($h, 0, 60), 'label' => 'Ad headline contains "' . mb_substr($h, 0, 40) . '"'];
    $s = trim((string)($ctx['story']['first_source'] ?? '')); if ($s === '' || $s === 'unknown') $s = trim((string)($c['source'] ?? ''));
    if ($s !== '' && $s !== 'unknown') $o[] = ['kind' => 'source', 'val' => $s, 'label' => 'Came by: ' . $s];
    if ($ctx['body'] !== '') { $w = array_slice(preg_split('/\s+/u', wq_norm_words(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $ctx['body']))), 0, 2); $w = trim(implode(' ', $w)); if (mb_strlen($w) >= 2) $o[] = ['kind' => 'words', 'val' => $w, 'label' => 'First words: "' . $w . '"']; }
    foreach ($ctx['tags'] as $t) if ((string)$t['src'] !== 'wq' && count($o) < 6) $o[] = ['kind' => 'tag', 'val' => (string)$t['tag'], 'label' => 'Customers tagged "' . $t['tag'] . '"'];
    if (!empty($ctx['camp']) && (int)$ctx['camp']['camp_id'] > 0) $o[] = ['kind' => 'campaign', 'val' => (string)$ctx['camp']['camp_id'], 'label' => 'Same campaign (' . $ctx['camp']['name'] . ')'];
    return $o;
}
function wq_rule_add(PDO $pdo, string $kind, string $val, string $type, int $by): array
{
    if (!in_array($kind, ['ad', 'source', 'tag', 'campaign', 'words', 'ref'], true)) return [false, 'Unknown rule kind.'];
    if (!isset(wq_types()[$type])) return [false, 'Choose a type.']; $val = trim($kind === 'words' ? wq_norm_words($val) : $val); if ($val === '') return [false, 'The rule needs a value.'];
    $val = mb_substr($val, 0, 190);
    $e = $pdo->prepare("SELECT id FROM wq_rules WHERE kind = ? AND val = ? AND type = ? AND active = 1"); $e->execute([$kind, $val, $type]); $id = (int)$e->fetchColumn();
    if ($id > 0) return [true, $id];
    $pdo->prepare("INSERT INTO wq_rules (kind, val, type, active, hits, created_by, created_at) VALUES (?,?,?,1,0,?,?)")->execute([$kind, $val, $type, $by, wq_dt()]);
    return [true, (int)$pdo->lastInsertId()];
}
/** Re-checks every waiting question against the rules; returns how many were typed. */
function wq_apply_rules_to_pending(PDO $pdo, int $max = 300): int
{
    $n = 0;
    try {
        $rows = $pdo->query("SELECT q.* FROM wq_qtype t JOIN wa_queries q ON q.id = t.query_id WHERE t.st IN ('ask','later') ORDER BY t.opened_at DESC LIMIT " . (int)$max)->fetchAll();
        foreach ($rows as $q) {
            $ctx = wq_ctx($pdo, $q); $r = wq_rule_match($pdo, $ctx); if (!$r) continue;
            $pdo->prepare("UPDATE wq_rules SET hits = hits + 1, last_hit = ? WHERE id = ?")->execute([wq_dt(), (int)$r['id']]);
            wq_qtype_set($pdo, $q, ['type' => (string)$r['type'], 'reason' => 'your rule: ' . wq_rule_label($r), 'rule_id' => (int)$r['id'], 'st' => 'ok'], 0, true); $n++;
        }
    } catch (Throwable $e) { error_log('wq_apply_rules_to_pending: ' . $e->getMessage()); }
    return $n;
}
function wq_pending_n(PDO $pdo): int { try { return (int)$pdo->query("SELECT COUNT(*) FROM wq_qtype WHERE st IN ('ask','later')")->fetchColumn(); } catch (Throwable $e) { return 0; } }

// ---- optional AI (suggestions / wording). Always stubbed in tests; the whole module works with the AI down.
function wq_ai(PDO $pdo, string $sys, string $ctx, array $schema): array
{
    if (function_exists('wq_ai_override')) return wq_ai_override($sys, $ctx, $schema);
    if (defined('WQ_NO_AI') || !function_exists('convo_ai') || (string)wq_get($pdo, 'wq_ai', '1') === '0') return [false, null, 'The AI is off.'];
    try { return convo_ai($sys, [['text' => $ctx]], $schema, false, 600, 'claude'); } catch (Throwable $e) { return [false, null, 'The AI is not available.']; }
}
/** The next unclear query as a question (see contract). */
function wq_next_question(PDO $pdo, array $skip = [], bool $withAi = true): ?array
{
    $skip = array_values(array_filter(array_map('intval', $skip)));
    $sql = "SELECT q.* FROM wq_qtype t JOIN wa_queries q ON q.id = t.query_id WHERE t.st IN ('ask','later')" . ($skip ? ' AND t.query_id NOT IN (' . implode(',', $skip) . ')' : '') . " ORDER BY (t.st = 'ask') DESC, t.opened_at DESC LIMIT 1";
    $q = $pdo->query($sql)->fetch(); if (!$q) return null;
    $ctx = wq_ctx($pdo, $q); $c = $ctx['c']; $T = $ctx['T'];
    $lines = [];
    $lines[] = $ctx['body'] !== '' ? 'First message: "' . mb_substr($ctx['body'], 0, 120) . '"' : 'First message: (no text - a photo, voice note or similar)';
    $lines[] = 'No ad, no campaign in the last ' . $ctx['camp_days'] . ' days, no community tag' . ($ctx['prev_in'] > 0 ? ', last wrote ' . (int)floor(($T - $ctx['prev_in']) / 86400) . ' days before' : '');
    $fs = !empty($ctx['story']['first_seen']) ? wq_ts($ctx['story']['first_seen']) : wq_ts($c['first_seen'] ?? '');
    $en = $pdo->prepare("SELECT COUNT(*) FROM wa_queries WHERE phone = ? AND id < ?"); $en->execute([$ctx['phone'], (int)$q['id']]);
    $lines[] = ($fs ? 'Customer since ' . date('j M Y', $fs) . ' · ' : '') . (int)$en->fetchColumn() . ' earlier chat(s)';
    $lines[] = 'Arrived ' . date('l j M, H:i', $T) . ' (' . wq_arrived($T) . ')';
    $sug = null;
    if ($withAi) {
        $sch = ['type' => 'OBJECT', 'properties' => ['type' => ['type' => 'STRING', 'enum' => array_keys(wq_types())], 'why' => ['type' => 'STRING']], 'required' => ['type', 'why']];
        [$ok, $v] = wq_ai($pdo, "You label first messages of a perfume shop's WhatsApp chats: meta (came from a Meta ad), campaign (answers our broadcast), community (from the community group), regenerated (came back after a long silence), new (brand-new customer), other (a plain enquiry or anything else). Answer with the best type and one short reason.", implode("\n", $lines), $sch);
        if ($ok && is_array($v) && isset(wq_types()[(string)($v['type'] ?? '')])) $sug = ['type' => (string)$v['type'], 'why' => mb_substr((string)($v['why'] ?? ''), 0, 160)];
    }
    $chips = []; foreach (wq_types() as $k => $l) $chips[] = ['type' => $k, 'label' => $l];
    return ['query_id' => (int)$q['id'], 'phone' => (string)$q['phone'], 'name' => (string)($c['name'] ?? ''), 'opened_at' => (string)$q['opened_at'], 'question' => 'How should I count this query?', 'chips' => $chips, 'context' => $lines, 'suggestion' => $sug, 'rule_options' => wq_rule_options($pdo, $ctx)];
}
/** Answer a question. Returns [ok, err|result]. */
function wq_answer(PDO $pdo, int $qid, string $type, bool $always, ?array $rule, int $by): array
{
    $q = wq_query_row($pdo, $qid); if (!$q) return [false, 'Query not found.', 404];
    if (!isset(wq_types()[$type])) return [false, 'Choose one of: ' . implode(', ', array_keys(wq_types())) . '.', 422];
    $ruleId = 0; $typed = 0;
    if ($always) {
        $opts = wq_rule_options($pdo, wq_ctx($pdo, $q)); $kind = (string)($rule['kind'] ?? ''); $val = (string)($rule['val'] ?? '');
        if ($kind === '' && $opts) { $kind = $opts[0]['kind']; $val = $opts[0]['val']; }
        if ($kind === '') return [false, 'There is nothing to base an "always" rule on for this query. Answer without it.', 422];
        [$okr, $rid] = wq_rule_add($pdo, $kind, $val, $type, $by); if (!$okr) return [false, (string)$rid, 422]; $ruleId = (int)$rid;
    }
    $old = wq_qtype_get($pdo, $qid);
    wq_qtype_set($pdo, $q, ['type' => $type, 'reason' => $ruleId ? 'you answered, always: ' . ($rule['label'] ?? ($kind ?? '') . ' ' . ($val ?? '')) : 'you answered', 'rule_id' => $ruleId, 'camp_id' => (int)($old['camp_id'] ?? 0), 'camp_emp' => (int)($old['camp_emp'] ?? 0), 'st' => 'ok'], $by, false);
    $typed = 1; if ($ruleId) $typed += wq_apply_rules_to_pending($pdo);
    return [true, ['typed' => $typed, 'rule_id' => $ruleId]];
}

// ------------------------------------------------------------------------------------------------------------------ duty (decision 7)
/**
 * Is this person on duty at $ts?  Reads: day_marks (leave / holiday / week_off / off => no, wfh => yes without a punch), leave_requests (approved, covering the
 * business day => leave), attendance (punched in within the last 20 h and not punched out yet), employee_shifts via emp_shift_map (only when the person has an own
 * start or an end: from 60 min before the start until the end; a shift that ends after midnight is handled; "free" mode has no limit).
 * The business day is the 10:00-10:00 day (biz_day_of). Returns ['on'=>bool,'state'=>on|not_in|leave|wfh|off_shift|after_shift|holiday,'why'=>text,'since'=>'HH:MM'|''].
 */
function wq_duty_info(PDO $pdo, int $emp, ?int $ts = null): array
{
    $ts = $ts ?: wq_now(); $r = ['on' => false, 'state' => 'not_in', 'why' => 'Not punched in', 'since' => ''];
    if ($emp <= 0) return ['on' => false, 'state' => 'not_in', 'why' => 'Nobody holds this chat', 'since' => ''];
    try {
        $day = function_exists('biz_day_of') ? biz_day_of($pdo, $ts) : date('Y-m-d', $ts);
        $mk = ''; try { $q = $pdo->prepare("SELECT mark FROM day_marks WHERE mark_date = ? AND employee_id = ?"); $q->execute([$day, $emp]); $mk = (string)$q->fetchColumn(); } catch (Throwable $e) { }
        if ($mk === 'leave') return ['on' => false, 'state' => 'leave', 'why' => 'Marked on leave today', 'since' => ''];
        if (in_array($mk, ['holiday', 'week_off', 'off'], true)) return ['on' => false, 'state' => 'holiday', 'why' => $mk === 'holiday' ? 'Holiday' : 'Day off', 'since' => ''];
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND LOWER(COALESCE(status,'')) = 'approved' AND date_from <= ? AND date_to >= ?"); $q->execute([$emp, $day, $day]); if ((int)$q->fetchColumn() > 0) return ['on' => false, 'state' => 'leave', 'why' => 'On approved leave', 'since' => '']; } catch (Throwable $e) { }
        if ($mk === 'wfh') return ['on' => true, 'state' => 'wfh', 'why' => 'Working from home', 'since' => ''];
        $a = $pdo->prepare("SELECT punch_in FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_in <= ? AND punch_in >= ? AND (punch_out IS NULL OR punch_out > ?) ORDER BY punch_in DESC LIMIT 1");
        $a->execute([$emp, wq_dt($ts), wq_dt($ts - 20 * 3600), wq_dt($ts)]); $pin = $a->fetchColumn();
        if (!$pin) return $r;
        $r = ['on' => true, 'state' => 'on', 'why' => 'Punched in at ' . date('H:i', wq_ts($pin)), 'since' => date('H:i', wq_ts($pin))];
        $m = &$GLOBALS['__wq_memo']['shift'][$day]; if (!isset($m)) { $m = []; if (function_exists('emp_shift_map')) { try { $m = emp_shift_map($pdo, $day); } catch (Throwable $e) { $m = []; } } }
        $sh = $m[$emp] ?? null;
        if ($sh && (string)($sh['mode'] ?? 'fixed') !== 'free' && ((int)($sh['own'] ?? 0) === 1 || (string)($sh['shift_end'] ?? '') !== '')) {
            $st = strtotime($day . ' ' . $sh['shift_start'] . ':00');
            if ($st && $ts < $st - 3600) return ['on' => false, 'state' => 'off_shift', 'why' => 'Shift starts at ' . $sh['shift_start'], 'since' => $r['since']];
            if ((string)($sh['shift_end'] ?? '') !== '') { $en = strtotime($day . ' ' . $sh['shift_end'] . ':00'); if ($en && $en <= $st) $en += 86400; if ($en && $ts > $en) return ['on' => false, 'state' => 'after_shift', 'why' => 'Shift ended at ' . $sh['shift_end'], 'since' => $r['since']]; }
        }
        return $r;
    } catch (Throwable $e) { return $r; }
}
function wq_on_duty(PDO $pdo, int $emp, ?int $ts = null): bool { return (bool)wq_duty_info($pdo, $emp, $ts)['on']; }
/** The sales people (department name contains "sales", not left). */
function wq_sales_people(PDO $pdo): array
{
    try { return $pdo->query("SELECT e.employee_id, e.full_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%' ORDER BY e.full_name")->fetchAll(); } catch (Throwable $e) { return []; }
}
function wq_admin_ids(PDO $pdo): array
{
    $ids = [];
    if (function_exists('wa_team_cfg')) { try { foreach (wa_team_cfg($pdo)['alert_emps'] as $i) $ids[(int)$i] = 1; } catch (Throwable $e) { } }
    try { foreach ($pdo->query("SELECT employee_id FROM users WHERE role IN ('Admin','HR') AND employee_id IS NOT NULL AND employee_id > 0")->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = 1; } catch (Throwable $e) { }
    return array_keys($ids);
}

// ------------------------------------------------------------------------------------------------------------------ guard (decision 6)
/** One atomic claim per (phone, inbound message id, kind). True = you may answer. Fails open (true) when the module is off or storage is broken, so nothing is ever blocked by accident. */
function wq_guard(PDO $pdo, string $phone, int $msgId, string $kind, string $who): bool
{
    if ($msgId <= 0) return true;
    try {
        if (!wq_tables($pdo)) return true;   // the one-answer-per-message guard works even with WhatsApp Sales switched off (bot + countdown could both answer)
        $st = $pdo->prepare("INSERT IGNORE INTO wq_guard (phone, msg_id, kind, who, at) VALUES (?,?,?,?,?)"); $st->execute([$phone, $msgId, $kind, $who, wq_dt()]); return $st->rowCount() === 1;
    } catch (Throwable $e) { return true; }
}
function wq_guard_release(PDO $pdo, string $phone, int $msgId, string $kind): void { try { $pdo->prepare("DELETE FROM wq_guard WHERE phone = ? AND msg_id = ? AND kind = ?")->execute([$phone, $msgId, $kind]); } catch (Throwable $e) { } }
function wq_guard_who(PDO $pdo, string $phone, int $msgId, string $kind = 'reply'): string { try { $q = $pdo->prepare("SELECT who FROM wq_guard WHERE phone = ? AND msg_id = ? AND kind = ?"); $q->execute([$phone, $msgId, $kind]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; } }
function wq_last_in(PDO $pdo, string $phone): array
{
    $q = $pdo->prepare("SELECT id, ts, mtype, body FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); return $q->fetch() ?: [];
}
/**
 * Called by wa.send before a text goes out. Returns '' to let it go, or a plain sentence to refuse it.
 *  - the browser countdown (body has "auto":true) must win the (phone, inbound message, 'reply') claim, so it can never answer a message the bot / the holding message already answered;
 *  - a message typed by a person is never blocked, except an exact copy of what the bot / auto reply already sent for that same customer message.
 */
function wq_send_guard(PDO $pdo, array $c, string $text, array $in): string
{
    try {
        if (!wq_tables($pdo)) return '';   // always on: never two automatic answers to one customer message
        $phone = (string)$c['phone']; $li = wq_last_in($pdo, $phone); $inId = (int)($li['id'] ?? 0); if ($inId <= 0) return '';
        if (!empty($in['auto'])) {
            if (!wq_guard($pdo, $phone, $inId, 'reply', 'countdown')) return 'This message was already answered by ' . (wq_guard_who($pdo, $phone, $inId) === 'bot' ? 'the bot' : 'an automatic reply') . '. Nothing was sent twice.';
            return '';
        }
        $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'out' AND id > ? AND who IN ('bot','auto') ORDER BY id DESC LIMIT 3"); $q->execute([$phone, $inId]);
        $n = wq_norm_words($text); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $b) if ($n !== '' && wq_norm_words((string)$b) === $n) return 'The bot already sent exactly this reply. Nothing was sent twice.';
    } catch (Throwable $e) { }
    return '';
}

// ------------------------------------------------------------------------------------------------------------------ orders (indexed copy of recent Shopify orders, by last 10 digits)
function wq_phone10($p): string { $d = preg_replace('/\D+/', '', (string)$p); return strlen($d) >= 10 ? substr($d, -10) : ''; }
/** Copies recent shop_orders into wq_ord (phone10, name, total, time). Incremental by created_at with a 2-day overlap; idempotent. */
function wq_ord_sync(PDO $pdo, float $deadline, int $max = 4000): int
{
    $n = 0;
    try {
        $cur = wq_meta_get($pdo, 'ord_cursor', ''); $first = $cur === '';
        if ($cur === '') $cur = wq_dt(wq_now() - 100 * 86400);
        elseif (wq_meta_get($pdo, 'ord_behind', '') !== '1' && wq_ts(wq_meta_get($pdo, 'ord_at', '')) > wq_now() - 120 && wq_ts($cur) > wq_now() - 3 * 86400) return 0;   // caught up: look again every 2 minutes only
        $behind = wq_meta_get($pdo, 'ord_behind', '') === '1'; $from = ($first || $behind) ? $cur : wq_dt(wq_ts($cur) - 2 * 86400);   // 2-day overlap only once caught up (late-synced orders); while catching up, no overlap
        $q = $pdo->prepare("SELECT store_key, shopify_id, order_name, phone, total, created_at, financial_status FROM shop_orders WHERE created_at >= ? ORDER BY created_at LIMIT " . (int)$max); $q->execute([$from]);
        $ins = $pdo->prepare("INSERT INTO wq_ord (okey, phone, order_name, total, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), total = VALUES(total), created_at = VALUES(created_at)");
        $last = $cur; $rows = $q->fetchAll(); $touched = [];
        foreach ($rows as $r) {
            $last = (string)$r['created_at'];
            $p = wq_phone10($r['phone']); if ($p === '') continue; if (in_array(strtolower((string)$r['financial_status']), ['voided', 'refunded'], true)) continue;
            $key = mb_substr((string)$r['store_key'] . '|' . ((string)$r['order_name'] !== '' ? $r['order_name'] : $r['shopify_id']), 0, 90);
            $ins->execute([$key, $p, mb_substr((string)$r['order_name'], 0, 40), (float)$r['total'], $r['created_at']]); $n++; if (wq_ts($r['created_at']) >= wq_now() - 40 * 86400) $touched[$p] = 1;
            if (microtime(true) > $deadline) break;
        }
        if (count($rows) < $max || microtime(true) <= $deadline) { if (count($rows) < $max) wq_meta_put($pdo, 'ord_at', wq_dt()); }
        if ($touched) { foreach (array_chunk(array_keys($touched), 300) as $ch) $pdo->prepare("UPDATE wq_qstat SET stat_at = NULL WHERE final = 0 AND phone IN (" . wq_in($ch) . ")")->execute($ch); }   // a new order: the summary of that customer's open queries is recomputed
        wq_meta_put($pdo, 'ord_behind', count($rows) >= $max ? '1' : '0');
        if ($rows) wq_meta_put($pdo, 'ord_cursor', $last);
        if (!$rows) wq_meta_put($pdo, 'ord_at', wq_dt());
    } catch (Throwable $e) { error_log('wq_ord_sync: ' . $e->getMessage()); }
    return $n;
}

// ------------------------------------------------------------------------------------------------------------------ per-query summary (wq_qstat) — what the board reads
/** Recomputes the summary of one query from its messages and the order copy. */
function wq_stat_one(PDO $pdo, int $qid): bool
{
    try {
        $q = wq_query_row($pdo, $qid); if (!$q) return false; $cfg = wq_cfg($pdo); $now = wq_now(); $phone = (string)$q['phone']; $o = wq_ts($q['opened_at']);
        $nx = $pdo->prepare("SELECT MIN(opened_at) FROM wa_queries WHERE phone = ? AND id > ?"); $nx->execute([$phone, $qid]); $end = wq_ts($nx->fetchColumn()) ?: $now + 86400;
        $m = $pdo->prepare("SELECT id, dir, who, emp_id, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND ts < ? AND dir IN ('in','out') ORDER BY id LIMIT 600"); $m->execute([$phone, wq_dt($o - 120), wq_dt($end)]);
        $firstIn = null; $firstOut = null; $firstOutBy = ''; $firstPerson = null; $pn = 0; $bn = 0; $lastOut = 0; $lastInAfterOut = false; $firstInId = 0;
        foreach ($m->fetchAll() as $r) {
            $t = wq_ts($r['ts']);
            if ($r['dir'] === 'in') { if ($firstIn === null) { $firstIn = $t; $firstInId = (int)$r['id']; } if ($t > $lastOut) $lastInAfterOut = $lastOut > 0; continue; }
            if ($firstIn === null) continue;
            $w = (string)$r['who']; $isP = in_array($w, ['emp', 'person'], true) && (int)$r['emp_id'] > 0; $isB = in_array($w, ['bot', 'auto', 'flow'], true);
            if (!$isP && !$isB) continue;
            if ($isP) { $pn++; if ($firstPerson === null) $firstPerson = $t; } else $bn++;
            if ($firstOut === null) { $firstOut = $t; $firstOutBy = $isP ? 'person' : 'bot'; }
            $lastOut = $t; $lastInAfterOut = false;
        }
        $grp = ''; if ($pn && $bn) $grp = $firstOutBy === 'bot' ? 'bot_then_person' : 'person_only'; elseif ($pn) $grp = 'person_only'; elseif ($bn) $grp = 'bot_only';
        $stopped = ($lastOut > 0 && !$lastInAfterOut && $now - $lastOut >= 3 * 86400) ? 1 : 0;
        $ad = (int)$cfg['wq_attr_days'] * 86400; $oq = $pdo->prepare("SELECT order_name, total, created_at FROM wq_ord WHERE phone = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at LIMIT 20"); $oq->execute([$phone, wq_dt($o), wq_dt(min($now, $o + $ad))]);
        $ords = $oq->fetchAll(); $rev = 0.0; foreach ($ords as $x) $rev += (float)$x['total'];
        $final = ($o < $now - ((int)$cfg['wq_attr_days'] + 7) * 86400) ? 1 : 0;
        $pdo->prepare("INSERT INTO wq_qstat (query_id, phone, opened_at, first_in_at, first_out_at, first_person_at, first_person_min, first_by, person_n, bot_n, grp, answered, stopped, orders, revenue, first_order, first_order_at, first_order_amt, final, stat_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), opened_at = VALUES(opened_at), first_in_at = VALUES(first_in_at), first_out_at = VALUES(first_out_at), first_person_at = VALUES(first_person_at),
            first_person_min = VALUES(first_person_min), first_by = VALUES(first_by), person_n = VALUES(person_n), bot_n = VALUES(bot_n), grp = VALUES(grp), answered = VALUES(answered), stopped = VALUES(stopped), orders = VALUES(orders), revenue = VALUES(revenue),
            first_order = VALUES(first_order), first_order_at = VALUES(first_order_at), first_order_amt = VALUES(first_order_amt), final = VALUES(final), stat_at = VALUES(stat_at)")
            ->execute([$qid, $phone, $q['opened_at'], $firstIn ? wq_dt($firstIn) : null, $firstOut ? wq_dt($firstOut) : null, $firstPerson ? wq_dt($firstPerson) : null, ($firstPerson && $firstIn) ? round(max(0, $firstPerson - $firstIn) / 60, 2) : null,
                $firstOutBy, $pn, $bn, $grp, $firstOut ? 1 : 0, $stopped, count($ords), round($rev, 2), $ords ? (string)$ords[0]['order_name'] : '', $ords ? $ords[0]['created_at'] : null, $ords ? (float)$ords[0]['total'] : 0, $final, wq_dt($now)]);
        return true;
    } catch (Throwable $e) { error_log('wq_stat_one: ' . $e->getMessage()); return false; }
}
function wq_stat_chunk(PDO $pdo, float $deadline, int $limit = 150): int
{
    $n = 0;
    try {
        $now = wq_now(); $since = wq_dt($now - 90 * 86400);
        $st = $pdo->prepare("SELECT q.id FROM wa_queries q LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND (s.query_id IS NULL OR s.stat_at IS NULL OR s.stat_at < q.last_in_at OR (s.final = 0 AND ((q.opened_at >= ? AND s.stat_at < ?) OR s.stat_at < ?)))
            ORDER BY (s.query_id IS NULL) DESC, q.id DESC LIMIT " . (int)$limit);
        $st->execute([$since, wq_dt($now - 2 * 86400), wq_dt($now - 600), wq_dt($now - 6 * 3600)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if (wq_stat_one($pdo, (int)$id)) $n++; if (microtime(true) > $deadline) break; }
    } catch (Throwable $e) { error_log('wq_stat_chunk: ' . $e->getMessage()); }
    return $n;
}
/** Backfill + typing of every untyped query of the last 90 days (newest first). Idempotent and resumable: it only ever picks queries that have no wq_qtype row. */
function wq_backfill_chunk(PDO $pdo, float $deadline, int $limit = 100): array
{
    $n = 0; $left = 0;
    try {
        $st = $pdo->prepare("SELECT q.id FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id WHERE q.opened_at >= ? AND t.query_id IS NULL ORDER BY q.id DESC LIMIT " . (int)$limit);
        $st->execute([wq_dt(wq_now() - 90 * 86400)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if (wq_type_query($pdo, (int)$id) !== null) $n++; if (microtime(true) > $deadline) break; }
        $c = $pdo->prepare("SELECT COUNT(*) FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id WHERE q.opened_at >= ? AND t.query_id IS NULL"); $c->execute([wq_dt(wq_now() - 90 * 86400)]); $left = (int)$c->fetchColumn();
        if ($left === 0 && wq_meta_get($pdo, 'backfill_done', '') === '') wq_meta_put($pdo, 'backfill_done', wq_dt());
    } catch (Throwable $e) { error_log('wq_backfill_chunk: ' . $e->getMessage()); }
    return ['typed' => $n, 'left' => $left];
}

// ------------------------------------------------------------------------------------------------------------------ the no-answer flow (decision 6)
function wq_clear_attention(PDO $pdo, string $phone, int $by, string $why): int
{
    try {
        $now = wq_dt();
        $u = $pdo->prepare("UPDATE wq_attention SET state = 'cleared', cleared_at = ?, cleared_by = ?, re_at = ?, why = ?, upd = ? WHERE phone = ? AND state IN ('held','escalated')");
        $u->execute([$now, $by, $now, mb_substr($why, 0, 200), $now, $phone]); return $u->rowCount();
    } catch (Throwable $e) { return 0; }
}
function wq_replied_after(PDO $pdo, string $phone, int $inId): bool
{
    $q = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND id > ? AND dir = 'out' AND who NOT IN ('auto','system','campaign','order') LIMIT 1"); $q->execute([$phone, $inId]); return (bool)$q->fetchColumn();
}
function wq_auto_send(PDO $pdo, array $c, string $text): array { return wa_send_text($pdo, $c, $text, 'auto', 0); }
/** The reply to send as the first automatic message: the bot's own prepared answer when the bot is ON and has one for this message, else the holding text. */
function wq_first_text(PDO $pdo, array $c, array $cfg): array
{
    try {
        if (function_exists('wag_cfg') && function_exists('wag_sugg_get') && wag_cfg($pdo)['bot'] === 'on') { $s = wag_sugg_get($pdo, (string)$c['phone']); if ($s && trim((string)$s['text']) !== '') return [mb_substr((string)$s['text'], 0, 1500), 'bot']; }
    } catch (Throwable $e) { }
    return [(string)$cfg['wq_hold_text'], 'hold'];
}
function wq_noans_scan(PDO $pdo, float $deadline): array
{
    $o = ['held' => 0, 'sent' => 0, 'escalated' => 0, 'cleared' => 0, 'skipped' => 0];
    if (!wq_on($pdo)) return $o;
    $cfg = wq_cfg($pdo); $now = wq_now(); $auto = $cfg['wq_auto_on'] === 1; $names = wq_names($pdo);
    // ---- 1. customers waiting: the holder is away or silent for wq_hold_sec -> ONE automatic reply
    $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 60");
    $st->execute([wq_dt($now - 23 * 3600), wq_dt($now - 2)]);
    foreach ($st->fetchAll() as $c) {
        if (microtime(true) > $deadline) break;
        $phone = (string)$c['phone']; if (!empty($c['cx_id'])) { $o['skipped']++; continue; }
        $li = wq_last_in($pdo, $phone); if (!$li || $li['mtype'] === 'reaction') continue; $inId = (int)$li['id']; $inTs = wq_ts($li['ts']);
        $qq = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $qq->execute([$phone]); $qid = (int)$qq->fetchColumn(); if ($qid <= 0) continue;
        $at = $pdo->prepare("SELECT * FROM wq_attention WHERE query_id = ?"); $at->execute([$qid]); $row = $at->fetch();
        if ($row && (int)$row['in_id'] >= $inId && in_array($row['state'], ['held', 'escalated', 'cleared'], true)) continue;
        $holder = (int)$c['assigned_emp']; $duty = $holder > 0 ? wq_duty_info($pdo, $holder, $now) : ['on' => false, 'state' => 'not_in', 'why' => 'Nobody holds this chat'];
        $wait = $duty['on'] ? $cfg['wq_hold_sec'] : 2; if ($now - $inTs < $wait) continue;
        if (wq_meta_get($pdo, 'typing:' . $phone, '') !== '' && $now - wq_ts(wq_meta_get($pdo, 'typing:' . $phone)) < 60) { $o['skipped']++; continue; }   // a person is typing in this chat
        $msgs = $row ? (int)$row['auto_msgs'] : 0; $sent = 0; $why = $duty['on'] ? ($names[$holder] ?? 'The holder') . ' did not reply within ' . $cfg['wq_hold_sec'] . ' s' : ($holder > 0 ? ($names[$holder] ?? 'The holder') . ' is away (' . $duty['why'] . ')' : 'Nobody holds this chat'); $mid = 0;
        if ($auto && $msgs < 2) {
            if (!wq_guard($pdo, $phone, $inId, 'reply', 'auto')) { $why .= '; the bot or a countdown is already answering'; }
            else {
                [$text, $kind] = wq_first_text($pdo, $c, $cfg); [$ok, $res] = wq_auto_send($pdo, $c, $text);
                if ($ok) { $sent = 1; $o['sent']++; $m2 = $pdo->prepare("SELECT id FROM wa_messages WHERE wamid = ?"); $m2->execute([(string)$res]); $mid = (int)$m2->fetchColumn(); if (function_exists('wa_event')) wa_event($pdo, $phone, 'Automatic reply sent (' . ($kind === 'bot' ? "the bot's prepared answer" : 'holding message') . ') - ' . $why); }
                else { wq_guard_release($pdo, $phone, $inId, 'reply'); $why .= '; automatic reply not sent: ' . mb_substr((string)$res, 0, 80); }
            }
        } elseif (!$auto) $why .= '; automatic replies are switched off';
        $pdo->prepare("INSERT INTO wq_attention (query_id, phone, in_id, holder, state, stage, hold_at, since, auto_msgs, hold_msg_id, why, upd) VALUES (?,?,?,?, 'held', 1, ?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE phone = VALUES(phone), in_id = VALUES(in_id), holder = VALUES(holder), state = 'held', stage = 1, hold_at = VALUES(hold_at), since = VALUES(since), auto_msgs = auto_msgs + ?, hold_msg_id = VALUES(hold_msg_id), why = VALUES(why), cleared_at = NULL, re_at = NULL, upd = VALUES(upd)")
            ->execute([$qid, $phone, $inId, $holder, wq_dt($now), wq_dt($inTs), $sent, $mid, mb_substr($why, 0, 200), wq_dt($now), $sent]);
        $o['held']++;
    }
    // ---- 2. a holding message went out and still nobody answered after wq_esc_min -> second message, Attention needed, push to admins
    $st = $pdo->prepare("SELECT a.*, c.optout, c.cx_id, c.last_in_at, c.name FROM wq_attention a JOIN wa_contacts c ON c.phone = a.phone WHERE a.state = 'held' AND a.hold_at <= ? ORDER BY a.hold_at LIMIT 40");
    $st->execute([wq_dt($now - $cfg['wq_esc_min'] * 60)]);
    foreach ($st->fetchAll() as $a) {
        if (microtime(true) > $deadline) break; $phone = (string)$a['phone']; $inId = (int)$a['in_id']; $upd = fn(string $s, string $w) => $pdo->prepare("UPDATE wq_attention SET state = ?, why = ?, upd = ? WHERE query_id = ?")->execute([$s, mb_substr($w, 0, 200), wq_dt($now), (int)$a['query_id']]);
        if (wq_replied_after($pdo, $phone, $inId)) { $upd('ok', 'Someone replied'); $o['cleared']++; continue; }
        if ((int)$a['optout'] === 1 || !empty($a['cx_id'])) { $upd('ok', (int)$a['optout'] === 1 ? 'Customer opted out' : 'Chat is with the Complaint team'); $o['cleared']++; continue; }
        $msgs = (int)$a['auto_msgs']; $c = wq_contact($pdo, $phone); $open = $c && wq_ts($c['last_in_at']) >= $now - 86400; $sent = 0;
        if ($auto && $msgs < 2 && $open && wq_guard($pdo, $phone, $inId, 'esc', 'auto')) {
            [$ok, $res] = wq_auto_send($pdo, $c, (string)$cfg['wq_esc_text']);
            if ($ok) { $sent = 1; $o['sent']++; if (function_exists('wa_event')) wa_event($pdo, $phone, 'Second automatic message sent - nobody answered in ' . $cfg['wq_esc_min'] . ' min'); } else wq_guard_release($pdo, $phone, $inId, 'esc');
        }
        $holder = (int)$a['holder']; $hn = $holder > 0 ? ($names[$holder] ?? 'The holder') : 'Nobody';
        $pdo->prepare("UPDATE wq_attention SET state = 'escalated', esc_at = ?, auto_msgs = auto_msgs + ?, why = ?, upd = ? WHERE query_id = ?")
            ->execute([wq_dt($now), $sent, mb_substr(($holder > 0 ? $hn . ' did not reply' : 'Nobody replied') . ' within ' . $cfg['wq_esc_min'] . ' min of the holding message', 0, 200), wq_dt($now), (int)$a['query_id']]);
        $o['escalated']++;
        if (function_exists('ct_push')) { try { $ids = wq_admin_ids($pdo); if ($ids) ct_push($pdo, $ids, 'WhatsApp: attention needed', ($a['name'] !== '' ? $a['name'] : '+91 ' . $phone) . ' is still waiting (' . $hn . ').', '/wa_sales_live.html#attention', 'wq-att-' . $phone); } catch (Throwable $e) { } }
    }
    // ---- 2b. an admin gave the chat to someone (cleared) and that person is silent too -> flagged again (no more automatic messages: the limit is 2)
    $st = $pdo->prepare("SELECT a.* FROM wq_attention a WHERE a.state = 'cleared' AND a.re_at IS NOT NULL AND a.re_at <= ? LIMIT 40"); $st->execute([wq_dt($now - $cfg['wq_esc_min'] * 60)]);
    foreach ($st->fetchAll() as $a) {
        $phone = (string)$a['phone']; $li = wq_last_in($pdo, $phone);
        if (!$li || wq_replied_after($pdo, $phone, (int)$li['id']) || wq_ts($li['ts']) < $now - 86400) { $pdo->prepare("UPDATE wq_attention SET state = 'ok', re_at = NULL, upd = ? WHERE query_id = ?")->execute([wq_dt($now), (int)$a['query_id']]); continue; }
        $c = wq_contact($pdo, $phone); $h = $c ? (int)$c['assigned_emp'] : 0;
        $pdo->prepare("UPDATE wq_attention SET state = 'escalated', esc_at = ?, holder = ?, why = ?, re_at = NULL, upd = ? WHERE query_id = ?")->execute([wq_dt($now), $h, mb_substr(($h > 0 ? ($names[$h] ?? 'The new holder') : 'Nobody') . ' has not replied either', 0, 200), wq_dt($now), (int)$a['query_id']]);
        $o['escalated']++;
    }
    // ---- 2c. escalated and somebody answered meanwhile -> clears by itself
    $st = $pdo->query("SELECT query_id, phone, in_id FROM wq_attention WHERE state = 'escalated' ORDER BY since LIMIT 60");
    foreach ($st->fetchAll() as $a) if (wq_replied_after($pdo, (string)$a['phone'], (int)$a['in_id'])) { $pdo->prepare("UPDATE wq_attention SET state = 'ok', cleared_at = ?, why = 'Someone replied', upd = ? WHERE query_id = ?")->execute([wq_dt($now), wq_dt($now), (int)$a['query_id']]); $o['cleared']++; }
    return $o;
}
/** Salesperson's chat page pings this while typing: the no-answer flow leaves the chat alone for 60 seconds. */
function wq_typing(PDO $pdo, string $phone): void { wq_meta_put($pdo, 'typing:' . $phone, wq_dt()); }

// ---- hooks for assignment (wa_assign_to calls wq_after_assign; the two writers that bypass it call wq_bypass_move)
function wq_after_assign(PDO $pdo, string $key, int $from, int $to, int $by = 0, string $how = ''): void
{
    try {
        if (!wq_on($pdo) || !wq_tables($pdo)) return;
        if ($from !== $to) wq_clear_attention($pdo, $key, $by, $to > 0 ? 'Given to a person' : 'Taken off the person');
        $q = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $qid = (int)$q->fetchColumn(); if ($qid > 0) $pdo->prepare("UPDATE wq_qstat SET stat_at = NULL WHERE query_id = ? AND final = 0")->execute([$qid]);
    } catch (Throwable $e) { }
}
/** For the writers that change assigned_emp directly (complaints.php unlink, claude_q.php leaver handover): writes the missing assign log row, moves the query in the ledger, runs the wq side. */
function wq_bypass_move(PDO $pdo, string $key, int $from, int $to, int $by = 0, string $how = 'system'): void
{
    try {
        if ($from === $to) return;
        $pdo->prepare("INSERT INTO wa_assign_log (phone, from_emp, to_emp, by_emp, how, at) VALUES (?,?,?,?,?,?)")->execute([$key, $from, $to, $by, mb_substr($how, 0, 10), wq_dt()]);
        if (function_exists('wag_q_moved')) wag_q_moved($pdo, $key, $from, $to);
        wq_after_assign($pdo, $key, $from, $to, $by, $how);
    } catch (Throwable $e) { error_log('wq_bypass_move: ' . $e->getMessage()); }
}
/** Leaver handover: the phones that are about to move (call BEFORE the bulk UPDATE), then wq_handover_post AFTER it. */
function wq_handover_pre(PDO $pdo, int $emp, string $cond): array
{
    try { $q = $pdo->prepare("SELECT phone FROM wa_contacts WHERE assigned_emp = ? AND ($cond) LIMIT 5000"); $q->execute([$emp]); return $q->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
}
function wq_handover_post(PDO $pdo, array $phones, int $from, int $to): int
{
    $n = 0; foreach ($phones as $p) { wq_bypass_move($pdo, (string)$p, $from, $to, 0, 'handover'); $n++; } return $n;
}

// ------------------------------------------------------------------------------------------------------------------ periods
function wq_day_of(PDO $pdo, int $ts): string { return function_exists('biz_day_of') ? biz_day_of($pdo, $ts) : date('Y-m-d', $ts); }
function wq_day_start(PDO $pdo, string $day): int { $s = function_exists('day_start_for') ? day_start_for($pdo, $day) : '10:00'; return (int)strtotime($day . ' ' . $s . ':00'); }
function wq_day_add(string $day, int $n): string { return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' day')); }
/** The last $days business days ending now: [fromTs, toTs, fromDay, toDay, [days]] (to = the end of the current business day for counting, now for display). */
function wq_period(PDO $pdo, int $days, ?int $now = null): array
{
    $now = $now ?: wq_now(); $days = in_array($days, [1, 7, 30, 90], true) ? $days : max(1, min(90, $days)); $today = wq_day_of($pdo, $now); $from = wq_day_add($today, -($days - 1)); $list = [];
    for ($i = 0; $i < $days; $i++) $list[] = wq_day_add($from, $i);
    return [wq_day_start($pdo, $from), wq_day_start($pdo, wq_day_add($today, 1)), $from, $today, $list];
}
/** Working days of one person in a day list: the days not marked leave / holiday / week_off / off. */
function wq_working_days(PDO $pdo, int $emp, array $days): int
{
    if (!$days) return 0; $off = 0;
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM day_marks WHERE employee_id = ? AND mark_date BETWEEN ? AND ? AND mark IN ('leave','holiday','week_off','off')"); $q->execute([$emp, $days[0], $days[count($days) - 1]]); $off = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    return max(0, count($days) - $off);
}
function wq_hours_worked(PDO $pdo, int $emp, int $fromTs, int $toTs): float
{
    $s = 0.0; $now = wq_now();
    try {
        $q = $pdo->prepare("SELECT punch_in, punch_out FROM attendance WHERE employee_id = ? AND punch_in >= ? AND punch_in < ?"); $q->execute([$emp, wq_dt($fromTs), wq_dt($toTs)]);
        foreach ($q->fetchAll() as $r) { $a = wq_ts($r['punch_in']); $b = $r['punch_out'] ? wq_ts($r['punch_out']) : min($now, $a + 16 * 3600); if ($b > $a) $s += ($b - $a) / 3600; }
    } catch (Throwable $e) { }
    return round($s, 1);
}
function wq_goals(PDO $pdo, array $cfg, int $workDays, int $queries, int $orders, $replyMin): array
{
    $gq = $cfg['wq_goal_queries'] * $workDays; $go = $cfg['wq_goal_orders'] === null ? null : $cfg['wq_goal_orders'] * $workDays; $gr = $cfg['wq_goal_reply_min'];
    $conv = function_exists('wa_sales_cfg') ? (int)wa_sales_cfg($pdo)['goal'] : (int)wq_get($pdo, 'wa_sales_goal', 23);
    $goal = ['queries' => $gq, 'orders' => $go, 'reply_min' => $gr, 'conversion' => $conv];
    if ($workDays <= 0) return [$goal, ['queries_pct' => null, 'orders_pct' => null, 'reply_pct' => null]];
    return [$goal, ['queries_pct' => $gq > 0 ? round(100 * $queries / $gq, 1) : null, 'orders_pct' => ($go !== null && $go > 0) ? round(100 * $orders / $go, 1) : null,
        'reply_pct' => ($replyMin !== null && (float)$replyMin > 0) ? round(100 * $gr / (float)$replyMin, 1) : null]];
}

// ------------------------------------------------------------------------------------------------------------------ board numbers
function wq_blank_types(): array { $o = []; foreach (wq_types() as $k => $l) $o[$k] = 0; $o['unclear'] = 0; return $o; }
/**
 * Per-holder numbers for queries opened in [$fromTs, $toTs). One grouped read of the summary tables, aggregated here.
 * Returns ['rows'=>[emp=>stats], 'total'=>stats, 'unassigned'=>n].
 */
function wq_people_stats(PDO $pdo, int $fromTs, int $toTs): array
{
    $st = $pdo->prepare("SELECT q.id, q.emp_id, q.first_emp, COALESCE(t.type, 'unclear') type, COALESCE(s.answered, 0) answered, s.first_person_min fpm, COALESCE(s.orders, 0) orders, COALESCE(s.revenue, 0) rev
        FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ?");
    $st->execute([wq_dt($fromTs), wq_dt($toTs)]);
    $blank = fn() => ['queries' => 0, 'by_type' => wq_blank_types(), 'replied' => 0, 'unanswered' => 0, 'orders' => 0, 'converted' => 0, 'revenue' => 0.0, 'fpm' => [], 'transferred_in' => 0, 'transferred_out' => 0];
    $rows = []; $tot = $blank(); $holder = []; $unassigned = 0;
    $add = function (array &$x, array $r, string $t) { $x['queries']++; $x['by_type'][$t]++; if ((int)$r['answered']) $x['replied']++; else $x['unanswered']++;
        $x['orders'] += (int)$r['orders']; if ((int)$r['orders'] > 0) $x['converted']++; $x['revenue'] += (float)$r['rev']; if ($r['fpm'] !== null) $x['fpm'][] = (float)$r['fpm']; };
    foreach ($st->fetchAll() as $r) {
        $e = (int)$r['emp_id']; $holder[(int)$r['id']] = $e; $t = isset($tot['by_type'][$r['type']]) ? $r['type'] : 'unclear';
        $add($tot, $r, $t);
        if ($e > 0) { if (!isset($rows[$e])) $rows[$e] = $blank(); $add($rows[$e], $r, $t); } else $unassigned++;
    }
    $mv = $pdo->prepare("SELECT DISTINCT m.query_id, m.from_emp, m.to_emp FROM wa_query_moves m JOIN wa_queries q ON q.id = m.query_id WHERE m.at >= ? AND q.opened_at >= ? AND q.opened_at < ?"); $mv->execute([wq_dt($fromTs), wq_dt($fromTs), wq_dt($toTs)]);   // a move happens after the query opened: k_at narrows it
    $seenIn = []; $seenOut = [];
    foreach ($mv->fetchAll() as $m) {
        $qid = (int)$m['query_id']; $h = $holder[$qid] ?? 0; $to = (int)$m['to_emp']; $fr = (int)$m['from_emp'];
        if ($to > 0 && $to === $h && empty($seenIn[$qid . '|' . $to])) { $seenIn[$qid . '|' . $to] = 1; if (!isset($rows[$to])) $rows[$to] = $blank(); $rows[$to]['transferred_in']++; }
        if ($fr > 0 && $fr !== $h && empty($seenOut[$qid . '|' . $fr])) { $seenOut[$qid . '|' . $fr] = 1; if (!isset($rows[$fr])) $rows[$fr] = $blank(); $rows[$fr]['transferred_out']++; }
    }
    return ['rows' => $rows, 'total' => $tot, 'unassigned' => $unassigned];
}
function wq_behind(array $x, array $team, int $minN): ?array
{
    $why = []; $q = (int)$x['queries']; $med = wq_median($x['fpm']);
    if ($x['unanswered'] >= 3 && $q > 0 && $x['unanswered'] / $q > 0.25) $why[] = $x['unanswered'] . ' of ' . $q . ' queries are still unanswered';
    if ($med !== null && count($x['fpm']) >= 5 && $team['reply'] !== null && $med > 3 * $team['reply'] && $med > 10) $why[] = 'First reply takes ' . round($med, 1) . ' min, the team median is ' . round($team['reply'], 1) . ' min';
    $minQ = max(10, (int)floor($minN / 3)); $conv = $q > 0 ? 100 * $x['converted'] / $q : null;
    if ($conv !== null && $q >= $minQ && $team['conv'] !== null && $team['conv'] > 0 && $conv < $team['conv'] / 2) $why[] = 'Conversion ' . round($conv, 1) . '% is far below the team median ' . round($team['conv'], 1) . '%';
    return $why ? ['is' => true, 'reasons' => $why] : null;
}
function wq_team_medians(array $rows, int $minN): array
{
    $minQ = max(10, (int)floor($minN / 3)); $conv = []; $rep = [];
    foreach ($rows as $x) { if ($x['queries'] >= $minQ) $conv[] = 100 * $x['converted'] / $x['queries']; $m = wq_median($x['fpm']); if ($m !== null && count($x['fpm']) >= 5) $rep[] = $m; }
    return ['conv' => wq_median($conv), 'reply' => wq_median($rep)];
}
function wq_person_out(PDO $pdo, int $emp, array $x, array $team, array $cfg, array $days, int $fromTs, int $toTs, array $names, bool $withDuty = true): array
{
    $med = wq_median($x['fpm']); $wd = wq_working_days($pdo, $emp, $days); [$goal, $prog] = wq_goals($pdo, $cfg, $wd, (int)$x['queries'], (int)$x['orders'], $med);
    $o = ['emp_id' => $emp, 'name' => wq_name($names, $emp), 'queries' => (int)$x['queries'], 'by_type' => $x['by_type'], 'transferred_in' => (int)$x['transferred_in'], 'transferred_out' => (int)$x['transferred_out'],
        'replied' => (int)$x['replied'], 'unanswered' => (int)$x['unanswered'], 'first_reply_min' => wq_num($med, 1), 'orders' => (int)$x['orders'], 'converted' => (int)$x['converted'], 'revenue' => round((float)$x['revenue']),
        'conversion' => wq_pct($x['converted'], $x['queries']), 'hours_worked' => wq_hours_worked($pdo, $emp, $fromTs, $toTs), 'working_days' => $wd, 'goal' => $goal, 'progress' => $prog, 'behind' => wq_behind($x, $team, (int)$cfg['wq_min_n'])];
    if ($withDuty) { $d = wq_duty_info($pdo, $emp); $o['on_duty'] = $d['on']; $o['duty'] = ['state' => $d['state'], 'why' => $d['why']]; }
    return $o;
}
function wq_board(PDO $pdo, int $days): array
{
    $cfg = wq_cfg($pdo); [$f, $t, $fd, $td, $list] = wq_period($pdo, $days); $S = wq_people_stats($pdo, $f, $t); $names = wq_names($pdo);
    $team = wq_team_medians($S['rows'], (int)$cfg['wq_min_n']); $ids = array_keys($S['rows']);
    foreach (wq_sales_people($pdo) as $p) if (!isset($S['rows'][(int)$p['employee_id']])) { $ids[] = (int)$p['employee_id']; $S['rows'][(int)$p['employee_id']] = ['queries' => 0, 'by_type' => wq_blank_types(), 'replied' => 0, 'unanswered' => 0, 'orders' => 0, 'converted' => 0, 'revenue' => 0.0, 'fpm' => [], 'transferred_in' => 0, 'transferred_out' => 0]; }
    $people = []; foreach (array_unique($ids) as $e) $people[] = wq_person_out($pdo, (int)$e, $S['rows'][$e], $team, $cfg, $list, $f, $t, $names);
    usort($people, fn($a, $b) => [$b['queries'], $a['name']] <=> [$a['queries'], $b['name']]);
    $T = $S['total'];
    if (!function_exists('cbd_count') && is_file(__DIR__ . '/cust_bday.php')) { try { require_once __DIR__ . '/cust_bday.php'; } catch (Throwable $e) { } }
    $bd = function_exists('cbd_count') ? cbd_count($pdo, wq_dt($f), wq_dt(wq_now())) : ['total' => 0, 'by_customer' => 0, 'by_team' => 0];   // 🎂 birthdays received in this period
    return ['ok' => true, 'on' => (bool)$cfg['wq_on'], 'days' => $days, 'from' => wq_dt($f), 'to' => wq_dt(wq_now()), 'min_n' => $cfg['wq_min_n'], 'birthdays' => $bd,
        'totals' => ['queries' => $T['queries'], 'by_type' => $T['by_type'], 'replied' => $T['replied'], 'unanswered' => $T['unanswered'], 'orders' => $T['orders'], 'converted' => $T['converted'], 'revenue' => round($T['revenue']),
            'conversion' => wq_pct($T['converted'], $T['queries']), 'first_reply_min' => wq_num(wq_median($T['fpm']), 1)],
        'team_median' => ['conversion' => wq_num($team['conv'], 1), 'first_reply_min' => wq_num($team['reply'], 1)], 'people' => $people, 'unassigned' => ['queries' => $S['unassigned']]];
}
function wq_waiting_list(PDO $pdo, int $emp, int $max = 20): array
{
    $now = wq_now(); $st = $pdo->prepare("SELECT c.phone, c.name, c.last_in_at, COALESCE((SELECT t.type FROM wq_qtype t WHERE t.phone = c.phone ORDER BY t.query_id DESC LIMIT 1), 'unclear') type
        FROM wa_contacts c WHERE c.assigned_emp = ? AND c.status = 'open' AND c.optout = 0 AND c.last_in_at >= ? AND (c.last_out_at IS NULL OR c.last_out_at < c.last_in_at) ORDER BY c.last_in_at LIMIT " . (int)$max);
    $st->execute([$emp, wq_dt($now - 86400)]); $o = [];
    foreach ($st->fetchAll() as $r) $o[] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'type' => (string)$r['type'], 'type_label' => wq_type_label((string)$r['type']), 'since_min' => max(0, (int)floor(($now - wq_ts($r['last_in_at'])) / 60))];
    return $o;
}
function wq_mine(PDO $pdo, int $emp, int $days): array
{
    $cfg = wq_cfg($pdo); [$f, $t, $fd, $td, $list] = wq_period($pdo, $days); $names = wq_names($pdo);
    if ($emp <= 0) { [$g, $p] = wq_goals($pdo, $cfg, 0, 0, 0, null); return ['ok' => true, 'on' => (bool)$cfg['wq_on'], 'emp_id' => 0, 'name' => '', 'days' => $days, 'queries' => 0, 'by_type' => wq_blank_types(), 'transferred_in' => 0, 'transferred_out' => 0, 'replied' => 0, 'unanswered' => 0, 'first_reply_min' => null, 'orders' => 0, 'converted' => 0, 'revenue' => 0, 'conversion' => null, 'hours_worked' => 0, 'on_duty' => false, 'working_days' => 0, 'goal' => $g, 'progress' => $p, 'behind' => null, 'waiting' => []]; }
    $S = wq_people_stats($pdo, $f, $t); $team = wq_team_medians($S['rows'], (int)$cfg['wq_min_n']);
    $x = $S['rows'][$emp] ?? ['queries' => 0, 'by_type' => wq_blank_types(), 'replied' => 0, 'unanswered' => 0, 'orders' => 0, 'converted' => 0, 'revenue' => 0.0, 'fpm' => [], 'transferred_in' => 0, 'transferred_out' => 0];
    $o = wq_person_out($pdo, $emp, $x, $team, $cfg, $list, $f, $t, $names); unset($o['duty']);
    return ['ok' => true, 'on' => (bool)$cfg['wq_on'], 'days' => $days] + $o + ['waiting' => wq_waiting_list($pdo, $emp)];
}

// ------------------------------------------------------------------------------------------------------------------ query list / detail
function wq_item(PDO $pdo, array $r, array $names, ?array $att = null): array
{
    $T = (string)($r['type'] ?? 'unclear'); $holder = (int)$r['emp_id']; $first = (int)$r['first_emp'];
    $state = $att && $att['state'] === 'escalated' ? 'escalated' : (!empty($r['answered']) ? 'answered' : 'waiting');
    if (($r['status'] ?? '') === 'closed' && $state === 'answered') $state = 'closed';
    $tags = wq_tags_of($pdo, (string)$r['phone']);
    return ['query_id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => (string)($r['cname'] ?? ''), 'type' => $T, 'type_label' => wq_type_label($T), 'reason' => (string)($r['reason'] ?? ''), 'auto' => !isset($r['auto']) || (int)$r['auto'] === 1,
        'opened_at' => (string)$r['opened_at'], 'arrived' => wq_arrived(wq_ts($r['opened_at'])), 'holder' => wq_person($names, $holder), 'first_holder' => wq_person($names, $first), 'transferred_in' => $first > 0 && $holder > 0 && $first !== $holder,
        'state' => $state, 'first_reply_min' => isset($r['fpm']) && $r['fpm'] !== null ? round((float)$r['fpm'], 1) : null, 'reply_by' => !empty($r['answered']) ? ((string)$r['first_by'] === 'bot' ? 'bot' : 'person') : 'none',
        'orders' => (int)($r['orders'] ?? 0), 'revenue' => round((float)($r['rev'] ?? 0)), 'tags' => $tags];
}
function wq_q_select(): string { return "SELECT q.id, q.phone, q.opened_at, q.emp_id, q.first_emp, COALESCE(t.type,'unclear') type, t.reason, t.auto, c.name cname, c.status, COALESCE(s.answered,0) answered, s.first_person_min fpm, COALESCE(s.person_n,0) person_n, COALESCE(s.first_by,'') first_by,
        COALESCE(s.orders,0) orders, COALESCE(s.revenue,0) rev FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wq_qstat s ON s.query_id = q.id LEFT JOIN wa_contacts c ON c.phone = q.phone"; }
function wq_queries_list(PDO $pdo, array $p): array
{
    $names = wq_names($pdo); $days = max(1, min(90, (int)($p['days'] ?? 7))); $page = max(1, (int)($p['page'] ?? 1)); $per = max(1, min(100, (int)($p['per'] ?? 30))); $type = (string)($p['type'] ?? 'all');
    $from = wq_dt(wq_now() - $days * 86400); $w = ["q.opened_at >= ?"]; $a = [$from];
    $emp = (int)($p['emp_id'] ?? 0); if ($emp > 0) { $w[] = 'q.emp_id = ?'; $a[] = $emp; }
    $s = trim((string)($p['q'] ?? ''));
    if (mb_strlen($s) >= 2) { $dg = preg_replace('/\D+/', '', $s); if (strlen($dg) > 10) $dg = substr($dg, -10); if (strlen($dg) >= 4) { $w[] = 'q.phone LIKE ?'; $a[] = '%' . $dg . '%'; } else { $w[] = 'c.name LIKE ?'; $a[] = '%' . $s . '%'; } }
    $base = implode(' AND ', $w);
    $cn = $pdo->prepare("SELECT COALESCE(t.type,'unclear') ty, COUNT(*) n FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wa_contacts c ON c.phone = q.phone WHERE $base GROUP BY ty"); $cn->execute($a);
    $counts = array_merge(['all' => 0], wq_blank_types()); foreach ($cn->fetchAll() as $r) { $k = isset($counts[$r['ty']]) ? $r['ty'] : 'unclear'; $counts[$k] += (int)$r['n']; $counts['all'] += (int)$r['n']; }
    $w2 = $base; $a2 = $a; if ($type !== 'all' && isset($counts[$type])) { $w2 .= $type === 'unclear' ? " AND (t.type IS NULL OR t.type = 'unclear')" : " AND t.type = ?"; if ($type !== 'unclear') $a2[] = $type; }
    $total = $type !== 'all' && isset($counts[$type]) ? $counts[$type] : $counts['all'];
    $st = $pdo->prepare(wq_q_select() . " WHERE $w2 ORDER BY q.opened_at DESC, q.id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per)); $st->execute($a2); $rows = $st->fetchAll();
    $att = []; if ($rows) { $ids = array_column($rows, 'id'); $q = $pdo->prepare("SELECT * FROM wq_attention WHERE query_id IN (" . wq_in($ids) . ")"); $q->execute($ids); foreach ($q->fetchAll() as $x) $att[(int)$x['query_id']] = $x; }
    $items = []; foreach ($rows as $r) $items[] = wq_item($pdo, $r, $names, $att[(int)$r['id']] ?? null);
    return ['ok' => true, 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts, 'items' => $items];
}
function wq_query_detail(PDO $pdo, int $qid): ?array
{
    $st = $pdo->prepare(wq_q_select() . " WHERE q.id = ?"); $st->execute([$qid]); $r = $st->fetch(); if (!$r) return null; $names = wq_names($pdo);
    $a = $pdo->prepare("SELECT * FROM wq_attention WHERE query_id = ?"); $a->execute([$qid]); $att = $a->fetch() ?: null; $t = wq_qtype_get($pdo, $qid);
    $rule = null; if ($t && (int)$t['rule_id'] > 0) { $q = $pdo->prepare("SELECT * FROM wq_rules WHERE id = ?"); $q->execute([(int)$t['rule_id']]); $x = $q->fetch(); if ($x) $rule = ['id' => (int)$x['id'], 'label' => wq_rule_label($x)]; }
    $mv = $pdo->prepare("SELECT m.at, m.from_emp, m.to_emp, m.late, (SELECT l.by_emp FROM wa_assign_log l WHERE l.phone = ? AND l.to_emp = m.to_emp AND l.at <= DATE_ADD(m.at, INTERVAL 5 SECOND) ORDER BY l.id DESC LIMIT 1) by_emp, (SELECT l.how FROM wa_assign_log l WHERE l.phone = ? AND l.to_emp = m.to_emp AND l.at <= DATE_ADD(m.at, INTERVAL 5 SECOND) ORDER BY l.id DESC LIMIT 1) how FROM wa_query_moves m WHERE m.query_id = ? ORDER BY m.id");
    $mv->execute([$r['phone'], $r['phone'], $qid]); $moves = [];
    foreach ($mv->fetchAll() as $m) $moves[] = ['at' => (string)$m['at'], 'from' => wq_person($names, (int)$m['from_emp']), 'to' => wq_person($names, (int)$m['to_emp']), 'late' => (int)$m['late'] === 1, 'by' => (int)$m['by_emp'] > 0 ? wq_name($names, (int)$m['by_emp']) : 'Automatic', 'how' => (string)($m['how'] ?? '')];
    $o = wq_query_row($pdo, $qid); $end = $pdo->prepare("SELECT MIN(opened_at) FROM wa_queries WHERE phone = ? AND id > ?"); $end->execute([$r['phone'], $qid]); $e = wq_ts($end->fetchColumn()) ?: wq_now() + 86400;
    $am = $pdo->prepare("SELECT ts, body, id, wamid FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'auto' AND ts >= ? AND ts < ? ORDER BY id LIMIT 6"); $am->execute([$r['phone'], $r['opened_at'], wq_dt($e)]); $auto = [];
    $first = true; foreach ($am->fetchAll() as $m) { $kind = $first ? 'hold' : 'escalate'; $first = false; $auto[] = ['at' => (string)$m['ts'], 'kind' => $kind, 'text' => (string)$m['body'], 'who' => 'auto']; }
    $tags = []; foreach (wq_ctag_list($pdo, (string)$r['phone']) as $x) $tags[] = ['tag' => (string)$x['tag'], 'label' => wq_tag_labels()[$x['tag']] ?? (string)$x['tag'], 'src' => (string)$x['src']];
    $sb = $t ? (int)$t['set_by'] : 0;
    return ['ok' => true, 'query' => wq_item($pdo, $r, $names, $att), 'typing' => $t ? ['type' => (string)$t['type'], 'reason' => (string)$t['reason'], 'auto' => (int)$t['auto'] === 1, 'rule' => $rule, 'set_by' => $sb > 0 ? wq_person($names, $sb) : ['emp_id' => 0, 'name' => 'Automatic'], 'at' => (string)$t['at']] : null,
        'moves' => $moves, 'auto_messages' => $auto, 'attention' => $att ? ['state' => (string)$att['state'], 'since' => (string)$att['since'], 'cleared_at' => $att['cleared_at'], 'why' => (string)$att['why']] : null, 'tags' => $tags];
}
function wq_attention_list(PDO $pdo): array
{
    $names = wq_names($pdo); $now = wq_now();
    $st = $pdo->query("SELECT a.*, c.name cname, COALESCE(t.type,'unclear') type FROM wq_attention a LEFT JOIN wa_contacts c ON c.phone = a.phone LEFT JOIN wq_qtype t ON t.query_id = a.query_id WHERE a.state = 'escalated' ORDER BY a.since LIMIT 200"); $o = [];
    foreach ($st->fetchAll() as $r) {
        $lb = $pdo->prepare("SELECT body FROM wa_messages WHERE id = ?"); $lb->execute([(int)$r['in_id']]);
        $o[] = ['query_id' => (int)$r['query_id'], 'phone' => (string)$r['phone'], 'name' => (string)$r['cname'], 'type' => (string)$r['type'], 'type_label' => wq_type_label((string)$r['type']), 'holder' => wq_person($names, (int)$r['holder']), 'since' => (string)$r['since'],
            'waiting_min' => max(0, (int)floor(($now - wq_ts($r['since'])) / 60)), 'state' => 'escalated', 'auto_msgs' => (int)$r['auto_msgs'], 'reason' => (string)$r['why'], 'last_in' => mb_substr((string)$lb->fetchColumn(), 0, 120)];
    }
    return $o;
}
function wq_tags_for(PDO $pdo, string $phone): array
{
    $q = $pdo->prepare("SELECT t.query_id, t.type, t.reason FROM wq_qtype t WHERE t.phone = ? ORDER BY t.query_id DESC LIMIT 1"); $q->execute([$phone]); $t = $q->fetch(); $tags = [];
    foreach (wq_ctag_list($pdo, $phone) as $x) { $tag = (string)$x['tag']; $tags[] = ['tag' => $tag, 'label' => wq_tag_labels()[$tag] ?? $tag, 'src' => (string)$x['src'], 'group' => (string)$x['src'] === 'wq' ? wq_tag_group($tag) : 'other']; }
    usort($tags, fn($a, $b) => [array_search($a['group'], ['type', 'time', 'value', 'other']), $a['tag']] <=> [array_search($b['group'], ['type', 'time', 'value', 'other']), $b['tag']]);
    return ['ok' => true, 'phone' => $phone, 'type' => $t ? ['query_id' => (int)$t['query_id'], 'type' => (string)$t['type'], 'label' => wq_type_label((string)$t['type']), 'reason' => (string)$t['reason'], 'pill' => $t['type'] === 'unclear' ? 'To decide' : wq_type_label((string)$t['type']) . ' query'] : null, 'tags' => $tags];
}

// ------------------------------------------------------------------------------------------------------------------ compare bot vs person (decision 8)
function wq_compare(PDO $pdo, int $days, string $type): array
{
    $cfg = wq_cfg($pdo); $minN = (int)$cfg['wq_min_n']; [$f, $t] = wq_period($pdo, $days);
    $sql = "SELECT s.grp, s.first_in_at, s.first_out_at, s.orders, s.revenue, s.stopped FROM wq_qstat s FORCE INDEX (k_open) JOIN wq_qtype ty ON ty.query_id = s.query_id WHERE s.opened_at >= ? AND s.opened_at < ? AND s.grp <> ''"; $a = [wq_dt($f), wq_dt($t)];
    if ($type !== 'all' && isset(wq_types()[$type])) { $sql .= ' AND ty.type = ?'; $a[] = $type; } else $type = 'all';
    $st = $pdo->prepare($sql); $st->execute($a); $G = [];
    foreach (['bot_only', 'person_only', 'bot_then_person'] as $g) $G[$g] = ['n' => 0, 'min' => [], 'conv' => 0, 'rev' => 0.0, 'stop' => 0];
    foreach ($st->fetchAll() as $r) {
        $x = &$G[$r['grp']]; if (!isset($x)) continue; $x['n']++; if ($r['first_in_at'] && $r['first_out_at']) $x['min'][] = max(0, (wq_ts($r['first_out_at']) - wq_ts($r['first_in_at'])) / 60);
        if ((int)$r['orders'] > 0) $x['conv']++; $x['rev'] += (float)$r['revenue']; $x['stop'] += (int)$r['stopped']; unset($x);
    }
    $botStarted = $G['bot_only']['n'] + $G['bot_then_person']['n']; $groups = [];
    foreach ($G as $g => $x) $groups[$g] = ['n' => $x['n'], 'first_reply_min' => wq_num(wq_median($x['min']), 1), 'conversion' => wq_pct($x['conv'], $x['n']), 'revenue_per_query' => $x['n'] ? round($x['rev'] / $x['n']) : null,
        'handover_rate' => ($g !== 'person_only' && $botStarted > 0) ? round(100 * $G['bot_then_person']['n'] / $botStarted, 1) : null, 'stopped_replying' => wq_pct($x['stop'], $x['n'])];
    $enough = $G['bot_only']['n'] >= $minN && $G['person_only']['n'] >= $minN;
    if (!$enough) {
        $low = []; foreach (['bot_only' => 'Bot only', 'person_only' => 'Person only'] as $g => $l) if ($G[$g]['n'] < $minN) $low[] = $l . ' has ' . $G[$g]['n'];
        $verdict = 'Not enough data yet (need at least ' . $minN . ' queries in each group; ' . implode(', ', $low) . ').';
    } else {
        $b = $groups['bot_only']['conversion']; $p = $groups['person_only']['conversion']; $per = $days === 1 ? 'today' : 'in the last ' . $days . ' days';
        if ($b !== null && $p !== null && abs($b - $p) >= 1) $verdict = ($p > $b ? 'Person only' : 'Bot only') . ' converts better than ' . ($p > $b ? 'Bot only' : 'Person only') . ' (' . max($b, $p) . '% vs ' . min($b, $p) . '%) ' . $per . '.';
        else $verdict = 'No clear difference in conversion between Bot only (' . $b . '%) and Person only (' . $p . '%) ' . $per . '.';
        $bm = $groups['bot_only']['first_reply_min']; $pm = $groups['person_only']['first_reply_min']; if ($bm !== null && $pm !== null) $verdict .= ' The bot answers in ' . $bm . ' min, a person in ' . $pm . ' min.';
    }
    return ['ok' => true, 'days' => $days, 'type' => $type, 'min_n' => $minN, 'enough' => $enough, 'groups' => $groups, 'verdict' => $verdict];
}

// ------------------------------------------------------------------------------------------------------------------ what won (decision 8)
/** Replies by PEOPLE that led to an order within wq_attr_days: copies the two texts so the owner can teach or ignore them. */
function wq_win_scan(PDO $pdo, float $deadline, int $limit = 40): int
{
    $n = 0;
    try {
        $st = $pdo->prepare("SELECT s.query_id, s.phone, s.opened_at, s.first_person_at, s.first_order, s.first_order_at, s.first_order_amt, COALESCE(t.type,'unclear') type, q.emp_id FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id LEFT JOIN wq_qtype t ON t.query_id = s.query_id
            WHERE s.opened_at >= ? AND s.orders > 0 AND s.person_n > 0 AND s.first_person_at IS NOT NULL AND s.first_person_at <= s.first_order_at AND NOT EXISTS (SELECT 1 FROM wq_win w WHERE w.query_id = s.query_id) ORDER BY s.query_id DESC LIMIT " . (int)$limit);
        $st->execute([wq_dt(wq_now() - 60 * 86400)]);
        foreach ($st->fetchAll() as $r) {
            if (microtime(true) > $deadline) break;
            [$body] = wq_first_msg($pdo, (string)$r['phone'], (string)$r['opened_at']);
            $m = $pdo->prepare("SELECT body, emp_id FROM wa_messages WHERE phone = ? AND dir = 'out' AND who IN ('emp','person') AND emp_id > 0 AND ts >= ? ORDER BY id LIMIT 1"); $m->execute([$r['phone'], $r['first_person_at']]); $o = $m->fetch();
            if (!$o || trim((string)$o['body']) === '') continue;
            $pdo->prepare("INSERT IGNORE INTO wq_win (query_id, phone, emp_id, type, customer_text, reply_text, order_name, amount, order_at, status, at) VALUES (?,?,?,?,?,?,?,?,?, 'new', ?)")
                ->execute([(int)$r['query_id'], $r['phone'], (int)$o['emp_id'], $r['type'], mb_substr($body, 0, 600), mb_substr((string)$o['body'], 0, 900), (string)$r['first_order'], (float)$r['first_order_amt'], $r['first_order_at'], wq_dt()]); $n++;
        }
    } catch (Throwable $e) { error_log('wq_win_scan: ' . $e->getMessage()); }
    return $n;
}
function wq_win_list(PDO $pdo, int $days, string $status, int $page, int $per): array
{
    $names = wq_names($pdo); $per = max(1, min(100, $per)); $page = max(1, $page); $since = wq_dt(wq_now() - max(1, $days) * 86400);
    $cn = ['new' => 0, 'taught' => 0, 'ignored' => 0]; $c = $pdo->prepare("SELECT status, COUNT(*) n FROM wq_win WHERE at >= ? OR order_at >= ? GROUP BY status"); $c->execute([$since, $since]); foreach ($c->fetchAll() as $r) if (isset($cn[$r['status']])) $cn[$r['status']] = (int)$r['n'];
    $w = "(w.at >= ? OR w.order_at >= ?)"; $a = [$since, $since]; if (isset($cn[$status])) { $w .= ' AND w.status = ?'; $a[] = $status; }
    $st = $pdo->prepare("SELECT w.*, c.name cname FROM wq_win w LEFT JOIN wa_contacts c ON c.phone = w.phone WHERE $w ORDER BY w.id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per)); $st->execute($a); $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['id' => (int)$r['id'], 'query_id' => (int)$r['query_id'], 'phone' => (string)$r['phone'], 'name' => (string)$r['cname'], 'type' => (string)$r['type'], 'type_label' => wq_type_label((string)$r['type']), 'emp' => wq_person($names, (int)$r['emp_id']),
        'customer_text' => (string)$r['customer_text'], 'reply_text' => (string)$r['reply_text'], 'order' => ['name' => (string)$r['order_name'], 'amount' => round((float)$r['amount']), 'at' => $r['order_at']], 'status' => (string)$r['status'], 'at' => (string)$r['at']];
    return ['ok' => true, 'counts' => $cn, 'items' => $items];
}
/** teach = a DRAFT row in Learning > From WhatsApp (wa_learn, status 'new'); never the live bot. */
function wq_win_act(PDO $pdo, int $id, string $act): array
{
    $q = $pdo->prepare("SELECT * FROM wq_win WHERE id = ?"); $q->execute([$id]); $w = $q->fetch(); if (!$w) return [false, 'Not found.', 404];
    if ($act === 'ignore') { $pdo->prepare("UPDATE wq_win SET status = 'ignored' WHERE id = ?")->execute([$id]); return [true, ['ok' => true, 'status' => 'ignored']]; }
    if ($act !== 'teach') return [false, 'Choose Teach or Ignore.', 422];
    if ((int)$w['learn_id'] > 0) return [true, ['ok' => true, 'status' => 'taught', 'learn_id' => (int)$w['learn_id'], 'note' => 'Already added to Learning > From WhatsApp.']];
    if (function_exists('wa_learn_tables')) { try { wa_learn_tables($pdo); } catch (Throwable $e) { } }
    try {
        $pdo->prepare("INSERT INTO wa_learn (phone, in_id, customer, sugg, sent, kind, emp_id, ver, status, created_at) VALUES (?, 0, ?, '', ?, 'win', ?, 0, 'new', ?)")->execute([$w['phone'], (string)$w['customer_text'], (string)$w['reply_text'], (int)$w['emp_id'], wq_dt()]);
        $lid = (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return [false, 'The Learning storage is not available.', 500]; }
    $pdo->prepare("UPDATE wq_win SET status = 'taught', learn_id = ? WHERE id = ?")->execute([$lid, $id]);
    return [true, ['ok' => true, 'status' => 'taught', 'learn_id' => $lid, 'note' => 'Added to Learning > From WhatsApp as a draft. Nothing is live until you approve it.']];
}

// ------------------------------------------------------------------------------------------------------------------ daily summary (decision 9)
function wq_summary_build(PDO $pdo, string $day): array
{
    $cfg = wq_cfg($pdo); $names = wq_names($pdo); $f = wq_day_start($pdo, $day); $t = wq_day_start($pdo, wq_day_add($day, 1)); $S = wq_people_stats($pdo, $f, $t); $T = $S['total']; $team = wq_team_medians($S['rows'], (int)$cfg['wq_min_n']);
    $fast = null; $behind = [];
    foreach ($S['rows'] as $e => $x) {
        $m = wq_median($x['fpm']); if ($m !== null && count($x['fpm']) >= 3 && ($fast === null || $m < $fast['first_reply_min'])) $fast = ['emp_id' => $e, 'name' => wq_name($names, $e), 'first_reply_min' => round($m, 1)];
        $b = wq_behind($x, $team, (int)$cfg['wq_min_n']); if ($b) $behind[] = ['emp_id' => $e, 'name' => wq_name($names, $e), 'reasons' => $b['reasons']];
    }
    $pend = wq_pending_n($pdo); $act = [];
    if ($T['unanswered'] > 0) $act[] = 'Reply to the ' . $T['unanswered'] . ' unanswered chat' . ($T['unanswered'] === 1 ? '' : 's') . ' first thing.';
    foreach (array_slice($behind, 0, 2) as $b) $act[] = 'Talk to ' . ($b['name'] ?: 'the person behind') . ': ' . $b['reasons'][0] . '.';
    if ($pend > 0) $act[] = 'Answer the ' . $pend . ' question' . ($pend === 1 ? '' : 's') . ' on the Query types page.';
    foreach (['Check the Attention needed strip before you start.', 'Look at Bot vs person on the Compare tab.', 'Teach the bot one winning reply from What won.'] as $d) if (count($act) < 3) $act[] = $d;
    $act = array_slice($act, 0, 3);
    $parts = []; foreach (wq_types() as $k => $l) if ($T['by_type'][$k] > 0) $parts[] = $l . ' ' . $T['by_type'][$k];
    $text = $T['queries'] === 0 ? 'No queries on ' . $day . '.' : 'On ' . $day . ' there were ' . $T['queries'] . ' queries (' . implode(', ', $parts) . '), ' . $T['unanswered'] . ' unanswered' . ($fast ? '; fastest first reply: ' . $fast['name'] . ' (' . $fast['first_reply_min'] . ' min)' : '') . ($behind ? '; behind: ' . implode(', ', array_column($behind, 'name')) : '') . '.';
    [$ok, $v] = wq_ai($pdo, "Rewrite this daily sales summary for a shop owner in 2 short plain sentences. Keep every number exactly as given. Do not add facts.", $text, ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING']], 'required' => ['text']]);
    if ($ok && is_array($v) && trim((string)($v['text'] ?? '')) !== '') $text = mb_substr(trim((string)$v['text']), 0, 500);
    return ['ok' => true, 'day' => $day, 'built' => true, 'built_at' => wq_dt(), 'total' => $T['queries'], 'by_type' => $T['by_type'], 'unanswered' => $T['unanswered'], 'fastest' => $fast, 'behind' => $behind, 'actions' => $act, 'text' => $text];
}
function wq_summary_get(PDO $pdo, string $day, bool $rebuild = false): array
{
    if (!$rebuild) { $q = $pdo->prepare("SELECT js FROM wq_daily WHERE day = ?"); $q->execute([$day]); $js = $q->fetchColumn(); if ($js) { $d = json_decode((string)$js, true); if (is_array($d)) return $d; } return ['ok' => true, 'day' => $day, 'built' => false, 'built_at' => null, 'total' => 0, 'by_type' => wq_blank_types(), 'unanswered' => 0, 'fastest' => null, 'behind' => [], 'actions' => [], 'text' => '']; }
    $d = wq_summary_build($pdo, $day); $pdo->prepare("INSERT INTO wq_daily (day, js, built_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE js = VALUES(js), built_at = VALUES(built_at)")->execute([$day, json_encode($d, JSON_UNESCAPED_UNICODE), wq_dt()]);
    wq_publish_finding($pdo, $d); return $d;
}
/** The summary as a Daily Summary finding (ck_findings, kind wa_day) so it shows with the other findings and in the Morning section. */
function wq_publish_finding(PDO $pdo, array $d): void
{
    try {
        $ref = 'wq:day:' . $d['day']; $now = wq_dt(); $title = 'WhatsApp yesterday: ' . $d['total'] . ' queries, ' . $d['unanswered'] . ' unanswered'; $detail = mb_substr((string)$d['text'], 0, 400); $sg = mb_substr(implode(' ', (array)$d['actions']), 0, 300);
        $pdo->prepare("INSERT INTO ck_findings (kind, ref_key, emp_id, section_id, severity, title, detail, suggestion, status, hits, first_at, last_at, last_day) VALUES ('wa_day', ?, NULL, NULL, ?, ?, ?, ?, 'open', 1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), detail = VALUES(detail), suggestion = VALUES(suggestion), last_at = VALUES(last_at)")->execute([$ref, $d['unanswered'] > 0 ? 'warn' : 'info', $title, $detail, $sg, $now, $now, substr($now, 0, 10)]);
        $pdo->prepare("UPDATE ck_findings SET status = 'fixed', fixed_at = ? WHERE kind = 'wa_day' AND status = 'open' AND ref_key <> ?")->execute([$now, $ref]);
    } catch (Throwable $e) { /* ck_findings exists only after the Check module ran once */ }
}

// ------------------------------------------------------------------------------------------------------------------ analytics
function wq_day_offset_min(PDO $pdo): int
{
    $c = []; for ($i = 0; $i < 7; $i++) { $d = wq_day_add(date('Y-m-d', wq_now()), $i); $s = function_exists('day_start_for') ? day_start_for($pdo, $d) : '10:00'; $c[$s] = ($c[$s] ?? 0) + 1; }
    arsort($c); $s = (string)array_key_first($c); return ((int)substr($s, 0, 2)) * 60 + (int)substr($s, 3, 2);
}
function wq_analytics(PDO $pdo, int $days, bool $fresh = false): array
{
    $days = in_array($days, [7, 30, 90], true) ? $days : 30; $key = 'an:' . $days; $now = wq_now();
    if (!$fresh) { $c = json_decode(wq_meta_get($pdo, $key, ''), true); if (is_array($c) && isset($c['_at']) && $now - (int)$c['_at'] < 300) { unset($c['_at']); $c['cached'] = true; return $c; } }
    $cfg = wq_cfg($pdo); $names = wq_names($pdo); [$f, $t, $fd, $td, $list] = wq_period($pdo, $days); $off = wq_day_offset_min($pdo); $fq = wq_dt($f); $tq = wq_dt($t); $target = (int)$cfg['wq_goal_reply_min'];
    $dx = "DATE_FORMAT(DATE_SUB(q.opened_at, INTERVAL $off MINUTE), '%Y-%m-%d')";
    // (1) + (9)
    $perDay = []; foreach ($list as $d) $perDay[$d] = ['day' => $d, 'total' => 0, 'by_type' => wq_blank_types(), 'unanswered_end' => 0, 'escalated' => 0];
    $st = $pdo->prepare("SELECT $dx d, COALESCE(t.type,'unclear') ty, COUNT(*) n FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ? GROUP BY d, ty"); $st->execute([$fq, $tq]);
    foreach ($st->fetchAll() as $r) if (isset($perDay[$r['d']])) { $k = isset($perDay[$r['d']]['by_type'][$r['ty']]) ? $r['ty'] : 'unclear'; $perDay[$r['d']]['by_type'][$k] += (int)$r['n']; $perDay[$r['d']]['total'] += (int)$r['n']; }
    $st = $pdo->prepare("SELECT $dx d, COUNT(*) n FROM wa_queries q LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ? AND (s.first_out_at IS NULL OR s.first_out_at >= TIMESTAMPADD(MINUTE, " . (1440 + $off) . ", STR_TO_DATE($dx, '%Y-%m-%d'))) GROUP BY d"); $st->execute([$fq, $tq]);
    foreach ($st->fetchAll() as $r) if (isset($perDay[$r['d']])) $perDay[$r['d']]['unanswered_end'] = (int)$r['n'];
    $st = $pdo->prepare("SELECT DATE_FORMAT(DATE_SUB(esc_at, INTERVAL $off MINUTE), '%Y-%m-%d') d, COUNT(*) n FROM wq_attention WHERE esc_at >= ? AND esc_at < ? GROUP BY d"); $st->execute([$fq, $tq]);
    foreach ($st->fetchAll() as $r) if (isset($perDay[$r['d']])) $perDay[$r['d']]['escalated'] = (int)$r['n'];
    // (2)
    $hours = array_fill(0, 24, 0); $st = $pdo->prepare("SELECT HOUR(opened_at) h, COUNT(*) n FROM wa_queries WHERE opened_at >= ? AND opened_at < ? GROUP BY h"); $st->execute([$fq, $tq]); foreach ($st->fetchAll() as $r) $hours[(int)$r['h']] = (int)$r['n'];
    $wd = array_fill(1, 7, 0); $st = $pdo->prepare("SELECT WEEKDAY(opened_at) + 1 w, COUNT(*) n FROM wa_queries WHERE opened_at >= ? AND opened_at < ? GROUP BY w"); $st->execute([$fq, $tq]); foreach ($st->fetchAll() as $r) $wd[(int)$r['w']] = (int)$r['n'];
    $wl = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    // (3) ads
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(c.ad_id,''), NULLIF(LEFT(c.ref_text,60),''), 'unknown') k, MAX(c.ad_head) h, MAX(c.ref_text) rt, COUNT(*) n, SUM(COALESCE(s.answered,0)) rep, SUM(COALESCE(s.orders,0) > 0) conv, SUM(COALESCE(s.orders,0)) ord, SUM(COALESCE(s.revenue,0)) rev
        FROM wa_queries q JOIN wq_qtype t ON t.query_id = q.id AND t.type = 'meta' LEFT JOIN wa_contacts c ON c.phone = q.phone LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ? GROUP BY k ORDER BY n DESC LIMIT 10"); $st->execute([$fq, $tq]); $ads = [];
    foreach ($st->fetchAll() as $r) $ads[] = ['key' => (string)$r['k'], 'title' => mb_substr((string)($r['h'] !== '' && $r['h'] !== null ? $r['h'] : $r['rt']), 0, 90), 'queries' => (int)$r['n'], 'replied' => (int)$r['rep'], 'orders' => (int)$r['ord'], 'revenue' => round((float)$r['rev']), 'conversion' => wq_pct((int)$r['conv'], (int)$r['n'])];
    // (4)
    $st = $pdo->prepare("SELECT t.camp_id, COALESCE(MAX(k.name), 'Campaign') nm, COUNT(*) n, SUM(COALESCE(s.orders,0) > 0) conv, SUM(COALESCE(s.orders,0)) ord, SUM(COALESCE(s.revenue,0)) rev FROM wq_qtype t JOIN wa_queries q ON q.id = t.query_id LEFT JOIN wa_campaigns k ON k.id = t.camp_id LEFT JOIN wq_qstat s ON s.query_id = t.query_id
        WHERE t.type = 'campaign' AND t.camp_id > 0 AND q.opened_at >= ? AND q.opened_at < ? GROUP BY t.camp_id ORDER BY n DESC LIMIT 10"); $st->execute([$fq, $tq]); $camps = [];
    foreach ($st->fetchAll() as $r) $camps[] = ['camp_id' => (int)$r['camp_id'], 'name' => (string)$r['nm'], 'queries' => (int)$r['n'], 'orders' => (int)$r['ord'], 'revenue' => round((float)$r['rev']), 'conversion' => wq_pct((int)$r['conv'], (int)$r['n'])];
    // (5)
    $cpd = []; foreach ($list as $d) $cpd[$d] = 0; $cq = 0; foreach ($perDay as $d => $x) { $cpd[$d] = $x['by_type']['community']; $cq += $x['by_type']['community']; }
    $tagN = 0; try { $tagN = (int)$pdo->query("SELECT COUNT(DISTINCT phone) FROM wa_ctags WHERE tag IN ('community','community_query')")->fetchColumn(); } catch (Throwable $e) { }
    // (6)
    $bk = "CASE WHEN {c} < 1 THEN 0 WHEN {c} < 5 THEN 1 WHEN {c} < 15 THEN 2 WHEN {c} < 60 THEN 3 ELSE 4 END"; $mk = fn($n) => ['n' => $n, 'counts' => [0, 0, 0, 0, 0], 'within' => 0];
    $team = $mk(0); $ppl = []; $cp = str_replace('{c}', 's.first_person_min', $bk);
    $st = $pdo->prepare("SELECT q.emp_id e, $cp b, COUNT(*) n, SUM(s.first_person_min <= ?) w FROM wq_qstat s JOIN wa_queries q ON q.id = s.query_id WHERE s.opened_at >= ? AND s.opened_at < ? AND s.first_person_min IS NOT NULL GROUP BY e, b"); $st->execute([$target, $fq, $tq]);
    foreach ($st->fetchAll() as $r) { $e = (int)$r['e']; if (!isset($ppl[$e])) $ppl[$e] = $mk(0); foreach ([&$ppl[$e], &$team] as &$x) { $x['n'] += (int)$r['n']; $x['counts'][(int)$r['b']] += (int)$r['n']; $x['within'] += (int)$r['w']; } unset($x); }
    $cb = str_replace('{c}', '(TIMESTAMPDIFF(SECOND, s.first_in_at, s.first_out_at) / 60)', $bk); $bot = $mk(0);
    $st = $pdo->prepare("SELECT $cb b, COUNT(*) n, SUM(TIMESTAMPDIFF(SECOND, s.first_in_at, s.first_out_at) / 60 <= ?) w FROM wq_qstat s WHERE s.opened_at >= ? AND s.opened_at < ? AND s.first_by = 'bot' AND s.first_in_at IS NOT NULL AND s.first_out_at IS NOT NULL GROUP BY b"); $st->execute([$target, $fq, $tq]);
    foreach ($st->fetchAll() as $r) { $bot['n'] += (int)$r['n']; $bot['counts'][(int)$r['b']] += (int)$r['n']; $bot['within'] += (int)$r['w']; }
    $fin = fn($x) => ['n' => $x['n'], 'counts' => $x['counts'], 'within_target_pct' => wq_pct($x['within'], $x['n'])]; $people = [];
    foreach ($ppl as $e => $x) if ($e > 0) $people[] = ['emp_id' => $e, 'name' => wq_name($names, $e)] + $fin($x); usort($people, fn($a, $b) => $b['n'] <=> $a['n']);
    // (7)
    $st = $pdo->prepare("SELECT q.emp_id e, $dx d, COUNT(*) n, SUM(COALESCE(s.orders,0)) o, AVG(s.first_person_min) a FROM wa_queries q LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.opened_at < ? AND q.emp_id > 0 GROUP BY e, d"); $st->execute([$fq, $tq]); $tr = [];
    foreach ($st->fetchAll() as $r) $tr[(int)$r['e']][(string)$r['d']] = ['day' => (string)$r['d'], 'queries' => (int)$r['n'], 'orders' => (int)$r['o'], 'first_reply_min' => $r['a'] === null ? null : round((float)$r['a'], 1)];
    $trend = []; foreach ($tr as $e => $dd) { ksort($dd); $trend[] = ['emp_id' => $e, 'name' => wq_name($names, $e), 'days' => array_values($dd)]; } usort($trend, fn($a, $b) => strcmp($a['name'], $b['name']));
    // (8)
    $loss = ['reasons' => ['price', 'no_interest', 'no_reply', 'other'], 'total' => 0, 'by_type' => []];
    try {
        $st = $pdo->prepare("SELECT COALESCE(t.type,'unclear') ty, o.code, COUNT(*) n FROM wq_qtype t JOIN wa_queries q ON q.id = t.query_id JOIN (SELECT phone, MAX(id) mid FROM wa_outcomes WHERE at >= ? GROUP BY phone) x ON x.phone = t.phone JOIN wa_outcomes o ON o.id = x.mid AND o.at >= q.opened_at
            WHERE q.opened_at >= ? AND q.opened_at < ? AND o.code IN ('price','no_interest','no_reply','wrong','complaint','info') GROUP BY ty, o.code"); $st->execute([$fq, $fq, $tq]);
        foreach ($st->fetchAll() as $r) { $k = in_array($r['code'], ['price', 'no_interest', 'no_reply'], true) ? $r['code'] : 'other'; if (!isset($loss['by_type'][$r['ty']])) $loss['by_type'][$r['ty']] = ['price' => 0, 'no_interest' => 0, 'no_reply' => 0, 'other' => 0]; $loss['by_type'][$r['ty']][$k] += (int)$r['n']; $loss['total'] += (int)$r['n']; }
    } catch (Throwable $e) { }
    $out = ['ok' => true, 'on' => (bool)$cfg['wq_on'], 'days' => $days, 'from' => $fq, 'to' => wq_dt($now), 'cached_at' => wq_dt($now), 'cached' => false, 'target_min' => $target, 'per_day' => array_values($perDay),
        'by_hour' => array_map(fn($h) => ['hour' => $h, 'n' => $hours[$h]], array_keys($hours)), 'by_weekday' => array_map(fn($w) => ['wd' => $w, 'label' => $wl[$w], 'n' => $wd[$w]], array_keys($wd)),
        'ads' => $ads, 'campaigns' => $camps, 'community' => ['queries' => $cq, 'customers_with_tag' => $tagN, 'per_day' => array_map(fn($d) => ['day' => $d, 'n' => $cpd[$d]], array_keys($cpd))],
        'reply' => ['buckets' => ['<1m', '1-5m', '5-15m', '15-60m', '>60m'], 'team' => $fin($team), 'bot' => $fin($bot), 'people' => $people], 'trend' => $trend, 'loss' => $loss];
    wq_meta_put($pdo, $key, json_encode($out + ['_at' => $now], JSON_UNESCAPED_UNICODE));
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ cut-over adapter (decision 11)
function wq_cutover_date(PDO $pdo): string { $d = trim((string)wq_get($pdo, 'wq_cutover', '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : ''; }
/** Is this business day (default: today's) read from WhatsApp Sales? False when there is no date. */
function wq_cutover_active(PDO $pdo, string $day = ''): bool { $c = wq_cutover_date($pdo); if ($c === '') return false; if ($day === '') $day = wq_day_of($pdo, wq_now()); return $day >= $c; }
/** Today's business day is on/after the cut-over date (the old typed counts have ended). */
function wq_cutover_passed(PDO $pdo): bool { return wq_cutover_active($pdo, ''); }
function wq_days_between(string $from, string $to): array { $o = []; for ($d = $from; $d <= $to && count($o) < 400; $d = wq_day_add($d, 1)) $o[] = $d; return $o; }
/** OLD typed counts: [day => [emp => n]] from employee_queries (n = SUM(GREATEST(0, query_count - champion_count))). The table may not exist: then nothing. */
function wq_old_days(PDO $pdo, string $from, string $to): array
{
    $o = []; if ($from > $to) return $o;
    try { $q = $pdo->prepare("SELECT query_date d, employee_id e, SUM(GREATEST(0, query_count - champion_count)) n FROM employee_queries WHERE query_date BETWEEN ? AND ? GROUP BY query_date, employee_id"); $q->execute([$from, $to]); foreach ($q->fetchAll() as $r) if ((int)$r['n'] > 0) $o[substr((string)$r['d'], 0, 10)][(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }
    return $o;
}
/** NEW counts of one business day: [emp => n], holder-based (queries opened inside that business day, counted for who holds them now). */
function wq_new_day(PDO $pdo, string $day): array
{
    $o = []; try { $q = $pdo->prepare("SELECT emp_id e, COUNT(*) n FROM wa_queries WHERE opened_at >= ? AND opened_at < ? AND emp_id > 0 GROUP BY emp_id"); $q->execute([wq_dt(wq_day_start($pdo, $day)), wq_dt(wq_day_start($pdo, wq_day_add($day, 1)))]); foreach ($q->fetchAll() as $r) $o[(int)$r['e']] = (int)$r['n']; } catch (Throwable $e) { }
    return $o;
}
/** ['YYYY-MM-DD' => [emp => n]] — each day from ONE side: old typed counts before the cut-over date (or when there is none), WhatsApp Sales from it. */
function wq_qcount_days(PDO $pdo, string $from, string $to): array
{
    $cut = wq_cutover_date($pdo); $out = [];
    $oldTo = ($cut !== '' && $cut <= $to) ? wq_day_add($cut, -1) : $to; foreach (wq_old_days($pdo, $from, $oldTo) as $d => $m) $out[$d] = $m;
    if ($cut !== '' && max($from, $cut) <= $to) foreach (wq_days_between(max($from, $cut), $to) as $d) { $m = wq_new_day($pdo, $d); if ($m) $out[$d] = $m; }
    ksort($out); return $out;
}
function wq_qcount_range(PDO $pdo, string $from, string $to): array { $o = []; foreach (wq_qcount_days($pdo, $from, $to) as $m) foreach ($m as $e => $n) $o[$e] = ($o[$e] ?? 0) + $n; return $o; }
function wq_qcount_all_day(PDO $pdo, string $day): array { return wq_qcount_days($pdo, $day, $day)[$day] ?? []; }
function wq_qcount(PDO $pdo, int $emp, string $from, string $to): int { $n = 0; foreach (wq_qcount_days($pdo, $from, $to) as $m) $n += (int)($m[$emp] ?? 0); return $n; }
function wq_qcount_day(PDO $pdo, int $emp, string $day): int { return wq_qcount($pdo, $emp, $day, $day); }
/** NEW side only, one person, day range (no old counts, no cut-over test). */
function wq_qcount_new(PDO $pdo, int $emp, string $from, string $to): int
{
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_queries WHERE emp_id = ? AND opened_at >= ? AND opened_at < ?"); $q->execute([$emp, wq_dt(wq_day_start($pdo, $from)), wq_dt(wq_day_start($pdo, wq_day_add($to, 1)))]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; }
}
/** ['fresh','in','new','comm'] of one person-day like employee_queries + campaign_queries (see the API file for the mapping). */
function wq_qcount_kinds(PDO $pdo, int $emp, string $day): array
{
    $o = ['fresh' => 0, 'in' => 0, 'new' => 0, 'comm' => 0];
    if (!wq_cutover_active($pdo, $day)) {
        $o['fresh'] = (int)(wq_old_days($pdo, $day, $day)[$day][$emp] ?? 0);
        try { $q = $pdo->prepare("SELECT kind, n FROM campaign_queries WHERE employee_id = ? AND cq_date = ?"); $q->execute([$emp, $day]); foreach ($q->fetchAll() as $r) if (isset($o[$r['kind']]) && $r['kind'] !== 'fresh') $o[$r['kind']] = (int)$r['n']; } catch (Throwable $e) { }
        return $o;
    }
    try {
        $q = $pdo->prepare("SELECT COALESCE(t.type,'unclear') ty, q.again ag, COUNT(*) n FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id WHERE q.emp_id = ? AND q.opened_at >= ? AND q.opened_at < ? GROUP BY ty, ag");
        $q->execute([$emp, wq_dt(wq_day_start($pdo, $day)), wq_dt(wq_day_start($pdo, wq_day_add($day, 1)))]);
        foreach ($q->fetchAll() as $r) { $k = $r['ty'] === 'campaign' ? ((int)$r['ag'] === 1 ? 'in' : 'new') : ($r['ty'] === 'community' ? 'comm' : 'fresh'); $o[$k] += (int)$r['n']; }
    } catch (Throwable $e) { }
    return $o;
}
/** [hour 0-23 => n] of one person-day, WhatsApp Sales only (empty before the cut-over). */
function wq_qcount_hour(PDO $pdo, int $emp, string $day): array
{
    if (!wq_cutover_active($pdo, $day)) return []; $o = [];
    try { $q = $pdo->prepare("SELECT HOUR(opened_at) h, COUNT(*) n FROM wa_queries WHERE emp_id = ? AND opened_at >= ? AND opened_at < ? GROUP BY h"); $q->execute([$emp, wq_dt(wq_day_start($pdo, $day)), wq_dt(wq_day_start($pdo, wq_day_add($day, 1)))]); foreach ($q->fetchAll() as $r) $o[(int)$r['h']] = (int)$r['n']; } catch (Throwable $e) { }
    return $o;
}
/** [emp => ['in'=>n,'out'=>n]] transfers of queries opened in the day range (WhatsApp Sales days only). */
function wq_qmoves_range(PDO $pdo, string $from, string $to): array
{
    $cut = wq_cutover_date($pdo); if ($cut === '' || $cut > $to) return []; $from = max($from, $cut); $S = wq_people_stats($pdo, wq_day_start($pdo, $from), wq_day_start($pdo, wq_day_add($to, 1))); $o = [];
    foreach ($S['rows'] as $e => $x) if ($x['transferred_in'] || $x['transferred_out']) $o[$e] = ['in' => $x['transferred_in'], 'out' => $x['transferred_out']];
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ boss engine (decision 13): proposals + flags
function wq_boss_seed(PDO $pdo): void
{
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO wq_boss (code, title, body, x, state) VALUES (?,?,?,?, 'proposed')");
        $ins->execute(['wq_unans', 'WhatsApp query unanswered for more than 10 minutes', 'A customer query that nobody answered for more than X minutes while the person was on duty.', 10]);
        $ins->execute(['wq_slow', 'WhatsApp first reply slower than 15 minutes', 'The median time to the first reply of a person is slower than Y minutes over the last 7 days.', 15]);
    } catch (Throwable $e) { }
}
/** Readable by the boss engine: what is wrong with this person's WhatsApp queries right now (never penalises by itself). */
function wq_staff_flags(PDO $pdo, int $emp): array
{
    $o = ['unanswered' => 0, 'slow_reply' => false, 'behind' => false, 'reasons' => [], 'approved_rules' => []];
    try {
        if (!wq_tables($pdo) || !wq_on($pdo) || $emp <= 0) return $o; $cfg = wq_cfg($pdo);
        $thr = []; foreach ($pdo->query("SELECT code, x, title FROM wq_boss WHERE state = 'approved'")->fetchAll() as $r) { $thr[$r['code']] = (int)$r['x']; $o['approved_rules'][] = ['code' => $r['code'], 'minutes' => (int)$r['x'], 'title' => $r['title']]; }
        $x = $thr['wq_unans'] ?? 10; $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE assigned_emp = ? AND status = 'open' AND optout = 0 AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at)");
        $q->execute([$emp, wq_dt(wq_now() - 86400), wq_dt(wq_now() - $x * 60)]); $o['unanswered'] = (int)$q->fetchColumn();
        if ($o['unanswered'] > 0) $o['reasons'][] = $o['unanswered'] . ' chat(s) unanswered for more than ' . $x . ' min';
        [$f, $t] = wq_period($pdo, 7); $S = wq_people_stats($pdo, $f, $t); $rx = $S['rows'][$emp] ?? null; $team = wq_team_medians($S['rows'], (int)$cfg['wq_min_n']);
        if ($rx) { $m = wq_median($rx['fpm']); $y = $thr['wq_slow'] ?? 15; if ($m !== null && count($rx['fpm']) >= 5 && $m > $y) { $o['slow_reply'] = true; $o['reasons'][] = 'First reply takes ' . round($m, 1) . ' min (limit ' . $y . ')'; } $b = wq_behind($rx, $team, (int)$cfg['wq_min_n']); if ($b) { $o['behind'] = true; $o['reasons'] = array_merge($o['reasons'], $b['reasons']); } }
    } catch (Throwable $e) { }
    return $o;
}
// ---- mirror into the internal-Claude question engine (claude_q.php cq_q): kind 'wqtype' (query to type) and 'wqrule' (proposed penalty rule)
function wq_cq_mirror(PDO $pdo): array
{
    $o = ['type' => 0, 'rules' => 0];
    if (!function_exists('cq_ask') && is_file(__DIR__ . '/claude_q.php')) { try { require_once __DIR__ . '/claude_q.php'; } catch (Throwable $e) { } }   // only functions; the cron request does not load the Boss module
    if (!function_exists('cq_ask') || !function_exists('cq_ensure')) return $o;
    try {
        cq_ensure($pdo); $live = []; $now = wq_dt();
        $q = $pdo->query("SELECT q.* FROM wq_qtype t JOIN wa_queries q ON q.id = t.query_id WHERE t.st = 'ask' ORDER BY t.opened_at DESC LIMIT 1")->fetch();
        $keep = '';
        if ($q) {
            $later = $pdo->query("SELECT query_id FROM wq_qtype WHERE st = 'later' LIMIT 500")->fetchAll(PDO::FETCH_COLUMN); $nq = wq_next_question($pdo, $later, false);
            if ($nq) {
                $keep = 'wq:type:' . $nq['query_id']; $opts = []; foreach ($nq['chips'] as $c) $opts[] = ['id' => $c['type'], 'label' => $c['label']]; $opts[] = ['id' => 'later', 'label' => 'Ask me later'];
                cq_ask($pdo, $live, $keep, ['kind' => 'wqtype', 'subject' => 'WhatsApp Sales', 'series' => 'wq_types', 'title' => 'How should I count this query? (+91 ' . $nq['phone'] . ')', 'body' => implode("\n", $nq['context']) . "\n" . wq_pending_n($pdo) . ' queries are waiting for a type. More options (always do this) are on the Query types page.', 'input' => 'choice', 'options' => $opts, 'payload' => ['query_id' => $nq['query_id']]]); $o['type'] = 1;
            }
        }
        $pdo->prepare("UPDATE cq_q SET status = 'gone', answered_at = ? WHERE sig LIKE 'wq:type:%' AND status = 'open' AND sig <> ?")->execute([$now, $keep]);
        foreach ($pdo->query("SELECT * FROM wq_boss WHERE state = 'proposed' OR (state = 'later' AND asked_at <= '" . wq_dt(wq_now() - 7 * 86400) . "')")->fetchAll() as $b) {
            cq_ask($pdo, $live, 'wq:rule:' . $b['code'], ['kind' => 'wqrule', 'subject' => 'WhatsApp Sales', 'series' => 'wq_rules', 'title' => 'Add a rule: ' . $b['title'] . '?', 'body' => $b['body'] . ' If you say yes it is added to Penalties as an inactive rule with amount 0: nobody is penalised until you set an amount and switch it on.',
                'input' => 'choice', 'options' => [['id' => 'yes', 'label' => 'Yes, add it (inactive)'], ['id' => 'no', 'label' => 'No, never'], ['id' => 'later', 'label' => 'Ask me later']], 'payload' => ['code' => $b['code']]], true);
            $pdo->prepare("UPDATE wq_boss SET state = 'asked', asked_at = ? WHERE code = ?")->execute([$now, $b['code']]); $o['rules']++;
        }
    } catch (Throwable $e) { error_log('wq_cq_mirror: ' . $e->getMessage()); }
    return $o;
}
/** Called by claude_q.php cq_apply for signatures starting 'wq:'. Returns [message, answer]. */
function wq_cq_apply(PDO $pdo, array $q, string $choice, string $text, $by): array
{
    $sig = (string)$q['sig']; $pl = json_decode((string)$q['payload'], true) ?: [];
    if (strpos($sig, 'wq:type:') === 0) {
        $qid = (int)($pl['query_id'] ?? 0);
        if ($choice === 'later') { $pdo->prepare("UPDATE wq_qtype SET st = 'later' WHERE query_id = ? AND st = 'ask'")->execute([$qid]); return ['OK, I will ask again later.', 'later']; }
        [$ok, $r] = wq_answer($pdo, $qid, $choice, false, null, 0); if (!$ok) return [(string)$r, 'error'];
        return ['Counted as ' . wq_type_label($choice) . '.', $choice];
    }
    if (strpos($sig, 'wq:rule:') === 0) {
        $code = (string)($pl['code'] ?? ''); $b = $pdo->prepare("SELECT * FROM wq_boss WHERE code = ?"); $b->execute([$code]); $r = $b->fetch(); if (!$r) return ['That proposal is gone.', 'gone'];
        if ($choice === 'later') { $pdo->prepare("UPDATE wq_boss SET state = 'later', asked_at = ? WHERE code = ?")->execute([wq_dt(), $code]); return ['OK, I will ask again in a week.', 'later']; }
        if ($choice === 'no') { $pdo->prepare("UPDATE wq_boss SET state = 'rejected', decided_at = ?, decided_by = ? WHERE code = ?")->execute([wq_dt(), (string)$by, $code]); return ['Noted: I will not propose this again.', 'no']; }
        $pid = 0;
        try {
            $e = $pdo->prepare("SELECT rule_id FROM penalty_rules WHERE name = ? LIMIT 1"); $e->execute([$r['title']]); $pid = (int)$e->fetchColumn();
            if ($pid <= 0) { $pdo->prepare("INSERT INTO penalty_rules (name, category, department, trigger_type, amount, grace_min, on_hours_done, notify_text, active, active_since, created_at, updated_at) VALUES (?, 'sales', '', ?, 0, ?, 'keep', ?, 0, NULL, ?, ?)")->execute([mb_substr($r['title'], 0, 120), $code, (int)$r['x'], mb_substr($r['body'], 0, 250), wq_dt(), wq_dt()]); $pid = (int)$pdo->lastInsertId(); }
        } catch (Throwable $e) { }
        $pdo->prepare("UPDATE wq_boss SET state = 'approved', decided_at = ?, decided_by = ?, pen_rule_id = ? WHERE code = ?")->execute([wq_dt(), (string)$by, $pid, $code]);
        return ['Added to Penalties as an inactive rule (amount 0). Nobody is penalised until you set an amount and switch it on.', 'yes'];
    }
    return ['Done.', $choice];
}

// ------------------------------------------------------------------------------------------------------------------ health + the tick
function wq_status(PDO $pdo): array
{
    $cfg = wq_cfg($pdo); $since = wq_dt(wq_now() - 90 * 86400);
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM wa_queries WHERE opened_at >= '$since'")->fetchColumn(); $typed = (int)$pdo->query("SELECT COUNT(*) FROM wq_qtype WHERE opened_at >= '$since'")->fetchColumn();
    $last = json_decode(wq_meta_get($pdo, 'last_tick', ''), true) ?: []; $busy = false; try { $busy = (int)$pdo->query("SELECT IS_FREE_LOCK('wq_tick')")->fetchColumn() === 0; } catch (Throwable $e) { }
    $cnt = []; foreach (['wq_qtype', 'wq_qstat', 'wq_guard', 'wq_attention', 'wq_win'] as $t) { try { $cnt[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Throwable $e) { $cnt[$t] = 0; } }
    return ['ok' => true, 'on' => (bool)$cfg['wq_on'], 'auto_on' => (bool)$cfg['wq_auto_on'], 'backfill' => ['done' => $typed >= $tot, 'queries' => $tot, 'typed' => min($typed, $tot), 'remaining' => max(0, $tot - $typed), 'cursor' => $typed],
        'tick' => ['last_at' => $last['at'] ?? null, 'last_ms' => $last['ms'] ?? null, 'last_result' => $last['result'] ?? null, 'busy' => $busy], 'pending_questions' => wq_pending_n($pdo),
        'attention_open' => (int)$pdo->query("SELECT COUNT(*) FROM wq_attention WHERE state = 'escalated'")->fetchColumn(), 'rules' => (int)$pdo->query("SELECT COUNT(*) FROM wq_rules WHERE active = 1")->fetchColumn(), 'cutover' => $cfg['wq_cutover'], 'tables' => $cnt];
}
/**
 * One tick: idempotent, chunked, time-boxed (~8 s), never throws, GET_LOCK('wq_tick', 0). $opt['light'] = only the latency-critical steps (used by wa_tick, which runs every few seconds).
 */
function wq_tick(PDO $pdo, array $opt = []): array
{
    $t0 = microtime(true); $r = [];
    try {
        if (!wq_on($pdo)) return ['off' => true];
        if (!wq_tables($pdo)) return ['error' => 'storage'];
        $deadline = $t0 + (float)($opt['budget'] ?? 8); $got = (int)$pdo->query("SELECT GET_LOCK('wq_tick', 0)")->fetchColumn(); if ($got !== 1) return ['skipped' => 'busy'];
        try {
            $GLOBALS['__wq_memo'] = []; wq_deps($pdo);
            $r['noans'] = wq_noans_scan($pdo, $deadline);
            $bf = wq_backfill_chunk($pdo, $deadline, !empty($opt['light']) ? 20 : 100); $r['typed'] = $bf['typed']; $r['untyped_left'] = $bf['left'];
            if (empty($opt['light'])) {
                if (microtime(true) < $deadline) $r['orders'] = wq_ord_sync($pdo, $deadline);
                if (microtime(true) < $deadline) $r['stats'] = wq_stat_chunk($pdo, $deadline);
                if (microtime(true) < $deadline) $r['wins'] = wq_win_scan($pdo, $deadline);
                if (microtime(true) < $deadline) {
                    $y = wq_day_add(wq_day_of($pdo, wq_now()), -1);
                    if (wq_now() >= wq_day_start($pdo, wq_day_of($pdo, wq_now())) + 300) { $q = $pdo->prepare("SELECT 1 FROM wq_daily WHERE day = ?"); $q->execute([$y]); if (!$q->fetchColumn()) { wq_summary_get($pdo, $y, true); $r['summary'] = $y; } }
                }
                if (microtime(true) < $deadline) { wq_boss_seed($pdo); $r['cq'] = wq_cq_mirror($pdo); }
                if (microtime(true) < $deadline) $r['campaign_tag'] = wq_window_tag($pdo);
                if (microtime(true) < $deadline && function_exists('wq_pace_tick')) { try { $r['pace'] = wq_pace_tick($pdo); } catch (Throwable $e) { } }   // ⚡ waiting-customer nudges / admin alerts
                if (microtime(true) < $deadline && function_exists('wqm_tick')) { try { $r['mgr'] = wqm_tick($pdo); } catch (Throwable $e) { } }
                if (microtime(true) < $deadline && function_exists('tb_tick')) { try { $r['tb'] = tb_tick($pdo); } catch (Throwable $e) { } }   // 👥 keep the Team table ready (today + this month)   // 🧭 Team manager: Claude looks at the whole team every 10 minutes
                if (microtime(true) < $deadline && function_exists('wq_rescue_tick')) $r['rescue'] = wq_rescue_tick($pdo, $deadline);   // 🛟 effort-missed queries + rescue bonus
                if (wq_ts(wq_meta_get($pdo, 'house_at', '')) < wq_now() - 3600) {
                    $pdo->prepare("DELETE FROM wq_guard WHERE at < ?")->execute([wq_dt(wq_now() - 3 * 86400)]); $pdo->prepare("DELETE FROM wq_meta WHERE k LIKE 'typing:%' AND v < ?")->execute([wq_dt(wq_now() - 3600)]); wq_meta_put($pdo, 'house_at', wq_dt());
                }
            }
        } finally { try { $pdo->query("SELECT RELEASE_LOCK('wq_tick')"); } catch (Throwable $e) { } }
        wq_meta_put($pdo, 'last_tick', json_encode(['at' => wq_dt(), 'ms' => (int)round((microtime(true) - $t0) * 1000), 'result' => $r], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) { error_log('wq_tick: ' . $e->getMessage()); $r['error'] = mb_substr($e->getMessage(), 0, 160); }
    return $r;
}
/** ONE "waiting too long" limit for the whole panel: the Signals alert minutes (Waiting → Signals & bot). Late replies, the red "waiting" in Not taken
 *  and the Signals list all use it, so every screen calls the same customers late. (wq_grab_min still wins if someone set it on purpose.) */
/** 🔴 A chat that must be answered NOW (customer ready to pay / bot handed it over): after this many minutes with no answer it shows to EVERYONE at once. Default 1. */
function wq_urgent_min(PDO $pdo): int { $v = wq_get($pdo, 'wq_urgent_min', null); return ($v !== null && (int)$v > 0) ? max(1, min(30, (int)$v)) : 1; }
function wq_late_min(PDO $pdo): int
{
    $own = wq_get($pdo, 'wq_grab_min', null); if ($own !== null && (int)$own > 0) return max(1, (int)$own);
    return function_exists('wag_cfg') ? max(1, (int)(wag_cfg($pdo)['unans_min'] ?? 4)) : 4;
}
/** "Late replies — take one": open chats where the customer has waited wq_grab_min (4) minutes or more with no answer from anyone. Not listed: chats someone answered after
 *  the customer's last message, chats where someone is typing right now (last 90 s), Complaint-team chats, opted-out customers and closed 24-hour windows. Anybody can take one. */
function wq_grab_list(PDO $pdo, int $me, int $limit = 60): array
{
    $min = wq_late_min($pdo); $um = min($min, wq_urgent_min($pdo)); $now = wq_now(); $names = wq_names($pdo);
    $st = $pdo->prepare("SELECT phone, name, assigned_emp, assigned_at, last_in_at, last_out_at, hot FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? AND last_in_at <= ?
        AND (last_out_at IS NULL OR last_out_at < last_in_at) AND (cx_id IS NULL OR cx_id = 0) AND (tag IS NULL OR tag != 'complaint') AND NOT EXISTS (SELECT 1 FROM wa_ctags ctx WHERE ctx.phone = wa_contacts.phone AND ctx.tag = 'complaint') AND NOT EXISTS (SELECT 1 FROM cx_cases cxx WHERE cxx.phone = wa_contacts.phone AND (cxx.status IN ('open','reopened') OR (cxx.status = 'resolved' AND cxx.raised_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)))) ORDER BY last_in_at LIMIT " . (int)($limit * 2));
    try { $st->execute([wq_dt($now - 86400), wq_dt($now - $um * 60)]); $rows = $st->fetchAll(); }
    catch (Throwable $e) { $st = $pdo->prepare("SELECT phone, name, assigned_emp, assigned_at, last_in_at, last_out_at, hot FROM wa_contacts WHERE status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? AND last_in_at <= ? AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT " . (int)($limit * 2)); $st->execute([wq_dt($now - 86400), wq_dt($now - $um * 60)]); $rows = $st->fetchAll(); }
    // typing marks and last messages for ALL rows in 2 queries (it was 2 queries per row: 120+ per refresh)
    $phs = array_values(array_map(fn($r) => (string)$r['phone'], $rows)); $typing = []; $lastIn = [];
    if ($phs) {
        try { $q = $pdo->prepare("SELECT k, v FROM wq_meta WHERE k IN (" . wq_in($phs) . ")"); $q->execute(array_map(fn($p) => 'typing:' . $p, $phs)); foreach ($q->fetchAll() as $t) $typing[substr((string)$t['k'], 7)] = (string)$t['v']; }
        catch (Throwable $e) { foreach ($phs as $p) $typing[$p] = wq_meta_get($pdo, 'typing:' . $p, ''); }
        try { $q = $pdo->prepare("SELECT m.phone, m.body FROM wa_messages m JOIN (SELECT phone, MAX(id) mid FROM wa_messages WHERE dir = 'in' AND phone IN (" . wq_in($phs) . ") GROUP BY phone) x ON x.mid = m.id"); $q->execute($phs); foreach ($q->fetchAll() as $t) $lastIn[(string)$t['phone']] = (string)$t['body']; } catch (Throwable $e) { }
    }
    $o = [];
    foreach ($rows as $r) {
        $ph = (string)$r['phone']; if (wq_ts($typing[$ph] ?? '') > $now - 90) continue;   // somebody is writing to them right now
        $urg = (int)($r['hot'] ?? 0) === 1; $need = $urg ? $um : $min; if ($now - wq_ts($r['last_in_at']) < $need * 60) continue;   // ready-to-pay chats go to everyone after 1 minute, the rest after the normal minutes
        if ((int)$r['assigned_emp'] > 0 && wq_ts($r['assigned_at'] ?? '') > $now - $need * 60) continue;   // just given / taken: that person gets their own minute   // just given / taken: that person gets their own few minutes
        $o[] = ['phone' => $ph, 'name' => (string)$r['name'], 'holder' => wq_person($names, (int)$r['assigned_emp']), 'mine' => (int)$r['assigned_emp'] === $me && $me > 0,
            'urgent' => $urg, 'waiting_min' => max(0, (int)floor(($now - wq_ts($r['last_in_at'])) / 60)), 'waiting_sec' => max(0, $now - wq_ts($r['last_in_at'])), 'last_in' => mb_substr((string)($lastIn[$ph] ?? ''), 0, 120)];
        if (count($o) >= $limit) break;
    }
    return ['ok' => true, 'min' => $min, 'count' => count($o), 'items' => $o];
}
/** 🔴🟠 "Needs you now": every open chat of the team (24-hour window) where the customer is ready to pay (RED) or interested (ORANGE) and NO PERSON has answered their last message — the bot may be chatting, that does not count.
 *  Everybody sees it at once and anybody can take it. A chat somebody just took (assigned_at in the last 2 minutes) leaves the others' list for those 2 minutes; silent after that, it is back for everyone.
 *  Computed at most every 10 seconds for the whole team (kept in one setting), so 15 phones refreshing cost one query. */
/** 🏅 ONE ranking of the salespeople who are at work now (sales department, punched in within 20 h), mixing today and the whole month: kept 2 minutes. [emp_id => rank 1..n]
 *  score = 30% ACTIVE RIGHT NOW (answered a customer in the last 10 minutes = full, fading to 0 at 30 minutes) + 20% reply speed today (median minutes, needs 3+ answers) + 10% activity today (answers given, capped at 20)
 *        + 22% order conversion of the last 30 days (needs 10+ queries) + 18% number of orders in the last 30 days (against the best at work now). */
function wq_rank_today(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wq_rank', ''), true); if (is_array($j) && (int)($j['at'] ?? 0) > time() - 120) return $j;
    $rank = []; $order = []; $skip = [];
    try {
        $sales = $pdo->query("SELECT e.employee_id, e.full_name FROM employees e LEFT JOIN departments d ON d.department_id = e.department_id WHERE e.status != 'ex' AND d.department_name LIKE '%sales%'")->fetchAll(PDO::FETCH_ASSOC);
        $lastAns = []; try { foreach ($pdo->query("SELECT emp_id, MAX(ts) t FROM wa_messages WHERE dir = 'out' AND emp_id > 0 AND ts >= '" . date('Y-m-d H:i:s', time() - 1800) . "' GROUP BY emp_id")->fetchAll(PDO::FETCH_ASSOC) as $r0) $lastAns[(int)$r0['emp_id']] = strtotime((string)$r0['t']); } catch (Throwable $e) { }   // who is answering customers right now
        $present = []; foreach ($pdo->query("SELECT DISTINCT employee_id FROM attendance WHERE punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= '" . date('Y-m-d H:i:s', time() - 20 * 3600) . "'")->fetchAll(PDO::FETCH_COLUMN) as $e) $present[(int)$e] = 1;
        $day = []; $mon = []; if (function_exists('wq_pace_get')) { try { foreach ((array)(wq_pace_get($pdo, 1)['people'] ?? []) as $p) $day[(int)$p['emp_id']] = $p; foreach ((array)(wq_pace_get($pdo, 30)['people'] ?? []) as $p) $mon[(int)$p['emp_id']] = $p; } catch (Throwable $e) { } }
        $maxO = 1; foreach ($mon as $e => $p) if (!empty($present[$e])) $maxO = max($maxO, (int)($p['orders']['orders'] ?? 0));
        $list = [];
        foreach ($sales as $r) { $id = (int)$r['employee_id']; if (empty($present[$id])) continue; $d = $day[$id] ?? null; $m = $mon[$id] ?? null; $oe = $m['orders'] ?? null; $n = $d ? (int)($d['pace']['n'] ?? 0) : 0; $med = $d ? ($d['pace']['median'] ?? null) : null;
            $speed = ($n >= 3 && $med !== null) ? max(0, 30 - (float)$med) / 30 * 100 : 0; $act = min(20, $n) / 20 * 100;
            $conv = ($oe && (int)($oe['queries'] ?? 0) >= 10 && $oe['conversion'] !== null) ? min(40, (float)$oe['conversion']) / 40 * 100 : 0; $ord = $oe ? min(100, 100 * (int)($oe['orders'] ?? 0) / $maxO) : 0;
            $mAgo = isset($lastAns[$id]) ? (time() - $lastAns[$id]) / 60 : 999; $live = $mAgo <= 10 ? 100 : ($mAgo < 30 ? (30 - $mAgo) / 20 * 100 : 0);
            $list[] = ['id' => $id, 'name' => (string)$r['full_name'], 's' => 0.30 * $live + 0.20 * $speed + 0.10 * $act + 0.22 * $conv + 0.18 * $ord]; }
        usort($list, fn($a, $b) => [$b['s'], $a['name']] <=> [$a['s'], $b['name']]); $i = 0; foreach ($list as $x) { $rank[$x['id']] = ++$i; $order[] = $x['id']; }
        // 🎯 proven sellers first: somebody with 10+ queries in 30 days whose orders are far below the team's (under 40% of the average, or none) is not shown new red / orange chats in the first minutes; they see them after the wave time like everybody else. Newer people (under 10 queries) are on trial and stay in.
        $cv = []; foreach ($list as $x) { $oe = $mon[$x['id']]['orders'] ?? null; if ($oe && (int)($oe['queries'] ?? 0) >= 10) $cv[$x['id']] = (float)($oe['conversion'] ?? 0); }
        $avg = $cv ? array_sum($cv) / count($cv) : 0; foreach ($cv as $e1 => $c1) if ($c1 <= 0 || ($avg > 0 && $c1 < 0.4 * $avg)) $skip[$e1] = 1;
    } catch (Throwable $e) { }
    $o = ['at' => time(), 'rank' => $rank, 'order' => $order, 'skip' => $skip]; setting_set($pdo, 'wq_rank', json_encode($o)); return $o;
}
/** Waves: a new red / orange chat is shown first to the best 15 (default), and to everybody after 2 minutes (default). */
function wq_wave_cfg(PDO $pdo): array
{
    $s = wq_get($pdo, 'wq_wave_size', null); $m = wq_get($pdo, 'wq_wave_min', null);
    return [($s !== null && (int)$s > 0) ? max(1, min(500, (int)$s)) : 40, ($m !== null && (int)$m > 0) ? max(1, min(30, (int)$m)) : 2];
}
/** The wave (1, 2, 3 …) of a person: who is in the first group, the next, … ; 0 = not ranked (not at work / not in sales): always sees everything. */
function wq_wave_of(array $rk, int $emp, int $size): int { return isset($rk['rank'][$emp]) ? (int)ceil($rk['rank'][$emp] / max(1, $size)) : 0; }
/** What counts as a PROPER answer by a person: a photo / video / voice note / document / pre-made message, or a text of 30+ letters ("I am checking your follow-up, sir", "your parcel will be out soon, it was late because of Diwali"). A bare ok / yes / hmm is not an answer. SQL for a message alias $h of a chat alias $c. */
function wq_human_sql(string $h = 'h', string $c = 'c', string $inCol = 'last_in_at'): string
{
    return "$h.dir = 'out' AND ($h.emp_id > 0 OR $h.who IN ('emp', 'phone')) AND $h.ts >= $c.$inCol AND ($h.mtype IN ('image', 'video', 'audio', 'document', 'template', 'interactive') OR CHAR_LENGTH(TRIM(COALESCE($h.body, ''))) >= 30)";
}
function wq_need_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wq_need_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, level VARCHAR(8) NOT NULL, seen_at DATETIME NOT NULL, in_at DATETIME NOT NULL, taken_at DATETIME NULL, taken_by INT NOT NULL DEFAULT 0, dismissed TINYINT NOT NULL DEFAULT 0, UNIQUE KEY u_pi (phone, in_at), KEY k_seen (seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { return $ok = false; }
}
function wq_needs(PDO $pdo, int $me, bool $waves = true): array
{
    $now = time(); $raw = json_decode((string)setting_get($pdo, 'wq_needs', ''), true);
    if (!is_array($raw) || (int)($raw['at'] ?? 0) < $now - 10) {
        $items = [];
        try {
            $st = $pdo->prepare("SELECT c.phone, c.name, c.assigned_emp, c.assigned_at, c.last_in_at, c.last_out_at, c.hot, c.tag, c.seen_msg_id FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.last_in_at IS NOT NULL AND c.last_in_at >= ? AND (c.cx_id IS NULL OR c.cx_id = 0) AND (c.tag IS NULL OR c.tag <> 'complaint')
                AND NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND " . wq_human_sql() . ") ORDER BY c.last_in_at DESC LIMIT 150");
            $st->execute([wq_dt($now - 86400)]); $rows = $st->fetchAll();
            $phs = array_values(array_map(fn($r) => (string)$r['phone'], $rows)); $last = []; $lastId = [];
            if ($phs) { $q = $pdo->prepare("SELECT m.phone, m.body, m.id FROM wa_messages m JOIN (SELECT phone, MAX(id) mid FROM wa_messages WHERE dir = 'in' AND phone IN (" . wq_in($phs) . ") GROUP BY phone) x ON x.mid = m.id"); $q->execute($phs); foreach ($q->fetchAll() as $t) { $last[(string)$t['phone']] = (string)$t['body']; $lastId[(string)$t['phone']] = (int)$t['id']; } }
            $ordered = []; try { foreach ($pdo->query("SELECT DISTINCT RIGHT(phone, 10) FROM shop_orders WHERE created_at >= '" . wq_dt($now - 7 * 86400) . "' AND phone <> ''")->fetchAll(PDO::FETCH_COLUMN) as $op) $ordered[(string)$op] = 1; } catch (Throwable $e) { }   // an order in the last 7 days: not a lead any more
            try { foreach ($pdo->query("SELECT phone FROM wa_order_done WHERE mute_until > NOW()")->fetchAll(PDO::FETCH_COLUMN) as $op) $ordered[substr(preg_replace('/\D/', '', (string)$op), -10)] = 1; } catch (Throwable $e) { }   // "order completed" was pressed in the chat
            // ⏱ a customer who showed interest EARLIER (even if the last message is just "ok / thanks") and nobody has answered for 3 minutes turns orange by time
            $intr = []; $cand = []; foreach ($rows as $r0) if ($now - wq_ts($r0['last_in_at']) >= 180) $cand[] = (string)$r0['phone'];
            if ($cand && function_exists('wag_read')) {
                try { $q = $pdo->prepare("SELECT phone, body FROM wa_messages WHERE dir = 'in' AND mtype = 'text' AND body <> '' AND ts >= ? AND phone IN (" . wq_in($cand) . ") ORDER BY id DESC LIMIT 1500"); $q->execute(array_merge([wq_dt($now - 86400)], $cand)); $cn = [];
                    foreach ($q->fetchAll() as $t) { $pp = (string)$t['phone']; $cn[$pp] = ($cn[$pp] ?? 0) + 1; if ($cn[$pp] > 6 || isset($intr[$pp])) continue; $xr = wag_read(['last_in_at' => wq_dt($now - 60), 'last_body' => (string)$t['body'], 'last_dir' => 'in'], true, true, false, false, 0, $now); if (!empty($xr['i'])) $intr[$pp] = 1; } } catch (Throwable $e) { }
                try { $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_auto_tags WHERE tag IN ('interested', 'hot', 'considering') AND (until IS NULL OR until > NOW()) AND phone IN (" . wq_in($cand) . ")"); $q->execute($cand); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pp) $intr[(string)$pp] = 1; } catch (Throwable $e) { }
                try { $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_ctags WHERE tag = 'orange' AND phone IN (" . wq_in($cand) . ")"); $q->execute($cand); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pp) $intr[(string)$pp] = 1; } catch (Throwable $e) { }   // 🟠 the Autobot marked this customer interested (price / offer question): it joins the same Needs-you-now list
            }
            $dis = []; if ($phs && wq_need_tables($pdo)) { try { $q = $pdo->prepare("SELECT phone, in_at FROM wq_need_log WHERE dismissed = 1 AND seen_at >= ? AND phone IN (" . wq_in($phs) . ")"); $q->execute(array_merge([wq_dt($now - 86400)], $phs)); foreach ($q->fetchAll() as $t) $dis[(string)$t['phone'] . '|' . wq_ts($t['in_at'])] = 1; } catch (Throwable $e) { } }
            foreach ($rows as $r) {
                $ph = (string)$r['phone']; $body = (string)($last[$ph] ?? ''); if (isset($dis[$ph . '|' . wq_ts($r['last_in_at'])])) continue;   // somebody marked it "not a real lead"
                if (isset($ordered[substr(preg_replace('/\D/', '', $ph), -10)])) continue;   // the customer has ordered
                if ((int)$r['assigned_emp'] > 0 && (int)$r['seen_msg_id'] > 0 && (int)$r['seen_msg_id'] >= (int)($lastId[$ph] ?? PHP_INT_MAX) && $now - max(wq_ts($r['assigned_at'] ?? ''), wq_ts($r['last_in_at'])) < 180) continue;   // taken and read: theirs for 3 minutes; with no real answer after that it goes back to the list for the others   // the person who has the chat has opened it and read the message: it is taken

                $rd = function_exists('wag_read') ? wag_read(['last_in_at' => $r['last_in_at'], 'last_body' => $body, 'last_dir' => 'in'], true, true, (int)$r['hot'] === 1, false, 0, $now) : ['i' => ''];
                $lv = (string)($rd['i'] ?? ''); $wv = wq_ts($r['last_in_at']);
                if ($lv === '') { if (($now - wq_ts($r['last_in_at'])) >= 180 && !empty($intr[$ph])) { $lv = 'orange'; $wv = wq_ts($r['last_in_at']) + 180; $rd['u'] = '⏱ Interested earlier — nobody has answered for ' . (int)floor(($now - wq_ts($r['last_in_at'])) / 60) . ' min'; } else continue; }
                $items[] = ['phone' => $ph, 'name' => (string)$r['name'], 'level' => $lv, 'emp' => (int)$r['assigned_emp'], 'at' => wq_ts($r['assigned_at'] ?? ''), 'in' => wq_ts($r['last_in_at']), 'wv' => $wv, 'txt' => mb_substr(trim($body), 0, 90), 'why' => (string)($rd['u'] ?? '')];
                if (count($items) >= 40) break;
            }
            if ($items && wq_need_tables($pdo)) { $ins = $pdo->prepare("INSERT IGNORE INTO wq_need_log (phone, level, seen_at, in_at) VALUES (?, ?, ?, ?)"); foreach ($items as $x) { try { $ins->execute([$x['phone'], $x['level'], wq_dt($now), wq_dt($x['in'])]); } catch (Throwable $e) { } } }   // for the "Bot handoffs" board
            if (wq_need_tables($pdo) && mt_rand(1, 300) === 1) { try { $pdo->exec("DELETE FROM wq_need_log WHERE seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"); } catch (Throwable $e) { } }
        } catch (Throwable $e) { }
        // ⏳ who still has answers pending: their chats (and open complaints they raised / own / hold) where the customer has waited 5+ minutes with no PROPER answer by a person and the customer's last message is more than an "ok / thanks".
        //    They do not get NEW red / orange chats until these are answered (an answer takes the chat off the list at once). The list itself is shown to that person only.
        $bk = []; $bkc = []; $bki = []; $bkMin = wq_get($pdo, 'wq_backlog_min', null); $bkMin = ($bkMin !== null && (int)$bkMin > 0) ? max(1, min(30, (int)$bkMin)) : 5;
        $hum = "NOT EXISTS (SELECT 1 FROM wa_messages h WHERE h.phone = c.phone AND " . wq_human_sql() . ")"; $pend = [];
        try { $q = $pdo->prepare("SELECT c.phone, c.name, c.assigned_emp, c.last_in_at FROM wa_contacts c WHERE c.status = 'open' AND c.optout = 0 AND c.assigned_emp > 0 AND (c.cx_id IS NULL OR c.cx_id = 0) AND c.last_in_at >= ? AND c.last_in_at <= ? AND $hum ORDER BY c.last_in_at LIMIT 400"); $q->execute([wq_dt($now - 86400), wq_dt($now - $bkMin * 60)]);
            foreach ($q->fetchAll() as $r1) $pend[(string)$r1['phone']] = ['name' => (string)$r1['name'], 'in' => wq_ts($r1['last_in_at']), 'emps' => [(int)$r1['assigned_emp']], 'cx' => 0]; } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT cc.id cid, cc.phone, cc.owner_emp, cc.raised_by, c.assigned_emp, c.name, c.last_in_at FROM cx_cases cc JOIN wa_contacts c ON c.phone = cc.phone WHERE cc.status IN ('open', 'reopened') AND c.last_in_at >= ? AND c.last_in_at <= ? AND $hum ORDER BY c.last_in_at LIMIT 300"); $q->execute([wq_dt($now - 86400), wq_dt($now - $bkMin * 60)]);
            foreach ($q->fetchAll() as $r1) { $es = []; foreach ([(int)$r1['owner_emp'], (int)$r1['raised_by'], (int)$r1['assigned_emp']] as $e1) if ($e1 > 0) $es[$e1] = $e1; if (!$es) continue; $pend[(string)$r1['phone']] = ['name' => (string)$r1['name'], 'in' => wq_ts($r1['last_in_at']), 'emps' => array_values($es), 'cx' => (int)$r1['cid']]; } } catch (Throwable $e) { }
        try { $q = $pdo->query("SELECT cc.phone, cc.owner_emp, cc.raised_at FROM cx_cases cc WHERE cc.status IN ('open', 'reopened') AND cc.owner_emp > 0 AND ((cc.due_at IS NOT NULL AND cc.due_at < NOW()) OR (cc.first_reply_at IS NULL AND cc.reply_due IS NOT NULL AND cc.reply_due < NOW())) LIMIT 200");   // ⛔ an OVERDUE complaint also holds back new red / orange chats from its owner
            foreach ($q->fetchAll() as $r1) { $e1 = (int)$r1['owner_emp']; $ph1 = (string)$r1['phone']; if (isset($pend[$ph1]) && in_array($e1, $pend[$ph1]['emps'], true)) continue; $bk[$e1] = ($bk[$e1] ?? 0) + 1; $bkc[$e1] = ($bkc[$e1] ?? 0) + 1; if (count($bki[$e1] ?? []) < 30) $bki[$e1][] = ['phone' => $ph1, 'name' => '', 'kind' => 'cx', 'in' => wq_ts($r1['raised_at']), 'txt' => 'Complaint is overdue']; } } catch (Throwable $e) { }
        if ($pend) { try { $pp = array_keys($pend); $q = $pdo->prepare("SELECT m.phone, m.body, m.mtype FROM wa_messages m JOIN (SELECT phone, MAX(id) mid FROM wa_messages WHERE dir = 'in' AND phone IN (" . wq_in($pp) . ") GROUP BY phone) x ON x.mid = m.id"); $q->execute($pp);
                foreach ($q->fetchAll() as $t) { $ph1 = (string)$t['phone']; if (!isset($pend[$ph1])) continue; if (function_exists('wq_pace_trivial') && wq_pace_trivial((string)$t['mtype'], (string)$t['body'])) { unset($pend[$ph1]); continue; } $pend[$ph1]['txt'] = mb_substr(trim((string)$t['body']) !== '' ? trim((string)$t['body']) : '[' . (string)$t['mtype'] . ']', 0, 90); } } catch (Throwable $e) { }
            foreach ($pend as $ph1 => $p1) foreach ($p1['emps'] as $e1) { $bk[$e1] = ($bk[$e1] ?? 0) + 1; if ($p1['cx']) $bkc[$e1] = ($bkc[$e1] ?? 0) + 1; if (count($bki[$e1] ?? []) < 30) $bki[$e1][] = ['phone' => (string)$ph1, 'name' => $p1['name'], 'kind' => $p1['cx'] ? 'cx' : 'chat', 'in' => $p1['in'], 'txt' => (string)($p1['txt'] ?? '')]; } }
        try { $old = []; foreach ($pdo->query("SELECT k, v FROM wq_meta WHERE k LIKE 'bki:%'")->fetchAll() as $r2) $old[(string)$r2['k']] = (string)$r2['v'];   // each person's own list is kept in its own row (not in the shared list every phone reads), and written only when it changed
            foreach ($bki as $e1 => $l1) { $js = json_encode($l1, JSON_UNESCAPED_UNICODE); $k1 = 'bki:' . $e1; if (($old[$k1] ?? null) !== $js) wq_meta_put($pdo, $k1, $js); unset($old[$k1]); }
            foreach ($old as $k1 => $_v) $pdo->prepare("DELETE FROM wq_meta WHERE k = ?")->execute([$k1]); } catch (Throwable $e) { }
        $raw = ['at' => $now, 'items' => $items, 'backlog' => $bk, 'bk_cx' => $bkc]; try { setting_set($pdo, 'wq_needs', json_encode($raw, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) { }   // kept for 10 s so every phone shares one rebuild
    }
    $names = wq_names($pdo); $o = []; $rkT = ($waves && $me > 0) ? wq_rank_today($pdo) : ['rank' => []]; [$wsz, $wmin] = wq_wave_cfg($pdo);
    foreach ((array)$raw['items'] as $x) {
        $own = $me > 0 && (int)$x['emp'] === $me;
        if (!$own && $waves && $me > 0 && (int)($raw['backlog'][$me] ?? 0) > 0) continue;   // answers of theirs are still pending: no new red / orange for them
        if (!$own && $waves && $me > 0 && function_exists('wqm_hold') && wqm_hold($pdo, $me)) continue;   // 🧭 the admin approved "no new chats" for this person (only in apply mode)
        if (!$own && $waves && $me > 0 && ($now - (int)($x['wv'] ?? $x['in'])) < $wmin * 60) {
            if (function_exists('wqm_mode') && wqm_mode($pdo) === 'apply') { if (wqm_late($pdo, $me) || !isset($rkT['rank'][$me]) || (!wqm_first($pdo, $me) && $rkT['rank'][$me] > $wsz)) continue; }   // 🧭 Team manager decides
            elseif (!isset($rkT['rank'][$me]) || $rkT['rank'][$me] > $wsz || !empty($rkT['skip'][$me])) continue;
        }   // first minutes: only people at work who sell well (not absent, not a poor converter); everybody present sees it after that   // first 2 minutes: the best 15 (today's speed mixed with the month's performance) see red and orange chats; everybody sees them after that
        if (!$own && (int)$x['emp'] > 0 && (int)$x['at'] > $now - 180) continue;   // somebody just took it: they get their own 3 minutes
        $o[] = ['phone' => $x['phone'], 'name' => $x['name'], 'level' => $x['level'], 'own' => $own, 'holder' => (int)$x['emp'] > 0 ? wq_name($names, (int)$x['emp']) : '', 'sec' => max(0, $now - (int)$x['in']), 'txt' => $x['txt'], 'why' => $x['why'], 'emp' => (int)$x['emp'], 'key' => md5((int)$x['in'] . '|' . (int)$x['emp'] . '|' . (int)$x['at'])];
    }
    usort($o, fn($a, $b) => ($a['level'] === $b['level'] ? 0 : ($a['level'] === 'red' ? -1 : 1)) ?: $b['sec'] <=> $a['sec']);
    return $o;
}
/** How many pending answers (chats waiting 4+ minutes, open complaints) each person has — from the last rebuild of the Needs you now list. [emp_id => n] */
/** The chats / complaints this person still has to answer (their own list only): [{phone, name, kind: chat|cx, sec, txt}], complaints first, then the longest wait. */
function wq_needs_backlog_items(PDO $pdo, int $me): array
{
    $j = json_decode(wq_meta_get($pdo, 'bki:' . $me, ''), true); $l = is_array($j) ? $j : []; $now = wq_now(); $o = [];
    foreach ($l as $x) $o[] = ['phone' => $x['phone'], 'name' => $x['name'], 'kind' => $x['kind'], 'sec' => max(0, $now - (int)$x['in']), 'txt' => $x['txt']];
    usort($o, fn($a, $b) => ($a['kind'] === $b['kind'] ? 0 : ($a['kind'] === 'cx' ? -1 : 1)) ?: $b['sec'] <=> $a['sec']); return $o;
}
function wq_needs_backlog_cx(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wq_needs', ''), true); return is_array($j) && is_array($j['bk_cx'] ?? null) ? $j['bk_cx'] : []; }
function wq_needs_backlog(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'wq_needs', ''), true); return is_array($j) && is_array($j['backlog'] ?? null) ? $j['backlog'] : []; }
/** 🔴 Chats that must be answered now and nobody answered in time: ONE push to every present salesperson at the same moment (again each time the chat comes back to the pool). Called from wa_tick. */
function wq_urgent_push(PDO $pdo): int
{
    $n = 0; if (!function_exists('ct_push')) return 0;
    $qf = wq_get($pdo, 'wq_push_quiet_from', null); $qt = wq_get($pdo, 'wq_push_quiet_to', null); $qf = $qf === null ? 1 : (int)$qf; $qt = $qt === null ? 10 : (int)$qt; $hr = (int)date('G');
    if ($qf !== $qt && ($qf < $qt ? ($hr >= $qf && $hr < $qt) : ($hr >= $qf || $hr < $qt))) return 0;   // quiet hours (default 01:00-10:00): no phone rings; the card still shows, and anything still waiting is pushed when the quiet hours end
    try {
        $rk = wq_rank_today($pdo); if (empty($rk['order'])) return 0; [$wsz, $wmin] = wq_wave_cfg($pdo);
        foreach (wq_needs($pdo, 0, false) as $x) {
            if ($x['level'] !== 'red') continue; $ph = (string)$x['phone'];   // RED only: orange (interested) shows in the card, it does not ring phones
            $stage = $x['sec'] >= $wmin * 60 ? 2 : 1; $stamp = $x['key'] . '|' . $stage; if (wq_meta_get($pdo, 'urg:' . $ph, '') === $stamp) continue; wq_meta_put($pdo, 'urg:' . $ph, $stamp);
            $bkm = wq_needs_backlog($pdo); $tg = []; foreach ($rk['order'] as $i => $e) { $top = ($i + 1) <= $wsz; if ((int)$e !== (int)$x['emp'] && empty($bkm[(int)$e]) && ($stage === 1 ? $top : !$top)) $tg[] = (int)$e; } if (!$tg) continue;   // first the best 15; after 2 minutes everybody else at work (the holder was already pushed when the chat turned hot)
            $cu = trim((string)$x['name']) !== '' ? (string)$x['name'] : '+91 ' . $ph;
            ct_push($pdo, $tg, '🔴 Needs you now', $cu . ' wants to buy — nobody has answered yet. Take it.', '/my_chats.html?phone=' . $ph, 'wa-urg-' . $ph); $n++;
            if ($stage === 1) wa_event($pdo, $ph, '🔴 Ready to pay — shown to the best of today first, then to everybody');
        }
        // ⏳ people who are held back by pending answers get ONE short push (at most every 30 minutes) while red / orange chats are waiting for somebody
        $pool = wq_needs($pdo, 0, false); if ($pool) { $bkm = wq_needs_backlog($pdo); $bcx = wq_needs_backlog_cx($pdo);
            foreach ($rk['order'] as $e) { $e = (int)$e; $nb = (int)($bkm[$e] ?? 0); if ($nb <= 0) continue; $any = false; foreach ($pool as $x) if ((int)$x['emp'] !== $e) { $any = true; break; } if (!$any) continue;
                if ((int)wq_meta_get($pdo, 'bkn:' . $e, '0') > time() - 1800) continue; wq_meta_put($pdo, 'bkn:' . $e, (string)time()); $cx = (int)($bcx[$e] ?? 0);
                ct_push($pdo, [$e], '⏳ Replies pending', 'Your ' . ($nb - $cx) . ' repl' . (($nb - $cx) === 1 ? 'y is' : 'ies are') . ($cx ? ' and ' . $cx . ' complaint repl' . ($cx === 1 ? 'y is' : 'ies are') : '') . ' pending — that is why you are not getting the red and orange chats. Please complete the replies to get them.', '/my_chats.html', 'wa-bk-' . $e); $n++; } }
    } catch (Throwable $e) { }
    return $n;
}
/** An ORANGE chat that is not a real lead (just curious, wrong person): hidden for everyone; counted on the board so the rules can be tightened. */
function wq_need_dismiss(PDO $pdo, string $phone, int $me): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can do this.']; $c = wq_contact($pdo, $phone); if (!$c || !wq_need_tables($pdo)) return ['error' => 'Chat not found.'];
    $pdo->prepare("UPDATE wq_need_log SET dismissed = 1 WHERE phone = ? AND in_at = ? AND level = 'orange'")->execute([$phone, wq_dt(wq_ts($c['last_in_at'] ?? ''))]);
    try { setting_set($pdo, 'wq_needs', ''); } catch (Throwable $e) { } return ['ok' => true];
}
/** "Order placed" was marked in the chat: whatever is waiting from this customer's latest message (red or orange) leaves the card for everyone. A NEW message of the customer can bring it back. */
function wq_need_close(PDO $pdo, string $phone): void
{
    try { if (!wq_need_tables($pdo)) return; $c = wq_contact($pdo, $phone); if (!$c || empty($c['last_in_at'])) return;
        $pdo->prepare("INSERT INTO wq_need_log (phone, level, seen_at, in_at, dismissed) VALUES (?, 'orange', ?, ?, 1) ON DUPLICATE KEY UPDATE dismissed = 1")->execute([$phone, wq_dt(wq_now()), wq_dt(wq_ts($c['last_in_at']))]);
        setting_set($pdo, 'wq_needs', ''); } catch (Throwable $e) { }
}
/** Admin board: what came up as red / orange in the last 7 days, who took it, how fast, and whether an order followed. */
function wq_needs_board(PDO $pdo): array
{
    $o = ['ok' => true, 'rows' => [], 'sum' => ['red' => ['n' => 0, 'taken' => 0, 'answered' => 0, 'ordered' => 0, 'dismissed' => 0, 'take_s' => 0, 'reply_s' => 0], 'orange' => ['n' => 0, 'taken' => 0, 'answered' => 0, 'ordered' => 0, 'dismissed' => 0, 'take_s' => 0, 'reply_s' => 0]], 'live' => []];
    if (!wq_need_tables($pdo)) return $o; $names = wq_names($pdo);
    try {
        $ord = []; foreach ($pdo->query("SELECT DISTINCT RIGHT(phone, 10) FROM shop_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 DAY) AND phone <> ''")->fetchAll(PDO::FETCH_COLUMN) as $p) $ord[(string)$p] = 1;
        $st = $pdo->query("SELECT l.phone, l.level, l.seen_at, l.in_at, l.taken_at, l.taken_by, l.dismissed, c.name, (SELECT MIN(h.ts) FROM wa_messages h WHERE h.phone = l.phone AND " . wq_human_sql('h', 'l', 'in_at') . ") reply_at FROM wq_need_log l LEFT JOIN wa_contacts c ON c.phone = l.phone WHERE l.seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY l.id DESC LIMIT 300");
        $n = 0; $cnt = ['red' => ['t' => 0, 'r' => 0], 'orange' => ['t' => 0, 'r' => 0]];
        foreach ($st->fetchAll() as $r) {
            $lv = $r['level'] === 'red' ? 'red' : 'orange'; $S = &$o['sum'][$lv]; $S['n']++; $seen = wq_ts($r['seen_at']);
            $tk = $r['taken_at'] ? max(0, wq_ts($r['taken_at']) - $seen) : null; $rp = $r['reply_at'] ? max(0, wq_ts($r['reply_at']) - $seen) : null; $od = isset($ord[$r['phone']]);
            if ($r['taken_at']) { $S['taken']++; $S['take_s'] += $tk; $cnt[$lv]['t']++; } if ($rp !== null) { $S['answered']++; $S['reply_s'] += $rp; $cnt[$lv]['r']++; } if ($od) $S['ordered']++; if ((int)$r['dismissed'] === 1) $S['dismissed']++; unset($S);
            if (++$n <= 60) $o['rows'][] = ['phone' => $r['phone'], 'name' => (string)$r['name'], 'level' => $lv, 'seen' => (string)$r['seen_at'], 'taken_by' => (int)$r['taken_by'] > 0 ? wq_person($names, (int)$r['taken_by']) : '', 'take_s' => $tk, 'reply_s' => $rp, 'ordered' => $od, 'dismissed' => (int)$r['dismissed'] === 1];
        }
        foreach (['red', 'orange'] as $lv) { $o['sum'][$lv]['take_s'] = $cnt[$lv]['t'] ? (int)round($o['sum'][$lv]['take_s'] / $cnt[$lv]['t']) : null; $o['sum'][$lv]['reply_s'] = $cnt[$lv]['r'] ? (int)round($o['sum'][$lv]['reply_s'] / $cnt[$lv]['r']) : null; }
        foreach (wq_needs($pdo, 0) as $x) $o['live'][] = ['phone' => $x['phone'], 'name' => $x['name'], 'level' => $x['level'], 'holder' => $x['holder'], 'sec' => $x['sec'], 'txt' => $x['txt']];
    } catch (Throwable $e) { }
    return $o;
}
/** Take a "Needs you now" chat (the bot may have answered it — only a PERSON's answer counts). Checked again at the moment of taking. */
function wq_need_take(PDO $pdo, string $phone, int $me): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can take a chat.'];
    $c = wq_contact($pdo, $phone); if (!$c) return ['error' => 'Chat not found.'];
    if ((int)$c['assigned_emp'] === $me) return ['ok' => true, 'already' => true];
    if ((int)($c['optout'] ?? 0) === 1) return ['error' => 'This customer opted out — nobody may message them.'];
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return ['error' => 'This is a complaint chat — it stays with its person.'];
    $in = wq_ts($c['last_in_at'] ?? ''); if ($in <= 0 || wq_now() - $in > 86400) return ['error' => 'The 24-hour window of this chat has closed.'];
    $q = $pdo->prepare("SELECT 1 FROM wa_messages h WHERE h.phone = ? AND h.dir = 'out' AND (h.emp_id > 0 OR h.who IN ('emp', 'phone')) AND h.ts >= ? AND (h.mtype IN ('image', 'video', 'audio', 'document', 'template', 'interactive') OR CHAR_LENGTH(TRIM(COALESCE(h.body, ''))) >= 30) LIMIT 1"); $q->execute([$phone, wq_dt($in)]); if ($q->fetchColumn()) return ['error' => 'A person has already answered this customer.'];
    $from = (int)$c['assigned_emp'];
    if ($from > 0 && wq_ts($c['assigned_at'] ?? '') > wq_now() - 180) return ['error' => wq_name(wq_names($pdo), $from) . ' just took this chat — give them 3 minutes.'];
    $mineN = 0; $isRed = false; foreach (wq_needs($pdo, $me, false) as $x) { if (!empty($x['own'])) $mineN++; if ((string)$x['phone'] === $phone && $x['level'] === 'red') $isRed = true; }
    $maxN = wq_get($pdo, 'wq_need_max', null); $maxN = ($maxN !== null && (int)$maxN > 0) ? min(50, (int)$maxN) : 6;   // default 6 waiting customers per person (setting wq_need_max)
    if ($mineN >= $maxN && !$isRed) return ['error' => 'You already have ' . $mineN . ' customers waiting for your answer — answer them first, then take another. (A customer who is ready to pay can always be taken.)'];   // the limit holds for ORANGE chats only: an order waiting is never left because someone is full
    $u = $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ? WHERE phone = ? AND assigned_emp = ?"); $u->execute([$me, $phone, $from]);
    if ($u->rowCount() === 0) return ['error' => 'Somebody else just took this chat.'];
    $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ? WHERE phone = ?")->execute([$from, $phone]);   // put it back for one line: wa_assign_to writes the log, the event and moves the query from $from
    wa_assign_to($pdo, $phone, $me, $me, 'grab'); try { setting_set($pdo, 'wq_needs', ''); if (wq_need_tables($pdo)) { $lg = $pdo->prepare("UPDATE wq_need_log SET taken_at = ?, taken_by = ? WHERE phone = ? AND in_at = ? AND taken_at IS NULL"); $lg->execute([wq_dt(wq_now()), $me, $phone, wq_dt($in)]); } } catch (Throwable $e) { }
    return ['ok' => true, 'from' => wq_person(wq_names($pdo), $from)];
}
/** Take one late chat: checked again at the moment of taking (somebody may have just answered or taken it). */
function wq_grab(PDO $pdo, string $phone, int $me): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can take a chat.'];
    $c = wq_contact($pdo, $phone); if (!$c) return ['error' => 'Chat not found.'];
    $min = wq_late_min($pdo); if ((int)($c['hot'] ?? 0) === 1) $min = min($min, wq_urgent_min($pdo)); $in = wq_ts($c['last_in_at'] ?? ''); $out = wq_ts($c['last_out_at'] ?? '');
    if ((int)$c['assigned_emp'] === $me) return ['ok' => true, 'already' => true];
    // everyone in the chat app may take a late chat (owner's rule)
    if ((int)($c['optout'] ?? 0) === 1) return ['error' => 'This customer opted out — nobody may message them.'];
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return ['error' => 'This is a complaint chat — it stays with its person.'];
    if ($in > 0 && wq_now() - $in > 86400) return ['error' => 'The 24-hour window of this chat has closed.'];
    if ($in <= 0 || $out >= $in) return ['error' => 'Somebody has already answered this customer.'];
    if (wq_now() - $in < $min * 60) return ['error' => 'This customer has waited less than ' . $min . ' minutes — the person handling it can still answer.'];
    if (wq_ts(wq_meta_get($pdo, 'typing:' . $phone, '')) > wq_now() - 90) return ['error' => 'Somebody is typing a reply to this customer right now.'];
    if ((int)$c['assigned_emp'] > 0 && wq_ts($c['assigned_at'] ?? '') > wq_now() - $min * 60) return ['error' => wq_name(wq_names($pdo), (int)$c['assigned_emp']) . ' just got this chat — give them ' . $min . ' minutes to reply.'];
    $from = (int)$c['assigned_emp'];
    $u = $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ? WHERE phone = ? AND assigned_emp = ? AND (last_out_at IS NULL OR last_out_at < last_in_at)"); $u->execute([$me, $phone, $from]);
    if ($u->rowCount() === 0) return ['error' => 'Somebody else just took this chat.'];
    $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ? WHERE phone = ?")->execute([$from, $phone]);   // put it back for one line: wa_assign_to writes the log, the event and moves the query from $from
    wa_assign_to($pdo, $phone, $me, $me, 'grab'); wq_clear_attention($pdo, $phone, $me, 'Taken by a free person after a late reply');
    return ['ok' => true, 'from' => wq_person(wq_names($pdo), $from)];
}
/** 🙋 Requests: every open chat that is NOT given to anybody yet (new inquiries), newest first — only while the 24-hour window is open, never complaint or opted-out
 *  chats. A salesperson opens the Requests tab and takes one; the moment a chat has an owner it leaves the list. */
function wq_req_list(PDO $pdo, int $me = 0, int $limit = 200): array
{
    $now = wq_now(); $rows = [];
    try { $st = $pdo->prepare("SELECT phone, name, last_in_at, last_out_at, source FROM wa_contacts WHERE (assigned_emp IS NULL OR assigned_emp = 0) AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ?
            AND (cx_id IS NULL OR cx_id = 0) AND (tag IS NULL OR tag != 'complaint') AND NOT EXISTS (SELECT 1 FROM wa_ctags ctx WHERE ctx.phone = wa_contacts.phone AND ctx.tag = 'complaint') AND NOT EXISTS (SELECT 1 FROM cx_cases cxx WHERE cxx.phone = wa_contacts.phone AND (cxx.status IN ('open','reopened') OR (cxx.status = 'resolved' AND cxx.raised_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)))) ORDER BY last_in_at DESC LIMIT " . (int)$limit); $st->execute([wq_dt($now - 2 * 86400)]); $rows = $st->fetchAll(); }
    catch (Throwable $e) { $st = $pdo->prepare("SELECT phone, name, last_in_at, last_out_at FROM wa_contacts WHERE (assigned_emp IS NULL OR assigned_emp = 0) AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? ORDER BY last_in_at DESC LIMIT " . (int)$limit); $st->execute([wq_dt($now - 2 * 86400)]); $rows = $st->fetchAll(); }
    $phs = array_values(array_map(fn($r) => (string)$r['phone'], $rows)); $lastIn = [];
    if ($phs) { try { $q = $pdo->prepare("SELECT m.phone, m.body, m.mtype FROM wa_messages m JOIN (SELECT phone, MAX(id) mid FROM wa_messages WHERE dir = 'in' AND phone IN (" . wq_in($phs) . ") GROUP BY phone) x ON x.mid = m.id"); $q->execute($phs); foreach ($q->fetchAll() as $t) $lastIn[(string)$t['phone']] = trim((string)$t['body']) !== '' ? (string)$t['body'] : '[' . (string)$t['mtype'] . ']'; } catch (Throwable $e) { } }
    $o = []; foreach ($rows as $r) { $ph = (string)$r['phone']; $in = wq_ts($r['last_in_at']); $o[] = ['phone' => $ph, 'name' => (string)$r['name'], 'mins' => max(0, (int)floor(($now - $in) / 60)), 'answered' => wq_ts($r['last_out_at'] ?? '') >= $in, 'last_in' => mb_substr((string)($lastIn[$ph] ?? ''), 0, 120), 'src' => (string)($r['source'] ?? '')]; }
    // ⏱ pinned on top: customers waiting wq_grab_min (4) min+ with no reply from anyone — unassigned ones AND ones with a person who did not answer (taken with wq.grab)
    $late = []; try { $g = wq_grab_list($pdo, $me); foreach ($g['items'] as $x) if (empty($x['mine'])) $late[] = $x; } catch (Throwable $e) { }
    $lp = array_flip(array_column($late, 'phone')); $o = array_values(array_filter($o, fn($x) => !isset($lp[$x['phone']])));
    // 🛍 a customer who already has an order is not a new request (after-sale questions: delivery, order id…) — they stay with their order's team, not in the open Requests list
    $all = array_values(array_unique(array_merge(array_column($o, 'phone'), array_column($late, 'phone'))));
    if ($all) { try {
        $l10 = []; foreach ($all as $ph) $l10[substr(preg_replace('/\D/', '', (string)$ph), -10)] = 1;
        $q = $pdo->prepare("SELECT DISTINCT RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''), 10) p FROM shop_orders WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''), 10) IN (" . wq_in(array_keys($l10)) . ")"); $q->execute(array_map('strval', array_keys($l10)));
        $has = array_flip(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));
        $hasOrd = fn($x) => isset($has[substr(preg_replace('/\D/', '', (string)$x['phone']), -10)]);
        $o = array_values(array_filter($o, fn($x) => !$hasOrd($x))); $late = array_values(array_filter($late, fn($x) => !$hasOrd($x)));
    } catch (Throwable $e) { } }
    $lab = wq_req_last($pdo, array_merge(array_column($o, 'phone'), array_column($late, 'phone')));   // 💬 the customer's last message, readable from the list (photo / voice named)
    foreach ($o as &$x) $x['last_in'] = $lab[$x['phone']] ?? ''; unset($x); foreach ($late as &$x) $x['last_in'] = $lab[$x['phone']] ?? ''; unset($x);
    $nd = function_exists('wq_needs') ? wq_needs($pdo, $me) : []; $hi = $me > 0 ? wq_needs_backlog_items($pdo, $me) : [];   // the list is read AFTER the rebuild, and the number is its length, so the box and the list can never disagree
    return ['ok' => true, 'count' => count($o) + count($late), 'items' => $o, 'late' => $late, 'late_min' => wq_late_min($pdo), 'needs' => $nd, 'needs_hold' => count($hi), 'needs_hold_cx' => count(array_filter($hi, fn($x) => $x['kind'] === 'cx')), 'backlog_min' => (int)(wq_get($pdo, 'wq_backlog_min', null) ?: 5), 'needs_hold_items' => $hi];
}
/** The customer's last message per phone, for the Requests list: text (or the caption), else 📷 Photo / 🎤 Voice note / … */
function wq_req_last(PDO $pdo, array $phs): array
{
    $phs = array_values(array_unique(array_filter(array_map('strval', $phs)))); if (!$phs) return []; $out = [];
    $nm = ['image' => '📷 Photo', 'audio' => '🎤 Voice note', 'voice' => '🎤 Voice note', 'video' => '🎥 Video', 'document' => '📄 Document', 'sticker' => '🙂 Sticker', 'location' => '📍 Location', 'contacts' => '👤 Contact'];
    try { $q = $pdo->prepare("SELECT m.phone, m.body, m.mtype FROM wa_messages m JOIN (SELECT phone, MAX(id) mid FROM wa_messages WHERE dir = 'in' AND phone IN (" . wq_in($phs) . ") GROUP BY phone) x ON x.mid = m.id"); $q->execute($phs);
        foreach ($q->fetchAll() as $t) { $b = trim(preg_replace('/\s+/u', ' ', (string)$t['body'])); $ty = strtolower((string)$t['mtype']); $pre = isset($nm[$ty]) ? $nm[$ty] : '';
            $out[(string)$t['phone']] = mb_substr($pre !== '' ? ($b !== '' && !preg_match('/^\[.*\]$/', $b) ? $pre . ' · ' . $b : $pre) : ($b !== '' ? $b : 'Message'), 0, 220); } } catch (Throwable $e) { }
    return $out;
}
/** Take a request: only if it is still with nobody (checked in the same UPDATE, so two people pressing at once cannot both get it). */
function wq_req_take(PDO $pdo, string $phone, int $me): array
{
    if ($me <= 0) return ['error' => 'Only a logged-in employee can take a chat.'];
    $c = wq_contact($pdo, $phone); if (!$c) return ['error' => 'Chat not found.'];
    if ((int)$c['assigned_emp'] === $me) return ['ok' => true, 'already' => true];
    // everyone who opens the chat app (every sales agent, managers and admin too) may take a request — owner's rule
    if ((int)($c['optout'] ?? 0) === 1) return ['error' => 'This customer opted out — nobody may message them.'];
    if ((int)($c['cx_id'] ?? 0) > 0 || (string)($c['tag'] ?? '') === 'complaint') return ['error' => 'This is a complaint chat — the complaint team handles it.'];
    if ((int)$c['assigned_emp'] > 0) return ['error' => (wq_name(wq_names($pdo), (int)$c['assigned_emp']) ?: 'Somebody') . ' already has this chat.'];
    $u = $pdo->prepare("UPDATE wa_contacts SET assigned_emp = ? WHERE phone = ? AND (assigned_emp IS NULL OR assigned_emp = 0)"); $u->execute([$me, $phone]);
    if ($u->rowCount() === 0) return ['error' => 'Somebody else just took this chat.'];
    $pdo->prepare("UPDATE wa_contacts SET assigned_emp = 0 WHERE phone = ?")->execute([$phone]);   // back for one line: wa_assign_to writes the log, the event and the query owner
    wa_assign_to($pdo, $phone, $me, $me, 'request'); wq_clear_attention($pdo, $phone, $me, 'Taken from Requests');
    return ['ok' => true];
}
/** "Who takes the most": per person, for the last 1 / 7 / 30 days — chats taken after a late reply, chats taken FROM them (they were late), how many taken chats got a reply
 *  and an order, and their query ratio. A taken chat's query belongs to the taker (wag_q_moved moves it), so queries / orders / % below already count it for them. */
function wq_grab_board(PDO $pdo, int $days): array
{
    $from = wq_dt(wq_now() - $days * 86400); $names = wq_names($pdo); $P = [];
    $row = function (int $e) use (&$P, $names) { if (!isset($P[$e])) $P[$e] = ['emp_id' => $e, 'name' => wq_name($names, $e) ?: 'Employee #' . $e, 'taken' => 0, 'taken_replied' => 0, 'taken_orders' => 0, 'lost' => 0, 'queries' => 0, 'q_orders' => 0, 'q_taken' => 0]; };
    $st = $pdo->prepare("SELECT phone, from_emp, to_emp, at FROM wa_assign_log WHERE how = 'grab' AND at >= ? ORDER BY id DESC LIMIT 3000"); $st->execute([$from]);
    $qid = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? AND opened_at <= ? ORDER BY id DESC LIMIT 1");
    $rep = $pdo->prepare("SELECT 1 FROM wa_messages WHERE phone = ? AND dir = 'out' AND ts > ? AND ts <= ? LIMIT 1");
    $ord = $pdo->prepare("SELECT orders FROM wq_qstat WHERE query_id = ?");
    $total = 0; $takenQ = [];
    foreach ($st->fetchAll() as $r) {
        $to = (int)$r['to_emp']; $fr = (int)$r['from_emp']; if ($to <= 0) continue; $total++;
        $row($to); $P[$to]['taken']++; if ($fr > 0 && $fr !== $to) { $row($fr); $P[$fr]['lost']++; }
        $rep->execute([(string)$r['phone'], (string)$r['at'], wq_dt(wq_ts($r['at']) + 86400)]); if ($rep->fetchColumn()) $P[$to]['taken_replied']++;
        $qid->execute([(string)$r['phone'], (string)$r['at']]); $q = (int)$qid->fetchColumn();
        if ($q > 0) { $takenQ[$q] = $to; try { $ord->execute([$q]); if ((int)$ord->fetchColumn() > 0) $P[$to]['taken_orders']++; } catch (Throwable $e) { } }
    }
    try {
        $qs = $pdo->prepare("SELECT q.id, q.emp_id, COALESCE(s.orders, 0) o FROM wa_queries q LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.emp_id > 0"); $qs->execute([$from]);
        foreach ($qs->fetchAll() as $r) { $e = (int)$r['emp_id']; $row($e); $P[$e]['queries']++; if ((int)$r['o'] > 0) $P[$e]['q_orders']++; if (($takenQ[(int)$r['id']] ?? 0) === $e) $P[$e]['q_taken']++; }
    } catch (Throwable $e) { }
    foreach ($P as &$x) { $x['reply_pct'] = wq_pct($x['taken_replied'], $x['taken']); $x['conv_pct'] = wq_pct($x['q_orders'], $x['queries']); } unset($x);
    $L = array_values($P); usort($L, fn($a, $b) => [$b['taken'], $b['q_orders'], $a['lost']] <=> [$a['taken'], $a['q_orders'], $b['lost']]);
    return ['ok' => true, 'days' => $days, 'total_taken' => $total, 'people' => $L];
}
/** Every customer who has not written for 30 hours gets the 'campaign' tag (owner's rule, 6 Nov: 30 h, was 24 h — a fresh query stays a New query for its first 30 hours), at most every 10 minutes.
 *  The tag stays: every later query of theirs is typed as a campaign query (wq_decide). Opted-out customers are skipped. Returns how many were newly tagged. */
function wq_window_tag(PDO $pdo): int
{
    if (wq_ts(wq_meta_get($pdo, 'camptag_at', '')) > wq_now() - 600) return 0;
    $n = 0; $cut = wq_dt(wq_now() - 30 * 3600);
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) SELECT phone, 'campaign', 'wq', DATE_ADD(last_in_at, INTERVAL 30 HOUR) FROM wa_contacts WHERE optout = 0 AND last_in_at IS NOT NULL AND last_in_at < ?");
        $st->execute([$cut]); $n = $st->rowCount();
        if ($n > 0) $pdo->exec("INSERT IGNORE INTO wa_tagidx (phone, tag) SELECT phone, tag FROM wa_ctags WHERE tag = 'campaign'");
    } catch (Throwable $e) { error_log('wq_window_tag: ' . $e->getMessage()); }
    wq_meta_put($pdo, 'camptag_at', wq_dt()); return $n;
}
/** Text added to the bot's prompt (query type + ad headline + store info + owner decisions), each part only if its helper exists; at most 2,000 characters. */
function wq_bot_context(PDO $pdo, array $c): string
{
    try {
        if (!wq_on($pdo) || !wq_tables($pdo)) return ''; $p = []; $phone = (string)$c['phone'];
        $q = $pdo->prepare("SELECT type, reason FROM wq_qtype WHERE phone = ? AND type <> 'unclear' ORDER BY query_id DESC LIMIT 1"); $q->execute([$phone]); $t = $q->fetch();
        if ($t) $p[] = 'This chat is a ' . wq_type_label((string)$t['type']) . ' query (' . mb_substr((string)$t['reason'], 0, 120) . ').';
        $ad = trim((string)($c['ad_head'] ?? '')); if ($ad !== '') $p[] = 'The customer came from the ad: ' . mb_substr($ad, 0, 150) . '.';
        if (function_exists('wa_store_text')) { $s = trim((string)wa_store_text($pdo, 'all')); if ($s !== '') $p[] = "Store info:\n" . mb_substr($s, 0, 600); }
        if (function_exists('wa_decisions_text')) { $d = trim((string)wa_decisions_text($pdo, 'all')); if ($d !== '') $p[] = "Owner decisions:\n" . mb_substr($d, 0, 800); }
        return mb_substr(implode("\n", $p), 0, 2000);
    } catch (Throwable $e) { return ''; }
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router (pinned at the end of the file; answers at include time like wa_orders.php / wa_seg.php)
$__wqa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wqa, 'wq.') === 0 && in_array($__wqa, ['wq.board', 'wq.mine', 'wq.attention', 'wq.assign', 'wq.on_duty', 'wq.queries', 'wq.query', 'wq.retype', 'wq.tags_for', 'wq.types_state', 'wq.types_next', 'wq.types_answer', 'wq.rules',
    'wq.compare', 'wq.whatwon', 'wq.whatwon_act', 'wq.summary', 'wq.analytics', 'wq.settings', 'wq.cutover', 'wq.status', 'wq.tick', 'wq.typing', 'wq.grab_list', 'wq.grab', 'wq.grab_board', 'wq.req_list', 'wq.req_take', 'wq.need_take', 'wq.need_peek', 'wq.need_dismiss', 'wq.needs_board'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available - check database permissions.'], 500);
    wq_deps($pdo); if (!wq_tables($pdo)) json_response(['error' => 'WhatsApp Sales storage is not available - check database permissions.'], 500);
    $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth); $cfg = wq_cfg($pdo); $names = wq_names($pdo);
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needGet = function () use ($isPost) { if ($isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needAdmin = function () use ($adm) { if (!$adm) json_response(['error' => 'Only Admin, HR or the control tower can do this.'], 403); };
    $days = function ($d = 1) use ($in) { $v = (int)($in['days'] ?? $d); return in_array($v, [1, 7, 30], true) ? $v : $d; };
    $phoneIn = function () use ($in) { $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); return strlen($k) >= 10 ? substr($k, -10) : ''; };
    switch ($__wqa) {
        case 'wq.board': { $needGet(); $needAdmin(); json_response(wq_board($pdo, $days(1))); }
        case 'wq.grab_list': { $needGet(); $g = wq_grab_list($pdo, $me); if (!$adm) foreach ($g['items'] as &$x0) $x0['last_in'] = ''; unset($x0); json_response($g); }   // 🔒 a salesperson sees the customer's words only after taking the chat (no picking and choosing)
        case 'wq.req_list': { $needGet(); $rl = wq_req_list($pdo, $me); if (!function_exists('wq_rescue_is_member') && is_file(__DIR__ . '/wq_rescue.php')) require_once __DIR__ . '/wq_rescue.php';   /* this router runs while wq.php is being loaded, BEFORE wq_rescue.php: load it now */ if (function_exists('wq_rescue_req_cached') && $me > 0) { try { $rl = array_merge($rl, wq_rescue_req_cached($pdo, $me)); } catch (Throwable $e) { } } json_response($rl); }   // everyone reads the customer's last message here: these chats are with nobody (or nobody answered), so the team decides from the list
        case 'wq.req_take': { $needPost(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_req_take($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r); }
        case 'wq.grab_board': { $needGet(); json_response(wq_grab_board($pdo, $days(7))); }
        case 'wq.need_peek': {   // 👀 the last messages of a chat that is in MY Needs-you-now list, read-only, so a person can see what was said before they take it
            $needGet(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $okp = false;
            foreach (wq_needs($pdo, $me) as $x) if ((string)$x['phone'] === $k) { $okp = true; break; }
            if (!$okp && !$adm) json_response(['error' => 'This chat is not in your list.'], 403);
            $q = $pdo->prepare("SELECT dir, who, mtype, body, ts FROM wa_messages WHERE phone = ? AND dir IN ('in', 'out') ORDER BY id DESC LIMIT 10"); $q->execute([$k]); $m = [];
            foreach (array_reverse($q->fetchAll(PDO::FETCH_ASSOC)) as $r) { $t = trim((string)$r['body']); if ($t === '') $t = '[' . ((string)$r['mtype'] !== '' ? $r['mtype'] : 'attachment') . ']'; $m[] = ['dir' => (string)$r['dir'], 'who' => (string)$r['who'], 'text' => mb_substr($t, 0, 400), 'ts' => (string)$r['ts']]; }
            json_response(['ok' => true, 'messages' => $m]);
        }
        case 'wq.need_take': { $needPost(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_need_take($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r); }
        case 'wq.need_dismiss': { $needPost(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_need_dismiss($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r); }
        case 'wq.needs_board': { $needGet(); $needAdmin(); json_response(wq_needs_board($pdo)); }
        case 'wq.grab': { $needPost(); $k = $phoneIn(); if ($k === '') json_response(['error' => 'Chat not found.'], 404); $r = wq_grab($pdo, $k, $me); if (isset($r['error'])) json_response($r, 409); json_response($r); }
        case 'wq.mine': { $needGet(); json_response(wq_mine($pdo, $me, $days(1))); }
        case 'wq.attention': { $needGet(); $needAdmin(); $l = wq_attention_list($pdo); json_response(['ok' => true, 'on' => (bool)$cfg['wq_on'], 'count' => count($l), 'items' => $l]); }
        case 'wq.assign': {
            $needPost(); $needAdmin(); $k = $phoneIn(); $emp = max(0, (int)($in['emp_id'] ?? 0)); if ($k === '' || !wa_contact($pdo, $k)) json_response(['error' => 'Chat not found.'], 404);
            if ($emp > 0 && !isset($names[$emp])) json_response(['error' => 'That person was not found.'], 422);
            wa_assign_to($pdo, $k, $emp, $me, 'manual'); wq_clear_attention($pdo, $k, $me, 'Assigned by an admin'); json_response(['ok' => true, 'cleared' => true]);
        }
        case 'wq.on_duty': {
            $needGet(); $now = wq_now(); $o = [];
            foreach (wq_sales_people($pdo) as $p) { $d = wq_duty_info($pdo, (int)$p['employee_id'], $now); $o[] = ['emp_id' => (int)$p['employee_id'], 'name' => (string)$p['full_name'], 'on_duty' => $d['on'], 'state' => $d['state'], 'why' => $d['why']]; }
            json_response(['ok' => true, 'at' => wq_dt($now), 'day' => wq_day_of($pdo, $now), 'people' => $o]);
        }
        case 'wq.queries': {
            $needGet(); $p = $in; if (!$adm) $p['emp_id'] = $me > 0 ? $me : -1; $p['type'] = (string)($in['type'] ?? 'all'); $r = wq_queries_list($pdo, $p); $r['on'] = (bool)$cfg['wq_on']; json_response($r);
        }
        case 'wq.query': {
            $needGet(); $qid = (int)($in['query_id'] ?? 0);
            if ($qid <= 0 && $phoneIn() !== '') { $q = $pdo->prepare("SELECT id FROM wa_queries WHERE phone = ? ORDER BY id DESC LIMIT 1"); $q->execute([$phoneIn()]); $qid = (int)$q->fetchColumn(); }
            $d = $qid > 0 ? wq_query_detail($pdo, $qid) : null; if (!$d) json_response(['error' => 'Query not found.'], 404);
            if (!$adm && ($me <= 0 || (int)$d['query']['holder']['emp_id'] !== $me)) json_response(['error' => 'This query is held by someone else.'], 403);
            $d['on'] = (bool)$cfg['wq_on']; json_response($d);
        }
        case 'wq.retype': {
            $needPost(); $needAdmin(); $qid = (int)($in['query_id'] ?? 0); [$ok, $r] = wq_retype($pdo, $qid, (string)($in['type'] ?? ''), mb_substr(trim((string)($in['reason'] ?? '')), 0, 200), $me);
            if (!$ok) json_response(['error' => $r], $r === 'Query not found.' ? 404 : 422);
            $q = wq_query_row($pdo, $qid); json_response(['ok' => true, 'type' => $r, 'type_label' => wq_type_label($r), 'tags' => wq_tags_of($pdo, (string)$q['phone'])]);
        }
        case 'wq.tags_for': {
            $needGet(); $k = $phoneIn(); $c = $k !== '' ? wa_contact($pdo, $k) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); json_response(wq_tags_for($pdo, $k));
        }
        case 'wq.typing': {
            $needPost(); $k = $phoneIn(); $c = $k !== '' ? wa_contact($pdo, $k) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403); wq_typing($pdo, $k); json_response(['ok' => true]);
        }
        case 'wq.types_state': {
            $needAdmin();
            if ($isPost) { if (!empty($in['confirm'])) { wq_meta_put($pdo, 'types_confirmed', wq_dt()); } else json_response(['error' => 'Nothing to change.'], 422); }
            $days = max(1, (int)$cfg['wq_camp_days']); $wake = max(1, (int)wq_get($pdo, 'wa_seg_wake_days', 60));
            $u = ['A customer who comes from a Meta ad counts as Meta.', 'A customer who replies within ' . $days . ' days after we sent them a campaign counts as Campaign.', 'When signs clash (ad, campaign, community tag) the most recent one wins.', 'A customer carrying the community tag counts as Community.',
                'A customer silent for ' . $wake . '+ days who writes again by themselves counts as Regenerated.', 'A brand-new customer with no other sign counts as New.', 'If nothing fits I ask you (Query types page).'];
            $rules = $pdo->query("SELECT * FROM wq_rules WHERE active = 1 ORDER BY id")->fetchAll(); foreach ($rules as $r) $u[] = 'Your rule: ' . wq_rule_label($r) . '.';
            $s = $pdo->prepare("SELECT type, auto, COUNT(*) n FROM wq_qtype WHERE st = 'ok' AND at >= ? GROUP BY type, auto"); $s->execute([wq_dt(strtotime(date('Y-m-d 00:00:00', wq_now())))]); $bt = array_fill_keys(array_keys(wq_types()), 0); $tot = 0; $au = 0;
            foreach ($s->fetchAll() as $r) { if (isset($bt[$r['type']])) $bt[$r['type']] += (int)$r['n']; $tot += (int)$r['n']; if ((int)$r['auto'] === 1) $au += (int)$r['n']; }
            $cf = wq_meta_get($pdo, 'types_confirmed', ''); json_response(['ok' => true, 'understood' => $u, 'rules_n' => count($rules), 'typed_today' => ['total' => $tot, 'auto' => $au, 'by_type' => $bt], 'pending' => wq_pending_n($pdo), 'confirmed' => $cf !== '', 'confirmed_at' => $cf !== '' ? $cf : null, 'settings' => ['wq_camp_days' => $days, 'wake_days' => $wake]]);
        }
        case 'wq.types_next': { $needGet(); $needAdmin(); $skip = array_filter(array_map('intval', explode(',', (string)($in['skip'] ?? '')))); json_response(['ok' => true, 'pending' => wq_pending_n($pdo), 'item' => wq_next_question($pdo, $skip)]); }
        case 'wq.types_answer': {
            $needPost(); $needAdmin(); $qid = (int)($in['query_id'] ?? 0);
            if (!empty($in['skip'])) { $pdo->prepare("UPDATE wq_qtype SET st = 'later' WHERE query_id = ? AND st IN ('ask','later')")->execute([$qid]); json_response(['ok' => true, 'typed' => 0, 'rule_id' => 0, 'pending' => wq_pending_n($pdo), 'next' => wq_next_question($pdo, [$qid])]); }
            $rule = is_array($in['rule'] ?? null) ? $in['rule'] : null; [$ok, $r, $code] = array_pad(wq_answer($pdo, $qid, (string)($in['type'] ?? ''), !empty($in['always']), $rule, $me), 3, 200);
            if (!$ok) json_response(['error' => $r], $code);
            if (function_exists('wq_cq_mirror')) wq_cq_mirror($pdo);
            json_response(['ok' => true, 'typed' => $r['typed'], 'rule_id' => $r['rule_id'], 'pending' => wq_pending_n($pdo), 'next' => wq_next_question($pdo, [])]);
        }
        case 'wq.rules': {
            $needAdmin();
            if ($isPost) {
                $op = (string)($in['op'] ?? '');
                if ($op === 'delete') { $d = $pdo->prepare("DELETE FROM wq_rules WHERE id = ?"); $d->execute([(int)($in['id'] ?? 0)]); if ($d->rowCount() < 1) json_response(['error' => 'Rule not found.'], 404); }
                elseif ($op === 'toggle') { $pdo->prepare("UPDATE wq_rules SET active = ? WHERE id = ?")->execute([!empty($in['active']) ? 1 : 0, (int)($in['id'] ?? 0)]); }
                elseif ($op === 'add') { [$ok, $r] = wq_rule_add($pdo, (string)($in['kind'] ?? ''), (string)($in['val'] ?? ''), (string)($in['type'] ?? ''), $me); if (!$ok) json_response(['error' => $r], 422); wq_apply_rules_to_pending($pdo); }
                else json_response(['error' => 'Use op = add, delete or toggle.'], 422);
            }
            $o = []; foreach ($pdo->query("SELECT * FROM wq_rules ORDER BY id")->fetchAll() as $r) $o[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'val' => $r['val'], 'type' => $r['type'], 'label' => wq_rule_label($r), 'hits' => (int)$r['hits'], 'created_at' => $r['created_at'], 'last_hit' => $r['last_hit'], 'active' => (int)$r['active'] === 1];
            json_response(['ok' => true, 'rules' => $o]);
        }
        case 'wq.compare': { $needGet(); $needAdmin(); $r = wq_compare($pdo, $days(7), (string)($in['type'] ?? 'all')); $r['on'] = (bool)$cfg['wq_on']; json_response($r); }
        case 'wq.whatwon': { $needGet(); $needAdmin(); json_response(wq_win_list($pdo, max(1, min(90, (int)($in['days'] ?? 14))), (string)($in['status'] ?? 'new'), (int)($in['page'] ?? 1), (int)($in['per'] ?? 20))); }
        case 'wq.whatwon_act': { $needPost(); $needAdmin(); [$ok, $r, $code] = array_pad(wq_win_act($pdo, (int)($in['id'] ?? 0), (string)($in['act'] ?? '')), 3, 200); if (!$ok) json_response(['error' => $r], $code); json_response($r); }
        case 'wq.summary': {
            $needGet(); $needAdmin(); $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['day'] ?? '')) ? (string)$in['day'] : wq_day_add(wq_day_of($pdo, wq_now()), -1);
            json_response(wq_summary_get($pdo, $day, !empty($in['rebuild'])) + ['on' => (bool)$cfg['wq_on']]);
        }
        case 'wq.analytics': { $needGet(); $needAdmin(); json_response(wq_analytics($pdo, (int)($in['days'] ?? 30), !empty($in['fresh']))); }
        case 'wq.settings': {
            if ($isPost) {
                $needAdmin(); $err = [];
                foreach (['wq_on', 'wq_auto_on'] as $k) if (array_key_exists($k, $in)) wq_put($pdo, $k, !empty($in[$k]) && (string)$in[$k] !== '0' ? '1' : '0');
                foreach (wq_limits() as $k => [$lo, $hi]) if (array_key_exists($k, $in)) { if (!is_numeric($in[$k]) || (int)$in[$k] < $lo || (int)$in[$k] > $hi) $err[] = $k . ' must be a number from ' . $lo . ' to ' . $hi . '.'; else wq_put($pdo, $k, (string)(int)$in[$k]); }
                if (array_key_exists('wq_goal_orders', $in)) { $v = $in['wq_goal_orders']; if ($v === null || $v === '') wq_put($pdo, 'wq_goal_orders', ''); elseif (is_numeric($v) && (int)$v >= 1 && (int)$v <= 500) wq_put($pdo, 'wq_goal_orders', (string)(int)$v); else $err[] = 'wq_goal_orders must be empty (no orders bar) or a number from 1 to 500.'; }
                foreach (['wq_hold_text', 'wq_esc_text'] as $k) if (array_key_exists($k, $in)) { $v = trim((string)$in[$k]); if ($v === '' || mb_strlen($v) > 400) $err[] = $k . ' must be 1 to 400 characters.'; else wq_put($pdo, $k, $v); }
                if ($err) json_response(['error' => implode(' ', $err)], 422);
                $cfg = wq_cfg($pdo);
            }
            $s = $cfg; $lim = wq_limits() + ['wq_goal_orders' => [1, 500]]; json_response(['ok' => true, 'editable' => $adm, 'settings' => $s, 'limits' => $lim]);
        }
        case 'wq.cutover': {
            $needAdmin(); $today = wq_day_of($pdo, wq_now());
            if ($isPost) { $d = trim((string)($in['date'] ?? '')); if ($d !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d))) json_response(['error' => 'Write the date as YYYY-MM-DD, or leave it empty to switch the cut-over off.'], 422); wq_put($pdo, 'wq_cutover', $d); }
            $c = wq_cutover_date($pdo); $state = $c === '' ? 'off' : ($today >= $c ? 'on' : 'before'); $dl = $c !== '' ? (int)round((strtotime($c) - strtotime($today)) / 86400) : null;
            json_response(['ok' => true, 'cutover' => $c, 'state' => $state, 'today' => $today, 'days_left' => $dl !== null && $dl > 0 ? $dl : ($c !== '' ? 0 : null), 'old_screens_read_only' => $state === 'on',
                'note' => $c === '' ? 'No cut-over date: the old typed counts are used everywhere.' : 'Days before ' . date('j M', strtotime($c)) . ' use the old typed counts; from ' . date('j M', strtotime($c)) . ' the WhatsApp counts. A day is never counted twice.']);
        }
        case 'wq.status': { $needGet(); $needAdmin(); json_response(wq_status($pdo)); }
        case 'wq.tick': { $needPost(); $needAdmin(); $t = microtime(true); $r = wq_tick($pdo, ['budget' => 8]); json_response(['ok' => true, 'result' => $r, 'ms' => (int)round((microtime(true) - $t) * 1000)]); }
    }
}
