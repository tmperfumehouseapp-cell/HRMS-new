<?php
/**
 * wa_smart.php — Smart campaigns (Claude plans a whole campaign from one line) + the Normal-campaign upgrade helpers. Loaded by wa_more.php (after wa_camp.php).
 *
 *   wa.smart_ask       POST {text, answers?{}, round?}        Claude reads the line, says what it understood and asks everything it cannot know (one form per round)
 *   wa.smart_plan      POST {id?, text, answers}              builds and stores the PLAN (groups, messages A/B/C, waves, numbers, cost, safeguards) — nothing is sent, nothing goes to Meta
 *   wa.smart_get       GET  ?id                               one plan     wa.smart_list GET  past plans     wa.smart_clone POST {id, changes?}   copy a past plan as a new draft
 *   wa.smart_edit      POST {id, group, instruction|text|swap, option?}   Claude rewrites a message / changes a group (revision logged, shows the diff)
 *   wa.smart_test      POST {id, group, option?}              sends that message to the ADMIN's own WhatsApp number only
 *   wa.smart_approve   POST {id, over_budget_ok?}             admin: validates, sends every message (+ utility twin) to every WhatsApp account once, creates and schedules the campaigns
 *   wa.smart_status    GET  ?id                               the live screen (Meta approval per version, sending progress, orders + revenue) + people list tabs
 *   wa.smart_people    GET  ?id&f&q&page   /  wa.smart_people_csv GET ?id&f
 *   wa.smart_pause / wa.smart_resume / wa.smart_stop   POST {id, group?}
 *   wa.smart_report    GET  ?id                               the final report (Claude writes what worked, numbers-only text when the AI is down)
 *   wa.camp_suggest    POST {text, goal?}                     Normal campaign: 3 marketing + 3 utility wordings of the owner's message (GET keeps answering the audience suggestions)
 *   wa.camp_speed      POST {size, speed?}                    Normal campaign: how long the sending takes, from the numbers' room and health
 * Everything is admin only (a salesperson gets 403). Kill switch: setting wa_smart_on = 0.
 * Tables: wa_smart_plans (id, title, status draft|approved|running|paused|done, brief, plan, report, created_by, created_at, updated_at, approved_at, rev, clone_of), wa_smart_rev (change log),
 *         wa_campaigns.smart_plan (the plan a campaign belongs to), index k_phone on wa_camp_items.
 * Settings: wa_smart_on (1) · wa_smart_attr_days (7) · wa_smart_min_aud (200) · wa_smart_max_touch (2; 3 for offers with a deadline) · wa_smart_util_min (30)
 * AI: every call goes through wsm_ai() (convo_ai, Claude first). Nothing runs an AI call when a panel opens; only ask / plan / edit / report / suggest do, each with a safe fallback.
 * Nothing here talks to Meta by itself: messages and templates go through wa_api() / wa_tpl_create_at() / the campaign engine.
 */
// (when a function of this file is fetched lazily from inside another function, $pdo is not in scope: the helpers still load, only the router below is skipped)
// wa_seg.php (agent G: customer stages, filters, context pack, decisions) is loaded after this file by wa_more.php; every use below checks function_exists() at call time.

