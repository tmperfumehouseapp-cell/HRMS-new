<?php
/**
 * dept_work.php — Shopping list for ALL offices (the "To buy" half). Part of Departments > Office supplies.
 * Put it in the SAME folder as api.php, boss.php, dept.php and dept_supplies.php. dept_supplies.php loads it by itself.
 *
 * What it does
 *  - One list with every "need to buy" of every office and the store. Filter by office, group by office.
 *  - A boss (Admin / HR / top boss / Supplies boss, or the office checker for his own office) GIVES items to a person, one or many at once, across offices.
 *    Claude suggests who (the office's buyer, else the best track record). Nothing is given without a person pressing the button.
 *  - The person gets ONE notification per batch ("4 things to buy today: Oberon 1, 2, 3"), the same on My Day, and a reminder every hour (7 am - 9 pm)
 *    until the deadline. Items nobody was given stay with the office's own buyer, so nothing is left without an owner.
 *  - The buyer ticks what he bought, types the amount per office, how he paid, and adds ONE payment screenshot (+ optional bill photo).
 *    Claude reads the screenshot: it must be a payment, the amount must match, the date must be fresh, it must not look edited,
 *    and the same screenshot cannot be used twice. AI down = accepted, but marked "not checked" for a person.
 *  - "The shop did not have it" puts the item back and tells the person who gave it.
 *  - Proof missing tab: purchases without screenshot / bill (own pocket, cash, credit) and flagged ones. The boss presses "Ask for proof": the buyer
 *    gets it on My Day and uploads it.
 *  - A not-bought item after its deadline = "Supply not bought on its day" for the person who was given it (same rule, same caps, same Questions gate).
 * Own table: dp_wk_shots. Columns added to dp_su_needs / dp_su_bought by dept_supplies.php. PHP 7.4 safe.
 */
if (!defined('DP_LOADED') || !isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('DP_WK_LOADED')) { return; }
define('DP_WK_LOADED', 1);

/* ============================== tables ============================== */
function dp_wk_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_dp_wk_tables_v1_' . substr(md5(__DIR__), 0, 8);
    $have = is_file($flag);
    if ($have) { try { $pdo->query("SELECT 1 FROM dp_wk_shots LIMIT 1"); } catch (Throwable $e) { $have = false; } }
    if (!$have) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_wk_shots (
            id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, ahash CHAR(64) NULL, txn_ref VARCHAR(80) NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0, file VARCHAR(160) NULL, created_at DATETIME NOT NULL, INDEX (created_at), INDEX (txn_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, '1');
    }
}

/* ============================== small helpers ============================== */
/** "Oberon 1, 2, 3 · Surat store": names that end in a number and share a first part are folded together */
function dp_wk_label(array $names) {
    $names = array_values(array_unique($names)); $groups = []; $order = [];
    foreach ($names as $n) {
        if (preg_match('/^(.*\S)\s+(\d+)$/u', $n, $m)) { if (!isset($groups[$m[1]])) { $groups[$m[1]] = []; $order[] = ['g', $m[1]]; } $groups[$m[1]][] = $m[2]; }
        else $order[] = ['s', $n];
    }
    $out = [];
    foreach ($order as $o) $out[] = $o[0] === 's' ? $o[1] : $o[1] . ' ' . implode(', ', $groups[$o[1]]);
    return implode(' · ', $out);
}
function dp_wk_eff_due(array $n) { return !empty($n['assigned_due']) ? $n['assigned_due'] : $n['needed_by']; }
function dp_wk_label_time($dt) { return $dt ? date('D g:i A', strtotime($dt)) : ''; }
/** the deadline a person is given. $wanted = text from the boss (or null = the need's own time). Never less than 1 hour away; a default is moved out of the night. */
function dp_wk_due($wanted, $neededBy, $now) {
    $t = false;
    if ($wanted !== null && trim((string)$wanted) !== '') {
        $w = trim((string)$wanted);
        if (preg_match('/^\d{1,2}:\d{2}$/', $w)) $t = strtotime(date('Y-m-d', $now) . ' ' . $w);
        else $t = strtotime(str_replace('T', ' ', $w));
    }
    $explicit = $t !== false;
    if (!$explicit) $t = strtotime($neededBy);
    if ($t < $now + 3600) $t = $now + 3600;
    if (!$explicit) { $h = (int)date('G', $t); if ($h >= 20 || $h < 8) $t = strtotime(date('Y-m-d', $h >= 20 ? $t + 86400 : $t) . ' 10:00'); }
    return date('Y-m-d H:i:s', $t);
}
/** a reason he cannot take work today (leave, holiday, week off, left). Not having punched in yet is NOT a reason here. */
function dp_wk_away(PDO $pdo, $id, $date) { $a = bd_away($pdo, $id, $date); return $a === 'not in today' ? '' : $a; }
function dp_wk_can_assign(array $acc, $placeCode) { return !empty($acc['mgr']) || isset($acc['chk'][$placeCode]); }
function dp_wk_any_assign(array $acc) { return !empty($acc['mgr']) || !empty($acc['chk']); }
/** Claude's suggestion for one place: its own buyer who is here today; else the person who bought most lately; else the best track record (never an owner / director). Never gives anything by itself. */
function dp_wk_suggest(PDO $pdo, array $place, $now) {
    static $pool = null;
    $today = date('Y-m-d', $now);
    foreach (dp_su_buyer_ids($pdo, $place) as $id) { if (dp_wk_away($pdo, $id, $today) === '') return ['id' => (int)$id, 'name' => bd_emp_name($pdo, $id), 'why' => 'Buyer of ' . $place['name']]; }
    if ($pool === null) {
        $pool = ['top' => [], 'skip' => []];
        foreach (bd_employees($pdo) as $e) { if (preg_match('/owner|director|chairman|\bceo\b|admin/i', (string)($e['designation'] ?? ''))) $pool['skip'][(int)$e['id']] = 1; }
        try { foreach ($pdo->query("SELECT buyer_emp, COUNT(*) c FROM dp_su_bought WHERE buyer_emp IS NOT NULL AND bought_at >= '" . date('Y-m-d', strtotime('-60 days')) . "' GROUP BY buyer_emp ORDER BY c DESC")->fetchAll() as $r) $pool['top'][(int)$r['buyer_emp']] = (int)$r['c']; } catch (Throwable $e) {}
    }
    foreach ($pool['top'] as $id => $c) { if (!isset($pool['skip'][$id]) && dp_wk_away($pdo, $id, $today) === '') return ['id' => $id, 'name' => bd_emp_name($pdo, $id), 'why' => 'Bought ' . $c . ' time' . ($c === 1 ? '' : 's') . ' in the last 2 months']; }
    foreach (dp_suggest($pdo, 'supplies.buyer', 8) as $c) { if ($c['track']['tasks'] && !isset($pool['skip'][$c['id']]) && dp_wk_away($pdo, $c['id'], $today) === '') return ['id' => (int)$c['id'], 'name' => $c['name'], 'why' => $c['line']]; }   // only a real track record counts
    return null;
}
function dp_wk_need_text(PDO $pdo, array $needs) {
    $parts = []; $places = [];
    foreach ($needs as $n) {
        $it = dp_su_item($pdo, $n['item_id']); $p = dp_su_place($pdo, (int)$n['place_id']); if (!$it || !$p) continue;
        $places[] = $p['name']; $parts[] = mb_strtolower($it['name']) . ' ' . dp_su_q($n['qty']) . ' ' . $it['unit'] . ' (' . $p['name'] . ')';
    }
    $cnt = count($parts); $np = count(array_unique($places));
    return [mb_substr($cnt . ' thing' . ($cnt === 1 ? '' : 's') . ' to buy' . ($np > 1 ? ', ' . $np . ' offices' : '') . ': ' . dp_wk_label($places), 0, 200), implode(', ', $parts)];
}

