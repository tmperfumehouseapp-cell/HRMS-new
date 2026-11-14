<?php
/**
 * wa_cfun.php — 📊 THE customer funnel (one for the whole system: Customers → Contacts and the Campaigns card use the same numbers).
 *
 *   Who is a customer: every number in wa_contacts (chats, Excel imports, "came from nowhere" first messages), every number in a campaign list and every number that ordered.
 *   Two views of the same table wa_cfun (one row per number):
 *
 *   GROUPS — each customer in exactly ONE, by the furthest step reached (they add up to everybody):
 *     ord2 (2+ orders) → ord1 (1 order) → replied (wrote to us, STOP counts) → deliv (a message reached the phone, no reply) → failed (never replied and the LAST 3 messages we sent failed)
 *     → sent (messages sent, none confirmed yet) → never (we never sent anything)
 *   COUNTS — a customer can be in several: messaged · reached · replied · never replied · wrote first · ordered · 2+ orders · opted out · failed · delivered · sent-not-confirmed · never messaged.
 *   A reply is permanent: the customer leaves "failed" for good. Orders: every order ever (online + store + old POS). Source: query (came by writing to us) / campaign (everybody else).
 *
 *   Cost: a background tick (from the engine round, at most once per 55 minutes, 25 s budget, one process at a time) recomputes only customers touched by recent messages / orders,
 *   then walks everybody in small chunks (a full pass about once a day). The screen reads one cached JSON.
 *
 *   wa.cf_stats  GET {src}   the numbers (cached)        wa.cf_list  GET {k, src, after, lim ≤ 5000, q}   customers of one group / count, for the screen and the Excel download
 *   wa.cf_run    POST        count again now (admin)
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('cfun_tables')) {

function cfun_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $coll = ''; try { $cn = (string)$pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_contacts' AND COLUMN_NAME = 'phone'")->fetchColumn(); if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $cn)) $coll = ' COLLATE ' . $cn; } catch (Throwable $e) { }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cfun (
            phone VARCHAR(12) NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', bucket VARCHAR(10) NOT NULL DEFAULT 'untried', replied TINYINT NOT NULL DEFAULT 0, optout TINYINT NOT NULL DEFAULT 0,
            out_n INT NOT NULL DEFAULT 0, deliv_n INT NOT NULL DEFAULT 0, sent_n INT NOT NULL DEFAULT 0, fail_n INT NOT NULL DEFAULT 0, in_n INT NOT NULL DEFAULT 0, orders_n INT NOT NULL DEFAULT 0,
            first_out DATETIME NULL, last_out DATETIME NULL, last_in DATETIME NULL, at DATETIME NULL, KEY k_bucket (bucket), KEY k_rep (replied), KEY k_ord (orders_n)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" . $coll);
        // columns added when the two funnels became one — added once, only when missing
        foreach (['src' => "VARCHAR(8) NOT NULL DEFAULT 'campaign'", 'grp' => "VARCHAR(8) NOT NULL DEFAULT 'never'", 'ecode' => 'INT NOT NULL DEFAULT 0', 'last_camp' => 'INT NOT NULL DEFAULT 0',
                  'imp' => 'TINYINT NOT NULL DEFAULT 0', 'batch' => "VARCHAR(24) NOT NULL DEFAULT ''", 'qual' => "VARCHAR(8) NOT NULL DEFAULT ''", 'intent' => "VARCHAR(12) NOT NULL DEFAULT ''",
                  'named' => 'TINYINT NOT NULL DEFAULT 0', 'asg' => 'TINYINT NOT NULL DEFAULT 0', 'num' => 'INT NOT NULL DEFAULT 0', 'last_deliv' => 'DATETIME NULL', 'last_fail' => 'DATETIME NULL'] as $col => $def) {
            try { $pdo->query("SELECT $col FROM wa_cfun LIMIT 0"); } catch (Throwable $e) { try { $pdo->exec("ALTER TABLE wa_cfun ADD COLUMN $col $def"); } catch (Throwable $e2) { } }
        }
        try { $q = $pdo->query("SHOW INDEX FROM wa_cfun WHERE Key_name = 'k_grp'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_cfun ADD INDEX k_grp (grp, src)"); } catch (Throwable $e) { }
        try { $q = $pdo->query("SHOW INDEX FROM wa_cfun WHERE Key_name = 'k_imp'"); if ($q && !$q->fetch()) $pdo->exec("ALTER TABLE wa_cfun ADD INDEX k_imp (imp, batch)"); } catch (Throwable $e) { }
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cfun_hist (d DATE NOT NULL PRIMARY KEY, j TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { }
        return $ok = true;
    } catch (Throwable $e) { error_log('wa_cfun: ' . $e->getMessage()); return $ok = false; }
}

function cfun_in(array $a): string { return implode(',', array_fill(0, count($a), '?')); }

/** Meta's error codes in words (the "why did it fail" list). */
function cfun_reason(int $c): string
{
    $m = [131026 => 'Not on WhatsApp / cannot receive', 131049 => 'Held by Meta (limit per customer)', 131048 => 'Held by Meta (spam limit)', 131050 => 'Stopped marketing messages', 130472 => 'In a Meta experiment', 131047 => 'Reply window closed', 131056 => 'Too many messages to this number', 0 => 'Failed (no reason given)'];
    return $m[$c] ?? ('Other (Meta code ' . $c . ')');
}