if (!function_exists('wsm_tables')) {

// ------------------------------------------------------------------------------------------------------------------ small helpers
function wsm_now(): int { return function_exists('wa_camp_now') ? (int)wa_camp_now() : time(); }
function wsm_get(PDO $pdo, string $k, string $d = ''): string { try { $v = setting_get($pdo, $k, null); } catch (Throwable $e) { $v = null; } return ($v === null || $v === '') ? $d : (string)$v; }
function wsm_put(PDO $pdo, string $k, string $v): void { try { setting_set($pdo, $k, $v); } catch (Throwable $e) { } }
function wsm_j($x): string { return json_encode($x, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); }
function wsm_arr($s): array { $d = is_array($s) ? $s : json_decode((string)$s, true); return is_array($d) ? $d : []; }
function wsm_n($n): string { return number_format((float)$n, 0, '.', ','); }
function wsm_dt(int $ts): string { return date('Y-m-d H:i:s', $ts); }
function wsm_short(string $s, int $n): string { $s = trim(preg_replace('/\s+/u', ' ', $s) ?? ''); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
function wsm_on(PDO $pdo): bool { return wsm_get($pdo, 'wa_smart_on', '1') !== '0'; }
function wsm_cfg_i(PDO $pdo, string $k, int $d): int { $v = wsm_get($pdo, $k, ''); return is_numeric($v) ? (int)$v : $d; }
function wsm_in(array $ks): string { return implode(',', array_fill(0, count($ks), '?')); }
function wsm_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_smart_plans (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL DEFAULT '', status VARCHAR(10) NOT NULL DEFAULT 'draft', brief MEDIUMTEXT NULL, plan MEDIUMTEXT NULL, report MEDIUMTEXT NULL,
            created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, approved_at DATETIME NULL, rev INT NOT NULL DEFAULT 1, clone_of INT NOT NULL DEFAULT 0, KEY k_st (status, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_smart_rev (id INT AUTO_INCREMENT PRIMARY KEY, plan_id INT NOT NULL, rev INT NOT NULL DEFAULT 1, at DATETIME NULL, who VARCHAR(60) NOT NULL DEFAULT '', kind VARCHAR(20) NOT NULL DEFAULT '',
            note VARCHAR(300) NOT NULL DEFAULT '', diff MEDIUMTEXT NULL, KEY k_p (plan_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('wa_more_tables')) { try { wa_more_tables($pdo); } catch (Throwable $e) { } }   // the campaign tables must exist before we alter them
        try { $pdo->query("SELECT smart_plan FROM wa_campaigns LIMIT 0"); } catch (PDOException $e) {
            try { $pdo->exec("ALTER TABLE wa_campaigns ADD COLUMN smart_plan INT NOT NULL DEFAULT 0"); $pdo->exec("ALTER TABLE wa_campaigns ADD INDEX k_sp (smart_plan)"); } catch (PDOException $e2) { }
        }
        try { $q = $pdo->query("SHOW INDEX FROM wa_camp_items WHERE Key_name = 'k_phone'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_camp_items ADD INDEX k_phone (phone)"); } catch (PDOException $e) { }
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
/** True when wa_campaigns has the smart_plan column (the Campaigns list hides the child campaigns of a smart plan). */
function wsm_has_col(PDO $pdo): bool { static $h = null; if ($h !== null) return $h; try { $pdo->query("SELECT smart_plan FROM wa_campaigns LIMIT 0"); $h = true; } catch (PDOException $e) { $h = false; } return $h; }

/** The AI call (replaceable in tests). Returns [ok, answer|null, whyNot]. Claude first (convo_ai); nothing here calls it unless the owner pressed a button. */
function wsm_ai(PDO $pdo, string $system, string $user, array $schema, int $max = 3500): array
{
    if (function_exists('wsm_ai_hook')) return wsm_ai_hook($system, $user, $schema, $max);
    if (!function_exists('convo_ai')) return [false, null, 'The AI is not available.'];
    @set_time_limit(150);
    try { $r = convo_ai($system, [['text' => $user]], $schema, false, $max, 'claude'); return [(bool)($r[0] ?? false), $r[1] ?? null, (string)($r[2] ?? '')]; }
    catch (Throwable $e) { return [false, null, 'The AI could not answer.']; }
}
/** What Claude knows about the business: the context pack of agent G when it is there, else a small local pack. Returns ['text', 'used'[]]. */
function wsm_pack(PDO $pdo, string $purpose): array
{
    if (function_exists('wa_ctx_pack')) { try { $p = wa_ctx_pack($pdo, $purpose); if (is_array($p) && isset($p['text'])) return ['text' => (string)$p['text'], 'used' => array_values((array)($p['used'] ?? []))]; } catch (Throwable $e) { } }
    $t = ''; $used = [];
    try { if (function_exists('teach_limits_text')) { $x = teach_limits_text($pdo); if ($x !== '') { $t .= "== OWNER LIMITS ==\n" . mb_substr($x, 0, 3000) . "\n"; $used[] = 'Teach limits'; } } } catch (Throwable $e) { }
    try { if (function_exists('teach_facts_prompt')) { $x = teach_facts_prompt($pdo, []); if (!empty($x[0])) { $t .= "== STORE FACTS ==\n" . mb_substr((string)$x[0], 0, 4000) . "\n"; $used[] = 'Teach rules'; } } } catch (Throwable $e) { }
    try { if (function_exists('wa_store_text')) { $x = wa_store_text($pdo, 'all'); if ($x !== '') { $t .= "== STORE ==\n" . mb_substr($x, 0, 1200) . "\n"; $used[] = 'store info'; } } } catch (Throwable $e) { }
    try { if (function_exists('wa_camp_health')) { $nn = []; foreach (wa_camp_health($pdo) as $h) $nn[] = ($h['label'] ?? '') . ' ' . ($h['quality'] ?? ''); if ($nn) { $t .= "== NUMBERS ==\n" . implode('; ', $nn) . "\n"; $used[] = 'number health'; } } } catch (Throwable $e) { }
    try { $n = (int)$pdo->query("SELECT COUNT(*) FROM wa_templates WHERE source = 'hrms'")->fetchColumn(); if ($n) { $t .= "== MESSAGES ==\n$n pre-made messages exist.\n"; $used[] = 'message states'; } } catch (Throwable $e) { }
    return ['text' => $t, 'used' => $used];
}
function wsm_decisions(PDO $pdo): string { try { return function_exists('wa_decisions_text') ? trim((string)wa_decisions_text($pdo, 'campaign')) : ''; } catch (Throwable $e) { return ''; } }

// ------------------------------------------------------------------------------------------------------------------ audience numbers (agent G's wa_seg_count, with a fallback)
/** [total, sendable, by_stage, by_value, adds_up text, excluded]. Fast (a few tens of ms). */
function wsm_count(PDO $pdo, array $filters): array
{
    static $memo = []; $mk = md5(wsm_j($filters)); if (isset($memo[$mk])) return $memo[$mk]; if (count($memo) > 60) $memo = [];
    return $memo[$mk] = wsm_count_raw($pdo, $filters);
}
function wsm_count_raw(PDO $pdo, array $filters): array
{
    if (function_exists('wa_seg_count')) {
        try { $r = wa_seg_count($pdo, $filters); return ['total' => (int)$r['total'], 'sendable' => (int)$r['sendable'], 'by_stage' => (array)($r['by_stage'] ?? []), 'by_value' => (array)($r['by_value'] ?? []), 'adds_up' => (array)($r['adds_up'] ?? []), 'excluded' => (array)($r['excluded'] ?? []), 'unbuilt' => (int)($r['unbuilt'] ?? 0), 'seg' => true]; } catch (Throwable $e) { }
    }
    $f = []; foreach (['gtags', 'wrote_from', 'wrote_to', 'ords', 'ord', 'dsl', 'src', 'wrote', 'ign', 'st', 'exclude_bought_days', 'exclude_messaged_days'] as $k) if (isset($filters[$k]) && $filters[$k] !== '') $f[$k] = $filters[$k];
    $t = 0; $s = 0; try { if (function_exists('wa_aud_count')) { $t = wa_aud_count($pdo, 'contacts', $f); $s = wa_aud_count($pdo, 'contacts', $f, true); } } catch (Throwable $e) { }
    return ['total' => $t, 'sendable' => $s, 'by_stage' => [], 'by_value' => [], 'adds_up' => ['ok' => true, 'text' => 'Total ' . wsm_n($t) . ' = sending ' . wsm_n($s) . ' + skipped ' . wsm_n($t - $s)], 'excluded' => [], 'unbuilt' => 0, 'seg' => false];
}
function wsm_filters_clean($raw): array
{
    if (function_exists('wa_seg_filters_clean')) { try { return wa_seg_filters_clean($raw); } catch (Throwable $e) { } }
    $o = []; if (!is_array($raw)) return $o;
    foreach (['gtags'] as $k) if (is_array($raw[$k] ?? null)) $o[$k] = array_values(array_filter(array_map('strval', $raw[$k])));
    foreach (['wrote_from', 'wrote_to', 'ign', 'exclude_bought_days', 'exclude_messaged_days'] as $k) if (isset($raw[$k]) && is_numeric($raw[$k])) $o[$k] = (int)$raw[$k];
    foreach (['ord', 'dsl', 'src', 'wrote', 'ords', 'st'] as $k) if (isset($raw[$k]) && is_scalar($raw[$k]) && $raw[$k] !== '') $o[$k] = (string)$raw[$k];
    return $o;
}
function wsm_stage_labels(PDO $pdo): array
{
    if (function_exists('wsg_stage_info') && function_exists('wsg_cfg')) { try { $o = []; foreach (wsg_stage_info(wsg_cfg($pdo)) as $k => $v) $o[$k] = ['label' => $v[0], 'help' => $v[1]]; return $o; } catch (Throwable $e) { } }
    return ['warm' => ['label' => 'Warm', 'help' => 'Wrote lately'], 'active' => ['label' => 'Active', 'help' => ''], 'cooling' => ['label' => 'Cooling', 'help' => ''], 'sleeping' => ['label' => 'Sleeping', 'help' => ''], 'never_replied' => ['label' => 'Never replied', 'help' => '']];
}
function wsm_value_labels(PDO $pdo): array
{
    if (function_exists('wsg_value_info') && function_exists('wsg_cfg')) { try { $o = []; foreach (wsg_value_info(wsg_cfg($pdo)) as $k => $v) $o[$k] = ['label' => $v[0], 'help' => $v[1]]; return $o; } catch (Throwable $e) { } }
    return ['vip' => ['label' => 'Top', 'help' => ''], 'high' => ['label' => 'High', 'help' => ''], 'mid' => ['label' => 'Mid', 'help' => ''], 'low' => ['label' => 'Low', 'help' => ''], 'not_bought' => ['label' => 'Not bought yet', 'help' => '']];
}
/** Number labels for the "which number they talk to" question. */
function wsm_numbers(PDO $pdo): array { $o = []; try { foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) $o[(int)$n['id']] = (string)$n['label'] !== '' ? (string)$n['label'] : ((string)$n['display'] !== '' ? (string)$n['display'] : 'Number ' . $n['id']); } catch (Throwable $e) { } return $o; }

// ------------------------------------------------------------------------------------------------------------------ reading the owner's line (rules; Claude adds to it)
function wsm_intents(): array { return ['deadline_offer' => 'Offer with a deadline', 'check_in' => 'Friendly check-in', 'launch' => 'New launch', 'restock' => 'Back in stock', 'winback' => 'Win back quiet customers', 'info' => 'Information']; }
function wsm_rule_intent(string $t): string
{
    $s = mb_strtolower($t);
    if (preg_match('/restock|back in stock|wapas aa|available again|stock mein/u', $s)) return 'restock';
    if (preg_match('/diwali|dussehra|navratri|holi|eid|christmas|new year|valentine|raksha|black friday|sale|offer|discount|deal|coupon|% ?off|festival|ऑफर|ઓફર/u', $s)) return 'deadline_offer';
    if (preg_match('/launch|new (oud|arrival|perfume|collection|attar|gel|candle)|naya|introduc|just arrived/u', $s)) return 'launch';
    if (preg_match('/miss you|win ?back|come back|wake|old customer|quiet|long time|inactive|sleeping|purane/u', $s)) return 'winback';
    if (preg_match('/kaise ho|how are you|hello|hi sir|check.?in|namaste|hey |kem cho|how.s everything|haal/u', $s)) return 'check_in';
    return 'info';
}
function wsm_months(): array { return ['jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12]; }
/** Dates the owner wrote: ['month' => ['from','to'], 'deadline' => 'Y-m-d', 'day' => 'Y-m-d', 'weekend' => bool]. */
function wsm_text_dates(string $t, int $now): array
{
    $s = mb_strtolower($t); $o = []; $M = wsm_months(); $y = (int)date('Y', $now);
    $next = function (int $mo, int $d = 1) use ($now, $y) { $ts = mktime(0, 0, 0, $mo, $d, $y); if ($ts < strtotime(date('Y-m-d', $now))) $ts = mktime(0, 0, 0, $mo, $d, $y + 1); return $ts; };
    $mrx = implode('|', array_keys($M));
    if (preg_match('/(?:by|till|until|before|ends?|deadline|last date|tak)\s*(?:on\s*)?(\d{1,2})(?:st|nd|rd|th)?\s*(' . $mrx . ')\b/u', $s, $m) || preg_match('/(?:by|till|until|before|ends?|deadline|last date)\s*(' . $mrx . ')\s*(\d{1,2})/u', $s, $m2)) {
        if (!empty($m)) { $d = (int)$m[1]; $mo = $M[$m[2]]; } else { $d = (int)$m2[2]; $mo = $M[$m2[1]]; }
        if ($d >= 1 && $d <= 31) $o['deadline'] = date('Y-m-d', $next($mo, $d));
    }
    if (!isset($o['deadline']) && preg_match('/\b(' . $mrx . ')\b/u', $s, $m)) { $mo = $M[$m[1]]; $from = $next($mo, 1); $o['month'] = ['from' => date('Y-m-d', $from), 'to' => date('Y-m-t', $from)]; }
    if (preg_match('/\b(tomorrow|kal)\b/u', $s)) $o['day'] = date('Y-m-d', $now + 86400); elseif (preg_match('/\b(today|aaj|abhi|now)\b/u', $s)) $o['day'] = date('Y-m-d', $now);
    if (preg_match('/weekend/u', $s)) $o['weekend'] = true;
    if (preg_match('/next week/u', $s)) $o['next_week'] = true;
    return $o;
}
/** The Claude reading of the line. Returns the normalized "understood" array. Claude may add to the rules (a better intent, a deadline, wording hints); the rules are the safe fallback. */
function wsm_understand(PDO $pdo, string $text, array $answers): array
{
    $now = wsm_now(); $intent = wsm_rule_intent($text); $dates = wsm_text_dates($text, $now); $source = 'rules'; $line = ''; $why = []; $sug = [];
    $sch = ['type' => 'OBJECT', 'properties' => ['intent' => ['type' => 'STRING', 'enum' => array_keys(wsm_intents())], 'deadline' => ['type' => 'STRING'], 'month' => ['type' => 'INTEGER'], 'goal' => ['type' => 'STRING'], 'words' => ['type' => 'STRING'],
        'understood_line' => ['type' => 'STRING'], 'wants_offer' => ['type' => 'BOOLEAN'], 'why' => ['type' => 'OBJECT'], 'suggest' => ['type' => 'OBJECT']], 'required' => ['intent', 'understood_line']];
    $pk = wsm_pack($pdo, 'campaign_ask'); $dec = wsm_decisions($pdo);
    $sys = "You help the owner of TM Perfume House (Surat, India) set up a WhatsApp campaign from ONE short line. Say which kind of campaign it is (intent) and, in one friendly sentence, how you understood it. Never invent a date: only give 'deadline' (YYYY-MM-DD) when the owner wrote one. " .
        "Customers write English, Hindi, Gujarati and Hinglish. Today is " . date('Y-m-d', $now) . ".\n" . $pk['text'] . ($dec !== '' ? "\nTHE OWNER'S DECISIONS (always obey):\n" . $dec : '');
    [$ok, $v] = wsm_ai($pdo, $sys, "The owner wrote: " . mb_substr($text, 0, 600) . "\nAnswers so far: " . wsm_j($answers), $sch, 1500);
    if ($ok && is_array($v)) {
        $source = 'claude'; $i = (string)($v['intent'] ?? ''); if (isset(wsm_intents()[$i])) $intent = $i; $line = mb_substr(trim((string)($v['understood_line'] ?? '')), 0, 400);
        if (!empty($v['deadline']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v['deadline']) && strtotime((string)$v['deadline']) >= strtotime(date('Y-m-d', $now))) $dates['deadline'] = (string)$v['deadline'];
        $why = is_array($v['why'] ?? null) ? $v['why'] : []; $sug = is_array($v['suggest'] ?? null) ? $v['suggest'] : [];
    }
    return ['intent' => $intent, 'dates' => $dates, 'line' => $line, 'source' => $source, 'why' => $why, 'sug' => $sug, 'used' => $pk['used'], 'words' => wsm_words($text)];
}
function wsm_words(string $t): string { return preg_match('/[\x{0900}-\x{097F}]/u', $t) ? 'Hindi' : (preg_match('/\b(kaise|mein|hai|bhejna|karo|aap|sir|ji|nahi)\b/iu', $t) ? 'Hinglish' : 'English'); }

// ------------------------------------------------------------------------------------------------------------------ the questions (one form per round)
/** The rules for what a campaign needs; Claude's reading only fills in the suggestions. Never asks what the data tells (language per customer, tone, number of messages, utility split, timeline shape, send hours). */
function wsm_forbidden_ids(): array { return ['language', 'tone', 'n_messages', 'utility_split', 'timeline', 'send_hours', 'send_time']; }
function wsm_default_hours(): array { return [[10, 13], [16, 20]]; }
function wsm_hours_label(array $h): string { $f = fn($x) => ((int)$x % 12 ?: 12) . ((int)$x >= 12 ? ' pm' : ' am'); return $f($h[0]) . '–' . $f($h[1]); }
function wsm_date_nice(string $d): string { $t = strtotime($d); return $t ? date('j M', $t) : $d; }
function wsm_dates_options(array $U, int $now, string $intent): array
{
    $o = []; $D = $U['dates'];
    if (!empty($D['month'])) {
        $f = strtotime($D['month']['from']); $mn = date('M', $f); $last = (int)date('t', $f);
        foreach ([[1, 5, '', false], [3, 7, '', true], [8, 14, '', false]] as [$a, $b, , $sg]) $o[] = ['v' => date('Y-m', $f) . sprintf('-%02d', $a) . '..' . date('Y-m', $f) . sprintf('-%02d', $b), 'label' => $mn . ' ' . $a . '–' . $b, 'suggested' => $sg];
        $o[] = ['v' => $D['month']['from'] . '..' . $D['month']['to'], 'label' => 'All ' . date('F', $f), 'suggested' => false];
    } else {
        $d0 = date('Y-m-d', $now); $d1 = date('Y-m-d', $now + 86400);
        $o[] = ['v' => $d0 . '..' . $d0, 'label' => 'Today', 'suggested' => !empty($D['day']) && $D['day'] === $d0]; $o[] = ['v' => $d1 . '..' . $d1, 'label' => 'Tomorrow', 'suggested' => !empty($D['day']) && $D['day'] === $d1];
        $o[] = ['v' => $d1 . '..' . date('Y-m-d', $now + 7 * 86400), 'label' => 'Next 7 days', 'suggested' => empty($D['day'])];
        $o[] = ['v' => $d1 . '..' . date('Y-m-d', $now + 14 * 86400), 'label' => 'Next 14 days', 'suggested' => false];
    }
    $o[] = ['v' => 'pick', 'label' => 'Pick dates…', 'suggested' => false, 'pick' => true]; return $o;
}
/** The whole question list for this round. $eff = the effective answers (answers, else Claude's suggestions) — used for the follow-up round. */
function wsm_questions(PDO $pdo, array $U, array $answers, int $round, array $eff): array
{
    $now = wsm_now(); $intent = $U['intent']; $why = $U['why']; $sug = $U['sug']; $Q = [];
    $w = function (string $id, string $def) use ($why) { $x = trim((string)($why[$id] ?? '')); return $x !== '' ? mb_substr($x, 0, 220) : $def; };
    $add = function (array $q) use (&$Q, $answers) { $q['answered'] = array_key_exists($q['id'], $answers) && $answers[$q['id']] !== 'claude' && $answers[$q['id']] !== null; $q['decided'] = ($answers[$q['id']] ?? null) === 'claude'; $q['value'] = $answers[$q['id']] ?? null; $Q[] = $q; };
    $dOpts = wsm_dates_options($U, $now, $intent); $dSug = ''; foreach ($dOpts as $o) if (!empty($o['suggested'])) { $dSug = $o['v']; break; }
    $hOpts = [['v' => '10-13', 'label' => '10 am–1 pm', 'suggested' => true], ['v' => '13-16', 'label' => '1–4 pm', 'suggested' => false], ['v' => '16-20', 'label' => '4–8 pm', 'suggested' => true], ['v' => '8-21', 'label' => 'Any time 8 am–9 pm', 'suggested' => false]];
    $add(['id' => 'dates', 'group' => 1, 'label' => 'Dates', 'kind' => 'single', 'options' => $dOpts, 'suggest' => $dSug, 'why' => $w('dates', !empty($U['dates']['month']) ? 'You said the month; the first week gets the most replies.' : 'Short windows keep the message fresh.')]);
    if ($intent === 'deadline_offer') $add(['id' => 'deadline', 'group' => 1, 'label' => 'When does the offer end?', 'kind' => 'date', 'options' => [['v' => '+3', 'label' => 'In 3 days'], ['v' => '+7', 'label' => 'In a week'], ['v' => '+14', 'label' => 'In 2 weeks'], ['v' => 'pick', 'label' => 'Pick date…', 'pick' => true]],
        'suggest' => !empty($U['dates']['deadline']) ? $U['dates']['deadline'] : '', 'required' => true, 'why' => $w('deadline', 'Claude plans the reminders backwards from this day.')]);
    $add(['id' => 'hours', 'group' => 1, 'label' => 'Time of day', 'kind' => 'multi', 'options' => $hOpts, 'suggest' => ['10-13', '16-20'], 'why' => $w('hours', 'Each customer still gets it near the hour they usually write; nothing goes out at night.')]);
    // who
    $cnt = wsm_count($pdo, []); $stl = wsm_stage_labels($pdo); $sOpts = [];
    $defSt = ['check_in' => ['warm', 'active', 'cooling', 'sleeping'], 'winback' => ['sleeping', 'cooling'], 'deadline_offer' => ['warm', 'active', 'cooling', 'sleeping', 'woke_up'], 'launch' => ['warm', 'active', 'cooling', 'woke_up'], 'restock' => ['warm', 'active', 'cooling'], 'info' => ['warm', 'active']][$intent] ?? ['warm', 'active', 'cooling'];
    foreach ($stl as $k => $v) $sOpts[] = ['v' => $k, 'label' => $v['label'], 'help' => $v['help'], 'count' => (int)($cnt['by_stage'][$k]['sendable'] ?? 0), 'suggested' => in_array($k, $defSt, true)];
    $add(['id' => 'stage', 'group' => 2, 'label' => 'Who gets it', 'section' => 'Stage', 'kind' => 'multi', 'options' => $sOpts, 'suggest' => $defSt, 'why' => $w('stage', 'People who talked to you recently answer best; a friendly check-in also works on quiet ones.')]);
    $vOpts = []; foreach (wsm_value_labels($pdo) as $k => $v) $vOpts[] = ['v' => $k, 'label' => $v['label'], 'help' => $v['help'], 'count' => (int)($cnt['by_value'][$k]['sendable'] ?? 0), 'suggested' => true];
    $add(['id' => 'value', 'group' => 2, 'label' => 'Value', 'kind' => 'multi', 'options' => $vOpts, 'suggest' => array_column(array_filter($vOpts, fn($o) => $o['suggested']), 'v'), 'why' => $w('value', 'All value levels, so nobody feels left out. Top customers get their own group when there are enough.')]);
    $nOpts = [['v' => 'all', 'label' => 'All ' . count(wsm_numbers($pdo)) . ' numbers', 'suggested' => true]]; foreach (wsm_numbers($pdo) as $id => $l) $nOpts[] = ['v' => (string)$id, 'label' => $l, 'suggested' => false];
    $add(['id' => 'numbers', 'group' => 2, 'label' => 'Which number they talk to', 'kind' => 'multi', 'options' => $nOpts, 'suggest' => ['all'], 'why' => $w('numbers', 'Every number can carry it; Claude spreads the sending by each number\'s room and health.')]);
    $tOpts = [['v' => 'any', 'label' => 'Any tag', 'suggested' => true]]; foreach ((defined('WA_TAGS') ? WA_TAGS : []) as $k => $l) $tOpts[] = ['v' => $k, 'label' => $l, 'suggested' => false];
    $add(['id' => 'tags', 'group' => 2, 'label' => 'Tags', 'kind' => 'multi', 'options' => $tOpts, 'suggest' => ['any'], 'why' => $w('tags', 'No tag filter: the stage already says who is warm.')]);
    $lo = [['v' => 'optout', 'label' => 'Opted out', 'locked' => true, 'suggested' => true], ['v' => 'bought14', 'label' => 'Bought in the last 14 days', 'suggested' => true], ['v' => 'messaged7', 'label' => 'Messaged this week', 'suggested' => true], ['v' => 'complaint', 'label' => 'Open complaint', 'suggested' => true]];
    $add(['id' => 'leave_out', 'group' => 2, 'label' => 'Leave out', 'kind' => 'multi', 'options' => $lo, 'suggest' => ['optout', 'bought14', 'messaged7', 'complaint'], 'why' => $w('leave_out', 'Opted-out customers are always left out. The rest protects people who just bought or were just messaged.')]);
    // what to say
    $add(['id' => 'goal', 'group' => 3, 'label' => 'Goal', 'kind' => 'single', 'options' => [['v' => 'reply', 'label' => 'Get a reply'], ['v' => 'order', 'label' => 'Get an order'], ['v' => 'store', 'label' => 'Store visit'], ['v' => 'reactivate', 'label' => 'Reactivate']],
        'suggest' => $intent === 'deadline_offer' ? 'order' : ($intent === 'winback' ? 'reactivate' : 'reply'), 'why' => $w('goal', $intent === 'check_in' ? 'A "kaise ho" message works best when it only asks for a reply.' : 'Matches what you wrote.')]);
    $offerSug = $intent === 'deadline_offer' ? 'coupon' : 'none';
    $add(['id' => 'offer', 'group' => 3, 'label' => 'Offer', 'kind' => 'single', 'options' => [['v' => 'none', 'label' => 'None'], ['v' => 'coupon', 'label' => 'Coupon'], ['v' => 'gift', 'label' => 'Gift'], ['v' => 'free_shipping', 'label' => 'Free shipping'], ['v' => 'early_access', 'label' => 'Early access']],
        'suggest' => $offerSug, 'why' => $w('offer', $intent === 'check_in' ? 'You asked for a friendly check-in with no offer.' : 'An offer with a deadline gives people a reason to act today.')]);
    $offerNow = $eff['offer']['type'] ?? $offerSug;
    if ($offerNow === 'coupon') { $cap = wsm_discount_cap($pdo, null); $add(['id' => 'offer_cap', 'group' => 3, 'label' => 'Highest discount Claude may use', 'kind' => 'number', 'unit' => '%', 'options' => [['v' => '5', 'label' => '5%'], ['v' => '10', 'label' => '10%'], ['v' => '15', 'label' => '15%'], ['v' => '20', 'label' => '20%']],
        'suggest' => (string)$cap, 'why' => $w('offer_cap', 'Never above your Teach limit; the same discount for everybody, only the code and wording change by group.')]); }
    $add(['id' => 'perfumes', 'group' => 3, 'label' => 'Perfumes or gels', 'kind' => 'single', 'options' => [['v' => 'none', 'label' => 'None'], ['v' => 'all', 'label' => 'All'], ['v' => 'collection', 'label' => 'A collection…']],
        'suggest' => $intent === 'check_in' ? 'none' : ($intent === 'launch' || $intent === 'restock' ? 'collection' : 'all'), 'why' => $w('perfumes', $intent === 'check_in' ? 'Claude only names the perfume the person asked about.' : 'Uses the website names, never the designer\'s.')]);
    $add(['id' => 'media', 'group' => 3, 'label' => 'Media', 'kind' => 'single', 'options' => [['v' => 'none', 'label' => 'None'], ['v' => 'photo', 'label' => 'Photo'], ['v' => 'video', 'label' => 'Video']], 'suggest' => 'none', 'why' => $w('media', 'A plain text message feels personal and is cheaper.')]);
    // after sending
    $add(['id' => 'followup', 'group' => 4, 'label' => 'Follow-up for people who do not reply', 'kind' => 'single', 'options' => [['v' => 'none', 'label' => 'None'], ['v' => 'one', 'label' => 'One, after 4 days'], ['v' => 'two', 'label' => 'Two, after 4 and 9 days']],
        'suggest' => $intent === 'deadline_offer' ? 'none' : 'one', 'why' => $w('followup', $intent === 'deadline_offer' ? 'The reminders before the deadline already work as follow-ups.' : 'One gentle nudge, then stop.')]);
    $add(['id' => 'reply_owner', 'group' => 4, 'label' => 'Who answers the replies', 'kind' => 'single', 'options' => [['v' => 'bot', 'label' => 'Bot first'], ['v' => 'salesperson', 'label' => 'A salesperson']], 'suggest' => 'bot', 'why' => $w('reply_owner', 'The bot answers at once and hands over when someone wants to buy.')]);
    // sending
    $add(['id' => 'speed', 'group' => 5, 'label' => 'Speed', 'kind' => 'single', 'options' => [['v' => 'gentle', 'label' => 'Gentle'], ['v' => 'normal', 'label' => 'Normal'], ['v' => 'fast', 'label' => 'Fast']], 'suggest' => $intent === 'deadline_offer' ? 'normal' : 'gentle', 'why' => $w('speed', 'Gentle spreads it over several days and protects your numbers.')]);
    $add(['id' => 'budget', 'group' => 5, 'label' => 'Budget cap', 'kind' => 'single', 'options' => [['v' => '3000', 'label' => '₹3,000'], ['v' => '5000', 'label' => '₹5,000'], ['v' => '8000', 'label' => '₹8,000'], ['v' => '0', 'label' => 'No cap']], 'suggest' => '5000', 'why' => $w('budget', 'Claude shows the estimate against this before you approve.')]);
    $add(['id' => 'test_first', 'group' => 5, 'label' => 'Test first', 'kind' => 'single', 'options' => [['v' => 'yes', 'label' => 'Send a test to my phone'], ['v' => 'no', 'label' => 'No test']], 'suggest' => 'yes', 'why' => $w('test_first', 'You see the exact message on your own phone before the real send.')]);
    // round 2: the audience is too small -> offer to add more
    if (!empty($eff['_small']) && !array_key_exists('widen', $answers)) {
        $have = (array)($eff['stage'] ?? []); $extra = array_values(array_diff(array_keys($stl), $have, ['imported_unknown', 'new'])); $o = [['v' => 'no', 'label' => 'No, keep it small', 'suggested' => false]];
        foreach (array_slice($extra, 0, 3) as $k) $o[] = ['v' => 'add:' . $k, 'label' => 'Add ' . $stl[$k]['label'], 'suggested' => count($o) === 1];
        array_unshift($Q, ['id' => 'widen', 'group' => 2, 'label' => 'Only ' . wsm_n($eff['_count']) . ' people match. Add more?', 'kind' => 'single', 'options' => $o, 'suggest' => $o[1]['v'] ?? 'no', 'why' => 'A very small list gives too little to learn from.', 'answered' => false, 'decided' => false, 'value' => null, 'followup' => true]);
    }
    return $Q;
}
/** Teach's own limits: the highest discount in % (null when none is set). */
function wsm_teach_max_pct(PDO $pdo): ?float
{
    try {
        if (function_exists('teach_limits_active')) { $A = teach_limits_active($pdo); $m = null; foreach ((array)($A['rows'] ?? []) as $r) if (isset($r['max_pct']) && $r['max_pct'] !== null) $m = $m === null ? (float)$r['max_pct'] : min($m, (float)$r['max_pct']); if ($m !== null) return $m; }
    } catch (Throwable $e) { }
    return null;
}
/** The highest discount % that is allowed: the owner's answer, and never above Teach's limit. */
function wsm_discount_cap(PDO $pdo, ?float $asked): int
{
    $t = wsm_teach_max_pct($pdo); $cap = $asked !== null ? $asked : 10.0; if ($t !== null) $cap = min($cap, $t);
    foreach (preg_split('/\n/', wsm_decisions($pdo)) ?: [] as $l) if (preg_match('/(?:more than|above|over|max(?:imum)?(?: discount)?(?: of)?|at most|up to)\s*(\d{1,2})\s*%/i', $l, $m)) $cap = min($cap, (float)$m[1]);
    return max(0, (int)floor($cap));
}
/** Answers (or, where the owner left it to Claude, the suggestions) as one clean brief. */
function wsm_effective(PDO $pdo, array $U, array $answers): array
{
    $now = wsm_now(); $intent = $U['intent']; $Qs = wsm_questions($pdo, $U, [], 0, []); $S = []; foreach ($Qs as $q) $S[$q['id']] = $q['suggest'];
    $pick = function (string $id) use ($answers, $S) { $a = $answers[$id] ?? null; return ($a === null || $a === 'claude' || $a === '' || $a === []) ? ($S[$id] ?? null) : $a; };
    $listv = fn($v) => is_array($v) ? array_values(array_map('strval', $v)) : ($v === null || $v === '' ? [] : [(string)$v]);
    $E = ['intent' => $intent, 'text' => (string)($answers['_text'] ?? '')];
    // dates
    $dv = (string)$pick('dates'); $from = null; $to = null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $dv, $m)) { $from = $m[1]; $to = $m[2]; }
    if (!empty($answers['dates_pick']) && is_array($answers['dates_pick']) && !empty($answers['dates_pick']['from'])) { $from = (string)$answers['dates_pick']['from']; $to = (string)($answers['dates_pick']['to'] ?? $from); }
    if ($from === null) { $from = date('Y-m-d', $now + 86400); $to = date('Y-m-d', $now + 7 * 86400); }
    $dl = $pick('deadline'); $deadline = '';
    if ($intent === 'deadline_offer') { if (is_string($dl) && preg_match('/^\+(\d+)$/', $dl, $m)) $deadline = date('Y-m-d', $now + (int)$m[1] * 86400); elseif (is_string($dl) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) $deadline = $dl; }
    $E['dates'] = ['from' => $from, 'to' => $to, 'deadline' => $deadline];
    $hrs = []; foreach ($listv($pick('hours')) as $x) if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $x, $m)) $hrs[] = [(int)$m[1], (int)$m[2]]; $E['hours'] = $hrs ?: wsm_default_hours();
    // who
    $f = []; $st = $listv($pick('stage')); $wid = (string)($answers['widen'] ?? ''); if (strpos($wid, 'add:') === 0) $st[] = substr($wid, 4);
    if ($st) $f['stage'] = array_values(array_unique($st)); $vl = $listv($pick('value')); if ($vl && count($vl) < count(wsm_value_labels($pdo))) $f['value'] = $vl;
    $nm = array_values(array_filter($listv($pick('numbers')), fn($x) => ctype_digit($x))); if ($nm && !in_array('all', $listv($pick('numbers')), true)) $f['number_used'] = array_map('intval', $nm);
    $tg = array_values(array_filter($listv($pick('tags')), fn($x) => $x !== 'any')); if ($tg) $f['gtags'] = $tg;
    $lo = $listv($pick('leave_out')); if (in_array('bought14', $lo, true)) $f['exclude_bought_days'] = 14; if (in_array('messaged7', $lo, true)) $f['exclude_messaged_days'] = 7; if (in_array('complaint', $lo, true)) $f['exclude_complaint'] = 1;
    foreach (['first_from', 'first_to', 'last_in_from', 'last_in_to', 'silent_min_days', 'returned_within_days', 'asked_days', 'ordered_from', 'ordered_to', 'order_count_min', 'order_count_max', 'spent_min', 'spent_max', 'source', 'first_number', 'language', 'first_on_from', 'first_on_to', 'last_in_on_from', 'last_in_on_to'] as $k)
        if (isset($answers['filters'][$k])) $f[$k] = $answers['filters'][$k];   // the audience builder (dates etc.) adds these
    if (is_array($answers['filters'] ?? null)) { foreach (['stage', 'value'] as $k) if (!empty($answers['filters'][$k]) && !array_key_exists($k === 'stage' ? 'stage' : 'value', $answers)) $f[$k] = $answers['filters'][$k]; }
    // picked on the Customers screen (wa_cust.php): exactly those people — their segment tag — and the stage / value suggestions do not narrow them unless the owner chose one
    $seg = mb_substr(trim((string)($answers['_seg'] ?? '')), 0, 40); if ($seg !== '' && strpos($seg, 'seg_') === 0) { $f['gtags'] = array_values(array_unique(array_merge((array)($f['gtags'] ?? []), [$seg]))); foreach (['stage', 'value'] as $k) if (!isset($answers[$k]) || $answers[$k] === 'claude' || $answers[$k] === '' || $answers[$k] === []) unset($f[$k]); }
    $E['filters'] = wsm_filters_clean($f); $E['leave_out'] = $lo;
    // what
    $E['goal'] = (string)$pick('goal'); $ot = (string)$pick('offer'); $cap = wsm_discount_cap($pdo, is_numeric($pick('offer_cap')) ? (float)$pick('offer_cap') : null);
    $E['offer'] = ['type' => $ot, 'cap' => $cap, 'pct' => $ot === 'coupon' ? min($cap, 10) : 0]; $E['perfumes'] = (string)$pick('perfumes'); $E['media'] = ['kind' => (string)$pick('media'), 'id' => (int)($answers['media_id'] ?? 0)];
    $E['followup'] = (string)$pick('followup'); $E['reply_owner'] = (string)$pick('reply_owner'); $E['speed'] = in_array((string)$pick('speed'), ['gentle', 'normal', 'fast'], true) ? (string)$pick('speed') : 'gentle';
    $b = $pick('budget'); $E['budget'] = is_numeric($b) ? (float)$b : 0.0; $E['test_first'] = (string)$pick('test_first') !== 'no';
    return $E;
}
/** The ask answer. Stateless: the screen sends {text, answers, round} each time. */
function wsm_ask(PDO $pdo, string $text, array $answers, int $round, ?array $keep = null): array
{
    $U = $keep && !empty($keep['intent']) && isset(wsm_intents()[$keep['intent']]) ? $keep + ['why' => [], 'sug' => [], 'dates' => [], 'line' => '', 'used' => [], 'words' => wsm_words($text), 'source' => 'rules'] : wsm_understand($pdo, $text, $answers);
    // $keep = what Claude understood on the first call (the screen sends it back, so tapping a chip never costs another AI call)
    $answers['_text'] = $text; $E = wsm_effective($pdo, $U, $answers);
    $c = wsm_count($pdo, $E['filters']); $minAud = wsm_cfg_i($pdo, 'wa_smart_min_aud', 200);
    $eff = ['stage' => $E['filters']['stage'] ?? [], 'offer' => $E['offer'], '_small' => $round >= 1 && $c['sendable'] > 0 && $c['sendable'] < $minAud && !empty($c['seg']), '_count' => $c['sendable']];
    $Qs = wsm_questions($pdo, $U, $answers, $round, $eff);
    $needDeadline = $U['intent'] === 'deadline_offer' && $E['dates']['deadline'] === '';
    $open = array_values(array_filter($Qs, fn($q) => !$q['answered'] && !$q['decided'])); $decideAll = !empty($answers['_decide_all']);
    $done = !$needDeadline && ($decideAll || !$open);
    $conf = wsm_confirm_line($pdo, $E, $c); $nU = count(array_filter($Qs, fn($q) => $q['answered'])); $nC = count($Qs) - $nU;
    $src = []; foreach (['goal' => 'goal', 'when' => 'dates', 'who' => 'stage', 'offer' => 'offer', 'words' => 'words', 'speed' => 'speed'] as $k => $qid) $src[$k] = ['src' => (array_key_exists($qid, $answers) && $answers[$qid] !== 'claude') ? 'you' : 'claude'];
    if (!empty($U['dates']['month']) || !empty($U['dates']['deadline']) || !empty($U['dates']['day'])) $src['when']['src'] = 'you';
    $und = ['goal' => ['v' => ['reply' => 'Get a reply', 'order' => 'Get an order', 'store' => 'Visit the store', 'reactivate' => 'Reactivate quiet customers'][$E['goal']] ?? $E['goal']] + $src['goal'], 'when' => ['v' => wsm_when_text($E)] + $src['when'], 'who' => ['v' => wsm_who_text($pdo, $E, $c)] + $src['who'],
        'offer' => ['v' => ['none' => 'No offer', 'coupon' => 'Coupon up to ' . $E['offer']['pct'] . '%', 'gift' => 'A gift', 'free_shipping' => 'Free shipping', 'early_access' => 'Early access'][$E['offer']['type']] ?? $E['offer']['type']] + $src['offer'], 'words' => ['v' => $U['words'], 'src' => 'you'], 'speed' => ['v' => ucfirst($E['speed'])] + $src['speed']];
    $line = $U['line'] !== '' ? $U['line'] : 'I understood this as ' . (wsm_intents()[$U['intent']] ?? 'a campaign') . '. A few things I need to know.';
    return ['ok' => true, 'intent' => $U['intent'], 'intent_label' => wsm_intents()[$U['intent']] ?? '', 'understood' => $und, 'claude_line' => $line, 'questions' => $Qs, 'done' => $done, 'round' => $round, 'confirm' => $conf, 'audience' => ['total' => $c['total'], 'sendable' => $c['sendable'], 'adds_up' => $c['adds_up'], 'filters' => $E['filters'], 'by_stage' => $c['by_stage'], 'by_value' => $c['by_value']],
        'counts' => ['questions' => count($Qs), 'you' => $nU, 'claude' => $nC], 'state' => $U, 'source' => $U['source'], 'used' => $U['used'], 'effective' => ['dates' => $E['dates'], 'filters' => $E['filters']], 'needs' => $needDeadline ? ['deadline'] : []];
}
function wsm_when_text(array $E): string
{
    $d = $E['dates']; $h = implode(' and ', array_map('wsm_hours_label', $E['hours'])); $a = $d['from'] === $d['to'] ? wsm_date_nice($d['from']) : wsm_date_nice($d['from']) . '–' . wsm_date_nice($d['to']);
    return $a . ', ' . $h . ($d['deadline'] !== '' ? ' · offer ends ' . wsm_date_nice($d['deadline']) : '');
}
function wsm_who_text(PDO $pdo, array $E, array $c): string
{
    $stl = wsm_stage_labels($pdo); $n = []; foreach ((array)($E['filters']['stage'] ?? []) as $s) $n[] = $stl[$s]['label'] ?? $s;
    return wsm_n($c['sendable']) . ' people' . ($n ? ' · ' . implode(' + ', $n) : ' · everyone') . ($E['leave_out'] ? ' · leaving out opted out' . (in_array('bought14', $E['leave_out'], true) ? ', recent buyers' : '') . (in_array('complaint', $E['leave_out'], true) ? ', complaints' : '') : '');
}
/** ONE line that repeats the date and the audience back to the owner before the plan. */
function wsm_confirm_line(PDO $pdo, array $E, array $c): string
{
    $what = wsm_intents()[$E['intent']] ?? 'a campaign'; $stl = wsm_stage_labels($pdo); $n = []; foreach ((array)($E['filters']['stage'] ?? []) as $s) $n[] = $stl[$s]['label'] ?? $s;
    return 'You want to send ' . strtolower($what) . ' to about ' . wsm_n($c['sendable']) . ' people' . ($n ? ' (' . implode(' + ', $n) . ')' : '') . ' on ' . wsm_when_text($E) . '.';
}


// ------------------------------------------------------------------------------------------------------------------ groups (at most 6; disjoint, so the counts add up to the audience)
function wsm_buckets(): array
{
    return ['warm' => ['Wrote lately', ['new', 'warm', 'active', 'woke_up']], 'cooling' => ['Cooling', ['cooling']], 'sleeping' => ['Sleeping', ['sleeping']], 'never' => ['Never replied', ['never_replied', 'imported_unknown']]];
}
function wsm_lang_sets(): array { return ['hinglish' => ['hinglish', 'unknown'], 'en' => ['en'], 'hi' => ['hi'], 'gu' => ['gu']]; }
function wsm_lang_label(string $l): string { return ['hinglish' => 'Hinglish', 'en' => 'English', 'hi' => 'Hindi', 'gu' => 'Gujarati'][$l] ?? $l; }
function wsm_split_value(array $f, array $set): array { $f['value'] = $set; return $f; }
/** Disjoint groups of the audience: by stage bucket, then by language when several languages are really there, then (offers) top customers apart. */
function wsm_groups(PDO $pdo, array $E): array
{
    $base = $E['filters']; $tot = wsm_count($pdo, $base); $G = [];
    if (empty($tot['seg'])) {   // no customer story yet: one group
        if ($tot['sendable'] > 0) $G[] = ['bucket' => 'all', 'title' => 'Everyone', 'lang' => 'hinglish', 'filters' => $base, 'cnt' => $tot];
        return $G;
    }
    $sel = (array)($base['stage'] ?? []); $cells = [];
    foreach (wsm_buckets() as $bk => [$title, $stages]) {
        $st = $sel ? array_values(array_intersect($stages, $sel)) : $stages; if (!$st) continue;
        $f = $base; $f['stage'] = $st; $c = wsm_count($pdo, $f); if ($c['sendable'] <= 0) continue;
        $cells[] = ['bucket' => $bk, 'title' => $title, 'lang' => 'hinglish', 'filters' => $f, 'cnt' => $c];
    }
    // language: only when a second language is a real share (20% or more) of a bucket
    $out = [];
    foreach ($cells as $cell) {
        $n = $cell['cnt']['sendable']; $split = [];
        if ($n >= 20) {
            $per = []; foreach (wsm_lang_sets() as $lk => $set) { $f = $cell['filters']; $f['language'] = $set; $c = wsm_count($pdo, $f); $per[$lk] = ['n' => $c['sendable'], 'f' => $f, 'c' => $c]; }
            arsort($per); $dom = array_key_first($per); $real = array_filter($per, fn($x) => $x['n'] >= max(20, 0.2 * $n));
            if (count($real) >= 2 && $n >= 100) {
                foreach ($per as $lk => $x) { if ($x['n'] <= 0) continue; if (isset($real[$lk])) $split[$lk] = $x; }
                $rest = 0; $restKeys = []; foreach ($per as $lk => $x) if ($x['n'] > 0 && !isset($real[$lk])) { $rest += $x['n']; $restKeys[] = $lk; }
                if ($rest > 0) { $d0 = $dom; $set = wsm_lang_sets()[$d0]; foreach ($restKeys as $rk) $set = array_merge($set, wsm_lang_sets()[$rk]); $f = $cell['filters']; $f['language'] = array_values(array_unique($set)); $split[$d0] = ['n' => wsm_count($pdo, $f)['sendable'], 'f' => $f, 'c' => wsm_count($pdo, $f)]; }
            } else $cell['lang'] = $dom;
        }
        if ($split) foreach ($split as $lk => $x) $out[] = ['bucket' => $cell['bucket'], 'title' => $cell['title'] . ' · ' . wsm_lang_label($lk), 'lang' => $lk, 'filters' => $x['f'], 'cnt' => $x['c']];
        else $out[] = $cell;
    }
    if (count($out) > 6) {   // too many: undo the language splits of the smallest buckets (their customers keep the same stage filters)
        $by = []; foreach ($out as $g) $by[$g['bucket']][] = $g;
        uasort($by, fn($a, $b) => array_sum(array_map(fn($g) => $g['cnt']['sendable'], $a)) <=> array_sum(array_map(fn($g) => $g['cnt']['sendable'], $b)));
        foreach ($by as $bk => $list) {
            if (count($out) <= 6) break; if (count($list) < 2) continue;
            $cell = null; foreach ($cells as $c0) if ($c0['bucket'] === $bk) $cell = $c0; if (!$cell) continue;
            $out = array_values(array_filter($out, fn($g) => $g['bucket'] !== $bk)); usort($list, fn($a, $b) => $b['cnt']['sendable'] <=> $a['cnt']['sendable']); $cell['lang'] = $list[0]['lang']; $out[] = $cell;
        }
        $out = array_slice($out, 0, 6);
    }
    // an offer: the best customers get their own group (same price, their own code and wording) when there is room
    if ($E['intent'] === 'deadline_offer' && count($out) <= 3 && empty($base['value'])) {
        $new = [];
        foreach ($out as $g) {
            if ($g['cnt']['sendable'] >= 100 && count($new) + (count($out) - count($new)) < 6) {
                $a = wsm_split_value($g['filters'], ['vip', 'high']); $ca = wsm_count($pdo, $a); $b = wsm_split_value($g['filters'], ['mid', 'low', 'not_bought']); $cb = wsm_count($pdo, $b);
                if ($ca['sendable'] >= 20 && $cb['sendable'] >= 20 && $ca['sendable'] + $cb['sendable'] === $g['cnt']['sendable'] && count($new) + 2 + (count($out) - count($new) - 1) <= 6) {
                    $new[] = ['bucket' => $g['bucket'], 'title' => $g['title'] . ' · top customers', 'lang' => $g['lang'], 'filters' => $a, 'cnt' => $ca, 'tier' => 'top']; $new[] = ['bucket' => $g['bucket'], 'title' => $g['title'], 'lang' => $g['lang'], 'filters' => $b, 'cnt' => $cb, 'tier' => 'rest']; continue;
                }
            }
            $new[] = $g;
        }
        $out = $new;
    }
    return array_slice($out, 0, 6);
}
function wsm_group_util(PDO $pdo, array $filters, int $sendable): array
{
    $days = function_exists('wa_mix_cfg') ? (int)wa_mix_cfg($pdo, 'wa_mix_days', 30) : 30; $min = wsm_cfg_i($pdo, 'wa_smart_util_min', 30);
    $f = $filters; $f['asked_days'] = $days; $a = wsm_count($pdo, $f); $g = $filters; $g['ordered_to'] = $days; $o = wsm_count($pdo, $g);
    $e = min($sendable, (int)$a['sendable'] + (int)$o['sendable']); if (empty($a['seg'])) $e = 0;
    $ok = $e >= $min && $e * 10 >= $sendable;   // true only where enough of the group asked or ordered lately
    if (!function_exists('wa_util_mix_on') || !wa_util_mix_on($pdo)) return ['eligible' => $e, 'days' => $days, 'ok' => false, 'note' => 'Offers go out as marketing messages only (Meta keeps utility for order and delivery updates).'];
    return ['eligible' => $e, 'days' => $days, 'ok' => $ok, 'note' => $ok ? 'About ' . wsm_n($e) . ' of them asked or ordered in the last ' . $days . ' days, so a utility twin is made.' : 'Hardly anyone here asked or ordered lately, so no utility version (Meta would re-label it).'];
}

// ------------------------------------------------------------------------------------------------------------------ learning: which angle works for which customers
function wsm_angles(): array { return ['A' => 'short and direct', 'B' => 'personal', 'C' => 'a question']; }
/** Past Smart campaigns, per customer stage (bucket) + language: sends, replies and orders of each angle A / B / C (main messages only), and the winner when the
 *  data is clear. Score = (replies + 3 x orders) / sends. A winner needs at least wa_smart_learn_min (80) sends on 2 angles and to beat the next one by 20 %.
 *  Cached for 6 hours (setting wa_smart_learned). */
function wsm_learned(PDO $pdo, bool $fresh = false): array
{
    $c0 = wsm_arr(wsm_get($pdo, 'wa_smart_learned', '')); if (!$fresh && !empty($c0['at']) && (int)$c0['at'] > wsm_now() - 6 * 3600) return (array)($c0['by'] ?? []);
    $min = wsm_cfg_i($pdo, 'wa_smart_learn_min', 80); $agg = [];
    if (function_exists('wa_camp_row') && function_exists('wa_camp_result')) {
        try { $ids = $pdo->query("SELECT id FROM wa_campaigns WHERE extra LIKE '%\"angle\"%' AND status IN ('running', 'paused', 'done') ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $ids = []; }
        foreach ($ids as $cid) {
            try { $c = wa_camp_row($pdo, (int)$cid); if (!$c) continue; $x = (array)$c['extra']; $a = (string)($x['angle'] ?? ''); if (!isset(wsm_angles()[$a]) || ($x['kind'] ?? '') === 'followup') continue;
                $k = (string)($x['bucket'] ?? 'all') . '|' . (string)($x['lang'] ?? 'hinglish'); $r = wa_camp_result($pdo, $c, ['rate_marketing' => 0, 'rate_utility' => 0], false);
                $g = &$agg[$k][$a]; $g['sent'] = ($g['sent'] ?? 0) + (int)$r['sent']; $g['replied'] = ($g['replied'] ?? 0) + (int)$r['replied']; $g['orders'] = ($g['orders'] ?? 0) + (int)$r['orders']; unset($g);
            } catch (Throwable $e) { }
        }
    }
    $by = [];
    foreach ($agg as $k => $angles) {
        $sc = []; foreach ($angles as $a => $v) { $v['score'] = $v['sent'] > 0 ? round(($v['replied'] + 3 * $v['orders']) / $v['sent'], 4) : 0; $v['reply_pct'] = $v['sent'] > 0 ? round($v['replied'] * 100 / $v['sent'], 1) : 0; $angles[$a] = $v; if ($v['sent'] >= $min) $sc[$a] = $v['score']; }
        arsort($sc); $win = null; $why = '';
        if (count($sc) >= 2) { $top = array_key_first($sc); $vals = array_values($sc); if ($vals[0] > 0 && $vals[0] >= $vals[1] * 1.2) { $win = $top; $why = 'Learned from past campaigns: ' . $top . ' (' . wsm_angles()[$top] . ') got ' . $angles[$top]['reply_pct'] . '% replies and ' . $angles[$top]['orders'] . ' orders from ' . wsm_n($angles[$top]['sent']) . ' sends, clearly better than the others.'; } }
        $by[$k] = ['angles' => $angles, 'winner' => $win, 'note' => $why];
    }
    wsm_put($pdo, 'wa_smart_learned', wsm_j(['at' => wsm_now(), 'by' => $by])); return $by;
}

// ------------------------------------------------------------------------------------------------------------------ the words (Claude writes; rules are the safe fallback)
function wsm_designers(): array
{
    return ['creed', 'aventus', 'dior', 'sauvage', 'chanel', 'bleu de chanel', 'tom ford', 'armani', 'acqua di gio', 'versace', 'gucci', 'ysl', 'yves saint laurent', 'hermes', 'hermès', 'paco rabanne', 'one million', 'invictus', 'jo malone', 'maison francis kurkdjian', 'baccarat rouge', 'parfums de marly', 'xerjoff', 'amouage', 'givenchy', 'burberry', 'calvin klein', 'hugo boss', 'davidoff', 'azzaro', 'montblanc', 'carolina herrera', 'jean paul gaultier', 'lattafa', 'initio', 'nishane', 'byredo', 'le labo', 'kilian', 'roja'];
}
function wsm_tokens(): array { return ['name', 'perfume', 'days', 'offer', 'deadline', 'code', 'store']; }
/** Cleans one message: no real designer names, the discount never above the cap, only known {{tokens}}, short. Returns [text, flags[]]. */
function wsm_clean_msg(PDO $pdo, string $t, int $cap, bool $offerOn): array
{
    $flags = []; $t = trim(str_replace(["\r\n", "\r"], "\n", $t));
    foreach (wsm_designers() as $d) { $re = '/\b' . preg_quote($d, '/') . '\b/iu'; if (preg_match($re, $t)) { $t = preg_replace($re, 'our perfume', $t); $flags[] = 'designer name removed'; } }
    $t = preg_replace('/(our perfume)(\s+our perfume)+/iu', '$1', $t) ?? $t;
    $t = preg_replace_callback('/(\d{1,3})\s*%/u', function ($m) use ($cap, $offerOn, &$flags) { $n = (int)$m[1]; if ($n > $cap) { $flags[] = 'discount cut to ' . $cap . '%'; return $cap . '%'; } return $m[0]; }, $t) ?? $t;
    if (preg_match('/[₹]\s?\d|\brs\.?\s?\d|\binr\s?\d/iu', $t)) $flags[] = 'has a price';
    $t = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use (&$flags) { $k = strtolower($m[1]); if (in_array($k, wsm_tokens(), true)) return '{{' . $k . '}}'; $flags[] = 'unknown variable removed'; return ''; }, $t) ?? $t;
    if (!$offerOn) { if (preg_match('/\{\{\s*(offer|code)\s*\}\}/', $t)) { $t = preg_replace('/\{\{\s*(offer|code)\s*\}\}/', '', $t); $flags[] = 'offer words removed (no offer chosen)'; } }
    $t = preg_replace('/[ \t]{2,}/', ' ', $t) ?? $t; if (mb_strlen($t) > 700) { $t = mb_substr($t, 0, 700); $flags[] = 'shortened'; }
    return [trim($t), array_values(array_unique($flags))];
}
/** Fallback wording, in the customer's language. Roles: A B C (first message), reminder, last, followup. */
function wsm_fallback_msg(string $lang, string $intent, string $role, array $o): string
{
    $off = $o['offer']; $perf = $o['perfumes'] !== 'none';
    $B = [
        'hinglish' => ['check_in' => ['A' => "Hello {{name}} ji, kaise hain aap? Kaafi time ho gaya baat kiye hue. Koi nayi khushboo ya koi madad chahiye to bas reply karein, hum yahin hain.", 'B' => "Namaste {{name}} ji! TM Perfume House se yaad kar rahe the. Aapka din kaisa chal raha hai? Kuch chahiye ho to bata dijiye.", 'C' => "Hi {{name}}, bas hal-chaal poochne aaye the. Aapko perfume ke baare mein kuch poochna ho to is message ka reply kar dijiye.", 'followup' => "{{name}} ji, pichla message shayad miss ho gaya. Koi sawal ho to reply kar dena, hum madad ke liye hain."],
            'offer' => ['A' => "Hi {{name}} ji! {{offer}} sirf {{deadline}} tak. Code {{code}} use karein aur apni pasand ki khushboo lein.", 'B' => "{{name}} ji, aapke liye khaas: {{offer}}, {{deadline}} tak. Code: {{code}}. Reply karein, hum order mein madad karenge.", 'C' => "Namaste {{name}} ji, {{deadline}} se pehle {{offer}} ka fayda uthayein. Code {{code}}. Pasand ki khushboo batayein, hum bhej dete hain.", 'reminder' => "{{name}} ji, yaad dila dein: {{offer}} {{deadline}} tak chalega. Code {{code}}. Abhi reply karein.", 'last' => "Aakhri mauka {{name}} ji! {{offer}} aaj/kal khatam ho raha hai ({{deadline}}). Code {{code}}."],
            'generic' => ['A' => "Hi {{name}} ji! TM Perfume House se ek khaas khabar hai. Detail jaanne ke liye is message ka reply karein.", 'B' => "Namaste {{name}} ji, aapke liye nayi jaankari hai. Reply karein, hum sab bata denge.", 'C' => "{{name}} ji, hamare paas kuch naya hai jo aapko pasand aa sakta hai. Reply karke poochiye.", 'followup' => "{{name}} ji, agar pichla message miss ho gaya ho to bas reply karein, hum bata denge."]],
        'en' => ['check_in' => ['A' => "Hello {{name}}, how have you been? It has been a while since we spoke. If you need anything or want to try a new fragrance, just reply here.", 'B' => "Hi {{name}}, this is TM Perfume House checking in. How is your day going? Let us know if we can help with anything.", 'C' => "Hi {{name}}, just saying hello. If you would like a suggestion for your next perfume, reply to this message.", 'followup' => "Hi {{name}}, in case you missed our last message, reply any time and we will be happy to help."],
            'offer' => ['A' => "Hi {{name}}! {{offer}} until {{deadline}}. Use code {{code}} and pick your favourite fragrance.", 'B' => "{{name}}, something special for you: {{offer}}, valid till {{deadline}}. Code: {{code}}. Reply and we will help you order.", 'C' => "Hello {{name}}, enjoy {{offer}} before {{deadline}}. Code {{code}}. Tell us your favourite scent and we will send it.", 'reminder' => "Hi {{name}}, a reminder: {{offer}} runs only until {{deadline}}. Code {{code}}. Reply to order.", 'last' => "Last call {{name}}! {{offer}} ends on {{deadline}}. Code {{code}}."],
            'generic' => ['A' => "Hi {{name}}! We have some news from TM Perfume House. Reply to this message to know more.", 'B' => "Hello {{name}}, there is something new for you. Reply and we will tell you everything.", 'C' => "Hi {{name}}, we have something you may like. Reply to ask us about it.", 'followup' => "Hi {{name}}, if you missed our last message, just reply and we will tell you more."]],
        'hi' => ['check_in' => ['A' => "नमस्ते {{name}} जी, आप कैसे हैं? काफ़ी समय हो गया बात किए हुए। कोई मदद चाहिए तो बस इस मैसेज का जवाब दें।", 'B' => "नमस्ते {{name}} जी! TM Perfume House से याद कर रहे थे। आपका दिन कैसा चल रहा है? कुछ चाहिए तो बताइए।", 'C' => "हेलो {{name}} जी, बस हाल-चाल पूछने आए थे। परफ़्यूम के बारे में कुछ पूछना हो तो जवाब दें।", 'followup' => "{{name}} जी, शायद पिछला मैसेज छूट गया। कोई सवाल हो तो जवाब दीजिए, हम मदद करेंगे।"],
            'offer' => ['A' => "नमस्ते {{name}} जी! {{offer}} सिर्फ़ {{deadline}} तक। कोड {{code}} इस्तेमाल करें और अपनी पसंद की खुशबू लें।", 'B' => "{{name}} जी, आपके लिए ख़ास: {{offer}}, {{deadline}} तक। कोड: {{code}}। जवाब दें, हम ऑर्डर में मदद करेंगे।", 'C' => "नमस्ते {{name}} जी, {{deadline}} से पहले {{offer}} का फ़ायदा उठाएँ। कोड {{code}}।", 'reminder' => "{{name}} जी, याद दिला दें: {{offer}} {{deadline}} तक चलेगा। कोड {{code}}।", 'last' => "आख़िरी मौक़ा {{name}} जी! {{offer}} {{deadline}} को ख़त्म हो रहा है। कोड {{code}}।"],
            'generic' => ['A' => "नमस्ते {{name}} जी! TM Perfume House की तरफ़ से एक ख़ास ख़बर है। जानने के लिए जवाब दें।", 'B' => "नमस्ते {{name}} जी, आपके लिए कुछ नया है। जवाब दें, हम सब बताएँगे।", 'C' => "{{name}} जी, हमारे पास कुछ है जो आपको पसंद आ सकता है। जवाब देकर पूछिए।", 'followup' => "{{name}} जी, अगर पिछला मैसेज छूट गया हो तो जवाब दें, हम बता देंगे।"]],
        'gu' => ['check_in' => ['A' => "નમસ્તે {{name}} ભાઈ/બહેન, કેમ છો? ઘણા સમયથી વાત નથી થઈ. કંઈ મદદ જોઈએ તો આ મેસેજનો જવાબ આપો.", 'B' => "નમસ્તે {{name}}! TM Perfume House તરફથી યાદ કરતા હતા. તમારો દિવસ કેવો ચાલે છે? કંઈ જોઈએ તો કહો.", 'C' => "હેલો {{name}}, બસ ખબર પૂછવા આવ્યા હતા. પરફ્યુમ વિશે પૂછવું હોય તો જવાબ આપો.", 'followup' => "{{name}}, કદાચ પાછલો મેસેજ રહી ગયો હશે. કંઈ પૂછવું હોય તો જવાબ આપો, અમે મદદ કરીશું."],
            'offer' => ['A' => "નમસ્તે {{name}}! {{offer}} ફક્ત {{deadline}} સુધી. કોડ {{code}} વાપરો અને તમારી ગમતી સુગંધ લો.", 'B' => "{{name}}, તમારા માટે ખાસ: {{offer}}, {{deadline}} સુધી. કોડ: {{code}}. જવાબ આપો, અમે ઓર્ડરમાં મદદ કરીશું.", 'C' => "નમસ્તે {{name}}, {{deadline}} પહેલાં {{offer}} નો લાભ લો. કોડ {{code}}.", 'reminder' => "{{name}}, યાદ અપાવીએ: {{offer}} {{deadline}} સુધી ચાલશે. કોડ {{code}}.", 'last' => "છેલ્લી તક {{name}}! {{offer}} {{deadline}} એ પૂરું થાય છે. કોડ {{code}}."],
            'generic' => ['A' => "નમસ્તે {{name}}! TM Perfume House તરફથી ખાસ સમાચાર છે. જાણવા માટે જવાબ આપો.", 'B' => "નમસ્તે {{name}}, તમારા માટે કંઈક નવું છે. જવાબ આપો, અમે બધું કહીશું.", 'C' => "{{name}}, અમારી પાસે કંઈક છે જે તમને ગમશે. જવાબ આપીને પૂછો.", 'followup' => "{{name}}, પાછલો મેસેજ રહી ગયો હોય તો જવાબ આપો, અમે જણાવીશું."]],
    ];
    $L = $B[$lang] ?? $B['hinglish']; $k = ($intent === 'check_in') ? 'check_in' : (($off && $off !== 'none') ? 'offer' : 'generic'); $set = $L[$k];
    if ($k === 'check_in' || $k === 'generic') { if ($role === 'reminder' || $role === 'last') $role = 'followup'; }
    $t = $set[$role] ?? ($role === 'followup' ? ($L['generic']['followup']) : $set['A']);
    if ($k === 'offer' && in_array($o['offer'], ['gift', 'free_shipping', 'early_access'], true) === false && false) $t = $t;
    if ($perf && $role === 'A' && $k !== 'offer') $t = preg_replace('/\{\{name\}\}/', '{{name}}', $t);
    return $t;
}
/** The Claude call that writes the messages of every group. Returns [groupId => [role => text]] (may be partial). */
function wsm_ai_messages(PDO $pdo, array $E, array $groups, string $text): array
{
    $roles = ['A', 'B', 'C']; if ($E['intent'] === 'deadline_offer') { $roles[] = 'reminder'; $roles[] = 'last'; } elseif ($E['followup'] !== 'none') $roles[] = 'followup';
    $gs = []; foreach ($groups as $g) $gs[] = ['id' => $g['id'], 'who' => $g['title'], 'language' => wsm_lang_label($g['lang']) . ($g['lang'] === 'hinglish' ? ' (Roman-letter Hindi mixed with English, the way customers write)' : ''), 'people' => $g['sendable'], 'offer' => $g['offer']['text'] ?? '', 'code' => $g['offer']['code'] ?? '', 'deadline' => $g['offer']['deadline_text'] ?? ''];
    $props = []; foreach ($roles as $r) $props[$r] = ['type' => 'STRING'];
    $sch = ['type' => 'OBJECT', 'properties' => ['groups' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'STRING'], 'messages' => ['type' => 'OBJECT', 'properties' => $props]], 'required' => ['id', 'messages']]]], 'required' => ['groups']];
    $pk = wsm_pack($pdo, 'message_write'); $dec = wsm_decisions($pdo);
    $sys = "You write WhatsApp campaign messages for TM Perfume House (Surat). Write each message IN THE LANGUAGE OF ITS GROUP. Short: two or three sentences, warm, one clear next step. A, B and C are three FIXED angles (always the same meaning, so the results of past campaigns can be compared): A = short and direct, the news or offer in the first line; B = personal, about the customer and their own perfume or last purchase; C = a question, open with one simple question that invites a reply. " .
        "Use ONLY these variables where they fit: {{name}} {{perfume}} {{days}} {{offer}} {{deadline}} {{code}}. Never start or end a message with a variable. NEVER name a real designer brand or house; use the website's own perfume names or say 'our perfume'. " .
        "Never write a price. The discount is exactly '" . ($E['offer']['pct'] ?? 0) . "%' at most and is the SAME for every group (only the code and the wording change). " . ($E['intent'] === 'check_in' ? 'This is a friendly check-in: no offer, no selling push.' : '') .
        " Roles: reminder = a reminder before the deadline; last = last day; followup = for people who did not reply (gentle, short).\n" . $pk['text'] . ($dec !== '' ? "\nTHE OWNER'S DECISIONS (always obey):\n" . $dec : '');
    [$ok, $v] = wsm_ai($pdo, $sys, "Campaign: " . (wsm_intents()[$E['intent']] ?? '') . ". Goal: " . $E['goal'] . ". The owner wrote: " . mb_substr($text, 0, 400) . "\nGroups: " . wsm_j($gs) . "\nWrite these roles for each group: " . implode(', ', $roles), $sch, 3500);
    $out = []; if ($ok && is_array($v)) foreach ((array)($v['groups'] ?? []) as $x) if (is_array($x) && isset($x['id'])) $out[(string)$x['id']] = array_map('strval', (array)($x['messages'] ?? []));
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ messages -> pre-made message drafts (marketing + utility twin)
function wsm_lang_code(string $l): string { return ['hinglish' => 'en', 'en' => 'en', 'hi' => 'hi', 'gu' => 'gu'][$l] ?? 'en'; }
function wsm_offer_text(string $type, int $pct): string { return ['coupon' => 'flat ' . $pct . '% off', 'gift' => 'a free gift with your order', 'free_shipping' => 'free shipping on your order', 'early_access' => 'early access before everyone else'][$type] ?? ''; }
/** The variables a message uses, in the order of {{1}} {{2}} ...  Returns [body with {{n}}, tokens[], specs[], examples[]]. $o = offer of the group. */
function wsm_tpl_body(string $text, array $o): array
{
    $order = []; $labels = [];
    $body = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use (&$order) { $k = strtolower($m[1]); $i = array_search($k, $order, true); if ($i === false) { $order[] = $k; $i = count($order) - 1; } return '{{' . ($i + 1) . '}}'; }, $text) ?? $text;
    if (preg_match('/^\s*\{\{/', $body)) $body = 'Hi ' . ltrim($body);
    if (preg_match('/\}\}\s*$/', $body)) $body = rtrim($body) . '.';
    $body = preg_replace('/\}\}(\s*)\{\{/', '}}, {{', $body) ?? $body;
    $specs = []; $ex = [];
    $offerTxt = (string)($o['text'] ?? ''); $dl = (string)($o['deadline_text'] ?? ''); $code = (string)($o['code'] ?? '');
    foreach ($order as $k) {
        switch ($k) {
            case 'name': $specs[] = ['src' => 'name', 'val' => '', 'slot' => '']; $ex[] = 'Rahul'; $labels[] = 'Name'; break;
            case 'perfume': $specs[] = ['src' => 'asked_perfume', 'val' => 'our best sellers', 'slot' => '']; $ex[] = 'Royal Oud'; $labels[] = 'Perfume'; break;
            case 'days': $specs[] = ['src' => 'days_since', 'val' => 'a few', 'slot' => '']; $ex[] = '14'; $labels[] = 'Days'; break;
            case 'offer': $specs[] = ['src' => 'fixed', 'val' => $offerTxt !== '' ? $offerTxt : 'a special offer', 'slot' => '']; $ex[] = $offerTxt !== '' ? $offerTxt : 'a special offer'; $labels[] = 'Offer'; break;
            case 'deadline': $specs[] = ['src' => 'fixed', 'val' => $dl !== '' ? $dl : 'this week', 'slot' => '']; $ex[] = $dl !== '' ? $dl : 'this week'; $labels[] = 'Deadline'; break;
            case 'code': $specs[] = ['src' => 'coupon', 'val' => $code !== '' ? $code : 'TMOFFER', 'slot' => '']; $ex[] = $code !== '' ? $code : 'TMOFFER'; $labels[] = 'Code'; break;
            default: $specs[] = ['src' => 'fixed', 'val' => 'TM Perfume House', 'slot' => '']; $ex[] = 'TM Perfume House'; $labels[] = 'Store';
        }
    }
    return [$body, $order, $specs, $ex, array_map(fn($x) => mb_substr($x, 0, 20), $labels)];
}
function wsm_tpl_draft(string $name, string $text, string $lang, array $o, array $media): array
{
    [$body, $tokens, $specs, $ex, $lab] = wsm_tpl_body($text, $o); $hf = ''; $lib = 0;
    if (!empty($media['id']) && in_array($media['kind'] ?? '', ['photo', 'video'], true)) { $hf = $media['kind'] === 'video' ? 'VIDEO' : 'IMAGE'; $lib = (int)$media['id']; }
    return ['draft' => ['name' => $name, 'language' => wsm_lang_code($lang), 'category' => 'MARKETING', 'header_format' => $hf, 'header_lib_id' => $lib, 'body' => $body, 'footer' => 'Reply STOP to opt out', 'buttons' => [], 'examples' => ['body' => $ex, 'labels' => $lab]], 'tokens' => $tokens, 'specs' => $specs];
}
/** Makes (or updates) the DRAFT of a marketing message and, when asked, its utility twin. Nothing goes to Meta here. Returns [tpl_m, tpl_u, warnings[]]. Throws RuntimeException with a plain sentence. */
function wsm_tpl_save(PDO $pdo, array $msg, array $draft, bool $twin, int $by, string $purpose): array
{
    if ($twin && (!function_exists('wa_util_mix_on') || !wa_util_mix_on($pdo))) $twin = false;   // no utility twins of offers
    [$t, $err] = wa_tpl_clean($draft, true); if ($err !== '') throw new RuntimeException($err);
    $id = (int)($msg['tpl_m'] ?? 0); $uid = (int)($msg['tpl_u'] ?? 0);
    $draftable = function (int $i) use ($pdo) { if ($i <= 0) return false; $r = wa_tpl_row($pdo, $i); if (!$r || $r['source'] !== 'hrms') return false; $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$i]); return (int)$q->fetchColumn() === 0; };
    $write = function (int $i, array $x) use ($pdo, $purpose) {
        $q = $pdo->prepare("SELECT id FROM wa_templates WHERE name = ? AND language = ? AND id <> ?"); $q->execute([$x['name'], $x['language'], $i]); if ($q->fetchColumn()) $x['name'] = substr($x['name'], 0, 52) . '_' . date('md') . substr((string)mt_rand(100, 999), 0, 3);
        $pdo->prepare("UPDATE wa_templates SET name = ?, language = ?, category = ?, header_format = ?, header_text = ?, header_lib_id = ?, body = ?, footer = ?, buttons = ?, lto = ?, examples = ?, extra = ?, folder = ?, purpose = ? WHERE id = ?")
            ->execute([$x['name'], $x['language'], $x['category'], $x['header_format'], $x['header_text'], (int)$x['header_lib_id'], $x['body'], $x['footer'], json_encode($x['buttons'], JSON_UNESCAPED_UNICODE), $x['lto'] ? json_encode($x['lto'], JSON_UNESCAPED_UNICODE) : null, json_encode($x['examples'], JSON_UNESCAPED_UNICODE), $x['extra'] ? json_encode($x['extra'], JSON_UNESCAPED_UNICODE) : null, $x['folder'] !== '' ? $x['folder'] : wa_tpl_folder_guess($x), mb_substr($purpose, 0, 600), $i]);
    };
    if ($id > 0 && wa_tpl_row($pdo, $id)) { if ($draftable($id)) $write($id, $t); } else $id = wa_tpl_insert($pdo, $t, $by, $purpose, 'Smart campaign');   // a message that already went to Meta is never touched or made twice
    $u = null;
    if ($twin && function_exists('wa_tpl_utility_build')) {
        try {
            $q = $pdo->prepare("SELECT name FROM wa_templates WHERE id = ?"); $q->execute([$id]); $nm = (string)$q->fetchColumn(); $ud = wa_tpl_utility_build($t)['draft']; if (!empty($msg['u_body']) && count(wa_tpl_vars((string)$msg['u_body'])) === count(wa_tpl_vars((string)$ud['body']))) $ud['body'] = (string)$msg['u_body']; $ud['category'] = 'UTILITY'; $ud['language'] = $t['language']; $ud['name'] = substr($nm, 0, 57) . '_u';
            [$cu, $ue] = wa_tpl_clean($ud, true);
            if ($ue === '') {
                $cu['family_id'] = $id; $cu['version'] = 1; $cu['extra'] = array_merge(is_array($cu['extra']) ? $cu['extra'] : [], ['utility_of' => $id, 'utility_v' => 1]); if ($cu['folder'] === '') $cu['folder'] = $t['folder'];
                if ($uid > 0 && wa_tpl_row($pdo, $uid)) { if ($draftable($uid)) { $write($uid, $cu); $pdo->prepare("UPDATE wa_templates SET family_id = ?, version = 1 WHERE id = ?")->execute([$id, $uid]); } } else $uid = wa_tpl_insert($pdo, $cu, $by, $purpose, 'Utility version of ' . $nm);
                wa_mix_extra_set($pdo, $id, ['utility_id' => $uid]); $u = $uid;
            }
        } catch (Throwable $e) { $u = null; }
    }
    return [$id, $u ?: 0, $t['warnings']];
}

