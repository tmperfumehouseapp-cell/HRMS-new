<?php
/**
 * wa_dq.php — 📅 The daily plan: ALL campaigns are one queue (owner, 11 Nov 2026).
 *
 *  - One shared cap for every campaign together: wa_dq_hourly (default 10,000) DELIVERED campaign messages in any rolling hour (counted as delivered + waiting-for-receipt × the recent delivery rate, so we push more when Meta delivers fewer). When it is used the campaigns wait for the next hour by themselves.
 *    ⚡ Send fast campaigns are the owner's explicit "now" and are not held by it (their sends still count in the hour).
 *  - Within a campaign the people who wrote to us most recently go first (Meta holds messages to people who do not engage).
 *  - A person is not messaged twice: the sender already skips whoever got a campaign message in the last wa_camp_gap_days (default 3), who just bought, or ignored the last 2 campaigns.
 *    A new campaign simply joins the queue (the sender looks at every running campaign every round).
 *  - 🔁 Recovery messages (failed people sent again from another number, who = 'recover') COUNT in the same hour and day as campaign messages, and they go first: the recovery sender is not held by this cap,
 *    so campaigns get what is left of the hour / day.
 *  - Meta's own 24-hour room (with our margin) stays the ceiling; the number rules (same number for people who wrote to it, the rest by room, Medium = High) are unchanged.
 *  - 🧠 Hour-aware (wa_dq_smart 1): it LEARNS how well each hour of the day delivers (messages older than 2 hours from the last 14 days, today counted double; a message that was READ counts double — an hour whose people open the message is worth more than one that is only delivered) and scales that hour's allowance:
 *    a good hour gets up to 3× the cap, a weak one down to 0.3× (never zero, so every hour keeps being measured). Until 4 hours have 300+ messages of data it stays flat. Score = hour's worth (delivered + read per message pushed) ÷ the overall worth.
 *  - 🤖 Auto day cap (wa_dq_auto 1): once at the start of every business day the plan judges yesterday — did the delivery rate hold up against the 7 days before, and did yesterday really use the cap? Held up and used (90%+) →
 *    the day cap goes UP 5,000 (max wa_dq_daily_max 90,000 = Meta's 90% margin); delivery fell 8+ points → DOWN 5,000 (15+ points → 10,000); otherwise it stays. Never below wa_dq_daily_min 40,000. The number saved on the card is the start / manual value.
 *  - 💰 Money limit (wa_dq_budget, ₹ per business day, 0 = off): the estimated spend of the day (delivered + waiting-for-receipt × the live delivery rate, × the marketing rate) stops the plan when it is reached.
 *  - 🛑 Guards against over-pushing: "good" delivery also needs wa_dq_good_floor (45%) — a falling usual range can no longer make a weak rate look good — and an hour never pushes more than wa_dq_push_mult (1.5) × its delivered target.
 * Settings: wa_dq_on 1 · wa_dq_hourly 10000 · wa_dq_daily 75000 (campaigns per business day: Meta's room is also used by recovery retries, order updates and follow-ups, so campaigns leave about 15,000 of it free).   Route (in wa_camp.php): wa.dq_set POST {on, hourly}
 */
