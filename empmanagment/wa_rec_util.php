<?php
/**
 * wa_rec_util.php — 🔁➡️💸 MODULE B: a marketing message that FAILED gets a UTILITY follow-up (owner, 17 Nov 2026).
 *
 * THE IDEA IN PLAIN WORDS
 *  - Module A (wa_mix / wa_camp) already sends marketing when a utility message cannot go. This is the other way round.
 *  - A person whose MARKETING campaign message failed gets one of the approved utility follow-up messages (the same pool the Utility campaign uses: wa_util_pick),
 *    but ONLY if they qualify for utility — they asked about a perfume on WhatsApp in the last 30 days, or ordered inside the order window (Meta's rule for utility).
 *    Everyone else is left alone ("no enquiry / order").
 *  - Which failures: 131049 (Meta held it: this customer got too many marketing messages) and 131050 (the customer stopped marketing messages) are marketing-only blocks,
 *    utility is not subject to them → tried after wa_ru_wait_min (default 10) minutes. Any other failure that another number / wording could fix goes to utility only AFTER
 *    Module A's recovery (wa_rec) used its tries on that person. NEVER: not on WhatsApp, own number, test group, spam flag, a wrong request.
 *  - Never to: opted out, wrote back since, delivered another way today, already got a utility follow-up for this item, already got one in the last 24 hours (any item),
 *    items whose failed message was itself a utility one (variant U).
 *  - One utility message per item. Every attempt is written to wa_ru_log (who, which number, which message, when). "Recovered" only ever means Meta said delivered / read.
 *  - When the utility message is delivered, the campaign's own later retry of that person is cancelled (no double message).
 *  - Which utility message: first the utility version of the campaign's OWN message (wa_util_usable: the owner's words, approved at Meta, filled by wa_mix_u_vars), then the 6 standard follow-ups (wa_util_pick).
 *  - ROUTE (known before sending): people the campaign skipped because Meta had already held / stopped marketing to them are NOT dropped: the same utility follow-up goes to them (if they asked / ordered recently).
 *  - Brakes: quiet hours, an hourly pool, a pause for an hour when 60%+ of the utility messages fail, 6 hours when Meta flags spam (131048).
 *  - Nothing is sent until at least one utility follow-up message is approved at Meta (Utility campaign → make the 6 follow-ups).
 * Settings: wa_ru_on 1 · wa_ru_hourly 1500 (calm) · wa_ru_wait_min 10 · wa_ru_ord_days 60 · wa_ru_ask_days 90 (asked / wrote to us within N days) · wa_ru_make_on 1 (make + send utility versions to Meta by itself) · wa_ru_pause_until (set by itself) · wa_ru_cursor (set by itself)
 * Routes (wa_camp.php): wa.ru_status GET · wa.ru_run POST · wa.ru_set POST {on, hourly?, wait_min?, ord_days?}
 * PHP 7.4 safe.
 */