// ------------------------------------------------------------------------------------------------------------------ numbers, speed, cost
function wsm_per_min(string $speed): int { return ['gentle' => 40, 'normal' => 80, 'fast' => 150][$speed] ?? 40; }
/** How fast and how long: from the saved number health and room (no call to Meta). */
function wsm_speed(PDO $pdo, int $size, string $speed): array
{
    $speed = in_array($speed, ['gentle', 'normal', 'fast'], true) ? $speed : 'gentle'; $health = function_exists('wa_camp_health') ? wa_camp_health($pdo) : []; $caps = function_exists('wa_number_caps') ? wa_number_caps($pdo, $health) : [];
    $rows = []; $perMin = 0; $left = 0; $cap = 0; $hb = []; foreach ($health as $h) $hb[(int)$h['id']] = $h;
    foreach ($hb as $id => $h) {
        $c = $caps[$id] ?? ['cap' => 0, 'left' => 0, 'why' => '', 'used' => 0]; $q = strtoupper((string)($h['quality'] ?? 'UNKNOWN')); $pm = (int)$c['cap'] > 0 ? (int)(wsm_per_min($speed) / ($q === 'YELLOW' ? 2 : 1)) : 0;
        $rows[] = ['id' => $id, 'label' => (string)($h['label'] ?? ('Number ' . $id)), 'quality' => $q, 'cap' => (int)$c['cap'], 'left' => (int)$c['left'], 'why' => (string)$c['why'], 'per_min' => $pm, 'usable' => $pm > 0 && (int)$c['left'] > 0];
        if ($pm > 0) { $perMin += $pm; $left += (int)$c['left']; $cap += (int)$c['cap']; }
    }
    $port = (int)($caps['_port']['left'] ?? $left); $room = min($left, $port); $nn = count(array_filter($rows, fn($r) => $r['usable']));
    $minutes = $perMin > 0 ? (int)ceil($size / $perMin) : 0; $days = 1; $why = '';
    if ($size > $room) { $days = 1 + (int)ceil(($size - $room) / max(1, min($cap, (int)floor((int)($caps['_port']['tier'] ?? $cap) * 0.9)))); $why = 'limited by the numbers\' room today (' . wsm_n($room) . ') and Meta\'s daily limit'; }
    $text = $nn === 0 ? 'No number can send right now.' : ($days > 1 ? 'About ' . $days . ' days (' . $nn . ' number' . ($nn === 1 ? '' : 's') . ', ' . $speed . ') — ' . $why : wsm_n($size) . ' messages in about ' . ($minutes >= 120 ? round($minutes / 60, 1) . ' hours' : $minutes . ($minutes == 1 ? ' minute' : ' minutes')) . ' (' . $nn . ' number' . ($nn === 1 ? '' : 's') . ', ' . $speed . ')');
    return ['speed' => $speed, 'numbers' => $rows, 'numbers_used' => $nn, 'per_min' => $perMin, 'room_today' => $room, 'cap_day' => $cap, 'minutes' => $minutes, 'days' => $days, 'text' => $text, 'rate' => max(1, min(1500, $perMin)),
        'push' => ['gentle' => 'careful', 'normal' => 'normal', 'fast' => 'aggressive'][$speed], 'retries_default' => 3, 'portfolio' => $caps['_port'] ?? null];
}
function wsm_window_label(PDO $pdo): string { [$o, $c] = function_exists('wa_camp_window') ? wa_camp_window($pdo) : [8, 21]; return (($c % 12) ?: 12) . ' ' . ($c >= 12 ? 'pm' : 'am') . ' and ' . (($o % 12) ?: 12) . ' ' . ($o >= 12 ? 'pm' : 'am'); }
function wsm_max_touches(array $E): int { return $E['intent'] === 'deadline_offer' || $E['followup'] === 'two' ? 3 : 2; }