if (!function_exists('wa_dq_cfg')) {

function wa_dq_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    $base = max(1000, min(1000000, (int)$g('wa_dq_daily', '75000'))); $auto = $g('wa_dq_auto', '0') === '1'; $dmin = max(1000, (int)$g('wa_dq_daily_min', '40000')); $dmax = max($dmin, $base, (int)$g('wa_dq_daily_max', '90000')); $daily = $base;   // the number the owner saved is never cut down by the automatic ceiling
    $av0 = (int)$g('wa_dq_daily_auto', '0'); if ($av0 > $dmax) $dmax = min(1000000, $av0);
    if ($auto) { $av = (int)$g('wa_dq_daily_auto', '0'); if ($av > 0) $daily = max($dmin, min($dmax, $av)); }
    return ['on' => $g('wa_dq_on', '1') !== '0', 'hourly' => max(500, min(60000, (int)$g('wa_dq_hourly', '10000'))), 'daily' => $daily, 'base' => $base, 'auto' => $auto, 'dmin' => $dmin, 'dmax' => $dmax, 'smart' => $g('wa_dq_smart', '1') !== '0', 'budget' => max(0, (int)$g('wa_dq_budget', '0')), 'pace' => $g('wa_dq_pace', '1') !== '0', 'good_floor' => max(0.0, min(0.95, (float)$g('wa_dq_good_floor', '0.45'))), 'push_mult' => max(1.0, min(5.0, (float)$g('wa_dq_push_mult', '1.5')))];
}
/** Pushed / delivered campaign messages in a time range (for the day-cap judgement). */
function wa_dq_counts(PDO $pdo, string $a, string $b): array
{
    try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ?"); $q->execute([$a, $b]); $r = $q->fetch(); return [(int)$r['n'], (int)$r['d']]; } catch (Throwable $e) { return [0, 0]; }
}
/** 🤖 Once per business day: raise or lower the day cap from how yesterday delivered. Keeps a short log (shown on the card). */
function wa_dq_auto_eval(PDO $pdo): void
{
    $cfg = wa_dq_cfg($pdo); if (!$cfg['on'] || !$cfg['auto']) return;
    $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $key = substr($today, 0, 10);
    if ((string)setting_get($pdo, 'wa_dq_auto_day', '') === $key) return;
    setting_set($pdo, 'wa_dq_auto_day', $key);
    $t = strtotime($today); $f = 'Y-m-d H:i:s'; [$yn, $yd] = wa_dq_counts($pdo, date($f, $t - 86400), $today); [$bn, $bd] = wa_dq_counts($pdo, date($f, $t - 8 * 86400), date($f, $t - 86400));
    $cap = $cfg['daily']; $new = $cap; $why = '';
    if ($yn < 3000 || $bn < 3000) $why = 'not enough data yet — kept';
    else { $yr = $yd / $yn; $br = $bd / $bn; $txt = 'yesterday ' . round($yr * 100) . '% delivered vs ' . round($br * 100) . '% in the 7 days before';
        if ($yr < $br - 0.15) { $new = $cap - 10000; $why = $txt . ' — delivery fell a lot, lowered'; }
        elseif ($yr < $br - 0.08) { $new = $cap - 5000; $why = $txt . ' — delivery fell, lowered'; }
        elseif ($yn >= 0.9 * $cap && $yr >= $br - 0.03) { $new = $cap + 5000; $why = $txt . ' and the cap was used — raised'; }
        else $why = $txt . ' — kept'; }
    $new = max($cfg['dmin'], min($cfg['dmax'], $new)); setting_set($pdo, 'wa_dq_daily_auto', (string)$new);
    try { $log = json_decode((string)setting_get($pdo, 'wa_dq_auto_log', ''), true); $log = is_array($log) ? $log : []; $log[] = ['day' => $key, 'from' => $cap, 'to' => $new, 'why' => $why]; setting_set($pdo, 'wa_dq_auto_log', json_encode(array_slice($log, -14))); } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_stat', ''); } catch (Throwable $e) { }
}
/** What each hour of the day has delivered lately: [hour => ['n', 'rate', 'mult']] + overall + whether it is still learning. Messages older than 2 hours only (receipts have arrived), last 7 days, today counted double. Remembered for 10 minutes. */
function wa_dq_hour_scores(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_scores', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 600) return $hit;
        if (is_array($hit) && (int)setting_get($pdo, 'wa_dq_scores_lock', '0') > time() - 90) return $hit;   // another request is already recounting: use the last copy (six copies at once were freezing the database)
        setting_set($pdo, 'wa_dq_scores_lock', (string)time()); } catch (Throwable $e) { }
    $o = ['t' => time(), 'hours' => [], 'overall' => 0.0, 'measured' => 0, 'learning' => true];
    try {
        $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n, SUM(IF(ts >= ?, 2, 1)) AS wn, SUM(IF(ts >= ?, 2, 1) * ((status IN ('delivered', 'read')) + (status = 'read'))) AS wd, SUM(IF(ts >= ?, 2, 1) * (status IN ('delivered', 'read'))) AS wdel FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)");
        $q->execute([$today, $today, $today, date('Y-m-d H:i:s', time() - 14 * 86400), date('Y-m-d H:i:s', time() - 7200)]); $sn = 0.0; $sd = 0.0; $rows = [];
        foreach ($q->fetchAll() as $r) { $rows[(int)$r['h']] = $r; if ((int)$r['n'] >= 300) { $sn += (float)$r['wn']; $sd += (float)$r['wd']; $o['measured']++; } }
        $overall = $sn > 0 ? $sd / $sn : 0.0; $o['overall'] = round($overall, 3); $o['learning'] = $o['measured'] < 4 || $overall <= 0;
        foreach ($rows as $h => $r) { $rate = (float)$r['wn'] > 0 ? (float)$r['wd'] / (float)$r['wn'] : 0.0; $m = (!$o['learning'] && (int)$r['n'] >= 300) ? max(0.3, min(3.0, $rate / $overall)) : 1.0; $o['hours'][$h] = ['n' => (int)$r['n'], 'rate' => round((float)$r['wn'] > 0 ? (float)$r['wdel'] / (float)$r['wn'] : 0.0, 3), 'worth' => round($rate, 3), 'mult' => round($m, 2)]; }
    } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_scores', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** 🗄 A small cache in the database (not in a setting): the Campaigns page keeps its last answer here so it opens pre-filled and refreshes behind it. */
function wa_cpc_table(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cp_cache (k VARCHAR(40) NOT NULL PRIMARY KEY, v MEDIUMTEXT NOT NULL, at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); return $ok = true; } catch (Throwable $e) { return $ok = false; }
}
function wa_cpc_put(PDO $pdo, string $k, array $v): void
{
    if (!wa_cpc_table($pdo)) return; try { $j = json_encode($v, JSON_UNESCAPED_UNICODE); if ($j !== false && strlen($j) < 3000000) $pdo->prepare("REPLACE INTO wa_cp_cache (k, v, at) VALUES (?, ?, ?)")->execute([$k, $j, date('Y-m-d H:i:s')]); } catch (Throwable $e) { }
}
function wa_cpc_get(PDO $pdo, string $k): ?array
{
    if (!wa_cpc_table($pdo)) return null; try { $q = $pdo->prepare("SELECT v, at FROM wa_cp_cache WHERE k = ?"); $q->execute([$k]); $r = $q->fetch(); if (!$r) return null; $d = json_decode((string)$r['v'], true); return is_array($d) ? ['v' => $d, 'age' => max(0, time() - (int)strtotime((string)$r['at']))] : null; } catch (Throwable $e) { return null; }
}
/** The TOPIC of a message: its folder in the message library when it has one, else its name without the prefix, the version and the date (so "last_10days_followup" and "last_10days_followup_v2" are one topic). */
function wa_msg_topic(array $t): string
{
    $f = trim((string)($t['folder'] ?? '')); if ($f !== '') return mb_substr($f, 0, 40);
    $n = preg_replace(['/^house_aisensy_/', '/_v\d+$/', '/_\d{4,}$/', '/_\d{2,4}$/'], '', (string)$t['name']); $n = trim(str_replace('_', ' ', (string)$n)); return $n !== '' ? mb_substr($n, 0, 40) : 'Other';
}
/** 🏆 Which messages work best — through THREE lenses that are always shown together (last N days, campaign messages Meta accepted):
 *   🚚 delivery — delivered ÷ accepted.   💬 replies — delivered customers who wrote back within the attribution window (wa_attr_days, default 3) ÷ delivered.
 *   🛒 orders — orders placed in that window after a message was delivered; an order counts for the LAST campaign message delivered to that phone before it (never twice), with its ₹ value (shop_orders.total, not counting voided / refunded);
 *      money: cost = delivered × your billing rate, return = ₹ ÷ cost, cost per order.
 *  A "balanced" score (0–100) averages the rank of the three lenses (orders count 1.5×). Messages with fewer than 200 sends are listed but not ranked; order figures need 1,000 delivered to count.
 *  Also: what the best ones have in common (photo on top, text length, emoji, buttons, question, price) per lens. Remembered 10 min in the database cache. */
