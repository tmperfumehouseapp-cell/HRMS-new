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
        $out = ['t' => time(), 'modules' => $mods];
        try { setting_set($pdo, $ck, json_encode($out)); } catch (Throwable $e) { }
        return $out;
    }
}
