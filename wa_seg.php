<?php
/**
 * wa_seg.php — customer journey, stage / value / behaviour, audience by dates, the Claude context pack and the owner's decision memory.
 *
 * Put this file in the SAME folder as api.php and load it from wa_more.php (one require_once line, see G_wiring.md). PHP 8 safe.
 * It never calls Meta, Shopify or Shiprocket. The only outside call is the AI (convo_ai) in the daily chat digest and wa_dec_extract(), and both have a safe
 * fallback when the AI is not available. Nothing here runs a big query when a panel opens: the heavy work (the customer summary, the digest, the campaign summary)
 * is done by wa_seg_tick() in small steps and the screens only read the summary tables.
 *
 *   wa.seg_count     POST {filters}              how many customers match: total, sending, skipped (with each reason), per stage and per value tier, the "adds up" line
 *   wa.seg_stages    GET                         stage x value matrix, the adds-up check, rupee cut-offs (admin), build progress, numbers, what data the order table has
 *   wa.seg_hist      POST {filters}              month histogram of the matched customers: first contact month + last-wrote month
 *   wa.seg_customer  GET  ?phone=                one customer's story: first contact, stage, value, gap, trend, timeline (max 40, newest first), facts
 *   wa.seg_settings  GET (any login) / POST (admin)   wa_seg_wake_days · cool_days · sleep_days · new_days · warm_days · value_pcts · session_hours · ask_min
 *   wa.seg_build     POST (admin) {limit?, reset?}    run one build step now, returns the progress
 *   wa.ctx_preview   GET  ?purpose=              (admin) the Claude context pack text + what it used + the size of each part
 *   wa.dec_list      GET  (any login)            the owner's decisions     wa.dec_save POST / wa.dec_del POST / wa.dec_extract POST   (admin)
 *
 * Other code can call:
 *   wa_ctx_pack($pdo, $purpose, $opts = [])        -> ['text','used'[],'parts'[],'chars']     purpose: campaign_plan | campaign_ask | message_write | report
 *   wa_decisions_text($pdo, $scope)                -> string (scope: all | campaign | template)
 *   wa_dec_extract($pdo, $ownerText)               -> ['ok','proposals'[],'source']           (never saves anything)
 *   wa_seg_tick($pdo, $opts = [])                  -> small summary; idempotent, time-boxed (~8 s), never throws
 *   wa_seg_touch($pdo, $phone, $prevIn, $now)      -> refresh ONE customer right after they wrote (cheap)
 *   wa_seg_refresh_one($pdo, $phone)               -> the fresh story row (array) or null
 *   wa_seg_count($pdo, $filters)                   -> same as the wa.seg_count answer
 *   wa_seg_filters_clean($raw)                     -> the filter array with only the keys wa_aud_where() understands (use it before saving a campaign audience)
 * Tables: wa_cust_story (one row per customer, phone = key) · wa_cust_sess (one row per time they wrote = a SESSION) · wa_seg_ord (order behaviour per customer)
 *         wa_chat_digest (daily chat digest) · wa_decisions (owner decisions).
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wsg_tables')) {

// ------------------------------------------------------------------------------------------------------------------ small helpers
/** "Now" (unix). A test may define WSG_NOW, or set $GLOBALS['WSG_NOW'], to move the clock. */
function wsg_now(): int { return defined('WSG_NOW') ? (int)WSG_NOW : (isset($GLOBALS['WSG_NOW']) ? (int)$GLOBALS['WSG_NOW'] : time()); }
function wsg_dt(int $ts): string { return date('Y-m-d H:i:s', $ts); }
function wsg_ts($s): ?int { if ($s === null || $s === '' || $s === '0000-00-00 00:00:00') return null; $t = strtotime((string)$s); return $t === false ? null : (int)$t; }
function wsg_get(PDO $pdo, string $k, string $d = ''): string
{
    try { $q = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false || $v === null ? $d : (string)$v; } catch (Throwable $e) { return $d; }
}
function wsg_put(PDO $pdo, string $k, string $v): void
{
    try { $pdo->prepare("INSERT INTO hrms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]); } catch (Throwable $e) { }
}
function wsg_json(PDO $pdo, string $k): array { $d = json_decode(wsg_get($pdo, $k, ''), true); return is_array($d) ? $d : []; }
function wsg_log(PDO $pdo, bool $ok, string $info): void { if (function_exists('wa_log')) { try { wa_log($pdo, 'segments', $ok, $info); } catch (Throwable $e) { } } }
function wsg_n($n): string { return number_format((float)$n, 0, '.', ','); }
function wsg_short(string $s, int $n): string { $s = trim(preg_replace('/\s+/u', ' ', $s) ?? ''); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
function wsg_in(array $ks): string { return implode(',', array_fill(0, count($ks), '?')); }
/** Phone numbers and e-mail addresses are never sent to the AI or shown in the pack. */
function wsg_redact(string $s): string { $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '(email)', $s) ?? $s; return preg_replace('/\+?\d[\d\s-]{6,}\d/u', '(number)', $s) ?? $s; }

// ------------------------------------------------------------------------------------------------------------------ settings
function wsg_defaults(): array { return ['wake_days' => 60, 'cool_days' => 30, 'sleep_days' => 60, 'new_days' => 7, 'warm_days' => 7, 'value_pcts' => '5,15,30', 'session_hours' => 6, 'ask_min' => 3]; }
/** The thresholds (settings keys wa_seg_*), checked: every number is a whole number of days, sleeping starts after cooling. */
function wsg_cfg(PDO $pdo, bool $fresh = false): array
{
    static $c = null; if ($c !== null && !$fresh) return $c;
    $d = wsg_defaults(); $o = [];
    foreach (['wake_days', 'cool_days', 'sleep_days', 'new_days', 'warm_days', 'session_hours', 'ask_min'] as $k) {
        $v = wsg_get($pdo, 'wa_seg_' . $k, ''); $n = is_numeric($v) ? (int)$v : $d[$k]; $o[$k] = max(1, min($k === 'session_hours' ? 72 : 3650, $n));
    }
    if ($o['sleep_days'] <= $o['cool_days']) $o['sleep_days'] = $o['cool_days'] + 1;
    if ($o['warm_days'] > $o['cool_days']) $o['warm_days'] = $o['cool_days'];
    $o['value_pcts'] = wsg_pcts_text(wsg_get($pdo, 'wa_seg_value_pcts', $d['value_pcts'])); $c = $o; return $o;
}
function wsg_pcts_valid(string $s): bool
{
    $p = array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== '')); if (count($p) !== 3) return false;
    foreach ($p as $x) { if (!is_numeric($x) || (float)$x <= 0 || (float)$x >= 100) return false; } return array_sum(array_map('floatval', $p)) <= 100;
}
/** "5,15,30" = the top 5% are VIP, the next 15% High, the next 30% Mid, the rest Low. Returns a clean "a,b,c" (defaults when wrong). */
function wsg_pcts_text(string $s): string
{
    $p = array_values(array_filter(array_map('trim', explode(',', $s)), fn($x) => $x !== '' && is_numeric($x))); if (count($p) !== 3) return '5,15,30';
    $p = array_map('floatval', $p); foreach ($p as $x) if ($x <= 0 || $x >= 100) return '5,15,30'; if (array_sum($p) > 100) return '5,15,30';
    return implode(',', array_map(fn($x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'), $p));
}
function wsg_stage_info(array $cfg): array
{
    return [
        'new' => ['New', 'First wrote to us less than ' . $cfg['new_days'] . ' days ago.'],
        'woke_up' => ['Woke up', 'Was silent for ' . $cfg['wake_days'] . '+ days, then wrote again in the last ' . $cfg['cool_days'] . ' days.'],
        'warm' => ['Warm', 'Wrote in the last ' . $cfg['warm_days'] . ' days.'],
        'active' => ['Active', 'Wrote ' . ($cfg['warm_days'] + 1) . ' to ' . $cfg['cool_days'] . ' days ago.'],
        'cooling' => ['Cooling', 'Last wrote ' . ($cfg['cool_days'] + 1) . ' to ' . $cfg['sleep_days'] . ' days ago.'],
        'sleeping' => ['Sleeping', 'Last wrote more than ' . $cfg['sleep_days'] . ' days ago.'],
        'never_replied' => ['Never replied', 'We sent them messages but they never wrote.'],
        'imported_unknown' => ['Imported, no activity', 'On the list (CSV import) but no message either way yet.'],
    ];
}
function wsg_value_info(array $cfg): array
{
    $p = array_map('floatval', explode(',', $cfg['value_pcts'])); $c1 = $p[0]; $c2 = $p[0] + $p[1]; $c3 = $c2 + $p[2]; $f = fn($x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    return ['vip' => ['VIP', 'Top ' . $f($c1) . '% of buyers by money spent.'], 'high' => ['High', 'Next ' . $f($p[1]) . '% of buyers (top ' . $f($c1) . '% to ' . $f($c2) . '%).'],
        'mid' => ['Mid', 'Next ' . $f($p[2]) . '% of buyers (top ' . $f($c2) . '% to ' . $f($c3) . '%).'], 'low' => ['Low', 'The rest of the buyers.'], 'not_bought' => ['Not bought', 'No order yet.']];
}
function wsg_sources(): array
{
    return ['ad' => 'Ad', 'qr' => 'QR code', 'community' => 'Community link', 'store' => 'Store', 'csv_import' => 'CSV import', 'campaign_reply' => 'Campaign reply', 'unknown' => 'Came from nowhere (unknown)'];
}

// ------------------------------------------------------------------------------------------------------------------ tables
function wsg_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cust_story (
            phone VARCHAR(12) NOT NULL PRIMARY KEY,
            first_seen DATETIME NULL, first_number_id INT NOT NULL DEFAULT 0, first_source VARCHAR(16) NOT NULL DEFAULT 'unknown',
            last_in_at DATETIME NULL, last_order_at DATETIME NULL, orders INT NOT NULL DEFAULT 0, spent DECIMAL(12,2) NOT NULL DEFAULT 0, avg_order DECIMAL(12,2) NOT NULL DEFAULT 0,
            sessions_30 SMALLINT NOT NULL DEFAULT 0, sessions_90 SMALLINT NOT NULL DEFAULT 0, sessions_all INT NOT NULL DEFAULT 0,
            asks_30 SMALLINT NOT NULL DEFAULT 0, asks_prev30 SMALLINT NOT NULL DEFAULT 0, asks_all INT NOT NULL DEFAULT 0, last_ask_at DATETIME NULL, interest_trend VARCHAR(8) NOT NULL DEFAULT 'none',
            longest_gap_days INT NOT NULL DEFAULT 0, last_sess_at DATETIME NULL, last_gap_days INT NULL, woke_at DATETIME NULL, woke_gap_days INT NULL,
            best_hour TINYINT NULL, best_wday TINYINT NULL, language_guess VARCHAR(10) NOT NULL DEFAULT '',
            stage VARCHAR(16) NOT NULL DEFAULT 'imported_unknown', value_tier VARCHAR(10) NOT NULL DEFAULT 'not_bought',
            flags SET('discount_buyer','asks_not_buys','cod','prepaid','complaint') NOT NULL DEFAULT '',
            camps_got INT NOT NULL DEFAULT 0, camps_read INT NOT NULL DEFAULT 0, camps_replied INT NOT NULL DEFAULT 0, last_camp_at DATETIME NULL, optout TINYINT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            KEY k_stage (stage, value_tier), KEY k_cnt (stage, value_tier, optout), KEY k_value (value_tier), KEY k_first (first_seen), KEY k_lastin (last_in_at), KEY k_order (last_order_at), KEY k_spent (spent),
            KEY k_src (first_source), KEY k_num (first_number_id), KEY k_lang (language_guess), KEY k_ask (last_ask_at), KEY k_woke (woke_at), KEY k_upd (updated_at)) $E");
        try { $pdo->query("SELECT optout FROM wa_cust_story LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_cust_story ADD COLUMN optout TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e2) { } }   // copy of wa_contacts.optout (kept in step by the tick) so a count needs no join
        try { $q = $pdo->query("SHOW INDEX FROM wa_cust_story WHERE Key_name = 'k_cnt'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_cust_story ADD INDEX k_cnt (stage, value_tier, optout)"); } catch (Throwable $e) { }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cust_sess (phone VARCHAR(12) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, msgs SMALLINT NOT NULL DEFAULT 1, number_id INT NOT NULL DEFAULT 0, ask TINYINT NOT NULL DEFAULT 0,
            snip VARCHAR(100) NOT NULL DEFAULT '', PRIMARY KEY (phone, started_at), KEY k_pn (phone, number_id), KEY k_num (number_id)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_seg_ord (phone VARCHAR(12) NOT NULL PRIMARY KEY, n INT NOT NULL DEFAULT 0, cod_n INT NOT NULL DEFAULT 0, prepaid_n INT NOT NULL DEFAULT 0, disc_n INT NOT NULL DEFAULT 0, fin_n INT NOT NULL DEFAULT 0) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_chat_digest (id INT AUTO_INCREMENT PRIMARY KEY, day DATE NOT NULL, text MEDIUMTEXT NULL, source VARCHAR(8) NOT NULL DEFAULT 'rules', sample_n INT NOT NULL DEFAULT 0, sample_json MEDIUMTEXT NULL, created_at DATETIME NULL, UNIQUE KEY u_day (day)) $E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_decisions (id INT AUTO_INCREMENT PRIMARY KEY, dkey VARCHAR(60) NOT NULL, text VARCHAR(500) NOT NULL, scope VARCHAR(10) NOT NULL DEFAULT 'all', set_by INT NOT NULL DEFAULT 0, at DATETIME NULL, active TINYINT NOT NULL DEFAULT 1, UNIQUE KEY u_key (dkey)) $E");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

// ------------------------------------------------------------------------------------------------------------------ the rules (pure functions: easy to test)
/** The one stage of a customer. Priority, top wins: new · woke_up · warm · active · cooling · sleeping · never_replied · imported_unknown. */
function wsg_stage(?int $firstTs, ?int $lastIn, bool $outAny, ?int $wokeAt, int $now, array $cfg): string
{
    if ($lastIn === null) return $outAny ? 'never_replied' : 'imported_unknown';
    if ($firstTs !== null && ($now - $firstTs) < $cfg['new_days'] * 86400) return 'new';
    if ($wokeAt !== null && ($now - $wokeAt) <= $cfg['cool_days'] * 86400) return 'woke_up';
    $age = max(0, $now - $lastIn);
    if ($age <= $cfg['warm_days'] * 86400) return 'warm';
    if ($age <= $cfg['cool_days'] * 86400) return 'active';
    if ($age <= $cfg['sleep_days'] * 86400) return 'cooling';
    return 'sleeping';
}
/** Value tier from the rupee cut-offs (spend of the owner's own buyers). A spend equal to a cut-off goes UP (ties are never split). */
function wsg_tier(int $orders, float $spent, array $cuts): string
{
    if ($orders <= 0) return 'not_bought';
    if ($spent <= 0) return 'low';
    if (isset($cuts['vip']) && $cuts['vip'] !== null && $spent >= (float)$cuts['vip']) return 'vip';
    if (isset($cuts['high']) && $cuts['high'] !== null && $spent >= (float)$cuts['high']) return 'high';
    if (isset($cuts['mid']) && $cuts['mid'] !== null && $spent >= (float)$cuts['mid']) return 'mid';
    return 'low';
}
/** Rupee cut-offs from a list of spends (buyers only), for percent steps "a,b,c" = top a% VIP, next b% High, next c% Mid. */
function wsg_cuts_from(array $spends, string $pcts): array
{
    $p = array_map('floatval', explode(',', wsg_pcts_text($pcts))); $spends = array_values(array_filter(array_map('floatval', $spends), fn($x) => $x > 0)); rsort($spends, SORT_NUMERIC); $n = count($spends);
    $o = ['vip' => null, 'high' => null, 'mid' => null, 'n' => $n, 'pcts' => implode(',', $p)];
    if ($n === 0) return $o;
    $cum = [$p[0], $p[0] + $p[1], $p[0] + $p[1] + $p[2]]; $names = ['vip', 'high', 'mid'];
    foreach ($names as $i => $nm) { $k = (int)max(1, min($n, (int)ceil($n * $cum[$i] / 100))); $o[$nm] = round($spends[$k - 1], 2); }
    return $o;
}
/** Words that mean "asking about a product / price / stock". */
function wsg_is_ask(string $t): bool
{
    static $re = '/(\?|\b(price|prices|rate|rates|cost|kitna|kitne|kimat|kimmat|keemat|bhav|bhaav|available|availability|stock|chahiye|chahie|joiye|joie|need|want|show|send|photo|pic|image|smell|fragrance|perfume|perfumes|scent|attar|itr|gel|combo|offer|discount|coupon|ml|bottle|buy|lena|levu|sample|catalog|catalogue|details|review|similar|alternative|brand|tester|notes)\b|कीमत|कितना|कितने|चाहिए|चाहिये|भाव|परफ्यूम|इत्र|ફ્રેગ્રન્સ|પરફ્યુમ|કિંમત|ભાવ|જોઈએ|કેટલા|કેટલો)/iu';
    return $t !== '' && (bool)preg_match($re, $t);
}
/** en | hi | gu | hinglish ('' when there is too little text), from the customer's own messages. */
function wsg_lang(string $t): string
{
    $dev = (int)preg_match_all('/\p{Devanagari}/u', $t); $guj = (int)preg_match_all('/\p{Gujarati}/u', $t); $lat = (int)preg_match_all('/[A-Za-z]/', $t); $L = $dev + $guj + $lat;
    if ($L < 3) return '';
    if ($guj / $L >= 0.3) return 'gu';
    if ($dev / $L >= 0.3) return 'hi';
    if ($dev > 0 && $lat > 0 && ($dev + $guj) / $L >= 0.1) return 'hinglish';
    static $hing = null, $eng = null;
    if ($hing === null) {
        $hing = array_flip(['hai', 'hain', 'kya', 'kaise', 'kaisa', 'kitna', 'kitne', 'kimat', 'nahi', 'nahin', 'haan', 'aap', 'aapka', 'aapko', 'mujhe', 'muje', 'mera', 'meri', 'mere', 'tum', 'tumhara', 'bhai', 'bhaiya', 'yaar', 'chahiye', 'chahie', 'dena', 'karo', 'karna', 'bhejo', 'bhej', 'batao', 'bataiye', 'dikhao', 'ka', 'ki', 'ke', 'ko', 'abhi', 'kab', 'kahan', 'kaun', 'kyun', 'kyu', 'accha', 'achha', 'theek', 'thik', 'ji', 'kem', 'cho', 'che', 'chhe', 'shu', 'nu', 'ni', 'tamne', 'tamaru', 'mane', 'mare', 'joiye', 'joie', 'kevi', 'ketla', 'ketlo', 'kyare', 'aavjo', 'hu', 'tame', 'aapo', 'moklo', 'mokalo', 'lena', 'wala', 'wali', 'bohot', 'bahut', 'kuch', 'koi', 'sab', 'wahi', 'abhi']);
        $eng = array_flip(['the', 'is', 'of', 'for', 'you', 'your', 'are', 'does', 'have', 'what', 'how', 'please', 'thanks', 'thank', 'want', 'need', 'send', 'can', 'will', 'my', 'and', 'with', 'this', 'that', 'it', 'in', 'on', 'price', 'available', 'hello', 'hi', 'which', 'where', 'when', 'do', 'to', 'me', 'i', 'a']);
    }
    preg_match_all('/[a-z]+/', mb_strtolower($t), $m); $h = 0; $e = 0; foreach ($m[0] as $w) { if (isset($hing[$w])) $h++; elseif (isset($eng[$w])) $e++; }
    return ($h >= 2 && $h * 2 >= $e) ? 'hinglish' : 'en';
}
function wsg_trend(int $a30, int $prev): string { if ($a30 === 0 && $prev === 0) return 'none'; return $a30 > $prev ? 'rising' : ($a30 < $prev ? 'falling' : 'steady'); }

/** Sessions from inbound events [[ts, number_id, mtype, body], …]: a new session starts after a silence of more than $gapSec seconds. */
function wsg_sessions(array $ins, int $gapSec): array
{
    usort($ins, fn($a, $b) => $a[0] <=> $b[0]); $S = [];
    foreach ($ins as [$ts, $num, $mt, $body]) {
        $isTxt = in_array($mt, ['text', 'button', 'interactive'], true) && trim((string)$body) !== ''; $n = count($S);
        if ($n && $ts - $S[$n - 1]['end'] <= $gapSec) { $S[$n - 1]['end'] = max($S[$n - 1]['end'], $ts); $S[$n - 1]['msgs']++; if ($isTxt) { if ($S[$n - 1]['snip'] === '') $S[$n - 1]['snip'] = wsg_short(wsg_redact((string)$body), 90); if (wsg_is_ask((string)$body)) $S[$n - 1]['ask'] = 1; } continue; }
        $S[] = ['start' => $ts, 'end' => $ts, 'msgs' => 1, 'number_id' => (int)$num, 'ask' => ($isTxt && wsg_is_ask((string)$body)) ? 1 : 0, 'snip' => $isTxt ? wsg_short(wsg_redact((string)$body), 90) : ''];
    }
    return $S;
}

/**
 * One customer's story row + sessions, from plain data (no database).
 *  $c      the wa_contacts row;  $msgs  [[dir, mtype, who, status, ts, number_id, body], …] oldest first (dir in / out only)
 *  $ord    ['n','last_order','spent'] from wa_cust;  $oa  ['cod_n','prepaid_n','disc_n','fin_n'] from wa_seg_ord;  $compl  true when a complaint exists
 */
function wsg_one(array $c, array $msgs, array $ord, array $oa, bool $compl, int $now, array $cfg, array $cuts): array
{
    $ins = []; $outAny = !empty($c['last_out_at']); $firstNum = 0; $firstDir = ''; $got = 0; $read = 0; $lastCamp = null; $campTs = []; $texts = [];
    foreach ($msgs as [$dir, $mt, $who, $st, $ts, $num, $body]) {
        if ($firstNum === 0 && (int)$num > 0) $firstNum = (int)$num;
        if ($firstDir === '') $firstDir = $dir;
        if ($dir === 'in') { $ins[] = [$ts, (int)$num, $mt, $body]; if ($mt === 'text' && trim((string)$body) !== '') $texts[] = (string)$body; }
        elseif ($dir === 'out') { $outAny = true; if ($mt === 'template' && $who === 'campaign' && $st !== 'failed') { $got++; if ($st === 'read') $read++; $campTs[] = $ts; if ($lastCamp === null || $ts > $lastCamp) $lastCamp = $ts; } }
    }
    $S = wsg_sessions($ins, $cfg['session_hours'] * 3600);
    // the customer row is the master for "when did they last write / first write"; the messages refine it
    $lastIn = wsg_ts($c['last_in_at'] ?? null); if ($S) { $le = $S[count($S) - 1]['end']; if ($lastIn === null || $le > $lastIn) $lastIn = $le; }
    $hasIn = $lastIn !== null;
    $first = wsg_ts($c['first_seen'] ?? null); if ($hasIn && $S && ($first === null || $S[0]['start'] < $first)) $first = $S[0]['start']; if ($hasIn && $first === null) $first = $lastIn;
    // gaps between sessions
    $longest = 0; $wokeAt = null; $wokeGap = null; $lastGap = null; $lastSess = $S ? $S[count($S) - 1]['start'] : null;
    for ($i = 1; $i < count($S); $i++) {
        $g = (int)floor(($S[$i]['start'] - $S[$i - 1]['end']) / 86400); if ($g > $longest) $longest = $g; $lastGap = $g;
        if ($g >= $cfg['wake_days']) { $wokeAt = $S[$i]['start']; $wokeGap = $g; }
    }
    $d30 = $now - 30 * 86400; $d60 = $now - 60 * 86400; $d90 = $now - 90 * 86400; $s30 = 0; $s90 = 0; $a30 = 0; $ap = 0; $aAll = 0; $lastAsk = null; $hrs = []; $wd = [];
    foreach ($S as $s) {
        if ($s['start'] > $d30) $s30++; if ($s['start'] > $d90) $s90++;
        if ($s['ask']) { $aAll++; if ($lastAsk === null || $s['start'] > $lastAsk) $lastAsk = $s['start']; if ($s['start'] > $d30) $a30++; elseif ($s['start'] > $d60) $ap++; }
        $hrs[] = (int)date('G', $s['start']); $wd[] = (int)date('N', $s['start']);
    }
    $mode = function (array $v) { if (count($v) < 2) return null; $cnt = array_count_values($v); $best = max($cnt); $pick = null; for ($i = count($v) - 1; $i >= 0; $i--) if ($cnt[$v[$i]] === $best) { $pick = $v[$i]; break; } return $pick; };   // the most common; a tie goes to the most recent
    $orders = (int)($ord['n'] ?? 0); $spent = round((float)($ord['spent'] ?? 0), 2); $lastOrder = wsg_ts($ord['last_order'] ?? null);
    $codN = (int)($oa['cod_n'] ?? 0); $preN = (int)($oa['prepaid_n'] ?? 0); $discN = (int)($oa['disc_n'] ?? 0); $finN = (int)($oa['fin_n'] ?? 0);
    $fl = []; if ($finN >= 2 && $discN >= 2 && $discN * 10 >= $finN * 7) $fl[] = 'discount_buyer'; if ($aAll >= $cfg['ask_min'] && $orders === 0) $fl[] = 'asks_not_buys';
    if ($codN > $preN) $fl[] = 'cod'; elseif ($preN > $codN) $fl[] = 'prepaid'; if ($compl) $fl[] = 'complaint';
    // campaigns answered: they wrote within 72 h after a campaign message
    $rep = 0; if ($campTs && $ins) { $its = array_column($ins, 0); sort($its); foreach ($campTs as $ct) { foreach ($its as $it) { if ($it > $ct) { if ($it <= $ct + 72 * 3600) $rep++; break; } } } }
    // the source: what the customer row says; a customer who never wrote is an import
    $srcMap = ['query' => 'ad', 'community' => 'community', 'campaign' => 'campaign_reply', 'qr' => 'qr', 'store' => 'store']; $cs = (string)($c['source'] ?? '');
    // no source on the customer row: if WE wrote first (or an earlier build already knew it was imported) it is a CSV import; if they wrote first out of the blue it is "unknown"
    $fsrc = isset($srcMap[$cs]) ? $srcMap[$cs] : (!$hasIn || $firstDir === 'out' || (string)($c['_prev_src'] ?? '') === 'csv_import' ? 'csv_import' : 'unknown');
    $lang = wsg_lang(implode("\n", array_slice($texts, -30)));
    $row = [
        'phone' => (string)$c['phone'], 'first_seen' => $first !== null ? wsg_dt($first) : null, 'first_number_id' => $firstNum > 0 ? $firstNum : (int)($c['last_number_id'] ?? 0), 'first_source' => $fsrc,
        'last_in_at' => $lastIn !== null ? wsg_dt($lastIn) : null, 'last_order_at' => $lastOrder !== null ? wsg_dt($lastOrder) : null, 'orders' => $orders, 'spent' => $spent, 'avg_order' => $orders > 0 ? round($spent / $orders, 2) : 0,
        'sessions_30' => $s30, 'sessions_90' => $s90, 'sessions_all' => count($S), 'asks_30' => $a30, 'asks_prev30' => $ap, 'asks_all' => $aAll, 'last_ask_at' => $lastAsk !== null ? wsg_dt($lastAsk) : null, 'interest_trend' => wsg_trend($a30, $ap),
        'longest_gap_days' => $longest, 'last_sess_at' => $lastSess !== null ? wsg_dt($lastSess) : null, 'last_gap_days' => $lastGap, 'woke_at' => $wokeAt !== null ? wsg_dt($wokeAt) : null, 'woke_gap_days' => $wokeGap,
        'best_hour' => $mode($hrs), 'best_wday' => $mode($wd), 'language_guess' => $lang,
        'stage' => wsg_stage($hasIn ? $first : null, $hasIn ? $lastIn : null, $outAny, $wokeAt, $now, $cfg), 'value_tier' => wsg_tier($orders, $spent, $cuts), 'flags' => implode(',', $fl),
        'camps_got' => $got, 'camps_read' => $read, 'camps_replied' => $rep, 'last_camp_at' => $lastCamp !== null ? wsg_dt($lastCamp) : null, 'optout' => (int)($c['optout'] ?? 0) === 1 ? 1 : 0, 'updated_at' => wsg_dt($now),
    ];
    $keep = array_slice($S, -60); $sess = [];
    foreach ($keep as $s) $sess[] = ['phone' => (string)$c['phone'], 'started_at' => wsg_dt($s['start']), 'ended_at' => wsg_dt($s['end']), 'msgs' => min(32000, $s['msgs']), 'number_id' => $s['number_id'], 'ask' => $s['ask'], 'snip' => $s['snip']];
    return [$row, $sess];
}

// ------------------------------------------------------------------------------------------------------------------ building the summary (always in small steps)
/** The cut-offs for the value tiers, kept in settings (wa_seg_cuts). $fresh = ask the order summary again (a sorted list of spends of every buyer). */
function wsg_cuts(PDO $pdo, bool $fresh = false): array
{
    $cfg = wsg_cfg($pdo); $cur = wsg_json($pdo, 'wa_seg_cuts');
    if (!$fresh && $cur && ($cur['pcts'] ?? '') === implode(',', array_map('floatval', explode(',', $cfg['value_pcts'])))) return $cur;
    try {
        if (function_exists('wa_cust_ready')) wa_cust_ready($pdo);
        $sp = $pdo->query("SELECT spent FROM wa_cust WHERE n > 0 AND spent > 0")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $sp = []; }
    $cuts = wsg_cuts_from($sp, $cfg['value_pcts']); $cuts['at'] = wsg_dt(wsg_now()); wsg_put($pdo, 'wa_seg_cuts', json_encode($cuts)); return $cuts;
}
/** Order behaviour per customer (cash on delivery vs prepaid, orders with a discount) in ONE grouped query, rebuilt at the start of each build. */
function wsg_ord_build(PDO $pdo): array
{
    $o = ['ok' => false, 'rows' => 0, 'fin' => false, 'matched' => 0];
    try {
        $pdo->exec("DROP TABLE IF EXISTS wa_seg_ord_new"); $pdo->exec("CREATE TABLE wa_seg_ord_new LIKE wa_seg_ord");
        $fin = false; try { $pdo->query("SELECT 1 FROM fin_orders LIMIT 1"); $fin = true; } catch (Throwable $e) { }
        $kx = "SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(o.phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), -10)";
        $tagCod = "(o.tags REGEXP '(^|[ ,])(cod|cash on delivery)([ ,]|$)')"; $tagPre = "(o.tags REGEXP '(^|[ ,])prepaid([ ,]|$)')";
        if ($fin) {
            $join = "LEFT JOIN (SELECT REPLACE(order_id, '#', '') AS oid, MAX(payment_type = 'COD') AS cod, MAX(payment_type = 'PREPAID') AS pre, MAX(discount > 0) AS disc FROM fin_orders GROUP BY REPLACE(order_id, '#', '')) f ON o.order_name <> '' AND f.oid = REPLACE(o.order_name, '#', '')";
            $isCod = "CASE WHEN f.oid IS NOT NULL THEN f.cod WHEN $tagCod OR o.financial_status IN ('pending', 'partially_paid') THEN 1 ELSE 0 END"; $isPre = "CASE WHEN f.oid IS NOT NULL THEN f.pre WHEN $tagPre THEN 1 ELSE 0 END";
            $isDisc = "COALESCE(f.disc, 0)"; $hasFin = "CASE WHEN f.oid IS NOT NULL THEN 1 ELSE 0 END";
        } else {
            $join = ""; $isCod = "CASE WHEN $tagCod OR o.financial_status IN ('pending', 'partially_paid') THEN 1 ELSE 0 END"; $isPre = "CASE WHEN $tagPre THEN 1 ELSE 0 END"; $isDisc = "0"; $hasFin = "0";
        }
        $pdo->exec("INSERT INTO wa_seg_ord_new (phone, n, cod_n, prepaid_n, disc_n, fin_n)
            SELECT k, COUNT(*), SUM(cod), SUM(pre), SUM(disc), SUM(hf) FROM (SELECT $kx AS k, ($isCod) AS cod, ($isPre) AS pre, ($isDisc) AS disc, ($hasFin) AS hf FROM shop_orders o $join WHERE o.phone IS NOT NULL AND o.phone <> '') x
            WHERE CHAR_LENGTH(k) = 10 GROUP BY k");
        $pdo->exec("DROP TABLE IF EXISTS wa_seg_ord_old"); $pdo->exec("RENAME TABLE wa_seg_ord TO wa_seg_ord_old, wa_seg_ord_new TO wa_seg_ord"); $pdo->exec("DROP TABLE IF EXISTS wa_seg_ord_old");
        $r = $pdo->query("SELECT COUNT(*), COALESCE(SUM(fin_n), 0), COALESCE(SUM(n), 0) FROM wa_seg_ord")->fetch(PDO::FETCH_NUM);
        $o = ['ok' => true, 'rows' => (int)$r[0], 'fin' => $fin, 'matched' => (int)$r[1], 'orders' => (int)$r[2]];
        wsg_put($pdo, 'wa_seg_data_notes', json_encode(['fin_table' => $fin, 'discount_known_orders' => (int)$r[1], 'orders_seen' => (int)$r[2], 'line_items' => false, 'at' => wsg_dt(wsg_now())]));
    } catch (Throwable $e) { $o['error'] = mb_substr($e->getMessage(), 0, 200); try { $pdo->exec("DROP TABLE IF EXISTS wa_seg_ord_new"); } catch (Throwable $e2) { } }
    return $o;
}

/** Messages (in / out only) of a list of customers, oldest first: phone => [[dir, mtype, who, status, ts, number_id, body], …]. Inbound text is cut at 300 characters. */
function wsg_msgs(PDO $pdo, array $phones, int $capEach = 1500): array
{
    if (!$phones) return []; sort($phones, SORT_STRING); $lo = $phones[0]; $hi = $phones[count($phones) - 1]; $want = array_flip($phones); $out = [];
    $q = $pdo->prepare("SELECT phone, dir, mtype, who, status, ts, number_id, CASE WHEN dir = 'in' THEN LEFT(body, 300) ELSE '' END AS b FROM wa_messages WHERE phone BETWEEN ? AND ? AND dir IN ('in', 'out') ORDER BY phone, id");
    $q->execute([$lo, $hi]);
    while ($r = $q->fetch(PDO::FETCH_NUM)) {
        $p = (string)$r[0]; if (!isset($want[$p])) continue;
        $out[$p][] = [(string)$r[1], (string)$r[2], (string)$r[3], (string)$r[4], (int)wsg_ts($r[5]), (int)$r[6], (string)$r[7]];
    }
    foreach ($out as $p => $l) if (count($l) > $capEach) $out[$p] = array_merge(array_slice($l, 0, 20), array_slice($l, -($capEach - 20)));   // a chat with thousands of messages: keep the first ones and the latest
    return $out;
}
/** Builds the story rows for a list of wa_contacts rows. Returns [rows, sessions]. */
function wsg_build_rows(PDO $pdo, array $contacts, int $now, ?array $cfg = null, ?array $cuts = null): array
{
    if (!$contacts) return [[], []]; $cfg = $cfg ?: wsg_cfg($pdo); $cuts = $cuts ?: wsg_cuts($pdo); $ph = array_map(fn($c) => (string)$c['phone'], $contacts);
    $msgs = wsg_msgs($pdo, $ph); $ord = []; $oa = []; $cx = []; $prevSrc = [];
    foreach (array_chunk($ph, 500) as $ch) {
        $q = $pdo->prepare("SELECT phone, n, last_order, spent FROM wa_cust WHERE phone IN (" . wsg_in($ch) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $r) $ord[(string)$r['phone']] = $r;
        $q = $pdo->prepare("SELECT phone, n, cod_n, prepaid_n, disc_n, fin_n FROM wa_seg_ord WHERE phone IN (" . wsg_in($ch) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $r) $oa[(string)$r['phone']] = $r;
        $q = $pdo->prepare("SELECT phone, first_source FROM wa_cust_story WHERE first_source = 'csv_import' AND phone IN (" . wsg_in($ch) . ")"); $q->execute($ch); foreach ($q->fetchAll() as $r) $prevSrc[(string)$r['phone']] = 'csv_import';
        foreach ([["SELECT DISTINCT phone FROM cx_cases WHERE phone IN (%s)"], ["SELECT DISTINCT phone FROM wa_outcomes WHERE code = 'complaint' AND phone IN (%s)"], ["SELECT DISTINCT phone FROM wa_ctags WHERE tag = 'complaint' AND phone IN (%s)"]] as [$sql]) {
            try { $q = $pdo->prepare(sprintf($sql, wsg_in($ch))); $q->execute($ch); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $cx[(string)$p] = true; } catch (Throwable $e) { }
        }
    }
    $rows = []; $sess = [];
    foreach ($contacts as $c) {
        $p = (string)$c['phone']; $compl = isset($cx[$p]) || (string)($c['tag'] ?? '') === 'complaint' || (int)($c['cx_id'] ?? 0) > 0;
        if (isset($prevSrc[$p])) $c['_prev_src'] = 'csv_import';
        [$r, $s] = wsg_one($c, $msgs[$p] ?? [], $ord[$p] ?? [], $oa[$p] ?? [], $compl, $now, $cfg, $cuts); $rows[] = $r; foreach ($s as $x) $sess[] = $x;
    }
    return [$rows, $sess];
}
if (!defined('WSG_STORY_COLS')) define('WSG_STORY_COLS', ['phone', 'first_seen', 'first_number_id', 'first_source', 'last_in_at', 'last_order_at', 'orders', 'spent', 'avg_order', 'sessions_30', 'sessions_90', 'sessions_all', 'asks_30', 'asks_prev30', 'asks_all', 'last_ask_at', 'interest_trend', 'longest_gap_days', 'last_sess_at', 'last_gap_days', 'woke_at', 'woke_gap_days', 'best_hour', 'best_wday', 'language_guess', 'stage', 'value_tier', 'flags', 'camps_got', 'camps_read', 'camps_replied', 'last_camp_at', 'optout', 'updated_at']);
/** Writes the rows + sessions of a batch (replacing the old ones of the same customers). Safe to repeat. */
function wsg_save(PDO $pdo, array $rows, array $sess): int
{
    if (!$rows) return 0; $cols = WSG_STORY_COLS; $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        foreach (array_chunk($rows, 200) as $ch) {
            $sql = "REPLACE INTO wa_cust_story (" . implode(',', $cols) . ") VALUES " . implode(',', array_fill(0, count($ch), '(' . implode(',', array_fill(0, count($cols), '?')) . ')')); $p = [];
            foreach ($ch as $r) foreach ($cols as $k) $p[] = $r[$k] ?? null;
            $pdo->prepare($sql)->execute($p);
        }
        $ph = array_map(fn($r) => (string)$r['phone'], $rows);
        foreach (array_chunk($ph, 500) as $ch) $pdo->prepare("DELETE FROM wa_cust_sess WHERE phone IN (" . wsg_in($ch) . ")")->execute($ch);
        foreach (array_chunk($sess, 300) as $ch) {
            $pdo->prepare("INSERT IGNORE INTO wa_cust_sess (phone, started_at, ended_at, msgs, number_id, ask, snip) VALUES " . implode(',', array_fill(0, count($ch), '(?,?,?,?,?,?,?)')))
                ->execute(array_merge(...array_map(fn($s) => [$s['phone'], $s['started_at'], $s['ended_at'], $s['msgs'], $s['number_id'], $s['ask'], $s['snip']], $ch)));
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return count($rows);
}
/** One customer, from scratch (a few queries on indexed columns). Returns the story row or null when the customer does not exist. */
function wa_seg_refresh_one(PDO $pdo, string $phone): ?array
{
    if (!wsg_tables($pdo)) return null;
    $q = $pdo->prepare("SELECT phone, name, first_seen, last_in_at, last_out_at, last_number_id, optout, source, tag, cx_id FROM wa_contacts WHERE phone = ?"); $q->execute([$phone]); $c = $q->fetch(); if (!$c) return null;
    [$rows, $sess] = wsg_build_rows($pdo, [$c], wsg_now()); wsg_save($pdo, $rows, $sess); return $rows[0] ?? null;
}
/** Called right after a customer message is stored (wa_source_hook). Never throws, never slow: it rebuilds this one customer. */
function wa_seg_touch(PDO $pdo, string $phone, ?string $prevIn = null, ?string $now = null): void
{
    try {
        if (!wsg_tables($pdo)) return;
        if (($prevIn === null || $prevIn === '') && $now !== null && $now !== '') { $pdo->prepare("UPDATE wa_contacts SET first_seen = ? WHERE phone = ?")->execute([$now, $phone]); }   // the first time THEY wrote (the same value wa_source_hook writes)
        wa_seg_refresh_one($pdo, $phone);
    } catch (Throwable $e) { }
}
/** The build state kept in settings (wa_seg_cycle): idle | running, with the cursor (last phone done). */
function wsg_cycle(PDO $pdo): array
{
    $c = wsg_json($pdo, 'wa_seg_cycle'); return $c + ['state' => 'idle', 'cursor' => '', 'done' => 0, 'total' => 0, 'started' => 0, 'finished' => 0, 'hi' => ''];
}
function wsg_cycle_put(PDO $pdo, array $c): void { wsg_put($pdo, 'wa_seg_cycle', json_encode($c)); }
/** Starts a new build: refresh the order behaviour + the cut-offs, then the cursor goes to the beginning. */
function wsg_cycle_start(PDO $pdo, string $lo = '', string $hi = ''): array
{
    $ord = wsg_ord_build($pdo); wsg_cuts($pdo, true);
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts WHERE phone > ?" . ($hi !== '' ? " AND phone <= ?" : "")); $q->execute($hi !== '' ? [$lo, $hi] : [$lo]);
    $c = ['state' => 'running', 'cursor' => $lo, 'done' => 0, 'total' => (int)$q->fetchColumn(), 'started' => wsg_now(), 'finished' => 0, 'hi' => $hi, 'ord' => $ord['ok'] ?? false];
    wsg_cycle_put($pdo, $c); return $c;
}
/** Builds contacts after the cursor until $limit customers are done or the deadline (unix float) has passed. Returns the cycle state (finished = true at the end). */
function wsg_chunk(PDO $pdo, int $limit, float $deadline, ?array $cyc = null): array
{
    $cyc = $cyc ?: wsg_cycle($pdo); if ($cyc['state'] !== 'running') return $cyc + ['built' => 0]; $cfg = wsg_cfg($pdo, true); $cuts = wsg_cuts($pdo); $built = 0; $per = 250;
    if (function_exists('wa_cust_ready')) wa_cust_ready($pdo);
    while ($built < $limit && microtime(true) < $deadline) {
        $n = min($per, $limit - $built); $hi = (string)($cyc['hi'] ?? '');
        $q = $pdo->prepare("SELECT phone, name, first_seen, last_in_at, last_out_at, last_number_id, optout, source, tag, cx_id FROM wa_contacts WHERE phone > ?" . ($hi !== '' ? " AND phone <= ?" : "") . " ORDER BY phone LIMIT " . (int)$n);
        $q->execute($hi !== '' ? [$cyc['cursor'], $hi] : [$cyc['cursor']]); $cs = $q->fetchAll();
        if (!$cs) { $cyc = wsg_cycle_finish($pdo, $cyc); $cyc['built'] = $built; return $cyc; }
        $t0 = microtime(true); [$rows, $sess] = wsg_build_rows($pdo, $cs, wsg_now(), $cfg, $cuts); wsg_save($pdo, $rows, $sess);
        $built += count($cs); $cyc['cursor'] = (string)$cs[count($cs) - 1]['phone']; $cyc['done'] = (int)$cyc['done'] + count($cs); wsg_cycle_put($pdo, $cyc);
        if (count($cs) < $n) { $cyc = wsg_cycle_finish($pdo, $cyc); $cyc['built'] = $built; return $cyc; }
        if (microtime(true) + (microtime(true) - $t0) * 1.2 > $deadline) break;   // the next batch would not fit in the time box
    }
    $cyc['built'] = $built; return $cyc;
}
/** End of a build: forget customers who no longer exist, remember the stage matrix, log. */
function wsg_cycle_finish(PDO $pdo, array $cyc): array
{
    if (($cyc['hi'] ?? '') === '') {   // a partial range (tests) must not delete the rest
        try { $pdo->exec("DELETE st FROM wa_cust_story st LEFT JOIN wa_contacts c ON c.phone = st.phone WHERE c.phone IS NULL"); $pdo->exec("DELETE s FROM wa_cust_sess s LEFT JOIN wa_contacts c ON c.phone = s.phone WHERE c.phone IS NULL"); } catch (Throwable $e) { }
    }
    $cyc['state'] = 'idle'; $cyc['finished'] = wsg_now(); $cyc['cursor'] = ''; wsg_cycle_put($pdo, $cyc);
    try { wsg_matrix($pdo, true); } catch (Throwable $e) { }
    wsg_log($pdo, true, 'Customer story rebuilt for ' . wsg_n($cyc['done']) . ' customers in ' . max(0, wsg_now() - (int)$cyc['started']) . ' s.'); return $cyc;
}

// ------------------------------------------------------------------------------------------------------------------ counts, matrix, histogram
/** The filter array reduced to the keys wa_aud_where() understands, with safe types. */
function wa_seg_filters_clean($raw): array
{
    if (!is_array($raw)) return [];
    $o = []; $enumList = function ($v, array $allowed) { $v = is_array($v) ? $v : ($v === '' || $v === null ? [] : [$v]); return array_values(array_unique(array_filter(array_map('strval', $v), fn($x) => in_array($x, $allowed, true)))); };
    foreach (['st', 'ord', 'dsl', 'src', 'wrote', 'gtag', 'ords'] as $k) if (isset($raw[$k]) && is_scalar($raw[$k]) && $raw[$k] !== '') $o[$k] = mb_substr((string)$raw[$k], 0, 40);
    if (isset($raw['ign']) && (int)$raw['ign'] > 0) $o['ign'] = min(50, (int)$raw['ign']);
    if (is_array($raw['gtags'] ?? null)) { $g = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), $raw['gtags']), fn($x) => $x !== ''))); if ($g) $o['gtags'] = array_slice($g, 0, 30); }
    $stages = array_keys(wsg_stage_info(wsg_defaults())); $vals = ['vip', 'high', 'mid', 'low', 'not_bought']; $srcs = array_keys(wsg_sources());
    if ($l = $enumList($raw['stage'] ?? [], $stages)) $o['stage'] = $l;
    if ($l = $enumList($raw['value'] ?? [], $vals)) $o['value'] = $l;
    if ($l = $enumList($raw['source'] ?? [], $srcs)) $o['source'] = $l;
    if ($l = $enumList($raw['language'] ?? [], ['en', 'hi', 'gu', 'hinglish', 'unknown'])) $o['language'] = $l;
    foreach (['first_number', 'number_used'] as $k) { $v = is_array($raw[$k] ?? null) ? $raw[$k] : []; $v = array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0))); if ($v) $o[$k] = array_slice($v, 0, 40); }
    foreach (['first_from', 'first_to', 'last_in_from', 'last_in_to', 'ordered_from', 'ordered_to', 'silent_min_days', 'returned_within_days', 'asked_days', 'exclude_bought_days', 'exclude_messaged_days', 'wrote_from', 'wrote_to'] as $k)
        if (isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== null && is_numeric($raw[$k])) $o[$k] = max(0, min(3650, (int)$raw[$k]));
    foreach (['order_count_min', 'order_count_max'] as $k) if (isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== null && is_numeric($raw[$k])) $o[$k] = max(0, min(100000, (int)$raw[$k]));
    foreach (['spent_min', 'spent_max'] as $k) if (isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== null && is_numeric($raw[$k])) $o[$k] = max(0, min(100000000, (float)$raw[$k]));
    foreach (['first_on_from', 'first_on_to', 'last_in_on_from', 'last_in_on_to'] as $k) if (isset($raw[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$raw[$k])) $o[$k] = (string)$raw[$k];
    if (!empty($raw['exclude_complaint'])) $o['exclude_complaint'] = 1;
    return $o;
}
/** The filters without the exclusions (what the audience is BEFORE we skip anybody). */
function wsg_positive(array $f): array { foreach (['exclude_bought_days', 'exclude_messaged_days', 'exclude_complaint'] as $k) unset($f[$k]); return $f; }
function wsg_days_label(int $d): string { return $d === 7 ? 'this week' : ($d === 1 ? 'today' : 'in the last ' . $d . ' days'); }
/** The opt-out flag is copied into the story table (so counts need no join). This puts the copy right again: only the few opted-out numbers are looked at. */
function wsg_optout_sync(PDO $pdo): int
{
    $ph = $pdo->query("SELECT phone FROM wa_contacts WHERE optout = 1")->fetchAll(PDO::FETCH_COLUMN); $n = 0;
    foreach (array_chunk($ph, 500) as $ch) { $q = $pdo->prepare("UPDATE wa_cust_story SET optout = 1 WHERE optout = 0 AND phone IN (" . wsg_in($ch) . ")"); $q->execute($ch); $n += $q->rowCount(); }
    $stale = $pdo->query("SELECT phone FROM wa_cust_story WHERE optout = 1")->fetchAll(PDO::FETCH_COLUMN); $keep = array_flip($ph); $off = array_values(array_filter($stale, fn($x) => !isset($keep[$x])));
    foreach (array_chunk($off, 500) as $ch) { $q = $pdo->prepare("UPDATE wa_cust_story SET optout = 0 WHERE phone IN (" . wsg_in($ch) . ")"); $q->execute($ch); $n += $q->rowCount(); }
    return $n;
}
/**
 * How many customers match: total, sending, skipped (each customer counted once, under the first reason), per stage and per value tier.
 * ONE grouped query. Filters that only look at the story table are answered from the story table alone (index only, tens of milliseconds);
 * filters that need the chat row or the order summary (messaged / bought recently, order counts, spend, last-wrote ranges) join those.
 */
function wa_seg_count(PDO $pdo, array $filters): array
{
    $t0 = microtime(true); wsg_tables($pdo); $f = wa_seg_filters_clean($filters); $pos = wsg_positive($f); $pos['_story'] = 1; [$fq, $p] = wa_aud_where('contacts', $pos, '');
    $now = wsg_now(); $d = fn(int $n) => wsg_dt($now - $n * 86400); $par = []; $where = (strpos($fq, ' WHERE ') === false) ? '' : substr($fq, strpos($fq, ' WHERE '));
    $fast = !preg_match('/\b[cs]\./', $where) && !isset($f['exclude_bought_days']) && !isset($f['exclude_messaged_days']);
    $oc = $fast ? 'st.optout' : 'COALESCE(c.optout, 0)';
    $ex = "CASE WHEN $oc = 1 THEN 'optout'";
    if (!empty($f['exclude_complaint'])) $ex .= " WHEN FIND_IN_SET('complaint', st.flags) > 0 THEN 'complaint'";
    if (isset($f['exclude_bought_days'])) { $ex .= " WHEN s.last_order >= ? THEN 'bought'"; $par[] = $d((int)$f['exclude_bought_days']); }
    if (isset($f['exclude_messaged_days'])) { $ex .= " WHEN c.last_out_at >= ? THEN 'messaged'"; $par[] = $d((int)$f['exclude_messaged_days']); }
    $ex .= " ELSE 'ok' END";
    $src = $fast ? "wa_cust_story st" . $where : $fq;
    $q = $pdo->prepare("SELECT $ex AS why, COALESCE(st.stage, 'unbuilt') AS stg, COALESCE(st.value_tier, 'unbuilt') AS vt, COUNT(*) AS n FROM $src GROUP BY 1, 2, 3"); $q->execute(array_merge($par, $p));
    $rows = $q->fetchAll();
    if ($fast && $where === '') {   // nothing filtered: customers without a story row yet are still customers
        $miss = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts")->fetchColumn() - (int)$pdo->query("SELECT COUNT(*) FROM wa_cust_story")->fetchColumn(); if ($miss > 0) $rows[] = ['why' => 'ok', 'stg' => 'unbuilt', 'vt' => 'unbuilt', 'n' => $miss];
    }
    $total = 0; $send = 0; $why = ['optout' => 0, 'complaint' => 0, 'bought' => 0, 'messaged' => 0]; $stg = []; $val = [];
    $add = function (array &$arr, string $k, int $n, bool $ok) { if (!isset($arr[$k])) $arr[$k] = ['total' => 0, 'sendable' => 0]; $arr[$k]['total'] += $n; if ($ok) $arr[$k]['sendable'] += $n; };
    foreach ($rows as $r) {
        $n = (int)$r['n']; $total += $n; $ok = $r['why'] === 'ok'; if ($ok) $send += $n; else $why[$r['why']] += $n;
        $add($stg, (string)$r['stg'], $n, $ok); $add($val, (string)$r['vt'], $n, $ok);
    }
    $lab = ['optout' => 'opted out', 'complaint' => 'open complaint', 'bought' => 'bought ' . (isset($f['exclude_bought_days']) ? wsg_days_label((int)$f['exclude_bought_days']) : ''), 'messaged' => 'messaged ' . (isset($f['exclude_messaged_days']) ? wsg_days_label((int)$f['exclude_messaged_days']) : '')];
    $parts = []; foreach ($why as $k => $n) if ($n > 0) $parts[] = trim($lab[$k]) . ' ' . wsg_n($n); $skipped = $total - $send;
    $text = 'Total ' . wsg_n($total) . ' = sending ' . wsg_n($send) . ' + skipped ' . wsg_n($skipped) . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    $unb = $stg['unbuilt']['total'] ?? 0;
    return ['total' => $total, 'sendable' => $send, 'skipped' => $skipped, 'excluded' => $why, 'by_stage' => $stg, 'by_value' => $val, 'unbuilt' => $unb, 'adds_up' => ['ok' => $total === $send + array_sum($why), 'text' => $text],
        'filters' => $f, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'fast' => $fast];
}
/** Month histogram of the matched customers: first contact month (imports counted separately) and last-wrote month. */
function wa_seg_hist(PDO $pdo, array $filters): array
{
    $t0 = microtime(true); wsg_tables($pdo); $f = wsg_positive(wa_seg_filters_clean($filters)); $f['_story'] = 1; [$fq, $p] = wa_aud_where('contacts', $f, '');
    $where = (strpos($fq, ' WHERE ') === false) ? '' : substr($fq, strpos($fq, ' WHERE ')); if (!preg_match('/\b[cs]\./', $where)) $fq = 'wa_cust_story st' . $where;   // story-only filters need no join
    $a = $pdo->prepare("SELECT DATE_FORMAT(st.first_seen, '%Y-%m') AS m, COUNT(*) AS n, SUM(st.first_source = 'csv_import') AS imp FROM $fq" . (strpos($fq, ' WHERE ') === false ? ' WHERE ' : ' AND ') . "st.first_seen IS NOT NULL GROUP BY m ORDER BY m"); $a->execute($p);
    $b = $pdo->prepare("SELECT DATE_FORMAT(st.last_in_at, '%Y-%m') AS m, COUNT(*) AS n FROM $fq" . (strpos($fq, ' WHERE ') === false ? ' WHERE ' : ' AND ') . "st.last_in_at IS NOT NULL GROUP BY m ORDER BY m"); $b->execute($p);
    return ['first' => array_map(fn($r) => ['m' => (string)$r['m'], 'n' => (int)$r['n'], 'imported' => (int)$r['imp']], $a->fetchAll()), 'last' => array_map(fn($r) => ['m' => (string)$r['m'], 'n' => (int)$r['n']], $b->fetchAll()), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}
/** Stage x value tier for ALL customers, kept for 2 minutes (and rewritten at the end of every build). */
function wsg_matrix(PDO $pdo, bool $fresh = false): array
{
    $c = wsg_json($pdo, 'wa_seg_matrix'); if (!$fresh && $c && (int)($c['at'] ?? 0) > wsg_now() - 120) return $c;
    $m = []; $built = 0; foreach ($pdo->query("SELECT stage, value_tier, COUNT(*) n FROM wa_cust_story GROUP BY stage, value_tier")->fetchAll() as $r) { $m[$r['stage']][$r['value_tier']] = (int)$r['n']; $built += (int)$r['n']; }
    $total = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts")->fetchColumn(); $lang = []; foreach ($pdo->query("SELECT language_guess, COUNT(*) n FROM wa_cust_story GROUP BY language_guess")->fetchAll() as $r) $lang[$r['language_guess'] === '' ? 'unknown' : $r['language_guess']] = (int)$r['n'];
    $fk = ['discount_buyer', 'asks_not_buys', 'cod', 'prepaid', 'complaint']; $fr = $pdo->query("SELECT " . implode(', ', array_map(fn($x) => "COALESCE(SUM(FIND_IN_SET('$x', flags) > 0), 0)", $fk)) . " FROM wa_cust_story")->fetch(PDO::FETCH_NUM); $flags = []; foreach ($fk as $i => $x) $flags[$x] = (int)($fr[$i] ?? 0);
    $c = ['at' => wsg_now(), 'matrix' => $m, 'built' => $built, 'total' => $total, 'lang' => $lang, 'flags' => $flags]; wsg_put($pdo, 'wa_seg_matrix', json_encode($c)); return $c;
}
/** Everything the "Customer groups" screen needs. */
function wsg_stages(PDO $pdo, bool $admin, bool $fresh = false): array
{
    $cfg = wsg_cfg($pdo); $mx = wsg_matrix($pdo, $fresh); $sinfo = wsg_stage_info($cfg); $vinfo = wsg_value_info($cfg); $stages = []; $vals = [];
    foreach ($sinfo as $k => [$l, $d]) { $row = $mx['matrix'][$k] ?? []; $stages[] = ['key' => $k, 'label' => $l, 'help' => $d, 'n' => array_sum($row), 'by_value' => $row]; }
    foreach ($vinfo as $k => [$l, $d]) { $n = 0; foreach ($mx['matrix'] as $row) $n += (int)($row[$k] ?? 0); $vals[] = ['key' => $k, 'label' => $l, 'help' => $d, 'n' => $n]; }
    $sum = array_sum(array_map(fn($s) => $s['n'], $stages)); $unb = max(0, $mx['total'] - $mx['built']); $cy = wsg_cycle($pdo); $cuts = wsg_json($pdo, 'wa_seg_cuts');
    $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) $nums[] = ['id' => (int)$n['id'], 'label' => (string)($n['label'] !== '' ? $n['label'] : $n['display'])];
    $notes = wsg_json($pdo, 'wa_seg_data_notes'); $disc = !empty($notes['discount_known_orders']);
    $out = ['stages' => $stages, 'values' => $vals, 'total_contacts' => $mx['total'], 'built' => $mx['built'], 'unbuilt' => $unb,
        'adds_up' => ['ok' => $sum + $unb === $mx['total'], 'text' => 'Total ' . wsg_n($mx['total']) . ' = ' . implode(' + ', array_map(fn($s) => wsg_n($s['n']) . ' ' . mb_strtolower($s['label']), array_filter($stages, fn($s) => $s['n'] > 0))) . ($unb > 0 ? ' + ' . wsg_n($unb) . ' not calculated yet' : '')],
        'language' => $mx['lang'], 'flags' => $mx['flags'], 'numbers' => $nums, 'sources' => wsg_sources(), 'config' => $cfg,
        'progress' => ['state' => $cy['state'], 'done' => (int)$cy['done'], 'total' => (int)$cy['total'], 'percent' => $cy['state'] === 'running' && $cy['total'] > 0 ? (int)floor(100 * $cy['done'] / $cy['total']) : ($cy['finished'] ? 100 : 0), 'finished_at' => $cy['finished'] ? wsg_dt((int)$cy['finished']) : ''],
        'data' => ['cod_prepaid' => true, 'discount' => $disc, 'discount_note' => $disc ? '' : 'Discount buyers: the order table does not say which orders had a discount (needs the finance order sheet with Order ID + Discount).', 'gel_vs_perfume' => false, 'gel_vs_perfume_note' => 'Gel vs perfume buyer is not shown: the order table keeps no product lines.']];
    $out['cutoffs'] = $admin ? ['vip' => $cuts['vip'] ?? null, 'high' => $cuts['high'] ?? null, 'mid' => $cuts['mid'] ?? null, 'buyers' => (int)($cuts['n'] ?? 0), 'pcts' => (string)($cuts['pcts'] ?? ''), 'at' => (string)($cuts['at'] ?? '')] : null;
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ one customer's story (wa.seg_customer)
function wsg_hour_label(?int $h): string { if ($h === null) return ''; $ap = $h >= 12 ? 'pm' : 'am'; return (($h % 12) ?: 12) . ' ' . $ap; }
function wsg_wday_label(?int $d): string { return $d === null ? '' : ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$d] ?? ''; }
/** Orders of one customer by indexed lookups on the common phone formats (never a table scan). */
function wsg_orders_for(PDO $pdo, string $k, int $lim = 12): array
{
    $v = array_values(array_unique([$k, '91' . $k, '+91' . $k, '0' . $k, '+91 ' . $k, '91 ' . $k, '+91-' . $k, substr($k, 0, 5) . ' ' . substr($k, 5), '+91 ' . substr($k, 0, 5) . ' ' . substr($k, 5), '+91' . substr($k, 0, 5) . ' ' . substr($k, 5), '+91-' . substr($k, 0, 5) . '-' . substr($k, 5)])); $o = [];
    try { $q = $pdo->prepare("SELECT order_name, created_at, total, financial_status FROM shop_orders WHERE phone IN (" . wsg_in($v) . ") ORDER BY created_at DESC LIMIT " . (int)$lim); $q->execute($v); foreach ($q->fetchAll() as $r) $o[] = $r; } catch (Throwable $e) { }
    return $o;
}
function wsg_prod_names(PDO $pdo): array
{
    static $n = null; if ($n !== null) return $n; $n = [];
    try { foreach ($pdo->query("SELECT name FROM wa_prod WHERE active = 1 AND kind IN ('perfume', 'gel', 'candle') ORDER BY units_sold DESC LIMIT 600")->fetchAll(PDO::FETCH_COLUMN) as $x) { $x = trim((string)$x); if (mb_strlen($x) >= 4) $n[mb_strtolower($x)] = $x; } } catch (Throwable $e) { }
    return $n;
}
function wsg_customer(PDO $pdo, string $phone): ?array
{
    if (!wsg_tables($pdo)) return null;
    $q = $pdo->prepare("SELECT * FROM wa_cust_story WHERE phone = ?"); $q->execute([$phone]); $s = $q->fetch();
    if (!$s || (string)$s['updated_at'] < wsg_dt(wsg_now() - 3 * 86400)) { $r = wa_seg_refresh_one($pdo, $phone); if ($r === null) return null; $q->execute([$phone]); $s = $q->fetch(); if (!$s) return null; }
    $cq = $pdo->prepare("SELECT optout, source, ref_text, ad_head, tag, cx_id FROM wa_contacts WHERE phone = ?"); $cq->execute([$phone]); $c = $cq->fetch() ?: [];
    $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nums[(int)$n['id']] = (string)($n['label'] !== '' ? $n['label'] : $n['display']);
    $tl = []; $names = wsg_prod_names($pdo);
    $sq = $pdo->prepare("SELECT started_at, msgs, number_id, ask, snip FROM wa_cust_sess WHERE phone = ? ORDER BY started_at DESC LIMIT 40"); $sq->execute([$phone]);
    foreach ($sq->fetchAll() as $r) {
        $asked = ''; if ($r['ask'] && $names && $r['snip'] !== '') { $low = mb_strtolower((string)$r['snip']); foreach ($names as $lk => $nm) if (mb_strpos($low, $lk) !== false) { $asked = $nm; break; } }
        $tl[] = ['at' => (string)$r['started_at'], 'type' => 'session', 'text' => 'Wrote ' . (int)$r['msgs'] . ($r['msgs'] == 1 ? ' message' : ' messages') . ((int)$r['number_id'] > 0 && isset($nums[(int)$r['number_id']]) ? ' on ' . $nums[(int)$r['number_id']] : '') . ($r['snip'] !== '' ? ': "' . $r['snip'] . '"' : ''), 'ask' => (int)$r['ask'] === 1, 'perfume' => $asked];
    }
    foreach (wsg_orders_for($pdo, $phone, 12) as $o) $tl[] = ['at' => (string)$o['created_at'], 'type' => 'order', 'text' => 'Order ' . ($o['order_name'] !== '' ? $o['order_name'] : '') . ' · Rs ' . wsg_n($o['total']) . ($o['financial_status'] !== '' ? ' · ' . $o['financial_status'] : '')];
    try {
        $mq = $pdo->prepare("SELECT ts, status, LEFT(body, 90) AS b FROM wa_messages WHERE phone = ? AND dir = 'out' AND mtype = 'template' AND who = 'campaign' ORDER BY id DESC LIMIT 12"); $mq->execute([$phone]);
        foreach ($mq->fetchAll() as $r) $tl[] = ['at' => (string)$r['ts'], 'type' => 'campaign', 'text' => 'Campaign message ' . ($r['status'] === 'read' ? '(read)' : ($r['status'] === 'failed' ? '(failed)' : '(' . ($r['status'] ?: 'sent') . ')')) . ($r['b'] !== '' ? ': ' . wsg_short(wsg_redact((string)$r['b']), 80) : '')];
    } catch (Throwable $e) { }
    try { $tq = $pdo->prepare("SELECT tag, at FROM wa_ctags WHERE phone = ? AND at IS NOT NULL ORDER BY at DESC LIMIT 8"); $tq->execute([$phone]); foreach ($tq->fetchAll() as $r) $tl[] = ['at' => (string)$r['at'], 'type' => 'tag', 'text' => 'Tag: ' . $r['tag']]; } catch (Throwable $e) { }
    try { $xq = $pdo->prepare("SELECT raised_at, problem, status FROM cx_cases WHERE phone = ? ORDER BY raised_at DESC LIMIT 5"); $xq->execute([$phone]); foreach ($xq->fetchAll() as $r) $tl[] = ['at' => (string)$r['raised_at'], 'type' => 'complaint', 'text' => 'Complaint (' . $r['problem'] . ', ' . $r['status'] . ')']; } catch (Throwable $e) { }
    if (!empty($s['woke_at'])) $tl[] = ['at' => (string)$s['woke_at'], 'type' => 'woke', 'text' => 'Came back after ' . (int)$s['woke_gap_days'] . ' days of silence'];
    if ((int)($c['optout'] ?? 0) === 1 && !empty($s['last_in_at'])) $tl[] = ['at' => (string)$s['last_in_at'], 'type' => 'optout', 'text' => 'Opted out of messages'];
    usort($tl, fn($a, $b) => strcmp($b['at'], $a['at'])); $tl = array_slice($tl, 0, 40);
    $imported = $s['first_source'] === 'csv_import'; $srcLab = wsg_sources();
    $first = ['at' => (string)$s['first_seen'], 'number_id' => (int)$s['first_number_id'], 'number' => $nums[(int)$s['first_number_id']] ?? '', 'source' => (string)$s['first_source'], 'source_label' => $srcLab[$s['first_source']] ?? $s['first_source'],
        'source_text' => $s['first_source'] === 'ad' ? (string)($c['ad_head'] ?: $c['ref_text'] ?? '') : '', 'imported' => $imported && empty($s['last_in_at']), 'label' => $imported ? (empty($s['last_in_at']) ? 'Imported on ' . substr((string)$s['first_seen'], 0, 10) : 'Imported from a list, first wrote ' . substr((string)$s['first_seen'], 0, 10)) : ($s['first_source'] === 'unknown' ? 'First wrote ' . substr((string)$s['first_seen'], 0, 10) . ' (came from nowhere)' : 'First wrote ' . substr((string)$s['first_seen'], 0, 10))];
    $cfg = wsg_cfg($pdo); $si = wsg_stage_info($cfg); $vi = wsg_value_info($cfg); $fl = $s['flags'] === '' ? [] : explode(',', (string)$s['flags']);
    $facts = ['language' => (string)$s['language_guess'], 'best_hour' => $s['best_hour'] !== null ? (int)$s['best_hour'] : null, 'best_hour_label' => wsg_hour_label($s['best_hour'] !== null ? (int)$s['best_hour'] : null), 'best_wday' => $s['best_wday'] !== null ? (int)$s['best_wday'] : null, 'best_wday_label' => wsg_wday_label($s['best_wday'] !== null ? (int)$s['best_wday'] : null),
        'sessions_30' => (int)$s['sessions_30'], 'sessions_90' => (int)$s['sessions_90'], 'sessions_all' => (int)$s['sessions_all'], 'asks_30' => (int)$s['asks_30'], 'asks_prev30' => (int)$s['asks_prev30'], 'asks_all' => (int)$s['asks_all'],
        'avg_order' => (float)$s['avg_order'], 'longest_gap_days' => (int)$s['longest_gap_days'], 'campaigns_got' => (int)$s['camps_got'], 'campaigns_read' => (int)$s['camps_read'], 'campaigns_replied' => (int)$s['camps_replied'], 'last_order_at' => (string)$s['last_order_at'],
        'flags' => $fl, 'optout' => (int)($c['optout'] ?? 0) === 1, 'complaint' => in_array('complaint', $fl, true)];
    return ['phone' => $phone, 'first' => $first, 'stage' => (string)$s['stage'], 'stage_label' => $si[$s['stage']][0] ?? $s['stage'], 'stage_help' => $si[$s['stage']][1] ?? '', 'value_tier' => (string)$s['value_tier'], 'value_label' => $vi[$s['value_tier']][0] ?? $s['value_tier'],
        'spent' => (float)$s['spent'], 'orders' => (int)$s['orders'], 'gap' => (int)$s['longest_gap_days'], 'woke_at' => $s['woke_at'] ? (string)$s['woke_at'] : null, 'interest_trend' => (string)$s['interest_trend'], 'last_in_at' => $s['last_in_at'] ? (string)$s['last_in_at'] : null,
        'timeline' => $tl, 'facts' => $facts, 'updated_at' => (string)$s['updated_at']];
}

// ------------------------------------------------------------------------------------------------------------------ the AI (one door, so tests can replace it)
/** One AI call that answers JSON. Returns [ok, value|null, why]. A test defines wa_seg_ai_hook() to answer instead (nothing leaves the server). */
function wsg_ai(string $sys, string $text, array $schema, int $maxOut = 2500): array
{
    if (function_exists('wa_seg_ai_hook')) return wa_seg_ai_hook($sys, $text, $schema, $maxOut);
    if (!function_exists('convo_ai')) return [false, null, 'The AI is not available.'];
    try { $r = convo_ai($sys, [['text' => $text]], $schema, false, $maxOut, 'claude'); return [(bool)$r[0], $r[1] ?? null, (string)($r[2] ?? '')]; } catch (Throwable $e) { return [false, null, mb_substr($e->getMessage(), 0, 200)]; }
}

// ------------------------------------------------------------------------------------------------------------------ decisions (the owner's durable rules)
function wa_decisions_text(PDO $pdo, string $scope = 'all'): string
{
    if (!wsg_tables($pdo)) return ''; $scope = in_array($scope, ['all', 'campaign', 'template'], true) ? $scope : 'all';
    $q = $pdo->prepare("SELECT dkey, text, scope FROM wa_decisions WHERE active = 1 AND scope IN ('all', ?) ORDER BY scope = 'all' DESC, id"); $q->execute([$scope]); $o = '';
    foreach ($q->fetchAll() as $r) $o .= '- ' . ($r['scope'] !== 'all' ? '[' . $r['scope'] . '] ' : '') . $r['text'] . "\n";
    return $o;
}
function wsg_dec_clean(array $in): array
{
    $k = trim(preg_replace('/\s+/u', ' ', (string)($in['key'] ?? '')) ?? ''); $t = trim(preg_replace('/\s+/u', ' ', (string)($in['text'] ?? '')) ?? ''); $sc = (string)($in['scope'] ?? 'all');
    if (!in_array($sc, ['all', 'campaign', 'template'], true)) $sc = 'all'; if ($k === '') $k = mb_substr($t, 0, 40);
    return ['key' => mb_substr($k, 0, 60), 'text' => mb_substr($t, 0, 500), 'scope' => $sc];
}
/** Asks Claude to pull DURABLE rules out of something the owner said ("never message before 11 am"). Returns proposals; it never saves. */
function wa_dec_extract(PDO $pdo, string $ownerText): array
{
    $t = trim(mb_substr($ownerText, 0, 1500)); if (mb_strlen($t) < 4) return ['ok' => false, 'proposals' => [], 'source' => 'none', 'error' => 'Write a sentence first.'];
    $sch = ['type' => 'OBJECT', 'properties' => ['proposals' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['key' => ['type' => 'STRING'], 'text' => ['type' => 'STRING'], 'scope' => ['type' => 'STRING', 'enum' => ['all', 'campaign', 'template']]], 'required' => ['key', 'text', 'scope']]]], 'required' => ['proposals']];
    $sys = "You read what the owner of a perfume shop said and pull out DURABLE business rules that should be remembered for every future WhatsApp campaign or message (for example: never message before 11 am, never offer more than 10% off, do not message customers who complained). " .
        "Ignore one-off requests, questions and opinions. key = 2 to 5 words. text = one clear sentence in plain English, the rule only. scope = campaign (about campaigns / when and who to message), template (about message wording) or all. At most 5 rules. Return an empty list when there is no durable rule.";
    [$ok, $v, $why] = wsg_ai($sys, wsg_redact($t), $sch, 800); $out = [];
    if ($ok && is_array($v)) { foreach ((array)($v['proposals'] ?? []) as $p) { if (!is_array($p)) continue; $c = wsg_dec_clean($p); if (mb_strlen($c['text']) >= 6) $out[] = $c; if (count($out) >= 5) break; } return ['ok' => true, 'proposals' => $out, 'source' => 'ai']; }
    // the AI is not available: the plainest rules ("never / always / do not / don't ..." sentences) are still offered
    foreach (preg_split('/(?<=[.!?\n])\s+/u', $t) ?: [] as $s) { $s = trim($s); if (preg_match('/^(never|always|do not|don\'t|dont|only|no more|stop|must)\b/iu', $s) || preg_match('/\b(never|always|must not|do not|don\'t)\b/iu', $s)) { $c = wsg_dec_clean(['text' => $s, 'scope' => 'all']); if (mb_strlen($c['text']) >= 6) $out[] = $c; } if (count($out) >= 5) break; }
    return ['ok' => true, 'proposals' => $out, 'source' => 'rules', 'note' => 'The AI could not be reached' . ($why !== '' ? ' (' . mb_substr($why, 0, 100) . ')' : '') . ', so only plain "never / always" sentences were picked.'];
}