if (!function_exists('wa_ru_tick')) {

/** Failures only marketing suffers from: utility is not held or blocked by them. */
if (!defined('WA_RU_MARKETING_ONLY')) { define('WA_RU_MARKETING_ONLY', [131049, 131050]); }

function wa_ru_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ru_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, item_id BIGINT NOT NULL, phone VARCHAR(20) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT '',
            number_id INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, wamid VARCHAR(120) NOT NULL DEFAULT '', ok TINYINT NOT NULL DEFAULT 1, err_code INT NOT NULL DEFAULT 0, first_code INT NOT NULL DEFAULT 0,
            err VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, UNIQUE KEY u_item (item_id), KEY k_camp (camp_id, at), KEY k_at (at), KEY k_wamid (wamid), KEY k_phone (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $c = (string)$pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_ru_log'")->fetchColumn(); if ($c !== '' && $c !== 'utf8mb4_unicode_ci') $pdo->exec("ALTER TABLE wa_ru_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e2) { }   // same collation as wa_messages, or the joins fail (error 1267)
        if ((string)setting_get($pdo, 'wa_ru_schema', '') !== '2') {   // v2: a second try from another number needs the words again (vars_json) and one row per (item, attempt)
            try { $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_ru_log' AND COLUMN_NAME = 'attempt'")->fetchColumn();
                if (!$has) { $pdo->exec("ALTER TABLE wa_ru_log ADD COLUMN attempt TINYINT NOT NULL DEFAULT 1, ADD COLUMN vars_json TEXT NULL"); try { $pdo->exec("ALTER TABLE wa_ru_log DROP INDEX u_item"); } catch (Throwable $e3) { } $pdo->exec("ALTER TABLE wa_ru_log ADD UNIQUE KEY u_item2 (item_id, attempt)"); }
                setting_set($pdo, 'wa_ru_schema', '2'); } catch (Throwable $e4) { }
        }
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function wa_ru_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    return ['on' => $g('wa_ru_on', '1') !== '0', 'hourly' => max(10, min(20000, (int)$g('wa_ru_hourly', '1500'))), 'wait_min' => max(5, min(1440, (int)$g('wa_ru_wait_min', '10'))),
            'ord_days' => in_array((int)$g('wa_ru_ord_days', '60'), [30, 60, 90, 120], true) ? (int)$g('wa_ru_ord_days', '60') : 60, 'pause_until' => (int)$g('wa_ru_pause_until', '0'),
            'ask_days' => in_array((int)$g('wa_ru_ask_days', '90'), [30, 60, 90, 120, 180], true) ? (int)$g('wa_ru_ask_days', '90') : 90, 'make_on' => $g('wa_ru_make_on', '1') !== '0'];
}
/** Can utility fix this failure? 'now' = marketing-only block · 'after_rec' = a generic failure, utility only once Module A used its tries · 'never'. */
function wa_ru_class(int $ec): string
{
    if (in_array($ec, WA_RU_MARKETING_ONLY, true)) return 'now';
    if (function_exists('wa_rec_class') && wa_rec_class($ec) === 'never') return 'never';
    return 'after_rec';
}
/** The utility message + the numbers that may send it (cached by the caller per message). [$tpl, $ctx] or [null, null]. */
function wa_ru_tpl_ctx(PDO $pdo, array $t): array
{
    $ok = array_map('strval', (array)($t['ok_wabas'] ?? [])); if (!$ok) return [null, null];
    $by = []; foreach ($ok as $wb) $by[$wb] = $t; $base = $t; $base['by_waba'] = $by; $base['ok_wabas'] = $ok;
    return [$base, wa_camp_ctx($pdo, $base, 99, [], 0, true)];
}
/** The utility module (wa_util.php) is loaded by the engine, but not on every route (the campaign routes did not have it, so "Start as utility" showed no messages). Loaded from here, inside a function that has $pdo. */
function wa_ru_load_util(PDO $pdo): void
{
    if (function_exists('wa_util_list') || !is_file(__DIR__ . '/wa_util.php')) return;
    try { require_once __DIR__ . '/wa_util.php'; } catch (Throwable $e) { }
}
function wa_ru_pool(PDO $pdo): array
{
    wa_ru_load_util($pdo);
    if (!function_exists('wa_util_pool_usable') && is_file(__DIR__ . '/wa_util.php')) { try { require_once __DIR__ . '/wa_util.php'; } catch (Throwable $e) { } }
    return function_exists('wa_util_pool_usable') ? wa_util_pool_usable($pdo) : [];
}

/** The utility follow-up messages that exist in HRMS (made, maybe still waiting for Meta). */
function wa_ru_pool_made(PDO $pdo): array
{
    if (!function_exists('wa_util_pool_rows') && is_file(__DIR__ . '/wa_util.php')) { try { require_once __DIR__ . '/wa_util.php'; } catch (Throwable $e) { } }
    try { return function_exists('wa_util_pool_rows') ? wa_util_pool_rows($pdo) : []; } catch (Throwable $e) { return []; }
}


/** Per number, how the utility messages of the last 3 hours did (only messages older than 15 minutes count: Meta's receipts need a little time). A number whose delivery stalls
 *  (40+ messages, under 35% delivered or 30%+ failed) RESTS for 2 hours: the other numbers take over; if every number rests, the sending simply waits. */
function wa_ru_numbers(PDO $pdo, int $nowTs, bool $update = true): array
{
    $rest = json_decode((string)setting_get($pdo, 'wa_ru_rest', ''), true); $rest = is_array($rest) ? $rest : []; $o = [];
    try {
        $q = $pdo->prepare("SELECT r.number_id, COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.at >= ? AND r.at <= ? GROUP BY r.number_id");
        $q->execute([date('Y-m-d H:i:s', $nowTs - 3 * 3600), date('Y-m-d H:i:s', $nowTs - 900)]);
        foreach ($q->fetchAll() as $r) { $id = (int)$r['number_id']; $n = (int)$r['n']; $d = (int)$r['d']; $f = (int)$r['f'];
            if ($update && $n >= 40 && ((float)$d / $n < 0.35 || (float)$f / $n >= 0.3) && (int)($rest[$id] ?? 0) <= $nowTs) { $rest[$id] = $nowTs + 7200; wa_log($pdo, 'campaign', false, 'Utility follow-up: number #' . $id . ' rests for 2 hours (' . $d . ' of ' . $n . ' delivered, ' . $f . ' failed)'); }
            $o[$id] = ['n' => $n, 'd' => $d, 'f' => $f, 'pct' => $n ? (int)round($d * 100 / $n) : null]; }
        if ($update) setting_set($pdo, 'wa_ru_rest', json_encode((object)array_filter($rest, fn($u) => (int)$u > $nowTs)));
    } catch (Throwable $e) { }
    foreach ($o as $id => $x) $o[$id]['rest_until'] = (int)($rest[$id] ?? 0) > $nowTs ? (int)$rest[$id] : 0;
    foreach ($rest as $id => $u) if ((int)$u > $nowTs && !isset($o[(int)$id])) $o[(int)$id] = ['n' => 0, 'd' => 0, 'f' => 0, 'pct' => null, 'rest_until' => (int)$u];
    return $o;
}
/** The plan the owner sees: how many are waiting, the calm speed, when it should be done (only hours inside the sending window count). */
function wa_ru_plan(PDO $pdo, array $cfg, array $diag): array
{
    $done = (int)($diag['done'] ?? 0); $left = max(0, (int)($diag['util_ok'] ?? 0) + (int)($diag['util_known_ok'] ?? 0) - $done); $per = max(1, (int)$cfg['hourly']);
    $now = time(); $t = $now; $need = $left; $guard = 0;
    while ($need > 0 && $guard++ < 24 * 14) { if (function_exists('wa_camp_in_window') && wa_camp_in_window($pdo, $t)) $need -= $per; if ($need <= 0) break; $t += 3600; }
    return ['left' => $left, 'per_hour' => $per, 'eta' => $left > 0 ? date('Y-m-d H:i', $t + ($t === $now ? 3600 : 0)) : null, 'hours' => $left > 0 ? (int)ceil($left / $per) : 0];
}
/** 🛠 Utility versions of EVERY marketing message are made and sent to Meta for approval by themselves (owner, 17 Nov 2026):
 *  - a marketing message that was just made gets its utility versions at once (newest first, today's campaigns first); then every older marketing message that has fewer than wa_ru_make_n (10) is filled up, a few per minute, until all are done;
 *  - each one is made AND sent to Meta in the same step; a draft that did not go through is sent again every minute (a Meta limit pauses it for 30 minutes) — nothing stays "not sent";
 *  - one round = at most 3 messages / about 25 seconds (each is a Claude call); a message whose try failed is left for 30 minutes;
 *  - the standard follow-ups (+ the welcome one) are made once if any is missing. */
function wa_ru_make_tick(PDO $pdo): array
{
    $o = ['made' => 0, 'note' => '']; wa_ru_load_util($pdo);
    if (!wa_ru_cfg($pdo)['make_on'] || !function_exists('wa_util_make') || !function_exists('wa_tpl_row')) { $o['note'] = 'off'; return $o; }
    $t0 = microtime(true); $now = time();
    // 1) utility messages that exist but are not at Meta yet: sent now, 8 per round (every minute)
    try {
        if (function_exists('wa_util_submit') && (int)setting_get($pdo, 'wa_ru_submit_pause', '0') < $now && (int)setting_get($pdo, 'wa_ru_draft_at', '0') < $now - 50) {
            setting_set($pdo, 'wa_ru_draft_at', (string)$now);
            $dq = $pdo->prepare("SELECT t.id, t.name FROM wa_templates t WHERE t.category = 'UTILITY' AND (t.name LIKE 'util\\_%' OR t.name LIKE 'tmph\\_fu\\_%') AND t.created_at >= ? AND t.relabelled_at IS NULL AND NOT EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id) ORDER BY t.id DESC LIMIT 8"); $dq->execute([date('Y-m-d H:i:s', $now - 7 * 86400)]); $drafts = $dq->fetchAll();
            if ($drafts) { $sent = 0; $err = '';
                foreach ($drafts as $d) { $r = wa_util_submit($pdo, (int)$d['id']); if (isset($r['error'])) { $err = (string)$r['error']; continue; } $okAny = false; foreach ((array)($r['submitted'] ?? []) as $x) { if (!empty($x['ok'])) $okAny = true; elseif ($err === '' && !empty($x['note'])) $err = (string)$x['note']; } if ($okAny) $sent++; }
                $o['made'] += $sent; if (!$sent && $err !== '' && preg_match('/limit|quota|too many|rate|exceed/i', $err)) setting_set($pdo, 'wa_ru_submit_pause', (string)($now + 1800));   // Meta's template limit: wait half an hour, then try again by itself
                try { setting_set($pdo, 'wa_ru_make_last', json_encode(['at' => $now, 'tpl' => 0, 'made' => $sent, 'error' => $sent ? '' : mb_substr($err, 0, 160), 'skipped' => []], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e9) { }
                if (function_exists('wa_log')) wa_log($pdo, 'template', $sent > 0, 'Utility messages sent to Meta by themselves: ' . $sent . ' of ' . count($drafts) . ($err !== '' && !$sent ? ' — ' . $err : ''));
                return $o; }
        }
    } catch (Throwable $e) { }
    // 2) make the missing utility versions of the marketing messages (newest / today's first)
    if (!function_exists('wa_lock') || !wa_lock($pdo, 'rumake', 55)) { $o['note'] = 'recent'; return $o; }
    try {
        if (function_exists('wa_util_pool_make') && function_exists('wa_util_pool_defs') && (int)setting_get($pdo, 'wa_ru_pool_at', '0') < $now - 6 * 3600) {
            setting_set($pdo, 'wa_ru_pool_at', (string)$now);
            if (count(wa_ru_pool_made($pdo)) < count(wa_util_pool_defs())) { $r = wa_util_pool_make($pdo, 0); $o['made'] += count($r['made'] ?? []); if (!empty($r['made'])) return $o; }
        }
        $want = max(2, min(12, (int)setting_get($pdo, 'wa_ru_make_n', '10'))); $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $fail = json_decode((string)setting_get($pdo, 'wa_ru_fail', ''), true); $fail = is_array($fail) ? array_filter($fail, fn($t) => (int)$t > $now - 1800) : [];
        $q = $pdo->prepare("SELECT t.id, (SELECT COUNT(*) FROM wa_templates u WHERE u.category = 'UTILITY' AND u.relabelled_at IS NULL AND u.family_id = COALESCE(NULLIF(t.family_id, 0), t.id) AND (NOT EXISTS (SELECT 1 FROM wa_tpl_waba x WHERE x.tpl_id = u.id) OR EXISTS (SELECT 1 FROM wa_tpl_waba x2 WHERE x2.tpl_id = u.id AND x2.status IN ('APPROVED', 'PENDING', 'IN_APPEAL', 'ERROR')))) AS have, (SELECT COUNT(*) FROM wa_templates u2 WHERE u2.category = 'UTILITY' AND u2.family_id = COALESCE(NULLIF(t.family_id, 0), t.id)) AS total_made,
              (SELECT COUNT(*) FROM wa_campaigns c WHERE c.tpl_id = t.id AND COALESCE(c.started_at, c.created_at) >= ?) AS today
            FROM wa_templates t WHERE t.category = 'MARKETING' AND (t.family_id IS NULL OR t.family_id = 0 OR t.family_id = t.id) AND (t.extra IS NULL OR (t.extra NOT LIKE '%utility_for%' AND t.extra NOT LIKE '%\"alt_of\"%'))
              AND (EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED') OR (t.created_at >= ? AND NOT EXISTS (SELECT 1 FROM wa_tpl_waba w2 WHERE w2.tpl_id = t.id AND w2.status = 'REJECTED')))
            HAVING have < ? AND total_made < ? ORDER BY today DESC, t.id DESC LIMIT 12");   // rejected / moved / paused versions do not count: they are replaced by new ones, but never more than twice the target in all (Meta's template allowance)
        $q->execute([$from, date('Y-m-d H:i:s', $now - 14 * 86400), $want, $want * 2]);
        foreach ($q->fetchAll() as $r) {
            if ($o['made'] >= 3 * 2 || microtime(true) - $t0 > 22) break;
            $tid = (int)$r['id']; if (isset($fail[$tid])) continue;
            $res = wa_util_make($pdo, $tid, min(6, $want - (int)$r['have']), 0, true);   // made AND sent to Meta in the same step
            $n = count($res['made'] ?? []); $o['made'] += $n; if (isset($res['error']) || $n === 0) $fail[$tid] = $now;
            try { setting_set($pdo, 'wa_ru_make_last', json_encode(['at' => $now, 'tpl' => $tid, 'made' => $n, 'error' => isset($res['error']) ? mb_substr((string)$res['error'], 0, 160) : '', 'skipped' => array_slice((array)($res['skipped'] ?? []), 0, 2)], JSON_UNESCAPED_UNICODE)); } catch (Throwable $e9) { }
            if (function_exists('wa_log')) wa_log($pdo, 'template', !isset($res['error']) && $n > 0, 'Utility versions for marketing message #' . $tid . ' (auto): ' . (isset($res['error']) ? $res['error'] : $n . ' made and sent to Meta'));
        }
        setting_set($pdo, 'wa_ru_fail', json_encode((object)$fail));
    } catch (Throwable $e) { $o['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 120); }
    return $o;
}
/** How far the filling-up of ALL marketing messages is: [done, total] (remembered for 2 minutes). */
function wa_ru_backfill_counts(PDO $pdo): array
{
    $c = json_decode((string)setting_get($pdo, 'wa_ru_bf', ''), true); if (is_array($c) && (int)($c['at'] ?? 0) > time() - 120) return $c;
    $want = max(2, min(12, (int)setting_get($pdo, 'wa_ru_make_n', '10'))); $o = ['done' => 0, 'total' => 0, 'want' => $want, 'at' => time()];
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(x.have >= ? OR x.total_made >= ?) AS d FROM (SELECT (SELECT COUNT(*) FROM wa_templates u WHERE u.category = 'UTILITY' AND u.relabelled_at IS NULL AND u.family_id = COALESCE(NULLIF(t.family_id, 0), t.id) AND (NOT EXISTS (SELECT 1 FROM wa_tpl_waba x WHERE x.tpl_id = u.id) OR EXISTS (SELECT 1 FROM wa_tpl_waba x2 WHERE x2.tpl_id = u.id AND x2.status IN ('APPROVED', 'PENDING', 'IN_APPEAL', 'ERROR')))) AS have, (SELECT COUNT(*) FROM wa_templates u2 WHERE u2.category = 'UTILITY' AND u2.family_id = COALESCE(NULLIF(t.family_id, 0), t.id)) AS total_made FROM wa_templates t
            WHERE t.category = 'MARKETING' AND (t.family_id IS NULL OR t.family_id = 0 OR t.family_id = t.id) AND (t.extra IS NULL OR (t.extra NOT LIKE '%utility_for%' AND t.extra NOT LIKE '%\"alt_of\"%'))
              AND (EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED') OR (t.created_at >= ? AND NOT EXISTS (SELECT 1 FROM wa_tpl_waba w2 WHERE w2.tpl_id = t.id AND w2.status = 'REJECTED')))) x");
        $q->execute([$want, $want * 2, date('Y-m-d H:i:s', time() - 14 * 86400)]); $r = $q->fetch(); $o['total'] = (int)$r['n']; $o['done'] = (int)$r['d'];
    } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_ru_bf', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** A utility message that FAILED (not delivered by Meta) is tried once more from a DIFFERENT number (never one that is resting, High / Medium quality only). If that fails too, it is left. */
function wa_ru_retry_tick(PDO $pdo, int $nowTs, int &$left, float $t0): array
{
    $o = ['sent' => 0, 'failed' => 0];
    if ($left <= 0) return $o;
    try {
        $never = implode(',', array_diff(defined('WA_REC_NEVER') ? WA_REC_NEVER : [0], WA_RU_MARKETING_ONLY)) ?: '0';
        $q = $pdo->prepare("SELECT l.item_id, l.camp_id, l.phone, l.number_id, l.tpl_id, l.kind, l.vars_json, l.first_code FROM wa_ru_log l LEFT JOIN wa_messages m ON m.wamid = l.wamid AND l.wamid <> ''
            WHERE l.attempt = 1 AND l.at <= ? AND l.at >= ? AND (l.ok = 0 OR m.status = 'failed') AND l.err_code NOT IN ($never) AND l.vars_json IS NOT NULL AND NOT EXISTS (SELECT 1 FROM wa_ru_log l2 WHERE l2.item_id = l.item_id AND l2.attempt = 2) ORDER BY l.id LIMIT 60");
        $q->execute([date('Y-m-d H:i:s', $nowTs - 1800), date('Y-m-d H:i:s', $nowTs - 8 * 3600)]); $rows = $q->fetchAll(); if (!$rows) return $o;
        $rest = wa_ru_numbers($pdo, $nowTs, false); $cache = [];
        foreach ($rows as $r) {
            if ($left <= 0 || microtime(true) - $t0 > 40) break;
            $ct = wa_contact($pdo, (string)$r['phone']); if (!$ct || (int)($ct['optout'] ?? 0) === 1) continue;
            $tid = (int)$r['tpl_id']; if (!array_key_exists($tid, $cache)) { $tp = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, $tid, true, 0) : null; $cache[$tid] = $tp ? wa_ru_tpl_ctx($pdo, $tp) : [null, null]; }
            [$tUse, $ctx] = $cache[$tid]; if (!$tUse || !$ctx) continue; $vars = json_decode((string)$r['vars_json'], true); if (!is_array($vars)) continue;
            $cand = array_filter($ctx['nums'], fn($x) => $x['left'] > 0 && (int)$x['id'] !== (int)$r['number_id'] && empty($rest[(int)$x['id']]['rest_until']) && in_array(($x['q'] ?? ''), ['GREEN', 'YELLOW'], true));
            if (!$cand) continue; $hi = array_filter($cand, fn($x) => ($x['q'] ?? '') === 'GREEN'); $cand = $hi ?: $cand;
            uasort($cand, fn($x, $y) => ($x['used'] / max(1, $x['cap'])) <=> ($y['used'] / max(1, $y['cap']))); $num = reset($cand); $nid = (int)$num['id']; $tSend = $num['tm'] ?? $tUse;
            [$ok, $rr, $ecs] = wa_send_tpl_one($pdo, ['phone' => (string)$r['phone'], 'wa_id' => (string)($ct['wa_id'] ?? ''), 'camp_optout' => (int)($ct['camp_optout'] ?? 0)], $tSend, $num, $vars, 'recover');
            try { $pdo->prepare("INSERT IGNORE INTO wa_ru_log (camp_id, item_id, phone, kind, number_id, tpl_id, wamid, ok, err_code, first_code, err, at, attempt, vars_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 2, ?)")
                ->execute([(int)$r['camp_id'], (int)$r['item_id'], (string)$r['phone'], (string)$r['kind'], $nid, (int)$tSend['id'], $ok ? (string)$rr : '', $ok ? 1 : 0, $ok ? 0 : (int)$ecs, (int)$r['first_code'], $ok ? '' : mb_substr((string)$rr, 0, 190), date('Y-m-d H:i:s', $nowTs), (string)$r['vars_json']]); } catch (Throwable $e) { }
            $ctx['nums'][$nid]['left']--; $ctx['nums'][$nid]['used']++; $ctx['port_left']--; $cache[$tid][1] = $ctx; $left--;
            if ($ok) $o['sent']++; else $o['failed']++;
        }
    } catch (Throwable $e) { }
    return $o;
}

/** ♻ Approved utility versions made for ANY marketing message (the newest $limit that no campaign has used up): they say "thanks for asking about {{perfume}} …" without an offer, so they fit any campaign.
 *  Same shape as wa_util_usable() (approved on a WhatsApp account, examples with the variable labels). Remembered for the request. */
function wa_ru_global_usable(PDO $pdo, int $limit = 12): array
{
    static $memo = null; if ($memo !== null) return array_slice($memo, 0, $limit);
    wa_ru_load_util($pdo); $o = [];
    try {
        $q = $pdo->query("SELECT t.id, t.examples FROM wa_templates t WHERE t.category = 'UTILITY' AND t.name LIKE 'util\\_%' AND t.relabelled_at IS NULL AND (t.category_now IS NULL OR t.category_now = '' OR t.category_now <> 'MARKETING')
            AND EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED') ORDER BY t.id DESC LIMIT 40");
        foreach ($q->fetchAll() as $r) { if (count($o) >= 12) break; $t = function_exists('wa_camp_tpl') ? wa_camp_tpl($pdo, (int)$r['id'], true, 0) : null; if (!$t || empty($t['ok_wabas'])) continue;
            if (!is_array($t['examples'] ?? null)) $t['examples'] = json_decode((string)($r['examples'] ?? ''), true) ?: []; $o[] = $t; }
    } catch (Throwable $e) { }
    $memo = $o; return array_slice($o, 0, $limit);
}
/** One round (≤ 40 s) of the utility follow-up for failed marketing messages. */
function wa_ru_tick(PDO $pdo): array
{
    wa_ru_load_util($pdo);
    $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'looked' => 0, 'note' => '', 'why' => []];
    if (!wa_ru_tables($pdo)) { $res['note'] = 'no table'; return $res; }
    $cfg = wa_ru_cfg($pdo); if (!$cfg['on']) { $res['note'] = 'off'; return $res; } if ($cfg['pause_until'] > time()) { $res['note'] = 'paused after a Meta warning'; return $res; }
    if (!function_exists('wa_lock') || !wa_lock($pdo, 'ru', 50)) { $res['note'] = 'busy'; return $res; }
    $t0 = microtime(true); $nowTs = wa_camp_now(); $now = date('Y-m-d H:i:s', $nowTs); $why = [];
    try { $res['made'] = wa_ru_make_tick($pdo)['made']; } catch (Throwable $e) { }   // utility versions of today's campaign messages → Meta
    try {
        if (!wa_camp_in_window($pdo, $nowTs)) { $res['note'] = 'quiet hours'; return $res; }
        $pool = wa_ru_pool($pdo); $famU = [];   // 1) the utility version(s) of the campaign's OWN message (the owner's words, approved at Meta), 2) the 6 standard follow-ups
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log WHERE at >= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - 3600)]); $left = min($cfg['hourly'] - (int)$q->fetchColumn(), max(20, (int)ceil($cfg['hourly'] / 45))); if ($left <= 0) { $res['note'] = 'this hour\'s pool is used'; return $res; }   // calm: about 1/45 of the hour's pool per round, never a burst
        // a round that mostly fails pauses itself for an hour
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(m.status = 'failed') AS f FROM wa_ru_log r JOIN wa_messages m ON m.wamid = r.wamid WHERE r.at >= ? AND r.wamid <> ''"); $q->execute([date('Y-m-d H:i:s', $nowTs - 7200)]); $hr = $q->fetch();
        if ((int)$hr['n'] >= 60 && (int)$hr['f'] / max(1, (int)$hr['n']) >= 0.6) { setting_set($pdo, 'wa_ru_pause_until', (string)($nowTs + 3600)); wa_log($pdo, 'campaign', false, 'Utility follow-up paused for an hour: ' . (int)$hr['f'] . ' of ' . (int)$hr['n'] . ' failed'); $res['note'] = 'paused: too many failures'; return $res; }
        // somebody got the utility message → the campaign's own retry of that person must not send a second one
        try { $pdo->exec("UPDATE wa_camp_items i JOIN wa_ru_log r ON r.item_id = i.id JOIN wa_messages m ON m.wamid = r.wamid SET i.status = 'skipped' WHERE (i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND r.ok = 1 AND r.wamid <> '' AND m.status IN ('delivered', 'read')"); } catch (Throwable $e) { }
        $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $cursor = (int)setting_get($pdo, 'wa_ru_cursor', '0'); $lim = 600; $recOn = function_exists('wa_rec_cfg') ? !empty(wa_rec_cfg($pdo)['on']) : false; $recTries = function_exists('wa_rec_cfg') ? max(1, (int)wa_rec_cfg($pdo)['max_attempts'] - 1) : 2;
        $mo = implode(',', WA_RU_MARKETING_ONLY);
        $q = $pdo->prepare("SELECT i.id, i.camp_id, c.tpl_id AS ctpl, i.phone, i.name, i.err_code, i.status, i.sent_at FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id
            WHERE (i.status = 'failed' OR ((i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND i.err_code IN ($mo)) OR " . wa_ru_known_sql() . ") AND COALESCE(i.variant, 'M') <> 'U' AND COALESCE(c.extra, '') NOT LIKE '%\"kind\":\"util\"%'
              AND (c.started_at >= ? OR i.sent_at >= ? OR (i.status = 'skipped' AND COALESCE(c.started_at, c.created_at) >= '" . date('Y-m-d H:i:s', $nowTs - 3 * 86400) . "')) AND i.id > ? AND NOT EXISTS (SELECT 1 FROM wa_ru_log l WHERE l.item_id = i.id) ORDER BY i.id LIMIT " . $lim);
        $q->execute([$from, $from, $cursor]); $items = $q->fetchAll();
        if (!$items) { if ($cursor > 0) setting_set($pdo, 'wa_ru_cursor', '0'); $rt = wa_ru_retry_tick($pdo, $nowTs, $left, $t0); $res['sent'] += $rt['sent']; $res['failed'] += $rt['failed']; $res['note'] = 'nothing new to follow up' . ($rt['sent'] + $rt['failed'] ? ' · retried ' . ($rt['sent'] + $rt['failed']) . ' from another number' : ''); return $res; }
        foreach (array_unique(array_map(fn($x) => (int)$x['ctpl'], $items)) as $tp) { try { $famU[$tp] = function_exists('wa_util_usable') ? wa_util_usable($pdo, $tp) : []; } catch (Throwable $e) { $famU[$tp] = []; } }
        if (!array_filter($famU) && (string)setting_get($pdo, 'wa_u_reuse', '1') !== '0') { $gl = wa_ru_global_usable($pdo, 12); if ($gl) foreach ($famU as $tp0 => $_v) if (!$_v) $famU[$tp0] = $gl; }   // ♻ versions made for other marketing messages
        if (!$pool && !array_filter($famU)) { $res['note'] = 'no approved utility message yet (waiting for Meta)'; return $res; }
        $res['looked'] = count($items); $in = implode(',', array_map(fn($x) => (int)$x['id'], $items));
        $phones = array_values(array_unique(array_map(fn($x) => (string)$x['phone'], $items))); $pin = implode(',', array_map(fn($p) => $pdo->quote($p), $phones));
        $ct = []; foreach ($pdo->query("SELECT phone, wa_id, name, optout, camp_optout, last_in_at, last_number_id FROM wa_contacts WHERE phone IN ($pin)")->fetchAll() as $r) $ct[(string)$r['phone']] = $r;
        $got = []; $q3 = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE phone IN ($pin) AND dir = 'out' AND mtype = 'template' AND status IN ('delivered', 'read') AND ts >= ?"); $q3->execute([date('Y-m-d H:i:s', strtotime($from) - 86400)]); foreach ($q3->fetchAll(PDO::FETCH_COLUMN) as $p) $got[(string)$p] = 1;
        $recent = []; $q4 = $pdo->prepare("SELECT DISTINCT phone FROM wa_ru_log WHERE at >= ?"); $q4->execute([date('Y-m-d H:i:s', $nowTs - 86400)]); foreach ($q4->fetchAll(PDO::FETCH_COLUMN) as $p) $recent[(string)$p] = 1;
        $rec = []; if (function_exists('wa_rec_tables') && wa_rec_tables($pdo)) { foreach ($pdo->query("SELECT item_id, COUNT(*) AS n, MAX(at) AS last FROM wa_rec_log WHERE item_id IN ($in) GROUP BY item_id")->fetchAll() as $r) $rec[(int)$r['item_id']] = $r; }
        $cache = []; $seen = []; $rot = 0; $lastId = $cursor; $broke = false; $numSt = wa_ru_numbers($pdo, $nowTs); $mixDays = function_exists('wa_mix_cfg') ? (int)wa_mix_cfg($pdo, 'wa_mix_days', 30) : 30; $shift = max(0, $cfg['ask_days'] - $mixDays) * 86400;
        $sk = function (string $k) use (&$res, &$why) { $res['skipped']++; $why[$k] = ($why[$k] ?? 0) + 1; };
        foreach ($items as $it) {
            if (microtime(true) - $t0 > 38 || $left <= 0) { $broke = true; break; }
            $ph = (string)$it['phone']; $iid = (int)$it['id']; $lastId = $iid; $ec = (int)$it['err_code']; if ((string)$it['status'] === 'skipped' && $ec === 131026) $ec = 131050;   // skipped before sending because Meta had already stopped marketing to them (the skip text says 131026)
            $cls = wa_ru_class($ec);
            if ($cls === 'never') { $sk('Meta will not deliver this one'); continue; }
            if (isset($recent[$ph]) || isset($seen[$ph])) { $sk('already got a utility message in the last 24 hours'); continue; }
            if (isset($got[$ph])) { $sk('delivered another way today'); continue; }
            $c = $ct[$ph] ?? null; if (!$c) { $sk('no contact record'); continue; } if ((int)$c['optout'] === 1) { $sk('opted out'); continue; }
            if (!empty($c['last_in_at']) && !empty($it['sent_at']) && strtotime((string)$c['last_in_at']) > strtotime((string)$it['sent_at'])) { $sk('wrote back'); continue; }
            $failedAt = !empty($it['sent_at']) ? strtotime((string)$it['sent_at']) : 0;
            if ($cls === 'now') { if ($failedAt > $nowTs - $cfg['wait_min'] * 60) { $sk('waiting a little first'); continue; } }
            else {   // a generic failure: Module A (recovery) goes first
                $r = $rec[$iid] ?? null; $n = $r ? (int)$r['n'] : 0;
                if ($recOn && $n < $recTries) { $sk('recovery from another number goes first'); continue; }
                if ($r && strtotime((string)$r['last']) > $nowTs - 3600) { $sk('recovery tried in the last hour'); continue; }
            }
            $rot++; $pk = null; $ctm = ['phone' => $ph, 'name' => (string)($c['name'] ?? $it['name'] ?? ''), 'optout' => 0, 'last_in_at' => $c['last_in_at'] ?? null];
            if (!empty($famU[(int)$it['ctpl']]) && function_exists('wa_mix_u_vars') && function_exists('wa_mix_cheap_ok')) {   // 1) the utility version of this campaign's own message (same words as the owner's marketing, no offer)
                $om = null; try { $om = wa_order_map($pdo)[$ph] ?? null; } catch (Throwable $e) { }
                if (wa_mix_cheap_ok($pdo, $ctm, $om, $nowTs - $shift)) { $fl = array_values($famU[(int)$it['ctpl']]); for ($fi = 0; $fi < count($fl); $fi++) { $u0 = $fl[($rot + $fi) % count($fl)]; $uv = wa_mix_u_vars($pdo, $u0, $ctm, $om, $nowTs - $shift); if (is_array($uv) && count($uv) >= count(wa_tpl_vars((string)$u0['body']))) { $pk = ['t' => $u0, 'vars' => $uv, 'kind' => 'fam']; break; } } }   // the asked / ordered window is the owner's (30 / 60 / 90 days …), the words stay true: the real dates are shown
            }
            if (!$pk && $pool) $pk = wa_util_pick($pdo, $pool, $ctm, $cfg['ord_days'], $nowTs, $rot, $cfg['ask_days'], true);   // 2) the standard follow-ups
            if (!$pk) { $sk('no approved utility message fits this person yet'); continue; }
            $tid = (int)$pk['t']['id']; if (!array_key_exists($tid, $cache)) $cache[$tid] = wa_ru_tpl_ctx($pdo, $pk['t']);
            [$tUse, $ctx] = $cache[$tid]; if (!$tUse || !$ctx) { $sk('utility message not approved on a number with room'); continue; }
            $cand = array_filter($ctx['nums'], fn($x) => $x['left'] > 0 && empty($numSt[(int)$x['id']]['rest_until'])); if (!$cand || $ctx['port_left'] <= 0) { $sk(array_filter($numSt, fn($z) => !empty($z['rest_until'])) ? 'numbers resting (delivery stalled)' : 'no number with room'); continue; }
            $hi = array_filter($cand, fn($x) => ($x['q'] ?? '') === 'GREEN'); $md = array_filter($cand, fn($x) => ($x['q'] ?? '') === 'YELLOW');   // best delivery first: High (green) quality numbers, then Medium; a Low / unknown number never sends these
            $own = (int)($c['last_number_id'] ?? 0); $cand = ($own > 0 && (isset($hi[$own]) || isset($md[$own]))) ? [$own => ($hi[$own] ?? $md[$own])] : ($hi ?: $md);   // the number this customer already chats with (if it is High / Medium and has room) keeps the conversation in one place; else the best High number, then Medium
            if (!$cand) { $sk('no High / Medium quality number with room'); continue; }
            uasort($cand, fn($x, $y) => ($x['used'] / max(1, $x['cap'])) <=> ($y['used'] / max(1, $y['cap']))); $num = reset($cand); $nid = (int)$num['id']; $tSend = $num['tm'] ?? $tUse;
            $seen[$ph] = 1; [$ok, $rr, $ecs] = wa_send_tpl_one($pdo, ['phone' => $ph, 'wa_id' => (string)($c['wa_id'] ?? ''), 'camp_optout' => (int)($c['camp_optout'] ?? 0)], $tSend, $num, $pk['vars'], 'recover');
            try { $pdo->prepare("INSERT IGNORE INTO wa_ru_log (camp_id, item_id, phone, kind, number_id, tpl_id, wamid, ok, err_code, first_code, err, at, attempt, vars_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
                ->execute([(int)$it['camp_id'], $iid, $ph, (string)$pk['kind'], $nid, (int)$tSend['id'], $ok ? (string)$rr : '', $ok ? 1 : 0, $ok ? 0 : (int)$ecs, $ec, $ok ? '' : mb_substr((string)$rr, 0, 190), $now, json_encode($pk['vars'], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { }
            $ctx['nums'][$nid]['left']--; $ctx['nums'][$nid]['used']++; $ctx['port_left']--; $cache[$tid][1] = $ctx; $left--;
            if ($ok) $res['sent']++; else { $res['failed']++;
                if ((int)$ecs === 131048) { setting_set($pdo, 'wa_ru_pause_until', (string)($nowTs + 6 * 3600)); wa_log($pdo, 'campaign', false, 'Utility follow-up paused for 6 hours: Meta flagged messages as spam (131048)'); $broke = true; break; }
                if (in_array((int)$ecs, [130429, 131056], true)) { $broke = true; break; } }
        }
        $rt = wa_ru_retry_tick($pdo, $nowTs, $left, $t0); $res['sent'] += $rt['sent']; $res['failed'] += $rt['failed']; $res['retried'] = $rt['sent'] + $rt['failed'];   // failed ones: one more try from another number
        setting_set($pdo, 'wa_ru_cursor', (string)(count($items) >= $lim || $broke ? $lastId : 0));   // a lap that read the whole list starts again from the first person
        arsort($why); $res['why'] = array_slice($why, 0, 6, true);
        if ($res['sent'] > 0) wa_log($pdo, 'campaign', true, 'Utility follow-up for failed marketing: ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed, ' . $res['skipped'] . ' left out');
    } catch (Throwable $e) { wa_log($pdo, 'campaign', false, 'Utility follow-up error: ' . $e->getMessage()); $res['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 140); }
    try { setting_set($pdo, 'wa_ru_last', json_encode(['at' => time(), 'note' => $res['note'], 'sent' => $res['sent'], 'failed' => $res['failed'], 'skipped' => $res['skipped'], 'looked' => $res['looked'], 'why' => $res['why']])); } catch (Throwable $e) { }
    return $res;
}

/** ROUTE: people the campaign already KNEW Meta would hold or block for marketing (held in the last days / stopped marketing) are skipped before sending: they get the utility follow-up instead of nothing. */
function wa_ru_known_sql(): string
{
    return "(i.status = 'skipped' AND (i.err_code = 131049 OR (i.err_code = 131026 AND EXISTS (SELECT 1 FROM wa_unreach u WHERE u.phone = i.phone AND u.code = 131050) AND NOT EXISTS (SELECT 1 FROM wa_unreach u2 WHERE u2.phone = i.phone AND u2.code = 131026))))";
}
/** Per campaign, for the colour line: utility sent by Module B, utility delivered (Module B + the campaign's own utility share). */
function wa_ru_counts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids || !wa_ru_tables($pdo)) return [];
    $in = implode(',', $ids); $o = []; $z = ['ru_sent' => 0, 'ru_del' => 0, 'ru_fail' => 0, 'mix_sent' => 0, 'mix_del' => 0];
    try { foreach ($pdo->query("SELECT r.camp_id, COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.camp_id IN ($in) GROUP BY r.camp_id")->fetchAll() as $r) $o[(int)$r['camp_id']] = ['ru_sent' => (int)$r['n'], 'ru_del' => (int)$r['d'], 'ru_fail' => (int)$r['f']] + $z; } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT i.camp_id, COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d FROM wa_camp_items i LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id IN ($in) AND i.variant = 'U' AND i.status = 'sent' GROUP BY i.camp_id")->fetchAll() as $r) { $c = (int)$r['camp_id']; $o[$c] = ($o[$c] ?? $z); $o[$c]['mix_sent'] = (int)$r['n']; $o[$c]['mix_del'] = (int)$r['d']; } } catch (Throwable $e) { }
    return $o;
}
/** Where the utility messages are at Meta (made in the last 3 days): approved / waiting / rejected / not sent yet, with the newest ones listed. */
function wa_ru_approval(PDO $pdo): array
{
    $o = ['total' => 0, 'approved' => 0, 'waiting' => 0, 'error' => 0, 'rejected' => 0, 'draft' => 0, 'list' => [], 'last_make' => (int)setting_get($pdo, 'wa_ru_draft_at', '0'), 'backfill' => wa_ru_backfill_counts($pdo), 'learn' => (function () use ($pdo) { wa_ru_load_util($pdo); if (!function_exists('wa_util_learn')) return null; $l = wa_util_learn($pdo); return ['appr' => $l['appr'], 'rej' => $l['rej'], 'rate' => $l['rate'], 'strict' => $l['strict'], 'bad' => $l['bad']]; })(), 'make_last' => json_decode((string)setting_get($pdo, 'wa_ru_make_last', ''), true)];
    try {
        $q = $pdo->prepare("SELECT t.id, t.name, t.created_at, t.relabelled_at, GROUP_CONCAT(w.status) AS st FROM wa_templates t LEFT JOIN wa_tpl_waba w ON w.tpl_id = t.id WHERE t.category = 'UTILITY' AND t.created_at >= ? GROUP BY t.id ORDER BY t.id DESC LIMIT 60");
        $q->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]);
        foreach ($q->fetchAll() as $r) {
            $st = array_filter(explode(',', strtoupper((string)$r['st']))); $o['total']++;
            if (!empty($r['relabelled_at'])) $k = 'rejected'; elseif (!$st) $k = 'draft'; elseif (in_array('APPROVED', $st, true)) $k = 'approved'; elseif (array_intersect(['PENDING', 'IN_APPEAL'], $st)) $k = 'waiting'; elseif (in_array('ERROR', $st, true)) $k = 'error'; else $k = 'rejected';
            $o[$k]++; if (count($o['list']) < 10) $o['list'][] = ['name' => (string)$r['name'], 'state' => $k, 'at' => (string)$r['created_at']];
        }
    } catch (Throwable $e) { }
    return $o;
}
/** Per campaign message: where its utility versions are at Meta — [tpl_id => ['approved' => n, 'waiting' => n, 'rejected' => n, 'draft' => n]]. */
function wa_ru_fam_states(PDO $pdo, array $tplIds): array
{
    wa_ru_load_util($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $tplIds)))); if (!$ids) return [];
    $o = []; foreach ($ids as $i) $o[$i] = ['approved' => 0, 'waiting' => 0, 'error' => 0, 'rejected' => 0, 'moved' => 0, 'paused' => 0, 'draft' => 0, 'items' => []];
    try {
        $q = $pdo->query("SELECT m.id AS tid, t.id AS uid, t.name AS nm, t.created_at AS ca, t.relabelled_at AS rl, GROUP_CONCAT(CONCAT(w.status, ':', LEFT(w.reason, 140)) SEPARATOR '|') AS st FROM wa_templates m JOIN wa_templates t ON t.category = 'UTILITY' AND t.family_id = COALESCE(NULLIF(m.family_id, 0), m.id)
            LEFT JOIN wa_tpl_waba w ON w.tpl_id = t.id WHERE m.id IN (" . implode(',', $ids) . ") GROUP BY m.id, t.id, t.name, t.created_at, t.relabelled_at ORDER BY t.id DESC");
        foreach ($q->fetchAll() as $r) {
            $rows = array_filter(explode('|', (string)$r['st'])); $stt = []; $why = '';
            foreach ($rows as $x) { $p2 = explode(':', $x, 2); $stt[] = strtoupper($p2[0]); }
            $reasonOf = function (array $want) use ($rows) { foreach ($rows as $x) { $p2 = explode(':', $x, 2); if (in_array(strtoupper($p2[0]), $want, true) && trim((string)($p2[1] ?? '')) !== '') return trim((string)$p2[1]); } return ''; };
            if (!empty($r['rl'])) { $k = 'moved'; }
            elseif (!$stt) { $k = 'draft'; }
            elseif (in_array('APPROVED', $stt, true)) { $k = 'approved'; }
            elseif (array_intersect(['PENDING', 'IN_APPEAL'], $stt)) { $k = 'waiting'; }
            elseif (in_array('ERROR', $stt, true)) { $k = 'error'; $why = $reasonOf(['ERROR']); }
            elseif (in_array('REJECTED', $stt, true)) { $k = 'rejected'; $why = $reasonOf(['REJECTED']); }
            else { $k = 'paused'; $why = $reasonOf(['PAUSED', 'DISABLED', 'FLAGGED']); }
            $o[(int)$r['tid']][$k]++; if (count($o[(int)$r['tid']]['items']) < 8) $o[(int)$r['tid']]['items'][] = ['name' => (string)$r['nm'], 'state' => $k, 'at' => (string)$r['ca'], 'why' => mb_substr($why, 0, 140)];
        }
    } catch (Throwable $e) { }
    return $o;
}
/** How many of the people a utility message could really be sent to (its variables must be true for them): an order message needs an order, a perfume message an enquiry, a name-only message fits everybody.
 *  $sp = wa_util_split() of the people: ask / order / none. $mine = a version of the campaign's own message (it may use an enquiry OR an order). */
function wa_ru_fit(array $labels, int $nvars, array $sp, int $tot, bool $mine): int
{
    $labels = array_map('strtolower', array_map('strval', $labels)); $ask = (int)($sp['ask'] ?? 0); $ord = (int)($sp['order'] ?? 0);
    if ($nvars <= 0 || ($nvars === 1 && (!$labels || $labels === ['name']))) return $tot;   // only the name (or nothing): everybody
    if (in_array('order', $labels, true)) return $ord;
    if (in_array('perfume', $labels, true)) return $mine ? $ask + $ord : $ask;
    if (in_array('date', $labels, true)) return $ask;
    return $mine ? $ask + $ord : $ask;
}
/** Campaigns started again "as utility": what happened since — waiting, utility sent / delivered, left out because no chosen message fits. [camp_id => [...]] */
function wa_ru_restart_stats(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return []; $o = [];
    try {
        foreach ($pdo->query("SELECT id, extra FROM wa_campaigns WHERE id IN (" . implode(',', $ids) . ") AND extra LIKE '%u_restart%'")->fetchAll() as $r) {
            $x = json_decode((string)$r['extra'], true); $rs = is_array($x) ? ($x['u_restart'] ?? null) : null; if (!is_array($rs) || empty($rs['at'])) continue; $cid = (int)$r['id']; $at = (string)$rs['at'];
            $q = $pdo->prepare("SELECT SUM(status IN ('queued', 'sending')) AS w, SUM(variant = 'U' AND status = 'sent' AND sent_at >= ?) AS us, SUM(COALESCE(variant, 'M') <> 'U' AND status = 'sent' AND sent_at >= ? AND tries <= 1 AND 0) AS ms, SUM(status = 'skipped' AND err IN ('No chosen utility message fits this person', 'Already delivered a message of ours')) AS k, SUM(status = 'failed' AND variant = 'U' AND sent_at >= ?) AS uf FROM wa_camp_items WHERE camp_id = ?");
            $q->execute([$at, $at, $at, $cid]); $a = $q->fetch();
            $d = $pdo->prepare("SELECT COUNT(*) FROM wa_camp_items i JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.camp_id = ? AND i.variant = 'U' AND i.sent_at >= ? AND m.status IN ('delivered', 'read')"); $d->execute([$cid, $at]);
            $o[$cid] = ['at' => $at, 'n' => (int)($rs['n'] ?? 0), 'waiting' => (int)$a['w'], 'u_sent' => (int)$a['us'], 'u_del' => (int)$d->fetchColumn(), 'left_out' => (int)$a['k']];
        }
    } catch (Throwable $e) { }
    return $o;
}
/** For the screen: each number with its utility delivery of the last 3 hours and whether it is resting. */
function wa_ru_number_view(PDO $pdo): array
{
    $st = wa_ru_numbers($pdo, time(), false); $o = [];
    try { foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) { $id = (int)$n['id']; if (!isset($st[$id])) continue; $o[] = ['label' => (string)($n['label'] !== '' ? $n['label'] : ($n['display'] ?: 'Number ' . $id)), 'n' => $st[$id]['n'], 'pct' => $st[$id]['pct'], 'rest_until' => $st[$id]['rest_until'] ?? 0]; } } catch (Throwable $e) { }
    return $o;
}
/** Approved utility versions of the messages used by today's campaigns (remembered for 2 minutes). */
function wa_ru_fam_count(PDO $pdo): int
{
    wa_ru_load_util($pdo);
    $c = json_decode((string)setting_get($pdo, 'wa_ru_fam', ''), true); if (is_array($c) && (int)($c['at'] ?? 0) > time() - 120) return (int)$c['n'];
    $n = 0; $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
    try { $q = $pdo->prepare("SELECT DISTINCT tpl_id FROM wa_campaigns WHERE COALESCE(started_at, created_at) >= ? LIMIT 30"); $q->execute([$from]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $tp) if (function_exists('wa_util_usable')) $n += count(wa_util_usable($pdo, (int)$tp)); } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_ru_fam', json_encode(['n' => $n, 'at' => time()])); } catch (Throwable $e) { }
    return $n;
}
/** What is waiting today (the business day), by reason — remembered for 2 minutes. */
function wa_ru_diag(PDO $pdo, int $ordDays): array
{
    $c = json_decode((string)setting_get($pdo, 'wa_ru_diag', ''), true); if (is_array($c) && (int)($c['at'] ?? 0) > time() - 120) return $c;
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
    $o = ['failed_total' => 0, 'never' => 0, 'now' => 0, 'after_rec' => 0, 'util_ok' => 0, 'util_no' => 0, 'done' => 0, 'from' => $from, 'at' => time()];
    try {
        $q = $pdo->prepare("SELECT i.err_code, COUNT(*) AS n FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE (c.started_at >= ? OR i.sent_at >= ?) AND COALESCE(i.variant, 'M') <> 'U' AND (i.status = 'failed' OR (i.status IN ('retry', 'sent') AND i.last_status = 'failed') OR " . wa_ru_known_sql() . ") GROUP BY i.err_code"); $q->execute([$from, $from]);
        foreach ($q->fetchAll() as $r) { $n = (int)$r['n']; $o['failed_total'] += $n; $cl = wa_ru_class((int)$r['err_code']); if ($cl === 'never') $o['never'] += $n; elseif ($cl === 'now') $o['now'] += $n; else $o['after_rec'] += $n; }
        if (wa_ru_tables($pdo)) { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log WHERE at >= ?"); $q->execute([$from]); $o['done'] = (int)$q->fetchColumn(); }
        $q = $pdo->prepare("SELECT DISTINCT i.phone FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE (c.started_at >= ? OR i.sent_at >= ?) AND COALESCE(i.variant, 'M') <> 'U' AND (i.status = 'failed' OR (i.status IN ('retry', 'sent') AND i.last_status = 'failed')) AND i.err_code NOT IN (" . (function_exists('wa_rec_never_sql') ? implode(',', array_diff(WA_REC_NEVER, WA_RU_MARKETING_ONLY)) : '0') . ") LIMIT 6000"); $q->execute([$from, $from]);
        $ph = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
        if ($ph && function_exists('wa_util_split')) { $sp = wa_util_split($pdo, $ph, $ordDays, (int)wa_ru_cfg($pdo)['ask_days']); $o['util_ok'] = (int)$sp['ask'] + (int)$sp['order'] + (int)$sp['none']; $o['util_no'] = 0; }
    } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_ru_diag', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** Everything the status line / card shows: settings, the last round, and how it did in a time range (default: the business day). */
function wa_ru_status(PDO $pdo, string $from = '', string $to = ''): array
{
    $cfg = wa_ru_cfg($pdo); $last = null; try { $l = json_decode((string)setting_get($pdo, 'wa_ru_last', ''), true); if (is_array($l)) $last = $l; } catch (Throwable $e) { }
    $from = $from !== '' ? $from : (function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00')); $to = $to !== '' ? $to : date('Y-m-d H:i:s', time() + 60);
    $o = ['tried' => 0, 'delivered' => 0, 'failed' => 0, 'waiting' => 0, 'by_kind' => ['ask' => 0, 'order' => 0]];
    if (wa_ru_tables($pdo)) {
        try { $q = $pdo->prepare("SELECT r.kind, COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.at >= ? AND r.at < ? GROUP BY r.kind"); $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $r) { $o['tried'] += (int)$r['n']; $o['delivered'] += (int)$r['d']; $o['failed'] += (int)$r['f']; if (isset($o['by_kind'][(string)$r['kind']])) $o['by_kind'][(string)$r['kind']] = (int)$r['n']; } } catch (Throwable $e) { }
        $o['waiting'] = max(0, $o['tried'] - $o['delivered'] - $o['failed']);
    }
    return ['on' => $cfg['on'], 'paused' => $cfg['pause_until'] > time(), 'hourly' => $cfg['hourly'], 'wait_min' => $cfg['wait_min'], 'ord_days' => $cfg['ord_days'], 'pool' => count(wa_ru_pool($pdo)), 'fam' => wa_ru_fam_count($pdo), 'pool_made' => count(wa_ru_pool_made($pdo)), 'pool_total' => function_exists('wa_util_pool_defs') ? count(wa_util_pool_defs()) : 6, 'diag' => ($dg = wa_ru_diag($pdo, $cfg['ord_days'])), 'plan' => wa_ru_plan($pdo, $cfg, $dg), 'numbers' => wa_ru_number_view($pdo), 'approval' => wa_ru_approval($pdo), 'ask_days' => $cfg['ask_days'], 'make_on' => $cfg['make_on'], 'range' => $o, 'last' => $last, 'now' => time()];
}

}   // function_exists guard
