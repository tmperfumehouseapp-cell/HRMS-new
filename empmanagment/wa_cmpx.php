<?php
/**
 * wa_cmpx.php — campaign extras that make the campaign section safer and smarter. Admin / HR.
 *
 *   1. 🧪 Test send       wa.cx_test    POST {tpl_id, media_lib_id?, to:[up to 3 numbers]}   the chosen message to YOUR number, with sample details, through the real sender. Not a campaign, not counted in results.
 *   2. 🔁 No-reply follow-ups   wa.cx_fu_get GET {id} · wa.cx_fu_save POST {camp_id, steps:[{d, tpl}]}   up to 3 follow-ups after a campaign: "if they did not reply or order after N days, send message X".
 *        The scan (cx_fu_tick, from the engine round) takes the people whose campaign message was DELIVERED at least N days ago (and not older than N + 10 days), and sends only to those who
 *        have not written to us and have not ordered since, are not opted out, and were not messaged in the last 20 hours. Each person is looked at once per step (table wa_cx_fu_log).
 *        One message is sent with wa_send_tpl_one (the same sender the Autopilot uses): numbers, daily room and Meta limits are respected. Only between 10:00 and 20:00.
 *   3. 🚨 Alerts          wa.cx_alerts  GET   cx_alert_tick (every 5 minutes, from the engine round): delivery falling below the usual rate, a failure spike, Meta holding our messages,
 *        a number with Low quality / not connected, funnel numbers going stale. Shown as a banner on Campaigns; new alerts are also pushed to the Admin / HR phones (once per 3 hours each).
 *   4. 📋 Daily plan       wa.cx_plan GET {budget, target, rate} · wa.cx_roi_run POST   what to send TODAY for a budget: the customers are split into groups (buyers, engaged, delivered-no-reply, new, blocked), each group's
 *        delivery rate and orders per 1,000 delivered come from the last 30 days (cx_roi_refresh, once a day), the budget goes to the groups that order best within the safe frequency of each,
 *        and the answer lists: messages to send, expected delivered / cost / orders, who NOT to send to, how many new numbers to import, and a delivery check of each recent Excel import.
 *   Cheap by design: indexed lookups, small batches, a time budget, one process at a time.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('cx_tables')) {

function cx_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cx_fu (camp_id INT NOT NULL, n TINYINT NOT NULL, after_days INT NOT NULL, tpl_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, PRIMARY KEY (camp_id, n)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $coll = ''; try { $cn = (string)$pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_camp_items' AND COLUMN_NAME = 'phone'")->fetchColumn(); if (preg_match('/^utf8mb4_[a-z0-9_]+$/', $cn)) $coll = ' COLLATE ' . $cn; } catch (Throwable $e) { }
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_cx_fu_log (camp_id INT NOT NULL, n TINYINT NOT NULL, phone VARCHAR(12) NOT NULL, st VARCHAR(8) NOT NULL DEFAULT '', at DATETIME NULL, PRIMARY KEY (camp_id, n, phone), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" . $coll);
        return $ok = true;
    } catch (Throwable $e) { error_log('wa_cmpx: ' . $e->getMessage()); return $ok = false; }
}

// ------------------------------------------------------------------------------------------------------------------------ 1. test send
function cx_test_send(PDO $pdo, int $tplId, int $libId, array $to): array
{
    $to = array_slice(array_values(array_unique(array_filter(array_map(function ($x) { return function_exists('wa_key') ? wa_key($x) : preg_replace('/\D/', '', (string)$x); }, $to), function ($k) { return preg_match('/^[6-9][0-9]{9}$/', (string)$k); }))), 0, 3);
    if (!$to) return ['error' => 'Type a valid 10-digit mobile number to send the test to.'];
    $hr = date('YmdH'); $cnt = (string)setting_get($pdo, 'wa_cx_test_h', ''); [$hh, $nn] = array_pad(explode('|', $cnt), 2, '0'); $nn = $hh === $hr ? (int)$nn : 0;
    if ($nn + count($to) > 12) return ['error' => 'Too many test messages this hour (12 is the limit). Try again later.'];
    $t = wa_camp_tpl($pdo, $tplId, true, $libId); if (!$t) return ['error' => 'Choose an approved message first (if it has a photo, video or PDF on top, choose the file in step 2).'];
    $ctx = wa_camp_ctx($pdo, $t, 2); $out = [];
    foreach ($to as $k) {
        $c = function_exists('wa_contact') ? wa_contact($pdo, $k) : null; if (!$c) $c = ['phone' => $k, 'wa_id' => '91' . $k, 'name' => 'Test', 'camp_optout' => 0];
        $c['camp_optout'] = 0;   // the owner is testing: never blocked by a Stop
        [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if (!$nid || empty($ctx['nums'][$nid])) { $out[] = ['to' => $k, 'ok' => false, 'msg' => 'No number can send right now (' . $why . ').']; continue; }
        $vars = wa_tpl_vars_for($t, 'Test', array_fill(0, max(0, (int)$t['nvars'] - 1), 'sample'));
        [$ok, $res] = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], $vars, 'test');
        $out[] = ['to' => $k, 'ok' => (bool)$ok, 'msg' => $ok ? 'Sent' : (string)$res]; if ($ok) $nn++;
    }
    setting_set($pdo, 'wa_cx_test_h', $hr . '|' . $nn);
    return ['ok' => true, 'results' => $out];
}

// ------------------------------------------------------------------------------------------------------------------------ 2. no-reply follow-ups
/** Looks at people due for a follow-up and sends (at most $cap messages in $budget seconds). Returns what happened. */
function cx_fu_tick(PDO $pdo, int $cap = 100, int $budget = 25): array
{
    if (!cx_tables($pdo)) return [];
    $h = (int)date('G'); if ($h < 10 || $h >= 20) return ['skipped' => 'outside 10:00–20:00'];
    $last = (int)strtotime((string)setting_get($pdo, 'wa_cx_fu_at', '1970-01-01')); if ($last > time() - 50) return ['skipped' => 'ran a moment ago'];
    try { if ((int)$pdo->query("SELECT GET_LOCK('wa_cx_fu', 0)")->fetchColumn() !== 1) return ['busy' => 1]; } catch (Throwable $e) { return ['busy' => 1]; }
    setting_set($pdo, 'wa_cx_fu_at', date('Y-m-d H:i:s')); $t0 = microtime(true); $o = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    try {
        $steps = $pdo->query("SELECT f.camp_id, f.n, f.after_days, f.tpl_id FROM wa_cx_fu f WHERE f.active = 1 ORDER BY f.camp_id, f.n")->fetchAll(PDO::FETCH_ASSOC);
        $ctxs = []; $now = time();
        foreach ($steps as $S) {
            if ($o['sent'] >= $cap || microtime(true) - $t0 > $budget) break;
            $cid = (int)$S['camp_id']; $n = (int)$S['n']; $d = (int)$S['after_days'];
            $t = wa_camp_tpl($pdo, (int)$S['tpl_id'], true, 0); if (!$t || (int)$t['nvars'] > 1) continue;   // the message must be approved and have at most the name as a blank
            $hi = date('Y-m-d H:i:s', $now - $d * 86400); $lo = date('Y-m-d H:i:s', $now - ($d + 10) * 86400);
            $q = $pdo->prepare("SELECT i.phone, i.sent_at FROM wa_camp_items i WHERE i.camp_id = ? AND i.status = 'sent' AND i.last_status IN ('delivered', 'read') AND i.sent_at <= ? AND i.sent_at >= ?
                AND NOT EXISTS (SELECT 1 FROM wa_cx_fu_log l WHERE l.camp_id = i.camp_id AND l.n = ? AND l.phone = i.phone) ORDER BY i.phone LIMIT 600");
            $q->execute([$cid, $hi, $lo, $n]); $cand = $q->fetchAll(PDO::FETCH_ASSOC); if (!$cand) continue;
            $ph = array_map(function ($r) { return (string)$r['phone']; }, $cand); $in = implode(',', array_fill(0, count($ph), '?'));
            $C = []; $st = $pdo->prepare("SELECT * FROM wa_contacts WHERE phone IN ($in)"); $st->execute($ph); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $C[(string)$r['phone']] = $r;
            $Or = []; try { $st = $pdo->prepare("SELECT phone, last_order FROM wa_cust WHERE last_order IS NOT NULL AND phone IN ($in)"); $st->execute($ph); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $Or[(string)$r['phone']] = (string)$r['last_order']; } catch (Throwable $e) { }
            $lg = $pdo->prepare("INSERT IGNORE INTO wa_cx_fu_log (camp_id, n, phone, st, at) VALUES (?, ?, ?, ?, ?)"); $tid = (int)$t['id']; if (!isset($ctxs[$tid])) $ctxs[$tid] = wa_camp_ctx($pdo, $t, 2);
            $stopped = false;
            foreach ($cand as $r) {
                $p = (string)$r['phone']; $sentAt = (string)$r['sent_at']; $c = $C[$p] ?? null;
                if ($o['sent'] >= $cap || microtime(true) - $t0 > $budget) { $stopped = true; break; }
                $why = '';
                if (!$c) $why = 'nocontact'; elseif ((int)$c['optout'] === 1 || (int)($c['camp_optout'] ?? 0) === 1) $why = 'optout';
                elseif ($c['last_in_at'] && (string)$c['last_in_at'] >= $sentAt) $why = 'replied'; elseif (isset($Or[$p]) && $Or[$p] >= $sentAt) $why = 'ordered';
                elseif ($c['last_out_at'] && (string)$c['last_out_at'] > date('Y-m-d H:i:s', $now - 20 * 3600)) $why = 'recent';
                if ($why !== '') { $lg->execute([$cid, $n, $p, $why, date('Y-m-d H:i:s')]); $o['skipped']++; continue; }
                $done = $pdo->prepare("SELECT 1 FROM wa_cx_fu_log WHERE camp_id = ? AND n = ? AND phone = ?"); $done->execute([$cid, $n, $p]); if ($done->fetchColumn()) { continue; }
                [$nid, $wy] = wa_pick_number($pdo, $c, $ctxs[$tid]);
                if ($nid === 0) { if ($wy === 'ignored every number') { $lg->execute([$cid, $n, $p, 'ignored', date('Y-m-d H:i:s')]); $o['skipped']++; continue; } $stopped = true; break; }   // no room now: try again at the next scan
                $vars = wa_tpl_vars_for($t, (string)$c['name'], []);
                [$ok, $res, $ec] = wa_send_tpl_one($pdo, $c, $t, $ctxs[$tid]['nums'][$nid], $vars, 'followup');
                if ($ok) { $ctxs[$tid]['nums'][$nid]['left']--; $ctxs[$tid]['nums'][$nid]['used']++; $ctxs[$tid]['port_left']--; $lg->execute([$cid, $n, $p, 'sent', date('Y-m-d H:i:s')]); $o['sent']++; }
                else { $lg->execute([$cid, $n, $p, 'failed', date('Y-m-d H:i:s')]); $o['failed']++; wa_log($pdo, 'followup', false, 'campaign ' . $cid . ' step ' . $n . ' · ' . $p . ' · ' . $res); if (in_array($ec, [131042, 131048, 132000, 132001, 132005, 132007, 132012, 132015, 132016], true)) { $stopped = true; break; } }
            }
        }
    } catch (Throwable $e) { error_log('cx_fu_tick: ' . $e->getMessage()); $o['error'] = mb_substr($e->getMessage(), 0, 160); }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_cx_fu')"); } catch (Throwable $e) { }
    return $o;
}

// ------------------------------------------------------------------------------------------------------------------------ 3. alerts
function cx_rates(PDO $pdo, string $from, string $to): array
{
    try { $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(10000) */ COUNT(*) n, SUM(status IN ('delivered', 'read')) d, SUM(status = 'failed') f, SUM(status = 'failed' AND err_code IN (131049, 131048)) h FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND ts >= ? AND ts <= ?");
        $q->execute([$from, $to]); $r = $q->fetch(PDO::FETCH_ASSOC) ?: []; return ['n' => (int)($r['n'] ?? 0), 'd' => (int)($r['d'] ?? 0), 'f' => (int)($r['f'] ?? 0), 'h' => (int)($r['h'] ?? 0)];
    } catch (Throwable $e) { return ['n' => 0, 'd' => 0, 'f' => 0, 'h' => 0]; }
}
/** Works out the alerts (every 5 minutes at most), keeps them in one setting, and pushes new ones to the Admin / HR phones. */
function cx_alert_tick(PDO $pdo, bool $force = false): array
{
    $last = (int)strtotime((string)setting_get($pdo, 'wa_cx_alert_at', '1970-01-01')); if (!$force && $last > time() - 290) return ['skipped' => 1];
    setting_set($pdo, 'wa_cx_alert_at', date('Y-m-d H:i:s')); $A = []; $now = time(); $f = function ($s) { return date('Y-m-d H:i:s', $s); };
    try {
        $run = $pdo->query("SELECT id, name FROM wa_campaigns WHERE status = 'running' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($run) {
            $cur = cx_rates($pdo, $f($now - 45 * 60), $f($now - 8 * 60));   // messages sent 8–45 minutes ago (delivery receipts need a few minutes)
            $base = cx_rates($pdo, $f($now - 26 * 3600), $f($now - 2 * 3600)); $b = $base['n'] >= 500 ? $base['d'] / $base['n'] : 0.5; $names = implode(', ', array_map(function ($r) { return (string)$r['name']; }, $run));
            if ($cur['n'] >= 200) {
                $rate = $cur['d'] / $cur['n'];
                if ($rate < max(0.2, 0.6 * $b)) $A[] = ['k' => 'low_delivery', 'level' => 'red', 'text' => 'Delivery has dropped: only ' . round(100 * $rate) . '% of the last ' . $cur['n'] . ' campaign messages were delivered (usually about ' . round(100 * $b) . '%). Running: ' . $names . '.'];
                if ($cur['f'] / $cur['n'] >= 0.35) $A[] = ['k' => 'fail_spike', 'level' => 'red', 'text' => round(100 * $cur['f'] / $cur['n']) . '% of the last ' . $cur['n'] . ' campaign messages failed. Check the numbers and pause if it continues. Running: ' . $names . '.'];
                if ($cur['h'] / $cur['n'] >= 0.15) $A[] = ['k' => 'held', 'level' => 'amber', 'text' => 'Meta is holding ' . round(100 * $cur['h'] / $cur['n']) . '% of the last messages (limit per customer). Fewer messages per customer, or leave these customers out.'];
            }
        }
        if (function_exists('wa_camp_health')) foreach (wa_camp_health($pdo) as $h) {
            $lab = (string)($h['label'] ?? ('Number ' . ($h['id'] ?? ''))); $q = strtoupper((string)($h['quality'] ?? '')); $s = (string)($h['status'] ?? '');
            if ($s !== '' && $s !== 'CONNECTED') $A[] = ['k' => 'num_conn_' . (int)($h['id'] ?? 0), 'level' => 'red', 'text' => $lab . ' is not connected (' . strtolower($s) . ').'];
            elseif ($q === 'RED') $A[] = ['k' => 'num_q_' . (int)($h['id'] ?? 0), 'level' => 'red', 'text' => $lab . ' has Low quality at Meta — sending from it risks a block.'];
        }
        $j = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true); $at = is_array($j) ? (int)strtotime((string)($j['at'] ?? '')) : 0;
        if ($at && $at < $now - 3 * 3600) $A[] = ['k' => 'funnel_stale', 'level' => 'amber', 'text' => 'The customer funnel numbers have not been refreshed for ' . round(($now - $at) / 3600) . ' hours.'];
    } catch (Throwable $e) { error_log('cx_alert_tick: ' . $e->getMessage()); }
    foreach ($A as &$a) $a['at'] = date('Y-m-d H:i:s'); unset($a);
    setting_set($pdo, 'wa_cx_alerts', json_encode($A));
    foreach ($A as $a) {   // a push per alert at most once every 3 hours
        $k = 'wa_cx_pushed_' . $a['k']; if ((int)strtotime((string)setting_get($pdo, $k, '1970-01-01')) > $now - 3 * 3600) continue; setting_set($pdo, $k, date('Y-m-d H:i:s'));
        if (function_exists('wq_admin_ids') && function_exists('push_send_employee')) foreach (wq_admin_ids($pdo) as $eid) { try { push_send_employee($pdo, (int)$eid, ['title' => ($a['level'] === 'red' ? '🚨 ' : '⚠ ') . 'WhatsApp campaign', 'body' => mb_substr($a['text'], 0, 180), 'url' => 'whatsapp.html', 'tag' => 'wa-alert-' . $a['k'], 'actions' => []]); } catch (Throwable $e) { } }
    }
    return ['alerts' => count($A)];
}