/** The waves, from the intent. deadline_offer runs backwards from the deadline; the others are a gentle drip. All times are real timestamps; groups = which groups take part. */
function wsm_waves(PDO $pdo, array $E, array $gids, int $now): array
{
    $h0 = $E['hours'][0][0] ?? 10; $win = function_exists('wa_camp_window') ? wa_camp_window($pdo) : [8, 21]; $h0 = max($win[0], min($win[1] - 1, $h0));
    $at = function (string $d, int $h) { return strtotime($d . ' ' . sprintf('%02d:00:00', $h)); }; $from = $E['dates']['from']; $to = $E['dates']['to']; $W = [];
    $startTs = max($at($from, $h0), $now + 120); if (function_exists('wa_camp_clamp')) $startTs = wa_camp_clamp($pdo, $startTs);
    if ($E['intent'] === 'deadline_offer') {
        $D = $E['dates']['deadline']; $dTs = $at($D, $win[1] - 1); $launchDay = max(strtotime($from), strtotime($D) - 7 * 86400); $span = (int)round((strtotime($D) - strtotime(date('Y-m-d', $startTs))) / 86400);
        $launchTs = max($startTs, $at(date('Y-m-d', $launchDay), $h0)); if ($launchTs > $dTs) $launchTs = $startTs;
        $W[] = ['id' => 'w1', 'kind' => 'launch', 'title' => 'Launch', 'start_ts' => $launchTs, 'groups' => $gids, 'role' => 'main', 'cond' => '', 'note' => 'Everyone in the audience'];
        $remTs = $at(date('Y-m-d', strtotime($D) - 3 * 86400), $h0); $lastTs = $at(date('Y-m-d', strtotime($D) - 86400), $h0);
        if ($span >= 4 && $remTs > $launchTs + 86400) $W[] = ['id' => 'w2', 'kind' => 'reminder', 'title' => 'Reminder', 'start_ts' => $remTs, 'groups' => $gids, 'role' => 'reminder', 'cond' => 'no_reply', 'note' => 'Only people who have not replied or ordered', 'after' => 'w1'];
        if ($span >= 2 && $lastTs > $launchTs + 3600) $W[] = ['id' => 'w' . (count($W) + 1), 'kind' => 'last', 'title' => 'Last 24 hours', 'start_ts' => $lastTs, 'groups' => $gids, 'role' => 'last', 'cond' => 'no_reply', 'note' => 'Only people who have not replied or ordered', 'after' => 'w1'];
        $W[] = ['id' => 'stop', 'kind' => 'stop', 'title' => 'Stop at the deadline', 'start_ts' => $dTs, 'groups' => $gids, 'role' => '', 'cond' => '', 'note' => 'Whatever is not sent by then is dropped'];
        return $W;
    }
    $title = ['check_in' => 'Gentle check-in', 'winback' => 'Win-back drip', 'launch' => 'Launch', 'restock' => 'Restock news', 'info' => 'Message'][$E['intent']] ?? 'Message';
    $W[] = ['id' => 'w1', 'kind' => 'drip', 'title' => $title, 'start_ts' => $startTs, 'end_ts' => $at($to, $win[1] - 1), 'groups' => $gids, 'role' => 'main', 'cond' => '', 'note' => 'Warmest customers first, a few thousand a day within each number\'s room'];
    $fu = ['none' => 0, 'one' => 1, 'two' => 2][$E['followup']] ?? 0; $days = [4, 9];
    for ($i = 0; $i < $fu; $i++) $W[] = ['id' => 'w' . (count($W) + 1), 'kind' => 'followup', 'title' => 'Follow-up ' . ($i + 1), 'start_ts' => $at(date('Y-m-d', $startTs + $days[$i] * 86400), $h0), 'groups' => $gids, 'role' => 'followup', 'cond' => 'no_reply', 'note' => 'After ' . $days[$i] . ' days, only people who did not reply or order', 'after' => 'w1'];
    return $W;
}
function wsm_cost(PDO $pdo, array $E, array $groups, array $waves): array
{
    $cfg = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 1.09, 'rate_utility' => 0.15]; $rm = (float)$cfg['rate_marketing']; $ru = (float)$cfg['rate_utility']; $start = function_exists('wa_mix_cfg') ? (int)wa_mix_cfg($pdo, 'wa_mix_start', 10) : 10;
    $first = 0; $uMax = 0; $later = 0; $touch = wsm_max_touches($E);
    foreach ($groups as $g) { $n = (int)$g['sendable']; $first += $n; if (!empty($g['util']['ok'])) $uMax += min($n, (int)$g['util']['eligible']); $k = count(array_filter($waves, fn($w) => in_array($w['kind'], ['reminder', 'last', 'followup'], true)));
        $f = 1.0; for ($i = 0; $i < min($k, $touch - 1); $i++) { $f *= 0.85; $later += (int)round($n * $f); } }
    $uEst = (int)floor($uMax * $start / 100); $msgs = $first + $later; $mEst = $msgs - $uEst; $total = round($mEst * $rm + $uEst * $ru, 2); $allM = round($msgs * $rm, 2);
    $b = (float)$E['budget']; $over = $b > 0 && $total > $b;
    return ['messages' => $msgs, 'first_touch' => $first, 'later_touch' => $later, 'marketing' => $mEst, 'utility' => $uEst, 'utility_possible' => $uMax, 'rate_marketing' => $rm, 'rate_utility' => $ru, 'total' => $total, 'if_all_marketing' => $allM, 'budget' => $b, 'over' => $over,
        'verdict' => $b > 0 ? ($over ? 'About ' . wsm_money($total - $b) . ' over your cap of ' . wsm_money($b) . '. Shrink the audience or raise the cap.' : 'Inside your cap of ' . wsm_money($b) . '.') : 'No cap set.'];
}
function wsm_money($n): string { return '₹' . number_format((float)$n, 0, '.', ','); }

