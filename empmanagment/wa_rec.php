<?php
/**
 * wa_rec.php — 🔁 Recover failed campaign messages (owner, 11 Nov 2026).
 *
 * THE IDEA IN PLAIN WORDS
 *  - A person whose campaign message FAILED for a reason another number can fix gets the same message again from a DIFFERENT number, slowly (up to wa_rec_hourly = 5,000 an hour,
 *    one by one, never inside the running campaign pool and never inside ⚡ Send fast). Three attempts per person in all (the first send is attempt 1), at least an hour apart.
 *  - Attempt 3 (or attempt 2 when Meta says the message itself has a problem) uses an approved ALTERNATIVE wording of the same message (same variables, same offer).
 *  - Alternatives (max 2 per message: versions 2 and 3 of its family) are written by Claude and sent to Meta for approval by themselves for every message that has failures today;
 *    the normal auto-submit engine sends them to every WhatsApp account and the campaign sender already knows approved alternatives (wa_fam_m_alts).
 *  - NEVER retried: not on WhatsApp (131026), the customer stopped marketing / own number / unsupported (131050 131047 131021 131051), a wrong request (100 131008 131009),
 *    Meta's experiment holdback (130472) and the spam flag (131048 — it also pauses recovery for 6 hours). Held by Meta (131049) keeps its own "one more try after a day" rule.
 *  - Never to people who were delivered another way, wrote back, opted out, or already have an attempt in the last hour.
 *  - Every attempt is written to wa_rec_log (who, which number, which message version, when). Delivery comes from the same Meta receipts as every message (wa_messages.status by wamid),
 *    so "recovered" only ever means Meta said delivered / read.
 * Settings: wa_rec_on 1 · wa_rec_hourly 5000 · wa_rec_alts 1 · wa_rec_pause_until (set by itself)
 * Routes (in wa_camp.php): wa.rec_report GET {id} · wa.rec_set POST {on}
 */