// ------------------------------------------------------------------------------------------------------------------------ 4. daily plan
function cx_seg_labels(): array
{
    return ['buyers_active' => ['Active buyers', 'New, repeat and loyal buyers (last order within 90 days)', 12, ['fv' => ['new', 'repeat', 'loyal'], 'fh' => ['ok']]],
        'engaged' => ['Engaged, not yet bought', 'Wrote to us but never ordered', 8, ['fc' => ['replied'], 'ord' => '0', 'fh' => ['ok']]],
        'buyers_winback' => ['Win-back buyers', 'One-time, at-risk, lapsed and past buyers', 14, ['fv' => ['one', 'atrisk', 'lapsed', 'past'], 'fh' => ['ok']]],
        'delivered' => ['Delivered, no reply', 'A message reached them, they have not replied', 21, ['fg' => ['deliv'], 'fh' => ['ok']]],
        'cold' => ['New / never delivered', 'Never messaged, or sent but nothing delivered yet', 1, ['fg' => ['never', 'sent'], 'fh' => ['ok']]]];
}
function cx_seg_of(array $r): string
{
    if ((string)$r['health'] !== 'ok' && (string)$r['health'] !== '') return 'blocked';
    $v = (string)$r['val']; if (in_array($v, ['new', 'repeat', 'loyal'], true)) return 'buyers_active'; if (in_array($v, ['one', 'atrisk', 'lapsed', 'past'], true)) return 'buyers_winback';
    if ((int)$r['replied'] === 1) return 'engaged'; if ((string)$r['grp'] === 'deliv') return 'delivered'; if (in_array((string)$r['grp'], ['never', 'sent'], true)) return 'cold'; return 'other';
}
/** The size and the delivery rate of every group (one pass over the funnel table; kept 10 minutes). */
function cx_seg_sizes(PDO $pdo): array
{
    $c = json_decode((string)setting_get($pdo, 'wa_cx_segs', ''), true); if (is_array($c) && (int)($c['t'] ?? 0) > time() - 600) return $c['v'];
    $v = [];
    try { foreach ($pdo->query("SELECT /*+ MAX_EXECUTION_TIME(30000) */ seg, COUNT(*) n, SUM(out_n) o, SUM(deliv_n) d FROM (SELECT CASE WHEN health NOT IN ('ok', '') THEN 'blocked' WHEN val IN ('new', 'repeat', 'loyal') THEN 'buyers_active' WHEN val IN ('one', 'atrisk', 'lapsed', 'past') THEN 'buyers_winback' WHEN replied = 1 THEN 'engaged' WHEN grp = 'deliv' THEN 'delivered' WHEN grp IN ('never', 'sent') THEN 'cold' ELSE 'other' END seg, out_n, deliv_n FROM wa_cfun) x GROUP BY seg")->fetchAll(PDO::FETCH_ASSOC) as $r) $v[(string)$r['seg']] = ['n' => (int)$r['n'], 'o' => (int)$r['o'], 'd' => (int)$r['d']]; } catch (Throwable $e) { }
    setting_set($pdo, 'wa_cx_segs', json_encode(['t' => time(), 'v' => $v])); return $v;
}
/** A customer's group BEFORE the order we are counting (so an order cannot move the customer into the group that "explains" it):
 *  buyers_active = ordered before (2+ orders, or one order 0–90 days before the window) · buyers_winback = ordered long before, or no known date · engaged = no earlier order, wrote to us · delivered = everybody else. */