// ------------------------------------------------------------------------------------------------------------------ past campaigns (summary kept in settings, rewritten by the tick)
/** Numbers of the last campaigns. Replies = wrote within 2 days after the message (same rule as the campaign result screen); orders = the customer's latest order is within 7 days after the message. */
function wsg_camp_build(PDO $pdo, int $n = 12): array
{
    $out = []; $now = wsg_now(); $cfg = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : ['rate_marketing' => 1.09];
    try { $cs = $pdo->query("SELECT id, name, tpl_id, status, audience, started_at, finished_at, created_at FROM wa_campaigns ORDER BY id DESC LIMIT " . (int)$n)->fetchAll(); } catch (Throwable $e) { return []; }
    $old = []; foreach (wsg_json($pdo, 'wa_seg_camps')['items'] ?? [] as $x) $old[(int)$x['id']] = $x;
    foreach ($cs as $c) {
        $id = (int)$c['id']; if (isset($old[$id]) && !empty($old[$id]['frozen'])) { $out[] = $old[$id]; continue; }
        $q = $pdo->prepare("SELECT status, last_status, variant, COUNT(*) n FROM wa_camp_items WHERE camp_id = ? GROUP BY status, last_status, variant"); $q->execute([$id]); $tot = 0; $sent = 0; $del = 0; $rd = 0; $fail = 0; $var = [];
        foreach ($q->fetchAll() as $r) {
            $k = (int)$r['n']; $tot += $k; $v = (string)$r['variant']; if (!isset($var[$v])) $var[$v] = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0];
            if ($r['status'] === 'sent') { $sent += $k; $var[$v]['sent'] += $k; if (in_array($r['last_status'], ['delivered', 'read'], true)) { $del += $k; $var[$v]['delivered'] += $k; } if ($r['last_status'] === 'read') { $rd += $k; $var[$v]['read'] += $k; } }
            elseif ($r['status'] === 'failed') $fail += $k;
        }
        $rep = 0; $ord = 0;
        if ($sent > 0) {
            $q = $pdo->prepare("SELECT i.variant, COUNT(DISTINCT i.phone) FROM wa_camp_items i WHERE i.camp_id = ? AND i.status = 'sent' AND i.sent_at IS NOT NULL AND EXISTS (SELECT 1 FROM wa_messages m WHERE m.phone = i.phone AND m.dir = 'in' AND m.ts > i.sent_at AND m.ts <= DATE_ADD(i.sent_at, INTERVAL 2 DAY)) GROUP BY i.variant"); $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$v, $k]) { $rep += (int)$k; if (isset($var[$v])) $var[$v]['replied'] = (int)$k; }
            try { $q = $pdo->prepare("SELECT COUNT(DISTINCT i.phone) FROM wa_camp_items i JOIN wa_cust s ON s.phone = i.phone WHERE i.camp_id = ? AND i.status = 'sent' AND i.sent_at IS NOT NULL AND s.last_order > i.sent_at AND s.last_order <= DATE_ADD(i.sent_at, INTERVAL 7 DAY)"); $q->execute([$id]); $ord = (int)$q->fetchColumn(); } catch (Throwable $e) { }
        }
        $tpl = ['name' => '', 'category' => '', 'body' => '']; if ((int)$c['tpl_id'] > 0) { try { $q = $pdo->prepare("SELECT name, category, body FROM wa_templates WHERE id = ?"); $q->execute([(int)$c['tpl_id']]); $r = $q->fetch(); if ($r) $tpl = ['name' => (string)$r['name'], 'category' => (string)$r['category'], 'body' => wsg_short(wsg_redact((string)$r['body']), 220)]; } catch (Throwable $e) { } }
        $aud = json_decode((string)$c['audience'], true); $audT = is_array($aud) ? implode(', ', array_slice(array_map(fn($k, $v) => $k . '=' . (is_array($v) ? implode('/', array_slice($v, 0, 4)) : (string)$v), array_keys($aud), $aud), 0, 8)) : '';
        $fin = $c['finished_at'] ? strtotime((string)$c['finished_at']) : null;
        $out[] = ['id' => $id, 'name' => (string)$c['name'], 'status' => (string)$c['status'], 'started_at' => (string)$c['started_at'], 'finished_at' => (string)$c['finished_at'], 'audience' => wsg_short($audT, 160), 'template' => $tpl,
            'total' => $tot, 'sent' => $sent, 'delivered' => $del, 'read' => $rd, 'failed' => $fail, 'replied' => $rep, 'orders' => $ord, 'cost' => round($del * (float)($cfg['rate_marketing'] ?? 1.09), 2),
            'variants' => count($var) > 1 ? $var : [], 'frozen' => $fin !== null && $fin < $now - 8 * 86400];
    }
    wsg_put($pdo, 'wa_seg_camps', json_encode(['at' => $now, 'items' => $out], JSON_UNESCAPED_UNICODE)); return $out;
}

