<?php
/**
 * One line per module for the top of the Campaigns page (owner 24 Nov 2026): A, B, C, Cold, Lineup, After-Order — sent today, this hour, the limit, failed, replies.
 * Counts only, read-only, cached 60 seconds. Replies come from the message-pattern report (wa_pat.php) when it is fresh.
 */
if (!function_exists('wa_strip_report')) {
    function wa_strip_report(PDO $pdo, string $only = ''): array
    {
        $ck = 'wa_strip_cache' . ($only !== '' ? '_' . preg_replace('/\W/', '', $only) : ''); $c = json_decode((string)setting_get($pdo, $ck, ''), true);
        if (is_array($c) && !empty($c['t']) && time() - (int)$c['t'] < 60) return $c;
        try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 2500"); } catch (Throwable $e) { }
        $t0 = date('Y-m-d 00:00:00'); $h1 = date('Y-m-d H:i:s', time() - 3600); $one = function ($sql, $a = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetch(PDO::FETCH_NUM) ?: []; } catch (Throwable $e) { return null; } };
        $g = function ($k, $d = '') use ($pdo) { try { return (string)setting_get($pdo, $k, $d); } catch (Throwable $e) { return $d; } };
        $rep = []; $pc = json_decode($g('wa_pat_cache_today'), true);
        if (is_array($pc) && !empty($pc['t']) && time() - (int)$pc['t'] < 3600 && date('Y-m-d', (int)$pc['t']) === date('Y-m-d')) foreach ($pc['modules'] as $m) $rep[$m['key']] = [(int)$m['sent'], (int)$m['replied']];
        $mods = [];
        if ($only === '' || $only === 'moda') { $a1 = $one("SELECT COUNT(*) FROM wa_camp_items WHERE id > (SELECT MAX(id) - 400000 FROM wa_camp_items) AND sent_at >= ? AND status = 'sent'", [$t0]); $a2 = $one("SELECT COUNT(*) FROM wa_camp_items WHERE id > (SELECT MAX(id) - 400000 FROM wa_camp_items) AND sent_at >= ? AND status = 'sent'", [$h1]); $a3 = $one("SELECT COUNT(*), SUM(status = 'failed') FROM wa_messages WHERE id > (SELECT MAX(id) - 60000 FROM wa_messages) AND dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ?", [$h1]);
        $mods[] = ['key' => 'moda', 'label' => 'Module A', 'sub' => 'campaigns', 'today' => (int)($a1[0] ?? 0), 'hour' => (int)($a2[0] ?? 0), 'limit' => (int)$g('wa_dq_hourly', '0'), 'fail' => ($a3 && (int)$a3[0] > 0) ? round(100 * (int)$a3[1] / (int)$a3[0]) : null]; }
        if ($only === '' || $only === 'modb') { $b1 = $one("SELECT SUM(ok = 1), SUM(ok = 0) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND kind <> 'cnev' AND at >= ?", [$t0]); $b2 = $one("SELECT COUNT(*) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND ok = 1 AND kind <> 'cnev' AND at >= ?", [$h1]);
        $mods[] = ['key' => 'modb', 'label' => 'Module B', 'sub' => 'utility follow-up', 'today' => (int)($b1[0] ?? 0), 'hour' => (int)($b2[0] ?? 0), 'limit' => (int)$g('wa_ru_hourly', '0'), 'fail' => ($b1 && ((int)$b1[0] + (int)$b1[1]) > 0) ? round(100 * (int)$b1[1] / ((int)$b1[0] + (int)$b1[1])) : null]; }
        if ($only === '' || $only === 'modc') { $c1 = $one("SELECT SUM(ok = 1), SUM(ok = 0) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND kind = 'cnev' AND at >= ?", [$t0]); $c2 = $one("SELECT COUNT(*) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND ok = 1 AND kind = 'cnev' AND at >= ?", [$h1]);
        $mods[] = ['key' => 'modc', 'label' => 'Module C', 'sub' => 'never wrote', 'today' => (int)($c1[0] ?? 0), 'hour' => (int)($c2[0] ?? 0), 'limit' => (int)$g('wa_rc_level', '0'), 'fail' => ($c1 && ((int)$c1[0] + (int)$c1[1]) > 0) ? round(100 * (int)$c1[1] / ((int)$c1[0] + (int)$c1[1])) : null]; }
        if ($only === '' || $only === 'cold') { $d1 = $one("SELECT SUM(ok = 1), SUM(ok = 0) FROM wa_cold_log WHERE id > (SELECT MAX(id) - 150000 FROM wa_cold_log) AND at >= ?", [$t0]); $d2 = $one("SELECT COUNT(*) FROM wa_cold_log WHERE id > (SELECT MAX(id) - 150000 FROM wa_cold_log) AND ok = 1 AND at >= ?", [$h1]);
        $mods[] = ['key' => 'cold', 'label' => 'Cold', 'sub' => 'cold funnel', 'today' => (int)($d1[0] ?? 0), 'hour' => (int)($d2[0] ?? 0), 'limit' => (int)$g('wcd_cap', '40000'), 'limit_day' => 1, 'fail' => ($d1 && ((int)$d1[0] + (int)$d1[1]) > 0) ? round(100 * (int)$d1[1] / ((int)$d1[0] + (int)$d1[1])) : null]; }
        if ($only === '' || $only === 'line') { $e1 = $one("SELECT COUNT(*) FROM wa_line_plan WHERE id > (SELECT MAX(id) - 400000 FROM wa_line_plan) AND status = 'sent' AND sent_at >= ?", [$t0]); $e2 = $one("SELECT COUNT(*) FROM wa_line_plan WHERE id > (SELECT MAX(id) - 400000 FROM wa_line_plan) AND status = 'sent' AND sent_at >= ?", [$h1]);
        $mods[] = ['key' => 'line', 'label' => 'Lineup', 'sub' => '30-day', 'today' => (int)($e1[0] ?? 0), 'hour' => (int)($e2[0] ?? 0), 'limit' => 0, 'fail' => null]; }
        if ($only === '' || $only === 'after') { $f1 = $one("SELECT COUNT(*) FROM wa_aft_plan WHERE id > (SELECT MAX(id) - 300000 FROM wa_aft_plan) AND status = 'sent' AND sent_at >= ?", [$t0]); $f2 = $one("SELECT COUNT(*) FROM wa_aft_plan WHERE id > (SELECT MAX(id) - 300000 FROM wa_aft_plan) AND status = 'sent' AND sent_at >= ?", [$h1]);
        $mods[] = ['key' => 'after', 'label' => 'After-Order', 'sub' => 'after delivery', 'today' => (int)($f1[0] ?? 0), 'hour' => (int)($f2[0] ?? 0), 'limit' => (int)$g('wa_aft_cap', '0'), 'limit_day' => 1, 'fail' => null]; }
        foreach ($mods as &$m) { $m['replies'] = isset($rep[$m['key']]) ? $rep[$m['key']][1] : null; } unset($m);
        if ($only !== '' && function_exists('wa_strip_detail') && isset($mods[0])) { try { $mods[0]['detail'] = wa_strip_detail($pdo, $only); } catch (Throwable $e) { } }
        $out = ['t' => time(), 'modules' => $mods];
        try { setting_set($pdo, $ck, json_encode($out)); } catch (Throwable $e) { }
        return $out;
    }
}
if (!function_exists('wa_strip_detail')) {
    /** The numbers to check inside a module line (owner 24 Nov): [[label, value, hint]]. Only the module that is asked for is worked out; the slow counts are kept for 10 minutes. */
    function wa_strip_detail(PDO $pdo, string $mod): array
    {
        try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 6000"); } catch (Throwable $e) { }
        $g = function ($k, $d = '') use ($pdo) { try { return (string)setting_get($pdo, $k, $d); } catch (Throwable $e) { return $d; } };
        $all = function ($sql, $a = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return null; } };
        $n = function ($v) { return number_format((int)$v); }; $pct = function ($a, $b) { return $b > 0 ? round(100 * $a / $b, 1) . '%' : '–'; };
        $d = [];
        if ($mod === 'moda') {
            $m10 = date('Y-m-d H:i:s', time() - 600); $m30 = date('Y-m-d H:i:s', time() - 1800); $m60 = date('Y-m-d H:i:s', time() - 3600); $t0 = date('Y-m-d 00:00:00');
            $r = $all("SELECT SUM(ts >= ? AND status <> 'failed'), SUM(ts >= ? AND status = 'failed'), SUM(ts >= ? AND status <> 'failed'), SUM(ts >= ? AND status = 'failed'), SUM(status <> 'failed'), SUM(status = 'failed') FROM wa_messages WHERE id > (SELECT MAX(id) - 60000 FROM wa_messages) AND dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ?", [$m10, $m10, $m30, $m30, $m60]);
            if ($r && $r[0]) { $x = $r[0]; $d[] = ['Pushed last 10 min', $n($x[0]) . ' ok · ' . $n($x[1]) . ' failed', $pct($x[1], $x[0] + $x[1]) . ' failed']; $d[] = ['Pushed last 30 min', $n($x[2]) . ' ok · ' . $n($x[3]) . ' failed', $pct($x[3], $x[2] + $x[3]) . ' failed']; $d[] = ['Pushed last 60 min', $n($x[4]) . ' ok · ' . $n($x[5]) . ' failed', $pct($x[5], $x[4] + $x[5]) . ' failed (danger above 65%)']; }
            $d[] = ['Plan (this hour allowance)', $n($g('wa_dq_hourly', '0')) . '/h', 'base ' . $n($g('wa_dq_base', '0')) . ' · floor ' . $n($g('wa_dq_floor', '0')) . ' · top ' . $n($g('wa_dq_top', '0')) . ' · steps 11k → 13k → 15k'];
            $hold = (int)$g('wa_dq_hold_until', '0'); $d[] = ['Meta spam hold (131048)', $hold > time() ? 'ON until ' . date('H:i', $hold) : 'none', 'a spam flag pauses campaigns for 3 hours'];
            $nr = $all("SELECT number_id, COUNT(*), SUM(status = 'failed'), SUM(err_code = 131049) FROM wa_messages WHERE id > (SELECT MAX(id) - 250000 FROM wa_messages) AND dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? GROUP BY number_id ORDER BY 2 DESC LIMIT 8", [$t0]);
            if ($nr) { $names = []; foreach ($all("SELECT id, label, display FROM wa_numbers") ?: [] as $w) $names[(int)$w[0]] = trim((string)$w[1]) !== '' ? (string)$w[1] : (string)$w[2]; foreach ($nr as $w) $d[] = ['Number: ' . ($names[(int)$w[0]] ?? ('#' . $w[0])), $n($w[1]) . ' sent today', $pct($w[2], $w[1]) . ' failed · ' . $n($w[3]) . ' held by Meta (131049)']; }
            $pend = json_decode($g('wa_strip_pend_a'), true);
            if (!is_array($pend) || (int)($pend['at'] ?? 0) < time() - 600) { $rq = $all("SELECT COUNT(*), COUNT(DISTINCT i.phone) FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE c.status = 'running' AND i.status = 'queued'"); $rc = $all("SELECT status, COUNT(*) FROM wa_campaigns WHERE COALESCE(started_at, created_at) >= ? GROUP BY status", [date('Y-m-d H:i:s', time() - 10 * 86400)]); if ($rq) { $pend = ['at' => time(), 'items' => (int)$rq[0][0], 'people' => (int)$rq[0][1], 'st' => $rc ?: []]; try { setting_set($pdo, 'wa_strip_pend_a', json_encode($pend)); } catch (Throwable $e) { } } }
            if (is_array($pend) && isset($pend['items'])) { $st = []; foreach ($pend['st'] ?? [] as $w) $st[] = $w[0] . ' ' . $w[1]; $d[] = ['Still waiting to be sent', $n($pend['items']) . ' messages · ' . $n($pend['people']) . ' people', 'campaigns (10 days): ' . implode(' · ', $st) . ' · counted ' . date('H:i', (int)$pend['at'])]; }
            $pre = [];
            $st = $all("SELECT SUM(status IN ('delivered','read')), SUM(status = 'read'), SUM(status = 'sent'), SUM(status = 'failed'), COUNT(*) FROM wa_messages WHERE id > (SELECT MAX(id) - 250000 FROM wa_messages) AND dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ?", [$t0]);
            if ($st && $st[0] && (int)$st[0][4] > 0) { $x = $st[0]; $pre[] = ['Messages today', $n($x[4]) . ' released', 'delivered ' . $n($x[0]) . ' (' . $pct($x[0], $x[4]) . ') · read ' . $n($x[1]) . ' · on the way ' . $n($x[2]) . ' · failed ' . $n($x[3]) . ' (' . $pct($x[3], $x[4]) . ')']; }
            $cs = $all("SELECT COUNT(DISTINCT camp_id), COUNT(*), COUNT(DISTINCT HOUR(sent_at)), MIN(sent_at) FROM wa_camp_items WHERE id > (SELECT MAX(id) - 400000 FROM wa_camp_items) AND sent_at >= ? AND status = 'sent'", [$t0]);
            if ($cs && $cs[0] && (int)$cs[0][1] > 0) { $x = $cs[0]; $hh = max(1, (int)$x[2]); $pre[] = ['Campaigns that sent today', $n($x[0]), 'average ' . $n((int)round($x[1] / $hh)) . ' per active hour (' . $hh . ' hours) · first send ' . date('H:i', strtotime((string)$x[3]))]; }
            $au = $all("SELECT c.name, COUNT(i.id) FROM wa_campaigns c JOIN wa_camp_items i ON i.camp_id = c.id WHERE c.created_at >= ? GROUP BY c.id, c.name ORDER BY 2 DESC LIMIT 6", [$t0]);
            if ($au !== null) { $tot = 0; $nm = []; foreach ($au as $w) { $tot += (int)$w[1]; $nm[] = mb_substr((string)$w[0], 0, 22) . ' ' . $n($w[1]); } $pre[] = ['Audience added today', $n($tot) . ' people', $au ? count($au) . ' new campaign(s): ' . implode(' · ', $nm) : 'no new campaign today']; }
            $d = array_merge($pre, $d);
        } elseif ($mod === 'modb') {
            $t0 = date('Y-m-d 00:00:00'); $r = $all("SELECT HOUR(at), COUNT(*) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND ok = 1 AND kind <> 'cnev' AND at >= ? GROUP BY 1 ORDER BY 1 DESC LIMIT 4", [$t0]);
            $d[] = ['Level (speed limit)', $n($g('wa_ru_hourly', '0')) . '/h', 'steps up when the last hours were fast and clean'];
            if ($r) $d[] = ['Last hours (hour: sent)', implode(' · ', array_map(function ($w) { return $w[0] . ':00 → ' . number_format((int)$w[1]); }, array_reverse($r))), 'today'];
            $last = json_decode($g('wa_ru_last'), true); if (is_array($last)) { $why = []; foreach ($last['why'] ?? [] as $k => $v) $why[] = $k . ' ' . $v; $d[] = ['Last round', 'looked ' . $n($last['looked'] ?? 0) . ' · sent ' . $n($last['sent'] ?? 0) . ' · failed ' . $n($last['failed'] ?? 0), implode(' · ', $why)]; }
            $pool = json_decode($g('wa_strip_pool_b'), true);
            if (!is_array($pool) || (int)($pool['at'] ?? 0) < time() - 900) { $dd = date('Y-m-d H:i:s', time() - 8 * 86400); $rp = $all("SELECT COUNT(*), COUNT(DISTINCT i.phone) FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE COALESCE(c.started_at, c.created_at) >= ? AND (i.status = 'failed' OR i.status = 'retry' OR (i.status = 'sent' AND i.last_status = 'failed')) AND i.err_code IN (131049, 131050, 130472) AND COALESCE(i.variant, '') <> 'U' AND NOT EXISTS (SELECT 1 FROM wa_ru_log r WHERE r.item_id = i.id)", [$dd]); if ($rp) { $pool = ['at' => time(), 'items' => (int)$rp[0][0], 'people' => (int)$rp[0][1]]; try { setting_set($pdo, 'wa_strip_pool_b', json_encode($pool)); } catch (Throwable $e) { } } }
            if (is_array($pool) && isset($pool['items'])) { $hr = max(1, (int)(($all("SELECT COUNT(*) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 30000 FROM wa_ru_log) AND ok = 1 AND kind <> 'cnev' AND at >= ?", [date('Y-m-d H:i:s', time() - 3600)])[0][0] ?? 0))); $d[] = ['Left to send (failed marketing, no utility yet)', $n($pool['items']) . ' messages · ' . $n($pool['people']) . ' people', 'at the last hour\'s speed (' . $n($hr) . '/h) about ' . round($pool['people'] / $hr, 1) . ' hours · counted ' . date('H:i', (int)$pool['at'])]; }
            $d[] = ['Filter "delivered another way today"', $g('wa_ru_got_sql', '1') === '0' ? 'OFF' : 'on', 'off = more people are looked at and skipped in each round'];
            $pre = [];
            $bc = $all("SELECT COUNT(DISTINCT r.camp_id), MIN(c.started_at), MAX(c.started_at), COUNT(*) FROM wa_ru_log r JOIN wa_campaigns c ON c.id = r.camp_id WHERE r.id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND r.ok = 1 AND r.kind <> 'cnev' AND r.at >= ?", [$t0]);
            if ($bc && $bc[0] && (int)$bc[0][3] > 0) { $x = $bc[0]; $pre[] = ['Worked on today', $n($x[0]) . ' campaigns', 'those campaigns started from ' . date('d M H:i', strtotime((string)$x[1])) . ' to ' . date('d M H:i', strtotime((string)$x[2])) . ' · ' . $n($x[3]) . ' utility messages sent for them']; }
            $pw = $all("SELECT COUNT(*), MIN(COALESCE(started_at, created_at)), MAX(COALESCE(started_at, created_at)) FROM wa_campaigns WHERE COALESCE(started_at, created_at) >= ? AND status IN ('done','running')", [date('Y-m-d H:i:s', time() - 8 * 86400)]);
            if ($pw && $pw[0]) $pre[] = ['Campaigns it can use (last 8 days)', $n($pw[0][0]), date('d M', strtotime((string)$pw[0][1])) . ' → ' . date('d M', strtotime((string)$pw[0][2])) . ' · only failed marketing people of these'];
            $d = array_merge($pre, $d);
        } elseif ($mod === 'modc') {
            if (!function_exists('wa_rc_overview')) require_once __DIR__ . '/wa_modc.php'; $o = wa_rc_overview($pdo); $R = $o['receipts'] ?? [];
            $d[] = ['Level (speed limit)', $n($o['hourly'] ?? 0) . '/h', 'steps: ' . implode(' → ', array_map('number_format', (array)($o['ladder'] ?? [])))];
            $d[] = ['Sent', $n($o['sent_today'] ?? 0) . ' today · ' . $n($o['sent_hour'] ?? 0) . ' last hour', 'failed today ' . $n($o['failed_today'] ?? 0)];
            $d[] = ['Receipts today', 'delivered ' . $n($R['delivered'] ?? 0) . ' · read ' . $n($R['read'] ?? 0) . ' · sent ' . $n($R['sent'] ?? 0) . ' · failed ' . $n($R['failed'] ?? 0), 'delivered + read = reached the phone'];
            $d[] = ['Replied today', $n($o['replied_today'] ?? 0), 'people who wrote back after a Module C message'];
            $d[] = ['Waiting to get a message', ($o['pending'] !== null ? $n($o['pending']) . ' people' : 'counting…'), 'never wrote, marketing failed, no B/C message yet · since ' . substr((string)($o['from'] ?? ''), 0, 10)];
            $ll = $o['lad_last'] ?? null; if (is_array($ll)) $d[] = ['Last speed decision', date('H:i', (int)($ll['at'] ?? 0)) . ' · ' . $n($ll['n'] ?? 0) . ' sent · ' . $n($ll['f'] ?? 0) . ' failed', 'level ' . $n($ll['level'] ?? 0) . ' ' . (string)($ll['why'] ?? '')];
            $L = $o['last'] ?? null; if (is_array($L)) { $why = []; foreach ($L['why'] ?? [] as $k => $v) $why[] = $k . ' ' . $v; $d[] = ['Last round', 'looked ' . $n($L['looked'] ?? 0) . ' · sent ' . $n($L['sent'] ?? 0), implode(' · ', $why)]; }
            if (!empty($o['pause_until']) && (int)$o['pause_until'] > time()) $d[] = ['Paused until', date('H:i', (int)$o['pause_until']), 'a safety brake (spam flags / many failures)'];
            $cc = $all("SELECT COUNT(DISTINCT camp_id), COUNT(*), COUNT(DISTINCT HOUR(at)) FROM wa_ru_log WHERE id > (SELECT MAX(id) - 80000 FROM wa_ru_log) AND kind = 'cnev' AND ok = 1 AND at >= ?", [date('Y-m-d 00:00:00')]);
            if ($cc && $cc[0] && (int)$cc[0][1] > 0) { $x = $cc[0]; array_unshift($d, ['Sent today', $n($x[1]) . ' messages', 'from ' . $n($x[0]) . ' campaigns · average ' . $n((int)round($x[1] / max(1, (int)$x[2]))) . ' per active hour (limit ' . $n($o['hourly'] ?? 0) . '/h)']); }
        }
        return $d;
    }
}
if (!function_exists('wa_strip_replies')) {
    /** Replies to a module's messages today (the customer wrote within 48 h after one): counted from the message-pattern reader, scaled for the big ones, kept 10 minutes. */
    function wa_strip_replies(PDO $pdo, string $mod): array
    {
        $key = 'wa_strip_rep_' . preg_replace('/\W/', '', $mod); $c = json_decode((string)setting_get($pdo, $key, ''), true);
        if (is_array($c) && !empty($c['t']) && time() - (int)$c['t'] < 600 && date('Y-m-d', (int)$c['t']) === date('Y-m-d')) return $c;
        $map = ['moda' => 'moda', 'modb' => 'modb', 'modc' => 'modc', 'cold' => 'cold', 'line' => 'line', 'after' => 'after']; if (!isset($map[$mod])) return ['t' => time(), 'modules' => []];
        if (!function_exists('wa_pat_rows')) require_once __DIR__ . '/wa_pat.php';
        try { $pdo->exec("SET SESSION MAX_EXECUTION_TIME = 12000"); } catch (Throwable $e) { }
        $rep = null; $sent = 0;
        try {
            if ($mod === 'moda') { $q = $pdo->prepare("SELECT COUNT(*), SUM(EXISTS (SELECT 1 FROM wa_messages m WHERE m.phone = i.phone COLLATE utf8mb4_unicode_ci AND m.dir = 'in' AND m.ts > i.sent_at AND m.ts <= DATE_ADD(i.sent_at, INTERVAL 48 HOUR))) FROM wa_camp_items i WHERE i.id > (SELECT MAX(id) - 120000 FROM wa_camp_items) AND i.id % 12 = 0 AND i.sent_at >= ? AND i.status = 'sent'"); $q->execute([date('Y-m-d 00:00:00')]); $r = $q->fetch(PDO::FETCH_NUM); $sent = (int)$r[0] * 12; $rep = (int)$r[1] * 12; }   // a sample of the newest campaign rows (x12): the full count takes too long
            else { $mult = in_array($mod, ['cold', 'modb', 'modc'], true) ? 3 : 1; $sent = 0; $rep = 0; foreach (wa_pat_rows($pdo, $mod, date('Y-m-d 00:00:00'), $mult) as $r) { $sent += $r[1]; $rep += $r[2]; } } } catch (Throwable $e) { $rep = null; }
        $out = ['t' => time(), 'modules' => [['key' => $mod, 'replies' => $rep, 'replies_sent' => $sent]]];
        if ($rep !== null) { try { setting_set($pdo, $key, json_encode($out)); } catch (Throwable $e) { } }
        return $out;
    }
}