/* ============================== My Day items: one per person, holding everything he was given ============================== */
/** one open "Shopping" item per person who was given needs; done when nothing is left on it */
function dp_wk_sync_items(PDO $pdo, $now) {
    dp_wk_ensure($pdo);
    $made = 0; $today = date('Y-m-d', $now);
    $emps = $pdo->query("SELECT DISTINCT assigned_emp FROM dp_su_needs WHERE status = 'open' AND assigned_emp IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($emps as $emp) {
        $emp = (int)$emp;
        $q = $pdo->prepare("SELECT n.*, d.status AS di_status FROM dp_su_needs n LEFT JOIN dp_items d ON d.id = n.dp_item_id WHERE n.status = 'open' AND n.assigned_emp = ? ORDER BY COALESCE(n.assigned_due, n.needed_by), n.id");
        $q->execute([$emp]);
        $needs = array_values(array_filter($q->fetchAll(), function ($n) { return $n['di_status'] !== 'missed'; }));   // a need already missed today waits for tomorrow
        if (!$needs) continue;
        if (dp_wk_away($pdo, $emp, $today) !== '') {   // on leave: no item, no fine; the boss who gave it is told once
            $first = $needs[0];
            bd_inbox('dp_note', bd_emp_name($pdo, $emp) . ' is away but was given things to buy', 'Give them to somebody else in Departments > Office supplies > Shopping list.', ['emp_id' => $emp], null, !empty($first['assigned_by_emp']) ? (int)$first['assigned_by_emp'] : null, 'wk_away:' . $emp . ':' . $today);
            continue;
        }
        $due = null; foreach ($needs as $n) { $d = dp_wk_eff_due($n); if ($due === null || $d < $due) $due = $d; }
        list($title, $detail) = dp_wk_need_text($pdo, $needs);
        $detail = mb_substr($detail . ' · by ' . dp_wk_label_time($due), 0, 400);
        $ids = array_map(function ($n) { return (int)$n['id']; }, $needs);
        $st = $pdo->prepare("SELECT * FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE ? AND status = 'open' ORDER BY id LIMIT 1"); $st->execute(['su:wk:' . $emp . ':%']); $ex = $st->fetch();
        $payload = ['kind' => 'buy', 'wk' => 1, 'need_ids' => $ids, 'page' => 'dept_work.html?me=1'];
        if ($ex) {
            $old = bd_unjson($ex['payload']); if (isset($old['last_push'])) $payload['last_push'] = $old['last_push'];
            $pdo->prepare("UPDATE dp_items SET title = ?, detail = ?, due_at = ?, payload = ?, qty = ? WHERE id = ?")->execute([$title, $detail, $due, bd_json($payload), count($needs), (int)$ex['id']]);
            $iid = (int)$ex['id'];
        } else {
            $k = 'su:wk:' . $emp . ':' . $today; $try = 1;
            while (true) { $c = $pdo->prepare("SELECT 1 FROM dp_items WHERE ref_key = ?"); $c->execute([$k]); if (!$c->fetchColumn()) break; $try++; $k = 'su:wk:' . $emp . ':' . $today . ':' . $try; }
            $payload['last_push'] = $now;
            $iid = dp_item_make($pdo, 'supplies', 'supply_missed', $k, 'supplies.buyer', $title, $detail, $today, $due, $payload, $emp, count($needs));
            $made++;
            if (empty($GLOBALS['dp_wk_quiet'])) bd_push($pdo, $emp, 'Shopping for you: ' . $title, $detail);
        }
        $pdo->prepare("UPDATE dp_su_needs SET dp_item_id = ? WHERE id IN (" . implode(',', $ids) . ") AND (dp_item_id IS NULL OR dp_item_id != ?)")->execute([$iid, $iid]);
    }
    // a person's item with nothing left on it (bought, taken back, given to someone else) is done
    foreach ($pdo->query("SELECT id FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE 'su:wk:%' AND status = 'open'")->fetchAll() as $r) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs WHERE dp_item_id = ? AND status = 'open' AND assigned_emp IS NOT NULL"); $q->execute([(int)$r['id']]);
        if ((int)$q->fetchColumn() === 0) dp_item_done($pdo, $r['id']);
    }
    return $made;
}
/** the hourly reminder: 7 am to 9 pm, every wk_repeat_min minutes (0 = off), until the deadline */
function dp_wk_repeat(PDO $pdo, $now) {
    $every = (int)dp_get('wk_repeat_min', '60'); if ($every <= 0) return 0;
    $win = explode('-', dp_get('wk_hours', '7-21')); $from = (int)($win[0] ?? 7); $to = (int)($win[1] ?? 21);
    $h = (int)date('G', $now); if ($h < $from || $h >= $to) return 0;
    if (bd_mode() === 'shadow') return 0;
    $n = 0;
    $st = $pdo->query("SELECT * FROM dp_items WHERE kind = 'supplies' AND status = 'open' AND ref_key LIKE 'su:wk:%' AND owner_emp IS NOT NULL AND due_at IS NOT NULL");
    foreach ($st->fetchAll() as $it) {
        if (strtotime($it['due_at']) <= $now) continue;   // the deadline passed: the miss rule takes over, no more reminders
        $p = bd_unjson($it['payload']); $last = (int)($p['last_push'] ?? 0);
        if ($now - $last < $every * 60) continue;
        $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs WHERE dp_item_id = ? AND status = 'open'"); $q->execute([(int)$it['id']]); $open = (int)$q->fetchColumn(); if (!$open) continue;
        bd_push($pdo, (int)$it['owner_emp'], 'Still to buy (' . $open . '), by ' . date('g:i A', strtotime($it['due_at'])), mb_substr((string)$it['detail'], 0, 140));
        $p['last_push'] = $now; $pdo->prepare("UPDATE dp_items SET payload = ? WHERE id = ?")->execute([bd_json($p), (int)$it['id']]); $n++;
    }
    return $n;
}