// ------------------------------------------------------------------------------------------------------------------ the plan
function wsm_plan_row(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare("SELECT * FROM wa_smart_plans WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) return null;
    $r['brief'] = wsm_arr($r['brief']); $r['plan'] = wsm_arr($r['plan']); $r['report'] = wsm_arr($r['report']); $r['id'] = (int)$r['id']; $r['rev'] = (int)$r['rev']; return $r;
}
function wsm_plan_save(PDO $pdo, int $id, array $plan, ?array $brief = null, ?string $status = null, ?string $title = null): void
{
    $sets = ['plan = ?', 'updated_at = ?']; $args = [wsm_j($plan), wsm_dt(wsm_now())];
    if ($brief !== null) { $sets[] = 'brief = ?'; $args[] = wsm_j($brief); } if ($status !== null) { $sets[] = 'status = ?'; $args[] = $status; } if ($title !== null) { $sets[] = 'title = ?'; $args[] = mb_substr($title, 0, 160); }
    $args[] = $id; $pdo->prepare("UPDATE wa_smart_plans SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
}
function wsm_rev(PDO $pdo, int $planId, int $rev, string $who, string $kind, string $note, $diff = null): void
{
    try { $pdo->prepare("INSERT INTO wa_smart_rev (plan_id, rev, at, who, kind, note, diff) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$planId, $rev, wsm_dt(wsm_now()), mb_substr($who, 0, 60), $kind, mb_substr($note, 0, 300), $diff === null ? null : wsm_j($diff)]); } catch (Throwable $e) { }
}
function wsm_example_text(string $text, array $o): string
{
    [$body, $tokens, , $ex] = wsm_tpl_body($text, $o); return preg_replace_callback('/\{\{(\d+)\}\}/', fn($m) => $ex[(int)$m[1] - 1] ?? $m[0], $body) ?? $body;
}
function wsm_make_msg(PDO $pdo, string $text, array $g, int $cap, bool $offerOn, string $role): array
{
    [$t, $flags] = wsm_clean_msg($pdo, $text, $cap, $offerOn); return ['role' => $role, 'text' => $t, 'flags' => $flags, 'tpl_m' => 0, 'tpl_u' => 0, 'example' => wsm_example_text($t, $g['offer'])];
}
/** Which messages of a group really go out (so which pre-made messages have to exist). Returns [key => role] where key is "A" / "B" / "C" / "reminder" / "last" / "followup". */
function wsm_needed(array $g, array $plan): array
{
    $n = []; if (!empty($g['rotate'])) foreach (['A', 'B', 'C'] as $k) $n[$k] = 'main'; else $n[(string)($g['chosen'] ?? 'A')] = 'main';
    foreach ((array)($plan['waves'] ?? []) as $w) if (in_array($w['kind'], ['reminder', 'last', 'followup'], true) && in_array($g['id'], (array)$w['groups'], true)) $n[$w['role']] = $w['role'];
    return $n;
}
/** Makes / refreshes the DRAFT pre-made messages of the plan (marketing + utility twin where it is true). Nothing is sent to Meta. Returns warnings. */
function wsm_sync_templates(PDO $pdo, array &$plan, int $planId, int $by): array
{
    $W = []; $media = (array)($plan['media'] ?? []);
    foreach ($plan['groups'] as $gi => &$g) {
        foreach (wsm_needed($g, $plan) as $key => $role) {
            $isMain = $role === 'main'; if ($isMain) $ref = &$g['options'][$key]; else $ref = &$g['wave_msgs'][$key]; if (!is_array($ref)) { unset($ref); continue; }
            $name = 'sm' . $planId . '_' . $g['id'] . '_' . strtolower($key) . '_' . wsm_lang_code($g['lang']); $d = wsm_tpl_draft($name, $ref['text'], $g['lang'], $g['offer'], $media);
            try { [$m, $u, $warn] = wsm_tpl_save($pdo, $ref, $d['draft'], $isMain && !empty($g['util']['ok']), $by, 'Smart campaign #' . $planId . ' · ' . $g['title']); $ref['tpl_m'] = $m; $ref['tpl_u'] = $u; $ref['tokens'] = $d['tokens']; $ref['specs'] = $d['specs']; foreach ($warn as $x) $W[] = $g['title'] . ' ' . $key . ': ' . $x; }
            catch (Throwable $e) { $ref['error'] = $e->getMessage(); $W[] = $g['title'] . ' ' . $key . ': ' . $e->getMessage(); }
            unset($ref);
        }
    }
    unset($g); return $W;
}
function wsm_stage_mix(PDO $pdo, array $c): string
{
    $stl = wsm_stage_labels($pdo); $vl = wsm_value_labels($pdo); $p = []; foreach ((array)$c['by_stage'] as $k => $v) if ((int)($v['total'] ?? 0) > 0 && $k !== 'unbuilt') $p[] = ($stl[$k]['label'] ?? $k) . ' ' . wsm_n($v['total']);
    $q = []; foreach ((array)$c['by_value'] as $k => $v) if ((int)($v['total'] ?? 0) > 0 && $k !== 'unbuilt') $q[] = ($vl[$k]['label'] ?? $k) . ' ' . wsm_n($v['total']);
    return implode(' · ', array_merge($p, $q));
}
function wsm_build_plan(PDO $pdo, string $text, array $answers, int $planId, int $by, ?array $u = null): array
{
    $now = wsm_now(); $U = $u && !empty($u['intent']) ? $u + ['why' => [], 'sug' => [], 'dates' => [], 'line' => '', 'used' => [], 'words' => ''] : wsm_understand($pdo, $text, $answers);
    $answers['_text'] = $text; $E = wsm_effective($pdo, $U, $answers);
    if ($E['intent'] === 'deadline_offer') { if ($E['dates']['deadline'] === '') throw new RuntimeException('NEEDS_DEADLINE'); if (strtotime($E['dates']['deadline']) < strtotime(date('Y-m-d', $now))) throw new RuntimeException('The offer deadline is already over. Pick a later date.'); }
    $groups = wsm_groups($pdo, $E); if (!$groups) throw new RuntimeException('Nobody matches these choices (after leaving out opted-out and recently messaged customers). Widen the audience.');
    $base = wsm_count($pdo, $E['filters']); $cap = (int)$E['offer']['cap']; $pct = (int)$E['offer']['pct']; $type = $E['offer']['type']; $offerOn = $type !== 'none'; $dl = $E['dates']['deadline'];
    $G = []; foreach ($groups as $i => $x) {
        $c = $x['cnt']; $id = 'g' . ($i + 1); $code = $type === 'coupon' ? 'TM' . $pct . chr(65 + $i) : '';
        $G[] = ['id' => $id, 'title' => $x['title'], 'bucket' => $x['bucket'], 'lang' => $x['lang'], 'lang_label' => wsm_lang_label($x['lang']), 'filters' => $x['filters'], 'count' => $c['total'], 'sendable' => $c['sendable'], 'by_stage' => $c['by_stage'], 'by_value' => $c['by_value'], 'stage_mix' => wsm_stage_mix($pdo, $c),
            'util' => wsm_group_util($pdo, $x['filters'], (int)$c['sendable']), 'offer' => ['type' => $type, 'pct' => $pct, 'code' => $code, 'text' => wsm_offer_text($type, $pct), 'deadline' => $dl, 'deadline_text' => $dl !== '' ? wsm_date_nice($dl) : ''],
            'rotate' => $c['sendable'] >= 300, 'chosen' => 'A', 'options' => [], 'wave_msgs' => []];
    }
    unset($groups);
    try { $L = wsm_learned($pdo); } catch (Throwable $e) { $L = []; }
    foreach ($G as $gi => $g0) {
        $l = $L[$g0['bucket'] . '|' . $g0['lang']] ?? null;
        if ($l && !empty($l['winner'])) { $G[$gi]['chosen'] = $l['winner']; $G[$gi]['rotate'] = false; $G[$gi]['learned'] = $l['note']; }
        elseif ((int)$g0['sendable'] >= 60) { $G[$gi]['rotate'] = true; $G[$gi]['learned'] = 'Still learning for ' . $g0['title'] . ' (' . $g0['lang_label'] . '): A, B and C are rotated so the best angle shows up.'; }
    }
    $waves = wsm_waves($pdo, $E, array_column($G, 'id'), $now); $plan = ['waves' => $waves, 'groups' => $G];
    // the words
    $ai = wsm_ai_messages($pdo, $E, $G, $text); $src = $ai ? 'claude' : 'rules';
    foreach ($G as $gi => $g) {
        $roles = ['A', 'B', 'C']; foreach ($waves as $w) if (in_array($w['kind'], ['reminder', 'last', 'followup'], true) && !in_array($w['role'], $roles, true)) $roles[] = $w['role'];
        foreach ($roles as $r) {
            $txt = trim((string)($ai[$g['id']][$r] ?? '')); $fb = false; if ($txt === '' || mb_strlen($txt) < 8) { $txt = wsm_fallback_msg($g['lang'], $E['intent'], $r, ['offer' => $type, 'perfumes' => $E['perfumes']]); $fb = true; }
            $m = wsm_make_msg($pdo, $txt, $g, $cap, $offerOn, in_array($r, ['A', 'B', 'C'], true) ? 'main' : $r); if ($fb) $m['flags'][] = 'written by rules (Claude was not available)';
            if (in_array($r, ['A', 'B', 'C'], true)) $G[$gi]['options'][$r] = $m; else $G[$gi]['wave_msgs'][$r] = $m;
        }
    }
    $plan['groups'] = $G; $total = array_sum(array_column($G, 'sendable')); $sp = wsm_speed($pdo, $total, $E['speed']); $room = array_sum(array_map(fn($r) => $r['usable'] ? $r['left'] : 0, $sp['numbers']));
    $numIds = array_values(array_map(fn($r) => $r['id'], array_filter($sp['numbers'], fn($r) => $r['usable'])));
    foreach ($plan['groups'] as &$g) {
        $g['numbers'] = $numIds; $g['send'] = ['hours' => implode(' and ', array_map('wsm_hours_label', $E['hours'])), 'days' => wsm_when_text($E)];
        $fu = ['none' => 0, 'one' => 1, 'two' => 2][$E['followup']] ?? 0; $g['followup'] = $E['intent'] === 'deadline_offer' ? ['n' => 0, 'text' => 'The reminders before the deadline do this job.'] : ['n' => $fu, 'after_days' => $fu ? [4, 9][0] : 0, 'text' => $fu ? 'After 4' . ($fu > 1 ? ' and 9' : '') . ' days, only people who did not reply or order. Stops after that.' : 'No follow-up.', 'stop_rule' => 'Anyone who replies, orders, opts out or complains is removed at once.'];
    }
    unset($g);
    $tw = 0; foreach ($numIds as $_) $tw++; $shareTot = max(1, array_sum(array_map(fn($r) => $r['usable'] ? $r['left'] : 0, $sp['numbers']))); $nrows = [];
    foreach ($sp['numbers'] as $r) $nrows[] = $r + ['share_pct' => $r['usable'] ? (int)round(100 * $r['left'] / $shareTot) : 0, 'est_msgs' => $r['usable'] ? (int)round($total * $r['left'] / $shareTot) : 0];
    $plan += ['intent' => $E['intent'], 'intent_label' => wsm_intents()[$E['intent']] ?? '', 'message_source' => $src];
    $plan['dates'] = ['from' => $E['dates']['from'], 'to' => $E['dates']['to'], 'deadline' => $dl, 'start_ts' => (int)$waves[0]['start_ts'], 'start' => wsm_dt((int)$waves[0]['start_ts']), 'future' => (int)$waves[0]['start_ts'] > $now + 3600, 'hours' => $E['hours']];
    $plan['settings'] = ['max_touches' => wsm_max_touches($E), 'freq_days' => 0, 'speed' => $E['speed'], 'push' => $sp['push'], 'rate' => $sp['rate'], 'retries' => 3, 'test_first' => $E['test_first'], 'reply_owner' => $E['reply_owner'], 'followup' => $E['followup'], 'goal' => $E['goal'], 'attr_days' => wsm_cfg_i($pdo, 'wa_smart_attr_days', 7), 'budget' => $E['budget'], 'discount_cap' => $cap, 'media' => $E['media']];
    $plan['media'] = $E['media']; $plan['audience'] = ['filters' => $E['filters'], 'total' => $base['total'], 'sendable' => $base['sendable'], 'adds_up' => $base['adds_up'], 'excluded' => $base['excluded'], 'unbuilt' => $base['unbuilt'], 'groups_sum' => $total, 'groups_ok' => $total === (int)$base['sendable']];
    $plan['numbers'] = ['rows' => $nrows, 'speed' => $sp['speed'], 'text' => $sp['text'], 'minutes' => $sp['minutes'], 'days' => $sp['days'], 'room_today' => $room, 'per_min' => $sp['per_min']];
    $plan['cost'] = wsm_cost($pdo, $E, $plan['groups'], $waves);
    $stl = wsm_stage_labels($pdo); $dec = wsm_decisions($pdo);
    $und = ['goal' => ['v' => ['reply' => 'Get a reply', 'order' => 'Get an order', 'store' => 'Visit the store', 'reactivate' => 'Reactivate quiet customers'][$E['goal']] ?? $E['goal'], 'src' => array_key_exists('goal', $answers) && $answers['goal'] !== 'claude' ? 'you' : 'claude'],
        'when' => ['v' => wsm_when_text($E), 'src' => (!empty($U['dates']['month']) || !empty($U['dates']['deadline']) || array_key_exists('dates', $answers)) && ($answers['dates'] ?? '') !== 'claude' ? 'you' : 'claude'], 'who' => ['v' => wsm_n($base['sendable']) . ' people', 'src' => array_key_exists('stage', $answers) && $answers['stage'] !== 'claude' ? 'you' : 'claude'],
        'offer' => ['v' => $type === 'none' ? 'No offer' : ($type === 'coupon' ? 'Coupon, ' . $pct . '% (never above ' . $cap . '%)' : ucfirst(str_replace('_', ' ', $type))), 'src' => array_key_exists('offer', $answers) && $answers['offer'] !== 'claude' ? 'you' : 'claude'], 'words' => ['v' => $U['words'] . ', per customer', 'src' => 'claude'], 'speed' => ['v' => ucfirst($E['speed']), 'src' => array_key_exists('speed', $answers) && $answers['speed'] !== 'claude' ? 'you' : 'claude']];
    $plan['understood'] = $und; $plan['confirm'] = wsm_confirm_line($pdo, $E, $base);
    $warn = []; $co = []; foreach ($plan['groups'] as $g) if ($g['offer']['code'] !== '') $co[] = $g['offer']['code'];
    if ($co) $warn[] = 'Create the coupon code' . (count($co) > 1 ? 's ' : ' ') . implode(', ', $co) . ' in Shopify for ' . $pct . '% off before you approve — HRMS does not create coupons.';
    if ($base['unbuilt'] > 0) $warn[] = wsm_n($base['unbuilt']) . ' customers are not analysed yet (their stage is unknown) and are not in any group.';
    if (!$plan['audience']['groups_ok']) $warn[] = 'The groups add up to ' . wsm_n($total) . ' but the audience is ' . wsm_n($base['sendable']) . ' — some customers have no stage yet.';
    if ($sp['numbers_used'] === 0) $warn[] = 'No number can send right now (quality, connection or room). The campaign would wait.';
    if ($plan['dates']['future']) $warn[] = 'The audience is picked again on the start day, so people who order meanwhile are left out. Messages are sent to Meta for approval at once and re-checked 7 days before the start.';
    if ($plan['cost']['over']) $warn[] = $plan['cost']['verdict'];
    $plan['warnings'] = $warn;
    $plan['safeguards'] = ['Opted-out customers are never messaged.', 'Each customer gets at most ' . $plan['settings']['max_touches'] . ' messages from this plan.',
        'Anyone who replies, orders, opts out or complains is removed from every later message.', 'A number whose quality drops is slowed down or paused by itself.', 'The discount is ' . ($type === 'coupon' ? $pct . '% for everybody (your limit: ' . $cap . '%)' : 'the same for everybody') . '; only the wording and the code change by group.'];
    $pk = wsm_pack($pdo, 'campaign_plan'); $used = $pk['used']; array_unshift($used, wsm_n($base['sendable']) . ' customers in the audience'); if ($dec !== '') $used[] = "owner's decisions";
    $plan['based_on'] = $used; $plan['decisions'] = $dec !== '' ? preg_split('/\n/', trim($dec)) : []; $plan['built_at'] = wsm_dt($now); $plan['text'] = $text; $plan['rev_note'] = '';
    $plan['warnings'] = array_merge($plan['warnings'], wsm_sync_templates($pdo, $plan, $planId, $by));
    return [$plan, $E];
}
/** The ids of every pre-made message (marketing + utility) a plan points to. */
function wsm_plan_tpl_ids(array $plan): array
{
    $o = []; foreach ($plan['groups'] ?? [] as $g) foreach (['options', 'wave_msgs'] as $k) foreach ((array)($g[$k] ?? []) as $m) foreach (['tpl_m', 'tpl_u'] as $f) if ((int)($m[$f] ?? 0) > 0) $o[(int)$m[$f]] = true; return array_keys($o);
}
/** Draft messages the plan no longer uses (it was made again or a group was dropped) are removed; a message already sent to Meta is never touched. */
function wsm_gc_drafts(PDO $pdo, array $old, array $new): void
{
    $keep = array_flip(wsm_plan_tpl_ids($new));
    foreach (wsm_plan_tpl_ids($old) as $id) {
        if (isset($keep[$id])) continue;
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); if ((int)$q->fetchColumn() > 0) continue; $q = $pdo->prepare("SELECT source FROM wa_templates WHERE id = ?"); $q->execute([$id]); if ((string)$q->fetchColumn() !== 'hrms') continue;
            $pdo->prepare("DELETE FROM wa_templates WHERE id = ?")->execute([$id]); } catch (Throwable $e) { }
    }
}
/** Creates or rebuilds a plan. Returns the stored plan row. */
function wsm_plan_make(PDO $pdo, string $text, array $answers, int $by, string $who, int $planId = 0, ?array $u = null): array
{
    wsm_tables($pdo); $new = false;
    if ($planId > 0) { $r = wsm_plan_row($pdo, $planId); if (!$r) throw new RuntimeException('Plan not found.'); if ($r['status'] !== 'draft') throw new RuntimeException('This plan is already approved. Copy it to change it.'); }
    else { $pdo->prepare("INSERT INTO wa_smart_plans (title, status, brief, plan, created_by, created_at, updated_at, rev) VALUES ('', 'draft', '{}', '{}', ?, ?, ?, 1)")->execute([$by, wsm_dt(wsm_now()), wsm_dt(wsm_now())]); $planId = (int)$pdo->lastInsertId(); $new = true; }
    $oldPlan = !$new && !empty($r['plan']) ? $r['plan'] : [];
    try { [$plan, $E] = wsm_build_plan($pdo, $text, $answers, $planId, $by, $u); }
    catch (Throwable $e) { if ($new) { $pdo->prepare("DELETE FROM wa_smart_plans WHERE id = ?")->execute([$planId]); } throw $e; }
    if ($oldPlan) wsm_gc_drafts($pdo, $oldPlan, $plan);
    $title = (wsm_intents()[$E['intent']] ?? 'Campaign') . ' · ' . wsm_short($text, 60);
    $rev = 1; if (!$new) { $rev = (int)$pdo->query("SELECT rev FROM wa_smart_plans WHERE id = " . (int)$planId)->fetchColumn() + 1; }
    $pdo->prepare("UPDATE wa_smart_plans SET rev = ? WHERE id = ?")->execute([$rev, $planId]);
    wsm_plan_save($pdo, $planId, $plan, ['text' => $text, 'answers' => $answers, 'effective' => $E, 'u' => $u], 'draft', $title); wsm_rev($pdo, $planId, $rev, $who, 'plan', $new ? 'Plan made' : 'Plan made again');
    return wsm_plan_row($pdo, $planId);
}
function wsm_plan_public(array $r, bool $full = true): array
{
    $o = ['id' => $r['id'], 'title' => (string)$r['title'], 'status' => (string)$r['status'], 'rev' => $r['rev'], 'created_at' => (string)$r['created_at'], 'updated_at' => (string)$r['updated_at'], 'approved_at' => (string)($r['approved_at'] ?? ''), 'clone_of' => (int)($r['clone_of'] ?? 0)];
    if ($full) $o += ['plan' => $r['plan'], 'brief' => ['text' => $r['brief']['text'] ?? '', 'answers' => $r['brief']['answers'] ?? []], 'has_report' => !empty($r['report'])];
    else { $p = $r['plan']; $o += ['intent' => $p['intent'] ?? '', 'intent_label' => $p['intent_label'] ?? '', 'sendable' => (int)($p['audience']['sendable'] ?? 0), 'groups' => count($p['groups'] ?? []), 'start' => $p['dates']['start'] ?? '', 'cost' => $p['cost']['total'] ?? 0, 'text' => $r['brief']['text'] ?? '', 'has_report' => !empty($r['report'])]; }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------ edit / test / clone
function wsm_group_idx(array $plan, string $id): int { foreach ($plan['groups'] as $i => $g) if ($g['id'] === $id) return $i; return -1; }
/** One change: {group, option?|role?, instruction|text|swap|rotate|drop}. Returns [plan, diff[], proposed_decisions[]]. */
function wsm_edit(PDO $pdo, array $row, array $in, string $who): array
{
    $plan = $row['plan']; $gi = wsm_group_idx($plan, (string)($in['group'] ?? '')); if ($gi < 0) throw new RuntimeException('Group not found.'); $g = $plan['groups'][$gi]; $diff = []; $prop = [];
    $key = (string)($in['option'] ?? ($in['role'] ?? ($g['chosen'] ?? 'A'))); $cap = (int)($plan['settings']['discount_cap'] ?? 10); $offerOn = ($g['offer']['type'] ?? 'none') !== 'none';
    if (!empty($in['drop'])) {
        if (count($plan['groups']) <= 1) throw new RuntimeException('A plan needs at least one group.');
        $diff[] = ['what' => 'Group dropped', 'before' => $g['title'] . ' (' . wsm_n($g['sendable']) . ' people)', 'after' => '—']; array_splice($plan['groups'], $gi, 1);
        foreach ($plan['waves'] as &$w) $w['groups'] = array_values(array_diff((array)$w['groups'], [$g['id']])); unset($w);
    } elseif (array_key_exists('rotate', $in)) { $new = !empty($in['rotate']); $diff[] = ['what' => 'Rotate A/B/C', 'before' => !empty($g['rotate']) ? 'on' : 'off', 'after' => $new ? 'on' : 'off']; $plan['groups'][$gi]['rotate'] = $new; }
    elseif (!empty($in['swap'])) { $s = (string)$in['swap']; if (!isset($g['options'][$s])) throw new RuntimeException('There is no option ' . $s . '.'); $diff[] = ['what' => 'Message used', 'before' => (string)$g['chosen'], 'after' => $s]; $plan['groups'][$gi]['chosen'] = $s; $plan['groups'][$gi]['rotate'] = false; }
    else {
        $isOpt = isset($g['options'][$key]); $isWave = isset($g['wave_msgs'][$key]); if (!$isOpt && !$isWave) throw new RuntimeException('That message does not exist.');
        $old = $isOpt ? $g['options'][$key]['text'] : $g['wave_msgs'][$key]['text']; $new = '';
        if (isset($in['text']) && trim((string)$in['text']) !== '') $new = trim((string)$in['text']);
        elseif (!empty($in['instruction'])) {
            $ins = mb_substr(trim((string)$in['instruction']), 0, 500); $pk = wsm_pack($pdo, 'message_write'); $dec = wsm_decisions($pdo);
            $sch = ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING']], 'required' => ['text']];
            $sys = "You rewrite ONE WhatsApp campaign message of TM Perfume House as the owner asks. Keep the language (" . wsm_lang_label($g['lang']) . "), keep the variables {{name}} {{perfume}} {{days}} {{offer}} {{deadline}} {{code}} that are there, never start or end with a variable, never name a real designer brand, never write a price, discount at most " . $cap . "%.\n" . $pk['text'] . ($dec !== '' ? "\nTHE OWNER'S DECISIONS (always obey):\n" . $dec : '');
            [$ok, $v] = wsm_ai($pdo, $sys, "Message:\n" . $old . "\n\nThe owner says: " . $ins, $sch, 1200);
            if ($ok && is_array($v) && trim((string)($v['text'] ?? '')) !== '') $new = trim((string)$v['text']);
            else {   // rules only: the plainest requests
                if (preg_match('/short|chhota|small/i', $ins)) { $parts = preg_split('/(?<=[.!?।])\s+/u', $old) ?: [$old]; $new = trim(implode(' ', array_slice($parts, 0, 2))); }
                if ($new === '' || $new === $old) throw new RuntimeException('Claude could not be reached just now. Type the new wording yourself, or try again.');
            }
            if (function_exists('wa_dec_extract')) { try { $x = wa_dec_extract($pdo, $ins); if (!empty($x['proposals'])) $prop = $x['proposals']; } catch (Throwable $e) { } }
        } else throw new RuntimeException('Tell Claude what to change, or type the new wording.');
        $m = wsm_make_msg($pdo, $new, $g, $cap, $offerOn, $isOpt ? 'main' : $key); $prev = $isOpt ? $g['options'][$key] : $g['wave_msgs'][$key]; $m['tpl_m'] = $prev['tpl_m'] ?? 0; $m['tpl_u'] = $prev['tpl_u'] ?? 0;
        if ($isOpt) $plan['groups'][$gi]['options'][$key] = $m; else $plan['groups'][$gi]['wave_msgs'][$key] = $m;
        $diff[] = ['what' => 'Message ' . $key . ' · ' . $g['title'], 'before' => $old, 'after' => $m['text'], 'flags' => $m['flags']];
    }
    $w = wsm_sync_templates($pdo, $plan, $row['id'], 0); $plan['warnings'] = array_values(array_unique(array_merge(array_filter((array)$plan['warnings'], fn($x) => strpos($x, ': ') === false || strpos($x, 'Create the coupon') === 0), $w)));
    $tot = array_sum(array_column($plan['groups'], 'sendable')); $E = $row['brief']['effective'] ?? []; if ($E) { $plan['cost'] = wsm_cost($pdo, $E, $plan['groups'], $plan['waves']); $sp = wsm_speed($pdo, $tot, $plan['settings']['speed']); $plan['numbers']['text'] = $sp['text']; $plan['numbers']['minutes'] = $sp['minutes']; $plan['numbers']['days'] = $sp['days']; }
    $plan['audience']['groups_sum'] = $tot; return [$plan, $diff, $prop];
}
/** The admin's own number: the employee's mobile, as a chat. */
function wsm_admin_phone(PDO $pdo, array $auth): string
{
    $e = (int)($auth['employee_id'] ?? 0);
    if ($e > 0) { try { $q = $pdo->prepare("SELECT mobile FROM employees WHERE employee_id = ?"); $q->execute([$e]); $k = function_exists('wa_key') ? wa_key((string)$q->fetchColumn()) : ''; if (strlen($k) === 10) return $k; } catch (Throwable $e2) { } }
    $k = function_exists('wa_key') ? wa_key(wsm_get($pdo, 'wa_smart_test_phone', '')) : ''; return strlen($k) === 10 ? $k : '';   // the owner may have no employee profile: the number typed once on the test button
}
function wsm_test_send(PDO $pdo, array $row, array $in, array $auth): array
{
    $plan = $row['plan']; $gi = wsm_group_idx($plan, (string)($in['group'] ?? '')); if ($gi < 0) throw new RuntimeException('Group not found.'); $g = $plan['groups'][$gi]; $key = (string)($in['option'] ?? ($g['chosen'] ?? 'A'));
    $m = $g['options'][$key] ?? ($g['wave_msgs'][$key] ?? null); if (!$m) throw new RuntimeException('That message does not exist.');
    $ph = wsm_admin_phone($pdo, $auth); $typed = function_exists('wa_key') ? wa_key((string)($in['phone'] ?? '')) : '';
    if ($ph === '' && strlen($typed) === 10 && wa_valid_phone($typed)) { wsm_put($pdo, 'wa_smart_test_phone', $typed); $ph = $typed; }   // saved once; tests only ever go to this number
    if ($ph === '') throw new RuntimeException('Your own mobile number is not saved (employee profile), so the test cannot be sent. Type your own WhatsApp number once and press Test again.');
    $c = wa_ensure_contact($pdo, $ph, 'Test'); if ((int)$c['optout'] === 1) throw new RuntimeException('Your own number is opted out.');
    [$ok, $r] = wa_send_text($pdo, $c, "[TEST — " . $g['title'] . " · " . $key . "]\n" . $m['example'], 'person', (int)($auth['employee_id'] ?? 0));
    if (!$ok) throw new RuntimeException('The test could not be sent: ' . $r . ' Send "hi" to the WhatsApp number from your own phone first, then press Test again.');
    return ['ok' => true, 'to' => '+91 ' . substr($ph, 0, 5) . ' ' . substr($ph, 5), 'sent' => $m['example']];
}
function wsm_clone(PDO $pdo, array $row, array $changes, int $by, string $who): array
{
    $b = $row['brief']; $text = (string)($changes['text'] ?? ($b['text'] ?? '')); $ans = (array)($b['answers'] ?? []); foreach ((array)($changes['answers'] ?? []) as $k => $v) $ans[$k] = $v;
    foreach (['dates', 'deadline', 'stage', 'value', 'offer', 'goal', 'speed', 'followup', 'budget'] as $k) if (isset($changes[$k])) $ans[$k] = $changes[$k];
    $today = date('Y-m-d', wsm_now()); $d = (string)($ans['dates'] ?? ''); if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $d, $m) && !isset($changes['dates']) && $m[1] <= $today) {   // a past plan: the same shape, starting tomorrow
        $delta = (int)round((strtotime($today) + 86400 - strtotime($m[1])) / 86400); $ans['dates'] = date('Y-m-d', strtotime($m[1]) + $delta * 86400) . '..' . date('Y-m-d', strtotime($m[2]) + $delta * 86400);
        if (isset($ans['deadline']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ans['deadline']) && !isset($changes['deadline'])) $ans['deadline'] = date('Y-m-d', strtotime((string)$ans['deadline']) + $delta * 86400);
    }
    unset($ans['_decide_all']); $ans['_decide_all'] = 1;
    $r2 = wsm_plan_make($pdo, $text, $ans, $by, $who, 0, null); $pdo->prepare("UPDATE wa_smart_plans SET clone_of = ? WHERE id = ?")->execute([$row['id'], $r2['id']]); wsm_rev($pdo, $r2['id'], 1, $who, 'clone', 'Copied from plan #' . $row['id']); return wsm_plan_row($pdo, $r2['id']);
}

// ------------------------------------------------------------------------------------------------------------------ approve: templates -> every WhatsApp account (once), campaigns created and scheduled
/** Sends one pre-made message to every WhatsApp account that does not have it yet (idempotent). Returns [created, errors[]]. */
function wsm_submit_tpl(PDO $pdo, int $tplId, array &$bud): array
{
    $r = wa_tpl_row($pdo, $tplId); if (!$r) return [0, ['message ' . $tplId . ' not found']]; $wabas = wa_wabas($pdo); $q = $pdo->prepare("SELECT waba_id, status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$tplId]); $have = [];
    foreach ($q->fetchAll() as $x) if ($x['status'] !== 'REJECTED') $have[$x['waba_id']] = true;
    $err = wa_tpl_media_check($pdo, $r); if ($err !== '') return [0, [$err]]; $n = 0; $e = [];
    foreach ($wabas as $w) { if (isset($have[$w])) continue; [$ok, $why] = wa_tpl_try_create($pdo, $r, (string)$w, $bud); if ($ok) $n++; elseif (!preg_match('/limit|templates -|over the limit/', (string)$why)) $e[] = (string)$why; }
    return [$n, $e];
}
function wsm_campaign_insert(PDO $pdo, array $a): int
{
    $pdo->prepare("INSERT INTO wa_campaigns (name, tpl_id, audience, vars, rate, win_end, start_at, freq_days, switch_after, media_lib_id, media_folder, push, retry, own_hour, def_hour, extra, status, created_by, created_at, smart_plan) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 2, ?, '', ?, 1, 0, ?, ?, 'scheduled', ?, ?, ?)")
        ->execute([mb_substr($a['name'], 0, 80), $a['tpl_id'], wsm_j(['src' => 'contacts', 'f' => $a['filters'], 'smart' => 1]), wsm_j($a['vars']), $a['rate'], $a['start_at'], $a['freq'], $a['media_lib_id'], $a['push'], $a['def_hour'], wsm_j($a['extra']), $a['by'], wsm_dt(wsm_now()), $a['plan_id']]);
    return (int)$pdo->lastInsertId();
}
/** One campaign per group x wave x (rotating) message. Returns the list of created campaigns. */
function wsm_create_campaigns(PDO $pdo, array &$plan, int $planId, int $by): array
{
    $out = []; $base = []; $tot = max(1, array_sum(array_column($plan['groups'], 'sendable'))); $media = (int)($plan['media']['id'] ?? 0);
    $stopTs = 0; foreach ($plan['waves'] as $w) if ($w['kind'] === 'stop') $stopTs = (int)$w['start_ts'];
    foreach ($plan['waves'] as $w) {
        if ($w['kind'] === 'stop') continue;
        foreach ($plan['groups'] as $g) {
            if (!in_array($g['id'], (array)$w['groups'], true)) continue;
            if ($w['role'] === 'main') { $keys = !empty($g['rotate']) ? ['A', 'B', 'C'] : [(string)($g['chosen'] ?? 'A')]; $msgs = []; foreach ($keys as $k) $msgs[$k] = $g['options'][$k]; } else $msgs = [$w['role'] => $g['wave_msgs'][$w['role']] ?? null];
            $nM = count($msgs); $i = 0;
            foreach ($msgs as $key => $m) {
                if (!$m || (int)($m['tpl_m'] ?? 0) <= 0) throw new RuntimeException('The message ' . $key . ' of "' . $g['title'] . '" has no pre-made message yet: ' . ($m['error'] ?? 'it could not be made') . '.');
                $isMain = $w['role'] === 'main'; $x = ['smart_plan_id' => $planId, 'group' => $g['id'], 'wave' => $w['id'], 'kind' => $w['kind'], 'option' => (string)$key, 'bucket' => (string)($g['bucket'] ?? 'all'), 'lang' => (string)($g['lang'] ?? 'hinglish'), 'angle' => $isMain && isset(wsm_angles()[(string)$key]) ? (string)$key : '', 'aud_filters' => $g['filters'], 'resolve_at_start' => true, 'cond' => $w['cond'], 'mix' => (int)($m['tpl_u'] ?? 0) > 0, 'tpl_u' => (int)($m['tpl_u'] ?? 0),
                    'max_touches' => (int)$plan['settings']['max_touches'], 'hours' => $plan['dates']['hours'] ?? [], 'group_share' => round($g['sendable'] / $tot, 4)];
                if ($nM > 1) $x['split'] = ['n' => $nM, 'i' => $i];
                if ($stopTs > 0) $x['stop_at'] = $stopTs; if ($w['kind'] === 'drip') $x['drip'] = true; if (!empty($w['end_ts'])) $x['end_ts'] = (int)$w['end_ts'];
                if (!empty($w['after'])) $x['followup_of'] = array_values(array_map(fn($c) => $c['id'], array_filter($out, fn($c) => $c['wave'] === $w['after'] && $c['group'] === $g['id'])));
                if (!empty($w['after']) && empty($x['followup_of'])) throw new RuntimeException('Internal: the base wave of "' . $w['title'] . '" was not created.');
                $id = wsm_campaign_insert($pdo, ['name' => 'Smart #' . $planId . ' · ' . $g['title'] . ' · ' . $w['title'] . ($nM > 1 ? ' · ' . $key : ''), 'tpl_id' => (int)$m['tpl_m'], 'filters' => $g['filters'], 'vars' => $m['specs'] ?? [], 'rate' => (int)$plan['settings']['rate'], 'start_at' => wsm_dt((int)$w['start_ts']),
                    'freq' => $isMain ? (int)$plan['settings']['freq_days'] : 0, 'media_lib_id' => $media, 'push' => (string)$plan['settings']['push'], 'def_hour' => (int)($plan['dates']['hours'][0][0] ?? 10), 'extra' => $x, 'by' => $by, 'plan_id' => $planId]);
                $out[] = ['id' => $id, 'group' => $g['id'], 'wave' => $w['id'], 'key' => (string)$key, 'kind' => $w['kind']]; $i++;
            }
        }
    }
    return $out;
}
function wsm_approve(PDO $pdo, array $row, array $in, int $by, string $who): array
{
    if (!wsm_on($pdo)) throw new RuntimeException('Smart campaigns are switched off (setting wa_smart_on).');
    $planId = $row['id']; $plan = $row['plan'];
    if ($row['status'] !== 'draft') throw new RuntimeException('ALREADY');
    if (isset($in['rev']) && (int)$in['rev'] !== (int)$row['rev']) throw new RuntimeException('REV');
    if (!$plan || empty($plan['groups'])) throw new RuntimeException('There is nothing to approve: the plan has no group.');
    foreach ($plan['groups'] as $g) { if ((int)$g['sendable'] <= 0) throw new RuntimeException('"' . $g['title'] . '" has nobody to message.'); }
    if (!empty($plan['cost']['over']) && empty($in['over_budget_ok'])) throw new RuntimeException('OVER_BUDGET');
    if (!wa_wabas($pdo)) throw new RuntimeException('No WhatsApp Business Account ID yet — add one in WhatsApp → Setup.');
    $claim = $pdo->prepare("UPDATE wa_smart_plans SET status = 'approved', approved_at = ? WHERE id = ? AND status = 'draft'"); $claim->execute([wsm_dt(wsm_now()), $planId]); if ($claim->rowCount() !== 1) throw new RuntimeException('ALREADY');
    try {
        $warn = wsm_sync_templates($pdo, $plan, $planId, $by); $bud = ['left' => 80]; $sub = ['created' => 0, 'errors' => [], 'messages' => 0]; $seen = [];
        foreach ($plan['groups'] as $g) foreach (wsm_needed($g, $plan) as $key => $role) {
            $m = $role === 'main' ? ($g['options'][$key] ?? null) : ($g['wave_msgs'][$key] ?? null); if (!$m) continue;
            foreach ([(int)($m['tpl_m'] ?? 0), (int)($m['tpl_u'] ?? 0)] as $tid) { if ($tid <= 0 || isset($seen[$tid])) continue; $seen[$tid] = 1; [$n, $e] = wsm_submit_tpl($pdo, $tid, $bud); $sub['created'] += $n; $sub['messages']++; foreach ($e as $x) $sub['errors'][] = $g['title'] . ' ' . $key . ': ' . $x; }
        }
        if ($sub['errors'] && $sub['created'] === 0 && !$sub['messages']) throw new RuntimeException('Meta did not take the messages: ' . $sub['errors'][0]);
        $camps = wsm_create_campaigns($pdo, $plan, $planId, $by); $plan['campaigns'] = $camps; $plan['approved'] = ['at' => wsm_dt(wsm_now()), 'by' => $who, 'submitted' => $sub];
        wsm_plan_save($pdo, $planId, $plan); wsm_rev($pdo, $planId, $row['rev'], $who, 'approve', 'Approved: ' . count($camps) . ' campaigns, ' . $sub['messages'] . ' messages sent to Meta');
        if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Smart plan #' . $planId . ' approved: ' . count($camps) . ' campaigns');
        return ['ok' => true, 'id' => $planId, 'campaigns' => $camps, 'submitted' => $sub, 'warnings' => $warn];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE wa_smart_plans SET status = 'draft', approved_at = NULL WHERE id = ?")->execute([$planId]);   // nothing was started: the owner can fix it and approve again (messages already sent to Meta are not sent twice)
        throw $e;
    }
}

// ------------------------------------------------------------------------------------------------------------------ the engine side: audiences are picked ON THE START DAY, follow-ups only get people who did not react
function wsm_ordered_since(PDO $pdo, array $phones, string $since): array
{
    $o = []; if (!$phones) return $o;
    try {   // orders of the shop since a time, matched on the last 10 digits (cheap: created_at is indexed and the window is short)
        $q = $pdo->prepare("SELECT phone FROM shop_orders WHERE created_at >= ? AND phone <> ''"); $q->execute([$since]); $want = array_flip($phones);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) { $k = function_exists('wa_key') ? wa_key($p) : substr(preg_replace('/\D/', '', (string)$p), -10); if (isset($want[$k])) $o[$k] = true; }
    } catch (Throwable $e) { }
    try { $in = wsm_in($phones); $q = $pdo->prepare("SELECT phone FROM wa_cust WHERE n > 0 AND last_order >= ? AND phone IN ($in)"); $q->execute(array_merge([$since], $phones)); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $o[(string)$p] = true; } catch (Throwable $e) { }
    return $o;
}
function wsm_flag_complaints(PDO $pdo, array $phones): array
{
    $o = []; if (!$phones) return $o; try { $in = wsm_in($phones); $q = $pdo->prepare("SELECT phone FROM wa_cust_story WHERE phone IN ($in) AND FIND_IN_SET('complaint', flags) > 0"); $q->execute($phones); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $o[(string)$p] = true; } catch (Throwable $e) { } return $o;
}
function wsm_touches(PDO $pdo, int $planId, array $phones): array
{
    $o = []; if (!$phones) return $o; $in = wsm_in($phones);
    $q = $pdo->prepare("SELECT i.phone, COUNT(*) n FROM wa_camp_items i JOIN wa_campaigns k ON k.id = i.camp_id WHERE k.smart_plan = ? AND i.status = 'sent' AND i.phone IN ($in) GROUP BY i.phone"); $q->execute(array_merge([$planId], $phones)); foreach ($q->fetchAll() as $r) $o[(string)$r['phone']] = (int)$r['n']; return $o;
}
/** The hour a customer gets it: their usual hour when the owner allows it, else the nearest allowed hour. */
function wsm_pick_hour(?int $usual, array $ranges, int $def): int
{
    if (!$ranges) return $def; $best = null; $bd = 99;
    foreach ($ranges as [$a, $b]) { for ($h = $a; $h < $b; $h++) { $d = $usual === null ? abs($h - $def) : abs($h - $usual); if ($d < $bd) { $bd = $d; $best = $h; } } }
    return $best ?? $def;
}
/** Fills the items of a scheduled smart campaign on its start day and starts it. Returns the number of customers queued. */
function wsm_resolve_campaign(PDO $pdo, array $c): int
{
    $x = $c['extra']; $planId = (int)($x['smart_plan_id'] ?? 0); $id = (int)$c['id']; $now = wsm_now(); $maxT = max(1, (int)($x['max_touches'] ?? 2)); $split = $x['split'] ?? null;
    $plan = wsm_plan_row($pdo, $planId); $since = $plan ? (string)$plan['created_at'] : wsm_dt($now - 86400); $ins = $pdo->prepare("INSERT INTO wa_camp_items (camp_id, phone, name, send_at) VALUES (?, ?, ?, ?)");
    $drip = !empty($x['drip']); $ranges = []; foreach ((array)($x['hours'] ?? []) as $h) if (is_array($h) && count($h) === 2) $ranges[] = [(int)$h[0], (int)$h[1]]; $defH = (int)($ranges[0][0] ?? $c['def_hour'] ?? 10);
    $dayCap = 0; if ($drip) { $caps = function_exists('wa_number_caps') && function_exists('wa_camp_health') ? wa_number_caps($pdo, wa_camp_health($pdo)) : []; $cs = 0; foreach ($caps as $k => $v) if ($k !== '_port' && is_array($v)) $cs += (int)$v['cap']; $dayCap = max(200, (int)floor($cs * 0.8 * (float)($x['group_share'] ?? 1) / max(1, (int)($x['split']['n'] ?? 1)))); }
    $startTs = max($now, strtotime((string)$c['start_at'])); $day0 = strtotime(date('Y-m-d', $startTs)); $queued = 0; $buf = []; $seenPhones = [];
    $flush = function () use (&$buf, $pdo, $ins, &$queued, $drip, $ranges, $defH, $dayCap, $day0, $startTs, $id) {
        if (!$buf) return; $hrs = []; if ($drip && function_exists('wa_camp_user_hours')) { try { $hrs = wa_camp_user_hours($pdo, array_column($buf, 0)); } catch (Throwable $e) { } }
        $pdo->beginTransaction();
        try { foreach ($buf as [$ph, $nm]) { $sa = null;
                if ($drip) { $k = $dayCap > 0 ? intdiv($queued, $dayCap) : 0; $h = wsm_pick_hour(isset($hrs[$ph]) ? (int)$hrs[$ph] : null, $ranges, $defH); $t = wa_camp_send_at($pdo, $day0 + $k * 86400, $h, $ph); if ($t < $startTs) $t = wa_camp_clamp($pdo, $startTs + abs((int)crc32($ph)) % 900); $sa = wsm_dt($t); }   // their hour on day k; an hour already gone today goes out now (spread over 15 minutes)
                $ins->execute([$id, $ph, mb_substr((string)$nm, 0, 120), $sa]); $queued++; }
            $pdo->commit(); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $buf = [];
    };
    $accept = function (array $rows) use ($pdo, $planId, $maxT, $since, &$buf, &$seenPhones, $flush, $split, $x) {   // rows: [phone, name, since?]
        $ph = array_values(array_unique(array_column($rows, 'phone'))); if (!$ph) return;
        $opt = []; $in = wsm_in($ph); $q = $pdo->prepare("SELECT phone, optout FROM wa_contacts WHERE phone IN ($in)"); $q->execute($ph); foreach ($q->fetchAll() as $r) if ((int)$r['optout'] === 1) $opt[(string)$r['phone']] = true;
        $ord = wsm_ordered_since($pdo, $ph, !empty($x['followup_of']) ? '1970-01-01' : $since); $cmp = !empty($x['followup_of']) || true ? wsm_flag_complaints($pdo, $ph) : []; $tc = wsm_touches($pdo, $planId, $ph);
        foreach ($rows as $r) {
            $p = (string)$r['phone']; if (isset($seenPhones[$p]) || isset($opt[$p]) || isset($cmp[$p]) || ($tc[$p] ?? 0) >= $maxT || !wa_valid_phone($p)) continue;
            if (!empty($x['followup_of'])) { if (!empty($ord[$p]) && !empty($r['sent_at']) && wsm_order_after($pdo, $p, (string)$r['sent_at'])) continue; }
            elseif (isset($ord[$p])) continue;
            if ($split && (abs((int)crc32($p)) % (int)$split['n']) !== (int)$split['i']) continue;
            $seenPhones[$p] = 1; $buf[] = [$p, (string)$r['name']]; if (count($buf) >= 300) $flush();
        }
    };
    if (!empty($x['followup_of'])) {
        $ids = array_map('intval', (array)$x['followup_of']); $in = implode(',', $ids); $last = 0;
        while (true) {
            $q = $pdo->prepare("SELECT i.id, i.phone, i.name, i.sent_at FROM wa_camp_items i WHERE i.camp_id IN ($in) AND i.status = 'sent' AND i.id > ? AND NOT EXISTS (SELECT 1 FROM wa_messages m WHERE m.phone = i.phone AND m.dir = 'in' AND m.ts > i.sent_at) ORDER BY i.id LIMIT 1000");
            $q->execute([$last]); $rows = $q->fetchAll(); if (!$rows) break; $last = (int)end($rows)['id']; $accept($rows); if (count($rows) < 1000) break;
        }
    } else {
        $f = (array)($x['aud_filters'] ?? []); $passes = $drip ? [['last_in_to' => 30], []] : [[]];
        foreach ($passes as $pi => $extraF) {
            $chunk = []; $cut = date('Y-m-d H:i:s', time() - 31 * 86400);
            foreach (wa_aud_each($pdo, 'contacts', array_merge($f, $extraF)) as $r) {
                if ($pi === 1 && !empty($r['last_in_at']) && (string)$r['last_in_at'] > $cut) continue;   // already queued in the warm pass
                $chunk[] = ['phone' => $r['phone'], 'name' => $r['name']]; if (count($chunk) >= 500) { $accept($chunk); $chunk = []; }
            }
            if ($chunk) $accept($chunk);
        }
    }
    $flush();
    if ($queued > 0) $pdo->prepare("UPDATE wa_campaigns SET status = 'running', started_at = ?, note = '' WHERE id = ?")->execute([wsm_dt($now), $id]);
    else $pdo->prepare("UPDATE wa_campaigns SET status = 'done', started_at = ?, finished_at = ?, note = 'Nobody was left to message on the start day.' WHERE id = ?")->execute([wsm_dt($now), wsm_dt($now), $id]);
    $x['resolved_n'] = $queued; $x['resolved_at'] = wsm_dt($now); $pdo->prepare("UPDATE wa_campaigns SET extra = ? WHERE id = ?")->execute([wsm_j($x), $id]);
    return $queued;
}
function wsm_order_after(PDO $pdo, string $phone, string $sentAt): bool
{
    try { $q = $pdo->prepare("SELECT 1 FROM wa_cust WHERE phone = ? AND n > 0 AND last_order > ?"); $q->execute([$phone, $sentAt]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT phone FROM shop_orders WHERE created_at > ? AND phone <> '' AND phone LIKE ?"); $q->execute([$sentAt, '%' . $phone]); if ($q->fetchColumn()) return true; } catch (Throwable $e) { }
    return false;
}
/** Called at the start of every campaign tick (each minute). Starts due smart campaigns, ends offers at their deadline, keeps the plan status right, re-checks messages 7 days before a future start. Never throws. */
function wsm_tick(PDO $pdo, int $nowTs): array
{
    $o = ['resolved' => 0, 'stopped' => 0]; try { wsm_tables($pdo); } catch (Throwable $e) { } if (!wsm_has_col($pdo)) return $o;
    try {
        $t0 = microtime(true); $now = wsm_dt($nowTs);
        foreach ($pdo->query("SELECT id FROM wa_campaigns WHERE smart_plan > 0 AND status = 'scheduled' AND start_at <= '$now' ORDER BY start_at, id LIMIT 12")->fetchAll() as $r) {
            if (microtime(true) - $t0 > 25) break; $c = wa_camp_row($pdo, (int)$r['id']); if (!$c) continue;
            try { $o['resolved'] += wsm_resolve_campaign($pdo, $c) > 0 ? 1 : 0; } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'smart #' . $r['id'] . ' could not start: ' . $e->getMessage()); $pdo->prepare("UPDATE wa_campaigns SET note = ? WHERE id = ?")->execute([mb_substr('Could not start: ' . $e->getMessage(), 0, 190), $r['id']]); }
        }
        foreach ($pdo->query("SELECT id, extra, status FROM wa_campaigns WHERE smart_plan > 0 AND status IN ('scheduled', 'running', 'paused')")->fetchAll() as $r) {
            $x = wsm_arr($r['extra']); $st = (int)($x['stop_at'] ?? 0); if ($st > 0 && $st <= $nowTs) {
                $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = 'The offer ended' WHERE camp_id = ? AND status IN ('queued', 'retry')")->execute([$r['id']]);
                $pdo->prepare("UPDATE wa_campaigns SET status = 'done', finished_at = ?, note = 'The offer ended.' WHERE id = ?")->execute([$now, $r['id']]); $o['stopped']++;
            }
        }
        $plans = $pdo->query("SELECT id, status FROM wa_smart_plans WHERE status IN ('approved', 'running', 'paused')")->fetchAll();
        foreach ($plans as $p) {
            $q = $pdo->prepare("SELECT status, COUNT(*) n FROM wa_campaigns WHERE smart_plan = ? GROUP BY status"); $q->execute([$p['id']]); $c = []; foreach ($q->fetchAll() as $r) $c[$r['status']] = (int)$r['n']; $tot = array_sum($c); if (!$tot) continue;
            $live = ($c['running'] ?? 0); $sched = ($c['scheduled'] ?? 0); $pause = ($c['paused'] ?? 0); $new = $p['status'];
            if ($live > 0) $new = 'running'; elseif ($sched > 0) $new = $p['status'] === 'running' ? 'running' : 'approved'; elseif ($pause > 0) $new = 'paused'; else $new = 'done';
            if ($new !== $p['status']) $pdo->prepare("UPDATE wa_smart_plans SET status = ?, updated_at = ? WHERE id = ?")->execute([$new, $now, $p['id']]);
        }
        wsm_recheck($pdo, $nowTs);
    } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'smart tick: ' . $e->getMessage()); }
    return $o;
}
/** Plans that start within 7 days: if a message is still not approved anywhere, say so on the plan (once a day). */
function wsm_recheck(PDO $pdo, int $nowTs): void
{
    if (wsm_get($pdo, 'wsm_recheck_at', '') !== '' && (int)wsm_get($pdo, 'wsm_recheck_at', '0') > $nowTs - 3600) return; wsm_put($pdo, 'wsm_recheck_at', (string)$nowTs);
    foreach ($pdo->query("SELECT p.id FROM wa_smart_plans p WHERE p.status IN ('approved', 'running')")->fetchAll() as $pr) {
        $row = wsm_plan_row($pdo, (int)$pr['id']); if (!$row) continue; $plan = $row['plan']; $start = (int)strtotime((string)($plan['dates']['start'] ?? '')); if ($start <= $nowTs || $start > $nowTs + 7 * 86400) continue;
        $stuck = []; foreach ($plan['groups'] as $g) foreach (wsm_needed($g, $plan) as $key => $role) { $m = $role === 'main' ? ($g['options'][$key] ?? null) : ($g['wave_msgs'][$key] ?? null); $tid = (int)($m['tpl_m'] ?? 0); if ($tid > 0 && wa_mix_tpl_state($pdo, $tid, wa_wabas($pdo)) !== 'approved') $stuck[] = $g['title'] . ' · ' . $key; }
        $a = array_values(array_filter((array)($plan['alerts'] ?? []), fn($x) => ($x['kind'] ?? '') !== 'stuck'));
        if ($stuck) { $a[] = ['kind' => 'stuck', 'at' => wsm_dt($nowTs), 'text' => 'Starts in less than 7 days, but Meta has not approved: ' . implode(', ', $stuck) . '.']; if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'Smart plan #' . $pr['id'] . ': ' . $a[count($a) - 1]['text']); }
        $plan['alerts'] = $a; wsm_plan_save($pdo, $row['id'], $plan);
    }
}

