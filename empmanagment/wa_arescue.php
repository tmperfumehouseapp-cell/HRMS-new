<?php
/* Module A RESCUE + FLAG WATCHDOG (owner 25 Nov 2026: "every failed message is sent again as utility within 3-4 hours; a flag stops everything for 2 hours").
 *  wa_arescue_tick: a Module A message whose item failed for a marketing-only reason (131049 / 131050 / 130472) is sent again, on the spot, as a UTILITY twin (the same twins as the Lineup / After-Order rescue), newest failures first,
 *  once per item and once per customer a day; never to an opted-out customer or one who wrote since. Own speed ladder (setting wa_arsc_level, 1,000 -> 6,000 an hour, +500 an hour while the last rescues failed under 8%, back to 1,000 above 20%).
 *  Table wa_arsc (item_id). Off: wa_arsc = 0.
 *  wa_flag_watch: 5 or more receipts of Meta's spam flag (131048) in 30 minutes, anywhere, stop Module A, B, C, the rescue and the Cold funnel for 2 hours (setting wa_flag_pause_until; it uses the native holds of A / B / C). */
if (!function_exists('wa_arescue_tick')) {
    function wa_flag_paused(PDO $pdo): bool { try { return (int)setting_get($pdo, 'wa_flag_pause_until', '0') > time(); } catch (Throwable $e) { return false; } }
    function wa_flag_watch(PDO $pdo): bool
    {
        if ((int)setting_get($pdo, 'wa_flag_chk_at', '0') > time() - 120) return wa_flag_paused($pdo); setting_set($pdo, 'wa_flag_chk_at', (string)time());
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE id > (SELECT MAX(id) - 60000 FROM wa_messages) AND dir = 'out' AND status = 'failed' AND err_code = 131048 AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 1800)]); $n = (int)$q->fetchColumn();
            if ($n >= 5 && !wa_flag_paused($pdo)) { $u = time() + 2 * 3600; setting_set($pdo, 'wa_flag_pause_until', (string)$u); setting_set($pdo, 'wa_dq_hold_until', (string)max((int)setting_get($pdo, 'wa_dq_hold_until', '0'), $u)); setting_set($pdo, 'wa_ru_pause_until', (string)max((int)setting_get($pdo, 'wa_ru_pause_until', '0'), $u)); setting_set($pdo, 'wa_rc_pause_until', (string)max((int)setting_get($pdo, 'wa_rc_pause_until', '0'), $u)); setting_set($pdo, 'wa_arsc_level', '1000');
                if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'FLAG WATCH: ' . $n . ' spam-flag receipts (131048) in 30 minutes: Module A, B, C, the rescue and Cold stop for 2 hours (owner rule)'); } } catch (Throwable $e) { }
        return wa_flag_paused($pdo);
    }
    function wa_arescue_tick(PDO $pdo, int $now, int $capMax = 120, int $budget = 12): int
    {
        if ((string)setting_get($pdo, 'wa_arsc', '1') === '0') return 0; if (wa_flag_watch($pdo)) { setting_set($pdo, 'wa_arsc_note', date('H:i') . ' paused: flag watch'); return 0; }
        if (!function_exists('wln_twins') || !function_exists('wln_send_any') || !function_exists('wln_mkt_codes')) { setting_set($pdo, 'wa_arsc_note', date('H:i') . ' Lineup functions not loaded here'); return 0; }
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS wa_arsc (item_id BIGINT NOT NULL PRIMARY KEY, phone VARCHAR(20) NOT NULL, at DATETIME NOT NULL, ok TINYINT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, code INT NOT NULL DEFAULT 0, wamid VARCHAR(80) NOT NULL DEFAULT '', why VARCHAR(100) NOT NULL DEFAULT '', KEY k_ph (phone), KEY k_at (at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) { return 0; }
        $lvl = max(500, min(6000, (int)setting_get($pdo, 'wa_arsc_level', '1000'))); $h1 = date('Y-m-d H:i:s', $now - 3600);
        // the hourly step: the receipts of the rescues of 2 hours to 15 minutes ago
        try { if ((int)setting_get($pdo, 'wa_arsc_step_at', '0') < $now - 3600) { setting_set($pdo, 'wa_arsc_step_at', (string)$now); $r = $pdo->prepare("SELECT COUNT(*), SUM(m.status = 'failed') FROM wa_arsc a JOIN wa_messages m ON (m.wamid COLLATE utf8mb4_unicode_ci) = (a.wamid COLLATE utf8mb4_unicode_ci) WHERE a.ok = 1 AND a.at >= ? AND a.at <= ?"); $r->execute([date('Y-m-d H:i:s', $now - 7200), date('Y-m-d H:i:s', $now - 900)]); $x = $r->fetch(PDO::FETCH_NUM); $n = (int)$x[0]; $f = (int)$x[1];
                if ($n >= 200) { $new = $f / max(1, $n) >= 0.20 ? 1000 : ($f / max(1, $n) < 0.08 ? min(6000, $lvl + 500) : $lvl); if ($new !== $lvl) { setting_set($pdo, 'wa_arsc_level', (string)$new); $lvl = $new; } setting_set($pdo, 'wa_arsc_last', json_encode(['at' => $now, 'n' => $n, 'failed' => $f, 'level' => $lvl])); } } } catch (Throwable $e) { }
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_arsc WHERE at >= ? AND ok = 1"); $q->execute([$h1]); $used = (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } $room = $lvl - $used; if ($room <= 0) { setting_set($pdo, 'wa_arsc_note', date('H:i') . ' hourly level used (' . $lvl . ')'); return 0; }
        $mo = implode(',', wln_mkt_codes()); $t0 = microtime(true); $n = 0; $cap = min($room, $capMax);
        /* owner 26 Nov 2026: the campaign's own retry engine turns a failed row into 'retry' (or 'skipped') within minutes; the rescue looked only at 'sent' / 'failed' rows, so 4,000 of 5,700 failed people were never seen */
        try { $q = $pdo->prepare("SELECT i.id, i.camp_id, i.phone, i.err_code, i.sent_at, c.tpl_id FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id LEFT JOIN wa_arsc a ON a.item_id = i.id WHERE i.id > (SELECT MAX(id) - 400000 FROM wa_camp_items) AND i.sent_at >= ? AND a.item_id IS NULL AND ((i.status = 'failed' AND i.err_code IN ($mo)) OR (i.status IN ('sent', 'retry', 'skipped') AND i.last_status = 'failed' AND i.err_code IN ($mo))) AND COALESCE(i.variant, 'M') <> 'U' ORDER BY i.id DESC LIMIT " . ($cap * 3)); $q->execute([date('Y-m-d H:i:s', $now - 12 * 3600)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { setting_set($pdo, 'wa_arsc_note', date('H:i') . ' query failed: ' . mb_substr($e->getMessage(), 0, 80)); return 0; }
        $clm = $pdo->prepare("INSERT IGNORE INTO wa_arsc (item_id, phone, at, ok, tpl_id, code, wamid, why) VALUES (?, ?, ?, 0, 0, ?, '', 'claimed')"); $fin = $pdo->prepare("UPDATE wa_arsc SET ok = ?, tpl_id = ?, wamid = ?, why = ?, at = ? WHERE item_id = ?"); $seen = [];   /* several rescuers at the same time: a row is claimed with one INSERT before anything is sent */
        $day = date('Y-m-d H:i:s', $now - 86400); $dq = $pdo->prepare("SELECT 1 FROM wa_arsc WHERE phone = ? AND ok = 1 AND at >= ? LIMIT 1"); $rq = $pdo->prepare("SELECT 1 FROM wa_ru_log WHERE phone = ? AND ok = 1 AND at >= ? LIMIT 1");
        foreach ($rows as $r) {
            if ($n >= $cap || microtime(true) - $t0 > $budget) break; $ph = (string)$r['phone']; $iid = (int)$r['id']; $code = (int)$r['err_code'];
            $clm->execute([$iid, $ph, date('Y-m-d H:i:s'), $code]); if ($clm->rowCount() !== 1) continue;   // another rescuer has it
            $f = function ($ok, $tid, $wamid, $why) use ($fin, $iid) { $fin->execute([$ok, $tid, $wamid, $why, date('Y-m-d H:i:s'), $iid]); };
            if (isset($seen[$ph])) { $f(0, 0, '', 'same customer in this round'); continue; } $seen[$ph] = 1;
            $dq->execute([$ph, $day]); if ($dq->fetchColumn()) { $f(0, 0, '', 'already rescued today'); continue; }
            $rq->execute([$ph, $day]); if ($rq->fetchColumn()) { $f(0, 0, '', 'got a Module B/C utility today'); continue; }
            $c = function_exists('wa_contact') ? wa_contact($pdo, $ph) : null; if (!$c) { $f(0, 0, '', 'no contact'); continue; }
            if (!empty($c['optout']) || !empty($c['camp_optout'])) { $f(0, 0, '', 'opted out'); continue; }
            if (!empty($c['last_in_at']) && (int)strtotime((string)$c['last_in_at']) > (int)strtotime((string)$r['sent_at'])) { $f(0, 0, '', 'he wrote since'); continue; }
            $tw = wln_twins($pdo, $ph, 'all', 0, (int)$r['tpl_id']); if (!$tw) { $f(0, 0, '', 'no utility message left'); continue; }
            $done = false; foreach ($tw as [$tid, $key]) { $res = wln_send_any($pdo, $c, $tid); if ($res[0]) { $f(1, $tid, (string)($res[3] ?? ''), 'rescued'); $n++; $done = true; break; } }
            if (!$done) $f(0, 0, '', 'no utility message could go');
        }
        setting_set($pdo, 'wa_arsc_note', date('H:i') . ' rescued ' . $n . ' · level ' . $lvl . ' · used this hour ' . ($used + $n));
        return $n;
    }
}