if (!function_exists('wa_rec_tables')) {

if (!defined('WA_REC_NEVER')) { define('WA_REC_NEVER', [131026, 131050, 131047, 131021, 131051, 100, 131008, 131009, 130472, 131048]); define('WA_REC_HELD', [131049]); define('WA_REC_TPL', [132000, 132001, 132005, 132007, 132012, 132015, 132016]); }

function wa_rec_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_rec_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, camp_id INT NOT NULL, item_id BIGINT NOT NULL, phone VARCHAR(20) NOT NULL, attempt TINYINT NOT NULL DEFAULT 2,
            number_id INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, is_alt TINYINT NOT NULL DEFAULT 0, wamid VARCHAR(120) NOT NULL DEFAULT '', ok TINYINT NOT NULL DEFAULT 1, err_code INT NOT NULL DEFAULT 0,
            err VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY k_item (item_id), KEY k_camp (camp_id, at), KEY k_at (at), KEY k_wamid (wamid), KEY k_phone (phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // the table must use the SAME collation as the rest of the database (wa_messages …): a table made with the server default (utf8mb4_0900_ai_ci) could not be joined to them — error 1267 "Illegal mix of collations", which kept every recovery round from starting
        try { $c = (string)$pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_rec_log'")->fetchColumn(); if ($c !== '' && $c !== 'utf8mb4_unicode_ci') $pdo->exec("ALTER TABLE wa_rec_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e2) { }
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function wa_rec_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    return ['on' => $g('wa_rec_on', '1') !== '0', 'hourly' => max(10, min(20000, (int)$g('wa_rec_hourly', '5000'))), 'alts' => $g('wa_rec_alts', '1') !== '0', 'pause_until' => (int)$g('wa_rec_pause_until', '0'), 'max_attempts' => 3, 'gap_min' => 60, 'held_on' => $g('wa_rec_held', '1') !== '0', 'held_off_until' => (int)$g('wa_rec_held_off_until', '0')];
}
/** 'never' | 'held' | 'tpl' (the message itself has a problem → use an alternative) | 'now' (another number can fix it). */
function wa_rec_class(int $ec): string
{
    if (in_array($ec, WA_REC_NEVER, true)) return 'never';
    if (in_array($ec, WA_REC_HELD, true)) return 'held';
    if (in_array($ec, WA_REC_TPL, true)) return 'tpl';
    return 'now';
}
function wa_rec_never_sql(): string { return implode(',', WA_REC_NEVER) . ',' . implode(',', WA_REC_HELD); }

/** The template to send plus the numbers that may send it (not the ones this person already failed on). [$tpl, $ctx] or [null, null]. */
function wa_rec_tpl_ctx(PDO $pdo, array $t, bool $preferAlt, int $altIdx): array
{
    $alts = []; if (function_exists('wa_fam_m_alts')) { try { $alts = array_values(array_filter(wa_fam_m_alts($pdo, $t), fn($a) => !empty($a['ok_wabas']))); } catch (Throwable $e) { } }
    $by = []; $base = $t;
    if ($preferAlt && $alts) { $a = $alts[$altIdx % count($alts)]; foreach ((array)$a['ok_wabas'] as $wb) $by[(string)$wb] = $a; $base = $a; }
    else { foreach ((array)$t['ok_wabas'] as $wb) $by[(string)$wb] = $t; foreach ($alts as $alt) foreach ((array)$alt['ok_wabas'] as $wb) if (!isset($by[(string)$wb])) $by[(string)$wb] = $alt; }
    if (!$by) return [null, null];
    $base['by_waba'] = $by; $base['ok_wabas'] = array_map('strval', array_keys($by));
    return [$base, wa_camp_ctx($pdo, $base, 99, [], 0, true)];
}

/** Alternatives (versions 2 and 3) for every message that has failures today that another number or wording could fix. They are sent to Meta by the normal auto-submit engine as well. Returns how many were made. */
function wa_rec_alts(PDO $pdo): int
{
    $cfg = wa_rec_cfg($pdo); if (!$cfg['on'] || !$cfg['alts'] || !function_exists('wa_fam_market_rewrite') || !function_exists('wa_tpl_insert')) return 0;
    $last = (int)strtotime((string)setting_get($pdo, 'wa_rec_alts_at', '')); if ($last > time() - 600) return 0; setting_set($pdo, 'wa_rec_alts_at', date('Y-m-d H:i:s'));
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
    try { $q = $pdo->prepare("SELECT c.tpl_id, COUNT(*) AS n FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.status = 'failed' AND (c.started_at >= ? OR i.sent_at >= ?) AND i.err_code NOT IN (" . wa_rec_never_sql() . ") GROUP BY c.tpl_id HAVING n >= 15 ORDER BY n DESC LIMIT 6"); $q->execute([$from, $from]); $rows = $q->fetchAll(); } catch (Throwable $e) { return 0; }
    $wabas = function_exists('wa_wabas') ? wa_wabas($pdo) : []; $budget = ['left' => 12, 'made' => 0]; $made = 0;
    foreach ($rows as $r) {
        $id = (int)$r['tpl_id']; $row = wa_tpl_row($pdo, $id); if (!$row || strtoupper((string)$row['category']) !== 'MARKETING') continue;
        $mem = array_values(array_filter(wa_fam_members($pdo, wa_fam_root($row)), fn($m) => $m['line'] === 'M' && !$m['relabelled_at'])); if (count($mem) >= 3) continue;   // original + 2 alternatives
        usort($mem, fn($a, $b) => $a['ver'] <=> $b['ver'] ?: (int)$a['id'] <=> (int)$b['id']); $L = end($mem); $Lrow = wa_tpl_row($pdo, (int)$L['id']); if (!$Lrow) continue; $ver = (int)$L['ver'] + 1;
        $d = wa_fam_market_rewrite($Lrow, $ver, $ver > 2 ? 'some people did not get the message the first time — make this one clearly different from the others: short, one clear line, a friendly question at the end' : 'some people did not get the message the first time');
        [$cd, $err] = wa_tpl_clean($d); if ($err === '' && function_exists('wa_tpl_media_check')) $err = wa_tpl_media_check($pdo, $cd); if ($err !== '') { wa_log($pdo, 'template', false, 'Recovery alternative of ' . $row['name'] . ' could not be made: ' . $err); continue; }
        $cd['family_id'] = wa_fam_root($row); $cd['version'] = $ver; $cd['extra'] = array_merge(is_array($cd['extra']) ? $cd['extra'] : [], ['alt_of' => (int)$L['id'], 'recovery' => 1]);
        $cd['id'] = wa_tpl_insert($pdo, $cd, 0, (string)$row['purpose'], 'Recovery version ' . $ver . ' of ' . $row['name']); $nrow = wa_tpl_row($pdo, $cd['id']); if (!$nrow) continue; $made++;
        foreach ($wabas as $w) wa_tpl_try_create($pdo, $nrow, $w, $budget);
        wa_log($pdo, 'template', true, 'Recovery alternative made for ' . $row['name'] . ': ' . $nrow['name'] . ' (sent to Meta for approval)');
    }
    return $made;
}

/** How the "held by Meta" people (131049) did when tried from another number: ['n' => tried, 'd' => delivered] over the last 12 hours (receipts have had an hour to arrive). */
function wa_rec_held_stats(PDO $pdo): array
{
    $o = ['n' => 0, 'd' => 0];
    try { $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(m.status IN ('delivered', 'read')) AS d FROM wa_rec_log r JOIN wa_camp_items i ON i.id = r.item_id AND i.err_code = 131049 LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.ok = 1 AND r.at >= ? AND r.at <= ?");
        $q->execute([date('Y-m-d H:i:s', time() - 12 * 3600), date('Y-m-d H:i:s', time() - 3600)]); $r = $q->fetch(); $o = ['n' => (int)$r['n'], 'd' => (int)$r['d']]; } catch (Throwable $e) { }
    return $o;
}
/** What is waiting today, by reason (the business day, same window as the card): shown under "Campaigns today" so a 0 always has an explanation. */
function wa_rec_diag(PDO $pdo): array
{
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $o = ['failed_total' => 0, 'never' => 0, 'held' => 0, 'eligible' => 0, 'campaign_retry' => 0];
    try {
        $q = $pdo->prepare("SELECT i.status, i.err_code, COUNT(*) AS n FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE (c.started_at >= ? OR i.sent_at >= ?) AND (i.status IN ('failed', 'retry') OR (i.status = 'sent' AND i.last_status = 'failed')) GROUP BY i.status, i.err_code"); $q->execute([$from, $from]);
        foreach ($q->fetchAll() as $r) { $n = (int)$r['n']; $cl = wa_rec_class((int)$r['err_code']); $o['failed_total'] += $n;
            if ($cl === 'never') $o['never'] += $n; elseif ($cl === 'held') $o['held'] += $n; elseif ($r['status'] === 'failed') $o['eligible'] += $n; else $o['campaign_retry'] += $n; }
    } catch (Throwable $e) { }
    return $o;
}
/** One recovery round + a note of what happened (kept for the status line). */
function wa_rec_tick(PDO $pdo): array
{
    $r = wa_rec_tick_run($pdo);
    if (($r['note'] ?? '') !== 'busy') {
        try { $last = json_decode((string)setting_get($pdo, 'wa_rec_last', ''), true); $last = is_array($last) ? $last : []; $diag = $last['diag'] ?? null; $dAt = (int)($last['diag_at'] ?? 0);
            if (!$diag || $dAt < time() - 120) { $diag = wa_rec_diag($pdo); $dAt = time(); }
            setting_set($pdo, 'wa_rec_last', json_encode(['at' => time(), 'note' => (string)($r['note'] ?? ''), 'sent' => (int)($r['sent'] ?? 0), 'failed' => (int)($r['failed'] ?? 0), 'skipped' => (int)($r['skipped'] ?? 0), 'looked' => (int)($r['looked'] ?? 0), 'why' => $r['why'] ?? [], 'diag' => $diag, 'diag_at' => $dAt])); } catch (Throwable $e) { }
    }
    return $r;
}
function wa_rec_status(PDO $pdo): array
{
    $cfg = wa_rec_cfg($pdo); $last = null; try { $l = json_decode((string)setting_get($pdo, 'wa_rec_last', ''), true); if (is_array($l)) $last = $l; } catch (Throwable $e) { }
    return ['on' => $cfg['on'], 'paused' => $cfg['pause_until'] > time(), 'hourly' => $cfg['hourly'], 'held_try' => $cfg['held_on'] && $cfg['held_off_until'] <= time(), 'held_stats' => wa_rec_held_stats($pdo), 'last' => $last, 'now' => time()];
}
/** The slow recovery sender. One round (≤ 40 s). Returns counts. */
function wa_rec_tick_run(PDO $pdo): array
{
    $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'note' => ''];
    if (!wa_rec_tables($pdo)) { $res['note'] = 'no table'; return $res; }
    $cfg = wa_rec_cfg($pdo); if (!$cfg['on']) { $res['note'] = 'off'; return $res; } if ($cfg['pause_until'] > time()) { $res['note'] = 'paused after a Meta warning'; return $res; }
    if (!function_exists('wa_lock') || !wa_lock($pdo, 'rec', 50)) { $res['note'] = 'busy'; return $res; }
    $t0 = microtime(true); $nowTs = wa_camp_now(); $now = date('Y-m-d H:i:s', $nowTs);
    try {
        if (!wa_camp_in_window($pdo, $nowTs)) { $res['note'] = 'quiet hours'; return $res; }
        try { $res['alts'] = wa_rec_alts($pdo); } catch (Throwable $e) { }
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_rec_log WHERE at >= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - 3600)]); $left = $cfg['hourly'] - (int)$q->fetchColumn(); if ($left <= 0) { $res['note'] = 'this hour\'s recovery pool is used'; return $res; }
        // a round of recovery that mostly fails (Meta receipts) pauses itself for an hour
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(m.status = 'failed') AS f FROM wa_rec_log r JOIN wa_messages m ON m.wamid = r.wamid WHERE r.at >= ? AND r.wamid <> ''"); $q->execute([date('Y-m-d H:i:s', $nowTs - 7200)]); $hr = $q->fetch();
        if ((int)$hr['n'] >= 60 && (int)$hr['f'] / max(1, (int)$hr['n']) >= 0.6) { setting_set($pdo, 'wa_rec_pause_until', (string)($nowTs + 3600)); wa_log($pdo, 'campaign', false, 'Recovery paused for an hour: ' . (int)$hr['f'] . ' of ' . (int)$hr['n'] . ' recovery messages failed'); $res['note'] = 'paused: too many failures'; return $res; }
        // somebody was delivered again → the campaign's own retry of that person (a day later) must not send it a second time
        try { $pdo->exec("UPDATE wa_camp_items i JOIN wa_rec_log r ON r.item_id = i.id JOIN wa_messages m ON m.wamid = r.wamid SET i.status = 'skipped' WHERE (i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND r.ok = 1 AND r.wamid <> '' AND m.status IN ('delivered', 'read')"); } catch (Throwable $e) { }
        // "held by Meta" (131049) people are tried from another number after 30 minutes — an experiment with a brake: if almost none get delivered, it stops for a day
        $heldOn = $cfg['held_on'] && $cfg['held_off_until'] <= $nowTs ? 1 : 0;
        if ($heldOn) { $hs = wa_rec_held_stats($pdo); if ($hs['n'] >= 150 && $hs['d'] / max(1, $hs['n']) < 0.03) { setting_set($pdo, 'wa_rec_held_off_until', (string)($nowTs + 86400)); $heldOn = 0; wa_log($pdo, 'campaign', false, 'Held-by-Meta retries from another number stopped for a day: only ' . $hs['d'] . ' of ' . $hs['n'] . ' were delivered'); } }
        $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00');
        $cur = max(0, (int)setting_get($pdo, 'wa_rec_cursor', '0')); $lim = 1200; $why = []; $broke = false; $lastId = 0;   // the people looked at move on every round (a person skipped for now is looked at again on the next lap) — before, the same first 500 were looked at every time
        $sk = function (string $k) use (&$res, &$why) { $res['skipped']++; $why[$k] = ($why[$k] ?? 0) + 1; };
        $q = $pdo->prepare("SELECT i.id, i.camp_id, i.phone, i.name, i.number_id, i.vars_json, i.err_code, i.status, i.sent_at, c.tpl_id, c.media_lib_id FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id
            WHERE ((i.status = 'failed' AND i.err_code NOT IN (" . wa_rec_never_sql() . ")) OR ($heldOn AND (i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND i.err_code = 131049 AND i.sent_at <= '" . date('Y-m-d H:i:s', $nowTs - 1800) . "')) AND (c.started_at >= ? OR i.sent_at >= ?) AND i.id > " . $cur . " ORDER BY i.id LIMIT " . $lim); $q->execute([$from, $from]); $items = $q->fetchAll();
        if (!$items) { if ($cur > 0) setting_set($pdo, 'wa_rec_cursor', '0'); $res['note'] = 'nothing to recover'; return $res; }
        $ids = array_map(fn($x) => (int)$x['id'], $items); $in = implode(',', $ids); $att = [];
        foreach ($pdo->query("SELECT item_id, COUNT(*) AS n, MAX(at) AS last, GROUP_CONCAT(number_id) AS nums FROM wa_rec_log WHERE item_id IN ($in) GROUP BY item_id")->fetchAll() as $r) $att[(int)$r['item_id']] = $r;
        $recent = []; $q2 = $pdo->prepare("SELECT DISTINCT phone FROM wa_rec_log WHERE at >= ?"); $q2->execute([date('Y-m-d H:i:s', $nowTs - $cfg['gap_min'] * 60)]); foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $p) $recent[(string)$p] = 1;
        $phones = array_values(array_unique(array_map(fn($x) => (string)$x['phone'], $items))); $pin = implode(',', array_map(fn($p) => $pdo->quote($p), $phones));
        $ct = []; foreach ($pdo->query("SELECT phone, wa_id, name, optout, last_in_at FROM wa_contacts WHERE phone IN ($pin)")->fetchAll() as $r) $ct[(string)$r['phone']] = $r;
        $got = []; $q3 = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE phone IN ($pin) AND dir = 'out' AND mtype = 'template' AND status IN ('delivered', 'read') AND ts >= ?"); $q3->execute([date('Y-m-d H:i:s', strtotime($from) - 86400)]); foreach ($q3->fetchAll(PDO::FETCH_COLUMN) as $p) $got[(string)$p] = 1;
        $GLOBALS['__wa_lim_cache_ok'] = true; $cache = []; $tplBase = []; $seen = [];
        foreach ($items as $it) {
            if (microtime(true) - $t0 > 38 || $left <= 0) { $broke = true; break; }
            $ph = (string)$it['phone']; $iid = (int)$it['id']; $lastId = $iid; $a = $att[$iid] ?? null; $n = $a ? (int)$a['n'] : 0;
            if ($n >= (in_array((string)$it['status'], ['retry', 'sent'], true) ? 1 : $cfg['max_attempts'] - 1)) { $sk('all tries used'); continue; }
            if (isset($recent[$ph]) || isset($seen[$ph])) { $sk('tried in the last hour'); continue; }
            if (isset($got[$ph])) { $sk('delivered another way today'); continue; }
            if ($a && strtotime((string)$a['last']) > $nowTs - $cfg['gap_min'] * 60) { $sk('tried in the last hour'); continue; }
            $c = $ct[$ph] ?? null; if (!$c) { $sk('no contact record'); continue; } if ((int)$c['optout'] === 1) { $sk('opted out'); continue; } if (!empty($c['last_in_at']) && !empty($it['sent_at']) && strtotime((string)$c['last_in_at']) > strtotime((string)$it['sent_at'])) { $sk('wrote back'); continue; }
            $tid = (int)$it['tpl_id']; if (!isset($tplBase[$tid])) $tplBase[$tid] = wa_camp_tpl($pdo, $tid, true, (int)$it['media_lib_id']); $t = $tplBase[$tid]; if (!$t) { $sk('message not found'); continue; }
            $vars = json_decode((string)$it['vars_json'], true); if (!is_array($vars)) { if ((int)$t['nvars'] > 0) { $sk('no saved variables'); continue; } $vars = []; }
            $attempt = $n + 2; $cls = wa_rec_class((int)$it['err_code']); $preferAlt = $attempt >= 3 || $cls === 'tpl'; $altIdx = abs((int)crc32($ph));
            $ck = $tid . '|' . ($preferAlt ? 'a' . ($altIdx % 2) : 'o'); if (!isset($cache[$ck])) { $cache[$ck] = wa_rec_tpl_ctx($pdo, $t, $preferAlt, $altIdx); if (!$cache[$ck][0] && $preferAlt) { $ck = $tid . '|o'; if (!isset($cache[$ck])) $cache[$ck] = wa_rec_tpl_ctx($pdo, $t, false, 0); } }
            [$tUse, $ctx] = $cache[$ck]; if (!$tUse || !$ctx) { $sk('message not approved on another account'); continue; }
            $tried = array_map('intval', array_filter(array_merge([(int)$it['number_id']], $a ? explode(',', (string)$a['nums']) : []))); $cand = array_filter($ctx['nums'], fn($x) => $x['left'] > 0 && !in_array((int)$x['id'], $tried, true));
            if (!$cand || $ctx['port_left'] <= 0) { $sk($ctx['port_left'] <= 0 ? 'Meta room used' : 'no other number with room'); continue; }   // no other number has room for this person: they wait for the next round
            $good = array_filter($cand, fn($x) => in_array(($x['q'] ?? ''), ['GREEN', 'YELLOW'], true)); if ($good) $cand = $good;
            uasort($cand, fn($x, $y) => ($x['used'] / max(1, $x['cap'])) <=> ($y['used'] / max(1, $y['cap']))); $num = reset($cand); $nid = (int)$num['id']; $tSend = $num['tm'] ?? $tUse;
            $seen[$ph] = 1; [$ok, $r, $ec] = wa_send_tpl_one($pdo, ['phone' => $ph, 'wa_id' => (string)($c['wa_id'] ?? '')], $tSend, $num, $vars, 'recover');
            $isAlt = (int)$tSend['id'] !== $tid ? 1 : 0;
            $pdo->prepare("INSERT INTO wa_rec_log (camp_id, item_id, phone, attempt, number_id, tpl_id, is_alt, wamid, ok, err_code, err, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([(int)$it['camp_id'], $iid, $ph, $attempt, $nid, (int)$tSend['id'], $isAlt, $ok ? (string)$r : '', $ok ? 1 : 0, $ok ? 0 : (int)$ec, $ok ? '' : mb_substr((string)$r, 0, 190), $now]);
            $cache[$ck][1]['nums'][$nid]['left']--; $cache[$ck][1]['nums'][$nid]['used']++; $cache[$ck][1]['port_left']--; $left--;
            if ($ok) $res['sent']++; else { $res['failed']++; if ((int)$ec === 131048) { setting_set($pdo, 'wa_rec_pause_until', (string)($nowTs + 6 * 3600)); wa_log($pdo, 'campaign', false, 'Recovery paused for 6 hours: Meta flagged messages as spam (131048)'); $broke = true; break; } if (in_array((int)$ec, [130429, 131056], true)) { $broke = true; break; } }
        }
        try { setting_set($pdo, 'wa_rec_cursor', (string)(($broke || count($items) >= $lim) ? $lastId : 0)); } catch (Throwable $e) { }
        arsort($why); $res['why'] = array_slice($why, 0, 6, true); $res['looked'] = count($items);
    } catch (Throwable $e) { wa_log($pdo, 'campaign', false, 'Recovery error: ' . $e->getMessage()); $res['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 140); }
    return $res;
}

/** Counts per campaign: ['tried' => attempts, 'recovered' => people delivered after a retry]. */
function wa_rec_counts(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids || !wa_rec_tables($pdo)) return [];
    try { $o = []; foreach ($pdo->query("SELECT r.camp_id, COUNT(*) AS tried, COUNT(DISTINCT CASE WHEN m.status IN ('delivered', 'read') THEN r.phone END) AS rec FROM wa_rec_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.camp_id IN (" . implode(',', $ids) . ") GROUP BY r.camp_id")->fetchAll() as $r) $o[(int)$r['camp_id']] = ['tried' => (int)$r['tried'], 'recovered' => (int)$r['rec']]; return $o; } catch (Throwable $e) { return []; }
}
/** The same for a time range (the "Campaigns today" card). */
function wa_rec_range(PDO $pdo, string $from, string $to): array
{
    $o = ['tried' => 0, 'recovered' => 0]; if (!wa_rec_tables($pdo)) return $o;
    try { $q = $pdo->prepare("SELECT COUNT(*) AS tried, COUNT(DISTINCT CASE WHEN m.status IN ('delivered', 'read') THEN r.phone END) AS rec FROM wa_rec_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.at >= ? AND r.at < ?"); $q->execute([$from, $to]); $r = $q->fetch(); $o = ['tried' => (int)$r['tried'], 'recovered' => (int)$r['rec']]; } catch (Throwable $e) { }
    return $o;
}

/** Everything the Recovery window of one campaign shows. */
function wa_rec_report(PDO $pdo, int $campId): array
{
    wa_rec_tables($pdo); $cfg = wa_rec_cfg($pdo);
    $camp = $pdo->prepare("SELECT id, name, tpl_id FROM wa_campaigns WHERE id = ?"); $camp->execute([$campId]); $c = $camp->fetch(); if (!$c) return ['error' => 'Campaign not found.'];
    $nm = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers")->fetchAll() as $n) $nm[(int)$n['id']] = (string)($n['label'] !== '' ? $n['label'] : $n['display']);
    $tn = []; foreach ($pdo->query("SELECT id, name, version FROM wa_templates")->fetchAll() as $t) $tn[(int)$t['id']] = ['name' => (string)$t['name'], 'ver' => (int)$t['version']];
    // first sends
    $fs = []; $failedTotal = 0; $never = 0; $held = 0;
    $q = $pdo->prepare("SELECT COALESCE(NULLIF(i.tpl_used, 0), ?) AS tid, COUNT(*) AS n, SUM(i.last_status IN ('delivered', 'read')) AS d, SUM(i.status = 'failed' OR i.last_status = 'failed') AS f FROM wa_camp_items i WHERE i.camp_id = ? AND i.status IN ('sent', 'failed') GROUP BY tid"); $q->execute([(int)$c['tpl_id'], $campId]);
    foreach ($q->fetchAll() as $r) $fs[(int)$r['tid']] = ['sent' => (int)$r['n'], 'delivered' => (int)$r['d'], 'failed' => (int)$r['f']];
    $q = $pdo->prepare("SELECT err_code, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status = 'failed' GROUP BY err_code"); $q->execute([$campId]);
    foreach ($q->fetchAll() as $r) { $failedTotal += (int)$r['n']; $cl = wa_rec_class((int)$r['err_code']); if ($cl === 'never') $never += (int)$r['n']; elseif ($cl === 'held') $held += (int)$r['n']; }
    // recovery attempts
    $q = $pdo->prepare("SELECT r.id, r.item_id, r.phone, r.attempt, r.number_id, r.tpl_id, r.is_alt, r.ok, r.err_code, r.err, r.at, m.status AS ms, i.number_id AS n1, i.err_code AS e1, i.err AS er1, i.name FROM wa_rec_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' LEFT JOIN wa_camp_items i ON i.id = r.item_id WHERE r.camp_id = ? ORDER BY r.id DESC LIMIT 4000");
    $q->execute([$campId]); $rows = $q->fetchAll(); $byItem = []; $byTpl = []; $trail = [];
    foreach ($rows as $r) {
        $state = ($r['ms'] === 'delivered' || $r['ms'] === 'read') ? 'delivered' : (((int)$r['ok'] === 0 || $r['ms'] === 'failed') ? 'failed' : 'waiting');
        $byItem[(int)$r['item_id']][] = $state; $t = (int)$r['tpl_id']; $byTpl[$t] = $byTpl[$t] ?? ['tried' => 0, 'recovered' => 0, 'failed' => 0]; $byTpl[$t]['tried']++; if ($state === 'delivered') $byTpl[$t]['recovered']++; if ($state === 'failed') $byTpl[$t]['failed']++;
        if (count($trail) < 80) $trail[] = ['name' => (string)($r['name'] ?? ''), 'phone' => substr((string)$r['phone'], 0, 2) . '••••' . substr((string)$r['phone'], -3), 'attempt' => (int)$r['attempt'], 'first' => ['number' => $nm[(int)$r['n1']] ?? '', 'why' => ((string)$r['er1'] !== '' ? (string)$r['er1'] : ((int)$r['e1'] ? 'code ' . (int)$r['e1'] : 'failed'))],
            'number' => $nm[(int)$r['number_id']] ?? ('Number ' . (int)$r['number_id']), 'message' => ($tn[$t]['name'] ?? ('#' . $t)), 'alt' => (int)$r['is_alt'] === 1, 'state' => $state, 'err' => (string)$r['err'], 'at' => (string)$r['at']];
    }
    $recovered = 0; $retried = count($byItem); $stillFailed = 0; $retrying = 0;
    foreach ($byItem as $st) { if (in_array('delivered', $st, true)) $recovered++; elseif (count($st) >= $cfg['max_attempts'] - 1 && !in_array('waiting', $st, true)) $stillFailed++; else $retrying++; }
    $waiting = max(0, $failedTotal - $never - $held - $retried);
    $ids = array_unique(array_merge(array_keys($fs), array_keys($byTpl))); $table = [];
    foreach ($ids as $tid) { $f = $fs[$tid] ?? ['sent' => 0, 'delivered' => 0, 'failed' => 0]; $b = $byTpl[$tid] ?? ['tried' => 0, 'recovered' => 0, 'failed' => 0];
        $table[] = ['id' => (int)$tid, 'name' => $tn[$tid]['name'] ?? ('#' . $tid), 'ver' => $tn[$tid]['ver'] ?? 1, 'alt' => (int)$tid !== (int)$c['tpl_id'] && (($tn[$tid]['ver'] ?? 1) > 1), 'sent' => $f['sent'], 'delivered' => $f['delivered'], 'failed' => $f['failed'], 'retry_tried' => $b['tried'], 'retry_delivered' => $b['recovered'], 'rate' => $b['tried'] ? round($b['recovered'] * 100 / $b['tried']) : null]; }
    usort($table, fn($a, $b) => ($a['alt'] <=> $b['alt']) ?: $a['id'] <=> $b['id']);
    $alts = 0; $altsOk = 0; try { $root = isset($tn[(int)$c['tpl_id']]) ? (int)$pdo->query("SELECT COALESCE(NULLIF(family_id, 0), id) FROM wa_templates WHERE id = " . (int)$c['tpl_id'])->fetchColumn() : 0;
        if ($root) foreach (wa_fam_members($pdo, $root) as $m) if ($m['line'] === 'M' && (int)$m['id'] !== (int)$c['tpl_id']) { $alts++; $tr = wa_tpl_row($pdo, (int)$m['id']); $cc = $tr ? wa_camp_tpl($pdo, (int)$m['id'], true, 0) : null; if ($cc && !empty($cc['ok_wabas'])) $altsOk++; } } catch (Throwable $e) { }
    return ['campaign' => ['id' => (int)$c['id'], 'name' => (string)$c['name']], 'cfg' => ['on' => $cfg['on'], 'hourly' => $cfg['hourly'], 'max_attempts' => $cfg['max_attempts'], 'paused' => $cfg['pause_until'] > time()],
        'summary' => ['failed' => $failedTotal, 'never' => $never, 'held' => $held, 'waiting' => $waiting, 'retried' => $retried, 'recovered' => $recovered, 'still_failed' => $stillFailed, 'retrying' => $retrying, 'rate' => $retried ? round($recovered * 100 / $retried) : null],
        'alts' => ['made' => $alts, 'approved' => $altsOk], 'table' => $table, 'trail' => $trail];
}

}
