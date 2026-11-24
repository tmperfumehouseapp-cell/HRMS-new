<?php
/**
 * wa_modc.php — MODULE C (owner, 24 Nov 2026): people whose marketing message FAILED (Meta's per-customer marketing limit 131049 / 131050 / 130472) and who NEVER wrote to us
 * (Module B takes only those who asked within 90 days or ordered within 180). They get ONE plain UTILITY message (the "open" kind of the standard pool: name only, no offer, no price,
 * no claim of an enquiry or an order), 200 an hour to start, then 300 / 400 / 500 / 600 / 1000 while delivery is clean (setting wa_rc_level), from the failed items of campaigns since 1 Nov (setting wa_rc_from).
 *  · UTILITY templates only; a message Meta moved to marketing is never used here.
 *  · Own counter (wa_ru_log kind 'cnev'), own switch (wa_rc_on = 0 stops it), own pause (wa_rc_pause_until). A person is messaged once ever (any row in wa_ru_log blocks).
 *  · Stops itself: Module B's pause, the marketing hold, quiet hours, 5 or more Meta spam flags (131048) in 4 hours = 6 hours' pause, 60% failed of the last 40+ = 1 hour's pause.
 * Called from the minute job after Module B (wa_rec.php). PHP 7.4 safe.
 */