// ------------------------------------------------------------------------------------------------------------------ chat digest (daily)
/** The customer messages the digest is made from (a SAMPLE, never everything): 300 recent, 100 replies after a campaign, 100 messages before an order. All redacted. */
function wsg_digest_sample(PDO $pdo, int $now): array
{
    $A = []; $B = []; $C = []; $seen = [];
    $q = $pdo->prepare("SELECT phone, LEFT(body, 200) AS b, ts FROM wa_messages WHERE ts >= ? AND dir = 'in' AND mtype = 'text' ORDER BY ts DESC LIMIT 2500"); $q->execute([wsg_dt($now - 14 * 86400)]);
    foreach ($q->fetchAll() as $r) {
        $b = trim((string)$r['b']); if (mb_strlen($b) < 3 || preg_match('/^\W*(ok|okay|thanks|thank you|hi|hello|hii+|yes|no)\W*$/iu', $b)) continue;
        $p = (string)$r['phone']; $seen[$p] = ($seen[$p] ?? 0) + 1; if ($seen[$p] > 2) continue; if (count($A) < 300) $A[] = wsg_short(wsg_redact($b), 160);
    }
    // replies after a campaign: the last campaigns' customers who wrote within 3 days
    try {
        $ids = $pdo->query("SELECT id FROM wa_campaigns ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $q = $pdo->prepare("SELECT phone, sent_at FROM wa_camp_items WHERE camp_id IN (" . wsg_in($ids) . ") AND status = 'sent' AND sent_at IS NOT NULL ORDER BY id DESC LIMIT 1500"); $q->execute($ids); $it = $q->fetchAll();
            $ph = array_values(array_unique(array_map(fn($r) => (string)$r['phone'], $it))); $li = [];
            foreach (array_chunk($ph, 500) as $ch) { $q = $pdo->prepare("SELECT phone, last_in_at FROM wa_contacts WHERE phone IN (" . wsg_in($ch) . ") AND last_in_at IS NOT NULL"); $q->execute($ch); foreach ($q->fetchAll() as $r) $li[(string)$r['phone']] = strtotime((string)$r['last_in_at']); }
            $mq = $pdo->prepare("SELECT LEFT(body, 200) FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' AND ts > ? ORDER BY id LIMIT 1"); $done = [];
            foreach ($it as $r) { $p = (string)$r['phone']; if (count($B) >= 100) break; if (isset($done[$p]) || empty($li[$p])) continue; $st = strtotime((string)$r['sent_at']); if ($li[$p] <= $st || $li[$p] > $st + 3 * 86400) continue; $done[$p] = 1; $mq->execute([$p, $r['sent_at']]); $b = trim((string)$mq->fetchColumn()); if (mb_strlen($b) >= 2) $B[] = wsg_short(wsg_redact($b), 160); }
        }
    } catch (Throwable $e) { }
    // messages before an order: buyers of the last 14 days, their last message before the order
    try {
        $q = $pdo->prepare("SELECT phone, last_order FROM wa_cust WHERE last_order >= ? AND n > 0 ORDER BY last_order DESC LIMIT 400"); $q->execute([wsg_dt($now - 14 * 86400)]); $mq = $pdo->prepare("SELECT LEFT(body, 200) FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' AND ts <= ? ORDER BY id DESC LIMIT 1");
        foreach ($q->fetchAll() as $r) { if (count($C) >= 100) break; $mq->execute([$r['phone'], $r['last_order']]); $b = trim((string)$mq->fetchColumn()); if (mb_strlen($b) >= 3) $C[] = wsg_short(wsg_redact($b), 160); }
    } catch (Throwable $e) { }
    return ['recent' => $A, 'campaign_replies' => $B, 'before_order' => $C];
}
/** Counts without the AI: what customers ask about, in plain numbers, with one example each. */
function wsg_digest_rules(array $S): string
{
    $topics = ['Price / rate' => '/\b(price|rate|cost|kitna|kimat|kimmat|bhav|how much)\b|कीमत|कितना|ભાવ|કિંમત/iu', 'Available / stock' => '/\b(available|availability|stock|hai kya|milega|mil jayega)\b/iu', 'Delivery / order status' => '/\b(deliver|delivery|dispatch|tracking|track|shipped|courier|kab aayega|when will)\b/iu',
        'Cash on delivery / payment' => '/\b(cod|cash on delivery|payment|upi|pay|advance|link)\b/iu', 'Discount / offer' => '/\b(discount|offer|coupon|deal|combo|free)\b/iu', 'Photos / details' => '/\b(photo|pic|image|video|details|catalog|catalogue|list)\b/iu',
        'Smell / longevity' => '/\b(smell|fragrance|long lasting|longevity|projection|stays|notes|strong)\b/iu', 'Original / quality' => '/\b(original|genuine|fake|real|quality|batch|authentic)\b/iu', 'Return / complaint' => '/\b(return|refund|exchange|replace|damaged|wrong|complaint|not received)\b/iu', 'Gift / occasion' => '/\b(gift|wedding|birthday|anniversary|occasion|party)\b/iu'];
    $o = ''; $n = count($S['recent']);
    $res = []; foreach ($topics as $name => $re) { $c = 0; $ex = ''; foreach ($S['recent'] as $m) if (preg_match($re, $m)) { $c++; if ($ex === '') $ex = $m; } if ($c > 0) $res[] = [$c, $name, $ex]; }
    usort($res, fn($a, $b) => $b[0] <=> $a[0]);
    $o .= "What customers write about (count rules on a sample of $n recent customer messages; the AI digest was not available):\n"; foreach (array_slice($res, 0, 8) as [$c, $nm, $ex]) $o .= "- $nm: $c" . ($ex !== '' ? ' (e.g. "' . wsg_short($ex, 90) . '")' : '') . "\n";
    $o .= 'Replies that came after a campaign (' . count($S['campaign_replies']) . "):\n"; foreach (array_slice($S['campaign_replies'], 0, 5) as $m) $o .= '- "' . wsg_short($m, 90) . "\"\n";
    $o .= 'Last message before an order (' . count($S['before_order']) . "):\n"; foreach (array_slice($S['before_order'], 0, 5) as $m) $o .= '- "' . wsg_short($m, 90) . "\"\n";
    return $o;
}
/** Makes today's digest: Claude when it answers, the plain counts when it does not. Returns ['ok','source','chars','sample']. */
function wsg_digest_make(PDO $pdo, int $now): array
{
    $S = wsg_digest_sample($pdo, $now); $n = count($S['recent']) + count($S['campaign_replies']) + count($S['before_order']);
    $sch = ['type' => 'OBJECT', 'properties' => ['what_they_ask' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'objections' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'led_to_orders' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'campaign_replies' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'wording_that_works' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'languages' => ['type' => 'STRING']], 'required' => ['what_they_ask', 'objections', 'led_to_orders', 'campaign_replies', 'wording_that_works']];
    $sys = "You are the analyst of a perfume shop (TM Perfume House, India) that sells on WhatsApp. You get SAMPLES of real customer messages (numbers and e-mails are already removed). Write a short digest for the shop's own marketing planner: " .
        "what customers ask most (with rough counts in words like 'about 60 of 300'), the common objections or doubts, what customers wrote just before they ordered, what customers answered after a campaign message, and which kinds of wording seem to get answers. " .
        "Plain English, one short line per item, at most 8 items per list, no customer names, never invent numbers you cannot see in the samples. Mention if the customers write in Hindi, Gujarati or Hinglish.";
    $txt = "RECENT CUSTOMER MESSAGES (" . count($S['recent']) . "):\n- " . implode("\n- ", $S['recent']) . "\n\nREPLIES AFTER A CAMPAIGN (" . count($S['campaign_replies']) . "):\n- " . implode("\n- ", $S['campaign_replies']) . "\n\nLAST MESSAGE BEFORE AN ORDER (" . count($S['before_order']) . "):\n- " . implode("\n- ", $S['before_order']);
    $src = 'rules'; $text = ''; $why = '';
    if ($n >= 10) {
        [$ok, $v, $why] = wsg_ai($sys, $txt, $sch, 2500);
        if ($ok && is_array($v)) {
            $L = function (string $t, $a) { $a = array_values(array_filter(array_map(fn($x) => wsg_short((string)$x, 160), (array)$a), fn($x) => $x !== '')); return $a ? $t . ":\n- " . implode("\n- ", array_slice($a, 0, 8)) . "\n" : ''; };
            $text = $L('What customers ask', $v['what_they_ask'] ?? []) . $L('Doubts and objections', $v['objections'] ?? []) . $L('What they wrote just before ordering', $v['led_to_orders'] ?? []) . $L('What they answered after campaigns', $v['campaign_replies'] ?? []) . $L('Wording that gets answers', $v['wording_that_works'] ?? []) . (trim((string)($v['languages'] ?? '')) !== '' ? 'Languages: ' . wsg_short((string)$v['languages'], 160) . "\n" : '');
            if (mb_strlen(trim($text)) > 40) $src = 'ai'; else $text = '';
        }
    }
    if ($text === '') $text = $n > 0 ? wsg_digest_rules($S) : "No customer messages in the last 14 days to learn from yet.\n";
    $text = mb_substr($text, 0, 6000);
    $day = date('Y-m-d', $now); $pdo->prepare("REPLACE INTO wa_chat_digest (day, text, source, sample_n, sample_json, created_at) VALUES (?, ?, ?, ?, NULL, ?)")->execute([$day, $text, $src, $n, wsg_dt($now)]);
    $pdo->exec("DELETE FROM wa_chat_digest WHERE day < DATE_SUB(CURDATE(), INTERVAL 60 DAY)");
    return ['ok' => true, 'source' => $src, 'chars' => mb_strlen($text), 'sample' => ['recent' => count($S['recent']), 'campaign_replies' => count($S['campaign_replies']), 'before_order' => count($S['before_order'])], 'ai_note' => $src === 'rules' ? $why : ''];
}

