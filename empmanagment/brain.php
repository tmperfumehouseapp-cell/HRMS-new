<?php
/**
 * brain.php — ONE company brain for the whole HRMS.   PHP 7.4 safe.
 *
 *   Three scopes of knowledge (stored in the Inbox tables sxl_facts / sxl_brain / sxl_events, which got a `scope` column — nothing already taught is lost):
 *     company   facts about the business that every Claude may know (stores, address, timings...). A company fact can be marked "team only".
 *     customer  what customers may be told + how we talk to them. Used by everything a CUSTOMER reads: Inbox, WhatsApp bot and console, Complaints replies.
 *     internal  rules only staff read: penalties, duties, who handles what, finance rules. NEVER put into anything a customer reads.
 *
 *   ONE Claude door:  brain_ai_text() / brain_ai_json() / brain_ai_text_x().  Key, model, budget, retries, empty-answer fix and the scope's knowledge are here.
 *   The old doors (convo_ai, b2_ai_json / b2_ai_text, fin_ai_text, the Dispatch call) call this file.
 *
 *   "New rule wins": everything the owner teaches here beats the older Teach-the-bot notes. The older notes are NOT deleted: they are listed in brain_old,
 *   and a note the owner replaced is left out of every prompt (brain_filter_facts / brain_filter_lessons / brain_filter_corr are called by api.php).
 *
 *   ROUTING: a Claude call that does not name a scope is placed by brain_route_table() (staff side = internal shelf; customer side only when the customer switch is LIVE, and in TRIAL the customer bot
 *   also writes each reply the new way after the answer has gone out, logged in brain_trial). Unlisted calls get no private knowledge.
 *   Actions (bd.br_*): br_route_get · br_route_set · br_trial_list · br_trial_rate · br_trial_clear · br_page · br_overview · br_facts · br_teach_read · br_teach_save · br_fact_move · br_fact_delete · br_old_replace · br_old_restore · br_import · br_summary · br_leak_test
 */