if (!function_exists('wa_rc_tick')) {
    /** Module C's hourly speed (owner 24 Nov 2026): starts at 200, goes 300, 400, 500, 600, 1000 while delivery is clean (checked every 30 minutes on the messages of the window 2 hours to 30 minutes ago: at least 60% of the speed was used and under 10% failed), back to 200 when 25% or more failed, when Meta flags spam (131048) or when it paused. Setting wa_rc_level. */
    function wa_rc_ladder(): array { return [200, 300, 400, 500, 600, 1000]; }
    function wa_rc_level(PDO $pdo, int $nowTs): int
    {
        $lad = wa_rc_ladder(); $lvl = (int)setting_get($pdo, 'wa_rc_level', '0'); if (!in_array($lvl, $lad, true)) { $lvl = 200; setting_set($pdo, 'wa_rc_level', '200'); }
        try { if ((int)setting_get($pdo, 'wa_rc_lad_at', '0') > $nowTs - 1800) return $lvl; if (!wa_ru_in_window($pdo, $nowTs - 7200)) return $lvl;   // the window must have been open the whole time
            setting_set($pdo, 'wa_rc_lad_at', (string)$nowTs);
            $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.kind = 'cnev' AND r.at >= ? AND r.at < ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - 7200), date('Y-m-d H:i:s', $nowTs - 1800)]); $r = $q->fetch(); $n = (int)$r['n']; $f = (int)$r['f'];
            $why = ''; $new = $lvl; $idx = array_search($lvl, $lad, true);
            if ($n >= 30 && $f / max(1, $n) >= 0.25) { $new = 200; $why = $f . ' of ' . $n . ' failed'; }
            elseif ($n >= (int)round($lvl * 1.5 * 0.6) && $f / max(1, $n) < 0.10) { if ($idx !== false && $idx < count($lad) - 1) { $new = $lad[$idx + 1]; $why = 'clean: ' . $n . ' sent, ' . $f . ' failed'; } }
            if ($new !== $lvl) { setting_set($pdo, 'wa_rc_level', (string)$new); $lvl = $new; if (function_exists('wa_log')) wa_log($pdo, 'campaign', $new > 200, 'Module C speed ' . ($new > 200 ? 'up' : 'back') . ' to ' . $new . ' an hour (' . $why . ')'); }
            setting_set($pdo, 'wa_rc_lad_last', json_encode(['at' => $nowTs, 'n' => $n, 'f' => $f, 'level' => $lvl, 'why' => $why])); } catch (Throwable $e) { }
        return $lvl;
    }
    function wa_rc_tick(PDO $pdo): array
    {
        $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'looked' => 0, 'note' => '', 'why' => []];
        try { if ((string)setting_get($pdo, 'wa_rc_from_0111', '') !== '1') { setting_set($pdo, 'wa_rc_from_0111', '1'); setting_set($pdo, 'wa_rc_from', '2026-11-01 00:00:00'); setting_set($pdo, 'wa_rc_cursor', '0'); setting_set($pdo, 'wa_rc_pending', ''); } } catch (Throwable $e) { }   // owner 24 Nov 2026: everybody pending since 1 Nov, not only since 14 Nov
        try { if ((string)setting_get($pdo, 'wa_style_v1', '') !== '1' || (string)setting_get($pdo, 'wa_style_v2', '') !== '1' || (string)setting_get($pdo, 'wa_style_v3', '') !== '1') { setting_set($pdo, 'wa_style_v1', '1'); setting_set($pdo, 'wa_style_v2', '1'); setting_set($pdo, 'wa_style_v3', '1'); if (!function_exists('wa_style_make') && is_file(__DIR__ . '/wa_style.php')) require_once __DIR__ . '/wa_style.php'; if (function_exists('wa_style_make')) wa_style_make($pdo); } } catch (Throwable $e) { }   // the five style messages of the owner (once)
        try {
            if ((string)setting_get($pdo, 'wa_rc_on', '1') === '0') { $res['note'] = 'off'; return $res; }
            if (!function_exists('wa_ru_tables') || !wa_ru_tables($pdo)) { $res['note'] = 'no table'; return $res; }
            $nowTs = function_exists('wa_camp_now') ? wa_camp_now() : time(); $now = date('Y-m-d H:i:s', $nowTs);
            if ((int)setting_get($pdo, 'wa_rc_pause_until', '0') > $nowTs) { $res['note'] = 'paused'; return $res; }
            if ((int)setting_get($pdo, 'wa_ru_pause_until', '0') > $nowTs) { $res['note'] = 'Module B is paused after a Meta warning: Module C waits too'; return $res; }
            if ((int)setting_get($pdo, 'wa_dq_hold_until', '0') > $nowTs) { $res['note'] = 'marketing hold (131048) running'; return $res; }
            if (!wa_ru_in_window($pdo, $nowTs)) { $res['note'] = 'quiet hours'; return $res; }
            if (!function_exists('wa_lock') || !wa_lock($pdo, 'rcnev', 50)) { $res['note'] = 'busy'; return $res; }
            $t0 = microtime(true); $hourly = wa_rc_level($pdo, $nowTs);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log WHERE kind = 'cnev' AND at >= ?"); $q->execute([date('Y-m-d H:i:s', $nowTs - 3600)]); $left = $hourly - (int)$q->fetchColumn();
            $q->execute([date('Y-m-d H:i:s', $nowTs - 300)]); $left = min($left, (int)ceil($hourly / 12) + 1 - (int)$q->fetchColumn());   // spread: a twelfth of the hour in any 5 minutes
            if ($left <= 0) { $res['note'] = 'this hour\'s ' . $hourly . ' are used'; return $res; }
            // brakes
            try { $q2 = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log r JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.kind = 'cnev' AND r.at >= ? AND m.status = 'failed' AND m.err_code = 131048"); $q2->execute([date('Y-m-d H:i:s', $nowTs - 4 * 3600)]);
                if ((int)$q2->fetchColumn() >= 5) { setting_set($pdo, 'wa_rc_level', '200'); setting_set($pdo, 'wa_rc_pause_until', (string)($nowTs + 6 * 3600)); if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'Module C stopped for 6 hours: Meta flagged 5 or more of its messages as spam (131048)'); $res['note'] = 'paused: spam flag'; return $res; } } catch (Throwable $e) { }
            try { $q3 = $pdo->prepare("SELECT COUNT(*) AS n, SUM(r.ok = 0 OR m.status = 'failed') AS f FROM wa_ru_log r LEFT JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.kind = 'cnev' AND r.at >= ? AND r.at <= ?"); $q3->execute([date('Y-m-d H:i:s', $nowTs - 7200), date('Y-m-d H:i:s', $nowTs - 600)]); $hr = $q3->fetch();
                if ((int)$hr['n'] >= 40 && (int)$hr['f'] / max(1, (int)$hr['n']) >= 0.6) { setting_set($pdo, 'wa_rc_level', '200'); setting_set($pdo, 'wa_rc_pause_until', (string)($nowTs + 3600)); if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'Module C paused for an hour: ' . (int)$hr['f'] . ' of ' . (int)$hr['n'] . ' failed'); $res['note'] = 'paused: too many failures'; return $res; } } catch (Throwable $e) { }
            // the utility messages that fit people who never wrote: the plain "open" kind, no claim of an enquiry / an order, approved as UTILITY (never one Meta moved to marketing)
            $pool = function_exists('wa_ru_pool') ? wa_ru_pool($pdo) : [];
            $pool = array_values(array_filter($pool, function ($t) { return ($t['pool_kind'] ?? '') === 'open' && empty($t['claims']) && empty($t['as_marketing']) && strtolower((string)($t['category'] ?? 'utility')) === 'utility'; }));
            $tpp = json_decode((string)setting_get($pdo, 'wa_ru_tpl_pause', ''), true); $tpp = is_array($tpp) ? $tpp : [];
            $pool = array_values(array_filter($pool, function ($t) use ($tpp, $nowTs) { return (int)($tpp[(int)($t['id'] ?? 0)] ?? 0) <= $nowTs; }));
            if (!$pool) { $res['note'] = 'no approved plain utility message yet (waiting for Meta)'; return $res; }
            $from = (string)setting_get($pdo, 'wa_rc_from', '2026-11-14 00:00:00'); $cursor = (int)setting_get($pdo, 'wa_rc_cursor', '0'); $lim = 400; $mo = implode(',', WA_RU_MARKETING_ONLY);
            $q4 = $pdo->prepare("SELECT i.id, i.camp_id, i.phone, i.name, i.err_code, i.sent_at FROM wa_camp_items i WHERE i.sent_at >= ? AND i.sent_at <= ? AND ((i.status = 'failed' AND i.err_code IN ($mo)) OR (i.status = 'sent' AND i.last_status = 'failed' AND i.err_code IN ($mo))) AND COALESCE(i.variant, 'M') <> 'U' AND i.id > ?
                AND NOT EXISTS (SELECT 1 FROM wa_ru_log l WHERE l.item_id = i.id) ORDER BY i.id LIMIT " . $lim);
            $q4->execute([$from, date('Y-m-d H:i:s', $nowTs - 3600), $cursor]); $items = $q4->fetchAll();
            if (!$items) { if ($cursor > 0) setting_set($pdo, 'wa_rc_cursor', '0'); $res['note'] = 'nothing new'; return $res; }
            $res['looked'] = count($items); $phones = array_values(array_unique(array_map(function ($x) { return (string)$x['phone']; }, $items))); $pin = implode(',', array_map(function ($p) use ($pdo) { return $pdo->quote($p); }, $phones));
            $ct = []; foreach ($pdo->query("SELECT phone, wa_id, name, optout, camp_optout, last_in_at, last_number_id FROM wa_contacts WHERE phone IN ($pin)")->fetchAll() as $r) $ct[(string)$r['phone']] = $r;
            $ever = []; foreach ($pdo->query("SELECT DISTINCT phone FROM wa_ru_log WHERE phone IN ($pin)")->fetchAll(PDO::FETCH_COLUMN) as $p) $ever[(string)$p] = 1;
            $got = []; $q5 = $pdo->prepare("SELECT DISTINCT phone FROM wa_messages WHERE phone IN ($pin) AND dir = 'out' AND mtype = 'template' AND status IN ('delivered', 'read') AND ts >= ?"); $q5->execute([date('Y-m-d H:i:s', $nowTs - 86400)]); foreach ($q5->fetchAll(PDO::FETCH_COLUMN) as $p) $got[(string)$p] = 1;
            $recOn = function_exists('wa_rec_cfg') ? !empty(wa_rec_cfg($pdo)['on']) : false; $recTries = function_exists('wa_rec_cfg') ? max(1, (int)wa_rec_cfg($pdo)['max_attempts'] - 1) : 2; $rec = [];
            if (function_exists('wa_rec_tables') && wa_rec_tables($pdo)) { $iin = implode(',', array_map(function ($x) { return (int)$x['id']; }, $items)); try { foreach ($pdo->query("SELECT item_id, COUNT(*) AS n, MAX(at) AS last FROM wa_rec_log WHERE item_id IN ($iin) GROUP BY item_id")->fetchAll() as $r) $rec[(int)$r['item_id']] = $r; } catch (Throwable $e) { } }
            $lineup = function_exists('wa_ru_lineup') ? wa_ru_lineup($pdo, $phones) : []; $numSt = function_exists('wa_ru_numbers') ? wa_ru_numbers($pdo, $nowTs, false) : [];
            $why = []; $sk = function ($k) use (&$res, &$why) { $res['skipped']++; $why[$k] = ($why[$k] ?? 0) + 1; }; $cache = []; $seen = []; $rot = (int)setting_get($pdo, 'wa_rc_rot', '0'); $lastId = $cursor; $broke = false;
            foreach ($items as $it) {
                if (microtime(true) - $t0 > 35 || $left <= 0) { $broke = true; break; }
                $ph = (string)$it['phone']; $lastId = max($lastId, (int)$it['id']);
                $c = $ct[$ph] ?? null; if (!$c) { $sk('no contact record'); continue; }
                if ((int)$c['optout'] === 1 || (int)$c['camp_optout'] === 1) { $sk('opted out'); continue; }
                if (!empty($c['last_in_at'])) { $sk('wrote to us (Module B\'s people)'); continue; }
                if (isset($ever[$ph]) || isset($seen[$ph])) { $sk('already got a utility message'); continue; }
                if (isset($lineup[$ph])) { $sk('in the line-up of a campaign'); continue; }
                $rcr = $rec[(int)$it['id']] ?? null; $rcn = $rcr ? (int)$rcr['n'] : 0;   // Module A's recovery (another number) goes first, like for Module B
                $oldFail = !empty($it['sent_at']) && strtotime((string)$it['sent_at']) < $nowTs - max(1, (int)setting_get($pdo, 'wa_rc_rec_wait_h', '6')) * 3600;   // owner 24 Nov: a failure older than 6 hours does not wait for Module A's recovery any more (287 of 400 people in a round were held by it and Module C sent ~50 an hour)
                if ($recOn && $rcn < $recTries && !$oldFail) { $sk('recovery from another number goes first'); continue; }
                if ($rcr && strtotime((string)$rcr['last']) > $nowTs - 3600) { $sk('recovery tried in the last hour'); continue; }
                if (isset($got[$ph])) { $sk('delivered another way today'); continue; }
                $rot++; $ctm = ['phone' => $ph, 'name' => (string)($c['name'] ?? $it['name'] ?? ''), 'optout' => 0, 'last_in_at' => null];
                $pk = wa_util_pick($pdo, $pool, $ctm, 0, $nowTs, $rot, 1, true); if (!$pk) { $sk('no message fits'); continue; }
                $tid = (int)$pk['t']['id']; if (!array_key_exists($tid, $cache)) $cache[$tid] = wa_ru_tpl_ctx($pdo, $pk['t']);
                [$tUse, $ctx] = $cache[$tid]; if (!$tUse || !$ctx) { $sk('message not approved on a number with room'); continue; }
                $cand = array_filter($ctx['nums'], function ($x) use ($numSt) { return $x['left'] > 0 && empty($numSt[(int)$x['id']]['rest_until']); }); if (!$cand || $ctx['port_left'] <= 0) { $sk('no number with room'); continue; }
                $hi = array_filter($cand, function ($x) { return ($x['q'] ?? '') === 'GREEN'; }); $md = array_filter($cand, function ($x) { return ($x['q'] ?? '') === 'YELLOW'; }); $cand = $hi ?: $md; if (!$cand) { $sk('no High / Medium quality number with room'); continue; }
                uasort($cand, function ($x, $y) { return ($x['used'] / max(1, $x['cap'])) <=> ($y['used'] / max(1, $y['cap'])); }); $num = reset($cand); $nid = (int)$num['id']; $tSend = $num['tm'] ?? $tUse;
                $seen[$ph] = 1; $ctx['nums'][$nid]['left']--; $ctx['nums'][$nid]['used']++; $ctx['port_left']--; $cache[$tid][1] = $ctx; $left--;
                [$ok, $rr, $ecs] = wa_send_tpl_one($pdo, ['phone' => $ph, 'wa_id' => (string)($c['wa_id'] ?? ''), 'camp_optout' => 0], $tSend, $num, $pk['vars'], 'recover');
                try { $pdo->prepare("INSERT IGNORE INTO wa_ru_log (camp_id, item_id, phone, kind, number_id, tpl_id, wamid, ok, err_code, first_code, err, at, attempt, vars_json) VALUES (?, ?, ?, 'cnev', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
                    ->execute([(int)$it['camp_id'], (int)$it['id'], $ph, $nid, $tid, $ok ? (string)$rr : '', $ok ? 1 : 0, $ok ? 0 : (int)$ecs, (int)$it['err_code'], $ok ? '' : mb_substr((string)$rr, 0, 190), $now, json_encode($pk['vars'], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { }
                if ($ok) $res['sent']++; else { $res['failed']++; if ((int)$ecs === 131048) { setting_set($pdo, 'wa_rc_level', '200'); setting_set($pdo, 'wa_rc_pause_until', (string)($nowTs + 6 * 3600)); if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'Module C stopped for 6 hours: Meta flagged a message as spam (131048)'); $broke = true; break; } if (in_array((int)$ecs, [130429, 131056], true)) { $broke = true; break; } }
            }
            setting_set($pdo, 'wa_rc_rot', (string)($rot % 1000)); setting_set($pdo, 'wa_rc_cursor', (string)(count($items) >= $lim || $broke ? $lastId : 0));
            arsort($why); $res['why'] = array_slice($why, 0, 6, true);
            if ($res['sent'] > 0 && function_exists('wa_log')) wa_log($pdo, 'campaign', true, 'Module C (utility to people who never wrote): ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed, ' . $res['skipped'] . ' left out');
        } catch (Throwable $e) { if (function_exists('wa_log')) wa_log($pdo, 'campaign', false, 'Module C error: ' . $e->getMessage()); $res['note'] = 'error: ' . mb_substr($e->getMessage(), 0, 140); }
        try { setting_set($pdo, 'wa_rc_last', json_encode(['at' => time(), 'note' => $res['note'], 'sent' => $res['sent'], 'failed' => $res['failed'], 'skipped' => $res['skipped'], 'looked' => $res['looked'], 'why' => $res['why']])); } catch (Throwable $e) { }
        return $res;
    }

    /** 💬 The people behind Module C's Replied number (owner, 24 Nov: every Replies number opens the WhatsApp chats): customers who wrote after a Module C message sent since $from. Same rows as the Lineup / Cold replies. [rows, more, count] */
    function wa_rc_repliers(PDO $pdo, int $from, int $limit = 600): array
    {
        if (!function_exists('wa_ru_tables') || !wa_ru_tables($pdo)) return [[], false, 0];
        $q = $pdo->prepare("SELECT r.phone, MAX(r.at) at FROM wa_ru_log r WHERE r.kind = 'cnev' AND r.at >= ? AND r.wamid <> '' AND EXISTS (SELECT 1 FROM wa_messages i WHERE i.dir = 'in' AND i.phone = r.phone AND i.ts > r.at) GROUP BY r.phone ORDER BY at DESC LIMIT 3000"); $q->execute([date('Y-m-d H:i:s', $from)]); $left = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $left[(string)$r[0]] = (string)$r[1];
        if (!$left) return [[], false, 0];
        $info = []; $last = [];
        foreach (array_chunk(array_keys($left), 400) as $ch) { $in = implode(',', array_fill(0, count($ch), '?'));
            $c = $pdo->prepare("SELECT phone, name, assigned_emp, last_in_at, last_out_at, status FROM wa_contacts WHERE phone IN ($in)"); $c->execute($ch); foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $info[(string)$r['phone']] = $r;
            $m = $pdo->prepare("SELECT m.phone, m.body, m.mtype, m.ts FROM wa_messages m JOIN (SELECT phone, MAX(id) id FROM wa_messages WHERE dir = 'in' AND phone IN ($in) GROUP BY phone) x ON x.id = m.id"); $m->execute($ch); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $last[(string)$r['phone']] = $r; }
        $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $rows = [];
        foreach ($left as $ph => $at) { $c = $info[$ph] ?? []; $l = $last[$ph] ?? null; $answered = !empty($c['last_out_at']) && (string)$c['last_out_at'] >= (string)($c['last_in_at'] ?? ''); $emp = (int)($c['assigned_emp'] ?? 0);
            $rows[] = ['phone' => $ph, 'name' => (string)($c['name'] ?? ''), 'text' => $l ? mb_substr(((string)$l['body'] !== '' ? (string)$l['body'] : '[' . (string)$l['mtype'] . ']'), 0, 90) : '', 'at' => $l ? (string)$l['ts'] : $at, 'wait' => !$answered, 'emp' => $emp, 'holder' => $emp > 0 ? (string)($nm[$emp] ?? '') : '', 'closed' => ((string)($c['status'] ?? '') === 'closed')]; }
        usort($rows, function ($a, $b) { return [(int)$b['wait'], $b['at']] <=> [(int)$a['wait'], $a['at']]; });
        return [array_slice($rows, 0, $limit), count($rows) > $limit, count($rows)];
    }

    /** Module C status for the card under After-Order (and the diag): switch, speed, sent this hour / today, receipts, waiting people (counted at most once in 30 minutes). */
    function wa_rc_overview(PDO $pdo): array
    {
        $o = ['on' => (string)setting_get($pdo, 'wa_rc_on', '1') !== '0', 'hourly' => wa_rc_level($pdo, time()), 'ladder' => wa_rc_ladder(), 'lad_last' => json_decode((string)setting_get($pdo, 'wa_rc_lad_last', ''), true), 'from' => (string)setting_get($pdo, 'wa_rc_from', '2026-11-14 00:00:00'), 'pause_until' => (int)setting_get($pdo, 'wa_rc_pause_until', '0'), 'now' => time(),
            'last' => json_decode((string)setting_get($pdo, 'wa_rc_last', ''), true), 'sent_hour' => 0, 'sent_today' => 0, 'failed_today' => 0, 'receipts' => [], 'by_hour' => [], 'pending' => null, 'pending_at' => 0, 'pending_partial' => false];
        try { if (!function_exists('wa_ru_tables') || !wa_ru_tables($pdo)) return $o; } catch (Throwable $e) { return $o; }
        try { $t0 = date('Y-m-d 00:00:00'); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log WHERE kind = 'cnev' AND at >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 3600)]); $o['sent_hour'] = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT COUNT(*), SUM(ok = 0) FROM wa_ru_log WHERE kind = 'cnev' AND at >= ?"); $q->execute([$t0]); $r = $q->fetch(PDO::FETCH_NUM); $o['sent_today'] = (int)$r[0]; $o['failed_today'] = (int)$r[1];
            $q = $pdo->prepare("SELECT HOUR(at), COUNT(*) FROM wa_ru_log WHERE kind = 'cnev' AND at >= ? GROUP BY 1 ORDER BY 1"); $q->execute([$t0]); $o['by_hour'] = $q->fetchAll(PDO::FETCH_NUM);
            $q = $pdo->prepare("SELECT m.status, COUNT(*) FROM wa_ru_log r JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.kind = 'cnev' AND r.at >= ? GROUP BY 1"); $q->execute([$t0]); $o['receipts'] = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_ru_log r JOIN wa_messages m ON m.wamid = r.wamid AND r.wamid <> '' WHERE r.kind = 'cnev' AND r.at >= ? AND m.dir = 'out' AND EXISTS (SELECT 1 FROM wa_messages i WHERE i.dir = 'in' AND i.phone = r.phone AND i.ts > r.at)"); $q->execute([$t0]); $o['replied_today'] = (int)$q->fetchColumn(); } catch (Throwable $e) { }
        try { $pc = json_decode((string)setting_get($pdo, 'wa_rc_pending', ''), true);
            if (!is_array($pc) || (int)($pc['at'] ?? 0) < time() - 1800) { $prev = is_array($pc) ? $pc : []; try { $pc = wa_rc_pending($pdo); } catch (Throwable $ep) { $pc = ['at' => time() - 1500, 'people' => $prev['people'] ?? null, 'failed_people' => $prev['failed_people'] ?? null, 'partial' => true, 'err' => mb_substr($ep->getMessage(), 0, 100)]; } setting_set($pdo, 'wa_rc_pending', json_encode($pc)); }   // a failed count keeps the last good number and tries again in 5 minutes
            $o['pending'] = isset($pc['people']) ? (int)$pc['people'] : null; $o['pending_err'] = (string)($pc['err'] ?? ''); $o['pending_at'] = (int)($pc['at'] ?? 0); $o['pending_partial'] = !empty($pc['partial']); $o['pending_failed_people'] = (int)($pc['failed_people'] ?? 0); } catch (Throwable $e) { }
        return $o;
    }
    /** People waiting for Module C: failed marketing-only people (since wa_rc_from) with no Module B / C message yet and no WhatsApp message from them (ever). Counted in chunks, at most 12 seconds. */
    function wa_rc_pending(PDO $pdo): array
    {
        $t0 = microtime(true); $mo = implode(',', WA_RU_MARKETING_ONLY); $from = (string)setting_get($pdo, 'wa_rc_from', '2026-11-14 00:00:00');
        $q = $pdo->prepare("SELECT DISTINCT i.phone FROM wa_camp_items i WHERE i.sent_at >= ? AND ((i.status = 'failed' AND i.err_code IN ($mo)) OR (i.status = 'sent' AND i.last_status = 'failed' AND i.err_code IN ($mo))) AND COALESCE(i.variant, 'M') <> 'U' AND NOT EXISTS (SELECT 1 FROM wa_ru_log l WHERE l.phone = i.phone) LIMIT 40000");
        $q->execute([$from]); $phones = $q->fetchAll(PDO::FETCH_COLUMN); $never = 0; $partial = false;
        foreach (array_chunk($phones, 1500) as $ch) { if (microtime(true) - $t0 > 12) { $partial = true; break; }
            $in = implode(',', array_map(function ($p) use ($pdo) { return $pdo->quote((string)$p); }, $ch)); $never += (int)$pdo->query("SELECT COUNT(*) FROM wa_contacts WHERE phone IN ($in) AND last_in_at IS NULL AND optout = 0 AND COALESCE(camp_optout, 0) = 0")->fetchColumn(); }
        return ['at' => time(), 'people' => $never, 'failed_people' => count($phones), 'partial' => $partial];
    }
}