// ------------------------------------------------------------------------------------------------------------------ live status (read only, cached 60 s): messages + Meta approval, progress, breakdown
function wsm_camps(PDO $pdo, int $planId): array
{
    $q = $pdo->prepare("SELECT id, name, status, tpl_id, start_at, started_at, finished_at, note, extra, rate FROM wa_campaigns WHERE smart_plan = ? ORDER BY id"); $q->execute([$planId]); $o = [];
    foreach ($q->fetchAll() as $r) { $r['id'] = (int)$r['id']; $r['tpl_id'] = (int)$r['tpl_id']; $r['ex'] = wsm_arr($r['extra']); $o[] = $r; } return $o;
}
function wsm_ds_sql(array $ids): string { return str_replace('WHERE i.camp_id = ?', 'WHERE i.camp_id IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')', wa_camp_ds_sql()); }
function wsm_state_label(string $s): array
{
    return ['approved' => ['approved', 'Approved'], 'waiting' => ['waiting', 'Waiting for Meta'], 'rejected' => ['rejected', 'Rejected'], 'relabelled' => ['relabelled', 'Re-labelled as marketing'], 'paused' => ['rejected', 'Paused by Meta'], 'missing' => ['waiting', 'Not sent to Meta yet'], 'stopped' => ['rejected', 'Needs a person to rewrite']][$s] ?? ['waiting', ucfirst($s)];
}
/** Block 1: one row per message version with its Meta state and the per-number chips. */
function wsm_messages_block(PDO $pdo, array $plan): array
{
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $env = function_exists('wa_mix_env') ? wa_mix_env($pdo) : ['numbers' => []]; $rows = []; $seen = [];
    $chips = function (int $tid) use ($pdo, $env) { $c = []; foreach ($env['numbers'] as $nid => $n) { $a = function_exists('wa_tpl_avail') ? wa_tpl_avail($pdo, $tid, (int)$nid, $env) : ['ok' => false, 'state' => 'missing', 'why' => '']; $c[] = ['id' => (int)$nid, 'label' => 'N' . (count($c) + 1), 'name' => (string)$n['label_txt'], 'state' => $a['ok'] ? 'ok' : (($a['state'] ?? '') === 'rejected' ? 'x' : (($a['state'] ?? '') === 'waiting' ? 'waiting' : 'other')), 'why' => (string)($a['why'] ?? '')]; } return $c; };
    foreach ($plan['groups'] ?? [] as $g) foreach (wsm_needed($g, $plan) as $key => $role) {
        $m = $role === 'main' ? ($g['options'][$key] ?? null) : ($g['wave_msgs'][$key] ?? null); if (!$m) continue; $mid = (int)($m['tpl_m'] ?? 0); if ($mid <= 0 || isset($seen[$mid])) continue; $seen[$mid] = 1;
        $label = $g['title'] . ' · ' . ($role === 'main' ? 'Message ' . $key : ucfirst($role)); $st = wa_mix_tpl_state($pdo, $mid, $wabas); [$cls, $txt] = wsm_state_label($st); $tr = wa_mix_trow($pdo, $mid);
        $alt = ''; if ($st === 'rejected' || $st === 'paused') { $alts = wa_fam_members($pdo, wa_fam_root($tr)); $n = 0; foreach ($alts as $a) if ($a['line'] === 'M' && (int)$a['id'] !== $mid && !$a['relabelled_at']) $n++; if ($n) $alt = 'An alternative wording was made and is waiting for Meta.'; }
        $reason = ''; if ($st === 'rejected') { $q = $pdo->prepare("SELECT reason FROM wa_tpl_waba WHERE tpl_id = ? AND reason <> '' LIMIT 1"); $q->execute([$mid]); $reason = (string)$q->fetchColumn(); }
        $rows[] = ['tpl_id' => $mid, 'label' => $label, 'line' => 'M', 'ver' => 1, 'state' => $st, 'cls' => $cls, 'state_label' => $txt, 'chips' => $chips($mid), 'reason' => $reason, 'note' => $st === 'approved' ? '' : ($st === 'waiting' ? 'Waiting for Meta. Nothing is sent with this message until it is approved.' : ($alt ?: 'This message cannot be sent. Edit it and send again.'))];
        foreach (wa_mix_twins($pdo, $mid) as $tw) {
            $ts = wa_mix_tpl_state($pdo, (int)$tw['id'], $wabas); [$c2, $t2] = wsm_state_label($ts); $nx = null; foreach (wa_mix_twins($pdo, $mid) as $t3) if ((int)$t3['ver'] > (int)$tw['ver']) { $nx = $t3; break; }
            $why = ''; if ($ts === 'rejected') { $q = $pdo->prepare("SELECT reason FROM wa_tpl_waba WHERE tpl_id = ? AND reason <> '' LIMIT 1"); $q->execute([(int)$tw['id']]); $why = (string)$q->fetchColumn(); }
            $note = $ts === 'waiting' ? 'Utility is waiting, so marketing is sending for now. It switches by itself when Meta approves it.' : ($ts === 'relabelled' ? 'Meta changed this one to marketing by itself. It still sends, at the marketing price.' : ($ts === 'rejected' && $nx ? 'Version ' . (int)$nx['ver'] . ' was sent for approval with softer words. Marketing is sending meanwhile.' : ($ts === 'rejected' ? 'Marketing is sending meanwhile.' : '')));
            $rows[] = ['tpl_id' => (int)$tw['id'], 'label' => $label, 'line' => 'U', 'ver' => (int)$tw['ver'], 'state' => $ts, 'cls' => $c2, 'state_label' => $t2, 'chips' => $chips((int)$tw['id']), 'reason' => $why, 'note' => $note, 'replacement' => $nx ? (int)$nx['id'] : 0];
        }
    }
    return $rows;
}
function wsm_state_group(array $cs): array
{
    $st = array_column($cs, 'status'); if (!$st) return ['none', 'Not started']; if (in_array('running', $st, true)) return ['running', 'Sending']; if (in_array('paused', $st, true)) return ['paused', 'Paused'];
    if (in_array('scheduled', $st, true)) return ['scheduled', 'Scheduled']; if (count(array_unique($st)) === 1 && $st[0] === 'cancelled') return ['stopped', 'Stopped']; return ['done', 'Done'];
}
/** Orders and revenue by these customers within wa_smart_attr_days of a send (last 10 digits of the phone). Returns [per-key totals]. Streamed, no more than 100k orders. */
function wsm_attribution(PDO $pdo, array $camps, int $days): array
{
    $o = ['orders' => 0, 'revenue' => 0, 'by_camp' => [], 'by_num' => [], 'by_var' => []]; if (!$camps) return $o; $ids = implode(',', array_column($camps, 'id')); $sent = []; $min = null; $max = 0;
    $st = $pdo->query("SELECT phone, UNIX_TIMESTAMP(sent_at) ts, camp_id, number_id, COALESCE(NULLIF(variant, ''), 'M') v FROM wa_camp_items WHERE camp_id IN ($ids) AND status = 'sent' AND sent_at IS NOT NULL");
    while ($r = $st->fetch()) { $ts = (int)$r['ts']; $sent[(string)$r['phone']][] = [$ts, (int)$r['camp_id'], (int)$r['number_id'], (string)$r['v']]; if ($min === null || $ts < $min) $min = $ts; if ($ts > $max) $max = $ts; }
    if (!$sent) return $o;
    try {
        $q = $pdo->prepare("SELECT phone, UNIX_TIMESTAMP(created_at) ts, total FROM shop_orders WHERE created_at >= ? AND created_at <= ? AND phone IS NOT NULL AND phone <> '' LIMIT 100000"); $q->execute([wsm_dt($min), wsm_dt($max + $days * 86400)]);
        while ($r = $q->fetch()) {
            $k = function_exists('wa_key') ? wa_key((string)$r['phone']) : substr(preg_replace('/\D/', '', (string)$r['phone']), -10); if (!isset($sent[$k])) continue; $ots = (int)$r['ts']; $hit = null;
            foreach ($sent[$k] as $s) if ($s[0] < $ots && $ots <= $s[0] + $days * 86400 && ($hit === null || $s[0] > $hit[0])) $hit = $s;
            if (!$hit) continue; $amt = (int)round((float)$r['total']); $o['orders']++; $o['revenue'] += $amt;
            foreach ([['by_camp', $hit[1]], ['by_num', $hit[2]], ['by_var', $hit[3]]] as [$b, $key]) { $o[$b][$key]['orders'] = ($o[$b][$key]['orders'] ?? 0) + 1; $o[$b][$key]['revenue'] = ($o[$b][$key]['revenue'] ?? 0) + $amt; }
        }
    } catch (PDOException $e) { }
    return $o;
}
function wsm_est_size(array $plan, array $c): int
{
    $x = $c['ex']; $gid = (string)($x['group'] ?? ''); $n = 0; foreach ($plan['groups'] ?? [] as $g) if ($g['id'] === $gid) $n = (int)$g['sendable']; $kind = (string)($x['kind'] ?? ''); if (in_array($kind, ['reminder', 'last', 'followup'], true)) $n = (int)round($n * 0.7);
    if (!empty($x['split'])) $n = (int)round($n / max(1, (int)$x['split']['n'])); return $n;
}
function wsm_status(PDO $pdo, array $row, bool $fresh = false): array
{
    $pid = $row['id']; $plan = $row['plan']; $key = 'wsm_st_' . $pid;
    if (!$fresh) { $c = wsm_arr(wsm_get($pdo, $key, '')); if (!empty($c['at']) && $c['at'] > wsm_now() - 60 && isset($c['data'])) { $d = $c['data']; $d['cached'] = true; $d['plan_status'] = $row['status']; return $d; } }
    $camps = wsm_camps($pdo, $pid); $ids = array_column($camps, 'id'); $now = wsm_now(); $attr = wsm_cfg_i($pdo, 'wa_smart_attr_days', 7); $wabas = wa_wabas($pdo);
    $ds = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'queued' => 0]; $byCamp = []; $byNum = []; $byVar = ['M' => $ds, 'U' => $ds]; $reasons = []; $qWait = 0; $qTurn = 0;
    if ($ids) {
        $q = $pdo->query("SELECT t.camp_id, t.number_id, COALESCE(NULLIF(t.variant, ''), 'M') v, t.ds, COUNT(*) n FROM (" . wsm_ds_sql($ids) . ") t GROUP BY t.camp_id, t.number_id, v, t.ds");
        foreach ($q->fetchAll() as $r) { $n = (int)$r['n']; $c = (int)$r['camp_id']; $d = (string)$r['ds']; $nid = (int)$r['number_id']; $v = $r['v'] === 'U' ? 'U' : 'M';
            $ds[$d] = ($ds[$d] ?? 0) + $n; $byCamp[$c][$d] = ($byCamp[$c][$d] ?? 0) + $n; $byVar[$v][$d] = ($byVar[$v][$d] ?? 0) + $n; if ($nid > 0) $byNum[$nid][$d] = ($byNum[$nid][$d] ?? 0) + $n; }
        $zero = array_fill_keys(array_keys($ds), 0); foreach ($byCamp as $k => $x) $byCamp[$k] = array_merge($zero, $x); foreach ($byNum as $k => $x) $byNum[$k] = array_merge($zero, $x);
        $q = $pdo->query("SELECT t.err_code, t.err, t.ds, t.tries, COUNT(*) n FROM (" . wsm_ds_sql($ids) . ") t WHERE t.ds IN ('failed', 'retry') GROUP BY t.err_code, t.err, t.ds, t.tries ORDER BY n DESC LIMIT 60");
        foreach ($q->fetchAll() as $r) { $l = wa_camp_err_label((int)$r['err_code'], (string)$r['err']); $retry = $r['ds'] === 'retry'; $left = max(0, 3 - (int)$r['tries']); $k = $l . '|' . ($retry ? 'r' . $left : 'f');
            $reasons[$k] = ['text' => $l, 'n' => ($reasons[$k]['n'] ?? 0) + (int)$r['n'], 'kind' => $retry ? 'retry' : 'failed', 'note' => $retry ? 'Tries again later, ' . $left . ' ' . ($left === 1 ? 'retry' : 'retries') . ' left' : 'Gave up, not billed']; }
    }
    // queued items: waiting for Meta approval vs. waiting for their turn / time (also the not-yet-picked audiences of scheduled campaigns)
    $stateOf = []; $estSched = 0; $perCampWait = [];
    foreach ($camps as $c) {
        $tid = $c['tpl_id']; if (!isset($stateOf[$tid])) $stateOf[$tid] = wa_mix_tpl_state($pdo, $tid, $wabas); $queued = (int)($byCamp[$c['id']]['queued'] ?? 0) + (int)($byCamp[$c['id']]['retry'] ?? 0) * 0;
        if (in_array($c['status'], ['scheduled'], true)) { $e = wsm_est_size($plan, $c); $estSched += $e; $queued += $e; }
        if ($c['status'] === 'cancelled' || $c['status'] === 'done') continue;
        if ($stateOf[$tid] !== 'approved' && $stateOf[$tid] !== 'relabelled') { $qWait += $queued; $perCampWait[$c['id']] = $queued; } else $qTurn += $queued;
    }
    $sentAll = $ds['sent'] + $ds['delivered'] + $ds['read'] + $ds['replied']; $people = (int)array_sum(array_column($plan['groups'] ?? [], 'sendable')); $A = wsm_attribution($pdo, $camps, $attr);
    $cfg = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 1.09, 'rate_utility' => 0.15]; $rm = (float)$cfg['rate_marketing']; $ru = (float)$cfg['rate_utility']; $cost = ['M' => 0.0, 'U' => 0.0];
    foreach ($camps as $c) { $sent = array_sum(array_intersect_key($byCamp[$c['id']] ?? [], array_flip(['sent', 'delivered', 'read', 'replied']))); if (!$sent) continue; [$u, $ur] = wa_mix_u_counts($pdo, $c['id']); $cost['M'] += ($sent - $u + $ur) * $rm; $cost['U'] += ($u - $ur) * $ru; }
    $sc = fn(array $d) => $d['sent'] + $d['delivered'] + $d['read'] + $d['replied'];
    // by number
    $env = function_exists('wa_mix_env') ? wa_mix_env($pdo) : ['numbers' => [], 'health' => []]; $nums = []; $k = 0;
    foreach ($env['numbers'] as $nid => $n) { $k++; $d = $byNum[$nid] ?? null; $planned = 0; foreach ($plan['numbers']['rows'] ?? [] as $pr) if ((int)$pr['id'] === (int)$nid) $planned = (int)$pr['est_msgs'];
        if (!$d && !$planned) continue; $d = $d ?? $ds; if (!isset($byNum[$nid])) $d = array_fill_keys(array_keys($ds), 0); $sentN = $sc($d); $nums[] = ['id' => (int)$nid, 'chip' => 'N' . $k, 'label' => (string)$n['label_txt'], 'quality' => (string)($env['health'][$nid]['quality'] ?? ''), 'sent' => $sentN, 'planned' => max($planned, $sentN), 'failed' => $d['failed'], 'replied' => $d['replied'], 'orders' => (int)($A['by_num'][$nid]['orders'] ?? 0)]; }
    // by group (campaigns carry the group)
    $groups = []; foreach ($plan['groups'] ?? [] as $g) {
        $cs = array_values(array_filter($camps, fn($c) => ($c['ex']['group'] ?? '') === $g['id'])); $d = array_fill_keys(array_keys($ds), 0); $ord = 0; $rev = 0; $waves = [];
        foreach ($cs as $c) { foreach ($byCamp[$c['id']] ?? [] as $k2 => $n) $d[$k2] += $n; $ord += (int)($A['by_camp'][$c['id']]['orders'] ?? 0); $rev += (int)($A['by_camp'][$c['id']]['revenue'] ?? 0); $waves[] = ['wave' => $c['ex']['wave'] ?? '', 'kind' => $c['ex']['kind'] ?? '', 'status' => $c['status'], 'start_at' => $c['start_at'], 'note' => $c['note']]; }
        [$gs, $gl] = wsm_state_group($cs); $sentG = $sc($d); $pct = $g['sendable'] > 0 ? min(100, (int)round(100 * $sentG / $g['sendable'])) : 0;
        $groups[] = ['id' => $g['id'], 'title' => $g['title'], 'people' => (int)$g['sendable'], 'state' => $gs, 'state_label' => $gl, 'pct' => $pct, 'sent' => $sentG, 'replied' => $d['replied'], 'orders' => $ord, 'revenue' => $rev, 'failed' => $d['failed'], 'waves' => $waves, 'campaign_ids' => array_column($cs, 'id')];
    }
    // by version
    $ver = []; foreach (['M' => 'Marketing', 'U' => 'Utility'] as $v => $nm) { $d = $byVar[$v]; $s = $sc($d); $rd = $d['read'] + $d['replied']; $ver[] = ['line' => $v, 'name' => $nm, 'sent' => $s, 'read' => $rd, 'read_pct' => $s ? (int)round(100 * $rd / $s) : 0, 'replied' => $d['replied'], 'orders' => (int)($A['by_var'][$v]['orders'] ?? 0), 'revenue' => (int)($A['by_var'][$v]['revenue'] ?? 0), 'rate' => $v === 'M' ? $rm : $ru, 'cost' => round($cost[$v], 2)]; }
    $tp = array_sum(array_map(fn($c) => (int)($c['ex']['resolved_n'] ?? 0), $camps)); $first = null; $last = 0; foreach ($camps as $c) { $t = (int)strtotime((string)$c['start_at']); if ($first === null || $t < $first) $first = $t; $e = max((int)($c['ex']['end_ts'] ?? 0), $t); if ($e > $last) $last = $e; }
    $first = $first ?: (int)($plan['dates']['start_ts'] ?? $now); $day = $now < $first ? 0 : (int)floor(($now - $first) / 86400) + 1; $days = max(1, (int)ceil((max($last, $first) - $first) / 86400) + 1);
    $paused = array_values(array_filter($camps, fn($c) => $c['status'] === 'paused'));
    $out = ['ok' => true, 'id' => $pid, 'title' => $row['title'], 'plan_status' => $row['status'], 'people' => $people, 'day' => $day, 'days' => $days, 'started' => $first, 'start_text' => $first ? date('D j M', $first) : '', 'cached' => false, 'at' => wsm_dt($now),
        'campaign_states' => array_count_values(array_column($camps, 'status')), 'paused_note' => $paused ? (string)$paused[0]['note'] : '', 'messages' => wsm_messages_block($pdo, $plan),
        'progress' => ['people' => $people, 'sent' => $sentAll, 'pct' => $people > 0 ? min(100, (int)round(100 * $sentAll / $people)) : 0, 'replied' => $ds['replied'], 'read' => $ds['read'], 'delivered' => $ds['delivered'], 'sent_only' => $ds['sent'], 'delivered_total' => $ds['delivered'] + $ds['read'] + $ds['replied'], 'read_total' => $ds['read'] + $ds['replied'],
            'waiting_approval' => $qWait, 'waiting_turn' => $qTurn, 'retrying' => $ds['retry'], 'failed' => $ds['failed'], 'skipped' => $ds['skipped'], 'orders' => $A['orders'], 'revenue' => $A['revenue'], 'cost' => round($cost['M'] + $cost['U'], 2), 'attr_days' => $attr,
            'turn_text' => $qTurn > 0 ? 'sends ' . ($first && $now < $first ? 'from ' . date('j M', $first) : 'in the coming days') . ', ' . ($plan['dates']['hours'] ? wsm_hours_label($plan['dates']['hours'][0]) : 'in the sending hours') : '', 'wait_text' => $qWait > 0 ? 'the message is not approved by Meta yet' : ''],
        'reasons' => array_values($reasons), 'by_number' => $nums, 'by_group' => $groups, 'by_version' => $ver, 'cost_split' => ['marketing' => round($cost['M'], 2), 'utility' => round($cost['U'], 2)], 'alerts' => $plan['alerts'] ?? [], 'camp_waiting' => $perCampWait];
    wsm_put($pdo, $key, wsm_j(['at' => $now, 'data' => $out])); return $out;
}
function wsm_status_bust(PDO $pdo, int $planId): void { wsm_put($pdo, 'wsm_st_' . $planId, ''); }