if (!function_exists('brain_ensure')) {

define('BRAIN_SCOPES', 'company,customer,internal');

function brain_now() { return date('Y-m-d H:i:s'); }
function brain_pdo() { return isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null; }
function brain_col(PDO $pdo, $table, $col, $ddl) {
    try { $pdo->query("SELECT `$col` FROM `$table` LIMIT 1")->fetchAll(); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl"); } catch (Throwable $e2) {} }
}
function brain_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return;
    $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // the Inbox tables are created here too (same layout), so the brain also works on a request that never opened the Inbox
    $pdo->exec("CREATE TABLE IF NOT EXISTS sxl_facts (id INT AUTO_INCREMENT PRIMARY KEY, topic_id INT NOT NULL DEFAULT 0, fact TEXT NOT NULL, by_name VARCHAR(80) NULL, confirmed_at DATETIME NULL, created_at DATETIME NOT NULL, KEY k_topic (topic_id))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sxl_brain (id INT AUTO_INCREMENT PRIMARY KEY, version INT NOT NULL, summary MEDIUMTEXT NULL, source VARCHAR(8) NOT NULL DEFAULT 'claude', approved_at DATETIME NULL, created_at DATETIME NOT NULL)$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sxl_events (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, topic_id INT NOT NULL DEFAULT 0, title VARCHAR(200) NOT NULL DEFAULT '', was_text TEXT NULL, now_text TEXT NULL, by_name VARCHAR(80) NULL, status VARCHAR(8) NOT NULL DEFAULT 'kept', refs TEXT NULL, created_at DATETIME NOT NULL, KEY k_at (created_at))$E");
    brain_col($pdo, 'sxl_facts', 'scope', "VARCHAR(10) NOT NULL DEFAULT 'customer'");
    brain_col($pdo, 'sxl_facts', 'area', "VARCHAR(16) NOT NULL DEFAULT 'inbox'");
    brain_col($pdo, 'sxl_facts', 'shareable', 'TINYINT NOT NULL DEFAULT 1');
    brain_col($pdo, 'sxl_facts', 'source', "VARCHAR(12) NOT NULL DEFAULT 'owner'");
    brain_col($pdo, 'sxl_brain', 'scope', "VARCHAR(10) NOT NULL DEFAULT 'customer'");
    brain_col($pdo, 'sxl_events', 'scope', "VARCHAR(10) NOT NULL DEFAULT 'customer'");
    // what was in the older Claude databases (Teach the bot) and what the owner replaced
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_old (id INT AUTO_INCREMENT PRIMARY KEY, src VARCHAR(8) NOT NULL, ref VARCHAR(40) NOT NULL DEFAULT '', hash CHAR(32) NOT NULL, line TEXT NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'kept', by_fact INT NOT NULL DEFAULT 0, why VARCHAR(300) NOT NULL DEFAULT '', at DATETIME NOT NULL, UNIQUE KEY u_line (src, hash), KEY k_status (status))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_kv (k VARCHAR(40) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL)$E");
    // which Claude calls used the brain (per day), and the customer-bot trial log (old reply vs the reply written with the customer brain)
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_use (day DATE NOT NULL, cls VARCHAR(10) NOT NULL, fn VARCHAR(40) NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (day, cls, fn))$E");
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_trial (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, channel VARCHAR(12) NOT NULL DEFAULT 'chat', customer_msg TEXT NULL, old_reply TEXT NULL, new_reply TEXT NULL, ok TINYINT NOT NULL DEFAULT 0, same_text TINYINT NOT NULL DEFAULT 0, guard_ok TINYINT NOT NULL DEFAULT 1, guard_why VARCHAR(120) NOT NULL DEFAULT '', nums_bad VARCHAR(160) NOT NULL DEFAULT '', ms INT NOT NULL DEFAULT 0, verdict VARCHAR(8) NOT NULL DEFAULT '', note VARCHAR(300) NOT NULL DEFAULT '', KEY k_at (at))$E");
    if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);   // set up once per upload of this file
}
function brain_kv_get(PDO $pdo, $k, $d = '') { try { brain_ensure($pdo); $st = $pdo->prepare("SELECT v FROM brain_kv WHERE k = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false || $v === null ? $d : (string)$v; } catch (Throwable $e) { return $d; } }
function brain_kv_set(PDO $pdo, $k, $v) { try { brain_ensure($pdo); $pdo->prepare("INSERT INTO brain_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, (string)$v]); } catch (Throwable $e) {} }
function brain_scope_ok($s) { return in_array($s, ['company', 'customer', 'internal'], true); }
function brain_short($t, $n = 240) { $t = trim(preg_replace('/\s+/u', ' ', (string)$t)); return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t; }
function brain_norm($t) { $t = mb_strtolower(trim((string)$t)); $t = preg_replace('/^\s*(?:\d+[.)]|[-•*])\s*/u', '', $t); return trim(preg_replace('/\s+/u', ' ', $t)); }
function brain_hash($t) { return md5(brain_norm($t)); }
function brain_words($t, $min = 3) { $stop = ['the' => 1, 'and' => 1, 'for' => 1, 'are' => 1, 'you' => 1, 'our' => 1, 'with' => 1, 'from' => 1, 'now' => 1, 'is' => 1, 'to' => 1, 'of' => 1, 'in' => 1, 'at' => 1, 'on' => 1, 'it' => 1, 'be' => 1, 'we' => 1, 'a' => 1]; $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$t), -1, PREG_SPLIT_NO_EMPTY); $o = []; foreach ($w as $x) if (mb_strlen($x) >= $min && ($min >= 3 || !isset($stop[$x]))) $o[$x] = 1; return $o; }

/* ============================== facts ============================== */
/** Add a fact/rule once (the same sentence is never saved twice in a scope). Returns the new id, 0 when it was already there. */
function brain_fact_add(PDO $pdo, $scope, $area, $text, $by, array $o = []) {
    brain_ensure($pdo); if (!brain_scope_ok($scope)) return 0;
    $text = trim((string)$text); if ($text === '') return 0;
    $topic = (int)($o['topic'] ?? 0); $share = $scope === 'internal' ? 0 : (isset($o['shareable']) ? ((int)$o['shareable'] ? 1 : 0) : 1);
    $key = brain_norm($text);
    foreach ($pdo->query("SELECT fact FROM sxl_facts WHERE topic_id = " . $topic . " AND scope = '" . $scope . "'")->fetchAll() as $f) if (brain_norm($f['fact']) === $key) return 0;
    $pdo->prepare("INSERT INTO sxl_facts (topic_id, fact, by_name, confirmed_at, created_at, scope, area, shareable, source) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$topic, $text, mb_substr((string)$by, 0, 80), brain_now(), brain_now(), $scope, mb_substr((string)$area, 0, 16), $share, mb_substr((string)($o['source'] ?? 'owner'), 0, 12)]);
    $id = (int)$pdo->lastInsertId();   // read BEFORE any other write (that would reset it)
    brain_dirty($pdo, $scope);
    if ($scope !== 'internal' && function_exists('kb_touch')) kb_touch($pdo, 'Company brain (' . $scope . ')');   // the customer side changed: everyone refreshes
    if ($scope !== 'internal' && empty($o['no_conflict'])) brain_queue_conflict($pdo, $id);
    return $id;
}
/** what customers are told changed: the Inbox draft replies that Claude wrote earlier are forgotten, so they are written again with the new rule (the owner sees the change at once) */
function brain_drop_drafts(PDO $pdo) {
    if (function_exists('sxl_drop_drafts')) { sxl_drop_drafts($pdo); return; }
    try { $pdo->exec("UPDATE sx_items SET ai_sugg = NULL, ai_src = '', ai_at = NULL WHERE status = 'new' AND ai_src IN ('claude','')"); } catch (Throwable $e) { /* no Inbox tables yet: nothing to forget */ }
}
/** the newest waiting Inbox messages get their new reply right now (the Inbox code does the writing) */
function brain_regen(PDO $pdo) { return function_exists('sxl_regen_top') ? (int)sxl_regen_top($pdo, 8) : 0; }
function brain_dirty(PDO $pdo, $scope, $on = true) {
    if ($scope === 'internal') { brain_kv_set($pdo, 'dirty_internal', $on ? '1' : '0'); return; }
    if ($on) brain_drop_drafts($pdo);
    if (function_exists('sxl_dirty')) { sxl_dirty($pdo, $on); } else { brain_kv_set($pdo, 'dirty_customer', $on ? '1' : '0'); }
    if ($scope === 'company' && $on) brain_kv_set($pdo, 'dirty_internal', '1');
}
/** facts of a scope for a prompt or a list. $o: topic (int|null = all), shareable_only, limit */
function brain_facts(PDO $pdo, $scope, array $o = []) {
    try {
        brain_ensure($pdo); $w = "f.scope = '" . $scope . "'"; if (!empty($o['shareable_only'])) $w .= " AND f.shareable = 1";
        if (isset($o['topic']) && $o['topic'] !== null) $w .= " AND f.topic_id = " . (int)$o['topic'];
        return $pdo->query("SELECT f.id, f.topic_id, f.fact, f.by_name, f.confirmed_at, f.created_at, f.area, f.shareable, f.source, COALESCE(t.label, '') AS label FROM sxl_facts f LEFT JOIN sxl_topics t ON t.id = f.topic_id WHERE $w ORDER BY f.id DESC LIMIT " . (int)($o['limit'] ?? 60))->fetchAll();
    } catch (Throwable $e) {
        // the topics table is missing: ask again WITHOUT the join, but with the SAME filters (a team-only fact must never become visible because of a missing table)
        $w2 = "scope = '" . $scope . "'"; if (!empty($o['shareable_only'])) $w2 .= " AND shareable = 1"; if (isset($o['topic']) && $o['topic'] !== null) $w2 .= " AND topic_id = " . (int)$o['topic'];
        try { return $pdo->query("SELECT id, topic_id, fact, by_name, confirmed_at, created_at, area, shareable, source, '' AS label FROM sxl_facts WHERE $w2 ORDER BY id DESC LIMIT " . (int)($o['limit'] ?? 60))->fetchAll(); } catch (Throwable $e2) { return []; }
    }
}

/* ============================== the summary of each scope ============================== */
function brain_summary_row(PDO $pdo, $scope) { try { brain_ensure($pdo); $st = $pdo->prepare("SELECT * FROM sxl_brain WHERE scope = ? ORDER BY id DESC LIMIT 1"); $st->execute([$scope]); $r = $st->fetch(); return $r ?: null; } catch (Throwable $e) { return null; } }
function brain_summary_text(PDO $pdo, $scope) { $b = brain_summary_row($pdo, $scope); return $b ? trim((string)$b['summary']) : ''; }
/** the internal summary (the customer summary is written by sxl_brain_build in the Inbox file) */
function brain_summary_build_internal(PDO $pdo) {
    $facts = brain_facts($pdo, 'internal', ['limit' => 40]); $co = brain_facts($pdo, 'company', ['limit' => 25]);
    $src = "COMPANY FACTS:\n- " . ($co ? implode("\n- ", array_map(function ($f) { return $f['fact']; }, $co)) : '(none yet)') . "\nTEAM-ONLY RULES AND FACTS:\n- " . ($facts ? implode("\n- ", array_map(function ($f) { return ($f['area'] !== '' ? '[' . $f['area'] . '] ' : '') . $f['fact']; }, $facts)) : '(none yet)');
    $text = brain_ai_text('internal', '', 'You write the SHORT summary of what the owner of TM Perfume House (perfumes, India) has taught his team-side Claude: the rules of the business that only staff see (penalties, duties, who handles what, finance and dispatch rules). 5 to 8 plain sentences, no bullets, no markdown, no headings. Use only the lines given; invent nothing.', $src, 700, ['ctx' => false]);
    $source = 'claude';
    if ($text === '') { $source = 'plain'; $text = 'Team rules: ' . ($facts ? implode('; ', array_slice(array_map(function ($f) { return $f['fact']; }, $facts), 0, 8)) : 'none taught yet') . '.'; }
    $last = brain_summary_row($pdo, 'internal'); $ver = $last ? (int)$last['version'] : 0;
    if (!$last || trim((string)$last['summary']) !== $text) $pdo->prepare("INSERT INTO sxl_brain (version, summary, source, created_at, scope) VALUES (?,?,?,?, 'internal')")->execute([$ver + 1, $text, $source, brain_now()]);
    if ($source === 'claude') brain_kv_set($pdo, 'dirty_internal', '0');
    return brain_summary_row($pdo, 'internal');
}
function brain_summary_fresh(PDO $pdo, $scope) {   // rebuild when something was taught since (never throws)
    try {
        if ($scope === 'internal') { $b = brain_summary_row($pdo, 'internal'); if ((!$b && brain_facts($pdo, 'internal', ['limit' => 1])) || brain_kv_get($pdo, 'dirty_internal', '0') === '1') { if (brain_ai_on()) brain_summary_build_internal($pdo); } }
        elseif (function_exists('sxl_brain_build') && function_exists('sx_state_get')) { $b = sxl_brain_row($pdo); if (!$b || sx_state_get($pdo, 'sxl_brain_dirty', '0') === '1') { if (function_exists('sxl_ai_on') && sxl_ai_on()) sxl_brain_build($pdo); } }
    } catch (Throwable $e) {}
}

/* ============================== what Claude is told ============================== */
function brain_old_replaced(PDO $pdo) {   // [src => [hash => line]] (one query per request)
    static $c = null; if ($c !== null && empty($GLOBALS['brain_old_dirty'])) return $c; $GLOBALS['brain_old_dirty'] = false; $c = ['fact' => [], 'corr' => [], 'lesson' => []];
    try { brain_ensure($pdo); foreach ($pdo->query("SELECT src, hash, line, ref FROM brain_old WHERE status = 'replaced'")->fetchAll() as $r) $c[$r['src']][$r['hash']] = $r['src'] === 'lesson' ? (string)$r['ref'] : (string)$r['line']; } catch (Throwable $e) {}
    return $c;
}
/** The context text of a scope.  $area = the part of the HRMS asking (inbox, whatsapp, complaints, boss, finance, dispatch, office, hr, store, ask).  $o: rules (add the Boss Engine rules), bot (add the Teach-the-bot facts, for the Inbox/Complaints), texts */
function brain_context(PDO $pdo, $scope, $area = '', array $o = []) {
    static $cache = []; $ck = md5($scope . '|' . $area . '|' . json_encode($o)); if (isset($cache[$ck]) && empty($GLOBALS['brain_ctx_nocache'])) return $cache[$ck];
    $out = '';
    try {
        brain_ensure($pdo);
        $co = brain_facts($pdo, 'company', ['shareable_only' => $scope === 'customer', 'limit' => 30]);
        if ($co) { $out .= "\nABOUT THE COMPANY" . ($scope === 'customer' ? ' (facts you may state to a customer)' : '') . ":"; foreach ($co as $f) $out .= "\n- " . brain_short($f['fact'], 260); }
        if ($scope === 'customer') {
            $s = brain_summary_text($pdo, 'customer'); if ($s !== '' && empty($o['no_summary'])) $out .= "\nHOW WE TALK TO CUSTOMERS (the summary the owner confirmed):\n" . $s;
            $cu = brain_facts($pdo, 'customer', ['limit' => 40]); $rules = []; $facts = [];
            foreach ($cu as $f) { if (stripos($f['fact'], 'Style rule:') === 0) { if ((int)$f['topic_id'] === 0) $rules[] = trim(substr($f['fact'], 11)); } else $facts[] = ($f['label'] !== '' ? '[' . $f['label'] . '] ' : '') . $f['fact']; }
            if (!empty($o['voice']) && function_exists('sxl_voice')) { try { $v = sxl_voice($pdo, (string)$o['voice']); if ($v) $out .= "\nOUR VOICE (follow it): " . trim((string)$v['brief']); } catch (Throwable $e) {} }
            if (!empty($o['all_customer_facts'])) {
                if ($rules) { $out .= "\nHOW WE REPLY (rules the owner taught; follow them):"; foreach (array_slice($rules, 0, 15) as $x) $out .= "\n- " . brain_short($x, 260); }
                if ($facts) { $out .= "\nFACTS THE OWNER TAUGHT (state these; invent no others):"; foreach (array_slice($facts, 0, 20) as $x) $out .= "\n- " . brain_short($x, 260); }
            }
        } elseif ($scope === 'internal') {
            $s = brain_summary_text($pdo, 'internal'); if ($s !== '') $out .= "\nWHAT THE OWNER TAUGHT ABOUT RUNNING THE BUSINESS (team only; it beats your own habits):\n" . $s;
            $in = brain_facts($pdo, 'internal', ['limit' => 40]);
            if ($in) { $out .= "\nTEAM RULES AND FACTS (never to be shown to a customer):"; foreach ($in as $f) if ($area === '' || $area === 'ask' || $f['area'] === '' || $f['area'] === 'any' || $f['area'] === $area) $out .= "\n- " . ($f['area'] !== '' && $f['area'] !== 'any' ? '[' . $f['area'] . '] ' : '') . brain_short($f['fact'], 260); }
            if (!empty($o['rules'])) { $rp = brain_rules_pack($pdo); if ($rp !== '') $out .= "\n" . $rp; }
        }
        if ($scope === 'customer' && !empty($o['bot']) && function_exists('sxl_bot_context')) { $b = sxl_bot_context($pdo, array_map('strval', (array)($o['texts'] ?? [])), 'dm'); $out .= (string)$b[0]; }
        $rep = brain_old_replaced($pdo); $lines = array_slice(array_values($rep['fact'] + $rep['corr']), 0, 8);
        if ($lines && $scope !== 'internal') { $out .= "\nOLDER NOTES THE OWNER REPLACED (they may still appear elsewhere in this prompt: do NOT use them):"; foreach ($lines as $l) $out .= "\n- " . brain_short($l, 200); }
        if (in_array($scope, ['customer', 'internal'], true) && empty($o['has_teach'])) {   // what the owner taught on the Teach board + the Price chart, for every Claude (the WhatsApp bot already has them in its facts)
            try { if (function_exists('teach_facts_get')) { $st0 = trim((string)(teach_facts_get($pdo)['style'] ?? '')); if ($st0 !== '') $out .= "\nTHE OWNER'S SELLING RULES (how we talk to and sell to customers):\n" . mb_substr($st0, 0, 6000); } } catch (Throwable $e) { }
            try { if (function_exists('teach_limits_text')) { $lt0 = trim((string)teach_limits_text($pdo)); if ($lt0 !== '') $out .= "\n" . mb_substr($lt0, 0, 4000); } } catch (Throwable $e) { }
            if (function_exists('pc_bot_text')) { $pt = pc_bot_text($pdo); if ($pt !== '') $out .= "\n" . $pt; }
        }
        if ($out !== '') $out = "\n=== COMPANY BRAIN (" . strtoupper($scope) . ") ===" . $out . "\nIf anything in this brain disagrees with an older note in this prompt, the brain wins: it is what the owner said last.\n=== END OF BRAIN ===\n";
    } catch (Throwable $e) { $out = ''; }
    return $cache[$ck] = $out;
}
/** The rules in force in the Boss Engine (penalties, Dispatch limits, system checks) as a few lines. Internal prompts only. */
function brain_rules_pack(PDO $pdo) {
    if (!function_exists('b2_ai_pack')) return '';
    try {
        $p = b2_ai_pack($pdo); $o = "TEAM RULES IN FORCE (from the Boss Engine; staff only):"; $n = 0;
        foreach ((array)($p['old_rules_now'] ?? []) as $r) { if ($n++ >= 30) break; $o .= "\n- " . ($r['name'] ?? '') . (isset($r['amount']) && $r['amount'] > 0 ? ': ₹' . rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.') : '') . (!empty($r['per']) ? ' per ' . $r['per'] : '') . (!empty($r['grace_min']) ? ', grace ' . (int)$r['grace_min'] . ' min' : ''); }
        $d = (array)($p['dispatch_limits_now'] ?? []); if ($d) $o .= "\n- Dispatch limits: tracking ID within " . ($d['tracking_hours'] ?? '?') . " h, in transit by day " . ($d['transit_day'] ?? '?') . ", courier pickup within " . ($d['courier_pickup_hours'] ?? '?') . " h";
        return mb_substr($o, 0, 3200);
    } catch (Throwable $e) { return ''; }
}
/** numbers the owner wrote in the brain (the bot may repeat them) — added to the "allowed numbers" of the WhatsApp bot */
function brain_allowed_nums(PDO $pdo) {
    $o = []; if (!function_exists('teach_nums')) return $o;
    try { foreach (array_merge(brain_facts($pdo, 'company', ['shareable_only' => true, 'limit' => 60]), brain_facts($pdo, 'customer', ['limit' => 60])) as $f) $o += teach_nums((string)$f['fact']); } catch (Throwable $e) {}
    return $o;
}

/* ============================== the scope that is active while a Claude call is made ============================== */
class BrainScope {
    private $id;
    public function __construct($scope, $area = '', array $o = []) { $this->id = (int)($GLOBALS['brain_scope_seq'] = ($GLOBALS['brain_scope_seq'] ?? 0) + 1); $GLOBALS['brain_scope_stack'][$this->id] = [$scope, $area, $o]; }
    public function __destruct() { unset($GLOBALS['brain_scope_stack'][$this->id]); }   // removes its own entry, whatever order scopes end in
}
function brain_scope_cur() { $s = $GLOBALS['brain_scope_stack'] ?? []; return $s ? end($s) : null; }
/** convo_ai (api.php) calls this: when a scope is active the brain of that scope is added to the system prompt; with no scope nothing is added (a call that does not say who reads it gets no private knowledge) */
function brain_gate_sys($sys) {
    $c = brain_scope_cur(); if (!$c) $c = brain_auto_scope();   // no explicit scope: the routing table below decides (an unlisted call gets nothing)
    if (!$c) return $sys; $pdo = brain_pdo(); if (!$pdo) return $sys;
    return $sys . "\n" . brain_context($pdo, $c[0], $c[1], $c[2]);
}

/* ============================== the ONE Claude door ============================== */
function brain_key() { if (function_exists('tmph_claude_key')) return tmph_claude_key(); $k = getenv('ANTHROPIC_API_KEY'); if (!$k && defined('ANTHROPIC_API_KEY')) $k = ANTHROPIC_API_KEY; $k = trim((string)$k); return ($k === '' || stripos($k, 'PASTE') !== false) ? '' : $k; }
function brain_ai_on() { return function_exists('brain_ai_stub') || brain_key() !== ''; }
function brain_last_error() { return (string)($GLOBALS['brain_last_error'] ?? ''); }
/** the raw call.  Returns [ok, text, why].  Tries 3 times: normal, double room, and without the "thinking" field (some models refuse it). */
function brain_claude_call($system, $user, $max, array $o = []) {
    $GLOBALS['brain_last_error'] = '';
    if (function_exists('brain_ai_stub')) { $r = brain_ai_stub($system, $user, $o); return $r === null ? [false, '', 'stub: no answer'] : [true, is_string($r) ? $r : json_encode($r), '']; }
    $key = brain_key(); if ($key === '') { $GLOBALS['brain_last_error'] = 'Claude is switched off: no Anthropic key in config.php (ANTHROPIC_API_KEY).'; return [false, '', $GLOBALS['brain_last_error']]; }
    if (!empty($GLOBALS['ai_feature']) && function_exists('ai_feature_cap_hit') && ai_feature_cap_hit((string)$GLOBALS['ai_feature'])) { $GLOBALS['brain_last_error'] = 'Daily limit of this feature is reached — it continues tomorrow.'; return [false, '', $GLOBALS['brain_last_error']]; }
    if (function_exists('tmph_over_budget') && tmph_over_budget()) { $GLOBALS['brain_last_error'] = 'The monthly AI budget is used up.'; return [false, '', $GLOBALS['brain_last_error']]; }
    $model = function_exists('tmph_cfg') ? (string)tmph_cfg('TMPH_MODEL', 'claude-sonnet-5-5') : 'claude-sonnet-5-5'; if (strpos($model, 'claude') !== 0) $model = 'claude-sonnet-5-5';
    if (!empty($o['model']) && strpos((string)$o['model'], 'claude') === 0) $model = (string)$o['model'];   // a job that needs a faster / stronger model
    // $user = a text, OR a list of content blocks (photos), OR a whole conversation [{role, content}, ...]
    if (is_array($user)) $msgs = (isset($user[0]) && is_array($user[0]) && isset($user[0]['role'])) ? array_values($user) : [['role' => 'user', 'content' => $user]];
    else $msgs = [['role' => 'user', 'content' => (string)$user]];
    $base = function_exists('tmph_cfg') ? (string)tmph_cfg('ANTHROPIC_API_BASE', 'https://api.anthropic.com') : 'https://api.anthropic.com';
    $last = '';
    $bg = function_exists('tmph_is_background') && tmph_is_background();
    if ($bg && function_exists('tmph_is_busy') && tmph_is_busy()) { $GLOBALS['brain_last_error'] = 'Claude is busy with live chats — this background job tries again in a moment.'; return [false, '', $GLOBALS['brain_last_error']]; }   // 🚦 live chats first
    $waits = $bg ? [4] : [5, 10, 15];
    foreach ([[true, $max], [true, $max * 2 + 1000], [false, $max * 2 + 1000]] as $try) {
        $payload = ['model' => $model, 'max_tokens' => max(200, (int)$try[1]), 'messages' => $msgs];
        if ($try[0]) $payload['thinking'] = ['type' => 'disabled'];   // the model must not spend the answer room on hidden thinking
        if ($system !== '') $payload['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];   // 💾 prompt caching: the same brain text in the next calls is read from Claude's cache (cheaper, and it does not eat the per-minute limit)
        if (!empty($o['web'])) $payload['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => max(1, min(20, (int)$o['web']))]];   // Claude may look things up on the internet
        $ch = curl_init(rtrim($base, '/') . '/v1/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int)($o['timeout'] ?? 70),
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
        $out = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if ($out === false) { $last = 'Could not reach Claude: ' . $err; continue; }
        $j = json_decode($out, true);
        if (!is_array($j)) { $last = 'Claude gave an unreadable answer.'; continue; }
        if (isset($j['error'])) { $last = 'Claude said: ' . (string)($j['error']['message'] ?? 'unknown error'); if (stripos($last, 'thinking') !== false) continue; if (stripos($last, 'overloaded') !== false || stripos($last, 'rate') !== false || stripos($last, 'exceed') !== false) { if (function_exists('tmph_mark_busy')) tmph_mark_busy($bg ? 40 : 25); sleep($waits ? array_shift($waits) : 2); continue; } break; }
        if (function_exists('tmph_usage_add')) { try { $u = (array)($j['usage'] ?? []); tmph_usage_add($model, (int)round((int)($u['input_tokens'] ?? 0) + 1.25 * (int)($u['cache_creation_input_tokens'] ?? 0) + 0.1 * (int)($u['cache_read_input_tokens'] ?? 0)), (int)($u['output_tokens'] ?? 0), (string)($GLOBALS['ai_feature'] ?? ($o['purpose'] ?? 'brain'))); } catch (Throwable $e) {} }
        $txt = ''; foreach ((array)($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text' && trim((string)($b['text'] ?? '')) !== '') $txt .= $b['text'];
        if (trim($txt) !== '') return [true, $txt, ''];
        $last = 'the answer was cut off (' . ($j['stop_reason'] ?? 'no reason given') . ')';
    }
    $GLOBALS['brain_last_error'] = $last !== '' ? $last : 'Claude gave no answer.'; error_log('brain: ' . $GLOBALS['brain_last_error']);
    return [false, '', $GLOBALS['brain_last_error']];
}
function brain_prepare_system($scope, $area, $system, array $o) {
    if ($scope === 'internal' && function_exists('house_rules_text') && house_rules_text() !== '' && (!isset($o['rules']) || $o['rules'])) $system .= "\nHOUSE RULES:\n" . house_rules_text();   // the owner's standing rules, for every team-side Claude
    if (!isset($o['ctx']) || $o['ctx']) { $pdo = brain_pdo(); if ($pdo && brain_scope_ok($scope)) $system .= "\n" . brain_context($pdo, $scope, $area, $o); }
    return $system;
}
/** plain text from Claude; '' when Claude is off / failed (callers keep their built-in fallback).  A customer-facing text is checked before it is returned. */
function brain_ai_text($scope, $area, $system, $user, $max = 700, array $o = []) {
    $o += ['purpose' => $scope . ':' . $area];
    list($ok, $t) = brain_claude_call(brain_prepare_system($scope, $area, $system, $o), $user, $max, $o);
    $t = $ok ? trim($t) : ''; if ($t === '') return '';
    if ($scope === 'customer' && ($o['guard'] ?? true) && brain_pdo()) { $g = brain_guard_reply(brain_pdo(), $t); if ($g[0] === false) { error_log('brain: a customer reply was held back (' . $g[1] . ')'); $GLOBALS['brain_last_error'] = 'held back: ' . $g[1]; return ''; } }
    return $t;
}
/** same, but throws (Finance wants the reason shown) */
function brain_ai_text_x($scope, $area, $system, $user, $max = 700, array $o = []) {
    $o += ['purpose' => $scope . ':' . $area];
    list($ok, $t, $why) = brain_claude_call(brain_prepare_system($scope, $area, $system, $o), $user, $max, $o);
    if (!$ok || trim($t) === '') throw new Exception($why !== '' ? $why : 'Claude returned no answer.');
    return $t;
}
/** a JSON object from Claude, or null.  Asks once more with more room when the answer is not readable. */
function brain_ai_json($scope, $area, $system, $user, $max = 1000, array $o = []) {
    $o += ['purpose' => $scope . ':' . $area];
    $sys = brain_prepare_system($scope, $area, $system . " Reply with JSON only, no other text.", $o);
    foreach ([$max, $max * 2 + 1500] as $m) {
        list($ok, $t) = brain_claude_call($sys, $user, $m, $o); if (!$ok) return null;
        if (preg_match('/\{.*\}/s', $t, $mm)) { $j = json_decode($mm[0], true); if (is_array($j)) return $j; }
    }
    error_log('brain: no usable JSON from Claude'); return null;
}

/* ============================== safety: nothing private reaches a customer ============================== */
/** 5-word pieces of every team-only text; if a customer reply holds one, the reply is held back */
function brain_private_shingles(PDO $pdo) {
    static $c = null; if ($c !== null && empty($GLOBALS['brain_ctx_nocache'])) return $c; $c = [];
    try {
        $texts = []; foreach (brain_facts($pdo, 'internal', ['limit' => 200]) as $f) $texts[] = $f['fact'];
        foreach (brain_facts($pdo, 'company', ['limit' => 200]) as $f) if (!(int)$f['shareable']) $texts[] = $f['fact'];
        $sm = brain_summary_text($pdo, 'internal'); if ($sm !== '') $texts[] = $sm;
        foreach ($texts as $t) { $w = brain_words_seq($t); for ($i = 0; $i + 4 < count($w); $i++) $c[implode(' ', array_slice($w, $i, 5))] = $t; }
    } catch (Throwable $e) {}
    return $c;
}
function brain_words_seq($t) { return preg_split('/[^\p{L}\p{N}₹%]+/u', mb_strtolower((string)$t), -1, PREG_SPLIT_NO_EMPTY); }
function brain_guard_reply(PDO $pdo, $text) {
    $sh = brain_private_shingles($pdo); if (!$sh) return [true, ''];
    $w = preg_split('/[^\p{L}\p{N}₹%]+/u', mb_strtolower((string)$text), -1, PREG_SPLIT_NO_EMPTY);
    for ($i = 0; $i + 4 < count($w); $i++) { $k = implode(' ', array_slice($w, $i, 5)); if (isset($sh[$k])) return [false, 'it repeats a team-only rule']; }
    return [true, ''];
}

/** numbers (price, %, days...) in a customer reply that are in none of the taught facts and not in the case material. [] = fine. Used by Complaints; the Inbox has its own copy of the same rule in sxl_after_ai. */
function brain_numbers_bad(PDO $pdo, $reply, $material = '') {
    if (!function_exists('teach_bad_claims') || !function_exists('teach_nums')) return [];
    try {
        $allowed = teach_nums((string)$material) + brain_allowed_nums($pdo); $on = false; $bud = null;
        if (function_exists('sxl_bot_context')) { $b = sxl_bot_context($pdo, [(string)$material], 'dm'); $allowed += (array)$b[1]; $on = (bool)$b[3]; $bud = $b[2]; }
        if (!$on) return [];
        $bad = teach_bad_claims((string)$reply, $allowed); if ($bud && function_exists('teach_budget_bad')) $bad = array_merge($bad, teach_budget_bad((string)$reply, $bud));
        return array_values(array_unique($bad));
    } catch (Throwable $e) { return []; }
}

/* ============================== the older Claude databases: registry, "new rule wins" ============================== */
function brain_old_lines(PDO $pdo) {   // the lines that are in the Teach-the-bot notes right now (raw, not filtered)
    $out = [];
    try {
        foreach ($pdo->query("SELECT k, body FROM lib_facts WHERE k IN ('style','prices','offers','rules')")->fetchAll() as $r)
            foreach (preg_split('/\R/u', (string)$r['body']) as $ln) { $ln = trim($ln); if (mb_strlen($ln) < 4) continue; $out[] = ['src' => 'fact', 'ref' => $r['k'], 'line' => $ln]; }
    } catch (Throwable $e) {}
    try {
        if (function_exists('lib_guide_row')) { $g = lib_guide_row($pdo, 'live'); $b = $g ? json_decode((string)$g['body'], true) : null;
            if (is_array($b)) foreach ((array)($b['owner_corrections'] ?? []) as $i => $c) { $l = 'When the customer says: ' . ($c['customer'] ?? '') . ' → say: ' . ($c['better'] ?? ''); $out[] = ['src' => 'corr', 'ref' => (string)$i, 'line' => $l]; } }
    } catch (Throwable $e) {}
    return $out;
}
function brain_import_old(PDO $pdo) {
    brain_ensure($pdo); $n = ['facts' => 0, 'corr' => 0, 'lessons' => 0, 'new' => 0];
    $ins = $pdo->prepare("INSERT IGNORE INTO brain_old (src, ref, hash, line, status, at) VALUES (?,?,?,?, 'kept', ?)");
    foreach (brain_old_lines($pdo) as $l) { $ins->execute([$l['src'], $l['ref'], brain_hash($l['line']), mb_substr($l['line'], 0, 2000), brain_now()]); if ($ins->rowCount()) $n['new']++; $n[$l['src'] === 'fact' ? 'facts' : 'corr']++; }
    try { $n['lessons'] = (int)$pdo->query("SELECT COUNT(*) FROM lib_teach WHERE status = 'taught'")->fetchColumn(); } catch (Throwable $e) {}
    brain_kv_set($pdo, 'imported_at', brain_now()); return $n;
}
function brain_old_list(PDO $pdo) {
    brain_ensure($pdo); if (brain_kv_get($pdo, 'imported_at', '') === '' || time() - strtotime(brain_kv_get($pdo, 'imported_at', '')) > 3600) { try { brain_import_old($pdo); } catch (Throwable $e) {} }
    $rows = $pdo->query("SELECT id, src, ref, hash, line, status, by_fact, why FROM brain_old WHERE src IN ('fact','corr') OR status = 'replaced' ORDER BY (status = 'replaced'), src, id LIMIT 400")->fetchAll();
    $live = []; foreach (brain_old_lines($pdo) as $l) $live[$l['src'] . brain_hash($l['line'])] = 1;
    $out = []; foreach ($rows as $r) { if ($r['status'] === 'kept' && !isset($live[$r['src'] . $r['hash']])) continue; $out[] = ['id' => (int)$r['id'], 'src' => $r['src'], 'ref' => $r['ref'], 'line' => $r['line'], 'status' => $r['status'], 'by_fact' => (int)$r['by_fact'], 'why' => $r['why']]; }
    return $out;
}
function brain_old_set(PDO $pdo, $id, $status, $byFact = 0, $why = '') {
    $pdo->prepare("UPDATE brain_old SET status = ?, by_fact = ?, why = ?, at = ? WHERE id = ?")->execute([$status, (int)$byFact, mb_substr((string)$why, 0, 300), brain_now(), (int)$id]); $GLOBALS['brain_old_dirty'] = true; $GLOBALS['brain_ctx_nocache'] = true;
}
/** called by api.php (teach_facts_get): the replaced lines are taken out of the notes the bot reads */
function brain_filter_facts(PDO $pdo, array $F) {
    $rep = brain_old_replaced($pdo); if (!$rep['fact']) return $F;
    foreach (['prices', 'offers', 'rules', 'style'] as $k) {
        if (empty($F[$k]) || !is_string($F[$k])) continue; $keep = [];
        foreach (preg_split('/\R/u', $F[$k]) as $ln) { if (trim($ln) !== '' && isset($rep['fact'][brain_hash($ln)])) continue; $keep[] = $ln; }
        $F[$k] = implode("\n", $keep);
    }
    return $F;
}
function brain_filter_corr(PDO $pdo, array $list) {
    $rep = brain_old_replaced($pdo); if (!$rep['corr']) return $list; $o = [];
    foreach ($list as $c) { $l = 'When the customer says: ' . ($c['customer'] ?? '') . ' → say: ' . ($c['better'] ?? ''); if (isset($rep['corr'][brain_hash($l)])) continue; $o[] = $c; }
    return $o;
}
function brain_filter_lessons(PDO $pdo, array $rows) {
    $rep = brain_old_replaced($pdo); if (!$rep['lesson']) return $rows; $ids = array_flip(array_values($rep['lesson']));
    return array_values(array_filter($rows, function ($r) use ($ids) { return !isset($ids[(string)($r['id'] ?? '')]); }));
}
function brain_queue_conflict(PDO $pdo, $factId) {
    $q = json_decode(brain_kv_get($pdo, 'conflict_q', '[]'), true); if (!is_array($q)) $q = []; $q[] = (int)$factId; brain_kv_set($pdo, 'conflict_q', json_encode(array_values(array_unique($q))));
    if (empty($GLOBALS['brain_conflict_hooked'])) { $GLOBALS['brain_conflict_hooked'] = true; register_shutdown_function(function () { if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); $p = brain_pdo(); if ($p) { try { brain_conflicts_run($p, 3); } catch (Throwable $e) {} } }); }
}
/** Claude checks every fact taught since the last check against the older notes: which of them does it contradict or make obsolete? Those are marked "replaced". */
function brain_conflicts_run(PDO $pdo, $max = 3) {
    if (!brain_ai_on()) return 0; $q = json_decode(brain_kv_get($pdo, 'conflict_q', '[]'), true); if (!is_array($q) || !$q) return 0;
    $take = array_slice($q, 0, $max); brain_kv_set($pdo, 'conflict_q', json_encode(array_slice($q, $max))); $done = 0;
    try { brain_import_old($pdo); } catch (Throwable $e) {}
    foreach ($take as $fid) {
        $st = $pdo->prepare("SELECT id, fact, scope FROM sxl_facts WHERE id = ?"); $st->execute([(int)$fid]); $f = $st->fetch(); if (!$f || $f['scope'] === 'internal') continue;
        $cand = []; $words = brain_words($f['fact'], 2); $kept = $pdo->query("SELECT id, src, line FROM brain_old WHERE status = 'kept' ORDER BY id DESC LIMIT 600")->fetchAll();
        foreach ($kept as $o) { $s = 0; foreach (brain_words($o['line'], 2) as $w => $_) if (isset($words[$w])) $s++; if ($s >= 1 || count($kept) <= 60) $cand[] = [$s, ['key' => 'o' . $o['id'], 'id' => (int)$o['id'], 'src' => 'old', 'text' => $o['line']]]; }   // a short list of older notes goes to Claude whole
        if (function_exists('teach_lessons')) { foreach (teach_lessons($pdo, $f['fact'], false) as $l) $cand[] = [1, ['key' => 'l' . $l['id'], 'id' => (int)$l['id'], 'src' => 'lesson', 'text' => 'Customer says: ' . mb_substr((string)$l['customer'], 0, 140) . ' → better: ' . mb_substr((string)$l['better'], 0, 220) . ($l['remark'] !== '' ? ' (rule: ' . mb_substr((string)$l['remark'], 0, 160) . ')' : '')]]; }
        usort($cand, function ($a, $b) { return $b[0] <=> $a[0]; }); $cand = array_slice(array_map(function ($x) { return $x[1]; }, $cand), 0, 40); if (!$cand) continue;
        $list = ''; foreach ($cand as $c) $list .= $c['key'] . ': ' . brain_short($c['text'], 320) . "\n";
        $j = brain_ai_json('customer', '', 'You compare ONE new rule of a perfume shop owner with his OLDER notes. List the older notes that the new rule directly contradicts or makes obsolete (same subject, different instruction or number). Do not list notes that only touch the same topic, or that say the same thing. Return {"replaced":["o12","l5"],"why":"one short sentence"}.', "NEW RULE (the owner said this last):\n" . $f['fact'] . "\n\nOLDER NOTES:\n" . $list, 500, ['ctx' => false, 'guard' => false]);
        if (!is_array($j) || empty($j['replaced'])) continue;
        $by = []; foreach ($cand as $c) $by[$c['key']] = $c;
        foreach ((array)$j['replaced'] as $k) { if (!isset($by[$k])) continue; $c = $by[$k];
            if ($c['src'] === 'old') { brain_old_set($pdo, $c['id'], 'replaced', (int)$f['id'], (string)($j['why'] ?? '')); $done++; }
            else { $pdo->prepare("INSERT INTO brain_old (src, ref, hash, line, status, by_fact, why, at) VALUES ('lesson', ?, ?, ?, 'replaced', ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'replaced', by_fact = VALUES(by_fact), why = VALUES(why), at = VALUES(at)")->execute([(string)$c['id'], md5('lesson' . $c['id']), mb_substr($c['text'], 0, 1500), (int)$f['id'], mb_substr((string)($j['why'] ?? ''), 0, 300), brain_now()]); $GLOBALS['brain_old_dirty'] = true; $GLOBALS['brain_ctx_nocache'] = true; $done++; }
        }
    }
    return $done;
}

/* ============================== pages: what Claude understands for the page you are on ============================== */
function brain_page_area($file) {   // page → [area, scope, label]
    $f = strtolower(basename(preg_replace('/[?#].*$/', '', (string)$file)));
    $m = [['/^(whatsapp|my_chats|wa_|whatsapp_groups)/', 'whatsapp', 'customer', 'WhatsApp and the bot'], ['/^(social|inbox)/', 'inbox', 'customer', 'Inbox'], ['/^(complaint|cx_)/', 'complaints', 'customer', 'Complaints'],
        ['/^(sales|my_sales|my_orders|orders|order_alloc)/', 'sales', 'customer', 'Sales'], ['/^(finance|ledger|lg_|my_salary|salary|commission)/', 'finance', 'internal', 'Finance'], ['/^(dept_dispatch|dispatch)/', 'dispatch', 'internal', 'Dispatch'],
        ['/^(office|dept_work|dept_fix|dept_)/', 'office', 'internal', 'Offices'], ['/^(boss|my_day|daily_summary|penalt)/', 'boss', 'internal', 'Boss Engine'], ['/^(employee|attendance|leave|people|roles|users|departments)/', 'hr', 'internal', 'People'],
        ['/^(retail|store|rs_|my_store|pos)/', 'store', 'internal', 'Store']];
    foreach ($m as $x) if (preg_match($x[0], $f)) return [$x[1], $x[2], $x[3]];
    return ['any', 'internal', 'HRMS'];
}
function brain_page_view(PDO $pdo, array $w, $page) {
    brain_ensure($pdo); list($area, $scope, $label) = brain_page_area($page); $mgr = !empty($w['manager']);
    $o = ['page' => basename(preg_replace('/[?#].*$/', '', (string)$page)), 'area' => $area, 'area_label' => $label, 'scope' => $scope, 'claude' => $scope === 'customer' ? 'Customer Claude (what customers read)' : 'Team Claude (staff only)', 'can_teach' => $mgr, 'understands' => [], 'open' => ['questions' => 0, 'edits' => 0, 'needs' => 0], 'learned' => []];
    if (!$mgr) { $o['understands'][] = $scope === 'customer' ? 'Claude writes customer replies here from what the owner taught.' : 'Claude helps the managers with this page.'; return $o; }
    $line = function ($t) { return brain_short($t, 200); };
    if ($scope === 'customer') {
        $s = brain_summary_text($pdo, 'customer'); if ($s !== '') foreach (array_slice(preg_split('/(?<=[.!?])\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY), 0, 3) as $x) $o['understands'][] = $line($x);
        foreach (array_slice(brain_facts($pdo, 'company', ['shareable_only' => true, 'limit' => 3]), 0, 2) as $f) $o['understands'][] = $line($f['fact']);
        $nf = count(brain_facts($pdo, 'customer', ['limit' => 200])); if ($nf) $o['understands'][] = $nf . ' rules and facts taught for customer replies.';
        try { $o['open']['questions'] = (int)$pdo->query("SELECT COUNT(*) FROM sxl_asked WHERE status = 'open'")->fetchColumn(); $o['open']['edits'] = (int)$pdo->query("SELECT COUNT(*) FROM sxl_examples WHERE approved = 0 AND ignored = 0 AND edited = 1")->fetchColumn(); $o['open']['needs'] = (int)$pdo->query("SELECT COUNT(*) FROM sxl_needs WHERE answered = 0")->fetchColumn(); } catch (Throwable $e) {}
    } else {
        $s = brain_summary_text($pdo, 'internal'); if ($s !== '') foreach (array_slice(preg_split('/(?<=[.!?])\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY), 0, 2) as $x) $o['understands'][] = $line($x);
        foreach (brain_facts($pdo, 'internal', ['limit' => 60]) as $f) { if (count($o['understands']) >= 6) break; if ($f['area'] === $area || $f['area'] === 'any' || $f['area'] === '') $o['understands'][] = $line($f['fact']); }
        if (function_exists('b2_page_contract')) { try { $c = b2_page_contract($pdo, $page); if ($c) foreach (array_slice((array)$c['rules'], 0, 3) as $r) $o['understands'][] = 'Rule: ' . $line($r); } catch (Throwable $e) {} }
    }
    if ($scope !== 'customer') $o['open']['questions'] = brain_q_open_count($pdo);   // what Claude asks the owner about the team side
    if (!$o['understands']) $o['understands'][] = 'Nothing taught for this part yet. Tell Claude how it should work and it will remember.';
    try { foreach ($pdo->query("SELECT title, created_at FROM sxl_events WHERE scope = '" . ($scope === 'customer' ? 'customer' : 'internal') . "' ORDER BY id DESC LIMIT 3")->fetchAll() as $e) $o['learned'][] = ['title' => $line($e['title']), 'at' => $e['created_at']]; } catch (Throwable $e) {}
    return $o;
}

/* ============================== teaching: Claude reads the sentence, shows what it understood, nothing is saved before OK ============================== */
function brain_areas() { return ['any' => 'Everywhere', 'boss' => 'Boss Engine', 'finance' => 'Finance', 'dispatch' => 'Dispatch', 'office' => 'Offices', 'hr' => 'People', 'store' => 'Store', 'sales' => 'Sales', 'whatsapp' => 'WhatsApp', 'inbox' => 'Inbox', 'complaints' => 'Complaints']; }
function brain_teach_read(PDO $pdo, $text, $scopeHint = '') {
    $text = trim((string)$text); $items = []; $say = []; $ai = false;
    $j = brain_ai_json('internal', '', 'You are the learning part of the company brain of TM Perfume House (perfumes, India). The owner or a manager tells you something. Split it into separate items and decide for EACH item who may know it. scope = "customer" (customers may be told this, or it is how we talk to customers), "company" (a plain fact about the business that every part of the system may know), "internal" (staff only: penalties, duties, who handles what, finance or dispatch rules, costs, targets). When unsure choose "internal": a wrong "customer" would be shown to customers. shareable = true only for company facts customers may be told. area = one of: any, boss, finance, dispatch, office, hr, store, sales, whatsapp, inbox, complaints. kind = "rule" (how to act or reply) or "fact" (something that is true). task = true when it asks for a new duty, a penalty or an assignment that the Boss Engine must run (the system cannot enforce it from here). Keep the owner\'s words and numbers exactly; do not add anything. Return {"understood":["2 to 5 short plain sentences saying what you understood"],"items":[{"text":"","scope":"","shareable":true,"area":"","kind":"","task":false}]}.', "WHAT HE SAID:\n" . $text . "\nBUTTON CHOSEN BY HIM (a hint only): " . ($scopeHint !== '' ? $scopeHint : 'none'), 1200, ['ctx' => false]);
    if (is_array($j) && !empty($j['items']) && is_array($j['items'])) {
        $ai = true; $say = array_slice(array_values(array_filter(array_map('strval', (array)($j['understood'] ?? [])))), 0, 5);
        foreach (array_slice($j['items'], 0, 8) as $it) {
            if (!is_array($it) || trim((string)($it['text'] ?? '')) === '') continue; $sc = brain_scope_ok((string)($it['scope'] ?? '')) ? (string)$it['scope'] : 'internal'; $area = isset(brain_areas()[(string)($it['area'] ?? '')]) ? (string)$it['area'] : 'any';
            $items[] = ['text' => brain_short($it['text'], 600), 'scope' => $sc, 'shareable' => $sc === 'internal' ? false : !empty($it['shareable']), 'area' => $area, 'kind' => ($it['kind'] ?? '') === 'rule' ? 'rule' : 'fact', 'task' => !empty($it['task'])];
        }
    }
    if (!$items) { $sc = brain_scope_ok($scopeHint) ? $scopeHint : 'internal'; $items[] = ['text' => brain_short($text, 600), 'scope' => $sc, 'shareable' => $sc === 'company', 'area' => 'any', 'kind' => 'fact', 'task' => false]; $say = ['I saved your sentence as it is (Claude could not read it just now). Check the scope below.']; }
    return ['ai' => $ai, 'understood' => $say, 'items' => $items, 'areas' => brain_areas()];
}
function brain_teach_save(PDO $pdo, array $items, $by) {
    $saved = []; $ids = [];
    foreach (array_slice($items, 0, 8) as $it) {
        $sc = (string)($it['scope'] ?? ''); if (!brain_scope_ok($sc)) continue; $t = trim((string)($it['text'] ?? '')); if ($t === '') continue; $area = isset(brain_areas()[(string)($it['area'] ?? '')]) ? (string)$it['area'] : 'any';
        if (($it['kind'] ?? '') === 'rule' && $sc === 'customer' && stripos($t, 'Style rule:') !== 0) $t = 'Style rule: ' . $t;
        $id = brain_fact_add($pdo, $sc, $area, $t, $by, ['shareable' => !empty($it['shareable']), 'source' => 'owner']);
        if ($id) { $saved[] = ['id' => $id, 'scope' => $sc, 'text' => $t]; $ids[] = $id; }
    }
    if ($saved) {
        $sc = $saved[0]['scope']; $title = count($saved) === 1 ? brain_short($saved[0]['text'], 120) : count($saved) . ' things taught';
        try { $pdo->prepare("INSERT INTO sxl_events (kind, topic_id, title, was_text, now_text, by_name, status, refs, created_at, scope) VALUES ('fact',0,?,?,?,?, 'kept', ?, ?, ?)")->execute([$title, '', brain_short(implode("\n", array_map(function ($s) { return '[' . $s['scope'] . '] ' . $s['text']; }, $saved)), 900), mb_substr((string)$by, 0, 80), json_encode(['facts' => $ids]), brain_now(), $sc === 'internal' ? 'internal' : 'customer']); } catch (Throwable $e) {}
    }
    return $saved;
}

/* ============================== the leak test ============================== */
/** Plants a secret team-only rule and a team-only company fact, builds what every customer-facing Claude would be told and what the guard would pass, and checks the secrets are nowhere in it. */
function brain_leak_test(PDO $pdo) {
    brain_ensure($pdo); $res = []; $tag = 'CANARY' . substr(md5(uniqid('', true)), 0, 6);
    $s1 = "The $tag internal penalty is rupees seven hundred seventy seven for every late parcel at dispatch"; $s2 = "The $tag supplier cost price of our best seller is rupees one hundred twelve";
    $ids = [brain_fact_add($pdo, 'internal', 'dispatch', $s1, 'leak-test', ['no_conflict' => true]), brain_fact_add($pdo, 'company', 'finance', $s2, 'leak-test', ['shareable' => false, 'no_conflict' => true])];
    $GLOBALS['brain_ctx_nocache'] = true;
    try {
        foreach (['inbox', 'whatsapp', 'complaints', '', 'sales'] as $area) {
            foreach ([[], ['all_customer_facts' => true], ['rules' => true], ['bot' => true]] as $opt) { $c = brain_context($pdo, 'customer', $area, $opt); $res[] = ['name' => 'Customer brain (' . ($area ?: 'general') . ($opt ? ', ' . implode('+', array_keys($opt)) : '') . ')', 'ok' => stripos($c, $tag) === false]; }
        }
        $GLOBALS['brain_scope_stack'][-1] = ['customer', 'whatsapp', []]; $gs = brain_gate_sys('SYSTEM'); unset($GLOBALS['brain_scope_stack'][-1]); $res[] = ['name' => 'Prompt of the WhatsApp bot (convo_ai gate)', 'ok' => stripos($gs, $tag) === false];
        $res[] = ['name' => 'Company facts marked team-only stay out of customer prompts', 'ok' => stripos(brain_context($pdo, 'customer', 'whatsapp'), 'supplier cost price') === false];
        $res[] = ['name' => 'A customer reply that repeats a team-only rule is held back', 'ok' => brain_guard_reply($pdo, 'Sure, ' . $s1 . ' ok')[0] === false];
        $res[] = ['name' => 'A normal customer reply passes the guard', 'ok' => brain_guard_reply($pdo, 'Your order ships tomorrow, thank you for waiting 🙏')[0] === true];
        $res[] = ['name' => 'The team side does know the team-only rule', 'ok' => stripos(brain_context($pdo, 'internal', 'dispatch'), $tag) !== false];
        $res[] = ['name' => 'Nothing without a scope gets private knowledge', 'ok' => (function () use ($tag) { $keep = $GLOBALS['brain_scope_stack'] ?? []; $GLOBALS['brain_scope_stack'] = []; $x = brain_gate_sys('SYSTEM'); $GLOBALS['brain_scope_stack'] = $keep; return $x === 'SYSTEM'; })()];
    } finally {
        foreach ($ids as $id) if ($id) $pdo->prepare("DELETE FROM sxl_facts WHERE id = ?")->execute([$id]);
        $GLOBALS['brain_ctx_nocache'] = false; brain_dirty($pdo, 'internal', false);
    }
    $fail = count(array_filter($res, function ($r) { return !$r['ok']; }));
    $out = ['ok' => $fail === 0, 'checks' => $res, 'failed' => $fail, 'at' => brain_now()]; brain_kv_set($pdo, 'leak_last', json_encode($out)); return $out;
}

/* ============================== a taught duty or penalty -> a draft for the Boss Engine (nothing is made before the owner presses Create) ============================== */
/** the departments of the Boss Engine with their seats (worker / checker / boss) and who sits there */
function brain_task_sections(PDO $pdo) {
    $out = [];
    try {
        foreach ($pdo->query("SELECT id, code, name, about FROM bd_sections WHERE active = 1 ORDER BY name")->fetchAll() as $s) {
            $seats = [];
            foreach (['worker' => 'Team', 'checker' => 'Checker', 'boss' => 'Boss'] as $k => $nm) {
                $sd = function_exists('bd_section_seat') ? bd_section_seat($pdo, (int)$s['id'], $k) : 0; if (!$sd) continue; $r = bd_seat_row($pdo, $sd);
                $hold = []; foreach (bd_holders($pdo, $sd) as $e) $hold[] = bd_emp_name($pdo, $e);
                $seats[] = ['id' => (int)$sd, 'kind' => $k, 'code' => (string)($r['code'] ?? ''), 'title' => (string)($r['title'] ?? $nm), 'holders' => $hold];
            }
            $out[] = ['id' => (int)$s['id'], 'code' => (string)$s['code'], 'name' => (string)$s['name'], 'about' => (string)$s['about'], 'seats' => $seats];
        }
    } catch (Throwable $e) { return []; }
    return $out;
}
/** Claude fills the whole Boss Engine form from the sentence the owner taught. Returns ['draft' => [...], 'sections' => [...]] or ['error' => '...']. */
function brain_task_draft(PDO $pdo, $text, $fix = '', array $prev = []) {
    $text = trim((string)$text); if (mb_strlen($text) < 4) return ['error' => 'Write the rule first.'];
    if (!function_exists('bd_clean_duty')) return ['error' => 'The Boss Engine is not loaded on this page. Open Boss > Add a task instead.'];
    $secs = brain_task_sections($pdo); if (!$secs) return ['error' => 'The Boss Engine has no departments yet. Open Boss > Sections first.'];
    if (!brain_ai_on()) return ['error' => 'Claude is switched off (no Anthropic key), so it cannot draft this. Open Boss > Add a task instead.'];
    $list = []; foreach ($secs as $s) { $st = []; foreach ($s['seats'] as $x) $st[] = $x['kind']; $list[] = $s['code'] . ' = ' . $s['name'] . ($s['about'] !== '' ? ' (' . brain_short($s['about'], 80) . ')' : '') . '; seats: ' . implode(', ', $st); }
    $sys = 'You turn one rule that the owner of a small Indian perfume company taught into a draft for the Boss Engine (the part that gives staff their daily work and charges penalties). Fill every field yourself; the owner only checks and presses Create. '
        . 'kind = "duty" when staff must do something on a schedule (every day at a time, weekly, monthly, once); kind = "penalty" when it is only a fine that a boss or checker flags when something was missed (no schedule). '
        . 'section = ONE code from the list. seat = worker, checker or boss: who must do it (almost always worker). title = a short plain sentence, for example "Dustbin emptied by 6 PM". why = ONE plain sentence. '
        . 'freq = daily, weekly, monthly or once; dow = day numbers for weekly (1 Monday to 7 Sunday, comma separated, else ""); dom = day of the month for monthly (else 0); due_time = HH:MM in 24 hours. '
        . 'proof = tick (the person ticks it), photo (the person sends a photo) or count (the person enters a number); photo_prompt = what the photo must show (only for photo, else ""); target_qty and unit only for count (else 0 and ""). '
        . 'amount = the fine in rupees, 0 when the owner gave none; use the owner\'s numbers and times EXACTLY and never invent an amount he did not say. per = miss (each time) or day. grace_min = minutes of grace (0 when none). mode = approve (the boss says yes first, the safe default) or auto. '
        . 'notes = up to 3 short plain sentences about anything you had to choose or could not decide (for example "He gave no time, so I used 6 PM").';
    $user = "DEPARTMENTS:\n" . implode("\n", $list) . "\n\nTHE RULE THE OWNER TAUGHT:\n" . $text . ($prev && $fix !== '' ? "\n\nYOUR EARLIER DRAFT: " . json_encode($prev, JSON_UNESCAPED_UNICODE) . "\nTHE OWNER NOW ASKS: " . $fix . "\nChange only what he asked." : '')
        . "\n\nReturn {\"kind\":\"\",\"section\":\"\",\"seat\":\"\",\"title\":\"\",\"why\":\"\",\"freq\":\"\",\"dow\":\"\",\"dom\":0,\"due_time\":\"\",\"proof\":\"\",\"photo_prompt\":\"\",\"target_qty\":0,\"unit\":\"\",\"amount\":0,\"per\":\"\",\"grace_min\":0,\"mode\":\"\",\"notes\":[]}";
    $j = brain_ai_json('internal', 'boss', $sys, $user, 900, ['rules' => true]);
    if (!is_array($j)) return ['error' => 'Claude could not draft this just now. Try again, or open Boss > Add a task.'];
    $sec = null; foreach ($secs as $s) if ($s['code'] === (string)($j['section'] ?? '')) $sec = $s; $guess = $sec === null; if ($guess) $sec = $secs[0];
    $want = in_array((string)($j['seat'] ?? ''), ['worker', 'checker', 'boss'], true) ? (string)$j['seat'] : 'worker'; $seat = null;
    foreach ($sec['seats'] as $x) if ($x['kind'] === $want) $seat = $x; if (!$seat && $sec['seats']) $seat = $sec['seats'][0];
    $c = bd_clean_duty($j + ['pen_amount' => $j['amount'] ?? 0]);
    $kind = ($j['kind'] ?? '') === 'penalty' ? 'penalty' : 'duty';
    $notes = array_slice(array_values(array_filter(array_map(function ($n) { return brain_short((string)$n, 200); }, (array)($j['notes'] ?? [])))), 0, 3);
    if ($guess) array_unshift($notes, 'I could not tell which department this belongs to. Please choose it.');
    if ($seat && !$seat['holders']) $notes[] = 'Nobody sits in "' . $seat['title'] . '" yet. Put a person there in Boss > People, or the work has no owner.';
    $d = ['kind' => $kind, 'section_id' => $sec['id'], 'seat_id' => $seat ? $seat['id'] : 0, 'title' => $c['title'], 'why' => $c['why'], 'freq' => $c['freq'], 'dow' => $c['dow'], 'dom' => $c['dom'] ?: 0, 'due_time' => $c['due_time'], 'proof' => $c['proof'],
        'photo_prompt' => $c['photo_prompt'], 'target_qty' => $c['target_qty'] ?: 0, 'cmp' => $c['cmp'], 'unit' => $c['unit'], 'pen_amount' => $c['pen_amount'], 'per' => ($j['per'] ?? '') === 'day' ? 'day' : 'miss',
        'grace_min' => max(0, min(600, (int)($j['grace_min'] ?? 0))), 'pen_mode' => ($j['mode'] ?? '') === 'auto' ? 'auto' : 'approve', 'notes' => $notes];
    return ['draft' => $d, 'sections' => $secs, 'mode' => function_exists('bd_mode') ? bd_mode() : 'shadow'];
}
/** makes the duty (or the flag-a-miss penalty) in the Boss Engine from the draft the owner confirmed. Returns [ok, message, http, extra]. */
function brain_task_create(PDO $pdo, array $w, $by, array $d) {
    foreach (['bd_clean_duty', 'bd_save_duty', 'bd_can_section'] as $fn) if (!function_exists($fn)) return [false, 'The Boss Engine is not loaded on this page.', 500, []];
    $kind = ($d['kind'] ?? '') === 'penalty' ? 'penalty' : 'duty'; $sid = (int)($d['section_id'] ?? 0);
    if (!$sid || !bd_can_section($w, $sid)) return [false, 'Choose one of your departments.', 403, []];
    $sec = $pdo->prepare("SELECT id, name FROM bd_sections WHERE id = ? AND active = 1"); $sec->execute([$sid]); $secRow = $sec->fetch(); if (!$secRow) return [false, 'That department does not exist.', 404, []];
    $seat = (int)($d['seat_id'] ?? 0); $sr = null; if ($seat) { $sr = bd_seat_row($pdo, $seat); if (!$sr || ((int)$sr['section_id'] !== $sid && $sr['section_id'] !== null)) return [false, 'That seat is in another department.', 422, []]; }
    $c = bd_clean_duty($d + ['pen_amount' => $d['pen_amount'] ?? 0]); if ($c['title'] === '' || $c['title'] === 'Untitled' || mb_strlen($c['title']) < 3) return [false, 'Give it a short name.', 422, []];
    $grace = max(0, min(600, (int)($d['grace_min'] ?? 0))); $penMode = ($d['pen_mode'] ?? '') === 'auto' ? 'auto' : 'approve';
    if ($kind === 'duty') {
        if ($c['freq'] === 'weekly' && $c['dow'] === '') $c['dow'] = '1';
        if (empty($d['force'])) foreach ($pdo->query("SELECT title, freq FROM bd_duties WHERE section_id = " . $sid . " AND status IN ('live','proposed','paused')")->fetchAll() as $x) {
            if ($x['freq'] === $c['freq'] && function_exists('bd_sim') && bd_sim($x['title'], $c['title']) >= 0.6) return [false, 'A similar duty already exists in ' . $secRow['name'] . ': “' . $x['title'] . '”. Change its name here, or edit that one in the Boss Engine.', 409, ['similar' => $x['title']]];
        }
        $canLive = (int)bd_cfg_fresh('top_boss') || !empty($w['admin']); $status = $canLive ? 'live' : 'proposed';
        $id = bd_save_duty($pdo, $sid, $seat, $c, 'teach', $status, $by);
        $pdo->prepare("UPDATE bd_duties SET grace_min = ?, pen_mode = ? WHERE id = ?")->execute([$grace, $penMode, $id]);
        if ($c['freq'] === 'once') $pdo->prepare("UPDATE bd_duties SET on_date = ? WHERE id = ?")->execute([preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['on_date'] ?? '')) ? $d['on_date'] : bd_today(), $id]);
        bd_log('duty', $id, 'created from Teach Claude: ' . $c['title'], $by);
        if ($status === 'live' && function_exists('bd_tick')) { try { bd_tick($pdo, true); } catch (Throwable $e) { error_log('brain_task_create tick: ' . $e->getMessage()); } }
        $mode = function_exists('bd_mode') ? bd_mode() : 'shadow';
        $msg = $status === 'live' ? 'Created in ' . $secRow['name'] . ' and switched on.' . ($mode === 'shadow' ? ' The Boss Engine is in test mode, so it is only recorded and nobody is charged yet.' : ($c['pen_amount'] > 0 && $penMode === 'approve' ? ' A penalty waits for your yes each time.' : '')) : 'Created in ' . $secRow['name'] . ' as a proposal. An Admin switches it on in Boss > Sections.';
        return [true, $msg, 200, ['kind' => 'duty', 'id' => $id, 'status' => $status]];
    }
    // a penalty that a boss or the checker flags when something was missed (the same record as Boss > Penalties > New penalty)
    try { $pdo->query("SELECT 1 FROM dp_rules LIMIT 1"); } catch (Throwable $e) { return [false, 'Open Boss > Penalties once first, so the penalty list exists. Then press Create again.', 409, []]; }
    if (!$sr) { $wk = bd_section_seat($pdo, $sid, 'worker'); $sr = $wk ? bd_seat_row($pdo, $wk) : null; }
    $seatCode = $sr ? (string)$sr['code'] : ''; if ($seatCode === '') return [false, 'This department has no seat to charge. Open Boss > People first.', 422, []];
    $amt = (float)$c['pen_amount']; $cap = (float)bd_cfg('pen_cap_month'); if ($amt < 0 || ($cap > 0 && $amt > $cap)) return [false, 'The amount must be between 0 and the monthly limit (' . (int)$cap . ').', 422, []];
    $st = $pdo->prepare("SELECT id FROM dp_rules WHERE LOWER(title) = LOWER(?)"); $st->execute([$c['title']]); if ($st->fetchColumn()) return [false, 'A penalty with this name already exists.', 409, []];
    $rkey = 'c_' . substr(md5($c['title'] . microtime(true)), 0, 10); $per = ($d['per'] ?? '') === 'day' ? 'day' : 'miss';
    $pdo->prepare("INSERT INTO dp_rules (rkey, title, topic, amount, per, decide, seat_code, when_text, mode, grace_min, active, sort_no, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)")
        ->execute([$rkey, $c['title'], 'custom', $amt, $per, $amt > 0 ? 0 : 1, $seatCode, 'Flagged by a boss or checker', $penMode, $grace, 100 + (int)$pdo->query("SELECT COUNT(*) FROM dp_rules")->fetchColumn(), bd_now()]);
    bd_log('rule', $rkey, 'new penalty from Teach Claude "' . $c['title'] . '" ' . $amt . ' ' . $per . ', ' . $penMode . ', seat ' . $seatCode, $by);
    return [true, 'Penalty created in ' . $secRow['name'] . '. A boss or the checker flags a miss in Boss > Penalties; it is charged by these rules' . ((function_exists('bd_mode') ? bd_mode() : 'shadow') === 'shadow' ? ' (the Boss Engine is in test mode, so it is only recorded for now).' : '.'), 200, ['kind' => 'penalty', 'rkey' => $rkey]];
}

/* ============================== Claude asks the owner what it is missing about the team side (answers become team-only facts) ============================== */
function brain_q_ensure(PDO $pdo) {
    static $d = false; if ($d) return; $d = true; if (function_exists('schema_ok') && schema_ok($pdo, __FUNCTION__)) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS brain_q (id INT AUTO_INCREMENT PRIMARY KEY, area VARCHAR(16) NOT NULL DEFAULT 'any', question VARCHAR(300) NOT NULL, why VARCHAR(300) NULL, hash CHAR(32) NOT NULL, state VARCHAR(10) NOT NULL DEFAULT 'open', answer TEXT NULL, fact_id INT NULL, created_at DATETIME NOT NULL, answered_at DATETIME NULL, UNIQUE KEY uq_bq (hash), KEY k_state (state)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('schema_set')) schema_set($pdo, __FUNCTION__, ['brain_q']);
}
function brain_q_open_count(PDO $pdo) { try { brain_q_ensure($pdo); return (int)$pdo->query("SELECT COUNT(*) FROM brain_q WHERE state = 'open'")->fetchColumn(); } catch (Throwable $e) { return 0; } }
function brain_q_list(PDO $pdo) {
    brain_q_ensure($pdo); $areas = brain_areas(); $out = [];
    foreach ($pdo->query("SELECT id, area, question, why FROM brain_q WHERE state = 'open' ORDER BY id LIMIT 3")->fetchAll() as $r) $out[] = ['id' => (int)$r['id'], 'q' => $r['question'], 'why' => (string)$r['why'], 'area' => $r['area'], 'area_label' => $areas[$r['area']] ?? 'Everywhere'];
    return $out;
}
/** what the screens need after every action: the open questions and whether Claude may ask more today */
function brain_q_state(PDO $pdo) {
    $open = brain_q_open_count($pdo); $day = brain_kv_get($pdo, 'q_day', ''); $n = $day === date('Y-m-d') ? (int)brain_kv_get($pdo, 'q_n', '0') : 0;
    return ['questions' => brain_q_list($pdo), 'can_make' => brain_ai_on() && $open < 3 && $n < 3, 'ai' => brain_ai_on()];
}
function brain_q_norm_hash($q) { $w = array_keys(brain_words($q, 3)); sort($w); return md5(implode(' ', $w)); }
function brain_q_similar($a, $b) { $x = brain_words($a, 3); $y = brain_words($b, 3); if (!$x || !$y) return false; $i = count(array_intersect_key($x, $y)); return $i / max(1, min(count($x), count($y))) >= 0.7; }
/** asks Claude for up to 3 questions (never more than 3 a day, never more than 3 open, never one that was asked, answered or skipped before). Returns [made, why-not]. */
function brain_q_make(PDO $pdo, $manual = false) {
    brain_q_ensure($pdo); $today = date('Y-m-d');
    if (!brain_ai_on()) return [0, 'Claude is switched off (no Anthropic key).'];
    $open = brain_q_open_count($pdo); if ($open >= 3) return [0, 'There are already 3 questions waiting.'];
    $day = brain_kv_get($pdo, 'q_day', ''); $n = (int)brain_kv_get($pdo, 'q_n', '0'); if ($day !== $today) { $n = 0; brain_kv_set($pdo, 'q_day', $today); brain_kv_set($pdo, 'q_n', '0'); }
    if ($n >= 3) return [0, 'Claude has already asked 3 questions today.'];
    if (!$manual && $open > 0) return [0, 'Questions are waiting.'];
    $last = (int)brain_kv_get($pdo, 'q_gen_at', '0'); if (!$manual && time() - $last < 20 * 3600) return [0, 'Asked recently.'];
    // only ONE request may ask Claude at a time (two screens opening together must not make 6 questions)
    $pdo->prepare("INSERT IGNORE INTO brain_kv (k, v) VALUES ('q_gen_at', '0')")->execute(); $cas = $pdo->prepare("UPDATE brain_kv SET v = ? WHERE k = 'q_gen_at' AND v = ?"); $cas->execute([(string)(time() > $last ? time() : $last + 1), (string)$last]);
    if ($cas->rowCount() !== 1) return [0, 'Claude is already asking.'];
    $asked = []; foreach ($pdo->query("SELECT question FROM brain_q ORDER BY id DESC LIMIT 80")->fetchAll() as $r) $asked[] = $r['question'];
    $room = min(3 - $open, 3 - $n);
    $sys = 'You are the team side of the company brain of TM Perfume House (a perfume company in Surat, India). You already know the staff-only facts and the Boss Engine rules written above. '
        . 'Find up to ' . $room . ' things about how the STAFF side of the company works that you are missing and that would help you do your work: who approves what, limits and amounts, deadlines, who handles what, what is allowed or not allowed, what to do when something goes wrong. '
        . 'Ask short, specific questions that the owner can answer in one sentence, for example "Who approves a refund above ₹2,000?". Do not ask about customers or prices shown to customers. '
        . 'Never ask something the facts or rules above already answer, and never ask anything close to the questions already asked. Never ask for passwords, bank or card numbers, ID numbers, or private details about a person. '
        . 'area = one of: ' . implode(', ', array_keys(brain_areas())) . '. why = ONE short sentence saying why you need it. If you are missing nothing important, return an empty list.';
    $j = brain_ai_json('internal', 'boss', $sys, "ALREADY ASKED (never repeat):\n" . ($asked ? '- ' . implode("\n- ", $asked) : '(nothing yet)') . "\n\nReturn {\"questions\":[{\"q\":\"\",\"area\":\"\",\"why\":\"\"}]}", 600, ['rules' => true]);
    if (!is_array($j) || empty($j['questions']) || !is_array($j['questions'])) return [0, 'Claude has nothing to ask right now.'];
    $made = 0;
    foreach ($j['questions'] as $x) {
        if ($made >= $room || !is_array($x)) continue; $q = brain_short((string)($x['q'] ?? ''), 220); if (mb_strlen($q) < 12) continue;
        $dup = false; foreach ($asked as $a) if (brain_q_similar($q, $a)) { $dup = true; break; } if ($dup) continue;
        $area = isset(brain_areas()[(string)($x['area'] ?? '')]) ? (string)$x['area'] : 'any';
        try { $pdo->prepare("INSERT INTO brain_q (area, question, why, hash, state, created_at) VALUES (?,?,?,?, 'open', ?)")->execute([$area, $q, brain_short((string)($x['why'] ?? ''), 240), brain_q_norm_hash($q), brain_now()]); $made++; $asked[] = $q; } catch (Throwable $e) { /* the same question again: skipped */ }
    }
    if ($made) brain_kv_set($pdo, 'q_n', (string)($n + $made));
    return [$made, $made ? '' : 'Claude has nothing new to ask right now.'];
}
/** the owner's answer becomes a team-only fact: the question and his answer, in his words */
function brain_q_answer(PDO $pdo, $id, $text, $by) {
    brain_q_ensure($pdo); $st = $pdo->prepare("SELECT * FROM brain_q WHERE id = ? AND state = 'open'"); $st->execute([(int)$id]); $r = $st->fetch(); if (!$r) return [false, 'This question was already answered or skipped.', 404];
    $a = trim((string)$text); if (mb_strlen($a) < 2) return [false, 'Type the answer first.', 422];
    $fact = brain_short(rtrim($r['question']) . ' ' . $a, 600); $fid = brain_fact_add($pdo, 'internal', $r['area'], $fact, $by, ['source' => 'owner', 'no_conflict' => true]);
    $pdo->prepare("UPDATE brain_q SET state = 'answered', answer = ?, fact_id = ?, answered_at = ? WHERE id = ?")->execute([mb_substr($a, 0, 600), $fid ?: null, brain_now(), (int)$id]);
    return [true, 'Saved as team-only. Claude uses it from now on.', 200];
}
function brain_q_skip(PDO $pdo, $id) { brain_q_ensure($pdo); $pdo->prepare("UPDATE brain_q SET state = 'skipped', answered_at = ? WHERE id = ? AND state = 'open'")->execute([brain_now(), (int)$id]); }

/* ============================== ROUTING: every Claude call finds its shelf here ============================== */
/** Who reads what each Claude call writes.  internal = only staff read it (it may use team-only knowledge).  customer = a customer may read it (shareable knowledge only).
 *  A call that is not listed gets NO private knowledge (that is the safe default).  [class, area, label, file, options]. Add a line here when a new Claude call is built. */
function brain_route_table() {
    return [
        // ---- staff side: managers and staff read the result ----
        'bd_photo_judge' => ['internal', 'boss', 'Boss: checks a duty photo', 'boss.php'],
        'bd_ai_draft' => ['internal', 'boss', 'Boss: drafts a duty or penalty', 'boss.php', ['rules' => true]],
        'b2_pilot_read' => ['internal', 'boss', 'Boss Engine: reads the old rules', 'boss2.php', ['rules' => true]],
        'ck_read_documents' => ['internal', 'hr', 'Document check', 'check.php'],
        'dp_repairs_tick' => ['internal', 'office', 'Repairs', 'dept_repairs.php'],
        'dp_proof_screen_check' => ['internal', 'boss', 'Department proof screen check', 'dept.php'],
        'dp_wk_shot_check' => ['internal', 'office', 'Work photo check', 'dept_work.php'],
        'ofp_suggest' => ['internal', 'office', 'Office places: suggestions', 'office_place.php'],
        'of_ai_draft' => ['internal', 'office', 'Offices: drafts', 'office.php'],
        'dp_tk_transcribe' => ['internal', 'boss', 'Tasks: voice note to text', 'dept_tasks.php'],
        'dp_tk_analyze' => ['internal', 'boss', 'Tasks: reads a task', 'dept_tasks.php'],
        'dp_tk_badge' => ['internal', 'boss', 'Tasks: badge', 'dept_tasks.php'],
        'wa_tag_contact' => ['internal', 'whatsapp', 'WhatsApp: tags a chat', 'wa.php'],
        'wag_ai_tag_one' => ['internal', 'whatsapp', 'WhatsApp: tags a customer', 'wa_growth.php'],
        'wsg_ai' => ['internal', 'whatsapp', 'WhatsApp: pulls rules out of what the owner said', 'wa_seg.php'],
        'convo_transcribe' => ['internal', 'sales', 'Sales calls: transcript', 'api.php'],
        'convo_analyze' => ['internal', 'sales', 'Sales calls: review', 'api.php'],
        'ph_ai_understand' => ['internal', 'whatsapp', 'WhatsApp photos: reads a photo', 'wa_photos.php'],
        'ph_ai_read_many' => ['internal', 'whatsapp', 'WhatsApp photos: reads several photos', 'wa_photos.php'],
        'wch_ai' => ['internal', 'sales', 'Cart: catalogue and billing questions', 'wch.php'],
        'dp_pen_ai_draft' => ['internal', 'boss', 'Departments: drafts a penalty', 'dept_custom.php', ['rules' => true]],
        'footwear_ai' => ['internal', 'store', 'Footwear', 'api.php'],
        // ---- customer side: what a customer reads (used only when the customer switch is LIVE) ----
        'lib_bot_reply' => ['customer', 'whatsapp', 'WhatsApp bot: the reply', 'api.php', ['all_customer_facts' => true, 'has_teach' => true]],
        'teach_judge' => ['customer', 'whatsapp', 'WhatsApp bot: picks the best reply', 'api.php', ['all_customer_facts' => true, 'has_teach' => true]],
        'lib_guide_write' => ['customer', 'whatsapp', 'WhatsApp bot: writes the guidebook', 'api.php', ['all_customer_facts' => true]],
        'convo_playbook_build' => ['customer', 'sales', 'Sales playbook', 'api.php', ['all_customer_facts' => true]],
        'addon_build' => ['customer', 'sales', 'Add-on offers', 'api.php'],
        'wag_brain_reply' => ['customer', 'whatsapp', 'WhatsApp: suggested replies', 'wa_growth.php', ['all_customer_facts' => true, 'has_teach' => true]],
        'wag_golive' => ['customer', 'whatsapp', 'WhatsApp: campaign messages', 'wa_growth.php'],
        'fu_write' => ['customer', 'whatsapp', 'WhatsApp: follow-up message', 'wa_follow.php', ['all_customer_facts' => true]],
        'fu_ai_draft' => ['customer', 'whatsapp', 'WhatsApp: follow-up drafts', 'wa_follow.php', ['all_customer_facts' => true]],
        'fu_ai_rewrite' => ['customer', 'whatsapp', 'WhatsApp: follow-up rewrite', 'wa_follow.php', ['all_customer_facts' => true]],
        'fu_pick_sit' => ['customer', 'whatsapp', 'WhatsApp: picks the follow-up situation', 'wa_follow.php'],
        'fu_ladder_draft' => ['customer', 'whatsapp', 'WhatsApp: quiet-chat templates', 'wa_brain.php'],
        'learn_scan' => ['customer', 'whatsapp', 'WhatsApp: finds repeated situations', 'wa_brain.php'],
        'learn_enforce_v' => ['customer', 'whatsapp', 'WhatsApp: keeps a reply inside the hard limits', 'wa_hist.php'],
        'wa_number_caps' => ['customer', 'whatsapp', 'WhatsApp: reply for the salesperson', 'wa_more.php', ['all_customer_facts' => true]],
        'wa_tpl_model' => ['customer', 'whatsapp', 'WhatsApp: template rewrite', 'wa_tpl.php'],
        'wa_tpl_utility_build' => ['customer', 'whatsapp', 'WhatsApp: utility template', 'wa_tpl.php'],
        'wsm_ai' => ['customer', 'whatsapp', 'WhatsApp: campaign set-up and messages', 'wa_smart.php'],
        'wa_prod_ai' => ['customer', 'whatsapp', 'WhatsApp: finds the perfume / photo for a customer', 'wa_prod.php'],
        'wq_ai' => ['customer', 'whatsapp', 'WhatsApp Sales: reply wording', 'wq.php'],
        'pl_ai_bullets' => ['customer', 'sales', 'Price list wording', 'price_list.php'],
    ];
}
/** the three switches (saved in brain_kv): staff = '1'|'0' · customer = 'off'|'trial'|'live' · sample = 1..100 (% of bot replies that are also written the new way during the trial) */
function brain_route_cfg(PDO $pdo) {
    static $c = null; if ($c !== null && empty($GLOBALS['brain_cfg_nocache'])) return $c;
    $st = brain_kv_get($pdo, 'route_staff', '1'); $cu = brain_kv_get($pdo, 'route_customer', 'trial'); $sa = (int)brain_kv_get($pdo, 'route_sample', '25');
    $cu = 'live';   // one shared brain: the customer shelf always reaches the WhatsApp bot, Inbox and Complaints (the old off / trial switch is no longer used)
    return $c = ['staff' => $st === '0' ? '0' : '1', 'customer' => in_array($cu, ['off', 'trial', 'live'], true) ? $cu : 'trial', 'sample' => max(1, min(100, $sa ?: 25))];
}
/** a call without an explicit BrainScope: find the function that asked and use its shelf from the table above (never throws) */
function brain_auto_scope() {
    try {
        $pdo = brain_pdo(); if (!$pdo) return null; $T = brain_route_table(); $cfg = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14) as $f) {
            $fn = (string)($f['function'] ?? ''); if ($fn === '' || !isset($T[$fn])) continue;
            $e = $T[$fn]; $cfg = $cfg ?: brain_route_cfg($pdo);
            if ($e[0] === 'internal' && $cfg['staff'] !== '1') return null;
            if ($e[0] === 'customer' && $cfg['customer'] !== 'live') return null;
            try { $pdo->prepare("INSERT INTO brain_use (day, cls, fn, n) VALUES (CURDATE(), ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1")->execute([$e[0], mb_substr($fn, 0, 40)]); } catch (Throwable $x) {}
            return [$e[0], $e[1], (array)($e[4] ?? [])];
        }
    } catch (Throwable $e) {}
    return null;
}

/* ============================== TRIAL: the customer bot writes its reply the new way too, nothing reaches the customer ============================== */
/** Call at the end of a customer reply path.  When the customer switch is "trial" and this reply is in the sample, the reply is ALSO written with the customer brain AFTER the answer has gone out
 *  (the customer never waits), checked by the leak guard and the numbers rule, and both texts are saved in brain_trial for the owner to compare.  $newFn() returns [ok, reply text, note].  Never throws. */
function brain_trial_after(PDO $pdo, $channel, $customerMsg, $oldReply, $newFn) {
    try {
        if (!empty($GLOBALS['brain_trial_running'])) return; $cfg = brain_route_cfg($pdo); if ($cfg['customer'] !== 'trial') return;
        $customerMsg = trim((string)$customerMsg); $oldReply = trim((string)$oldReply); if ($customerMsg === '' || $oldReply === '') return;
        if (mt_rand(1, 100) > $cfg['sample']) return;
        register_shutdown_function(function () use ($channel, $customerMsg, $oldReply, $newFn) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @ignore_user_abort(true); @set_time_limit(120); $GLOBALS['brain_trial_running'] = true; $p = brain_pdo(); if (!$p) return;
            try {
                $t0 = microtime(true); $r = call_user_func($newFn); $new = trim((string)($r[1] ?? '')); $ok = !empty($r[0]) && $new !== '';
                $guard = $ok ? brain_guard_reply($p, $new) : [false, 'no new reply'];
                $nums = $ok ? brain_numbers_bad($p, $new, $customerMsg) : [];
                $same = $ok && brain_norm($new) === brain_norm($oldReply);
                brain_ensure($p);
                $p->prepare("INSERT INTO brain_trial (at, channel, customer_msg, old_reply, new_reply, ok, same_text, guard_ok, guard_why, nums_bad, ms, verdict, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,'','')")
                    ->execute([brain_now(), mb_substr((string)$channel, 0, 12), mb_substr($customerMsg, 0, 1500), mb_substr($oldReply, 0, 2500), mb_substr($new, 0, 2500), $ok ? 1 : 0, $same ? 1 : 0, $guard[0] ? 1 : 0, mb_substr((string)$guard[1], 0, 120), mb_substr(implode(', ', (array)$nums), 0, 160), (int)round((microtime(true) - $t0) * 1000)]);
            } catch (Throwable $e) { error_log('brain trial: ' . $e->getMessage()); }
            $GLOBALS['brain_trial_running'] = false;
        });
    } catch (Throwable $e) {}
}
function brain_trial_stats(PDO $pdo) {
    brain_ensure($pdo); $o = ['total' => 0, 'ok' => 0, 'same' => 0, 'differ' => 0, 'guard_fail' => 0, 'nums_fail' => 0, 'better' => 0, 'worse' => 0, 'equal' => 0, 'unrated' => 0, 'avg_ms' => 0];
    try {
        $r = $pdo->query("SELECT COUNT(*) t, SUM(ok) ok, SUM(same_text) sm, SUM(ok=1 AND same_text=0) df, SUM(ok=1 AND guard_ok=0) gf, SUM(ok=1 AND nums_bad<>'') nf, SUM(verdict='better') b, SUM(verdict='worse') w, SUM(verdict='same') e, SUM(ok=1 AND verdict='') u, AVG(NULLIF(ms,0)) ms FROM brain_trial")->fetch();
        $o = ['total' => (int)$r['t'], 'ok' => (int)$r['ok'], 'same' => (int)$r['sm'], 'differ' => (int)$r['df'], 'guard_fail' => (int)$r['gf'], 'nums_fail' => (int)$r['nf'], 'better' => (int)$r['b'], 'worse' => (int)$r['w'], 'equal' => (int)$r['e'], 'unrated' => (int)$r['u'], 'avg_ms' => (int)$r['ms']];
    } catch (Throwable $e) {}
    // ready = at least 30 usable pairs, no guard or number failure, and "worse" is less than 10% of what was rated
    $rated = $o['better'] + $o['worse'] + $o['equal']; $o['ready'] = $o['ok'] >= 30 && $o['guard_fail'] === 0 && $o['nums_fail'] === 0 && ($rated === 0 || $o['worse'] / max(1, $rated) < 0.10);
    return $o;
}

/* ============================== actions ============================== */
function br_dispatch(PDO $pdo, $action, array $auth, array $w, $by, array $in, $isPost) {
    if (!brain_pdo()) $GLOBALS['pdo'] = $pdo;
    brain_ensure($pdo);
    $post = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $mgr = function () use ($w) { if (empty($w['manager'])) json_response(['error' => 'Only the owner, Admin, HR and section bosses can teach Claude.'], 403); };
    switch ($action) {
    case 'bd.br_page': {
        json_response(['ok' => true] + brain_page_view($pdo, $w, (string)($_GET['page'] ?? $in['page'] ?? '')));
    }
    case 'bd.br_overview': {
        $mgr(); $c = []; foreach (['company', 'customer', 'internal'] as $s) $c[$s] = (int)$pdo->query("SELECT COUNT(*) FROM sxl_facts WHERE scope = '" . $s . "'")->fetchColumn();
        $old = brain_old_list($pdo); $rep = count(array_filter($old, function ($x) { return $x['status'] === 'replaced'; })); $lessons = 0; try { $lessons = (int)$pdo->query("SELECT COUNT(*) FROM lib_teach WHERE status = 'taught'")->fetchColumn(); } catch (Throwable $e) {}
        json_response(['ok' => true, 'counts' => $c, 'old' => ['lines' => count($old) - $rep, 'replaced' => $rep, 'lessons' => $lessons], 'ai' => brain_ai_on(), 'leak' => json_decode(brain_kv_get($pdo, 'leak_last', 'null'), true), 'imported_at' => brain_kv_get($pdo, 'imported_at', ''), 'areas' => brain_areas()]);
    }
    case 'bd.br_facts': {
        $mgr(); $sc = (string)($_GET['scope'] ?? $in['scope'] ?? 'internal'); if (!brain_scope_ok($sc)) $sc = 'internal'; brain_summary_fresh($pdo, $sc === 'internal' ? 'internal' : 'customer');
        $rows = array_map(function ($f) { return ['id' => (int)$f['id'], 'text' => $f['fact'], 'label' => $f['label'], 'area' => $f['area'], 'shareable' => (int)$f['shareable'] === 1, 'by' => $f['by_name'], 'at' => $f['created_at'], 'source' => $f['source']]; }, brain_facts($pdo, $sc, ['limit' => 120]));
        $sum = $sc === 'internal' ? brain_summary_row($pdo, 'internal') : ($sc === 'customer' && function_exists('sxl_brain_row') ? sxl_brain_row($pdo) : null);
        json_response(['ok' => true, 'scope' => $sc, 'facts' => $rows, 'old' => $sc === 'customer' ? brain_old_list($pdo) : [], 'summary' => $sum ? ['text' => $sum['summary'], 'version' => (int)$sum['version'], 'at' => $sum['created_at']] : null, 'areas' => brain_areas()]);
    }
    case 'bd.br_teach_read': {
        $mgr(); $post(); $t = trim((string)($in['text'] ?? '')); if (mb_strlen($t) < 4) json_response(['error' => 'Write what Claude should know.'], 422);
        json_response(['ok' => true] + brain_teach_read($pdo, mb_substr($t, 0, 3000), (string)($in['scope'] ?? '')));
    }
    case 'bd.br_teach_save': {
        $mgr(); $post(); $saved = brain_teach_save($pdo, (array)($in['items'] ?? []), $by); $rw = 0; foreach ($saved as $sv0) if ($sv0['scope'] !== 'internal') { $rw = brain_regen($pdo); break; }
        json_response(['ok' => true, 'saved' => $saved, 'rewritten' => $rw, 'message' => $saved ? count($saved) . ' saved. Claude uses ' . (count($saved) === 1 ? 'it' : 'them') . ' from now on.' . ($rw ? ' ' . $rw . ' waiting repl' . ($rw === 1 ? 'y was' : 'ies were') . ' written again with it.' : '') : 'Nothing new to save (it was already taught).']);
    }
    case 'bd.br_fact_move': {   // change the scope or the "customers may be told" flag of a fact
        $mgr(); $post(); $id = (int)($in['id'] ?? 0); $sc = (string)($in['scope'] ?? ''); if (!brain_scope_ok($sc)) json_response(['error' => 'Choose who may know it.'], 422);
        $pdo->prepare("UPDATE sxl_facts SET scope = ?, shareable = ? WHERE id = ?")->execute([$sc, $sc === 'internal' ? 0 : (!empty($in['shareable']) || $sc === 'customer' ? 1 : 0), $id]); brain_dirty($pdo, 'internal'); brain_dirty($pdo, 'customer'); $GLOBALS['brain_ctx_nocache'] = true;
        json_response(['ok' => true, 'rewritten' => brain_regen($pdo)]);
    }
    case 'bd.br_fact_delete': { $mgr(); $post(); $pdo->prepare("DELETE FROM sxl_facts WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); brain_dirty($pdo, 'internal'); brain_dirty($pdo, 'customer'); json_response(['ok' => true, 'rewritten' => brain_regen($pdo)]); }
    case 'bd.br_old_replace': {   // "Replace this older note" (with a new sentence) or "Stop using this" (no sentence)
        $mgr(); $post(); $id = (int)($in['id'] ?? 0); $st = $pdo->prepare("SELECT * FROM brain_old WHERE id = ?"); $st->execute([$id]); $o = $st->fetch(); if (!$o) json_response(['error' => 'Note not found.'], 404);
        $new = trim((string)($in['text'] ?? '')); $fid = 0; if ($new !== '') { $fid = brain_fact_add($pdo, 'customer', 'whatsapp', ($in['kind'] ?? 'rule') === 'rule' ? 'Style rule: ' . $new : $new, $by, ['no_conflict' => true]); }
        brain_old_set($pdo, $id, 'replaced', $fid, $new !== '' ? 'Replaced by the owner.' : 'The owner stopped using this note.');
        json_response(['ok' => true, 'rewritten' => brain_regen($pdo)]);
    }
    case 'bd.br_old_restore': { $mgr(); $post(); brain_old_set($pdo, (int)($in['id'] ?? 0), 'kept', 0, ''); json_response(['ok' => true]); }
    case 'bd.br_import': { $mgr(); $post(); $n = brain_import_old($pdo); json_response(['ok' => true, 'imported' => $n, 'message' => $n['facts'] . ' lines of Teach-the-bot notes and ' . $n['corr'] . ' corrections are listed; ' . $n['lessons'] . ' lessons are used automatically when a message matches.']); }
    case 'bd.br_summary': { $mgr(); $post(); if (!brain_ai_on()) json_response(['error' => 'Claude is switched off (no Anthropic key).'], 422); $b = brain_summary_build_internal($pdo); json_response(['ok' => true, 'summary' => $b ? $b['summary'] : '']); }
    case 'bd.br_leak_test': { $mgr(); $post(); json_response(['ok' => true] + brain_leak_test($pdo)); }
    case 'bd.br_task_draft': {   // a taught duty / penalty -> the Boss Engine form, filled by Claude (nothing is made yet)
        $mgr(); $post(); $r = brain_task_draft($pdo, (string)($in['text'] ?? ''), mb_substr(trim((string)($in['fix'] ?? '')), 0, 300), is_array($in['draft'] ?? null) ? $in['draft'] : []);
        if (isset($r['error'])) json_response(['error' => $r['error']], 422); json_response(['ok' => true] + $r);
    }
    case 'bd.br_task_create': {   // the owner pressed Create
        $mgr(); $post(); $r = brain_task_create($pdo, $w, $by, is_array($in['draft'] ?? null) ? $in['draft'] : []);
        if (!$r[0]) json_response(array_merge(['error' => $r[1]], $r[3]), $r[2]); json_response(['ok' => true, 'message' => $r[1]] + $r[3]);
    }
    case 'bd.br_q_list': {   // the questions Claude has for the owner (it asks for new ones at most once a day, 3 at most)
        $mgr(); json_response(['ok' => true] + brain_q_state($pdo));
    }
    case 'bd.br_q_make': {   // asks Claude for new questions (auto = only when none are waiting and 20 hours passed; manual = "Ask me something")
        $mgr(); $post(); list($made, $why) = brain_q_make($pdo, !empty($in['manual']));
        json_response(['ok' => true, 'made' => $made, 'message' => $why] + brain_q_state($pdo));
    }
    case 'bd.br_q_answer': { $mgr(); $post(); $r = brain_q_answer($pdo, (int)($in['id'] ?? 0), (string)($in['text'] ?? ''), $by); if (!$r[0]) json_response(['error' => $r[1]], $r[2]); json_response(['ok' => true, 'message' => $r[1]] + brain_q_state($pdo)); }
    case 'bd.br_route_get': {   // the three switches, who is on which shelf, how much each call used the brain
        $mgr(); $cfg = brain_route_cfg($pdo); $T = brain_route_table(); $use = [];
        try { foreach ($pdo->query("SELECT fn, SUM(n) w, SUM(CASE WHEN day = CURDATE() THEN n ELSE 0 END) t FROM brain_use WHERE day >= CURDATE() - INTERVAL 6 DAY GROUP BY fn")->fetchAll() as $r) $use[$r['fn']] = [(int)$r['t'], (int)$r['w']]; } catch (Throwable $e) {}
        $sites = []; foreach ($T as $fn => $e) $sites[] = ['fn' => $fn, 'cls' => $e[0], 'area' => $e[1], 'label' => $e[2], 'file' => $e[3], 'today' => $use[$fn][0] ?? 0, 'week' => $use[$fn][1] ?? 0];
        $leak = json_decode(brain_kv_get($pdo, 'leak_last', 'null'), true);
        json_response(['ok' => true, 'cfg' => $cfg, 'sites' => $sites, 'trial' => brain_trial_stats($pdo), 'can_set' => !empty($w['admin']) || !empty($w['top']), 'leak' => $leak, 'ai' => brain_ai_on(),
            'doors' => [['Boss Engine, Departments, Finance, Dispatch, Retail Field, Social inbox', 'Already ask the brain directly (the one door).'], ['Follow-ups, Offices, Shops', 'Already tell the brain who reads the answer.']]]);
    }
    case 'bd.br_route_set': {
        $mgr(); $post(); if (empty($w['admin']) && empty($w['top'])) json_response(['error' => 'Only the owner or Admin can change this.'], 403);
        $cfg = brain_route_cfg($pdo); $log = [];
        if (isset($in['staff'])) { $v = (string)$in['staff'] === '0' ? '0' : '1'; if ($v !== $cfg['staff']) { brain_kv_set($pdo, 'route_staff', $v); $log[] = 'Staff screens ' . ($v === '1' ? 'use the brain' : 'stopped using the brain'); } }
        if (isset($in['customer'])) {
            $v = (string)$in['customer']; if (!in_array($v, ['off', 'trial', 'live'], true)) json_response(['error' => 'Choose off, trial or live.'], 422);
            if ($v === 'live') { $lk = json_decode(brain_kv_get($pdo, 'leak_last', 'null'), true); if (!is_array($lk) || empty($lk['ok']) || strtotime((string)($lk['at'] ?? '')) < time() - 86400) json_response(['error' => 'Run the leak test first (it must pass today) before customers get replies from the brain.'], 422); }
            if ($v !== $cfg['customer']) { brain_kv_set($pdo, 'route_customer', $v); $log[] = 'Customer replies: ' . $cfg['customer'] . ' → ' . $v; }
        }
        if (isset($in['sample'])) { $v = max(1, min(100, (int)$in['sample'])); if ($v !== $cfg['sample']) { brain_kv_set($pdo, 'route_sample', (string)$v); $log[] = 'Trial sample ' . $v . '%'; } }
        $GLOBALS['brain_cfg_nocache'] = true; foreach ($log as $l) { try { $pdo->prepare("INSERT INTO sxl_events (kind, topic_id, title, was_text, now_text, by_name, status, refs, created_at, scope) VALUES ('route',0,?,?,?,?, 'kept', '', ?, 'internal')")->execute([mb_substr($l, 0, 190), '', $l, mb_substr((string)$by, 0, 80), brain_now()]); } catch (Throwable $e) {} }
        json_response(['ok' => true, 'cfg' => brain_route_cfg($pdo), 'message' => $log ? implode('. ', $log) . '.' : 'Nothing changed.']);
    }
    case 'bd.br_trial_list': {   // the comparison log: filter all | differ | flag (guard / numbers failed) | unrated
        $mgr(); $f = (string)($_GET['f'] ?? $in['f'] ?? 'differ'); $wh = ['all' => '1=1', 'differ' => 'ok = 1 AND same_text = 0', 'flag' => "ok = 1 AND (guard_ok = 0 OR nums_bad <> '')", 'unrated' => "ok = 1 AND verdict = '' AND same_text = 0"][$f] ?? '1=1'; $rows = [];
        try { $rows = $pdo->query("SELECT id, at, channel, customer_msg, old_reply, new_reply, ok, same_text, guard_ok, guard_why, nums_bad, ms, verdict, note FROM brain_trial WHERE $wh ORDER BY id DESC LIMIT 40")->fetchAll(); } catch (Throwable $e) {}
        foreach ($rows as &$r) { foreach (['id', 'ok', 'same_text', 'guard_ok', 'ms'] as $k) $r[$k] = (int)$r[$k]; } unset($r);
        json_response(['ok' => true, 'rows' => $rows, 'stats' => brain_trial_stats($pdo)]);
    }
    case 'bd.br_trial_rate': {
        $mgr(); $post(); $v = (string)($in['verdict'] ?? ''); if (!in_array($v, ['better', 'same', 'worse', ''], true)) json_response(['error' => 'Choose better, same or worse.'], 422);
        $pdo->prepare("UPDATE brain_trial SET verdict = ?, note = ? WHERE id = ?")->execute([$v, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), (int)($in['id'] ?? 0)]); json_response(['ok' => true, 'stats' => brain_trial_stats($pdo)]);
    }
    case 'bd.br_trial_clear': {
        $mgr(); $post(); if (empty($w['admin']) && empty($w['top'])) json_response(['error' => 'Only the owner or Admin can clear the log.'], 403);
        $pdo->exec("DELETE FROM brain_trial"); json_response(['ok' => true, 'message' => 'The trial log was cleared.', 'stats' => brain_trial_stats($pdo)]);
    }
    case 'bd.br_q_skip': { $mgr(); $post(); brain_q_skip($pdo, (int)($in['id'] ?? 0)); json_response(['ok' => true] + brain_q_state($pdo)); }
    }
    json_response(['error' => 'Unknown brain action.'], 404);
}

}