// ------------------------------------------------------------------------------------------------------------------ the Claude context pack
/** Characters each part may use, per purpose. The sum of a row stays under the hard cap (20,000 characters = about 6,000 tokens). */
function wsg_pack_budget(): array
{
    return [
        'campaign_plan' => ['business' => 5000, 'segments' => 1800, 'campaigns' => 4500, 'digest' => 4500, 'now' => 1500, 'decisions' => 2200],
        'campaign_ask' => ['business' => 3000, 'segments' => 1500, 'campaigns' => 2200, 'digest' => 2500, 'now' => 1200, 'decisions' => 1500],
        'message_write' => ['business' => 5000, 'campaigns' => 2500, 'digest' => 3500, 'decisions' => 2000],
        'report' => ['business' => 1500, 'segments' => 2000, 'campaigns' => 6000, 'digest' => 2500, 'now' => 1800, 'decisions' => 1500],
    ];
}
/** Cuts a text at a line boundary so it fits $max characters. */
function wsg_fit(string $s, int $max): string
{
    $s = trim($s); if (mb_strlen($s) <= $max) return $s; $out = ''; foreach (preg_split('/\n/', $s) as $ln) { if (mb_strlen($out) + mb_strlen($ln) + 1 > $max - 12) break; $out .= $ln . "\n"; }
    return rtrim($out) . "\n(cut)";
}
function wsg_pack_business(PDO $pdo, int $max, array &$used): string
{
    $o = ''; $teach = 0;
    if (function_exists('teach_facts_prompt')) {
        try {
            [$t] = teach_facts_prompt($pdo, []); $t = preg_replace('/^\(No catalogue product matches[^\n]*\n?/m', '', (string)$t) ?? ''; $t = trim($t);
            if ($t !== '') { $o .= $t . "\n"; if (function_exists('teach_limits_active')) { $A = teach_limits_active($pdo); $teach += count($A['rows'] ?? []) + count($A['gen'] ?? []) + count($A['rules'] ?? []); } if (function_exists('teach_facts_get')) { $F = teach_facts_get($pdo); $teach += count(array_filter(preg_split('/\n/', (string)($F['style'] ?? '')), fn($x) => trim($x) !== '')); } }
        } catch (Throwable $e) { }
    } elseif (function_exists('teach_limits_text')) { try { $t = trim((string)teach_limits_text($pdo)); if ($t !== '') { $o .= $t . "\n"; $teach += substr_count($t, "\n- "); } } catch (Throwable $e) { } }
    if ($teach > 0) $used[] = 'Teach rules and limits: ' . $teach;
    if (function_exists('wa_store_text')) { try { $s = trim((string)wa_store_text($pdo, 'all')); if ($s !== '') { $o .= "STORE INFO:\n" . $s . "\n"; $used[] = 'store info'; } } catch (Throwable $e) { } }
    try {
        $r = $pdo->query("SELECT name, kind, price_text, units_sold FROM wa_prod WHERE active = 1 AND units_sold > 0 ORDER BY units_sold DESC LIMIT 12")->fetchAll();
        if ($r) { $o .= "TOP SELLERS (units sold on the website):\n"; foreach ($r as $x) $o .= '- ' . $x['name'] . ' (' . $x['kind'] . ($x['price_text'] !== '' ? ', ' . $x['price_text'] : '') . '): ' . wsg_n($x['units_sold']) . "\n"; $used[] = 'top ' . count($r) . ' sellers'; }
    } catch (Throwable $e) { }
    return wsg_fit($o, $max);
}
function wsg_pack_segments(PDO $pdo, int $max, array &$used): string
{
    $mx = wsg_json($pdo, 'wa_seg_matrix'); if (!$mx || empty($mx['matrix'])) return ''; $cfg = wsg_cfg($pdo); $si = wsg_stage_info($cfg); $o = "CUSTOMER GROUPS (" . wsg_n($mx['total']) . " customers; stage by value tier; as of " . wsg_dt((int)$mx['at']) . "):\n";
    foreach ($si as $k => [$l, $d]) { $row = $mx['matrix'][$k] ?? []; $n = array_sum($row); if ($n <= 0) continue; $v = []; foreach (['vip', 'high', 'mid', 'low', 'not_bought'] as $t) if (!empty($row[$t])) $v[] = $t . ' ' . wsg_n($row[$t]); $o .= '- ' . $l . ' (' . $d . '): ' . wsg_n($n) . ' [' . implode(', ', $v) . "]\n"; }
    $cu = wsg_json($pdo, 'wa_seg_cuts'); if (!empty($cu['vip'])) $o .= 'Value tiers by money spent: VIP from Rs ' . wsg_n($cu['vip']) . ', High from Rs ' . wsg_n($cu['high']) . ', Mid from Rs ' . wsg_n($cu['mid']) . ' (' . wsg_n($cu['n']) . " buyers).\n";
    if (!empty($mx['lang'])) { arsort($mx['lang']); $o .= 'Language of customers: ' . implode(', ', array_map(fn($k, $v) => "$k " . wsg_n($v), array_keys($mx['lang']), $mx['lang'])) . "\n"; }
    if (!empty($mx['flags'])) $o .= 'Behaviour: ' . implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . wsg_n($v), array_keys($mx['flags']), $mx['flags'])) . "\n";
    $used[] = 'customer groups (' . wsg_n($mx['total']) . ' customers)'; return wsg_fit($o, $max);
}
function wsg_pack_campaigns(PDO $pdo, int $max, int $n, array &$used): string
{
    $c = wsg_json($pdo, 'wa_seg_camps'); $it = array_slice($c['items'] ?? [], 0, $n); if (!$it) return '';
    $o = "PAST CAMPAIGNS (newest first; replied = wrote within 2 days; orders = ordered within 7 days):\n"; $rep = 0; $kept = 0;
    foreach ($it as $x) {
        $e = '- #' . $x['id'] . ' ' . $x['name'] . ' [' . $x['status'] . ', ' . substr((string)($x['started_at'] ?: ''), 0, 10) . ']: sent ' . wsg_n($x['sent']) . ', delivered ' . wsg_n($x['delivered']) . ', read ' . wsg_n($x['read']) . ', replied ' . wsg_n($x['replied']) . ', orders ' . wsg_n($x['orders']) . ', cost Rs ' . wsg_n($x['cost'])
            . ($x['audience'] !== '' ? '; audience ' . $x['audience'] : '') . (!empty($x['template']['body']) ? '; wording "' . $x['template']['body'] . '"' : '') . "\n";
        if (!empty($x['variants'])) foreach ($x['variants'] as $v => $s2) $e .= '    variant ' . $v . ': sent ' . wsg_n($s2['sent']) . ', read ' . wsg_n($s2['read']) . ', replied ' . wsg_n($s2['replied']) . "\n";
        if (mb_strlen($o) + mb_strlen($e) > $max - 40) break;   // a campaign is kept whole or not at all
        $o .= $e; $kept++; $rep += (int)$x['replied'];
    }
    if ($kept === 0) return '';
    if ($kept < count($it)) $o .= '(' . (count($it) - $kept) . " older campaigns left out to keep this short)\n";
    $used[] = 'last ' . $kept . ' campaigns' . ($kept < count($it) ? ' (of ' . count($it) . ' kept in the summary)' : ''); if ($rep > 0) $used[] = wsg_n($rep) . ' replies counted'; return trim($o);
}
function wsg_pack_digest(PDO $pdo, int $max, array &$used): string
{
    try { $r = $pdo->query("SELECT day, text, source, sample_n FROM wa_chat_digest ORDER BY day DESC LIMIT 1")->fetch(); } catch (Throwable $e) { return ''; } if (!$r || trim((string)$r['text']) === '') return '';
    $used[] = 'chat digest of ' . $r['day'] . ' (' . ($r['source'] === 'ai' ? 'written by Claude' : 'plain counts') . ', ' . wsg_n($r['sample_n']) . ' messages sampled)';
    return wsg_fit("WHAT CUSTOMERS SAY (daily digest of {$r['day']}):\n" . $r['text'], $max);
}
function wsg_pack_now(PDO $pdo, int $max, array &$used): string
{
    $o = '';
    $h = wsg_json($pdo, 'wa_health'); $caps = wsg_json($pdo, 'wa_seg_caps'); $cp = $caps['caps'] ?? [];
    if ($h) {
        $o .= "NUMBERS (last saved health, no live check):\n"; $k = 0;
        foreach ($h as $id => $x) { if (!is_array($x)) continue; $k++; $c = $cp[$id] ?? null; $o .= '- ' . ($x['label'] ?? ('Number ' . $id)) . ': ' . ($x['level'] ?? '?') . ', quality ' . ($x['quality'] ?? '?') . ($c ? ', room today ' . wsg_n($c['left'] ?? 0) . ' of ' . wsg_n($c['cap'] ?? 0) . (($c['why'] ?? '') !== '' ? ' (' . $c['why'] . ')' : '') : '') . "\n"; }
        if ($k) $used[] = 'number health (' . $k . ')';
    }
    try { $r = $pdo->query("SELECT status, COUNT(*) n FROM wa_tpl_waba GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR); if ($r) { $o .= 'TEMPLATES at Meta: ' . implode(', ', array_map(fn($k, $v) => strtolower($k) . ' ' . $v, array_keys($r), $r)) . "\n"; $used[] = 'template approvals'; } } catch (Throwable $e) { }
    try {
        $r = $pdo->prepare("SELECT id, name, status, start_at FROM wa_campaigns WHERE status IN ('running', 'scheduled', 'paused') OR (started_at >= ?) ORDER BY id DESC LIMIT 6"); $r->execute([wsg_dt(wsg_now() - 7 * 86400)]); $rows = $r->fetchAll();
        if ($rows) { $o .= "CAMPAIGNS THIS WEEK:\n"; foreach ($rows as $x) $o .= '- #' . $x['id'] . ' ' . $x['name'] . ' (' . $x['status'] . ')' . "\n"; $used[] = 'campaigns running this week (' . count($rows) . ')'; }
    } catch (Throwable $e) { }
    return wsg_fit($o, $max);
}
/**
 * The pack of facts handed to the in-app Claude. Built ONLY from settings, small tables and the summary tables (no scans, no Meta, no AI).
 * Returns ['text' => string (<= 20,000 characters), 'used' => human lines, 'parts' => [{key,label,chars,budget,included}], 'chars' => int, 'purpose'].
 * Opts: max_chars (lower cap), campaigns (how many past campaigns), skip (list of part keys to leave out).
 */
function wa_ctx_pack(PDO $pdo, string $purpose, array $opts = []): array
{
    wsg_tables($pdo); $B = wsg_pack_budget(); if (!isset($B[$purpose])) $purpose = 'campaign_plan'; $bud = $B[$purpose]; $cap = max(1000, min(20000, (int)($opts['max_chars'] ?? 20000))); $skip = (array)($opts['skip'] ?? []);
    $labels = ['business' => 'Business facts (Teach rules, limits, store info, top sellers)', 'segments' => 'Customer groups (stage x value, language)', 'campaigns' => 'Past campaigns', 'digest' => 'Chat digest (what customers say)', 'now' => 'Right now (numbers, templates, this week)', 'decisions' => "Owner's decisions"];
    $head = ['business' => '== BUSINESS FACTS ==', 'segments' => '== CUSTOMER GROUPS ==', 'campaigns' => '== PAST CAMPAIGNS ==', 'digest' => '== WHAT CUSTOMERS SAY ==', 'now' => '== RIGHT NOW ==', 'decisions' => "== THE OWNER'S DECISIONS (always obey) =="];
    $nC = (int)($opts['campaigns'] ?? ($purpose === 'report' ? 12 : ($purpose === 'campaign_plan' ? 8 : 5))); $parts = []; $used = []; $text = [];
    $scope = $purpose === 'message_write' ? 'template' : (in_array($purpose, ['campaign_plan', 'campaign_ask'], true) ? 'campaign' : 'all');
    foreach ($bud as $key => $max) {
        $u = []; $s = '';
        if (!in_array($key, $skip, true)) {
            try {
                switch ($key) {
                    case 'business': $s = wsg_pack_business($pdo, $max, $u); break;
                    case 'segments': $s = wsg_pack_segments($pdo, $max, $u); break;
                    case 'campaigns': $s = wsg_pack_campaigns($pdo, $max, $nC, $u); break;
                    case 'digest': $s = wsg_pack_digest($pdo, $max, $u); break;
                    case 'now': $s = wsg_pack_now($pdo, $max, $u); break;
                    case 'decisions': $s = wsg_fit(wa_decisions_text($pdo, $scope), $max); $n = $s === '' ? 0 : substr_count($s, "\n- ") + 1; if ($n > 0) $u[] = "owner's decisions: " . $n; break;
                }
            } catch (Throwable $e) { $s = ''; $u = []; }
        }
        $s = trim($s); if ($s !== '' && substr($s, -5) === '(cut)') $u = array_map(fn($x) => $x . ' (shortened)', $u);   // be honest: the part did not fit whole
        if ($s !== '') { $block = $head[$key] . "\n" . $s; $text[] = $block; foreach ($u as $x) $used[] = $x; $parts[] = ['key' => $key, 'label' => $labels[$key], 'chars' => mb_strlen($block), 'budget' => $max, 'included' => true]; }
        else $parts[] = ['key' => $key, 'label' => $labels[$key], 'chars' => 0, 'budget' => $max, 'included' => false];
    }
    $all = implode("\n\n", $text); if (mb_strlen($all) > $cap) $all = wsg_fit($all, $cap);   // hard cap
    return ['text' => $all, 'used' => $used, 'parts' => $parts, 'chars' => mb_strlen($all), 'purpose' => $purpose, 'tokens_estimate' => (int)ceil(mb_strlen($all) / 3.5)];
}

// ------------------------------------------------------------------------------------------------------------------ the tick
/** Number caps for the pack, from the saved health (no Meta call), every 15 minutes. */
function wsg_caps_refresh(PDO $pdo, int $now): void
{
    $c = wsg_json($pdo, 'wa_seg_caps'); if ($c && (int)($c['at'] ?? 0) > $now - 900) return; if (!function_exists('wa_number_caps')) return;
    $h = wsg_json($pdo, 'wa_health'); if (!$h) return;
    try { $caps = wa_number_caps($pdo, array_values(array_filter($h, 'is_array'))); wsg_put($pdo, 'wa_seg_caps', json_encode(['at' => $now, 'caps' => $caps])); } catch (Throwable $e) { }
}
/**
 * The background job: customer story in chunks, the daily digest, the past-campaign summary. Idempotent, never throws, stops after about 8 seconds.
 * Call it from the cron branch of wa_more.php (opts inline = true) or from wa_tick (then it waits until the page has been sent).
 * Opts: inline (run now), budget (seconds, default 8), chunk (customers per call, default 4000), digest (false to skip), now (clock for tests).
 */
function wa_seg_tick(PDO $pdo, array $opts = []): array
{
    $o = ['ran' => false];
    try {
        if (empty($opts['inline']) && PHP_SAPI !== 'cli') {   // a web request must not wait for us
            register_shutdown_function(function () use ($pdo) { if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); try { wa_seg_tick($pdo, ['inline' => true]); } catch (Throwable $e) { } }); return ['deferred' => true];
        }
        if (!wsg_tables($pdo)) return ['error' => 'tables'];
        $got = (int)$pdo->query("SELECT GET_LOCK('wa_seg_tick', 0)")->fetchColumn(); if ($got !== 1) return ['skipped' => 'busy'];
        try {
            $t0 = microtime(true); $deadline = $t0 + (float)($opts['budget'] ?? 8); @set_time_limit(max(30, (int)($opts['budget'] ?? 8) + 25)); $now = wsg_now(); $o['ran'] = true; $cyc = wsg_cycle($pdo);
            $hr = (int)date('G', $now); $nightly = $hr >= 1 && $hr < 7;
            $due = $cyc['finished'] > 0 ? ($cyc['finished'] < $now - 20 * 3600 && ($nightly || $cyc['finished'] < $now - 36 * 3600)) : true;
            $digWant = ($opts['digest'] ?? true) !== false; $digForce = !empty($opts['digest_force']);
            $digDue = $digForce || ($digWant && (int)wsg_get($pdo, 'wa_seg_digest_try', '0') < $now - 3 * 3600 && ($hr >= 2 || $cyc['finished'] === 0) && !$pdo->query("SELECT 1 FROM wa_chat_digest WHERE day = '" . date('Y-m-d', $now) . "'")->fetchColumn());
            if ($digDue && ($cyc['state'] !== 'running' || $digForce)) {   // the AI step gets a call of its own
                wsg_put($pdo, 'wa_seg_digest_try', (string)$now); @set_time_limit(120); $o['digest'] = wsg_digest_make($pdo, $now); wsg_log($pdo, true, 'Chat digest made (' . $o['digest']['source'] . ', ' . ($o['digest']['chars'] ?? 0) . ' characters).');
            } elseif ($cyc['state'] === 'idle' && $due) {
                $cyc = wsg_cycle_start($pdo); $o['started'] = true;   // the order summary + cut-offs take this call; the chunks follow in the next calls
            } elseif ($cyc['state'] === 'running') {
                $cyc = wsg_chunk($pdo, (int)($opts['chunk'] ?? 4000), $deadline, $cyc); $o['built'] = $cyc['built'] ?? 0; $o['state'] = $cyc['state'];
            } elseif ((int)wsg_get($pdo, 'wa_seg_catchup', '0') < $now - 600) {   // idle: customers added since the last build (imports) get their row
                wsg_put($pdo, 'wa_seg_catchup', (string)$now);
                $q = $pdo->query("SELECT c.phone, c.name, c.first_seen, c.last_in_at, c.last_out_at, c.last_number_id, c.optout, c.source, c.tag, c.cx_id FROM wa_contacts c LEFT JOIN wa_cust_story st ON st.phone = c.phone WHERE st.phone IS NULL LIMIT 500"); $cs = $q->fetchAll();
                if ($cs) { [$rows, $sess] = wsg_build_rows($pdo, $cs, $now); wsg_save($pdo, $rows, $sess); } $o['catchup'] = count($cs);
            }
            if ((int)wsg_get($pdo, 'wa_seg_optsync', '0') < $now - 300) { wsg_put($pdo, 'wa_seg_optsync', (string)$now); $o['optout_fixed'] = wsg_optout_sync($pdo); }
            if (microtime(true) < $deadline - 1.5) {
                $cc = wsg_json($pdo, 'wa_seg_camps'); if (!$cc || (int)($cc['at'] ?? 0) < $now - 1800) { wsg_camp_build($pdo, 14); $o['campaigns'] = true; }
                wsg_caps_refresh($pdo, $now);
            }
            $o['ms'] = (int)round((microtime(true) - $t0) * 1000);
        } finally { try { $pdo->query("SELECT RELEASE_LOCK('wa_seg_tick')"); } catch (Throwable $e) { } }
    } catch (Throwable $e) { wsg_log($pdo, false, 'segments tick: ' . mb_substr($e->getMessage(), 0, 200)); $o['error'] = mb_substr($e->getMessage(), 0, 120); }
    return $o;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router
$__wsa = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wsa, ['wa.seg_count', 'wa.seg_stages', 'wa.seg_hist', 'wa.seg_customer', 'wa.seg_settings', 'wa.seg_build', 'wa.ctx_preview', 'wa.dec_list', 'wa.dec_save', 'wa.dec_del', 'wa.dec_extract'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !wsg_tables($pdo)) json_response(['error' => 'Storage is not available — check database permissions.'], 500);
    if (function_exists('wa_more_tables')) { try { wa_more_tables($pdo); } catch (Throwable $e) { } }
    $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth); $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needAdmin = function () use ($pdo, $auth) { wa_guard_admin($pdo, $auth); };
    switch ($__wsa) {
        case 'wa.seg_count': { $needPost(); json_response(wa_seg_count($pdo, is_array($in['filters'] ?? null) ? $in['filters'] : [])); }
        case 'wa.seg_hist': { $needPost(); json_response(wa_seg_hist($pdo, is_array($in['filters'] ?? null) ? $in['filters'] : [])); }
        case 'wa.seg_stages': { json_response(wsg_stages($pdo, $adm, !empty($in['fresh']))); }
        case 'wa.seg_customer': {
            $k = preg_replace('/\D+/', '', (string)($in['phone'] ?? '')); $k = strlen($k) >= 10 ? substr($k, -10) : ''; if ($k === '') json_response(['error' => 'Write the customer\'s 10-digit phone number.'], 422);
            $c = wa_contact($pdo, $k); if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $r = wsg_customer($pdo, $k); if (!$r) json_response(['error' => 'Chat not found.'], 404); json_response($r);
        }
        case 'wa.seg_settings': {
            if ($isPost) {
                $needAdmin(); $d = wsg_defaults(); $old = wsg_cfg($pdo, true); $changed = false;
                foreach (['wake_days', 'cool_days', 'sleep_days', 'new_days', 'warm_days', 'session_hours', 'ask_min'] as $k) if (isset($in[$k]) && is_numeric($in[$k])) { $v = max(1, min($k === 'session_hours' ? 72 : 3650, (int)$in[$k])); wsg_put($pdo, 'wa_seg_' . $k, (string)$v); }
                if (isset($in['value_pcts'])) { if (!wsg_pcts_valid((string)$in['value_pcts'])) json_response(['error' => 'Write three percentages that add up to 100 or less, for example 5,15,30.'], 422); wsg_put($pdo, 'wa_seg_value_pcts', wsg_pcts_text((string)$in['value_pcts'])); }
                $new = wsg_cfg($pdo, true); foreach ($new as $k => $v) if (($old[$k] ?? null) !== $v) $changed = true;
                if ($changed) { wsg_cuts($pdo, true); $cy = wsg_cycle($pdo); $cy['state'] = 'running'; $cy['cursor'] = ''; $cy['done'] = 0; $cy['started'] = wsg_now(); $cy['hi'] = ''; $cy['total'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts")->fetchColumn(); wsg_cycle_put($pdo, $cy); }   // new thresholds: everybody is calculated again in the background
            }
            $cfg = wsg_cfg($pdo); json_response(['config' => $cfg, 'defaults' => wsg_defaults(), 'stage_info' => array_map(fn($x) => ['label' => $x[0], 'help' => $x[1]], wsg_stage_info($cfg)), 'value_info' => array_map(fn($x) => ['label' => $x[0], 'help' => $x[1]], wsg_value_info($cfg)), 'recalculating' => wsg_cycle($pdo)['state'] === 'running', 'can_edit' => $adm]);
        }
        case 'wa.seg_build': {
            $needPost(); $needAdmin(); @set_time_limit(120); $cy = wsg_cycle($pdo);
            if (!empty($in['reset']) || $cy['state'] !== 'running') $cy = wsg_cycle_start($pdo);
            if (empty($in['start_only'])) $cy = wsg_chunk($pdo, max(10, min(2000, (int)($in['limit'] ?? 500))), microtime(true) + 20, $cy);
            json_response(['ok' => true, 'state' => $cy['state'], 'done' => (int)$cy['done'], 'total' => (int)$cy['total'], 'built_now' => (int)($cy['built'] ?? 0), 'percent' => $cy['total'] > 0 ? (int)min(100, floor(100 * $cy['done'] / $cy['total'])) : 100, 'cursor' => $cy['cursor']]);
        }
        case 'wa.ctx_preview': { $needAdmin(); $r = wa_ctx_pack($pdo, (string)($in['purpose'] ?? 'campaign_plan')); json_response($r); }
        case 'wa.dec_list': {
            $names = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = $pdo->query("SELECT id, dkey, text, scope, set_by, at, active FROM wa_decisions ORDER BY id")->fetchAll();
            json_response(['decisions' => array_map(fn($r) => ['id' => (int)$r['id'], 'key' => (string)$r['dkey'], 'text' => (string)$r['text'], 'scope' => (string)$r['scope'], 'set_by' => (int)$r['set_by'], 'set_by_name' => $names[(int)$r['set_by']] ?? '', 'at' => (string)$r['at'], 'active' => (int)$r['active'] === 1], $rows), 'can_edit' => $adm, 'scopes' => ['all' => 'Everything', 'campaign' => 'Campaigns', 'template' => 'Message wording']]);
        }
        case 'wa.dec_save': {
            $needPost(); $needAdmin(); $c = wsg_dec_clean($in); if (mb_strlen($c['text']) < 4) json_response(['error' => 'Write the rule in one clear sentence.'], 422);
            $by = $me > 0 ? $me : -max(1, (int)($auth['user_id'] ?? 1)); $id = (int)($in['id'] ?? 0); $act = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1; $now = date('Y-m-d H:i:s', wsg_now());
            if ($id > 0) {
                $ex = $pdo->prepare("SELECT id FROM wa_decisions WHERE id = ?"); $ex->execute([$id]); if (!$ex->fetch()) json_response(['error' => 'Decision not found.'], 404);
                $dup = $pdo->prepare("SELECT id FROM wa_decisions WHERE dkey = ? AND id <> ?"); $dup->execute([$c['key'], $id]); if ($dup->fetch()) json_response(['error' => 'Another decision already has this short name. Use a different one.'], 409);
                $pdo->prepare("UPDATE wa_decisions SET dkey = ?, text = ?, scope = ?, set_by = ?, at = ?, active = ? WHERE id = ?")->execute([$c['key'], $c['text'], $c['scope'], $by, $now, $act, $id]);
            } else {
                if ((int)$pdo->query("SELECT COUNT(*) FROM wa_decisions")->fetchColumn() >= 100) json_response(['error' => 'There are already 100 decisions. Remove one first.'], 422);
                $pdo->prepare("INSERT INTO wa_decisions (dkey, text, scope, set_by, at, active) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE text = VALUES(text), scope = VALUES(scope), set_by = VALUES(set_by), at = VALUES(at), active = VALUES(active)")->execute([$c['key'], $c['text'], $c['scope'], $by, $now, $act]);
                $q = $pdo->prepare("SELECT id FROM wa_decisions WHERE dkey = ?"); $q->execute([$c['key']]); $id = (int)$q->fetchColumn();
            }
            json_response(['ok' => true, 'id' => $id, 'decision' => ['id' => $id, 'key' => $c['key'], 'text' => $c['text'], 'scope' => $c['scope'], 'active' => $act === 1]]);
        }
        case 'wa.dec_del': { $needPost(); $needAdmin(); $id = (int)($in['id'] ?? 0); $d = $pdo->prepare("DELETE FROM wa_decisions WHERE id = ?"); $d->execute([$id]); if ($d->rowCount() < 1) json_response(['error' => 'Decision not found.'], 404); json_response(['ok' => true]); }
        case 'wa.dec_extract': { $needPost(); $needAdmin(); json_response(wa_dec_extract($pdo, (string)($in['text'] ?? ''))); }
    }
}