/** 🧹 Cancels heavy counts of the message ranking / order matching that have been running too long (they pile up on the database and freeze every page). Checked at most every 20 s. Only cancels the QUERY (the connection stays). */
function wa_kill_stale_counts(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    try {
        if ((int)setting_get($pdo, 'wa_kill_at', '0') > time() - 20) return; setting_set($pdo, 'wa_kill_at', (string)time());
        $me = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $info = (string)($r['Info'] ?? ''); $t = (int)($r['Time'] ?? 0); $id = (int)($r['Id'] ?? 0); if ($id === $me || $t < 45 || $info === '') continue;
            if (strpos($info, 'AS sg,') !== false || strpos($info, 'PARTITION BY o.shopify_id') !== false || (strpos($info, "COALESCE(NULLIF(i.tpl_used, 0), c.tpl_id) AS tid") !== false && strpos($info, 'GROUP BY') !== false)) { try { $pdo->exec('KILL QUERY ' . $id); } catch (Throwable $e) { } }
        }
    } catch (Throwable $e) { }
}
function wa_msg_rank(PDO $pdo, int $days = 14, int $maxAge = 600, bool $orders = true, string $seg = ''): array
{   // a page asked ($orders = false): ONE heavy count at a time on the whole server — everybody else gets "preparing…" at once (the counts were piling up on the database)
    if ($orders) return wa_msg_rank_run($pdo, $days, $maxAge, true, $seg);
    $r = wa_msg_rank_run($pdo, $days, $maxAge, false, $seg, true); if ($r !== null) return $r;
    // no copy yet: a page never runs the heavy count itself — it asks the background job for this window and shows "preparing…" (the job runs within a few minutes, the page asks again by itself)
    try { setting_set($pdo, 'wa_rank_want', json_encode([max(1, min(60, $days)), mb_substr($seg, 0, 60)])); setting_set($pdo, 'wa_dq_rank_at', '0'); setting_set($pdo, 'wa_dq_pre_at', '0'); } catch (Throwable $e) { }
    return ['days' => max(1, min(60, $days)), 'win' => 3, 'pending' => true, 'orders_pending' => false, 'rows' => [], 'tips' => [], 'overall' => ['n' => 0, 'dn' => 0, 'd' => 0.0, 'cost' => 0, 'ord' => 0, 'rev' => 0, 'roi' => null], 'orders_ok' => false, 'seg' => $seg, 'segs' => []];
}
function wa_msg_rank_run(PDO $pdo, int $days, int $maxAge, bool $orders, string $seg, bool $cacheOnly = false): ?array
{   // $orders = false (the page): never run the heavy order matching — use the last prepared one (the engine prepares it in the background); the answer is cached so the next open is instant
    $days = max(1, min(60, $days)); $win = max(1, min(14, (int)setting_get($pdo, 'wa_attr_days', '3'))); $seg = mb_substr(trim($seg), 0, 60); $ck = 'msg_rank4_' . $days . '_' . $win . ($seg !== '' ? '_' . md5(mb_strtolower($seg)) : '');
    $hit = wa_cpc_get($pdo, $ck); if ($hit && $hit['age'] < $maxAge && ($orders ? empty($hit['v']['orders_pending']) : true)) return $hit['v'];
    if ($cacheOnly) return $hit ? $hit['v'] : null;   // any copy is fine for a page, however old (the engine refreshes it); none → the caller decides
    $o = ['days' => $days, 'win' => $win, 'orders_pending' => false, 'rows' => [], 'tips' => [], 'overall' => ['n' => 0, 'dn' => 0, 'd' => 0.0, 'cost' => 0, 'ord' => 0, 'rev' => 0, 'roi' => null], 'orders_ok' => false, 'seg' => $seg, 'segs' => []];
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    foreach (['aud_type' => "VARCHAR(60) NOT NULL DEFAULT ''", 'aud_from' => 'DATE NULL', 'aud_to' => 'DATE NULL'] as $c0 => $d0) { try { $pdo->query("SELECT $c0 FROM wa_campaigns LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_campaigns ADD COLUMN $c0 $d0"); } catch (Throwable $e2) { } } }
    try {
        $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(30000) */ COALESCE(NULLIF(i.tpl_used, 0), c.tpl_id) AS tid, c.aud_type AS sg, COUNT(*) AS n, SUM(i.last_status IN ('delivered', 'read')) AS d, SUM(i.last_status = 'read') AS rd, SUM(i.err_code = 131049) AS held,
            SUM(i.last_status IN ('delivered', 'read') AND ct.last_in_at IS NOT NULL AND ct.last_in_at > i.sent_at AND ct.last_in_at < DATE_ADD(i.sent_at, INTERVAL $win DAY)) AS rep
            FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id LEFT JOIN wa_contacts ct ON ct.phone = i.phone WHERE i.wamid <> '' AND i.sent_at >= ?" . ($seg !== '' ? " AND c.aud_type = ?" : '') . " GROUP BY tid, sg");
        $q->execute($seg !== '' ? [$since, $seg] : [$since]); $acc = []; $bys = []; $segN = [];
        foreach ($q->fetchAll() as $r0) { $t0 = (int)$r0['tid']; $a0 = &$acc[$t0]; $a0 = $a0 ?? ['tid' => $t0, 'n' => 0, 'd' => 0, 'rd' => 0, 'held' => 0, 'rep' => 0]; foreach (['n', 'd', 'rd', 'held', 'rep'] as $k0) $a0[$k0] += (int)$r0[$k0]; unset($a0);
            $sg = trim((string)$r0['sg']); $sg = $sg !== '' ? $sg : '—'; $b0 = &$bys[$t0][$sg]; $b0 = $b0 ?? [0, 0, 0]; $b0[0] += (int)$r0['n']; $b0[1] += (int)$r0['d']; $b0[2] += (int)$r0['rep']; unset($b0); $segN[$sg] = ($segN[$sg] ?? 0) + (int)$r0['n']; }
        $rows = array_values(array_filter($acc, fn($x) => $x['n'] >= 30)); usort($rows, fn($x, $y) => ($y['d'] / $y['n']) <=> ($x['d'] / $x['n'])); $rows = array_slice($rows, 0, 200); arsort($segN); $o['segs'] = array_map(fn($k, $v) => ['name' => $k, 'n' => $v], array_keys($segN), array_values($segN));
    } catch (Throwable $e) { return $o; }
    $ord = []; $ock = 'msg_orders_' . $days . '_' . $win; $oc = $seg === '' ? wa_cpc_get($pdo, $ock) : null;   // order figures are not split per customer type (yet)
    if ($oc && is_array($oc['v']) && ($oc['age'] < 1800 || !$orders)) { foreach ($oc['v'] as $k => $v) $ord[(int)$k] = $v; $o['orders_ok'] = true; }   // the prepared order figures (refreshed at most every 30 minutes)
    elseif ($orders && $seg === '') {
    try {   // orders → the last campaign message delivered to that phone (within the window) before the order
        $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(20000) */ tid, COUNT(*) AS o, SUM(total) AS rev FROM (SELECT o.shopify_id, o.store_key, o.total, COALESCE(NULLIF(i.tpl_used, 0), c.tpl_id) AS tid,
                ROW_NUMBER() OVER (PARTITION BY o.shopify_id, o.store_key ORDER BY i.sent_at DESC) AS rn
            FROM shop_orders o JOIN wa_camp_items i ON i.phone COLLATE utf8mb4_unicode_ci = RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10) COLLATE utf8mb4_unicode_ci
                AND i.last_status IN ('delivered', 'read') AND i.wamid <> '' AND i.sent_at < o.created_at AND i.sent_at >= DATE_SUB(o.created_at, INTERVAL $win DAY)
            JOIN wa_campaigns c ON c.id = i.camp_id WHERE o.created_at >= ? AND o.financial_status NOT IN ('voided', 'refunded')) t WHERE rn = 1 GROUP BY tid");
        $q->execute([$since]); foreach ($q->fetchAll() as $r) $ord[(int)$r['tid']] = [(int)$r['o'], (float)$r['rev']]; $o['orders_ok'] = true;
    } catch (Throwable $e) { }
        if ($o['orders_ok']) wa_cpc_put($pdo, $ock, $ord);
    }
    if (!$orders && !$o['orders_ok'] && $seg === '') $o['orders_pending'] = true;   // not prepared yet: the page shows delivery and replies now and asks again
    $R = function_exists('wab_rates') ? wab_rates($pdo) : ['marketing' => 1.09, 'utility' => 0.15];
    $tn = []; foreach ($pdo->query("SELECT id, name, version, body, header_format, buttons, category, folder FROM wa_templates")->fetchAll() as $t) $tn[(int)$t['id']] = $t;
    $emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u'; $groups = []; $T = ['n' => 0, 'dn' => 0, 'cost' => 0.0, 'ord' => 0, 'rev' => 0.0]; $out = [];
    foreach ($rows as $r) {
        $t = $tn[(int)$r['tid']] ?? null; if (!$t) continue; $n = (int)$r['n']; $dn = (int)$r['d']; $rep = (int)$r['rep']; [$on, $rev] = $ord[(int)$r['tid']] ?? [0, 0.0];
        $body = (string)$t['body']; $len = mb_strlen($body); $hf = strtoupper((string)$t['header_format']); $btn = json_decode((string)$t['buttons'], true); $nb = is_array($btn) ? count($btn) : 0;
        $rate = strtoupper((string)$t['category']) === 'UTILITY' ? (float)$R['utility'] : (float)$R['marketing']; $cost = $dn * $rate;
        $f = ['header' => in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ? ['IMAGE' => 'a photo on top', 'VIDEO' => 'a video on top', 'DOCUMENT' => 'a PDF on top'][$hf] : 'text only', 'length' => $len <= 150 ? 'short text (150 letters or less)' : ($len <= 300 ? 'medium text (151–300 letters)' : 'long text (over 300 letters)'),
            'emoji' => preg_match($emoji, $body) ? 'an emoji' : 'no emoji', 'buttons' => $nb > 0 ? 'buttons' : 'no buttons', 'question' => strpos($body, '?') !== false ? 'a question in the text' : 'no question', 'price' => (strpos($body, '₹') !== false || stripos($body, 'rs.') !== false) ? 'a price (₹)' : 'no price'];
        if ($n >= 200) { foreach ($f as $k => $v) { $g = &$groups[$k][$v]; $g = $g ?? [0, 0, 0, 0, 0.0]; $g[0] += $n; $g[1] += $dn; $g[2] += $rep; $g[3] += $on; $g[4] += $rev; unset($g); } $T['n'] += $n; $T['dn'] += $dn; $T['cost'] += $cost; $T['ord'] += $on; $T['rev'] += $rev; }
        $out[] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'ver' => (int)$t['version'], 'n' => $n, 'dn' => $dn, 'd' => round($dn / $n, 3), 'rd' => round((int)$r['rd'] / $n, 3), 'held' => round((int)$r['held'] / $n, 3),
            'rep' => $dn ? round($rep / $dn, 4) : 0, 'rep_n' => $rep, 'ord' => $on, 'rev' => round($rev), 'cost' => round($cost), 'roi' => $cost > 0 && $dn >= 1000 ? round($rev / $cost, 2) : null, 'cpo' => $on ? round($cost / $on) : null,
            'o1k' => $dn >= 1000 ? round($on * 1000 / $dn, 2) : null, 'low' => $n < 200, 'lowo' => $dn < 1000, 'by_seg' => array_map(fn($k, $v) => ['seg' => $k, 'n' => $v[0], 'd' => $v[0] ? round($v[1] / $v[0], 3) : 0, 'dn' => $v[1], 'rep_n' => $v[2], 'rep' => $v[1] ? round($v[2] / $v[1], 4) : 0], array_keys($bys[(int)$r['tid']] ?? []), array_values($bys[(int)$r['tid']] ?? [])), 'topic' => wa_msg_topic($t), 'chars' => $len, 'header' => $f['header'], 'category' => (string)$t['category'], 'body' => mb_substr($body, 0, 900), 'buttons' => $nb, 'features' => array_values($f)];
    }
    // the balanced score: where each message stands among the ranked ones on the three lenses
    $rk = fn(array $vals, $v) => count($vals) > 1 ? count(array_filter($vals, fn($x) => $x < $v)) / (count($vals) - 1) : 0.5;
    $ok = array_filter($out, fn($x) => !$x['low']); $vd = array_column($ok, 'd'); $vr = array_column($ok, 'rep'); $vo = array_filter(array_column($ok, 'o1k'), fn($x) => $x !== null);
    foreach ($out as &$x) { if ($x['low']) { $x['score'] = null; continue; } $w = 0; $sum = 0; $sum += $rk($vd, $x['d']); $w += 1; $sum += $rk($vr, $x['rep']); $w += 1; if ($o['orders_ok'] && $x['o1k'] !== null && count($vo) > 1) { $sum += 1.5 * $rk(array_values($vo), $x['o1k']); $w += 1.5; } $x['score'] = (int)round($sum / $w * 100); } unset($x);
    $o['rows'] = $out; $o['overall'] = ['n' => $T['n'], 'dn' => $T['dn'], 'd' => $T['n'] ? round($T['dn'] / $T['n'], 3) : 0.0, 'cost' => round($T['cost']), 'ord' => $T['ord'], 'rev' => round($T['rev']), 'roi' => $T['cost'] > 0 ? round($T['rev'] / $T['cost'], 2) : null];
    // what the best ones have in common, per lens (only when both sides have enough messages)
    $tips = [];
    foreach ($groups as $k => $gs) {
        $lens = ['d' => [], 'r' => [], 'o' => []]; foreach ($gs as $name => [$n, $dn, $rep, $on, $rev]) { if ($n >= 1000) $lens['d'][$name] = $dn / $n; if ($dn >= 1000) $lens['r'][$name] = $rep / $dn; if ($dn >= 1500 && $on >= 5) $lens['o'][$name] = $on * 1000 / $dn; }
        foreach (['d' => ['🚚', 0.05, 'abs', fn($v) => round($v * 100) . '% delivered'], 'r' => ['💬', 0.25, 'rel', fn($v) => round($v * 100, 1) . '% of delivered replied'], 'o' => ['🛒', 0.30, 'rel', fn($v) => round($v, 1) . ' orders per 1,000 delivered']] as $lk => [$em, $th, $mode, $fmt]) {
            $v = $lens[$lk]; if (count($v) < 2) continue; asort($v); $wn = array_key_first($v); $bn = array_key_last($v); $wv = $v[$wn]; $bv = $v[$bn]; $diff = $mode === 'abs' ? $bv - $wv : ($wv > 0 ? ($bv - $wv) / $wv : 1);
            if ($diff >= $th) $tips[] = ['diff' => $diff, 'text' => $em . ' With ' . $bn . ': ' . $fmt($bv) . ', against ' . $fmt($wv) . ' with ' . $wn . '.'];
        }
    }
    usort($tips, fn($a, $b) => $b['diff'] <=> $a['diff']); $o['tips'] = array_slice(array_column($tips, 'text'), 0, 6);
    wa_cpc_put($pdo, $ck, $o); return $o;
}
/** The usual RANGE of the hourly delivery rate over the last 7 days (each date+hour with 300+ messages, receipts given 2 hours): p25 / p50 / p75. Judging "good" or "low" against a range, not one number. Remembered for 10 min. */
function wa_dq_range(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_range', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 600) return $hit;
        if (is_array($hit) && (int)setting_get($pdo, 'wa_dq_range_lock', '0') > time() - 90) return $hit;   // another request is already recounting: use the last copy (seven copies at once were freezing the database)
        setting_set($pdo, 'wa_dq_range_lock', (string)time()); } catch (Throwable $e) { }
    $o = ['t' => time(), 'p25' => 0.0, 'p50' => 0.0, 'p75' => 0.0, 'n' => 0];
    try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY DATE(ts), HOUR(ts) HAVING n >= 300");
        $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 7200)]); $r = []; foreach ($q->fetchAll() as $x) $r[] = (int)$x['d'] / max(1, (int)$x['n']); sort($r);
        if (count($r) >= 8) { $pc = function (float $p) use ($r) { return $r[(int)max(0, min(count($r) - 1, round($p * (count($r) - 1))))]; }; $o = ['t' => time(), 'p25' => round($pc(0.25), 3), 'p50' => round($pc(0.5), 3), 'p75' => round($pc(0.75), 3), 'n' => count($r)]; } } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_range', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** The delivery rate RIGHT NOW: the last 4 hours (receipts of the last hour left out), else today so far, else yesterday. Remembered for 60 s. Returns ['rate' (or null), 'from' => 'last 4 h' | 'today' | 'yesterday']. */
