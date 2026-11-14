<?php
/**
 * wa_cfun.php — 📊 Customer funnel: every mobile number is ONE customer, and each customer sits in exactly one bucket.
 *
 *   Who is a customer: every number in wa_contacts (chats, Excel imports, "came from nowhere" first messages) and every number that ordered (wa_cust).
 *   Bucket (first rule that fits):
 *     replied    wrote to us at least once (STOP counts too) — permanent, it overrides failed messages
 *     failed     never replied and the LAST 3 messages we sent (or all, if fewer) failed
 *     delivered  never replied, at least one message reached the phone (delivered / read)
 *     pending    never replied, messages were sent but none confirmed yet
 *     untried    we never sent them a message
 *   Orders: every order ever (online + store + old POS, from customer_facts and the Shopify copy); repeat = 2 or more.
 *
 *   Cost: one row per customer in wa_cfun. A background tick (called from the engine round, runs at most once per 55 minutes) recomputes ONLY customers touched by
 *   recent messages / orders, then walks the rest in small chunks (full pass about once a day), under a 25 s budget, one process at a time. The page reads one cached JSON.
 *
 *   wa.cf_stats  GET   the numbers (cached)          wa.cf_list  GET {k, after, lim ≤ 5000, q}  customers of one bucket, for the screen and the Excel download
 *   wa.cf_run    POST  refresh now (admin)
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
        return $ok = true;
    } catch (Throwable $e) { error_log('wa_cfun: ' . $e->getMessage()); return $ok = false; }
}

function cfun_in(array $a): string { return implode(',', array_fill(0, count($a), '?')); }

/** Recompute these customers (≤ ~1500 at a time) and save their rows. Returns how many rows were written. */
function cfun_calc(PDO $pdo, array $phones): int
{
    $phones = array_values(array_unique(array_filter(array_map('strval', $phones), function ($p) { return $p !== ''; }))); if (!$phones) return 0;
    $in = cfun_in($phones); $C = []; $now = date('Y-m-d H:i:s');
    $get = function (string $sql, array $p) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };
    foreach ($phones as $p) $C[$p] = ['name' => '', 'optout' => 0, 'last_in' => null, 'out_n' => 0, 'deliv' => 0, 'sent' => 0, 'fail' => 0, 'a' => null, 'b' => null, 'in_n' => 0, 'orders' => 0, 'ci' => null];
    foreach ($get("SELECT phone, name, optout, last_in_at FROM wa_contacts WHERE phone IN ($in)", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['name'] = (string)$r['name']; $c['optout'] = (int)$r['optout']; $c['ci'] = $r['last_in_at']; unset($c); }
    foreach ($get("SELECT phone, COUNT(*) n, SUM(status IN ('delivered','read')) d, SUM(status = 'sent') s, SUM(status = 'failed') f, MIN(ts) a, MAX(ts) b FROM wa_messages WHERE dir = 'out' AND phone IN ($in) GROUP BY phone", $phones) as $r) {
        $c = &$C[(string)$r['phone']]; $c['out_n'] = (int)$r['n']; $c['deliv'] = (int)$r['d']; $c['sent'] = (int)$r['s']; $c['fail'] = (int)$r['f']; $c['a'] = $r['a']; $c['b'] = $r['b']; unset($c); }
    foreach ($get("SELECT phone, COUNT(*) n, MAX(ts) b FROM wa_messages WHERE dir = 'in' AND mtype <> 'system' AND phone IN ($in) GROUP BY phone", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['in_n'] = (int)$r['n']; $c['last_in'] = $r['b']; unset($c); }
    // orders: the Shopify copy (wa_cust) and every channel (customer_facts); the bigger number wins, nothing is counted twice
    foreach ($get("SELECT phone, name, n FROM wa_cust WHERE phone IN ($in)", $phones) as $r) { $c = &$C[(string)$r['phone']]; $c['orders'] = max($c['orders'], (int)$r['n']); if ($c['name'] === '') $c['name'] = (string)$r['name']; unset($c); }
    foreach ($get("SELECT p10, name, orders_n FROM customer_facts WHERE p10 IN ($in)", $phones) as $r) { $k = (string)$r['p10']; if (!isset($C[$k])) continue; $C[$k]['orders'] = max($C[$k]['orders'], (int)$r['orders_n']); if ($C[$k]['name'] === '') $C[$k]['name'] = (string)$r['name']; }
    $rows = [];
    foreach ($C as $p => $c) {
        $replied = ($c['in_n'] > 0 || $c['ci'] !== null || $c['optout'] === 1) ? 1 : 0; $last3 = 0;
        if (!$replied && $c['fail'] > 0) {   // only people with a failed message need the "last 3 messages" look-up (an indexed read by phone)
            try { $q = $pdo->prepare("SELECT status FROM wa_messages WHERE phone = ? AND dir = 'out' ORDER BY id DESC LIMIT 3"); $q->execute([$p]); $st = $q->fetchAll(PDO::FETCH_COLUMN); $last3 = ($st && count(array_filter($st, function ($s) { return $s === 'failed'; })) === count($st)) ? 1 : 0; } catch (Throwable $e) { } }
        $bucket = $replied ? 'replied' : ($c['out_n'] === 0 ? 'untried' : ($last3 ? 'failed' : ($c['deliv'] > 0 ? 'delivered' : 'pending')));
        $li = $c['last_in'] ?: $c['ci'];
        $rows[] = [$p, mb_substr($c['name'], 0, 120), $bucket, $replied, $c['optout'], $c['out_n'], $c['deliv'], $c['sent'], $c['fail'], $c['in_n'], $c['orders'], $c['a'], $c['b'], $li, $now];
    }
    $n = 0;
    foreach (array_chunk($rows, 400) as $ch) {
        $ph = implode(',', array_fill(0, count($ch), '(' . implode(',', array_fill(0, 15, '?')) . ')')); $par = []; foreach ($ch as $r) foreach ($r as $v) $par[] = $v;
        $pdo->prepare("INSERT INTO wa_cfun (phone, name, bucket, replied, optout, out_n, deliv_n, sent_n, fail_n, in_n, orders_n, first_out, last_out, last_in, at) VALUES $ph
            ON DUPLICATE KEY UPDATE name = VALUES(name), bucket = VALUES(bucket), replied = VALUES(replied), optout = VALUES(optout), out_n = VALUES(out_n), deliv_n = VALUES(deliv_n), sent_n = VALUES(sent_n), fail_n = VALUES(fail_n),
            in_n = VALUES(in_n), orders_n = VALUES(orders_n), first_out = VALUES(first_out), last_out = VALUES(last_out), last_in = VALUES(last_in), at = VALUES(at)")->execute($par);
        $n += count($ch);
    }
    return $n;
}

/** The numbers shown on the screen, worked out from wa_cfun (one pass over an indexed summary table). */
function cfun_stats(PDO $pdo): array
{
    $q = $pdo->query("SELECT COUNT(*) total, SUM(out_n > 0) messaged, SUM(replied = 1) replied, SUM(replied = 1 AND out_n = 0) wrote_first, SUM(replied = 0) never_replied,
        SUM(bucket = 'failed') failed, SUM(bucket = 'delivered') delivered, SUM(bucket = 'pending') pending, SUM(bucket = 'untried') untried, SUM(optout = 1) optout,
        SUM(out_n) m_total, SUM(deliv_n) m_deliv, SUM(sent_n) m_sent, SUM(fail_n) m_fail, SUM(in_n) m_in, SUM(orders_n > 0) ordered, SUM(orders_n >= 2) repeat_n,
        SUM(orders_n > 0 AND replied = 1) ordered_replied, SUM(orders_n > 0 AND out_n > 0) ordered_messaged, SUM(deliv_n > 0) reached FROM wa_cfun")->fetch(PDO::FETCH_ASSOC) ?: [];
    $o = []; foreach ($q as $k => $v) $o[$k] = (int)$v; $o['at'] = date('Y-m-d H:i:s'); return $o;
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
        // 1. the customers the latest messages / orders touched (status changes keep the same message id, so the newest 60 000 messages are looked at again)
        $touch = [];
        try { $mx = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM wa_messages")->fetchColumn(); $q = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE id > ? LIMIT 30000"); $q->execute([max(0, $mx - 60000)]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $touch[(string)$p] = 1; } catch (Throwable $e) { }
        foreach ([["SELECT phone FROM wa_contacts WHERE first_seen >= ? LIMIT 20000"], ["SELECT phone FROM wa_contacts WHERE last_in_at >= ? LIMIT 20000"], ["SELECT phone FROM wa_cust WHERE last_order >= ? LIMIT 20000"]] as $s) {
            try { $q = $pdo->prepare($s[0]); $q->execute([$since]); foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $p) $touch[(string)$p] = 1; } catch (Throwable $e) { } }
        foreach (array_chunk(array_keys($touch), 1500) as $ch) { if (!$left()) break; $o['patched'] += cfun_calc($pdo, $ch); }
        // 2. the walk over everybody (a full pass about once a day, or at once while the table is still empty)
        $cur = (string)setting_get($pdo, 'wa_cfun_cur', ''); $fullAt = (int)strtotime((string)setting_get($pdo, 'wa_cfun_full', '1970-01-01'));
        if ($cur === 'done' && $fullAt < time() - 20 * 3600) $cur = '';
        if ($cur !== 'done') {
            while ($left()) {
                $q = $pdo->prepare("SELECT phone FROM (SELECT phone FROM wa_contacts WHERE phone > ? UNION SELECT phone FROM wa_cust WHERE phone > ?) u ORDER BY phone LIMIT 1500"); $q->execute([$cur, $cur]);
                $ph = $q->fetchAll(PDO::FETCH_COLUMN); if (!$ph) { $cur = 'done'; setting_set($pdo, 'wa_cfun_full', date('Y-m-d H:i:s')); break; }
                $o['walked'] += cfun_calc($pdo, $ph); $cur = (string)end($ph); usleep(60000);   // a short breath between chunks keeps the database calm
            }
            setting_set($pdo, 'wa_cfun_cur', $cur);
        }
        // 3. the numbers for the screen (one cached row); while the first walk is not finished this runs again on the next round
        setting_set($pdo, 'wa_cfun_stats', json_encode(cfun_stats($pdo) + ['walking' => $cur === 'done' ? 0 : 1]));
        if ($cur === 'done' || $force) setting_set($pdo, 'wa_cfun_at', date('Y-m-d H:i:s'));
        elseif ($o['walked'] > 0) setting_set($pdo, 'wa_cfun_at', date('Y-m-d H:i:s', time() - 3300 + 240));   // first build: come back in ~4 minutes
    } catch (Throwable $e) { error_log('cfun_tick: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_cfun')"); } catch (Throwable $e) { }
    $o['sec'] = round(microtime(true) - $t0, 1); return $o;
}

function cfun_where(string $k): string
{
    $m = ['all' => '1=1', 'replied' => 'replied = 1', 'never_replied' => 'replied = 0', 'wrote_first' => 'replied = 1 AND out_n = 0', 'failed' => "bucket = 'failed'", 'delivered' => "bucket = 'delivered'",
        'pending' => "bucket = 'pending'", 'untried' => "bucket = 'untried'", 'messaged' => 'out_n > 0', 'reached' => 'deliv_n > 0', 'ordered' => 'orders_n > 0', 'repeat' => 'orders_n >= 2', 'optout' => 'optout = 1'];
    return $m[$k] ?? '1=1';
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- router
$__cfa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__cfa, 'wa.cf_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !cfun_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    switch ($__cfa) {
        case 'wa.cf_stats': {
            $j = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true);
            if (!is_array($j)) { cfun_tick($pdo, true, 20); $j = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true); }
            json_response(['ok' => true] + (is_array($j) ? $j : ['total' => 0, 'walking' => 1]));
        }
        case 'wa.cf_run': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $r = cfun_tick($pdo, true, 40); json_response(['ok' => true, 'run' => $r] + (json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true) ?: []));
        }
        case 'wa.cf_list': {
            $k = preg_replace('/[^a-z_]/', '', (string)($_GET['k'] ?? 'all')); $after = preg_replace('/\D/', '', (string)($_GET['after'] ?? '')); $lim = max(1, min(5000, (int)($_GET['lim'] ?? 1000)));
            $qs = trim((string)($_GET['q'] ?? '')); $w = cfun_where($k); $p = [];
            if ($qs !== '') { $d = preg_replace('/\D/', '', $qs); if (strlen($d) >= 3) { $w .= ' AND phone LIKE ?'; $p[] = '%' . $d . '%'; } else { $w .= ' AND name LIKE ?'; $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qs) . '%'; } }
            $tot = $pdo->prepare("SELECT COUNT(*) FROM wa_cfun WHERE $w"); $tot->execute($p); $total = (int)$tot->fetchColumn();
            $w2 = $w . ($after !== '' ? ' AND phone > ?' : ''); $p2 = $p; if ($after !== '') $p2[] = $after;
            $q = $pdo->prepare("SELECT phone, name, bucket, replied, optout, out_n, deliv_n, sent_n, fail_n, in_n, orders_n, first_out, last_out, last_in FROM wa_cfun WHERE $w2 ORDER BY phone LIMIT $lim"); $q->execute($p2);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC); json_response(['ok' => true, 'total' => $total, 'rows' => $rows, 'last' => $rows ? (string)end($rows)['phone'] : '', 'more' => count($rows) === $lim]);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
