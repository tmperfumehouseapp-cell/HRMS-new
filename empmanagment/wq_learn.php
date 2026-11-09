<?php
/**
 * 🧠 The guidebook learns every night from EVERY WhatsApp chat, won and lost.
 *  • WON chats (an order came) are saved by wa_proof.php (every few minutes).
 *  • LOST chats (a person talked to the customer, nothing was ordered in the 8+ days after) are saved here, masked, as kind 'lost' — the guidebook writer already treats lost chats as important as won ones.
 *  • After 02:00 (once a day) the engine (wa_tick, the cron) saves the lost chats and runs the study pass, which rewrites the guidebook and puts it live by itself (lib_guide_nightly in api.php),
 *    unless the change is very big — then it stays a draft and the Learning screen says so.
 *  • wlr_status() feeds the "Learning" card of the Speed screen: last night's note, how many chats were taken, and whether the cron is alive.
 * PHP 7.4 safe.
 */
if (!function_exists('wlr_import_lost')) {

/** @return array{made:int, checked:int} */
function wlr_import_lost(PDO $pdo, int $max = 40): array
{
    $o = ['made' => 0, 'checked' => 0];
    if (!function_exists('ensure_convo_tables') || !ensure_convo_tables($pdo) || !function_exists('wa_proof_mask')) return $o;
    $now = time();
    try {
        $st = $pdo->prepare("SELECT phone, assigned_emp, last_in_at FROM wa_contacts WHERE optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ? AND last_in_at <= ? AND (cx_id IS NULL OR cx_id = 0) AND (tag IS NULL OR tag <> 'complaint') ORDER BY last_in_at DESC LIMIT 400");
        $st->execute([date('Y-m-d H:i:s', $now - 45 * 86400), date('Y-m-d H:i:s', $now - 8 * 86400)]); $rows = $st->fetchAll();
    } catch (Throwable $e) { return $o; }
    $find = $pdo->prepare("SELECT 1 FROM convo_uploads WHERE kind = 'lost' AND source = 'whatsapp' AND order_id = ? LIMIT 1");
    $ord = $pdo->prepare("SELECT 1 FROM shop_orders WHERE phone LIKE ? AND created_at >= ? LIMIT 1");
    $mq = $pdo->prepare("SELECT dir, who, mtype, body, emp_id, ts FROM wa_messages WHERE phone = ? AND dir IN ('in', 'out') AND ts >= ? AND ts <= ? ORDER BY id LIMIT 300");
    $FU = ['auto', 'campaign', 'followup', 'journey', 'fu', 'wj'];
    foreach ($rows as $r) {
        if ($o['made'] >= $max) break;
        $key = substr(preg_replace('/\D/', '', (string)$r['phone']), -10); if (strlen($key) !== 10) continue;
        $o['checked']++; $lastIn = (string)$r['last_in_at']; $oid = 'lost:' . $key . ':' . substr($lastIn, 0, 10);
        try { $find->execute([$oid]); if ($find->fetchColumn()) continue;
            $ord->execute(['%' . $key, date('Y-m-d H:i:s', strtotime($lastIn) - 30 * 86400)]); if ($ord->fetchColumn()) continue;   // they did order: that is a won chat, not a lost one
        } catch (Throwable $e) { continue; }
        if (function_exists('wa_heat_flags')) { $hf = wa_heat_flags($pdo, [$key]); if (!empty($hf[$key]['biz']) || !empty($hf[$key]['cx'])) continue; }   // business auto-replies and complaints teach nothing here
        $mq->execute([$key, date('Y-m-d H:i:s', strtotime($lastIn) - 30 * 86400), date('Y-m-d H:i:s', strtotime($lastIn) + 86400)]); $ms = $mq->fetchAll();
        $nIn = 0; $nPerson = 0; $emp = (int)$r['assigned_emp']; $rows2 = []; $seq = 0; $first = true;
        foreach ($ms as $m) {
            $txt = trim((string)$m['body']); if ($txt === '' || $m['mtype'] === 'template') { if ($m['dir'] === 'in') $nIn++; continue; }
            $cust = $m['dir'] === 'in'; $stage = 'none';
            if ($cust) $nIn++;
            elseif ($m['who'] === 'emp' || $m['who'] === 'phone') { $nPerson++; if ($emp <= 0 && (int)$m['emp_id'] > 0) $emp = (int)$m['emp_id']; }
            elseif ($m['who'] === 'bot') $txt = '[bot] ' . $txt;
            elseif (in_array((string)$m['who'], $FU, true)) $stage = 'followup';
            else continue;
            if (!$cust && $stage === 'none') { if ($first) $stage = 'opening'; elseif (preg_match('/(₹|\brs\.?\s?\d|\bprice\b|\brate\b|\bkimat\b)/iu', $txt)) $stage = 'price'; $first = false; }
            $rows2[] = [++$seq, $cust ? 'customer' : 'sales', wa_proof_mask(mb_substr($txt, 0, 600)), substr((string)$m['ts'], 11, 5), $stage];
        }
        if ($nIn < 2 || $nPerson < 2 || count($rows2) < 4) continue;   // not a real conversation with one of our people
        $nowS = date('Y-m-d H:i:s'); $sum = 'Chat taken automatically from WhatsApp — no order in the 8+ days after (' . $nIn . ' customer, ' . $nPerson . ' team messages).';
        $res = json_encode(['via' => 'whatsapp', 'chat' => ['complete' => true, 'call_mentioned' => false, 'summary' => $sum, 'via' => 'whatsapp']], JSON_UNESCAPED_UNICODE);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO convo_uploads (kind, source, order_id, order_name, order_total, order_day, employee_id, user_id, note, shots, has_video, has_call, files, status, msg, outcome, result, flag_wrong, files_gone, tries, created_at, updated_at, analyzed_at, msgs_at, msgs_n, msgs_tries, msgs_sig)
                           VALUES ('lost', 'whatsapp', ?, '', 0, ?, ?, 0, 'WhatsApp (no order)', 10, 0, 0, '[]', 'done', ?, 'lost', ?, 0, 0, 0, ?, ?, ?, ?, ?, 0, 'wa')")
                ->execute([$oid, substr($lastIn, 0, 10), $emp, $sum, $res, $nowS, $nowS, $nowS, $nowS, count($rows2)]);
            $uid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO convo_messages (upload_id, src, seq, who, msg_text, at_time, stage) VALUES (?, 'chat', ?, ?, ?, ?, ?)");
            foreach ($rows2 as $x) $ins->execute([$uid, $x[0], $x[1], $x[2], $x[3], $x[4]]);
            $pdo->commit(); $o['made']++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
    return $o;
}

/** Called at the end of every engine run: once a day after 02:00 it saves the lost chats and runs the study pass (guidebook rewrite). */
function wlr_nightly(PDO $pdo): void
{
    if ((int)date('G') < 2) return; $today = date('Y-m-d');
    if ((string)setting_get($pdo, 'lib_guide_nightly', '') === $today) return;
    if ((int)setting_get($pdo, 'wlr_hook_at', '0') > time() - 1800) return; setting_set($pdo, 'wlr_hook_at', (string)time());
    $note = [];
    try { $l = wlr_import_lost($pdo); $note[] = $l['made'] . ' lost chats saved'; } catch (Throwable $e) { $note[] = 'lost chats: ' . mb_substr($e->getMessage(), 0, 80); }
    try { if (function_exists('wa_proof_scan')) { $w = wa_proof_scan($pdo, true); $note[] = $w['made'] . ' won chats saved'; } } catch (Throwable $e) { }
    try { if (function_exists('lib_tick_run')) { @set_time_limit(300); lib_tick_run($pdo, 0, 280); } } catch (Throwable $e) { $note[] = 'study pass: ' . mb_substr($e->getMessage(), 0, 80); }
    setting_set($pdo, 'wlr_last', date('Y-m-d H:i') . ' — ' . implode(' · ', $note));
}

/** What the Speed screen shows about the nightly learning. */
function wlr_status(PDO $pdo): array
{
    $o = ['live' => null, 'last_note' => (string)setting_get($pdo, 'lib_guide_nightly_note', ''), 'last_day' => (string)setting_get($pdo, 'lib_guide_nightly', ''), 'import' => (string)setting_get($pdo, 'wlr_last', ''), 'won' => 0, 'lost' => 0, 'cron_at' => (string)setting_get($pdo, 'wa_cron_at', ''), 'cron_ok' => false, 'stale' => false];
    try { if (function_exists('lib_guide_row')) { $g = lib_guide_row($pdo, 'live'); if ($g) $o['live'] = ['version' => (int)$g['version'], 'at' => (string)$g['approved_at'], 'by' => (string)$g['approved_by']]; } } catch (Throwable $e) { }
    try { foreach ($pdo->query("SELECT kind, COUNT(*) n FROM convo_uploads WHERE msgs_sig = 'wa' GROUP BY kind")->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['kind'] === 'order') $o['won'] = (int)$r['n']; if ($r['kind'] === 'lost') $o['lost'] = (int)$r['n']; } } catch (Throwable $e) { }
    $o['cron_ok'] = $o['cron_at'] !== '' && strtotime($o['cron_at']) > time() - 180;
    $o['stale'] = $o['last_day'] === '' || ($o['last_day'] < date('Y-m-d') && (int)date('G') >= 4);   // tonight's run (after 02:00) is missing
    try { $k = (string)setting_get($pdo, 'wa_cron_key', ''); if ($k === '') { $k = bin2hex(random_bytes(16)); setting_set($pdo, 'wa_cron_key', $k); } $h = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($h !== '') $o['cron_cmd'] = 'curl -s "' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $h . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/empmanagment/api.php')), '/') . '/api.php?action=wa.cron&key=' . $k . '" > /dev/null 2>&1'; } catch (Throwable $e) { }   // admin only (wq.speed): the line to paste into the server's cron list
    $cs = (int)setting_get($pdo, 'wa_cron_start', '0'); $fin = (int)strtotime($o['cron_at']);
    $o['stuck_min'] = ($cs > 0 && $cs > $fin && time() - $cs > 300) ? (int)((time() - $cs) / 60) : 0;   // a run started, did not finish
    return $o;
}

}