function wa_dq_live(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_live', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 60) return $hit; } catch (Throwable $e) { }
    $f = 'Y-m-d H:i:s'; $now = time(); $today = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $t = strtotime($today); $o = ['t' => $now, 'rate' => null, 'from' => ''];
    foreach ([['last 4 h', date($f, $now - 4 * 3600), date($f, $now - 3600), 500], ['today', $today, date($f, $now - 3600), 500], ['yesterday', date($f, $t - 86400), $today, 1000]] as [$lab, $a, $b, $min]) {
        if (strtotime($b) <= strtotime($a)) continue; [$n, $d] = wa_dq_counts($pdo, $a, $b); if ($n >= $min) { $o['rate'] = round($d / $n, 3); $o['from'] = $lab; break; } }
    try { setting_set($pdo, 'wa_dq_live', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** 🛡 Safety net for "Meta limit counted per number": if we are past the SHARED limit's margin and a third of the campaign messages of the last 30 minutes were refused (anything but the per-person refusals),
 *  Meta is counting one shared pool after all → the mode goes back to 'shared' by itself and says so. Checked at most once a minute. */
function wa_limit_guard(PDO $pdo): void
{
    try {
        if ((string)setting_get($pdo, 'wa_limit_mode', 'shared') !== 'per_number') return;
        if ((int)setting_get($pdo, 'wa_limit_guard_at', '0') > time() - 60) return; setting_set($pdo, 'wa_limit_guard_at', (string)time());
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status = 'failed' AND err_code NOT IN (131049, 131026, 130472, 131050, 131047, 131021, 131051)) AS f FROM (SELECT status, err_code FROM wa_camp_items WHERE sent_at >= ? ORDER BY sent_at DESC LIMIT 300) t");
        $q->execute([date('Y-m-d H:i:s', time() - 1800)]); $r = $q->fetch(); $n = (int)$r['n']; $f = (int)$r['f'];
        $q = $pdo->prepare("SELECT COUNT(DISTINCT phone) FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 86400)]); $day = (int)$q->fetchColumn();
        if (!function_exists('wa_health_all') && is_file(__DIR__ . '/wa_console.php')) require_once __DIR__ . '/wa_console.php';
        $tiers = array_filter(array_column(wa_health_all($pdo, false), 'tier'), fn($t) => $t !== null); $shared = (int)floor(($tiers ? (in_array(-1, $tiers, true) ? 1000000 : max($tiers)) : 250) * 0.9);   // the margin of the SHARED limit
        if ($n >= 100 && $f / max(1, $n) >= 0.33 && $day >= $shared) { setting_set($pdo, 'wa_limit_mode', 'shared'); wa_log($pdo, 'campaign', false, 'Meta limit counting went back to SHARED: ' . $f . ' of the last ' . $n . ' messages were refused after ' . $day . ' people in 24 h'); try { setting_set($pdo, 'wa_dq_stat', ''); } catch (Throwable $e) { } }
    } catch (Throwable $e) { }
}
/** This hour's room (owner, 11 Nov): the hourly cap counts messages Meta DELIVERS. Messages still waiting for their receipt count at today's real delivery rate. The delivery rate is judged against its usual RANGE:
 *   good (at or above the middle of the range) → the hour's target grows with it (×1.0 up to ×2, on top of the hour-of-day score) and the sender is allowed to push more to deliver it;
 *   ok (between the low and the middle) → the normal target, half-compensating pushes;
 *   low (below the low end of the range) → NO extra pushing: pushes are sized as if delivery were normal, so we do not burn Meta's room on people it will not deliver to.
 *  The day cap counts pushes (Meta's own wall counts pushes) and keeps room for retries / order updates.
 *  Returns ['on', 'cap', 'used' (pushed this hour), 'exp' (expected delivered), 'rate', 'state', 'range', 'mult' (hour score), 'm_live', 'eff_cap', 'daily', 'day_used', 'day_left', 'left' (pushes still allowed), 'why']. Remembered for 15 s ($fresh: counted again). */
/** ⚖ Even pace: how many campaign messages are waiting to be sent (running campaigns, queued / retry), remembered for 60 s and counted by one request at a time. */
function wa_dq_waiting(PDO $pdo): int
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_wait', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 60) return (int)$hit['n'];
        if (is_array($hit) && (int)setting_get($pdo, 'wa_dq_wait_lock', '0') > time() - 30) return (int)$hit['n']; setting_set($pdo, 'wa_dq_wait_lock', (string)time()); } catch (Throwable $e) { $hit = null; }
    $n = (int)($hit['n'] ?? 0);
    try { $n = (int)$pdo->query("SELECT COUNT(*) FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE c.status = 'running' AND i.status IN ('queued', 'retry')")->fetchColumn(); setting_set($pdo, 'wa_dq_wait', json_encode(['t' => time(), 'n' => $n])); } catch (Throwable $e) { }
    return $n;
}
/** ⚖ Hours left today in which campaigns may send: to the end of the sending window, or (24 h window) to the end of the business day. At least half an hour. */
function wa_dq_hours_left(PDO $pdo): float
{
    $now = time(); $h = (int)date('G', $now) + (int)date('i', $now) / 60 + (int)date('s', $now) / 3600; [$w0, $w1] = function_exists('wa_camp_window') ? wa_camp_window($pdo) : [0, 24];
    if ($w0 === 0 && $w1 >= 24) { $from = function_exists('wa_bizday_start') ? strtotime(wa_bizday_start($pdo)) : strtotime(date('Y-m-d 10:00:00')); $end = $from + 86400; if ($end <= $now) $end += 86400; return max(0.5, ($end - $now) / 3600); }
    if ($h >= $w0 && $h < $w1) return max(0.5, $w1 - $h);
    return max(0.5, $w1 - $w0);
}
function wa_dq_left(PDO $pdo, bool $fresh = false): array
{
    static $memo = null, $at = 0; if (!$fresh && $memo !== null && microtime(true) - $at < 15) return $memo;
    try { wa_dq_auto_eval($pdo); wa_limit_guard($pdo); } catch (Throwable $e) { }
    $cfg = wa_dq_cfg($pdo); $used = 0; $dayUsed = 0; $exp = 0; $rate = 0.75; $mult = 1.0; $learning = true; $state = 'unknown'; $mLive = 1.0; $range = ['p25' => 0.0, 'p50' => 0.0, 'p75' => 0.0]; $rateUsed = 0.75; $spend = 0.0; $mr = function_exists('wab_rates') ? (float)wab_rates($pdo)['marketing'] : 1.09;
    if ($cfg['on'] && $cfg['smart']) { $sc = wa_dq_hour_scores($pdo); $learning = !empty($sc['learning']); $mult = (float)($sc['hours'][(int)date('G')]['mult'] ?? 1.0); }   // 🧠 this hour of the day delivers better / worse than average
    if ($cfg['on']) {
        $now = time(); $dH = 0; $pH = 0;
        try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'sent') AS p FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', $now - 3600)]); $r = $q->fetch(); $used = (int)$r['n']; $dH = (int)$r['d']; $pH = (int)$r['p']; } catch (Throwable $e) { }
        $lv = wa_dq_live($pdo); $rg = wa_dq_range($pdo); if ($lv['rate'] !== null) $rate = (float)$lv['rate'];
        $rate = max(0.15, min(0.95, $rate)); $exp = (int)round($dH + $pH * $rate);
        if ($lv['rate'] !== null && $rg['n'] >= 8 && $rg['p50'] > 0) { $range = $rg; $r0 = (float)$lv['rate'];
            if ($r0 >= $rg['p50'] && $r0 >= $cfg['good_floor']) { $state = 'good'; $mLive = max(1.0, min(2.0, $r0 / $rg['p50'])); $rateUsed = $r0; }
            elseif ($r0 >= $rg['p25'] || $r0 >= $rg['p50']) { $state = 'ok'; $rateUsed = $r0 >= $rg['p50'] ? $rg['p50'] : $rg['p50'] - ($rg['p50'] - $r0) * 0.5; }
            else { $state = 'low'; $rateUsed = $rg['p50']; } }
        else $rateUsed = max(0.5, $rate);
        try { $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([$from]); $dayUsed = (int)$q->fetchColumn(); $q = $pdo->prepare("SELECT SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'sent') AS p FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([$from]); $sr = $q->fetch(); $spend = ((int)$sr['d'] + (int)$sr['p'] * $rate) * $mr; } catch (Throwable $e) { }
    }
    $effCap = (int)round($cfg['hourly'] * $mult * $mLive); $rateUsed = max(0.15, min(0.95, $rateUsed)); $hourLeft = max(0, (int)ceil(($effCap - $exp) / $rateUsed)); $pushCap = (int)round($effCap * $cfg['push_mult']);
    // ⚖ EVEN PACE: send what is waiting evenly over the hours left, not in bursts. Target per hour = waiting ÷ hours left (+10 %), never above the cap, never below 1,000;
    // and in any 10 minutes at most a sixth of it (+30 %), so the hour is not sent in its first minutes and then left idle.
    $pace = ['on' => (bool)$cfg['pace'], 'waiting' => 0, 'hours_left' => 0.0, 'target' => 0, 'slice' => 0, 'used10' => 0];
    if ($cfg['on'] && $cfg['pace']) {
        try { $pace['waiting'] = wa_dq_waiting($pdo); $pace['hours_left'] = round(wa_dq_hours_left($pdo), 2);
            $dayLeftP = max(0, $cfg['daily'] - $dayUsed); $pace['day_left'] = $dayLeftP; $pace['amount'] = min($pace['waiting'], $dayLeftP);   // what can still go out today: the queue, but never more than what is left of the day's limit
            $target = (int)ceil($pace['amount'] / max(0.5, $pace['hours_left']) * 1.1); $pace['target'] = max(1000, $target);
            $pushCap = min($pushCap, $pace['target']);
            $pace['slice'] = (int)ceil($pushCap / 6 * 1.3); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE dir = 'out' AND who IN ('campaign', 'recover') AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 600)]); $pace['used10'] = (int)$q->fetchColumn();
        } catch (Throwable $e) { $pace['on'] = false; }
    }
    $hourLeft = max(0, min($hourLeft, $pushCap - $used)); if ($pace['on'] && $pace['slice'] > 0) $hourLeft = max(0, min($hourLeft, $pace['slice'] - $pace['used10'])); $dayLeft = max(0, $cfg['daily'] - $dayUsed);
    $moneyLeft = $cfg['budget'] > 0 ? max(0.0, $cfg['budget'] - $spend) : null; $moneyPush = $moneyLeft === null ? PHP_INT_MAX : (int)floor($moneyLeft / max(0.01, $mr * max(0.2, $rate)));
    $memo = ['pace' => $pace, 'on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $used, 'exp' => $exp, 'rate' => round($rate, 2), 'state' => $state, 'range' => $range, 'm_live' => round($mLive, 2), 'mult' => round($mult, 2), 'eff_cap' => $effCap, 'learning' => $learning, 'smart' => $cfg['smart'], 'daily' => $cfg['daily'], 'day_used' => $dayUsed, 'day_left' => $dayLeft, 'left' => min($hourLeft, $dayLeft, $moneyPush), 'spend' => (int)round($spend), 'budget' => $cfg['budget'], 'push_cap' => $pushCap, 'why' => ($moneyLeft !== null && $moneyLeft <= 0) ? 'money' : ($dayLeft <= 0 ? 'day' : ($hourLeft <= 0 ? 'hour' : ''))]; $at = microtime(true); return $memo;
}
/** 🏦 Meta's OWN spend (pricing analytics of every WhatsApp account, by calendar day, all message kinds) from what the engine already fetched (read from the cache only, never waits for Meta; the engine refreshes it hourly),
 *  and the real price per delivered message yesterday (Meta's ₹ ÷ delivered template messages that day). Returns ['today', 'yday', 'eff', 'at', 'error'] (null = not available). Meta's figures arrive with a delay of a few hours. */