// ------------------------------------------------------------------------------------------------------------------ people (all campaigns of the plan) + CSV
function wsm_people_rows(PDO $pdo, array $camps, string $f, string $qs, int $page, int $per, int $afterId = 0, int $limit = 0, array $only = []): array
{
    $ids = $only ?: array_column($camps, 'id'); if (!$ids) return []; $w = wa_camp_ds_filter($f); $p = [];
    if ($qs !== '') { $l = '%' . addcslashes($qs, '%_\\') . '%'; $w .= " AND (t.phone LIKE ? OR COALESCE(NULLIF(t.name, ''), c.name) LIKE ?)"; $p[] = $l; $p[] = $l; }
    if ($afterId > 0) { $w .= " AND t.id > ?"; $p[] = $afterId; }
    $lim = $limit > 0 ? "LIMIT " . (int)$limit : "LIMIT " . ((int)$per + 1) . " OFFSET " . (int)max(0, ($page - 1) * $per);
    $s = $pdo->prepare("SELECT t.*, c.name AS cname FROM (" . wsm_ds_sql($ids) . ") t LEFT JOIN wa_contacts c ON c.phone = t.phone WHERE $w ORDER BY t.id $lim"); $s->execute($p); $ex = []; foreach ($camps as $c) $ex[$c['id']] = $c['ex']; $nm = [];
    try { foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nm[(int)$n['id']] = (string)($n['label'] !== '' ? $n['label'] : $n['display']); } catch (Throwable $e) { }
    $out = []; foreach ($s->fetchAll() as $r) {
        $ds = (string)$r['ds']; $code = (int)$r['err_code']; $why = '';
        if ($ds === 'failed') $why = wa_camp_err_label($code, (string)$r['err']) . ((int)$r['tries'] >= 3 ? ' · gave up after 3 tries' : ''); elseif ($ds === 'retry') $why = 'Failed: ' . wa_camp_err_label($code, (string)$r['err']) . ' · will try again'; elseif ($ds === 'skipped') $why = (string)$r['err'];
        elseif ($ds === 'queued') $why = $r['send_at'] ? 'Waiting — goes out ' . date('j M, g:i a', strtotime((string)$r['send_at'])) : 'Waiting to be sent';
        elseif ($ds === 'replied') { $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ? AND ts <= DATE_ADD(?, INTERVAL 2 DAY) ORDER BY id LIMIT 1"); $q->execute([$r['phone'], $r['sent_at'], $r['sent_at']]); $b = trim((string)$q->fetchColumn()); $why = $b !== '' ? 'Wrote back: “' . mb_substr($b, 0, 90) . '”' : 'Replied'; }
        $x = $ex[(int)$r['camp_id']] ?? [];
        $out[] = ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'name' => trim((string)($r['name'] !== '' ? $r['name'] : $r['cname'])), 'status' => $ds, 'why' => $why, 'group' => (string)($x['group'] ?? ''), 'wave' => (string)($x['wave'] ?? ''), 'option' => (string)($x['option'] ?? ''), 'number' => $nm[(int)$r['number_id']] ?? '', 'version' => (string)($r['variant'] ?? 'M') === 'U' ? 'Utility' : 'Marketing', 'sent_at' => $ds === 'queued' ? null : $r['sent_at'], 'send_at' => $r['send_at'], 'camp_id' => (int)$r['camp_id']];
    }
    return $out;
}
function wsm_people(PDO $pdo, array $row, array $q): array
{
    $camps = wsm_camps($pdo, $row['id']); $f = in_array((string)($q['f'] ?? 'all'), ['all', 'delivered', 'read', 'replied', 'failed', 'retry', 'skipped', 'queued', 'sent'], true) ? (string)($q['f'] ?? 'all') : 'all'; $page = max(1, (int)($q['page'] ?? 1)); $per = max(10, min(100, (int)($q['per'] ?? 40)));
    $only = []; $g = (string)($q['group'] ?? ''); if ($g !== '') foreach ($camps as $c) if (($c['ex']['group'] ?? '') === $g) $only[] = $c['id']; if ($g !== '' && !$only) return ['ok' => true, 'rows' => [], 'page' => 1, 'more' => false, 'counts' => []];
    $rows = wsm_people_rows($pdo, $camps, $f, trim((string)($q['q'] ?? '')), $page, $per, 0, 0, $only); $more = count($rows) > $per; if ($more) array_pop($rows);
    $counts = []; $ids = $only ?: array_column($camps, 'id'); if ($ids) { $d = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'queued' => 0]; foreach ($pdo->query("SELECT t.ds, COUNT(*) n FROM (" . wsm_ds_sql($ids) . ") t GROUP BY t.ds")->fetchAll() as $r) $d[(string)$r['ds']] = (int)$r['n'];
        $counts = ['all' => array_sum($d), 'replied' => $d['replied'], 'failed' => $d['failed'], 'waiting' => $d['queued'] + $d['retry']]; }
    return ['ok' => true, 'rows' => $rows, 'page' => $page, 'more' => $more, 'counts' => $counts, 'scheduled_note' => count(array_filter($camps, fn($c) => $c['status'] === 'scheduled')) ? 'Groups that have not started yet are picked on their start day, so they are not in this list yet.' : ''];
}
function wsm_people_csv(PDO $pdo, array $row, array $q): void
{
    $camps = wsm_camps($pdo, $row['id']); $f = in_array((string)($q['f'] ?? 'all'), ['all', 'delivered', 'read', 'replied', 'failed', 'retry', 'skipped', 'queued', 'sent'], true) ? (string)($q['f'] ?? 'all') : 'all';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="smart_plan_' . $row['id'] . '_' . ($f === 'all' ? 'everyone' : $f) . '_' . date('Y-m-d') . '.csv"'); header('Cache-Control: no-store');
    $cell = function ($v) { $v = str_replace(["\r", "\n", "\t"], ' ', (string)$v); if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; };
    echo "\xEF\xBB\xBF" . implode(',', array_map($cell, ['Name', 'Phone', 'Status', 'Group', 'Wave', 'Message', 'Version', 'Number', 'Why', 'Sent at'])) . "\r\n"; $after = 0;
    while (true) { $rows = wsm_people_rows($pdo, $camps, $f, '', 1, 0, $after, 2000); if (!$rows) break;
        foreach ($rows as $r) { $after = max($after, $r['id']); echo implode(',', array_map($cell, [$r['name'], $r['phone'], $r['status'], $r['group'], $r['wave'], $r['option'], $r['version'], $r['number'], $r['why'], $r['sent_at'] ?? ''])) . "\r\n"; }
        if (count($rows) < 2000) break; }
}