/** Recompute these customers (≤ ~1500 at a time) and save their rows. Returns how many rows were written. */
function cfun_calc(PDO $pdo, array $phones): int
{
    $phones = array_values(array_unique(array_filter(array_map('strval', $phones), function ($p) { return $p !== ''; }))); if (!$phones) return 0;
    $in = cfun_in($phones); $C = []; $now = date('Y-m-d H:i:s');
    $get = function (string $sql, array $p) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    foreach ($phones as $p) $C[(string)$p] = ['name' => '', 'optout' => 0, 'src' => 'campaign', 'out_n' => 0, 'deliv' => 0, 'sent' => 0, 'fail' => 0, 'ec' => 0, 'a' => null, 'b' => null, 'in_n' => 0, 'last_in' => null, 'ci' => null, 'orders' => 0, 'lc' => 0, 'asg' => 0, 'num' => 0, 'imp' => 0, 'batch' => '', 'qual' => '', 'intent' => '', 'ld' => null, 'lf' => null];
    foreach ($get("SELECT phone, name, optout, last_in_at, source, assigned_emp, last_number_id FROM wa_contacts WHERE phone IN ($in)", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['name'] = (string)$r['name']; $c['optout'] = (int)$r['optout']; $c['ci'] = $r['last_in_at']; $c['src'] = (string)$r['source'] === 'query' ? 'query' : 'campaign'; $c['asg'] = (int)$r['assigned_emp'] > 0 ? 1 : 0; $c['num'] = (int)$r['last_number_id']; unset($c); }
    foreach ($get("SELECT phone, MAX(tag) b FROM wa_ctags WHERE src = 'import' AND tag LIKE 'import\\_%' AND phone IN ($in) GROUP BY phone", $phones) as $r) { $C[(string)$r['phone']]['imp'] = 1; $C[(string)$r['phone']]['batch'] = mb_substr((string)$r['b'], 0, 24); }
    foreach ($get("SELECT DISTINCT phone FROM wa_ctags WHERE src = 'import' AND phone IN ($in)", $phones) as $r) { $C[(string)$r['phone']]['imp'] = 1; }
    foreach ($get("SELECT phone, tag FROM wa_tagidx WHERE tag IN ('premium_buyer', 'budget_buyer', 'hot', 'interested', 'considering', 'not_interested') AND phone IN ($in)", $phones) as $r) { $k = (string)$r['phone']; $t = (string)$r['tag'];
        if ($t === 'premium_buyer') $C[$k]['qual'] = 'premium'; elseif ($t === 'budget_buyer') $C[$k]['qual'] = 'budget';
        else { $rank = ['hot' => 4, 'interested' => 3, 'considering' => 2, 'not_interested' => 1]; if (($rank[$t] ?? 0) > ($rank[$C[$k]['intent']] ?? 0)) $C[$k]['intent'] = $t; } }
    foreach ($get("SELECT phone, COUNT(*) n, SUM(status IN ('delivered','read')) d, SUM(status = 'sent') s, SUM(status = 'failed') f, MAX(CASE WHEN status = 'failed' THEN err_code END) ec, MIN(ts) a, MAX(ts) b, MAX(CASE WHEN status IN ('delivered','read') THEN ts END) ld, MAX(CASE WHEN status = 'failed' THEN ts END) lf FROM wa_messages WHERE dir = 'out' AND phone IN ($in) GROUP BY phone", $phones) as $r) {
        $c = &$C[(string)$r['phone']]; $c['out_n'] = (int)$r['n']; $c['deliv'] = (int)$r['d']; $c['sent'] = (int)$r['s']; $c['fail'] = (int)$r['f']; $c['ec'] = (int)$r['ec']; $c['a'] = $r['a']; $c['b'] = $r['b']; $c['ld'] = $r['ld']; $c['lf'] = $r['lf']; unset($c); }
    foreach ($get("SELECT phone, COUNT(*) n, MAX(ts) b FROM wa_messages WHERE dir = 'in' AND mtype <> 'system' AND phone IN ($in) GROUP BY phone", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['in_n'] = (int)$r['n']; $c['last_in'] = $r['b']; unset($c); }
    foreach ($get("SELECT phone, MAX(camp_id) lc FROM wa_camp_items WHERE phone IN ($in) GROUP BY phone", $phones) as $r) { $C[(string)$r['phone']]['lc'] = (int)$r['lc']; }
    // orders: the Shopify copy (wa_cust) and every channel (customer_facts); the bigger number wins, nothing is counted twice
    foreach ($get("SELECT phone, name, n FROM wa_cust WHERE phone IN ($in)", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['orders'] = max($c['orders'], (int)$r['n']); if ($c['name'] === '') $c['name'] = (string)$r['name']; unset($c); }
    foreach ($get("SELECT p10, name, orders_n FROM customer_facts WHERE p10 IN ($in)", $phones) as $r) { $k = (string)$r['p10']; if (!isset($C[$k])) continue; $C[$k]['orders'] = max($C[$k]['orders'], (int)$r['orders_n']); if ($C[$k]['name'] === '') $C[$k]['name'] = (string)$r['name']; }
    $rows = [];
    foreach ($C as $p => $c) {
        $p = (string)$p; $replied = ($c['in_n'] > 0 || $c['ci'] !== null || $c['optout'] === 1) ? 1 : 0; $last3 = 0;
        if (!$replied && $c['fail'] > 0) {   // only people with a failed message need the "last 3 messages" look-up (an indexed read by phone)
            try { $q = $pdo->prepare("SELECT status FROM wa_messages WHERE phone = ? AND dir = 'out' ORDER BY id DESC LIMIT 3"); $q->execute([$p]); $st = $q->fetchAll(PDO::FETCH_COLUMN); $last3 = ($st && count(array_filter($st, function ($s) { return $s === 'failed'; })) === count($st)) ? 1 : 0; } catch (Throwable $e) { }
        }
        $bucket = $replied ? 'replied' : ($c['out_n'] === 0 ? 'untried' : ($last3 ? 'failed' : ($c['deliv'] > 0 ? 'delivered' : 'pending')));
        $grp = $c['orders'] >= 2 ? 'ord2' : ($c['orders'] === 1 ? 'ord1' : ($bucket === 'replied' ? 'replied' : ($bucket === 'delivered' ? 'deliv' : ($bucket === 'failed' ? 'failed' : ($bucket === 'pending' ? 'sent' : 'never')))));
        $qual = $c['qual'] !== '' ? $c['qual'] : ($c['orders'] > 0 ? 'std' : '');   // premium / budget from the automatic tags; any other buyer = standard
        $rows[] = [$p, mb_substr($c['name'], 0, 120), $bucket, $replied, $c['optout'], $c['out_n'], $c['deliv'], $c['sent'], $c['fail'], $c['in_n'], $c['orders'], $c['a'], $c['b'], $c['last_in'] ?: $c['ci'], $now, $c['src'], $grp, $bucket === 'failed' ? $c['ec'] : 0, $c['lc'],
            $c['imp'], $c['batch'], $qual, $c['intent'], trim($c['name']) !== '' ? 1 : 0, $c['asg'], $c['num'], $c['ld'], $c['lf']];
    }
    $n = 0;
    foreach (array_chunk($rows, 300) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '(' . implode(',', array_fill(0, 28, '?')) . ')')); $par = []; foreach ($ch as $r) foreach ($r as $v) $par[] = $v;
        $pdo->prepare("INSERT INTO wa_cfun (phone, name, bucket, replied, optout, out_n, deliv_n, sent_n, fail_n, in_n, orders_n, first_out, last_out, last_in, at, src, grp, ecode, last_camp, imp, batch, qual, intent, named, asg, num, last_deliv, last_fail) VALUES $ph
            ON DUPLICATE KEY UPDATE name = VALUES(name), bucket = VALUES(bucket), replied = VALUES(replied), optout = VALUES(optout), out_n = VALUES(out_n), deliv_n = VALUES(deliv_n), sent_n = VALUES(sent_n), fail_n = VALUES(fail_n),
            in_n = VALUES(in_n), orders_n = VALUES(orders_n), first_out = VALUES(first_out), last_out = VALUES(last_out), last_in = VALUES(last_in), at = VALUES(at), src = VALUES(src), grp = VALUES(grp), ecode = VALUES(ecode), last_camp = VALUES(last_camp),
            imp = VALUES(imp), batch = VALUES(batch), qual = VALUES(qual), intent = VALUES(intent), named = VALUES(named), asg = VALUES(asg), num = VALUES(num), last_deliv = VALUES(last_deliv), last_fail = VALUES(last_fail)")->execute($par);
        $n += count($ch);
    }
    return $n;
}

/** The numbers for the screen: groups, counts, message totals and the reasons for "failed" — for one source (all / query / campaign). */
function cfun_stats(PDO $pdo, string $src = 'all'): array
{
    $w = $src === 'query' ? " WHERE src = 'query'" : ($src === 'campaign' ? " WHERE src = 'campaign'" : '');
    $q = $pdo->query("SELECT COUNT(*) total, SUM(out_n > 0) messaged, SUM(deliv_n > 0) reached, SUM(replied = 1) replied, SUM(replied = 1 AND out_n = 0) wrote_first, SUM(replied = 0) never_replied,
        SUM(bucket = 'failed') failed, SUM(out_n > 0 AND deliv_n = 0) not_delivered, SUM(fail_n > 0) any_failed, SUM(out_n > 0 AND fail_n >= out_n) all_failed, SUM(bucket = 'delivered') delivered, SUM(bucket = 'pending') pending, SUM(bucket = 'untried') untried, SUM(optout = 1) optout, SUM(orders_n > 0) ordered, SUM(orders_n >= 2) repeat_n,
        SUM(out_n) m_total, SUM(deliv_n) m_deliv, SUM(sent_n) m_sent, SUM(fail_n) m_fail, SUM(in_n) m_in, SUM(src = 'query') from_query, SUM(src = 'campaign') from_campaign,
        SUM(named = 1) named, SUM(named = 0) unnamed, SUM(asg = 1) assigned, SUM(asg = 0) unassigned, SUM(imp = 1) imp, SUM(imp = 1 AND out_n = 0) imp_never, SUM(imp = 1 AND deliv_n > 0) imp_deliv, SUM(imp = 1 AND out_n > 0 AND deliv_n = 0) imp_nodel,
        SUM(qual = 'premium') q_premium, SUM(qual = 'budget') q_budget, SUM(qual = 'std') q_std, SUM(intent = 'hot') i_hot, SUM(intent = 'interested') i_interested, SUM(intent = 'considering') i_considering, SUM(intent = 'not_interested') i_not,
        SUM(grp = 'never') g_never, SUM(grp = 'sent') g_sent, SUM(grp = 'failed') g_failed, SUM(grp = 'deliv') g_deliv, SUM(grp = 'replied') g_replied, SUM(grp = 'ord1') g_ord1, SUM(grp = 'ord2') g_ord2 FROM wa_cfun" . $w)->fetch(PDO::FETCH_ASSOC) ?: [];
    $o = []; foreach ($q as $k => $v) $o[$k] = (int)$v; $o['reasons'] = [];
    try { $by = []; foreach ($pdo->query("SELECT ecode, COUNT(*) n FROM wa_cfun WHERE bucket = 'failed'" . ($w === '' ? '' : ' AND ' . substr($w, 7)) . " GROUP BY ecode")->fetchAll(PDO::FETCH_ASSOC) as $g) { $l = cfun_reason((int)$g['ecode']); $by[$l] = ($by[$l] ?? 0) + (int)$g['n']; }
        arsort($by); foreach ($by as $k => $v) $o['reasons'][] = ['text' => $k, 'n' => $v]; } catch (Throwable $e) { }
    $o['batches'] = [];
    try { foreach ($pdo->query("SELECT batch, COUNT(*) n, SUM(deliv_n > 0) dl, SUM(out_n > 0 AND deliv_n = 0) nd, SUM(replied = 1) rp FROM wa_cfun WHERE imp = 1 AND batch <> ''" . ($w === '' ? '' : ' AND ' . substr($w, 7)) . " GROUP BY batch ORDER BY MAX(first_out) DESC, batch DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $b) $o['batches'][] = ['b' => (string)$b['batch'], 'n' => (int)$b['n'], 'dl' => (int)$b['dl'], 'nd' => (int)$b['nd'], 'rp' => (int)$b['rp']]; } catch (Throwable $e) { }
    $o['src'] = $src; $o['at'] = date('Y-m-d H:i:s'); return $o;
}

/** The background job. Cheap when nothing is due; never more than one at a time; never longer than $budget seconds. */
function cfun_tick(PDO $pdo, bool $force = false, int $budget = 25): array
{
    if (!cfun_tables($pdo)) return ['error' => 'no table'];
    $last = (int)strtotime((string)setting_get($pdo, 'wa_cfun_at', '1970-01-01')); if (!$force && $last > time() - 3300) return ['skipped' => 1];
    try { if ((int)$pdo->query("SELECT GET_LOCK('wa_cfun', 0)")->fetchColumn() !== 1) return ['busy' => 1]; } catch (Throwable $e) { return ['busy' => 1]; }
    $t0 = microtime(true); $left = function () use ($t0, $budget) { return microtime(true) - $t0 < $budget; }; $o = ['patched' => 0, 'walked' => 0];
    try {
        @set_time_limit($budget + 20); $since = date('Y-m-d H:i:s', max($last, time() - 7 * 86400) - 600);
        $v = (string)setting_get($pdo, 'wa_cfun_v', '');
        if ($v !== '5') { setting_set($pdo, 'wa_cfun_curf', ''); setting_set($pdo, 'wa_cfun_cur', ''); setting_set($pdo, 'wa_cfun_v', '5'); }   // v2: new columns (count everybody again once) · v3: people with a failed message are counted FIRST
        // 1. the customers the latest messages / orders touched (status changes keep the same message id, so the newest 60 000 messages are looked at again)
        $touch = [];
        try { $mx = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM wa_messages")->fetchColumn(); $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE id > ? LIMIT 30000"); $q->execute([max(0, $mx - 60000)]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $touch[(string)$p] = 1; } catch (Throwable $e) { }
        foreach ([["SELECT /*+ MAX_EXECUTION_TIME(8000) */ phone FROM wa_contacts WHERE first_seen >= ? LIMIT 20000"], ["SELECT phone FROM wa_contacts WHERE last_in_at >= ? LIMIT 20000"], ["SELECT phone FROM wa_cust WHERE last_order >= ? LIMIT 20000"]] as $s) {
            try { $q = $pdo->prepare($s[0]); $q->execute([$since]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $touch[(string)$p] = 1; } catch (Throwable $e) { } }
        foreach (array_chunk(array_keys($touch), 1500) as $ch) { if (!$left()) break; $o['patched'] += cfun_calc($pdo, $ch); }
        // 2a. FIRST everybody who has a failed message (one pass), so the failed lists are complete before the rest
        $curf = (string)setting_get($pdo, 'wa_cfun_curf', '');
        if ($curf !== 'done') {
            while ($left()) {
                $ph = [];
                foreach (["SELECT DISTINCT phone FROM wa_messages WHERE dir = 'out' AND status = 'failed' AND phone > ? ORDER BY phone LIMIT 1500", "SELECT DISTINCT phone FROM wa_camp_items WHERE status IN ('failed', 'retry') AND phone > ? ORDER BY phone LIMIT 1500"] as $sq) {
                    try { $q = $pdo->prepare($sq); $q->execute([$curf]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $ph[(string)$x] = 1; } catch (Throwable $e) { }
                }
                $ph = array_map('strval', array_keys($ph)); sort($ph, SORT_STRING); $ph = array_slice($ph, 0, 1500);
                if (!$ph) { $curf = 'done'; break; }
                $o['walked'] += cfun_calc($pdo, $ph); $curf = (string)end($ph); usleep(60000);
            }
            setting_set($pdo, 'wa_cfun_curf', $curf);
        }
        // 2b. the walk over everybody (a full pass about once a day, or at once while the table is still empty)
        $cur = (string)setting_get($pdo, 'wa_cfun_cur', ''); $fullAt = (int)strtotime((string)setting_get($pdo, 'wa_cfun_full', '1970-01-01'));
        if ($cur === 'done' && $fullAt < time() - 20 * 3600) $cur = '';
        if ($cur !== 'done' && $curf === 'done') {
            while ($left()) {
                $ph = [];
                foreach (["SELECT phone FROM wa_contacts WHERE phone > ? ORDER BY phone LIMIT 1500", "SELECT phone FROM wa_cust WHERE phone > ? ORDER BY phone LIMIT 1500", "SELECT phone FROM wa_camp_items WHERE phone > ? GROUP BY phone ORDER BY phone LIMIT 1500"] as $sq) {   // plain reads, merged here: no collation clash
                    try { $q = $pdo->prepare($sq); $q->execute([$cur]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $ph[(string)$x] = 1; } catch (Throwable $e) { }
                }
                $ph = array_map('strval', array_keys($ph)); sort($ph, SORT_STRING); $ph = array_slice($ph, 0, 1500);
                if (!$ph) { $cur = 'done'; setting_set($pdo, 'wa_cfun_full', date('Y-m-d H:i:s')); break; }
                $o['walked'] += cfun_calc($pdo, $ph); $cur = (string)end($ph); usleep(60000);   // a short breath between chunks keeps the database calm
            }
            setting_set($pdo, 'wa_cfun_cur', $cur);
        }
        // 3. the numbers for the screen (one cached row); while the first walk is not finished this runs again on the next round
        $S = []; foreach (['all', 'query', 'campaign'] as $s) $S[$s] = cfun_stats($pdo, $s);
        $S['all']['numbers'] = cfun_numbers($pdo); $S['all']['hist'] = cfun_hist($pdo, $S['all']);
        $o['done'] = ($cur === 'done' && $curf === 'done') ? 1 : 0; $o['failed_first_done'] = $curf === 'done' ? 1 : 0;
        setting_set($pdo, 'wa_cfun_stats', json_encode(['walking' => $o['done'] ? 0 : 1, 'failed_first' => $o['failed_first_done'], 'at' => date('Y-m-d H:i:s'), 'by' => $S]));
        if ($o['done']) setting_set($pdo, 'wa_cfun_at', date('Y-m-d H:i:s'));
        else setting_set($pdo, 'wa_cfun_at', date('Y-m-d H:i:s', time() - 3300 + 240));   // first build: come back in ~4 minutes
    } catch (Throwable $e) { error_log('cfun_tick: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_cfun')"); } catch (Throwable $e) { }
    $o['sec'] = round(microtime(true) - $t0, 1); return $o;
}

/** Messages per WhatsApp number (sent / delivered / failed) and customers per number. The message scan is the heaviest read, so it is redone at most every 6 hours; in between the saved copy is used. */
function cfun_numbers(PDO $pdo): array
{
    $old = json_decode((string)setting_get($pdo, 'wa_cfun_nums', ''), true);
    if (is_array($old) && (int)($old['t'] ?? 0) > time() - 6 * 3600) return $old['rows'];
    $rows = [];
    try {
        $lab = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $n) $lab[(int)$n['id']] = (string)($n['label'] !== '' ? $n['label'] : $n['display']);
        $M = []; foreach ($pdo->query("SELECT /*+ MAX_EXECUTION_TIME(60000) */ number_id, COUNT(*) n, SUM(status IN ('delivered','read')) dl, SUM(status = 'failed') f FROM wa_messages WHERE dir = 'out' GROUP BY number_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $M[(int)$r['number_id']] = $r;
        $C = []; foreach ($pdo->query("SELECT num, COUNT(*) c FROM wa_cfun GROUP BY num")->fetchAll(PDO::FETCH_ASSOC) as $r) $C[(int)$r['num']] = (int)$r['c'];
        foreach ($lab + array_fill_keys(array_keys($M), '') as $id => $l) { $m = $M[$id] ?? ['n' => 0, 'dl' => 0, 'f' => 0]; if (!(int)$m['n'] && empty($C[$id])) continue;
            $rows[] = ['id' => (int)$id, 'name' => $l !== '' ? $l : ('Number ' . $id), 'sent' => (int)$m['n'], 'dl' => (int)$m['dl'], 'f' => (int)$m['f'], 'cust' => (int)($C[$id] ?? 0)]; }
        setting_set($pdo, 'wa_cfun_nums', json_encode(['t' => time(), 'rows' => $rows]));
    } catch (Throwable $e) { return is_array($old) ? $old['rows'] : []; }
    return $rows;
}
/** One saved copy of the key numbers per day, so the screen can say "7 days ago it was …". Returns the last 8 days (oldest first). */
function cfun_hist(PDO $pdo, array $all): array
{
    try {
        $keep = ['total', 'reached', 'failed', 'replied', 'ordered', 'repeat_n', 'not_delivered', 'imp_nodel', 'unnamed']; $j = []; foreach ($keep as $k) $j[$k] = (int)($all[$k] ?? 0);
        if ((int)($all['total'] ?? 0) > 0) $pdo->prepare("INSERT IGNORE INTO wa_cfun_hist (d, j) VALUES (?, ?)")->execute([date('Y-m-d'), json_encode($j)]);   // first save of the day wins
        $out = []; foreach (array_reverse($pdo->query("SELECT d, j FROM wa_cfun_hist ORDER BY d DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC)) as $r) $out[] = ['d' => (string)$r['d']] + (array)json_decode((string)$r['j'], true);
        return $out;
    } catch (Throwable $e) { return []; }
}

function cfun_where(string $k): string
{
    $m = ['all' => '1=1', 'replied' => 'replied = 1', 'never_replied' => 'replied = 0', 'wrote_first' => 'replied = 1 AND out_n = 0', 'failed' => "bucket = 'failed'", 'delivered' => "bucket = 'delivered'",
        'pending' => "bucket = 'pending'", 'untried' => "bucket = 'untried'", 'messaged' => 'out_n > 0', 'reached' => 'deliv_n > 0', 'not_delivered' => 'out_n > 0 AND deliv_n = 0', 'any_failed' => 'fail_n > 0', 'all_failed' => 'out_n > 0 AND fail_n >= out_n', 'ordered' => 'orders_n > 0', 'repeat_n' => 'orders_n >= 2', 'optout' => 'optout = 1',
        'named' => 'named = 1', 'unnamed' => 'named = 0', 'assigned' => 'asg = 1', 'unassigned' => 'asg = 0', 'imp' => 'imp = 1', 'imp_never' => 'imp = 1 AND out_n = 0', 'imp_deliv' => 'imp = 1 AND deliv_n > 0', 'imp_nodel' => 'imp = 1 AND out_n > 0 AND deliv_n = 0',
        'q_premium' => "qual = 'premium'", 'q_budget' => "qual = 'budget'", 'q_std' => "qual = 'std'", 'i_hot' => "intent = 'hot'", 'i_interested' => "intent = 'interested'", 'i_considering' => "intent = 'considering'", 'i_not' => "intent = 'not_interested'",
        'g_never' => "grp = 'never'", 'g_sent' => "grp = 'sent'", 'g_failed' => "grp = 'failed'", 'g_deliv' => "grp = 'deliv'", 'g_replied' => "grp = 'replied'", 'g_ord1' => "grp = 'ord1'", 'g_ord2' => "grp = 'ord2'"];
    return $m[$k] ?? '1=1';
}

/** The compact stats answer the screens read: the cached numbers for one source (counted on the spot, once, if nothing is cached yet). */
function cfun_answer(PDO $pdo, string $src): array
{
    $j = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true);
    if (!is_array($j) || !isset($j['by'])) { cfun_tick($pdo, true, 20); $j = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true); }
    if (!is_array($j) || !isset($j['by'][$src])) return ['total' => 0, 'walking' => 1, 'src' => $src, 'reasons' => []];
    return $j['by'][$src] + ['walking' => (int)($j['walking'] ?? 0), 'at' => (string)($j['at'] ?? ''), 'numbers' => $j['by']['all']['numbers'] ?? [], 'hist' => $j['by']['all']['hist'] ?? []];
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- one-time / manual counting job (server cron, key instead of a login)
if ((string)($action ?? ($_GET['action'] ?? '')) === 'wa.cron_funnel') {
    ignore_user_abort(true); @set_time_limit(150);
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !cfun_tables($pdo)) json_response(['error' => 'storage'], 500);
    $__k = (string)setting_get($pdo, 'wa_cron_key', ''); if ($__k === '' || !hash_equals($__k, (string)($_GET['key'] ?? ''))) json_response(['error' => 'Wrong key.'], 403);
    try { $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5'); } catch (Throwable $e) { }
    $r = cfun_tick($pdo, true, 100); json_response(['ok' => true] + $r + ['rows' => (int)$pdo->query('SELECT COUNT(*) FROM wa_cfun')->fetchColumn()]);
}
// ---------------------------------------------------------------------------------------------------------------- router
$__cfa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__cfa, 'wa.cf_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !cfun_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $src = in_array((string)($_GET['src'] ?? 'all'), ['all', 'query', 'campaign'], true) ? (string)($_GET['src'] ?? 'all') : 'all';
    switch ($__cfa) {
        case 'wa.cf_stats': { json_response(['ok' => true] + cfun_answer($pdo, $src)); }
        case 'wa.cf_run': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = cfun_tick($pdo, true, 40); json_response(['ok' => true, 'run' => $r] + cfun_answer($pdo, 'all'));
        }
        case 'wa.cf_list': {
            $k = preg_replace('/[^a-z_0-9]/', '', (string)($_GET['k'] ?? 'all')); $after = preg_replace('/\D/', '', (string)($_GET['after'] ?? '')); $lim = max(1, min(5000, (int)($_GET['lim'] ?? 1000)));
            $qs = trim((string)($_GET['q'] ?? '')); $w = cfun_where($k); $p = [];
            if ($src !== 'all') { $w .= ' AND src = ?'; $p[] = $src; }
            $bt = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_GET['b'] ?? ''))); if ($bt !== '') { $w .= ' AND batch = ?'; $p[] = $bt; }
            $nm = (int)($_GET['n'] ?? 0); if ($nm > 0) { $w .= ' AND num = ?'; $p[] = $nm; }
            if ($qs !== '') { $d = preg_replace('/\D/', '', $qs); if (strlen($d) >= 3) { $w .= ' AND phone LIKE ?'; $p[] = '%' . $d . '%'; } else { $w .= ' AND name LIKE ?'; $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qs) . '%'; } }
            $tot = $pdo->prepare("SELECT COUNT(*) FROM wa_cfun WHERE $w"); $tot->execute($p); $total = (int)$tot->fetchColumn();
            $w2 = $w . ($after !== '' ? ' AND phone > ?' : ''); $p2 = $p; if ($after !== '') $p2[] = $after;
            $q = $pdo->prepare("SELECT phone, name, src, grp, bucket, ecode, last_camp, replied, optout, out_n, deliv_n, sent_n, fail_n, in_n, orders_n, first_out, last_out, last_in, imp, batch, qual, intent, named, asg, num, last_deliv, last_fail FROM wa_cfun WHERE $w2 ORDER BY phone LIMIT $lim"); $q->execute($p2);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC); $cn = [];
            if ($rows) { try { foreach ($pdo->query("SELECT id, name FROM wa_campaigns ORDER BY id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC) as $c) $cn[(int)$c['id']] = (string)$c['name']; } catch (Throwable $e) { } }
            foreach ($rows as &$r) { $r['reason'] = $r['bucket'] === 'failed' ? cfun_reason((int)$r['ecode']) : ''; $r['camp'] = (int)$r['last_camp'] > 0 ? ($cn[(int)$r['last_camp']] ?? ('#' . (int)$r['last_camp'])) : ''; unset($r['ecode'], $r['last_camp']); } unset($r);
            json_response(['ok' => true, 'total' => $total, 'rows' => $rows, 'last' => $rows ? (string)end($rows)['phone'] : '', 'more' => count($rows) === $lim]);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