/* ============================== the payment screenshot check ============================== */
/**
 * Called with the saved picture and the amount the buyer typed (total of all offices). Returns
 *   ['ok'=>bool, 'reason'=>plain English why not (when !ok), 'note'=>'' or why a person must look (AI down), 'hash', 'txn', 'ai'=>bool]
 * Rules: look-alike of an earlier screenshot with the same amount (average hash, up to 2 of 64 bits apart) or the same transaction id = rejected.
 * Claude reads it: must be a payment, amount within 1 rupee, date not older than 2 days, not edited. Claude not available = accepted, noted.
 */
function dp_wk_shot_check(PDO $pdo, $empId, $absPath, $amount) {
    dp_wk_ensure($pdo);
    $out = ['ok' => true, 'reason' => '', 'note' => '', 'hash' => null, 'txn' => '', 'ai' => false];
    $out['hash'] = function_exists('bd_ahash') ? bd_ahash($absPath) : null;
    $v = null;
    if (dp_get('wk_shot_ai', '1') === '1' && function_exists('convo_ai') && function_exists('bd_b64_small')) {
        $img = bd_b64_small($absPath);
        if ($img) {
            $sys = "You check a screenshot that an employee sends as proof that he paid a shop (a UPI app such as GPay, PhonePe, Paytm, or a bank app). Read it. "
                . "is_payment_screenshot = true only if it clearly shows a completed payment (not a QR code, a chat, a bill photo or a home screen). "
                . "amount = the rupee amount paid as a number (0 if you cannot read it). date = the payment date as yyyy-mm-dd ('' if not visible). txn_ref = the UPI / transaction reference id exactly as printed ('' if none). "
                . "looks_edited = true if digits or fonts look edited, the date or name is cropped out on purpose, or it is a photo of another screen. "
                . "reason = ONE short plain sentence telling the employee what is wrong or what to retake (empty if all fine). Be fair: normal screenshots pass.";
            $schema = ['type' => 'OBJECT', 'properties' => ['is_payment_screenshot' => ['type' => 'BOOLEAN'], 'amount' => ['type' => 'NUMBER'], 'date' => ['type' => 'STRING'], 'txn_ref' => ['type' => 'STRING'], 'looks_edited' => ['type' => 'BOOLEAN'], 'reason' => ['type' => 'STRING']], 'required' => ['is_payment_screenshot', 'amount', 'looks_edited']];
            try { $r = convo_ai($sys, [['text' => 'Payment screenshot:'], ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $img]]], $schema, false, 400); } catch (Throwable $e) { $r = null; }
            if (is_array($r) && !empty($r[0]) && isset($r[1]) && is_array($r[1]) && array_key_exists('is_payment_screenshot', $r[1])) $v = $r[1];
        }
    }
    $txn = $v ? trim((string)($v['txn_ref'] ?? '')) : ''; $out['txn'] = mb_substr($txn, 0, 80); $out['ai'] = $v !== null;
    // 1. the same picture (or the same transaction) used before
    $q = $pdo->prepare("SELECT * FROM dp_wk_shots WHERE created_at >= ?"); $q->execute([date('Y-m-d H:i:s', strtotime('-60 days'))]);
    foreach ($q->fetchAll() as $r) {
        $dup = false;
        if ($txn !== '' && strlen($txn) >= 6 && $r['txn_ref'] !== null && strcasecmp($txn, (string)$r['txn_ref']) === 0) $dup = true;
        elseif ($out['hash'] && $r['ahash'] && bd_hamming($out['hash'], $r['ahash']) <= 2 && abs((float)$r['amount'] - (float)$amount) < 0.5) {
            $both = $txn !== '' && (string)$r['txn_ref'] !== '' && strcasecmp($txn, (string)$r['txn_ref']) !== 0;   // two different transaction ids = two different payments
            if (!$both) $dup = true;
        }
        if ($dup) {
            bd_inbox('dp_note', bd_emp_name($pdo, $empId) . ' sent a payment screenshot that was already used', 'It matches a screenshot from ' . date('D j M', strtotime($r['created_at'])) . ' (' . dp_inr($r['amount']) . ')' . ((int)$r['emp_id'] !== (int)$empId ? ' of ' . bd_emp_name($pdo, $r['emp_id']) : ' of his own') . '. Not accepted.', ['emp_id' => (int)$empId], null, null, 'wk_dup:' . $empId . ':' . date('YmdHi'));
            $out['ok'] = false; $out['reason'] = 'This screenshot is the same as one already used on ' . date('D j M', strtotime($r['created_at'])) . '. If this is a new payment, take a new screenshot that shows the time and the transaction id.';
            return $out;
        }
    }
    // 2. what Claude reads
    if ($v === null) { $out['note'] = 'Not checked by Claude (a person must look at it).'; return $out; }
    if (empty($v['is_payment_screenshot'])) { $out['ok'] = false; $out['reason'] = trim((string)($v['reason'] ?? '')) ?: 'This does not look like a payment screenshot. Open your UPI app, open the payment, and take a screenshot of the success screen.'; return $out; }
    $seen = (float)($v['amount'] ?? 0);
    if ($seen > 0 && abs($seen - (float)$amount) > 1) { $out['ok'] = false; $out['reason'] = 'The screenshot shows ' . dp_inr($seen) . ' but you typed ' . dp_inr($amount) . '. Fix the amount or send the right screenshot.'; return $out; }
    $d = trim((string)($v['date'] ?? ''));
    if ($d !== '' && ($ts = strtotime($d)) !== false) {
        if ($ts < strtotime(date('Y-m-d', time() - 2 * 86400))) { $out['ok'] = false; $out['reason'] = 'The screenshot is dated ' . date('j M', $ts) . '. It must be a payment made in the last 2 days.'; return $out; }
        if ($ts > time() + 86400) { $out['ok'] = false; $out['reason'] = 'The date on the screenshot (' . date('j M', $ts) . ') is in the future. Send the real screenshot.'; return $out; }
    }
    if (!empty($v['looks_edited'])) { $out['ok'] = false; $out['reason'] = trim((string)($v['reason'] ?? '')) ?: 'This screenshot looks edited. Send the original screenshot.'; return $out; }
    if ($seen <= 0) $out['note'] = 'Claude could not read the amount on the screenshot.';
    return $out;
}
function dp_wk_shot_register(PDO $pdo, $empId, array $chk, $file, $amount) {
    if (empty($chk['hash']) && empty($chk['txn'])) return;
    $pdo->prepare("INSERT INTO dp_wk_shots (emp_id, ahash, txn_ref, amount, file, created_at) VALUES (?,?,?,?,?,?)")->execute([(int)$empId, $chk['hash'] ?: null, $chk['txn'] !== '' ? $chk['txn'] : null, $amount, $file, bd_now()]);
}