function cx_pre_seg(?array $cf, ?array $cu, string $since): string
{
    $n = $cu ? (int)$cu['n'] : 0; $lo = $cu ? (string)$cu['last_order'] : ''; $co = $cf ? (int)$cf['orders_n'] : 0;
    if ($n >= 2) return 'buyers_active';
    if ($n === 1 && $lo !== '' && $lo < $since) return $lo >= date('Y-m-d H:i:s', strtotime($since) - 90 * 86400) ? 'buyers_active' : 'buyers_winback';
    if ($n === 0 && $co >= 1) return 'buyers_winback';
    return ($cf && (int)$cf['replied'] === 1) ? 'engaged' : 'delivered';
}
/** The 30-day study: for every group (as it was BEFORE the order), how many customers got a delivered campaign message, how many delivered messages, and how many online orders followed within 7 days. Once a day. */
function cx_roi_refresh(PDO $pdo, int $budget = 80): array
{
    $t0 = microtime(true); $since = date('Y-m-d H:i:s', time() - 30 * 86400); $S = []; $add = function ($k, $f, $n) use (&$S) { if (!isset($S[$k])) $S[$k] = ['cust' => 0, 'deliv' => 0, 'orders' => 0, 'rev' => 0.0]; $S[$k][$f] += $n; };
    try { if ((int)$pdo->query("SELECT GET_LOCK('wa_cx_roi', 0)")->fetchColumn() !== 1) return ['busy' => 1]; } catch (Throwable $e) { return ['busy' => 1]; }
    try {
        @set_time_limit($budget + 30); $get = function ($sql, $p) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); };
        $rows = $pdo->query("SELECT /*+ MAX_EXECUTION_TIME(60000) */ phone, COUNT(*) c FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND status IN ('delivered', 'read') AND ts >= '$since' GROUP BY phone")->fetchAll(PDO::FETCH_ASSOC);
        foreach (array_chunk($rows, 2500) as $ch) { if (microtime(true) - $t0 > $budget) return ['partial' => 1]; $ph = array_map(function ($r) { return (string)$r['phone']; }, $ch); $in = cfun_in($ph);
            $F = []; foreach ($get("SELECT phone, replied, orders_n FROM wa_cfun WHERE phone IN ($in)", $ph) as $r) $F[(string)$r['phone']] = $r;
            $U = []; try { foreach ($get("SELECT phone, n, last_order FROM wa_cust WHERE phone IN ($in)", $ph) as $r) $U[(string)$r['phone']] = $r; } catch (Throwable $e) { }
            foreach ($ch as $r) { $k = cx_pre_seg($F[(string)$r['phone']] ?? null, $U[(string)$r['phone']] ?? null, $since); $add($k, 'cust', 1); $add($k, 'deliv', (int)$r['c']); } }
        $ord = $pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE COALESCE(tags, '') NOT LIKE '%wa-rx%' AND created_at >= '$since' AND phone IS NOT NULL AND phone <> ''")->fetchAll(PDO::FETCH_ASSOC); $q = $pdo->prepare("SELECT MAX(ts) FROM wa_messages WHERE phone = ? AND dir = 'out' AND who = 'campaign' AND status IN ('delivered', 'read') AND ts <= ? AND ts >= ?");
        $fq = $pdo->prepare("SELECT replied, orders_n FROM wa_cfun WHERE phone = ?"); $uq = $pdo->prepare("SELECT n, last_order FROM wa_cust WHERE phone = ?"); $att = 0; $seen = [];
        foreach ($ord as $o) { if (microtime(true) - $t0 > $budget + 20) break; $k = substr(preg_replace('/\\D+/', '', (string)$o['phone']), -10); if (strlen($k) !== 10) continue; $at = (string)$o['created_at']; $q->execute([$k, $at, date('Y-m-d H:i:s', strtotime($at) - 7 * 86400)]); if (!$q->fetchColumn()) continue;
            $fq->execute([$k]); $cf = $fq->fetch(PDO::FETCH_ASSOC) ?: null; $uq->execute([$k]); $cu = $uq->fetch(PDO::FETCH_ASSOC) ?: null; $seg = cx_pre_seg($cf, $cu, $since); $add($seg, 'orders', 1); $add($seg, 'rev', (float)$o['total']); $att++; }
        $tot = ['deliv' => 0, 'orders' => $att, 'cust' => 0]; foreach ($S as $x) { $tot['deliv'] += $x['deliv']; $tot['cust'] += $x['cust']; }
        setting_set($pdo, 'wa_cx_roi', json_encode(['v' => 2, 'at' => date('Y-m-d H:i:s'), 'seg' => $S, 'tot' => $tot, 'orders_all' => count($ord)]));
        $o = ['ok' => 1, 'sec' => round(microtime(true) - $t0, 1)];
    } catch (Throwable $e) { error_log('cx_roi_refresh: ' . $e->getMessage()); $o = ['error' => mb_substr($e->getMessage(), 0, 160)]; }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_cx_roi')"); } catch (Throwable $e) { }
    return $o;
}
function cx_roi_tick(PDO $pdo): array
{
    $j = json_decode((string)setting_get($pdo, 'wa_cx_roi', ''), true); $at = is_array($j) ? (int)strtotime((string)($j['at'] ?? '')) : 0; $h = (int)date('G');
    if ((int)($j['v'] ?? 0) >= 2 && ($at > time() - 22 * 3600 || ($at && ($h < 2 || $h > 6)))) return ['skipped' => 1];   // an old-method study (v1) is redone at once   // once a day, in the quiet hours (the very first study runs at once)
    return cx_roi_refresh($pdo, 70);
}
/** The plan for a budget. */
function cx_plan(PDO $pdo, float $budget, int $target, float $rate): array
{
    $L = cx_seg_labels(); $Z = cx_seg_sizes($pdo); $j = json_decode((string)setting_get($pdo, 'wa_cx_roi', ''), true); $R = is_array($j) && (int)($j['v'] ?? 0) >= 2 ? $j : ['seg' => [], 'tot' => ['deliv' => 0, 'orders' => 0, 'cust' => 0]];
    $totD = max(1, (int)($R['tot']['deliv'] ?? 0)); $base = (int)($R['tot']['deliv'] ?? 0) > 0 ? 1000 * (int)($R['tot']['orders'] ?? 0) / $totD : 1.6;
    $D = $budget / max(0.1, $rate); $left = $D; $rows = []; $order = array_keys($L); $conv = [];
    foreach ($order as $k) { $x = $R['seg'][$k === 'cold' ? 'delivered' : $k] ?? null; $m = $x && $x['deliv'] >= 3000 && $x['orders'] >= 3; $conv[$k] = ['v' => $m ? 1000 * $x['orders'] / $x['deliv'] : $base, 'src' => $m ? 'measured' : 'assumed', 'deliv' => $x['deliv'] ?? 0, 'orders' => $x['orders'] ?? 0]; }
    usort($order, function ($a, $b) use ($conv) { return $conv[$b]['v'] <=> $conv[$a]['v']; });
    foreach ($order as $k) {
        $z = $Z[$k] ?? ['n' => 0, 'o' => 0, 'd' => 0]; $dr = $z['o'] > 100 ? max(0.2, min(0.95, $z['d'] / $z['o'])) : 0.6; $cad = (int)$L[$k][2];
        $cap = $k === 'cold' ? min(0.3 * $D, $z['n'] * $dr) : $z['n'] * $dr / $cad;   // delivered messages a day this group can take at its safe frequency
        $dl = max(0, min($cap, $left)); $left -= $dl;
        $rows[] = ['k' => $k, 'label' => $L[$k][0], 'about' => $L[$k][1], 'reach' => $z['n'], 'every' => $cad, 'rate' => round($dr, 2), 'cap' => (int)round($cap), 'send' => (int)round($dl / $dr), 'delivered' => (int)round($dl), 'cost' => (int)round($dl * $rate), 'conv' => round($conv[$k]['v'], 2), 'conv_src' => $conv[$k]['src'], 'orders' => round($dl * $conv[$k]['v'] / 1000, 1), 'cf' => $L[$k][3]];
    }
    $sumD = 0; $sumO = 0; foreach ($rows as $r) { $sumD += $r['delivered']; $sumO += $r['orders']; }
    $bl = $Z['blocked'] ?? ['n' => 0, 'o' => 0, 'd' => 0]; $cold = null; foreach ($rows as $r) if ($r['k'] === 'cold') $cold = $r;
    $o = ['ok' => true, 'budget' => $budget, 'rate' => $rate, 'target' => $target, 'delivered_target' => (int)round($D), 'rows' => $rows, 'delivered' => $sumD, 'cost' => (int)round($sumD * $rate), 'orders' => round($sumO, 1), 'base' => round($base, 2), 'study_at' => (string)($R['at'] ?? ''), 'blocked' => (int)$bl['n']];
    $o['unspent'] = (int)round(max(0, $budget - $sumD * $rate));
    if ($o['unspent'] > 1000) $o['short_customers'] = (int)round(($D - $sumD) / 0.7 * 10);   // customers who can receive that would let the whole budget be used at a ten-day rhythm
    $o['need_budget'] = $sumO > 0 ? (int)round($target / $sumO * $sumD * $rate) : 0;
    $o['import_per_day'] = $cold ? (int)round($cold['delivered'] / 0.44) : 0;   // 44% of imported numbers got at least one delivered message
    $o['batches'] = []; $s = json_decode((string)setting_get($pdo, 'wa_cfun_stats', ''), true);
    foreach (array_slice((array)($s['by']['all']['batches'] ?? []), 0, 8) as $b) { $t = (int)$b['dl'] + (int)$b['nd']; $r = $t > 0 ? $b['dl'] / $t : null; $o['batches'][] = ['b' => $b['b'], 'n' => (int)$b['n'], 'messaged' => $t, 'rate' => $r === null ? null : round($r, 2), 'ok' => $r === null ? null : $r >= 0.35]; }
    return $o;
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- router
$__cxa = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__cxa, 'wa.cx_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !cx_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    switch ($__cxa) {
        case 'wa.cx_test': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); @set_time_limit(60);
            $to = is_array($in['to'] ?? null) ? $in['to'] : preg_split('/[\s,;]+/', (string)($in['to'] ?? ''));
            json_response(cx_test_send($pdo, (int)($in['tpl_id'] ?? 0), max(0, (int)($in['media_lib_id'] ?? 0)), (array)$to));
        }
        case 'wa.cx_fu_get': {
            $id = (int)($_GET['id'] ?? 0); $q = $pdo->prepare("SELECT n, after_days, tpl_id FROM wa_cx_fu WHERE camp_id = ? AND active = 1 ORDER BY n"); $q->execute([$id]); $steps = $q->fetchAll(PDO::FETCH_ASSOC);
            $c = $pdo->prepare("SELECT n, st, COUNT(*) c FROM wa_cx_fu_log WHERE camp_id = ? GROUP BY n, st"); $c->execute([$id]); $log = $c->fetchAll(PDO::FETCH_ASSOC);
            json_response(['ok' => true, 'steps' => $steps, 'log' => $log]);
        }
        case 'wa.cx_fu_save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $in = json_input(); $cid = (int)($in['camp_id'] ?? 0); if ($cid <= 0) json_response(['error' => 'Save the campaign first.'], 422);
            $steps = array_slice(is_array($in['steps'] ?? null) ? $in['steps'] : [], 0, 3); $clean = []; $prev = 0;
            foreach ($steps as $s) { $d = (int)($s['d'] ?? 0); $tid = (int)($s['tpl'] ?? 0); if ($d < 1 || $d > 30 || $tid <= 0) continue; if ($d <= $prev) json_response(['error' => 'Each follow-up must come later than the one before it.'], 422);
                $t = wa_camp_tpl($pdo, $tid, true, 0); if (!$t) json_response(['error' => 'A follow-up message is not approved (or needs a photo / video on top, which follow-ups cannot carry).'], 422); if ((int)$t['nvars'] > 1) json_response(['error' => 'A follow-up message can only have the customer name as a blank.'], 422);
                $clean[] = [$d, $tid]; $prev = $d; }
            $pdo->prepare("DELETE FROM wa_cx_fu WHERE camp_id = ?")->execute([$cid]); $n = 0; $ins = $pdo->prepare("INSERT INTO wa_cx_fu (camp_id, n, after_days, tpl_id, active, created_at) VALUES (?, ?, ?, ?, 1, ?)");
            foreach ($clean as [$d, $tid]) { $n++; $ins->execute([$cid, $n, $d, $tid, date('Y-m-d H:i:s')]); }
            json_response(['ok' => true, 'steps' => $n]);
        }
        case 'wa.cx_plan': {
            $b = max(1000, min(5000000, (float)($_GET['budget'] ?? 100000))); $t = max(1, min(5000, (int)($_GET['target'] ?? 200))); $r = max(0.1, min(10, (float)($_GET['rate'] ?? 1.09)));
            $p = cx_plan($pdo, $b, $t, $r); $jj = json_decode((string)setting_get($pdo, 'wa_cx_roi', ''), true); $p['study_pending'] = !(is_array($jj) && (int)($jj['v'] ?? 0) >= 2); json_response($p);   // the very first study is done by the engine in the background (about a minute)
        }
        case 'wa.cx_roi_run': { ignore_user_abort(true); if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); json_response(['ok' => true] + cx_roi_refresh($pdo, 90)); }
        case 'wa.cx_alerts': {
            cx_alert_tick($pdo); $a = json_decode((string)setting_get($pdo, 'wa_cx_alerts', '[]'), true); json_response(['ok' => true, 'alerts' => is_array($a) ? $a : [], 'at' => (string)setting_get($pdo, 'wa_cx_alert_at', '')]);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
