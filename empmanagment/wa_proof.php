<?php
/**
 * wa_proof.php — Stage C. Loaded by wa_more.php (no change to api.php).
 *
 *  1. AUTO ORDER PROOF. When a Shopify order comes from a customer who chatted with us on a NEW number (the whole chat is stored), the chat proof the
 *     "Allocate order" step asks an Employee for is created automatically — the salesperson no longer uploads screenshots for it.
 *     It is the same record the screenshots make (convo_uploads, status 'done'), so ct_proof_gate in api.php accepts it as it is.
 *     The call-recording rule is NOT touched: if the person has to upload a call recording, they still do.
 *  2. CHAT LIBRARY. The same chat is saved as messages (convo_messages) so the Chat library / guidebook learns from it like from any uploaded chat.
 *     No ratings and no scores are created here.
 *
 * Runs inside the engine tick (Hostinger cron every minute, at most every 5 minutes) and on demand:
 *   wa.proof_scan  POST   run it now → {made, checked}    [Admin / HR]
 */
if (!function_exists('wa_proof_scan')) {
function wa_proof_mask(string $t): string
{
    if (function_exists('convo_mask')) return convo_mask($t);
    $t = preg_replace('/(?<![\w.])(?:\+?91[\s-]?)?[6-9]\d{9}(?!\d)/u', '[phone]', $t) ?? $t;
    $t = preg_replace('/[\w.\-]{2,}@[A-Za-z]{2,}(?:\.[A-Za-z]{2,})*/u', '[id]', $t) ?? $t;
    return preg_replace('/(?<!\d)\d{9,18}(?!\d)/', '[number]', $t) ?? $t;
}
/** @return array{made:int, checked:int} */
function wa_proof_scan(PDO $pdo, bool $force = false): array
{
    $o = ['made' => 0, 'checked' => 0];
    if (!$force) { $at = (string)setting_get($pdo, 'wa_proof_at', ''); if ($at !== '' && strtotime($at) > time() - 300) return $o; }
    setting_set($pdo, 'wa_proof_at', date('Y-m-d H:i:s'));
    if (!function_exists('ensure_convo_tables') || !ensure_convo_tables($pdo)) return $o;
    try {
        $st = $pdo->prepare("SELECT shopify_id, store_key, order_name, created_at, total, phone FROM shop_orders WHERE created_at >= ? AND phone <> '' ORDER BY created_at LIMIT 3000");
        $st->execute([date('Y-m-d H:i:s', time() - 3 * 86400)]); $orders = $st->fetchAll();
    } catch (Throwable $e) { return $o; }
    $find = $pdo->prepare("SELECT id FROM convo_uploads WHERE kind = 'order' AND source = ? AND order_id = ? LIMIT 1");
    $cq = $pdo->prepare("SELECT phone, assigned_emp FROM wa_contacts WHERE phone = ?");
    $mq = $pdo->prepare("SELECT dir, who, mtype, body, emp_id, ts FROM wa_messages WHERE phone = ? AND dir IN ('in', 'out') AND ts <= ? AND ts >= ? ORDER BY id LIMIT 400");
    $stages = ['opening', 'discovery', 'offer', 'price', 'objection', 'closing', 'followup'];
    foreach ($orders as $r) {
        $key = substr(preg_replace('/\D/', '', (string)$r['phone']), -10); if (strlen($key) !== 10) continue;
        $src = (string)$r['store_key']; $oid = (string)$r['shopify_id']; if ($src === '' || $oid === '') continue;
        $o['checked']++;
        $find->execute([$src, $oid]); if ($find->fetchColumn()) continue;   // an employee's own upload (or an earlier automatic one) is already there
        $cq->execute([$key]); $c = $cq->fetch(); if (!$c) continue;
        $mq->execute([$key, (string)$r['created_at'], date('Y-m-d H:i:s', strtotime((string)$r['created_at']) - 14 * 86400)]); $ms = $mq->fetchAll();
        $nIn = 0; $nOut = 0; $emp = (int)$c['assigned_emp']; $rows = []; $seq = 0; $first = true;
        foreach ($ms as $m) {
            $txt = trim((string)$m['body']); if ($txt === '' || $m['mtype'] === 'template') { if ($m['dir'] === 'in') $nIn++; continue; }
            $cust = $m['dir'] === 'in';
            if ($cust) $nIn++;
            elseif ($m['who'] === 'emp') { $nOut++; if ($emp <= 0 && (int)$m['emp_id'] > 0) $emp = (int)$m['emp_id']; }
            else continue;   // broadcasts, follow-up robots and bot lines are not a salesperson's work
            $stage = 'none';
            if (!$cust) { if ($first) $stage = 'opening'; elseif (preg_match('/(₹|\brs\.?\s?\d|\bprice\b|\brate\b|\bkimat\b)/iu', $txt)) $stage = 'price'; $first = false; }
            if (!in_array($stage, $stages, true)) $stage = 'none';
            $rows[] = [++$seq, $cust ? 'customer' : 'sales', wa_proof_mask(mb_substr($txt, 0, 600)), substr((string)$m['ts'], 11, 5), $stage];
        }
        if ($nIn < 2 || $nOut < 1 || $emp <= 0) continue;   // not a real conversation with one of our people
        $now = date('Y-m-d H:i:s'); $sum = 'Chat taken automatically from WhatsApp (' . $nIn . ' customer + ' . $nOut . ' team messages).';
        $res = json_encode(['via' => 'whatsapp', 'chat' => ['complete' => true, 'call_mentioned' => false, 'summary' => $sum, 'via' => 'whatsapp']], JSON_UNESCAPED_UNICODE);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO convo_uploads (kind, source, order_id, order_name, order_total, order_day, employee_id, user_id, note, shots, has_video, has_call, files, status, msg, outcome, result, flag_wrong, files_gone, tries, created_at, updated_at, analyzed_at, msgs_at, msgs_n, msgs_tries, msgs_sig)
                           VALUES ('order', ?, ?, ?, ?, ?, ?, 0, 'WhatsApp (new number)', 10, 0, 0, '[]', 'done', ?, 'order', ?, 0, 0, 0, ?, ?, ?, ?, ?, 0, 'wa')")
                ->execute([$src, $oid, (string)$r['order_name'], (float)$r['total'], substr((string)$r['created_at'], 0, 10), $emp, $sum, $res, $now, $now, $now, $now, count($rows)]);
            $uid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO convo_messages (upload_id, src, seq, who, msg_text, at_time, stage) VALUES (?, 'chat', ?, ?, ?, ?, ?)");
            foreach ($rows as $x) $ins->execute([$uid, $x[0], $x[1], $x[2], $x[3], $x[4]]);
            $pdo->commit(); $o['made']++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
    return $o;
}
}

$__wp = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wp === 'wa.proof_scan') {
    $auth = require_auth(); wa_guard_admin($pdo, $auth);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    if (!wa_tables($pdo)) json_response(['error' => 'WhatsApp storage is not available — check database permissions.'], 500);
    json_response(['ok' => true] + wa_proof_scan($pdo, true));
}