function wa_meta_spend_cached(PDO $pdo, bool $fetch = false): array
{
    $o = ['today' => null, 'yday' => null, 'eff' => null, 'at' => 0, 'error' => ''];
    if (!function_exists('wab_meta')) return $o;
    $from = date('Y-m-d', time() - 2 * 86400); $to = date('Y-m-d'); $c = null;
    if ($fetch) { try { $c = wab_meta($pdo, $from, $to); } catch (Throwable $e) { $c = null; } }
    else { try { $c = json_decode((string)setting_get($pdo, 'wab_meta_' . md5($from . $to), ''), true); } catch (Throwable $e) { $c = null; } }
    if (!is_array($c)) return $o;
    $o['at'] = (int)($c['at'] ?? 0); $o['error'] = (string)($c['error'] ?? ''); $days = (array)($c['days'] ?? []);
    if ($days) { $o['today'] = (float)($days[$to] ?? 0); $o['yday'] = (float)($days[date('Y-m-d', time() - 86400)] ?? 0);
        if ($o['yday'] > 0) { try { $q = $pdo->prepare("SELECT SUM(status IN ('delivered', 'read')) FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND ts >= ? AND ts < ?"); $q->execute([date('Y-m-d 00:00:00', time() - 86400), date('Y-m-d 00:00:00')]); $dl = (int)$q->fetchColumn(); if ($dl >= 1000) $o['eff'] = round($o['yday'] / $dl, 2); } catch (Throwable $e) { } } }
    return $o;
}
/** The plain sentence for why the plan is holding the campaigns right now. */
function wa_dq_why_text(array $d): string
{
    $w = (string)($d['why'] ?? '');
    if ($w === 'money') return 'Daily plan: today’s money limit of ₹' . number_format((int)($d['budget'] ?? 0)) . ' is reached — it continues tomorrow.';
    if ($w === 'day') return 'Daily plan: today’s ' . number_format((int)($d['daily'] ?? 0)) . ' campaign messages are used — it continues tomorrow.';
    return 'Daily plan: this hour’s allowance (about ' . number_format((int)($d['push_cap'] ?? $d['cap'] ?? 0)) . ' pushes) is used by all campaigns together — it continues in the next hour.';
}
/** Campaign messages by hour of the day: pushed (accepted by Meta), delivered / read — for the current business day and for the last 7 days together (which hours suit best). Remembered for 60 s. */
function wa_dq_hours(PDO $pdo, int $maxAge = 900): array
{   // served from the database cache; the engine rebuilds it in the background every 2 minutes, so the page never waits for the heavy count
    $hit = wa_cpc_get($pdo, 'dq_hours'); if ($hit && $hit['age'] < $maxAge) return $hit['v'];
    $o = wa_dq_hours_build($pdo); wa_cpc_put($pdo, 'dq_hours', $o); return $o;
}
function wa_dq_precompute(PDO $pdo): void
{
    try { if ((int)setting_get($pdo, 'wa_dq_pre_at', '0') > time() - 120) return; setting_set($pdo, 'wa_dq_pre_at', (string)time()); wa_dq_hours($pdo, 120); if ((int)setting_get($pdo, 'wa_dq_rank_at', '0') <= time() - 1200) { setting_set($pdo, 'wa_dq_rank_at', (string)time()); $want = json_decode((string)setting_get($pdo, 'wa_rank_want', ''), true); if (is_array($want) && count($want) === 2) { setting_set($pdo, 'wa_rank_want', ''); wa_msg_rank($pdo, (int)$want[0], 1200, true, (string)$want[1]); } else { $n = (int)setting_get($pdo, 'wa_dq_pre_n', '0'); setting_set($pdo, 'wa_dq_pre_n', (string)($n + 1)); wa_msg_rank($pdo, [14, 7, 14, 30][$n % 4], 1200, true); } }   /* the heavy message ranking: one window every 20 minutes (was every 2) */ wa_meta_spend_cached($pdo, true); } catch (Throwable $e) { }
}
function wa_dq_hours_build(PDO $pdo): array
{
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $to = date('Y-m-d H:i:s', strtotime($from) + 86400);
    $o = ['t' => time(), 'from' => $from, 'to' => $to, 'start_hour' => (int)date('G', strtotime($from)), 'today' => [], 'week' => []];
    $run = function (string $a, string $b) use ($pdo) { $r = []; try { $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'read') AS rd FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)"); $q->execute([$a, $b]); foreach ($q->fetchAll() as $x) $r[(int)$x['h']] = [(int)$x['n'], (int)$x['d'], (int)$x['rd']]; } catch (Throwable $e) { } return $r; };
    $o['mult'] = []; if (wa_dq_cfg($pdo)['smart']) foreach ((wa_dq_hour_scores($pdo)['hours'] ?? []) as $h => $x) $o['mult'][(int)$h] = $x['mult'];
    // the plan for every hour of the day: delivered target (hourly number × the hour's score) and the pushes that takes at that hour's delivery rate; hours outside the sending window get 0
    $cfg2 = wa_dq_cfg($pdo); $sc2 = wa_dq_hour_scores($pdo); [$w0, $w1] = function_exists('wa_camp_window') ? wa_camp_window($pdo) : [0, 24]; $o['plan'] = []; $o['cur'] = (int)date('G'); $o['hourly'] = $cfg2['hourly']; $o['daily'] = $cfg2['daily']; $o['smart'] = $cfg2['smart']; $o['on'] = $cfg2['on'];
    for ($h = 0; $h < 24; $h++) { $in = $h >= $w0 && $h < $w1; $m = ($cfg2['smart'] && empty($sc2['learning'])) ? (float)($sc2['hours'][$h]['mult'] ?? 1.0) : 1.0; $rt = (float)($sc2['hours'][$h]['rate'] ?? 0); if ($rt <= 0) $rt = 0.75; $rt = max(0.3, min(0.95, $rt)); if ($h === $o['cur']) $m *= (float)(wa_dq_left($pdo)['m_live'] ?? 1.0); $d = $in ? (int)round($cfg2['hourly'] * $m) : 0; $o['plan'][$h] = ['d' => $d, 'p' => $d ? (int)ceil($d / $rt) : 0]; }
    $o['today'] = $run($from, $to); $o['week'] = $run(date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() + 60));
    return $o;
}
/** The plan card: cap, used, people waiting in the one queue, Meta room. Remembered for 30 s. */
function wa_dq_auto_last(PDO $pdo): ?array
{
    try { $log = json_decode((string)setting_get($pdo, 'wa_dq_auto_log', ''), true); return is_array($log) && $log ? end($log) : null; } catch (Throwable $e) { return null; }
}
function wa_dq_status(PDO $pdo): array
{
    $cfg = wa_dq_cfg($pdo);
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_stat', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 30 && ($hit['cap'] ?? 0) === $cfg['hourly'] && ($hit['daily'] ?? 0) === $cfg['daily'] && ($hit['on'] ?? null) === $cfg['on']) return $hit; } catch (Throwable $e) { }
    $l = wa_dq_left($pdo); $o = ['t' => time(), 'pace' => $l['pace'] ?? null, 'on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $l['used'], 'exp' => $l['exp'], 'rate' => $l['rate'], 'state' => $l['state'], 'range' => $l['range'], 'm_live' => $l['m_live'], 'mult' => $l['mult'], 'eff_cap' => $l['eff_cap'], 'learning' => $l['learning'], 'smart' => $l['smart'], 'left' => $l['left'], 'daily' => $l['daily'], 'day_used' => $l['day_used'], 'why' => $l['why'], 'spend' => $l['spend'], 'budget' => $l['budget'], 'push_cap' => $l['push_cap'], 'limit_mode' => (string)setting_get($pdo, 'wa_limit_mode', 'shared'), 'early' => (string)setting_get($pdo, 'wa_dq_early', '1') !== '0', 'auto' => $cfg['auto'], 'dmin' => $cfg['dmin'], 'dmax' => $cfg['dmax'], 'base' => $cfg['base'], 'auto_last' => wa_dq_auto_last($pdo), 'people' => 0, 'campaigns' => 0, 'gap_days' => max(0, (int)setting_get($pdo, 'wa_camp_gap_days', '3'))];
    try { $r = $pdo->query("SELECT COUNT(DISTINCT i.phone) AS p, COUNT(DISTINCT i.camp_id) AS c FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE c.status = 'running' AND i.status IN ('queued', 'retry')")->fetch(); $o['people'] = (int)$r['p']; $o['campaigns'] = (int)$r['c']; } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_stat', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}

}