// ------------------------------------------------------------------------------------------------------------------ pause / resume / stop (the whole plan or one group)
function wsm_control(PDO $pdo, array $row, string $op, string $group, string $who): array
{
    $camps = wsm_camps($pdo, $row['id']); $n = 0; $now = wsm_dt(wsm_now());
    foreach ($camps as $c) {
        if ($group !== '' && ($c['ex']['group'] ?? '') !== $group) continue; $id = $c['id'];
        if ($op === 'pause' && in_array($c['status'], ['running', 'scheduled'], true)) { $pdo->prepare("UPDATE wa_campaigns SET status = 'paused', note = ? WHERE id = ?")->execute([mb_substr('Paused by ' . $who, 0, 190), $id]); $n++; }
        elseif ($op === 'resume' && $c['status'] === 'paused') { $back = empty($c['started_at']) ? 'scheduled' : 'running'; $pdo->prepare("UPDATE wa_campaigns SET status = ?, note = '' WHERE id = ?")->execute([$back, $id]); $n++; }
        elseif ($op === 'stop' && in_array($c['status'], ['running', 'scheduled', 'paused'], true)) {
            $pdo->prepare("UPDATE wa_camp_items SET status = 'skipped', err = 'Stopped by the owner' WHERE camp_id = ? AND status IN ('queued', 'retry')")->execute([$id]);
            $pdo->prepare("UPDATE wa_campaigns SET status = 'cancelled', finished_at = ?, note = ? WHERE id = ?")->execute([$now, mb_substr('Stopped by ' . $who, 0, 190), $id]); $n++;
        }
    }
    $plan = $row['plan']; $plan['control'] = array_merge($plan['control'] ?? [], [['op' => $op, 'group' => $group, 'by' => $who, 'at' => $now, 'n' => $n]]); wsm_plan_save($pdo, $row['id'], $plan);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM wa_campaigns WHERE smart_plan = " . (int)$row['id'] . " AND status IN ('running', 'scheduled')")->fetchColumn(); $paused = (int)$pdo->query("SELECT COUNT(*) FROM wa_campaigns WHERE smart_plan = " . (int)$row['id'] . " AND status = 'paused'")->fetchColumn();
    $ns = $left > 0 ? 'running' : ($paused > 0 ? 'paused' : 'done'); $pdo->prepare("UPDATE wa_smart_plans SET status = ?, updated_at = ? WHERE id = ? AND status IN ('approved', 'running', 'paused', 'done')")->execute([$ns, $now, $row['id']]);
    wsm_rev($pdo, $row['id'], $row['rev'], $who, $op, ucfirst($op) . ($group !== '' ? ' ' . $group : ' all') . ': ' . $n . ' campaign(s)'); if (function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Smart plan #' . $row['id'] . ' ' . $op . ($group !== '' ? ' (' . $group . ')' : '') . ': ' . $n . ' campaign(s)');
    wsm_status_bust($pdo, $row['id']); return ['ok' => true, 'changed' => $n, 'plan_status' => $ns];
}

// ------------------------------------------------------------------------------------------------------------------ final report (numbers + Claude's words; a numbers-only text when Claude is not available)
function wsm_report(PDO $pdo, array $row, bool $refresh = false): array
{
    $r = $row['report']; $age = !empty($r['at']) ? wsm_now() - (int)strtotime((string)$r['at']) : 999999; $final = $row['status'] === 'done';
    if (!$refresh && $r && ($age < 600 || (!empty($r['final']) && $final))) return $r + ['cached' => true];
    $st = wsm_status($pdo, $row, true); $plan = $row['plan']; $P = $st['progress'];
    $cpo = $P['orders'] > 0 ? round($P['cost'] / $P['orders'], 2) : 0; $roi = $P['cost'] > 0 ? round($P['revenue'] / $P['cost'], 1) : 0;
    $totals = ['people' => $P['people'], 'sent' => $P['sent'], 'delivered' => $P['delivered_total'], 'read' => $P['read_total'], 'replied' => $P['replied'], 'failed' => $P['failed'], 'orders' => $P['orders'], 'revenue' => $P['revenue'], 'cost' => $P['cost'], 'cost_per_order' => $cpo, 'revenue_per_rupee' => $roi, 'budget' => (float)($plan['cost']['budget'] ?? 0), 'estimated_cost' => (float)($plan['cost']['total'] ?? 0)];
    $facts = ['plan' => $row['title'], 'totals' => $totals, 'groups' => array_map(fn($g) => array_intersect_key($g, array_flip(['title', 'people', 'sent', 'replied', 'orders', 'revenue', 'failed'])), $st['by_group']), 'versions' => $st['by_version'], 'reasons' => array_slice($st['reasons'], 0, 5), 'offer' => $plan['groups'][0]['offer']['text'] ?? '', 'intent' => $plan['intent_label'] ?? ''];
    $good = []; $bad = []; $next = []; $src = 'numbers';
    $pk = wsm_pack($pdo, 'report'); $sch = ['type' => 'OBJECT', 'properties' => ['worked' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'didnt' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'next' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['worked', 'didnt', 'next']];
    [$ok, $v] = wsm_ai($pdo, "You write the end-of-campaign report for the owner of TM Perfume House. Plain English, short sentences, use ONLY the numbers given, never invent one. Give 2-3 points each: what worked, what did not, what to do next time.\n" . $pk['text'], "Campaign result: " . wsm_j($facts), $sch, 1500);
    if ($ok && is_array($v)) { $cl = fn($a) => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 240), (array)$a))), 0, 4); $good = $cl($v['worked'] ?? []); $bad = $cl($v['didnt'] ?? []); $next = $cl($v['next'] ?? []); $src = 'claude'; }
    if (!$good && !$bad && !$next) {
        $rr = $P['sent'] > 0 ? (int)round(100 * $P['replied'] / $P['sent']) : 0; $bestG = null; foreach ($st['by_group'] as $g) if ($g['sent'] > 0 && ($bestG === null || $g['replied'] / $g['sent'] > $bestG['replied'] / max(1, $bestG['sent']))) $bestG = $g;
        $good[] = wsm_n($P['sent']) . ' of ' . wsm_n($P['people']) . ' messages were sent; ' . wsm_n($P['replied']) . ' people wrote back (' . $rr . '%).'; if ($P['orders'] > 0) $good[] = wsm_n($P['orders']) . ' orders worth ' . wsm_money($P['revenue']) . ' came within ' . $P['attr_days'] . ' days.'; if ($bestG) $good[] = '"' . $bestG['title'] . '" answered best.';
        if ($P['failed'] > 0) $bad[] = wsm_n($P['failed']) . ' messages did not go through' . (!empty($st['reasons'][0]) ? ' (mostly: ' . mb_strtolower($st['reasons'][0]['text']) . ')' : '') . '.'; if ($P['orders'] === 0) $bad[] = 'No order could be tied to this campaign yet.';
        $next[] = 'Write the numbers into your notes, copy this plan and change one thing (the offer or the time).'; if ($P['failed'] > 0) $next[] = 'Clean the list of numbers that are not on WhatsApp before the next one.';
    }
    $rep = ['ok' => true, 'id' => $row['id'], 'title' => $row['title'], 'status' => $row['status'], 'final' => $final, 'at' => wsm_dt(wsm_now()), 'source' => $src, 'totals' => $totals, 'by_group' => $st['by_group'], 'by_version' => $st['by_version'], 'by_number' => $st['by_number'], 'reasons' => $st['reasons'], 'worked' => $good, 'didnt' => $bad, 'next' => $next, 'cost_split' => $st['cost_split'], 'attr_days' => $P['attr_days'], 'ranges' => ['from' => $plan['dates']['from'] ?? '', 'to' => $plan['dates']['to'] ?? '']];
    $pdo->prepare("UPDATE wa_smart_plans SET report = ?, updated_at = ? WHERE id = ?")->execute([wsm_j($rep), wsm_dt(wsm_now()), $row['id']]); return $rep;
}
function wsm_list(PDO $pdo, int $limit = 40): array
{
    $o = []; foreach ($pdo->query("SELECT * FROM wa_smart_plans ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll() as $r) { $r['brief'] = wsm_arr($r['brief']); $r['plan'] = wsm_arr($r['plan']); $r['report'] = wsm_arr($r['report']); $r['id'] = (int)$r['id']; $r['rev'] = (int)$r['rev']; $x = wsm_plan_public($r, false);
        if (in_array($r['status'], ['approved', 'running', 'paused', 'done'], true)) { $q = $pdo->prepare("SELECT COUNT(*) n, SUM(i.status = 'sent') s FROM wa_camp_items i JOIN wa_campaigns k ON k.id = i.camp_id WHERE k.smart_plan = ?"); $q->execute([$r['id']]); $c = $q->fetch(); $x['items'] = (int)$c['n']; $x['sent'] = (int)$c['s']; }
        $o[] = $x; } return $o;
}

// ------------------------------------------------------------------------------------------------------------------ edit applied to a stored plan
function wsm_edit_apply(PDO $pdo, array $row, array $in, string $who): array
{
    if ($row['status'] !== 'draft') throw new RuntimeException('This plan is already approved. Copy it to change it.');
    [$plan, $diff, $prop] = wsm_edit($pdo, $row, $in, $who); wsm_gc_drafts($pdo, $row['plan'], $plan); $rev = $row['rev'] + 1; $pdo->prepare("UPDATE wa_smart_plans SET rev = ? WHERE id = ?")->execute([$rev, $row['id']]); wsm_plan_save($pdo, $row['id'], $plan);
    wsm_rev($pdo, $row['id'], $rev, $who, 'edit', 'Changed: ' . implode('; ', array_map(fn($d) => $d['what'], $diff)), $diff);
    $r2 = wsm_plan_row($pdo, $row['id']); return ['ok' => true, 'plan' => wsm_plan_public($r2), 'diff' => $diff, 'proposed_decisions' => $prop];
}

// ------------------------------------------------------------------------------------------------------------------ Normal campaign upgrade: 3 marketing + 3 utility suggestions, save + send to Meta, speed
function wsm_suggest_fallback(string $text, string $goal): array
{
    $t = trim($text); $t = preg_replace('/\s+/', ' ', $t) ?? $t; $hello = preg_match('/^(hi|hello|hey|namaste|namaskar|kem)\b/i', $t) ? '' : 'Hi {{name}}, ';
    $short = mb_strlen($t) > 120 ? rtrim((string)mb_substr($t, 0, 117), " ,.;") . '...' : $t; $ask = $goal === 'order' ? 'Want us to keep one ready for you?' : 'Shall I tell you more?';
    return [['text' => $hello . $t, 'why' => 'Your own words, with a greeting.'], ['text' => 'Hi {{name}}, ' . lcfirst($short) . ' ' . $ask, 'why' => 'Shorter, ends with a question that invites a reply.'], ['text' => 'Hi {{name}}! Quick note from TM Perfume House: ' . lcfirst($short) . ' Reply here and we will help.', 'why' => 'States who it is from and what to do next.']];
}
function wsm_camp_suggest(PDO $pdo, string $text, string $goal): array
{
    $text = trim($text); if ($text === '') throw new RuntimeException('Type the message first (even a rough one).'); $text = mb_substr($text, 0, 800); $goal = in_array($goal, ['reply', 'order', 'store', 'reactivate'], true) ? $goal : 'reply';
    $pk = wsm_pack($pdo, 'message_write'); $dec = wsm_decisions($pdo); $sch = ['type' => 'OBJECT', 'properties' => ['items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['text']]]], 'required' => ['items']];
    [$ok, $v] = wsm_ai($pdo, "You write WhatsApp marketing messages for TM Perfume House (Surat). Write THREE different versions of the owner's message (three angles, same meaning), in the same language the owner used. Two or three short sentences, warm, one clear next step. " .
        "You may use only these variables: {{name}} {{perfume}} {{days}} {{offer}} {{code}}. Never start or end with a variable. Never write a price and never name a real designer brand.\n" . $pk['text'] . ($dec !== '' ? "\nTHE OWNER'S DECISIONS (always obey):\n" . $dec : ''), "Goal: $goal. The owner wrote: " . $text, $sch, 1500);
    $m = []; if ($ok && is_array($v)) foreach ((array)($v['items'] ?? []) as $x) { if (!is_array($x)) continue; [$t] = wsm_clean_msg($pdo, (string)($x['text'] ?? ''), 100, false); if (mb_strlen($t) >= 8) $m[] = ['text' => $t, 'why' => mb_substr(trim((string)($x['why'] ?? '')), 0, 160)]; }
    $src = count($m) >= 3 ? 'claude' : 'rules'; if ($src === 'rules') { $fb = wsm_suggest_fallback($text, $goal); foreach ($fb as $f) { if (count($m) >= 3) break; $m[] = $f; } } $m = array_slice($m, 0, 3);
    $offer = ['text' => '', 'deadline_text' => '', 'code' => '']; $items = [];
    foreach ($m as $i => $x) {
        [$body, $tokens, $specs, $ex, $lab] = wsm_tpl_body($x['text'], $offer); $u = ['ok' => false, 'text' => '', 'changes' => [], 'why' => 'A utility version needs a message about an order or a question the customer asked.'];
        if (function_exists('wa_tpl_utility_build')) { try { $d = wsm_tpl_draft('x', $x['text'], 'en', $offer, []); [$t, $err] = wa_tpl_clean($d['draft'], true); if ($err === '') { $ub = wa_tpl_utility_build($t); $body2 = (string)$ub['draft']['body']; $back = preg_replace_callback('/\{\{(\d+)\}\}/', fn($mm) => '{{' . ($tokens[(int)$mm[1] - 1] ?? 'name') . '}}', $body2) ?? $body2; $u = ['ok' => true, 'text' => $back, 'body' => $body2, 'changes' => array_values(array_map(fn($c) => is_array($c) ? trim((string)($c['from'] ?? '') . ' → ' . (string)($c['to'] ?? '')) : (string)$c, (array)($ub['changes'] ?? []))), 'why' => '']; } } catch (Throwable $e) { } }
        $items[] = ['key' => (string)($i + 1), 'm' => ['text' => $x['text'], 'why' => $x['why'], 'tokens' => $tokens], 'u' => $u];
    }
    return ['ok' => true, 'source' => $src, 'goal' => $goal, 'items' => $items, 'marketing' => array_column($items, 'm'), 'utility' => array_column($items, 'u'), 'auto_approve' => true, 'used' => $pk['used'],
        'utility_note' => 'A utility version is sent only to people who asked about something or ordered in the last ' . (function_exists('wa_mix_cfg') ? (int)wa_mix_cfg($pdo, 'wa_mix_days', 30) : 30) . ' days. It costs much less.'];
}
/** Saves the approved suggestions as pre-made messages (marketing + utility twin) and sends them to every WhatsApp account once. Returns the list. */
function wsm_suggest_save(PDO $pdo, array $in, int $by): array
{
    $items = array_slice((array)($in['items'] ?? []), 0, 6); if (!$items) throw new RuntimeException('Nothing to approve.'); $auto = !array_key_exists('auto_approve', $in) || !empty($in['auto_approve']); $lang = in_array((string)($in['lang'] ?? 'en'), ['en', 'hinglish', 'hi', 'gu'], true) ? (string)($in['lang'] ?? 'en') : 'en';
    if (!wa_wabas($pdo)) throw new RuntimeException('No WhatsApp Business Account ID yet — add one in WhatsApp → Setup.'); $out = []; $skipped = 0; $bud = ['left' => 60]; $i = 0;
    foreach ($items as $it) {
        if (!is_array($it)) continue; $i++; $approved = array_key_exists('approved', $it) ? (bool)$it['approved'] : null; $edited = !empty($it['edited']);
        $take = $approved === true || ($auto && $approved !== false && !$edited); if (!$take) { $skipped++; continue; }
        [$text] = wsm_clean_msg($pdo, (string)($it['m_text'] ?? ''), 100, false); if (mb_strlen($text) < 8) { $out[] = ['index' => $i, 'ok' => false, 'error' => 'The message is too short.']; continue; }
        $name = 'nc' . date('md') . '_' . substr(bin2hex(random_bytes(3)), 0, 5) . '_' . $i; $d = wsm_tpl_draft($name, $text, $lang, ['text' => '', 'deadline_text' => '', 'code' => ''], []);
        $msg = []; $uText = trim((string)($it['u_text'] ?? '')); $wantU = !empty($it['u_on']) || ($uText !== '' && $approved !== false);
        if ($wantU && $uText !== '') { [$ub] = wsm_tpl_body($uText, ['text' => '', 'deadline_text' => '', 'code' => '']); $msg['u_body'] = $ub; }
        try {
            [$m, $u, $warn] = wsm_tpl_save($pdo, $msg, $d['draft'], $wantU, $by, 'Normal campaign suggestion'); $sent = 0; $errs = []; foreach ([$m, $u] as $tid) { if ($tid <= 0) continue; [$n, $e] = wsm_submit_tpl($pdo, $tid, $bud); $sent += $n; foreach ($e as $x) $errs[] = $x; }
            $out[] = ['index' => $i, 'ok' => true, 'tpl_id' => $m, 'utility_id' => $u, 'name' => $name, 'nvars' => count($d['tokens']), 'specs' => $d['specs'], 'tokens' => $d['tokens'], 'submitted' => $sent, 'warnings' => array_merge($warn, $errs)];
        } catch (Throwable $e) { $out[] = ['index' => $i, 'ok' => false, 'error' => $e->getMessage()]; }
    }
    return ['ok' => true, 'saved' => $out, 'skipped' => $skipped, 'auto_approve' => $auto];
}
/** How long a normal campaign takes: from the saved number health / room (no call to Meta). */
function wsm_camp_speed(PDO $pdo, array $in): array
{
    $size = (int)($in['size'] ?? 0); $id = (int)($in['id'] ?? 0); $speed = (string)($in['speed'] ?? 'gentle'); $retries = max(1, min(3, (int)($in['retries'] ?? 3)));
    if ($id > 0) { $c = wa_camp_row($pdo, $id); if (!$c) throw new RuntimeException('Campaign not found.'); $n = wa_camp_counts($pdo, $id); $size = $n['queued'] + $n['retry'] + $n['sending'];
        if ($size === 0 && ($n['total'] ?? 0) === 0) { $a = $c['audience']; try { if ((string)($a['src'] ?? 'contacts') === 'contacts') $size = (int)wa_aud_count($pdo, 'contacts', (array)($a['f'] ?? []), true, (int)$c['freq_days']); else { [$send, $stt] = wa_camp_audience($pdo, $a, (int)$c['freq_days']); $size = (int)($stt['send'] ?? count($send)); } } catch (Throwable $e) { } } }
    if ($size <= 0) throw new RuntimeException('Tell me how many people (size) or which campaign (id).');
    $all = []; foreach (['gentle', 'normal', 'fast'] as $sp) { $r = wsm_speed($pdo, $size, $sp); $all[$sp] = ['text' => $r['text'], 'minutes' => $r['minutes'], 'days' => $r['days'], 'per_min' => $r['per_min'], 'rate' => $r['rate'], 'push' => $r['push']]; }
    $r = wsm_speed($pdo, $size, $speed); $r['size'] = $size; $r['all'] = $all; $r['retries'] = $retries; $r['retry_text'] = 'A message Meta could not deliver is tried up to ' . $retries . ' time' . ($retries === 1 ? '' : 's') . ' in all (first try included).'; $r['ok'] = true; return $r;
}

// ---------------------------------------------------------------------------------------------------------------- router (admin only, pinned at the end)
$__wk = (string)($action ?? ($_GET['action'] ?? ''));
if (isset($pdo) && function_exists('json_response') && $__wk !== '' && (strpos($__wk, 'wa.smart_') === 0 || in_array($__wk, ['wa.camp_speed', 'wa.camp_suggest_save'], true) || ($__wk === 'wa.camp_suggest' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'))) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (!function_exists('wa_seg_count') && is_file(__DIR__ . '/wa_seg.php')) { try { require_once __DIR__ . '/wa_seg.php'; } catch (Throwable $e) { } }   // wa_more.php loads wa_seg.php LAST (after this file); this router runs at include time, so it fetches it now (the customer journey, the context pack, the decisions)
    $postOnly = ['wa.smart_ask', 'wa.smart_plan', 'wa.smart_clone', 'wa.smart_edit', 'wa.smart_test', 'wa.smart_approve', 'wa.smart_pause', 'wa.smart_resume', 'wa.smart_stop', 'wa.camp_suggest', 'wa.camp_suggest_save', 'wa.camp_speed'];
    $getOnly = ['wa.smart_get', 'wa.smart_list', 'wa.smart_status', 'wa.smart_people', 'wa.smart_people_csv', 'wa.smart_report'];
    if (!in_array($__wk, array_merge($postOnly, $getOnly), true)) json_response(['error' => 'Unknown action.'], 404);
    if (in_array($__wk, $postOnly, true) && !$isPost) json_response(['error' => 'Method not allowed.'], 405);
    if (in_array($__wk, $getOnly, true) && $isPost) json_response(['error' => 'Method not allowed.'], 405);
    if (!wa_tables($pdo) || !wa_more_tables($pdo) || !function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo) || !wsm_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    $me = (int)($auth['employee_id'] ?? 0); $who = (string)($auth['name'] ?? ($auth['username'] ?? ('#' . $me))); if ($who === '' || $who === '#0') $who = 'admin';
    $in = $isPost ? json_input() : []; if (!is_array($in)) $in = [];
    $writes = ['wa.smart_ask', 'wa.smart_plan', 'wa.smart_clone', 'wa.smart_edit', 'wa.smart_test', 'wa.smart_approve', 'wa.camp_suggest', 'wa.camp_suggest_save', 'wa.camp_speed'];   // pause / stop / reading stay possible when the switch is off
    if (in_array($__wk, $writes, true) && !wsm_on($pdo)) json_response(['error' => 'Smart campaigns are switched off (setting wa_smart_on = 0).'], 403);
    $planOf = function (int $id) use ($pdo) { $r = $id > 0 ? wsm_plan_row($pdo, $id) : null; if (!$r) json_response(['error' => 'Plan not found.'], 404); return $r; };
    try {
        switch ($__wk) {
            case 'wa.smart_ask': {
                $text = trim((string)($in['text'] ?? '')); if ($text === '') json_response(['error' => 'Type one line about the campaign you want.'], 422);
                $ans = is_array($in['answers'] ?? null) ? $in['answers'] : []; $o = wsm_ask($pdo, mb_substr($text, 0, 600), $ans, max(0, min(5, (int)($in['round'] ?? 0))), is_array($in['u'] ?? null) ? $in['u'] : null);
                // a rule that lasts ("never before 11 am") written in the line or in the free note: offered to the owner as a decision (saved only through wa.dec_save)
                $cand = trim((string)($ans['note'] ?? '')); if (preg_match('/\b(never|always|do not|don\'t|dont|must not)\b/i', $text)) $cand = trim($text . ' ' . $cand);
                $o['proposed_decisions'] = []; if ($cand !== '' && !is_array($in['u'] ?? null) && function_exists('wa_dec_extract')) { try { $x = wa_dec_extract($pdo, $cand); $o['proposed_decisions'] = array_values((array)($x['proposals'] ?? [])); } catch (Throwable $e) { } }
                json_response($o);
            }
            case 'wa.smart_plan': {
                $text = trim((string)($in['text'] ?? '')); $id = (int)($in['id'] ?? 0); if ($text === '' && $id > 0) { $r0 = $planOf($id); $text = (string)($r0['brief']['text'] ?? ''); } if ($text === '') json_response(['error' => 'Type one line about the campaign you want.'], 422);
                $ans = is_array($in['answers'] ?? null) ? $in['answers'] : []; $u = is_array($in['u'] ?? null) ? $in['u'] : null;
                try { $r = wsm_plan_make($pdo, mb_substr($text, 0, 600), $ans, $me, $who, $id, $u); }
                catch (RuntimeException $e) { if ($e->getMessage() === 'NEEDS_DEADLINE') json_response(['error' => 'Pick the last day of the offer first.', 'needs' => ['deadline']], 422); throw $e; }
                json_response(['ok' => true, 'plan' => wsm_plan_public($r)]);
            }
            case 'wa.smart_get': { $r = $planOf((int)($_GET['id'] ?? 0)); $o = ['ok' => true, 'plan' => wsm_plan_public($r)]; $q = $pdo->prepare("SELECT id, rev, at, who, kind, note FROM wa_smart_rev WHERE plan_id = ? ORDER BY id DESC LIMIT 40"); $q->execute([$r['id']]); $o['revisions'] = $q->fetchAll(); json_response($o); }
            case 'wa.smart_list': json_response(['ok' => true, 'plans' => wsm_list($pdo), 'on' => wsm_on($pdo)]);
            case 'wa.smart_clone': { $r = $planOf((int)($in['id'] ?? 0)); $r2 = wsm_clone($pdo, $r, is_array($in['changes'] ?? null) ? $in['changes'] : [], $me, $who); json_response(['ok' => true, 'plan' => $r2]); }
            case 'wa.smart_edit': { $r = $planOf((int)($in['id'] ?? 0)); json_response(wsm_edit_apply($pdo, $r, $in, $who)); }
            case 'wa.smart_test': { $r = $planOf((int)($in['id'] ?? 0)); json_response(wsm_test_send($pdo, $r, $in, $auth)); }
            case 'wa.smart_approve': {
                $r = $planOf((int)($in['id'] ?? 0));
                try { json_response(wsm_approve($pdo, $r, $in, $me, $who)); }
                catch (RuntimeException $e) { $m = $e->getMessage();
                    if ($m === 'ALREADY') json_response(['error' => 'This plan was already approved.', 'code' => 'already'], 409); if ($m === 'REV') json_response(['error' => 'The plan changed after you opened it. Reload it and look again.', 'code' => 'rev'], 409);
                    if ($m === 'OVER_BUDGET') json_response(['error' => (string)($r['plan']['cost']['verdict'] ?? 'Over your budget cap.'), 'code' => 'over_budget'], 422); throw $e; }
            }
            case 'wa.smart_status': { $r = $planOf((int)($_GET['id'] ?? 0)); json_response(wsm_status($pdo, $r, !empty($_GET['fresh']))); }
            case 'wa.smart_people': { $r = $planOf((int)($_GET['id'] ?? 0)); json_response(wsm_people($pdo, $r, $_GET)); }
            case 'wa.smart_people_csv': { $r = $planOf((int)($_GET['id'] ?? 0)); wsm_people_csv($pdo, $r, $_GET); exit; }
            case 'wa.smart_pause': case 'wa.smart_resume': case 'wa.smart_stop': { $r = $planOf((int)($in['id'] ?? 0)); if ($r['status'] === 'draft') json_response(['error' => 'This plan has not started yet.'], 422); json_response(wsm_control($pdo, $r, substr($__wk, 9), (string)($in['group'] ?? ''), $who)); }
            case 'wa.smart_report': { $r = $planOf((int)($_GET['id'] ?? 0)); if ($r['status'] === 'draft') json_response(['error' => 'This plan has not started yet, so there is no report.'], 422); json_response(wsm_report($pdo, $r, !empty($_GET['refresh']))); }
            case 'wa.camp_suggest': json_response(wsm_camp_suggest($pdo, (string)($in['text'] ?? ''), (string)($in['goal'] ?? '')));
            case 'wa.camp_suggest_save': json_response(wsm_suggest_save($pdo, $in, $me));
            case 'wa.camp_speed': json_response(wsm_camp_speed($pdo, $in));
        }
    } catch (RuntimeException $e) { json_response(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'smart ' . $__wk . ': ' . $e->getMessage()); json_response(['error' => 'Something went wrong while doing that. Nothing was changed. (' . mb_substr($e->getMessage(), 0, 120) . ')'], 500); }
}
}