/* ============================== views ============================== */
function dp_wk_need_select() {
    $pl = function_exists('ofp_ready') && ofp_ready();   // needs made by registered places also say which place (Pantry) and the Hindi name
    return "SELECT n.*, i.name AS item_name, i.unit AS item_unit, i.supplier_id AS item_supplier, p.code AS pcode, p.name AS pname, p.office_id AS poffice, p.sort_no AS psort, d.status AS di_status" . ($pl ? ", i.name_hi AS item_name_hi, i.zone_id AS item_zone, zz.name AS zone_name" : '') . "
            FROM dp_su_needs n JOIN dp_su_items i ON i.id = n.item_id JOIN dp_su_places p ON p.id = n.place_id LEFT JOIN dp_items d ON d.id = n.dp_item_id" . ($pl ? " LEFT JOIN of_zones zz ON zz.id = i.zone_id" : '');
}
function dp_wk_row(PDO $pdo, array $n, $now, $withSuggest) {
    $due = dp_wk_eff_due($n); $a = null; $state = 'new';
    if (!empty($n['assigned_emp'])) {
        $seen = !empty($n['seen_at']);
        $a = ['id' => (int)$n['assigned_emp'], 'name' => bd_emp_name($pdo, $n['assigned_emp']), 'by' => (string)$n['assigned_by'], 'at' => $n['assigned_at'], 'seen' => $seen, 'seen_label' => $seen ? date('g:i A', strtotime($n['seen_at'])) : ''];
        $state = $seen ? 'seen' : 'given';
    }
    if (($n['di_status'] ?? '') === 'missed') $state = 'held';
    $row = ['id' => (int)$n['id'], 'item' => $n['item_name'], 'unit' => $n['item_unit'], 'qty' => (float)$n['qty'], 'qty_label' => dp_su_q($n['qty']), 'reason' => $n['reason'],
        'place' => $n['pcode'], 'place_name' => $n['pname'], 'due' => $due, 'due_label' => dp_wk_label_time($due), 'late' => strtotime($due) < $now, 'assigned' => $a, 'state' => $state, 'supplier_id' => $n['item_supplier'] ? (int)$n['item_supplier'] : null];
    if (isset($n['item_zone'])) { $row['name_hi'] = (string)($n['item_name_hi'] ?? ''); $row['zone_id'] = $n['item_zone'] ? (int)$n['item_zone'] : null; $row['zone'] = (string)($n['zone_name'] ?? ''); }
    if ($withSuggest) { $p = dp_su_place($pdo, (int)$n['place_id']); $row['suggest'] = $p ? dp_wk_suggest($pdo, $p, $now) : null; }
    return $row;
}
/** purchases grouped by shopping trip (group_ref) */
function dp_wk_bought_groups(PDO $pdo, array $rows) {
    $groups = [];
    foreach ($rows as $b) {
        $key = $b['group_ref'] ? $b['group_ref'] : 'b' . $b['id'];
        if (!isset($groups[$key])) $groups[$key] = ['key' => $key, 'ids' => [], 'first_id' => (int)$b['id'], 'places' => [], 'offices' => [], 'amount' => 0.0, 'paid_by' => $b['paid_by'], 'buyer' => $b['buyer_emp'] ? bd_emp_name($pdo, $b['buyer_emp']) : '', 'buyer_id' => $b['buyer_emp'] ? (int)$b['buyer_emp'] : null,
            'at' => $b['bought_at'], 'shot' => '', 'bill' => '', 'note' => '', 'checked_note' => '', 'proof_state' => '', 'asked_at' => null, 'items' => [], 'supplier' => ''];
        $g = &$groups[$key];
        $g['ids'][] = (int)$b['id']; $g['places'][] = $b['pname']; $g['offices'][] = ['code' => $b['pcode'], 'name' => $b['pname'], 'amount' => (float)$b['amount']]; $g['amount'] += (float)$b['amount'];
        if (!empty($b['shot']) && $g['shot'] === '') $g['shot'] = $b['shot'];
        if (!empty($b['bill_shot']) && $g['bill'] === '') $g['bill'] = $b['bill_shot'];
        if (!empty($b['note']) && $g['note'] === '') $g['note'] = $b['note'];
        if (!empty($b['checked_note']) && $g['checked_note'] === '') $g['checked_note'] = $b['checked_note'];
        if ($b['proof_state'] !== '' && $g['proof_state'] === '') $g['proof_state'] = $b['proof_state'];
        if (!empty($b['proof_ask_at'])) $g['asked_at'] = $b['proof_ask_at'];
        $q = $pdo->prepare("SELECT i.name, i.unit, bi.qty FROM dp_su_bought_items bi JOIN dp_su_items i ON i.id = bi.item_id WHERE bi.bought_id = ?"); $q->execute([(int)$b['id']]);
        foreach ($q->fetchAll() as $r) $g['items'][] = mb_strtolower($r['name']) . ' ' . dp_su_q($r['qty']) . ' ' . $r['unit'] . ' (' . $b['pname'] . ')';
        if ($b['supplier_id'] && $g['supplier'] === '') { $s = dp_su_supplier($pdo, $b['supplier_id']); $g['supplier'] = $s ? $s['name'] : ''; }
        unset($g);
    }
    $out = [];
    foreach ($groups as $g) {
        $done = in_array($g['proof_state'], ['added', 'ok'], true);
        $g['has_proof'] = $g['shot'] !== '' || $g['bill'] !== '';
        $g['missing'] = !$g['has_proof'] && in_array($g['paid_by'], ['pocket', 'cash', 'credit'], true) && !$done;
        $g['flagged'] = $g['checked_note'] !== '' && !$done;
        $g['first_id'] = min($g['ids']); natcasesort($g['places']); $g['places'] = array_values($g['places']); usort($g['offices'], function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        $g['place_label'] = dp_wk_label($g['places']); $g['at_label'] = date('D g:i A', strtotime($g['at']));
        $out[] = $g;
    }
    return $out;
}
function dp_wk_bought_rows(PDO $pdo, $days, $buyer = null) {
    $sql = "SELECT b.*, p.code AS pcode, p.name AS pname FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.bought_at >= ?"; $args = [date('Y-m-d H:i:s', strtotime('-' . (int)$days . ' days'))];
    if ($buyer !== null) { $sql .= " AND b.buyer_emp = ?"; $args[] = (int)$buyer; }
    $st = $pdo->prepare($sql . " ORDER BY b.id DESC LIMIT 400"); $st->execute($args);
    return $st->fetchAll();
}
function dp_wk_push_assign(PDO $pdo, $emp, array $needIds) {
    $q = $pdo->query(dp_wk_need_select() . " WHERE n.id IN (" . implode(',', array_map('intval', $needIds)) . ")"); $needs = $q->fetchAll();
    if (!$needs) return;
    list($title, $detail) = dp_wk_need_text($pdo, $needs); $due = null; foreach ($needs as $n) { $d = dp_wk_eff_due($n); if ($due === null || $d < $due) $due = $d; }
    bd_push($pdo, $emp, 'NEW · ' . $title, mb_substr($detail, 0, 100) . ' · by ' . date('g:i A', strtotime($due)));
}

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'dp.wk_') === 0) {
    dp_ensure($pdo); dp_su_ensure($pdo); dp_wk_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $auth = require_auth();
    $acc = dp_su_acc($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];
    if (!is_array($in)) $in = [];
    $now = time(); $emp = (int)$acc['emp'];
    $needAssigner = function () use ($acc) { if (!dp_wk_any_assign($acc)) json_response(['error' => 'Only Admin, HR, the Supplies boss or an office checker can do this.'], 403); };
    $ids_of = function ($v) { $o = []; foreach ((array)$v as $x) { $x = (int)$x; if ($x > 0) $o[$x] = $x; } return array_values($o); };
    $loadNeeds = function (array $ids) use ($pdo) {
        if (!$ids) json_response(['error' => 'Tick at least one item.'], 422);
        $q = $pdo->query(dp_wk_need_select() . " WHERE n.id IN (" . implode(',', array_map('intval', $ids)) . ")"); $rows = $q->fetchAll();
        if (count($rows) !== count($ids)) json_response(['error' => 'One of the items does not exist any more. Refresh the list.'], 404);
        return $rows;
    };

    switch ($action) {

    /* ---------------- the boss's list ---------------- */
    case 'dp.wk_list': {
        if (!dp_wk_any_assign($acc)) json_response(['error' => 'The Shopping list is for Admin, HR, the Supplies boss and office checkers. Buyers use "My shopping".'], 403);
        $tab = (string)($_GET['tab'] ?? 'buy'); if (!in_array($tab, ['buy', 'bought', 'proof'], true)) $tab = 'buy';
        $only = (string)($_GET['place'] ?? '');
        $mine = array_values(array_filter($acc['places'], function ($p) use ($acc) { return $acc['mgr'] || isset($acc['chk'][$p['code']]); }));
        $codes = array_map(function ($p) { return $p['code']; }, $mine);
        if ($only !== '' && !in_array($only, $codes, true)) json_response(['error' => 'Not your office.'], 403);
        $useCodes = $only !== '' ? [$only] : $codes;
        $ph = $useCodes ? implode(',', array_fill(0, count($useCodes), '?')) : "''";
        // open needs
        $q = $pdo->prepare(dp_wk_need_select() . " WHERE n.status = 'open' AND p.active = 1 AND p.code IN ($ph) ORDER BY COALESCE(n.assigned_due, n.needed_by), p.sort_no, i.name"); $q->execute($useCodes ? $useCodes : []);
        $needs = $q->fetchAll();
        $chips = []; $open = 0; $unassigned = 0; $overdue = 0; $perPlace = [];
        foreach ($needs as $n) {
            $open++; if (empty($n['assigned_emp'])) $unassigned++; if (strtotime(dp_wk_eff_due($n)) < $now) $overdue++;
        }
        // chips always show every office of mine with its open count (not filtered)
        $qq = $pdo->prepare("SELECT place_id, COUNT(*) c FROM dp_su_needs WHERE status = 'open' GROUP BY place_id"); $qq->execute(); foreach ($qq->fetchAll() as $r) $perPlace[(int)$r['place_id']] = (int)$r['c'];
        foreach ($mine as $p) $chips[] = ['code' => $p['code'], 'name' => $p['name'], 'open' => $perPlace[(int)$p['id']] ?? 0];
        $groupsAll = dp_wk_bought_groups($pdo, dp_wk_bought_rows($pdo, 7));
        $proofAll = array_values(array_filter($groupsAll, function ($g) { return $g['missing'] || $g['flagged']; }));
        $rows = [];
        if ($tab === 'buy') { $cache = []; foreach ($needs as $n) { $r = dp_wk_row($pdo, $n, $now, true); $rows[] = $r; } }
        $filterG = function ($list) use ($useCodes) { return array_values(array_filter($list, function ($g) use ($useCodes) { foreach ($g['offices'] as $o) if (in_array($o['code'], $useCodes, true)) return true; return false; })); };
        $bought = $tab === 'bought' ? $filterG($groupsAll) : [];
        $proof = $tab === 'proof' ? $filterG($proofAll) : [];
        $emps = [];
        $today = date('Y-m-d', $now);
        $cnt = $pdo->query("SELECT assigned_emp, COUNT(*) c FROM dp_su_needs WHERE status = 'open' AND assigned_emp IS NOT NULL GROUP BY assigned_emp")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (bd_employees($pdo) as $e) { $away = dp_wk_away($pdo, $e['id'], $today); $emps[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'dept' => $e['dept'], 'open' => (int)($cnt[$e['id']] ?? 0), 'away' => $away !== '', 'away_why' => $away]; }
        json_response(['live' => dp_live($pdo, 'supplies'), 'tab' => $tab, 'place' => $only,
            'stats' => ['to_buy' => $open, 'not_given' => $unassigned, 'waiting_proof' => count($filterG($proofAll)), 'overdue' => $overdue, 'bought' => count($filterG($groupsAll))],
            'places' => $chips, 'rows' => $rows, 'bought' => $bought, 'proof' => $proof, 'employees' => $emps,
            'can' => ['assign' => true, 'settings' => !empty($acc['mgr'])], 'mgr' => !empty($acc['mgr']),
            'settings' => ['repeat_min' => (int)dp_get('wk_repeat_min', '60'), 'shot_ai' => dp_get('wk_shot_ai', '1') === '1'],
            'mode' => bd_mode(), 'now' => date('Y-m-d H:i:s', $now)]);
    }

    case 'dp.wk_settings': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (empty($acc['mgr'])) json_response(['error' => 'Only Admin, HR or the Supplies boss can change this.'], 403);
        if (isset($in['repeat_min'])) { $m = (int)$in['repeat_min']; if ($m !== 0 && ($m < 15 || $m > 480)) json_response(['error' => 'Reminder every 15 to 480 minutes, or 0 for none.'], 422); dp_put('wk_repeat_min', $m); }
        if (isset($in['shot_ai'])) dp_put('wk_shot_ai', !empty($in['shot_ai']) ? '1' : '0');
        json_response(['ok' => true, 'repeat_min' => (int)dp_get('wk_repeat_min', '60'), 'shot_ai' => dp_get('wk_shot_ai', '1') === '1']);
    }

    /* ---------------- give / take back ---------------- */
    case 'dp.wk_assign': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needAssigner(); dp_su_need_live($pdo);
        $ids = $ids_of($in['need_ids'] ?? []); $rows = $loadNeeds($ids);
        $to = (int)($in['emp_id'] ?? 0); $e = null; foreach (bd_employees($pdo) as $x) if ((int)$x['id'] === $to) $e = $x;
        if (!$e) json_response(['error' => 'Choose the person who will buy.'], 422);
        $away = dp_wk_away($pdo, $to, date('Y-m-d', $now)); if ($away !== '') json_response(['error' => $e['name'] . ' is away today (' . $away . '). Choose somebody else.'], 422);
        $wanted = isset($in['due']) ? (string)$in['due'] : null;
        $ok = []; $skipped = [];
        foreach ($rows as $n) {
            if (!dp_wk_can_assign($acc, $n['pcode'])) json_response(['error' => 'You cannot give items of ' . $n['pname'] . '.'], 403);
            if ($n['status'] !== 'open') { $skipped[] = $n['item_name'] . ' (' . $n['pname'] . ') is already bought'; continue; }
            if ($n['di_status'] === 'missed') { $skipped[] = $n['item_name'] . ' (' . $n['pname'] . ') was already missed today; it comes back tomorrow'; continue; }
            $ok[] = $n;
        }
        if (!$ok) json_response(['error' => 'Nothing could be given: ' . implode('; ', $skipped) . '.'], 409);
        $due = null;
        foreach ($ok as $n) {
            $d = dp_wk_due($wanted, $n['needed_by'], $now); if ($due === null || $d < $due) $due = $d;
            $pdo->prepare("UPDATE dp_su_needs SET assigned_emp = ?, assigned_by = ?, assigned_by_emp = ?, assigned_at = ?, assigned_due = ?, seen_at = NULL, dp_item_id = NULL, penalised = 0 WHERE id = ?")
                ->execute([$to, $by, $emp ?: null, bd_now(), $d, (int)$n['id']]);
        }
        $GLOBALS['dp_wk_quiet'] = true;   // ONE notification for the whole batch (below)
        dp_su_sync_items($pdo, $now);
        $GLOBALS['dp_wk_quiet'] = false;
        $okIds = array_map(function ($n) { return (int)$n['id']; }, $ok);
        dp_wk_push_assign($pdo, $to, $okIds);
        // the push time starts the hourly reminder
        $it = $pdo->prepare("SELECT id, payload FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE ? AND status = 'open' ORDER BY id LIMIT 1"); $it->execute(['su:wk:' . $to . ':%']); $ir = $it->fetch();
        if ($ir) { $pl = bd_unjson($ir['payload']); $pl['last_push'] = $now; $pdo->prepare("UPDATE dp_items SET payload = ? WHERE id = ?")->execute([bd_json($pl), (int)$ir['id']]); }
        bd_log('supplies', 'shopping', 'gave ' . count($ok) . ' item(s) to ' . $e['name'], $by);
        json_response(['ok' => true, 'assigned' => count($ok), 'skipped' => $skipped, 'emp_name' => $e['name'], 'due' => $due, 'due_label' => dp_wk_label_time($due), 'item_id' => $ir ? (int)$ir['id'] : null,
            'notified' => bd_mode() !== 'shadow', 'message' => 'Given ' . count($ok) . ' item' . (count($ok) === 1 ? '' : 's') . ' to ' . $e['name'] . ', needed by ' . dp_wk_label_time($due) . '.' . (bd_mode() === 'shadow' ? ' (Shadow mode: no notification was sent.)' : ' He has been notified.')]);
    }

    case 'dp.wk_unassign': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needAssigner(); dp_su_need_live($pdo);
        $rows = $loadNeeds($ids_of($in['need_ids'] ?? [])); $n = 0;
        foreach ($rows as $r) {
            if (!dp_wk_can_assign($acc, $r['pcode'])) json_response(['error' => 'You cannot change items of ' . $r['pname'] . '.'], 403);
            if ($r['status'] !== 'open' || empty($r['assigned_emp'])) continue;
            $pdo->prepare("UPDATE dp_su_needs SET assigned_emp = NULL, assigned_by = NULL, assigned_by_emp = NULL, assigned_at = NULL, assigned_due = NULL, seen_at = NULL, dp_item_id = NULL WHERE id = ?")->execute([(int)$r['id']]); $n++;
        }
        dp_su_sync_items($pdo, $now);
        json_response(['ok' => true, 'taken_back' => $n, 'message' => $n ? $n . ' item' . ($n === 1 ? '' : 's') . ' back with the office\'s own buyer.' : 'Nothing was given to anybody.']);
    }

    /* ---------------- the buyer ---------------- */
    case 'dp.wk_mine': {
        if (!$emp && empty($acc['mgr'])) json_response(['error' => 'Your login is not linked to an employee.'], 403);
        $live = dp_live($pdo, 'supplies');
        $rows = [];
        if ($emp) {
            $q = $pdo->prepare(dp_wk_need_select() . " WHERE n.status = 'open' AND n.assigned_emp = ? ORDER BY p.sort_no, COALESCE(n.assigned_due, n.needed_by), i.name"); $q->execute([$emp]); $needs = $q->fetchAll();
            $unseen = [];
            foreach ($needs as $n) { if (empty($n['seen_at'])) $unseen[] = (int)$n['id']; $rows[] = dp_wk_row($pdo, $n, $now, false); }
            $fresh = $unseen;
            if ($unseen) $pdo->exec("UPDATE dp_su_needs SET seen_at = '" . bd_now() . "' WHERE id IN (" . implode(',', $unseen) . ")");
            foreach ($rows as &$r) { $r['new'] = in_array($r['id'], $fresh, true); } unset($r);
        }
        $due = null; foreach ($rows as $r) if ($due === null || $r['due'] < $due) $due = $r['due'];
        $recent = $emp ? dp_wk_bought_groups($pdo, dp_wk_bought_rows($pdo, 3, $emp)) : [];
        $proofs = array_values(array_filter($recent, function ($g) { return ($g['missing'] || $g['flagged']) && $g['proof_state'] === 'asked'; }));
        $sups = []; foreach ($pdo->query("SELECT id, name FROM dp_su_suppliers WHERE active = 1 ORDER BY name")->fetchAll() as $s) $sups[] = ['id' => (int)$s['id'], 'name' => $s['name']];
        $by_place = []; foreach ($rows as $r) { if (!isset($by_place[$r['place']])) $by_place[$r['place']] = ['code' => $r['place'], 'name' => $r['place_name']]; }
        json_response(['live' => $live, 'boss' => dp_wk_any_assign($acc), 'emp' => $emp, 'name' => $emp ? bd_emp_name($pdo, $emp) : '', 'rows' => $rows, 'offices' => array_values($by_place), 'office_label' => dp_wk_label(array_map(function ($p) { return $p['name']; }, array_values($by_place))),
            'due' => $due, 'due_label' => dp_wk_label_time($due), 'suppliers' => $sups, 'proofs' => $proofs, 'recent' => array_slice($recent, 0, 6)]);
    }

    case 'dp.wk_nostock': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); dp_su_need_live($pdo);
        $rows = $loadNeeds($ids_of($in['need_ids'] ?? [])); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200); $n = 0; $by_emp = [];
        foreach ($rows as $r) { if ((int)$r['assigned_emp'] !== $emp && empty($acc['mgr'])) json_response(['error' => 'This was not given to you.'], 403); }
        foreach ($rows as $r) {
            if ($r['status'] !== 'open' || empty($r['assigned_emp'])) continue;
            $by_emp[(int)($r['assigned_by_emp'] ?? 0)][] = $r['item_name'] . ' (' . $r['pname'] . ')';
            $pdo->prepare("UPDATE dp_su_needs SET assigned_emp = NULL, assigned_by = NULL, assigned_by_emp = NULL, assigned_at = NULL, assigned_due = NULL, seen_at = NULL, dp_item_id = NULL WHERE id = ?")->execute([(int)$r['id']]);
            bd_inbox('dp_note', bd_emp_name($pdo, $r['assigned_emp']) . ': the shop did not have ' . $r['item_name'] . ' (' . $r['pname'] . ')', ($note !== '' ? $note . ' ' : '') . 'It is back on the Shopping list. Give it to somebody else or tell the office buyer.', ['need_id' => (int)$r['id']], null, !empty($r['assigned_by_emp']) ? (int)$r['assigned_by_emp'] : null, 'wk_nostock:' . $r['id'] . ':' . date('Ymd')); $n++;
        }
        dp_su_sync_items($pdo, $now);
        json_response(['ok' => true, 'put_back' => $n, 'message' => $n ? 'Put back. The person who gave it has been told.' : 'Nothing to put back.']);
    }

    case 'dp.wk_bought': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); dp_su_need_live($pdo);
        if (!$emp && empty($acc['mgr'])) json_response(['error' => 'Your login is not linked to an employee.'], 403);
        $lines = json_decode((string)($_POST['lines'] ?? '[]'), true); if (!is_array($lines) || !$lines) json_response(['error' => 'Tick what you bought.'], 422);
        $paid = (string)($_POST['paid_by'] ?? ''); if (!in_array($paid, ['upi', 'credit', 'pocket', 'cash'], true)) json_response(['error' => 'Choose how it was paid: Company UPI, Supplier ledger (credit), Own pocket or Cash.'], 422);
        $sid = (int)($_POST['supplier_id'] ?? 0); $sup = $sid ? dp_su_supplier($pdo, $sid) : null;
        if ($sid && !$sup) json_response(['error' => 'Unknown supplier.'], 422);
        if ($paid === 'credit' && !$sup) json_response(['error' => 'Supplier ledger needs the supplier. Choose the supplier.'], 422);
        $groups = []; $seen = [];
        foreach ($lines as $l) {
            $nid = (int)($l['need_id'] ?? 0); if (isset($seen[$nid])) continue; $seen[$nid] = 1;
            $q = $pdo->prepare(dp_wk_need_select() . " WHERE n.id = ?"); $q->execute([$nid]); $n = $q->fetch();
            if (!$n || $n['status'] !== 'open') json_response(['error' => 'One of the items is already bought or not on the list any more. Refresh.'], 409);
            $mineNeed = (int)$n['assigned_emp'] === $emp && $emp;
            if (!$mineNeed && empty($acc['mgr']) && !(empty($n['assigned_emp']) && isset($acc['buy'][$n['pcode']]))) json_response(['error' => 'This item was not given to you.'], 403);
            $q2 = isset($l['qty']) ? (float)$l['qty'] : (float)$n['qty']; if ($q2 <= 0 || $q2 > 100000) json_response(['error' => 'Enter a quantity for ' . $n['item_name'] . '.'], 422);
            $it = dp_su_item($pdo, $n['item_id']); if (!isset($groups[$n['pcode']])) $groups[$n['pcode']] = ['place' => dp_su_place($pdo, (int)$n['place_id']), 'rows' => []];
            $groups[$n['pcode']]['rows'][] = [$it, $q2];
        }
        $amts = json_decode((string)($_POST['amounts'] ?? '{}'), true); if (!is_array($amts)) $amts = [];
        $total = 0.0;
        foreach ($groups as $code => &$g) {
            $a = isset($amts[$code]) ? $amts[$code] : (count($groups) === 1 ? ($_POST['amount'] ?? 0) : 0); $a = round((float)$a, 2);
            if ($a <= 0 || $a > 1000000) json_response(['error' => 'Enter the amount paid for ' . $g['place']['name'] . ' (more than 0).'], 422);
            $g['amount'] = $a; $total += $a;
        } unset($g);
        $shot = dp_su_upload('shot', 'su', $paid === 'upi', 'Company UPI payments need the payment screenshot. Take or choose it first.');
        $bill = dp_su_upload('bill', 'subill', false, '');
        $chk = ['ok' => true, 'note' => '', 'hash' => null, 'txn' => ''];
        if ($shot && in_array($paid, ['upi', 'pocket'], true)) {
            $chk = dp_wk_shot_check($pdo, $emp, dp_dir() . $shot, $total);
            if (!$chk['ok']) { @unlink(dp_dir() . $shot); if ($bill) @unlink(dp_dir() . $bill); json_response(['error' => $chk['reason'], 'shot_rejected' => true], 422); }
        }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 240);
        $gref = 'g' . date('ymdHis') . substr(bin2hex(random_bytes(3)), 0, 6);
        $msgs = []; $warns = []; $bids = [];
        foreach ($groups as $code => $g) {
            list($m, $w, $bid) = dp_su_record_purchase($pdo, $emp, $g['place'], $paid, $g['amount'], $g['rows'], $sup, $shot, $note, $by, $now, ['bill' => $bill, 'checked_note' => $chk['note'], 'group_ref' => $gref]);
            $bids[] = $bid; if ($w !== '' && !in_array($w, $warns, true)) $warns[] = $w;
        }
        if ($shot && in_array($paid, ['upi', 'pocket'], true)) dp_wk_shot_register($pdo, $emp, $chk, $shot, $total);
        $cnt = 0; foreach ($groups as $g) $cnt += count($g['rows']);
        $proofLater = !$shot && !$bill && in_array($paid, ['pocket', 'cash', 'credit'], true);
        json_response(['ok' => true, 'message' => 'Saved. ' . $cnt . ' item' . ($cnt === 1 ? '' : 's') . ' bought, ' . dp_inr($total) . ' in ' . count($groups) . ' office' . (count($groups) === 1 ? '' : 's') . '. Each office\'s list is updated.',
            'warning' => implode(' ', $warns), 'ids' => $bids, 'group' => $gref, 'checked_note' => $chk['note'], 'proof_needed' => $proofLater]);
    }

    /* ---------------- proof ---------------- */
    case 'dp.wk_proof_ask': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needAssigner();
        $b = $pdo->prepare("SELECT b.*, p.code AS pcode, p.name AS pname FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.id = ?"); $b->execute([(int)($in['bought_id'] ?? 0)]); $r = $b->fetch();
        if (!$r) json_response(['error' => 'Purchase not found.'], 404);
        $all = $r['group_ref'] ? $pdo->prepare("SELECT b.*, p.code AS pcode, p.name AS pname FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.group_ref = ? ORDER BY b.id") : null;
        $grp = [$r]; if ($all) { $all->execute([$r['group_ref']]); $grp = $all->fetchAll(); }
        foreach ($grp as $g) if (!dp_wk_can_assign($acc, $g['pcode'])) json_response(['error' => 'Not your office.'], 403);
        if (!$r['buyer_emp']) json_response(['error' => 'Nobody is recorded as the buyer of this purchase.'], 422);
        $tot = 0; $names = []; foreach ($grp as $g) { $tot += (float)$g['amount']; $names[] = $g['pname']; }
        $firstId = (int)$grp[0]['id']; $label = dp_wk_label($names); $nt = mb_substr(trim((string)($in['note'] ?? '')), 0, 200);
        $pdo->prepare("UPDATE dp_su_bought SET proof_state = 'asked', proof_ask_at = ? WHERE id IN (" . implode(',', array_map(function ($g) { return (int)$g['id']; }, $grp)) . ")")->execute([bd_now()]);
        $iid = dp_item_make($pdo, 'supplies', '', 'su:proof:' . $firstId, 'self', 'Add the payment proof: ' . $label . ' (' . dp_inr($tot) . ')', ($nt !== '' ? $nt . ' · ' : '') . 'Upload the payment screenshot or the bill photo.', date('Y-m-d', $now), null,
            ['kind' => 'proof', 'bought_id' => $firstId, 'page' => 'dept_work.html?me=1&proof=' . $firstId], (int)$r['buyer_emp'], 1);
        $pdo->prepare("UPDATE dp_items SET status = 'open', done_at = NULL WHERE id = ?")->execute([$iid]);
        bd_push($pdo, (int)$r['buyer_emp'], 'Proof needed: ' . $label, 'Please upload the payment screenshot or bill for ' . dp_inr($tot) . '.');
        bd_log('supplies', 'shopping', 'asked proof for purchase #' . $firstId, $by);
        json_response(['ok' => true, 'item_id' => $iid, 'message' => 'Asked ' . bd_emp_name($pdo, $r['buyer_emp']) . ' for the proof. It is on his My Day.']);
    }

    case 'dp.wk_proof_add': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); dp_su_need_live($pdo);
        $b = $pdo->prepare("SELECT * FROM dp_su_bought WHERE id = ?"); $b->execute([(int)($_POST['bought_id'] ?? 0)]); $r = $b->fetch();
        if (!$r) json_response(['error' => 'Purchase not found.'], 404);
        if ((int)$r['buyer_emp'] !== $emp && empty($acc['mgr'])) json_response(['error' => 'Only the person who bought it can add the proof.'], 403);
        $q = $r['group_ref'] ? $pdo->prepare("SELECT * FROM dp_su_bought WHERE group_ref = ? ORDER BY id") : null; $grp = [$r]; if ($q) { $q->execute([$r['group_ref']]); $grp = $q->fetchAll(); }
        $tot = 0; foreach ($grp as $g) $tot += (float)$g['amount'];
        $shot = dp_su_upload('shot', 'su', false, ''); $bill = dp_su_upload('bill', 'subill', false, '');
        if (!$shot && !$bill) json_response(['error' => 'Add the payment screenshot or a photo of the bill.'], 422);
        $chk = ['ok' => true, 'note' => '', 'hash' => null, 'txn' => ''];
        if ($shot && in_array($r['paid_by'], ['upi', 'pocket'], true)) {
            $chk = dp_wk_shot_check($pdo, (int)$r['buyer_emp'] ?: $emp, dp_dir() . $shot, $tot);
            if (!$chk['ok']) { @unlink(dp_dir() . $shot); if ($bill) @unlink(dp_dir() . $bill); json_response(['error' => $chk['reason'], 'shot_rejected' => true], 422); }
        }
        $idl = implode(',', array_map(function ($g) { return (int)$g['id']; }, $grp));
        if ($shot) $pdo->exec("UPDATE dp_su_bought SET shot = '" . $shot . "' WHERE id IN ($idl) AND (shot IS NULL OR shot = '')");
        if ($bill) $pdo->exec("UPDATE dp_su_bought SET bill_shot = '" . $bill . "' WHERE id IN ($idl)");
        $pdo->prepare("UPDATE dp_su_bought SET proof_state = 'added', checked_note = ? WHERE id IN ($idl)")->execute([$chk['note'] !== '' ? $chk['note'] : null]);
        if ($shot && in_array($r['paid_by'], ['upi', 'pocket'], true)) dp_wk_shot_register($pdo, $emp, $chk, $shot, $tot);
        foreach ($grp as $g) { $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ? WHERE ref_key = ? AND status = 'open'")->execute([bd_now(), 'su:proof:' . (int)$g['id']]); }
        json_response(['ok' => true, 'message' => 'Thank you. The proof is saved.', 'checked_note' => $chk['note']]);
    }

    case 'dp.wk_proof_clear': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needAssigner();
        $b = $pdo->prepare("SELECT b.*, p.code AS pcode FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.id = ?"); $b->execute([(int)($in['bought_id'] ?? 0)]); $r = $b->fetch();
        if (!$r) json_response(['error' => 'Purchase not found.'], 404);
        if (!dp_wk_can_assign($acc, $r['pcode'])) json_response(['error' => 'Not your office.'], 403);
        $q = $r['group_ref'] ? $pdo->prepare("SELECT id FROM dp_su_bought WHERE group_ref = ?") : null; $ids = [(int)$r['id']]; if ($q) { $q->execute([$r['group_ref']]); $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)); }
        $pdo->exec("UPDATE dp_su_bought SET proof_state = 'ok' WHERE id IN (" . implode(',', $ids) . ")");
        foreach ($ids as $i) $pdo->prepare("UPDATE dp_items SET status = 'done', done_at = ? WHERE ref_key = ? AND status = 'open'")->execute([bd_now(), 'su:proof:' . $i]);
        bd_log('supplies', 'shopping', 'accepted purchase #' . $r['id'] . ' without more proof', $by);
        json_response(['ok' => true, 'message' => 'Marked as fine.']);
    }

    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in the Shopping list: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}
