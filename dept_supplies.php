<?php
/**
 * dept_supplies.php — Office supplies (pantry and consumables). A department of dept.php (topic "supplies").
 * Put it in the SAME folder as api.php, boss.php and dept.php. dept.php loads it by itself.
 *
 * What it does
 *  - Places: Surat store, Oberon 1/2/3, Nishal office, RJD Hub (and every office in Offices). Each place has its list of supplies.
 *  - The checker marks every item Ok / Low / Empty at the normal check. Low or Empty goes straight to "Need to buy".
 *    Daily items (milk, ice) come up every morning. Items on a cycle (every 10 / 15 days) come up on their day.
 *  - After 2 to 3 days of checks Claude works out how often each item must come and PROPOSES a cycle. You confirm it. Never silently.
 *  - The buyer taps "Bought": items, amount, how it was paid, screenshot. Own pocket -> reimbursement (main admin pays back by UPI with a screenshot).
 *    Supplier on credit -> pending bill in the supplier ledger; every payment of a bill needs a payment screenshot. Cash -> "please avoid cash".
 *  - Not bought by the time it was needed -> "Supply not bought on its day" (amount: you decide, Boss > Penalties).
 *  - Nothing is made for staff, and nobody is fined, until the topic is live (Boss > Questions: "This is right, make it live").
 * Own tables: dp_su_*. PHP 7.4 safe.
 */
if (!defined('DP_LOADED') || !isset($pdo, $action) || !function_exists('json_response')) { return; }
if (defined('DP_SU_LOADED')) { return; }
define('DP_SU_LOADED', 1);

/* ============================== tables ============================== */
function dp_su_ensure(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $flag = sys_get_temp_dir() . '/hrms_dp_su_tables_v2_' . substr(md5(__DIR__), 0, 8);
    $have = is_file($flag);
    if ($have) { try { $pdo->query("SELECT 1 FROM dp_su_needs LIMIT 1"); $pdo->query("SELECT 1 FROM dp_su_cycles LIMIT 1"); $pdo->query("SELECT assigned_emp, assigned_due FROM dp_su_needs LIMIT 1"); $pdo->query("SELECT bill_shot, checked_note, proof_state FROM dp_su_bought LIMIT 1"); } catch (Throwable $e) { $have = false; } }
    if (!$have) {
        $E = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_places (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, office_id INT NULL, active TINYINT NOT NULL DEFAULT 1, sort_no INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, phone VARCHAR(30) NULL, items VARCHAR(200) NULL, note VARCHAR(250) NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_items (
            id INT AUTO_INCREMENT PRIMARY KEY, place_id INT NOT NULL, name VARCHAR(100) NOT NULL, unit VARCHAR(20) NOT NULL DEFAULT 'pc', mode VARCHAR(8) NOT NULL DEFAULT 'learn', cycle_days SMALLINT NULL,
            qty DECIMAL(10,2) NOT NULL DEFAULT 1, supplier_id INT NULL, state VARCHAR(8) NOT NULL DEFAULT 'ok', state_at DATETIME NULL, last_bought DATE NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'active', source VARCHAR(8) NOT NULL DEFAULT 'manual', created_at DATETIME NOT NULL, UNIQUE KEY uq_place_name (place_id, name), INDEX (place_id, status)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_checks (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, place_id INT NOT NULL, state VARCHAR(8) NOT NULL, emp_id INT NULL, checked_on DATE NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_item_day (item_id, checked_on), INDEX (place_id, checked_on)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_needs (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, place_id INT NOT NULL, need_date DATE NOT NULL, reason VARCHAR(8) NOT NULL, qty DECIMAL(10,2) NOT NULL DEFAULT 1, needed_by DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open', penalised TINYINT NOT NULL DEFAULT 0, reset_on DATE NULL, dp_item_id INT NULL, bought_id INT NULL, created_at DATETIME NOT NULL, closed_at DATETIME NULL,
            UNIQUE KEY uq_need (item_id, need_date, reason), INDEX (place_id, status), INDEX (status, needed_by)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_bought (
            id INT AUTO_INCREMENT PRIMARY KEY, place_id INT NOT NULL, buyer_emp INT NULL, paid_by VARCHAR(8) NOT NULL, supplier_id INT NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0, note VARCHAR(250) NULL, shot VARCHAR(160) NULL,
            bought_at DATETIME NOT NULL, reimb_status VARCHAR(8) NOT NULL DEFAULT 'none', reimb_paid_by VARCHAR(80) NULL, reimb_paid_at DATETIME NULL, reimb_shot VARCHAR(160) NULL, INDEX (place_id, bought_at), INDEX (reimb_status)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_bought_items (id INT AUTO_INCREMENT PRIMARY KEY, bought_id INT NOT NULL, item_id INT NOT NULL, qty DECIMAL(10,2) NOT NULL DEFAULT 1, INDEX (bought_id), INDEX (item_id))$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT NOT NULL, kind VARCHAR(8) NOT NULL, amount DECIMAL(10,2) NOT NULL, settled DECIMAL(10,2) NOT NULL DEFAULT 0, bought_id INT NULL, note VARCHAR(250) NULL,
            shot VARCHAR(160) NULL, status VARCHAR(8) NOT NULL DEFAULT 'pending', on_date DATE NOT NULL, created_by VARCHAR(80) NULL, created_at DATETIME NOT NULL, INDEX (supplier_id, kind, status)
        )$E");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dp_su_cycles (
            id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, cycle_days SMALLINT NOT NULL, spans SMALLINT NOT NULL DEFAULT 0, basis VARCHAR(250) NULL, status VARCHAR(10) NOT NULL DEFAULT 'proposed',
            decided_by VARCHAR(80) NULL, decided_at DATETIME NULL, created_at DATETIME NOT NULL, INDEX (item_id, status)
        )$E");
        // Shopping list (all offices): who was given the need, and the proof state of a purchase. Added on top of the v1 tables, safe to repeat.
        $addCol = function ($table, $col, $def) use ($pdo) { try { $q = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'"); if (!$q->fetch()) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Throwable $e) { error_log('dp_su_ensure ' . $table . '.' . $col . ': ' . $e->getMessage()); } };
        foreach (['assigned_emp' => 'INT NULL', 'assigned_by' => 'VARCHAR(80) NULL', 'assigned_by_emp' => 'INT NULL', 'assigned_at' => 'DATETIME NULL', 'assigned_due' => 'DATETIME NULL', 'seen_at' => 'DATETIME NULL'] as $c => $d) $addCol('dp_su_needs', $c, $d);
        foreach (['bill_shot' => 'VARCHAR(160) NULL', 'checked_note' => 'VARCHAR(200) NULL', 'proof_state' => "VARCHAR(10) NOT NULL DEFAULT ''", 'group_ref' => 'VARCHAR(40) NULL', 'proof_ask_at' => 'DATETIME NULL'] as $c => $d) $addCol('dp_su_bought', $c, $d);
        @file_put_contents($flag, '1');
    }
    dp_su_sync_places($pdo);
}

/* ============================== small helpers ============================== */
function dp_su_slug($s) { $s = strtolower(trim((string)$s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-') ?: 'place'; }
function dp_su_norm($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }
function dp_su_q($n) { $n = (float)$n; return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); }
function dp_su_place(PDO $pdo, $codeOrId) { if (is_numeric($codeOrId)) { $st = $pdo->prepare("SELECT * FROM dp_su_places WHERE id = ?"); } else { $st = $pdo->prepare("SELECT * FROM dp_su_places WHERE code = ?"); } $st->execute([$codeOrId]); return $st->fetch() ?: null; }
function dp_su_item(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function dp_su_supplier(PDO $pdo, $id) { $st = $pdo->prepare("SELECT * FROM dp_su_suppliers WHERE id = ?"); $st->execute([(int)$id]); return $st->fetch() ?: null; }
function dp_su_names(PDO $pdo, array $ids) { return array_values(array_map(function ($i) use ($pdo) { return bd_emp_name($pdo, $i); }, $ids)); }
function dp_su_defaults() {
    // name, unit, mode, qty
    return [['Milk', 'L', 'daily', 2], ['Ice', 'kg', 'daily', 5], ['Coffee', 'pack', 'learn', 1], ['Tea', 'pack', 'learn', 1], ['Hand wash', 'bottle', 'learn', 1], ['Toilet cleaner', 'bottle', 'learn', 1], ['Salt', 'pack', 'learn', 1]];
}
function dp_su_seed_items(PDO $pdo, $placeId) {
    if (dp_get('su_seeded_' . $placeId, '') === '1') return;
    foreach (dp_su_defaults() as $d) {
        $pdo->prepare("INSERT IGNORE INTO dp_su_items (place_id, name, unit, mode, qty, status, source, created_at) VALUES (?,?,?,?,?, 'active', 'default', ?)")->execute([(int)$placeId, $d[0], $d[1], $d[2], $d[3], bd_now()]);
    }
    dp_put('su_seeded_' . $placeId, '1');
}
/** the places: the six you named, plus every office from Offices. Linked to an office when the names match. */
function dp_su_sync_places(PDO $pdo) {
    $offices = [];
    try { foreach ($pdo->query("SELECT id, name FROM of_offices WHERE active = 1")->fetchAll() as $o) $offices[] = $o; } catch (Throwable $e) {}
    $find = function ($name) use ($offices) { $n = dp_su_norm($name); foreach ($offices as $o) if (dp_su_norm($o['name']) === $n) return (int)$o['id']; return 0; };
    $names = ['Surat store', 'Oberon 1', 'Oberon 2', 'Oberon 3', 'Nishal office', 'RJD Hub'];
    $sort = 0;
    foreach ($names as $nm) {
        $sort += 10; $code = dp_su_slug($nm); $oid = $find($nm);
        $st = $pdo->prepare("SELECT id, office_id FROM dp_su_places WHERE code = ?"); $st->execute([$code]); $r = $st->fetch();
        if (!$r) { $pdo->prepare("INSERT INTO dp_su_places (code, name, office_id, sort_no, created_at) VALUES (?,?,?,?,?)")->execute([$code, $nm, $oid ?: null, $sort, bd_now()]); $pid = (int)$pdo->lastInsertId(); }
        else { $pid = (int)$r['id']; if (!$r['office_id'] && $oid) $pdo->prepare("UPDATE dp_su_places SET office_id = ? WHERE id = ?")->execute([$oid, $pid]); }
        dp_su_seed_items($pdo, $pid);
    }
    foreach ($offices as $o) {
        $st = $pdo->prepare("SELECT id FROM dp_su_places WHERE office_id = ?"); $st->execute([(int)$o['id']]); if ($st->fetchColumn()) continue;
        $code = dp_su_slug($o['name']); $st = $pdo->prepare("SELECT id FROM dp_su_places WHERE code = ?"); $st->execute([$code]); if ($st->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO dp_su_places (code, name, office_id, sort_no, created_at) VALUES (?,?,?,?,?)")->execute([$code, $o['name'], (int)$o['id'], 100 + (int)$o['id'], bd_now()]);
        dp_su_seed_items($pdo, (int)$pdo->lastInsertId());
    }
}

/* ============================== who is who ============================== */
function dp_su_buyer_code($placeCode) { return 'supplies.buyer.' . $placeCode; }
function dp_su_checker_code($placeCode) { return 'supplies.checker.' . $placeCode; }
/** employee ids that hold the buyer role for a place: its own seat first, else the shared buyer seat */
function dp_su_buyer_ids(PDO $pdo, array $place) {
    $own = dp_holders($pdo, dp_su_buyer_code($place['code'])); if ($own) return $own;
    return dp_holders($pdo, 'supplies.buyer');
}
function dp_su_checker_ids(PDO $pdo, array $place) {
    $o = dp_holders($pdo, dp_su_checker_code($place['code'])); if ($o) return $o;
    if (!empty($place['office_id'])) {
        try {
            $st = $pdo->prepare("SELECT section_id FROM of_offices WHERE id = ?"); $st->execute([(int)$place['office_id']]); $sid = (int)$st->fetchColumn();
            if ($sid) { $seat = bd_section_seat($pdo, $sid, 'checker'); $h = $seat ? bd_holders($pdo, $seat) : []; if ($h) return $h; }
        } catch (Throwable $e) {}
    }
    return dp_holders($pdo, 'supplies.checker');
}
/** the person who gets the work today: the first holder who is not away; else the Boss Engine fallback chain */
function dp_su_pick(PDO $pdo, array $ids, $date) {
    foreach ($ids as $i) { if (bd_away($pdo, $i, $date) === '') return (int)$i; }
    if ($ids) { list($fb) = bd_fallback($pdo, ['section_id' => (int)$pdo->query("SELECT id FROM bd_sections WHERE code = 'supplies'")->fetchColumn(), 'id' => 0], $date, $ids); return (int)$fb; }
    return 0;
}
/** what this login may do. mgr = Admin/HR/top boss/any section boss; buy/check = the places he can work on ('*' = all). */
function dp_su_acc(PDO $pdo, array $auth) {
    $w = bd_who($pdo, $auth); $emp = (int)($w['emp'] ?? 0);
    // "manager" here = Admin / HR / top boss / a boss or checker of the Supplies section. (An office checker or another section's boss is NOT a Supplies manager.)
    $supSec = (int)$pdo->query("SELECT id FROM bd_sections WHERE code = 'supplies'")->fetchColumn();
    $mgr = !empty($w['admin']) || !empty($w['top']) || ($supSec && in_array($supSec, (array)($w['sections'] ?? []), true));
    $buy = []; $chk = [];
    $all = $pdo->query("SELECT * FROM dp_su_places WHERE active = 1 ORDER BY sort_no, id")->fetchAll();
    $genBuyer = $emp && in_array($emp, dp_holders($pdo, 'supplies.buyer'), true);
    $genChecker = $emp && in_array($emp, dp_holders($pdo, 'supplies.checker'), true);
    foreach ($all as $p) {
        if ($mgr || $genBuyer || ($emp && in_array($emp, dp_holders($pdo, dp_su_buyer_code($p['code'])), true))) $buy[$p['code']] = 1;
        $isChk = $mgr || $genChecker || ($emp && in_array($emp, dp_holders($pdo, dp_su_checker_code($p['code'])), true));
        if (!$isChk && $emp && !empty($p['office_id'])) {
            try { $st = $pdo->prepare("SELECT section_id FROM of_offices WHERE id = ?"); $st->execute([(int)$p['office_id']]); $sid = (int)$st->fetchColumn(); $seat = $sid ? bd_section_seat($pdo, $sid, 'checker') : 0; if ($seat && in_array($emp, bd_holders($pdo, $seat), true)) $isChk = true; } catch (Throwable $e) {}
        }
        if ($isChk) $chk[$p['code']] = 1;
    }
    return ['w' => $w, 'emp' => $emp, 'mgr' => $mgr, 'admin' => !empty($w['admin']), 'buy' => $buy, 'chk' => $chk, 'places' => $all];
}
function dp_su_need_access(array $acc, $code, $what) {
    if ($acc['mgr']) return;
    if ($what === 'buy' && isset($acc['buy'][$code])) return;
    if ($what === 'check' && isset($acc['chk'][$code])) return;
    if ($what === 'view' && (isset($acc['buy'][$code]) || isset($acc['chk'][$code]))) return;
    json_response(['error' => 'Only the buyer or checker of this place (or a boss) can do this.'], 403);
}
function dp_su_need_live(PDO $pdo) {
    if (!dp_live($pdo, 'supplies')) json_response(['error' => 'Office supplies are not live yet. Answer Claude\'s questions in Boss > Questions first, then press "This is right, make it live".'], 409);
}

/* ============================== needs ============================== */
function dp_su_hours($reason) { return $reason === 'empty' ? max(1, (int)dp_get('su_empty_h', '4')) : max(1, (int)dp_get('su_low_h', '24')); }
/** "needed by": daily 10:00, cycle 12:00 (never less than 2 h from now); low / empty a few hours or a day away, moved out of the night */
function dp_su_due($reason, $now) {
    $d = date('Y-m-d', $now);
    if ($reason === 'daily') $t = strtotime($d . ' ' . dp_get('su_daily_by', '10:00'));
    elseif ($reason === 'cycle') $t = strtotime($d . ' 12:00');
    else $t = $now + 3600 * dp_su_hours($reason);
    if ($reason === 'daily' || $reason === 'cycle') { if ($t < $now + 7200) $t = $now + 7200; }
    $h = (int)date('G', $t);
    if ($h >= 20 || $h < 8) { $t = strtotime(date('Y-m-d', $h >= 20 ? $t + 86400 : $t) . ' 10:00'); }
    return date('Y-m-d H:i:s', $t);
}
function dp_su_rank($r) { return ['empty' => 4, 'low' => 3, 'min' => 3, 'missing' => 3, 'cycle' => 2, 'new' => 2, 'daily' => 1][$r] ?? 0; }   // min / missing / new = needs made by registered places (office_place.php)
/** open (or tighten) the one open need an item can have */
function dp_su_open_need(PDO $pdo, array $item, $reason, $now) {
    $st = $pdo->prepare("SELECT * FROM dp_su_needs WHERE item_id = ? AND status = 'open' ORDER BY id LIMIT 1"); $st->execute([(int)$item['id']]); $n = $st->fetch();
    $due = dp_su_due($reason, $now);
    if ($n) {
        $upd = [];
        if (dp_su_rank($reason) > dp_su_rank($n['reason'])) {
            // an ordinary daily / cycle need becomes a low / empty one; the key (item, date, reason) must stay unique
            $pdo->prepare("UPDATE IGNORE dp_su_needs SET reason = ?, needed_by = LEAST(needed_by, ?) WHERE id = ?")->execute([$reason, $due, $n['id']]);
        }
        return (int)$n['id'];
    }
    $pdo->prepare("INSERT IGNORE INTO dp_su_needs (item_id, place_id, need_date, reason, qty, needed_by, created_at) VALUES (?,?,?,?,?,?,?)")->execute([(int)$item['id'], (int)$item['place_id'], date('Y-m-d', $now), $reason, $item['qty'], $due, bd_now()]);
    $id = (int)$pdo->lastInsertId();
    if (!$id) { // the same item, date and reason was closed earlier today: open it again
        $pdo->prepare("UPDATE dp_su_needs SET status = 'open', needed_by = ?, penalised = 0, dp_item_id = NULL, bought_id = NULL, closed_at = NULL WHERE item_id = ? AND need_date = ? AND reason = ?")->execute([$due, (int)$item['id'], date('Y-m-d', $now), $reason]);
        $q = $pdo->prepare("SELECT id FROM dp_su_needs WHERE item_id = ? AND need_date = ? AND reason = ?"); $q->execute([(int)$item['id'], date('Y-m-d', $now), $reason]); $id = (int)$q->fetchColumn();
    }
    return $id;
}
function dp_su_close_needs(PDO $pdo, $itemId, $status, $boughtId = null) {
    $pdo->prepare("UPDATE dp_su_needs SET status = ?, bought_id = ?, closed_at = ? WHERE item_id = ? AND status = 'open'")->execute([$status, $boughtId, bd_now(), (int)$itemId]);
}
/** every morning: daily items, and items whose cycle has run out */
function dp_su_gen_needs(PDO $pdo, $now) {
    if ((int)date('G', $now) < (int)dp_get('su_start_hour', '6')) return 0;
    $n = 0; $today = date('Y-m-d', $now);
    $st = $pdo->query("SELECT i.*, p.name AS place_name FROM dp_su_items i JOIN dp_su_places p ON p.id = i.place_id WHERE i.status = 'active' AND p.active = 1 AND i.mode IN ('daily','cycle')");
    foreach ($st->fetchAll() as $it) {
        $o = $pdo->prepare("SELECT 1 FROM dp_su_needs WHERE item_id = ? AND status = 'open'"); $o->execute([(int)$it['id']]); if ($o->fetchColumn()) continue;
        if ($it['mode'] === 'daily') { $reason = 'daily'; }
        else {
            if (!$it['last_bought'] || !$it['cycle_days']) continue;
            if (strtotime($it['last_bought'] . ' +' . (int)$it['cycle_days'] . ' days') > strtotime($today)) continue;
            $reason = 'cycle';
        }
        $c = $pdo->prepare("SELECT 1 FROM dp_su_needs WHERE item_id = ? AND need_date = ? AND reason = ?"); $c->execute([(int)$it['id'], $today, $reason]); if ($c->fetchColumn()) continue;
        dp_su_open_need($pdo, $it, $reason, $now); $n++;
    }
    return $n;
}

/* ============================== My Day items ============================== */
function dp_su_buy_text(PDO $pdo, array $place, array $needs) {
    $parts = []; foreach ($needs as $n) { $it = dp_su_item($pdo, $n['item_id']); if ($it) $parts[] = mb_strtolower($it['name']) . ' ' . dp_su_q($n['qty']) . ' ' . $it['unit']; }
    return mb_substr('Buy for ' . $place['name'] . ': ' . implode(', ', $parts), 0, 200);
}
/** one open "Buy for <place>" item per place, holding all its open needs */
function dp_su_sync_items(PDO $pdo, $now) {
    $made = 0; $today = date('Y-m-d', $now);
    // yesterday's missed needs come back today (the penalty can fall again, per day)
    $pdo->prepare("UPDATE dp_su_needs n JOIN dp_items d ON d.id = n.dp_item_id SET n.penalised = 0, n.dp_item_id = NULL, n.reset_on = ?, n.needed_by = ?, n.assigned_due = NULL, n.seen_at = NULL WHERE n.status = 'open' AND d.status = 'missed' AND d.task_date < ?")
        ->execute([$today, dp_su_due('daily', $now), $today]);
    $places = $pdo->query("SELECT DISTINCT p.* FROM dp_su_places p JOIN dp_su_needs n ON n.place_id = p.id WHERE n.status = 'open' AND p.active = 1")->fetchAll();
    foreach ($places as $p) {
        $q = $pdo->prepare("SELECT n.*, d.status AS di_status FROM dp_su_needs n LEFT JOIN dp_items d ON d.id = n.dp_item_id WHERE n.place_id = ? AND n.status = 'open' AND n.assigned_emp IS NULL ORDER BY n.needed_by, n.id"); $q->execute([(int)$p['id']]); $needs = array_values(array_filter($q->fetchAll(), function ($n) { return $n['di_status'] !== 'missed'; }));   // needs of an item missed today wait for tomorrow; needs given to somebody else are on his own list
        if (!$needs) { dp_su_close_empty_place_items($pdo, $p); continue; }
        $due = $needs[0]['needed_by']; $buyer = dp_su_pick($pdo, dp_su_buyer_ids($pdo, $p), $today);
        $text = dp_su_buy_text($pdo, $p, $needs);
        $payload = ['place' => $p['code'], 'kind' => 'buy', 'need_ids' => array_map(function ($n) { return (int)$n['id']; }, $needs), 'page' => 'dept_supplies.html?place=' . $p['code']];
        $st = $pdo->prepare("SELECT id FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE ? AND status = 'open' ORDER BY id LIMIT 1"); $st->execute(['su:buy:' . $p['code'] . ':%']); $iid = (int)$st->fetchColumn();
        if ($iid) {
            $pdo->prepare("UPDATE dp_items SET title = ?, detail = ?, due_at = ?, payload = ?, qty = ?, owner_emp = COALESCE(?, owner_emp) WHERE id = ?")->execute([$text, 'Needed by ' . date('D g:i A', strtotime($due)), $due, bd_json($payload), count($needs), $buyer ?: null, $iid]);
        } else {
            $k = 'su:buy:' . $p['code'] . ':' . $today; $try = 1;
            while (true) { $c = $pdo->prepare("SELECT 1 FROM dp_items WHERE ref_key = ?"); $c->execute([$k]); if (!$c->fetchColumn()) break; $try++; $k = 'su:buy:' . $p['code'] . ':' . $today . ':' . $try; }
            $iid = dp_item_make($pdo, 'supplies', 'supply_missed', $k, 'supplies.buyer', $text, 'Needed by ' . date('D g:i A', strtotime($due)), $today, $due, $payload, $buyer, count($needs));
            $made++;
            if ($buyer && bd_mode() !== 'shadow') bd_push($pdo, $buyer, 'Supplies to buy: ' . $p['name'], mb_substr($text, 9));
        }
        $pdo->prepare("UPDATE dp_su_needs SET dp_item_id = ? WHERE place_id = ? AND status = 'open' AND assigned_emp IS NULL AND (dp_item_id IS NULL OR dp_item_id != ?)")->execute([$iid, (int)$p['id'], $iid]);
    }
    // a place whose needs were ALL given to somebody else (or bought) has nothing left on its own "Buy for" item
    foreach ($pdo->query("SELECT p.* FROM dp_su_places p WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM dp_su_needs n WHERE n.place_id = p.id AND n.status = 'open')")->fetchAll() as $p) dp_su_close_empty_place_items($pdo, $p);
    if (function_exists('dp_wk_sync_items')) $made += dp_wk_sync_items($pdo, $now);
    return $made;
}
/** the "Buy for <place>" item is done when no open need points to it any more */
function dp_su_close_empty_place_items(PDO $pdo, array $place) {
    $st = $pdo->prepare("SELECT id FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE ? AND status = 'open'"); $st->execute(['su:buy:' . $place['code'] . ':%']);
    foreach ($st->fetchAll() as $r) { $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs WHERE dp_item_id = ? AND status = 'open' AND assigned_emp IS NULL"); $q->execute([(int)$r['id']]); if ((int)$q->fetchColumn() === 0) dp_item_done($pdo, $r['id']); }
}
/** after a purchase: the item's list shrinks; when nothing is left it is done */
function dp_su_refresh_place(PDO $pdo, array $place, $now) {
    $st = $pdo->prepare("SELECT id FROM dp_items WHERE kind = 'supplies' AND ref_key LIKE ? AND status = 'open'"); $st->execute(['su:buy:' . $place['code'] . ':%']);
    foreach ($st->fetchAll() as $r) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs WHERE dp_item_id = ? AND status = 'open' AND assigned_emp IS NULL"); $q->execute([(int)$r['id']]);
        if ((int)$q->fetchColumn() === 0) dp_item_done($pdo, $r['id']);
    }
    dp_su_sync_items($pdo, $now);
}
/** the daily "mark Ok / Low / Empty" item for the checker */
function dp_su_sync_checks(PDO $pdo, $now) {
    if ((int)date('G', $now) < (int)dp_get('su_start_hour', '6')) return 0;
    $made = 0; $today = date('Y-m-d', $now); $by = dp_get('su_check_by', '12:00');
    foreach ($pdo->query("SELECT * FROM dp_su_places WHERE active = 1 ORDER BY sort_no, id")->fetchAll() as $p) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM dp_su_items WHERE place_id = ? AND status = 'active' AND mode != 'count'"); $c->execute([(int)$p['id']]); if (!(int)$c->fetchColumn()) continue;   // counted items belong to a place's own daily check, not to this Ok / Low / Empty tap
        $ids = dp_su_checker_ids($pdo, $p); if (!$ids) continue;
        $ref = 'su:check:' . $p['code'] . ':' . $today;
        $e = $pdo->prepare("SELECT id FROM dp_items WHERE ref_key = ?"); $e->execute([$ref]); if ($e->fetchColumn()) continue;
        $owner = dp_su_pick($pdo, $ids, $today); if (!$owner) continue;
        $iid = dp_item_make($pdo, 'supplies', '', $ref, 'supplies.checker', 'Mark Ok / Low / Empty for ' . $p['name'], 'Before ' . date('g:i A', strtotime($today . ' ' . $by)), $today, $today . ' ' . $by . ':00',
            ['place' => $p['code'], 'kind' => 'check', 'page' => 'dept_supplies.html?place=' . $p['code'] . '&tab=check'], $owner, 1);
        $made++;
        if ($owner && bd_mode() !== 'shadow') bd_push($pdo, $owner, 'Supply check: ' . $p['name'], 'Mark every item Ok, Low or Empty.');
    }
    return $made;
}
/** buying not done by the "needed by" time (plus the rule's grace) -> missed; the check not done -> missed, no penalty */
function dp_su_misses(PDO $pdo, $now) {
    $n = 0; $rule = dp_rule($pdo, 'supply_missed'); $grace = $rule ? max(0, (int)$rule['grace_min']) : 0;
    $st = $pdo->prepare("SELECT * FROM dp_items WHERE kind = 'supplies' AND status = 'open' AND due_at IS NOT NULL AND due_at <= ?"); $st->execute([date('Y-m-d H:i:s', $now - 60 * $grace)]);
    foreach ($st->fetchAll() as $it) {
        $p = bd_unjson($it['payload']);
        if (($p['kind'] ?? '') === 'buy') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM dp_su_needs WHERE dp_item_id = ? AND status = 'open'"); $q->execute([(int)$it['id']]); $open = (int)$q->fetchColumn();
            if (!$open) { dp_item_done($pdo, $it['id']); continue; }
            dp_miss($pdo, $it['id'], 'supply_missed', 1, 'not bought in time');
            $pdo->prepare("UPDATE dp_su_needs SET penalised = 1 WHERE dp_item_id = ? AND status = 'open'")->execute([(int)$it['id']]);
            $n++;
        } else { dp_miss($pdo, $it['id'], '', 1, 'supply check not done'); $n++; }
    }
    return $n;
}

/* ============================== learning how often each item must come ============================== */
function dp_su_nice($d) { $n = [1, 2, 3, 5, 7, 10, 15, 20, 30]; $best = 1; $bd = 9999; foreach ($n as $x) { $e = abs($x - $d) / max(1, $x); if ($e < $bd) { $bd = $e; $best = $x; } } return $best; }
/** from bought dates and the checker's marks: days from a refill to the first Low / Empty. Returns [spans[], checkdays] */
function dp_su_spans(PDO $pdo, $itemId) {
    $ev = [];
    $b = $pdo->prepare("SELECT DATE(bo.bought_at) d FROM dp_su_bought_items bi JOIN dp_su_bought bo ON bo.id = bi.bought_id WHERE bi.item_id = ?"); $b->execute([(int)$itemId]);
    foreach ($b->fetchAll() as $r) $ev[] = [$r['d'], 0, 'bought'];
    $c = $pdo->prepare("SELECT checked_on d, state FROM dp_su_checks WHERE item_id = ?"); $c->execute([(int)$itemId]);
    $days = 0; foreach ($c->fetchAll() as $r) { $ev[] = [$r['d'], 1, $r['state']]; $days++; }
    usort($ev, function ($a, $b) { return strcmp($a[0], $b[0]) ?: ($a[1] <=> $b[1]); });
    $refill = null; $prev = ''; $spans = [];
    foreach ($ev as $e) {
        if ($e[2] === 'bought') { $refill = $e[0]; continue; }
        if ($e[2] === 'ok') { if (in_array($prev, ['low', 'empty'], true) && $refill === null) $refill = $e[0]; $prev = 'ok'; continue; }
        // low / empty
        if ($refill !== null && $e[0] > $refill) {
            $span = (int)round((strtotime($e[0]) - strtotime($refill)) / 86400);
            $spans[] = ['days' => $e[2] === 'low' ? $span / 0.75 : $span, 'raw' => $span, 'state' => $e[2], 'from' => $refill, 'to' => $e[0]];
            $refill = null;
        }
        $prev = $e[2];
    }
    return [$spans, $days];
}
/** Claude's proposals. Returns how many new ones. */
function dp_su_learn(PDO $pdo) {
    $new = 0;
    $st = $pdo->query("SELECT i.*, p.name AS place_name FROM dp_su_items i JOIN dp_su_places p ON p.id = i.place_id WHERE i.status = 'active' AND i.mode IN ('learn','cycle')");
    foreach ($st->fetchAll() as $it) {
        list($spans, $days) = dp_su_spans($pdo, $it['id']);
        if (!$spans) continue;
        if (count($spans) < 2 && $days < 3) continue;
        $avg = array_sum(array_map(function ($s) { return $s['days']; }, $spans)) / count($spans);
        $cyc = dp_su_nice($avg);
        if ($it['mode'] === 'cycle' && (int)$it['cycle_days'] > 0 && abs($cyc - (int)$it['cycle_days']) / max(1, (int)$it['cycle_days']) < 0.3) continue;
        $o = $pdo->prepare("SELECT id FROM dp_su_cycles WHERE item_id = ? AND status = 'proposed'"); $o->execute([(int)$it['id']]); $ex = $o->fetchColumn();
        $basis = count($spans) . ' refill' . (count($spans) === 1 ? '' : 's') . ' watched: it ran ' . implode(', ', array_map(function ($s) { return $s['raw'] . ' day' . ($s['raw'] === 1 ? '' : 's') . ' until ' . $s['state']; }, array_slice($spans, -4))) . '.';
        if ($ex) { $pdo->prepare("UPDATE dp_su_cycles SET cycle_days = ?, spans = ?, basis = ? WHERE id = ?")->execute([$cyc, count($spans), mb_substr($basis, 0, 250), $ex]); continue; }
        $r = $pdo->prepare("SELECT 1 FROM dp_su_cycles WHERE item_id = ? AND status = 'rejected' AND cycle_days = ? AND created_at > ?"); $r->execute([(int)$it['id'], $cyc, date('Y-m-d H:i:s', strtotime('-30 days'))]); if ($r->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO dp_su_cycles (item_id, cycle_days, spans, basis, created_at) VALUES (?,?,?,?,?)")->execute([(int)$it['id'], $cyc, count($spans), mb_substr($basis, 0, 250), bd_now()]);
        $cid = (int)$pdo->lastInsertId(); $new++;
        bd_inbox('dp_note', $it['name'] . ' at ' . $it['place_name'] . ': bring it every ' . $cyc . ' day' . ($cyc === 1 ? '' : 's') . '?', $basis . ' Confirm it in Departments > Office supplies > Learned cycles. Nothing changes until you confirm.', ['cycle_id' => $cid], null, null, 'su_cyc:' . $cid);
    }
    return $new;
}

/* ============================== money moves (only when the Money module is there) ============================== */
function dp_su_money_out(PDO $pdo, $amount, $who, $note, $file, $ref) {
    if (!function_exists('dp_money_post')) return;
    try { dp_money_post($pdo, 'out', (float)$amount, (string)$who, (string)$note . ($file ? ' (screenshot kept in Supplies)' : ''), ['method' => strpos((string)$note, '(cash)') !== false ? 'cash' : 'upi', 'ref_key' => (string)$ref, 'kind' => 'vendor']); } catch (Throwable $e) { error_log('Supplies -> Money: ' . $e->getMessage()); }
}
function dp_su_balance(PDO $pdo, $supplierId) {
    $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind = 'bill' THEN amount ELSE -amount END),0) FROM dp_su_ledger WHERE supplier_id = ?"); $q->execute([(int)$supplierId]); return (float)$q->fetchColumn();
}
/** a payment closes the oldest bills first */
function dp_su_settle(PDO $pdo, $supplierId, $amount) {
    $left = (float)$amount;
    $q = $pdo->prepare("SELECT * FROM dp_su_ledger WHERE supplier_id = ? AND kind = 'bill' AND status = 'pending' ORDER BY on_date, id"); $q->execute([(int)$supplierId]);
    foreach ($q->fetchAll() as $b) {
        if ($left <= 0) break;
        $need = (float)$b['amount'] - (float)$b['settled']; $use = min($need, $left); $left -= $use;
        $full = ((float)$b['settled'] + $use) >= (float)$b['amount'] - 0.001;
        $pdo->prepare("UPDATE dp_su_ledger SET settled = settled + ?, status = ? WHERE id = ?")->execute([$use, $full ? 'paid' : 'pending', $b['id']]);
    }
}

/* ============================== proposals from the office walk-through ============================== */
function dp_su_keywords() {
    return ['hand wash' => ['Hand wash', 'bottle'], 'handwash' => ['Hand wash', 'bottle'], 'soap' => ['Hand wash', 'bottle'], 'toilet' => ['Toilet cleaner', 'bottle'], 'phenyl' => ['Toilet cleaner', 'bottle'],
        'tissue' => ['Tissue paper', 'pack'], 'dustbin' => ['Garbage bags', 'pack'], 'garbage' => ['Garbage bags', 'pack'], 'bin liner' => ['Garbage bags', 'pack'], 'sanitizer' => ['Sanitizer', 'bottle'], 'sanitiser' => ['Sanitizer', 'bottle'],
        'water' => ['Drinking water', 'can'], 'dispenser' => ['Drinking water', 'can'], 'coffee' => ['Coffee', 'pack'], 'tea' => ['Tea', 'pack'], 'sugar' => ['Sugar', 'kg'], 'milk' => ['Milk', 'L'], 'floor' => ['Floor cleaner', 'bottle'], 'glass' => ['Glass cleaner', 'bottle'], 'mop' => ['Floor cleaner', 'bottle'], 'freshener' => ['Air freshener', 'pc']];
}
/** read the zones and checkpoints Claude drew from the office video (Offices) and propose the supplies they point to */
function dp_su_propose(PDO $pdo, array $place) {
    if (empty($place['office_id'])) return [0, 'This place is not an office in Offices, so there is no walk-through to read.'];
    $text = '';
    try {
        $z = $pdo->prepare("SELECT z.name, GROUP_CONCAT(p.text SEPARATOR ' ; ') pts FROM of_zones z LEFT JOIN of_points p ON p.zone_id = z.id WHERE z.office_id = ? GROUP BY z.id"); $z->execute([(int)$place['office_id']]);
        foreach ($z->fetchAll() as $r) $text .= ' ' . strtolower($r['name'] . ' ' . $r['pts']);
    } catch (Throwable $e) { return [0, 'Offices has no zones for this place yet.']; }
    if (trim($text) === '') return [0, 'Offices has no zones for this place yet. Show Claude the office video there first.'];
    $found = [];
    foreach (dp_su_keywords() as $kw => $it) { if (strpos($text, $kw) !== false) $found[$it[0]] = $it; }
    $made = 0;
    foreach ($found as $it) {
        $s = $pdo->prepare("INSERT IGNORE INTO dp_su_items (place_id, name, unit, mode, qty, status, source, created_at) VALUES (?,?,?,?,1, 'proposed', 'video', ?)");
        $s->execute([(int)$place['id'], $it[0], $it[1], 'learn', bd_now()]); $made += $s->rowCount();
    }
    return [$made, $made ? $made . ' item' . ($made === 1 ? '' : 's') . ' proposed from the office video. Accept the ones you want.' : 'Nothing new: everything the video points to is already on the list.'];
}

/* ============================== views ============================== */
function dp_su_item_view(array $i, $buyBy = null) {
    return ['id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'mode' => $i['mode'], 'cycle_days' => $i['cycle_days'] ? (int)$i['cycle_days'] : null, 'qty' => (float)$i['qty'], 'supplier_id' => $i['supplier_id'] ? (int)$i['supplier_id'] : null,
        'state' => $i['state'], 'last_bought' => $i['last_bought'], 'status' => $i['status'], 'source' => $i['source'],
        // registered places (office_place.php): counted against a minimum, kept apart from the Ok / Low / Empty tap list
        'zone_id' => isset($i['zone_id']) && $i['zone_id'] ? (int)$i['zone_id'] : null, 'kind' => (string)($i['kind'] ?? 'consumable'), 'group' => (string)($i['pgroup'] ?? ''), 'min_qty' => isset($i['min_qty']) && $i['min_qty'] !== null ? (float)$i['min_qty'] : null,
        'fill_to' => isset($i['fill_to']) && $i['fill_to'] !== null ? (float)$i['fill_to'] : null, 'refill' => (string)($i['refill'] ?? 'none'), 'name_hi' => (string)($i['name_hi'] ?? ''), 'last_count' => isset($i['last_count']) && $i['last_count'] !== null ? (float)$i['last_count'] : null, 'equip_state' => $i['equip_state'] ?? null];
}
function dp_su_place_card(PDO $pdo, array $p) {
    $q = $pdo->prepare("SELECT COUNT(*) c, SUM(state = 'low') lo, SUM(state = 'empty') em FROM dp_su_items WHERE place_id = ? AND status = 'active' AND mode != 'count'"); $q->execute([(int)$p['id']]); $c = $q->fetch();
    $n = $pdo->prepare("SELECT COUNT(*) c, MIN(needed_by) first FROM dp_su_needs WHERE place_id = ? AND status = 'open'"); $n->execute([(int)$p['id']]); $nn = $n->fetch();
    $k = $pdo->prepare("SELECT COUNT(DISTINCT item_id) FROM dp_su_checks WHERE place_id = ? AND checked_on = ?"); $k->execute([(int)$p['id'], bd_today()]); $done = (int)$k->fetchColumn();
    return ['code' => $p['code'], 'name' => $p['name'], 'office_id' => $p['office_id'] ? (int)$p['office_id'] : null, 'items' => (int)$c['c'], 'low' => (int)$c['lo'], 'empty' => (int)$c['em'], 'open_needs' => (int)$nn['c'], 'first_due' => $nn['first'],
        'checked_today' => (int)$c['c'] > 0 && $done >= (int)$c['c'], 'checked_n' => $done, 'buyer' => dp_su_names($pdo, dp_su_buyer_ids($pdo, $p)), 'checker' => dp_su_names($pdo, dp_su_checker_ids($pdo, $p))];
}
function dp_su_need_view(PDO $pdo, array $n) {
    $it = dp_su_item($pdo, $n['item_id']);
    return ['id' => (int)$n['id'], 'item_id' => (int)$n['item_id'], 'item' => $it ? $it['name'] : '?', 'unit' => $it ? $it['unit'] : '', 'qty' => (float)$n['qty'], 'reason' => $n['reason'], 'needed_by' => $n['needed_by'], 'needed_label' => date('D g:i A', strtotime($n['needed_by'])), 'late' => strtotime($n['needed_by']) < time(), 'supplier_id' => $it && $it['supplier_id'] ? (int)$it['supplier_id'] : null];
}
function dp_su_totals(PDO $pdo) {
    $owed = 0.0; foreach ($pdo->query("SELECT id FROM dp_su_suppliers")->fetchAll() as $s) $owed += max(0, dp_su_balance($pdo, $s['id']));
    return ['owed' => $owed, 'reimb' => (int)$pdo->query("SELECT COUNT(*) FROM dp_su_bought WHERE reimb_status = 'pending'")->fetchColumn(), 'reimb_amount' => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dp_su_bought WHERE reimb_status = 'pending'")->fetchColumn(),
        'cycles' => (int)$pdo->query("SELECT COUNT(*) FROM dp_su_cycles WHERE status = 'proposed'")->fetchColumn(), 'needs' => (int)$pdo->query("SELECT COUNT(*) FROM dp_su_needs WHERE status = 'open'")->fetchColumn()];
}
function dp_su_upload($field, $prefix, $required, $why) {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? 4) === 4 || empty($_FILES[$field]['tmp_name'])) {
        if ($required) json_response(['error' => $why], 422);
        return null;
    }
    if ((int)$_FILES[$field]['error'] !== 0) json_response(['error' => 'The picture could not be uploaded. Try again.'], 422);
    list($name, $err) = dp_save_image($_FILES[$field]['tmp_name'], $prefix);
    if (!$name) json_response(['error' => $err], 422);
    return $name;
}

/** records ONE purchase for ONE place (shared by dp.su_bought and the Shopping list's dp.wk_bought). $rows = [[item row, qty], ...]. Returns [message, warning, bought id]. */
function dp_su_record_purchase(PDO $pdo, $emp, array $p, $paid, $amount, array $rows, $sup, $shot, $note, $by, $now, array $x) {
    $pdo->prepare("INSERT INTO dp_su_bought (place_id, buyer_emp, paid_by, supplier_id, amount, note, shot, bought_at, reimb_status, bill_shot, checked_note, group_ref) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$p['id'], $emp ?: null, $paid, $sup ? (int)$sup['id'] : null, $amount, $note ?: null, $shot, bd_now(), $paid === 'pocket' ? 'pending' : 'none', isset($x['bill']) ? $x['bill'] : null, isset($x['checked_note']) && $x['checked_note'] !== '' ? $x['checked_note'] : null, isset($x['group_ref']) ? $x['group_ref'] : null]);
    $bid = (int)$pdo->lastInsertId(); $today = bd_today();
    foreach ($rows as $r) {
        list($it, $q) = $r;
        $pdo->prepare("INSERT INTO dp_su_bought_items (bought_id, item_id, qty) VALUES (?,?,?)")->execute([$bid, (int)$it['id'], $q]);
        $pdo->prepare("UPDATE dp_su_items SET last_bought = ?, state = 'ok', state_at = ? WHERE id = ?")->execute([$today, bd_now(), (int)$it['id']]);
        dp_su_close_needs($pdo, $it['id'], 'bought', $bid);
    }
    $msg = 'Saved. ' . count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' marked as bought.'; $warn = '';
    if ($paid === 'credit') { $pdo->prepare("INSERT INTO dp_su_ledger (supplier_id, kind, amount, bought_id, note, shot, on_date, created_by, created_at) VALUES (?, 'bill', ?, ?, ?, ?, ?, ?, ?)")->execute([(int)$sup['id'], $amount, $bid, $note ?: ('Bought for ' . $p['name']), $shot, $today, $by, bd_now()]); $msg .= ' A bill of ' . dp_inr($amount) . ' was added to ' . $sup['name'] . '\'s ledger.'; }
    elseif ($paid === 'pocket') { $msg .= ' It is in Reimbursements: the main admin will pay you back by UPI.'; bd_inbox('dp_note', bd_emp_name($pdo, $emp) . ' paid ' . dp_inr($amount) . ' from his pocket for ' . $p['name'], 'Pay it back by UPI in Departments > Office supplies > Reimbursements and attach the transaction screenshot.', ['bought_id' => $bid], null, null, 'su_reimb:' . $bid); }
    elseif ($paid === 'upi') { dp_su_money_out($pdo, $amount, $sup ? $sup['name'] : 'Supplies - ' . $p['name'], 'Supplies for ' . $p['name'], $shot, 'su:' . $bid); }
    elseif ($paid === 'cash') { $warn = 'Please avoid cash; pay by UPI or ledger.'; $msg .= ' ' . $warn; dp_su_money_out($pdo, $amount, $sup ? $sup['name'] : 'Supplies - ' . $p['name'], 'Supplies for ' . $p['name'] . ' (cash)', null, 'su:' . $bid); bd_inbox('dp_note', 'Cash used for supplies: ' . $p['name'], bd_emp_name($pdo, $emp) . ' paid ' . dp_inr($amount) . ' in cash. Ask for UPI or ledger next time.', ['bought_id' => $bid], null, null, 'su_cash:' . $bid); }
    dp_su_refresh_place($pdo, $p, $now);
    bd_log('supplies', $p['code'], 'bought ' . dp_inr($amount) . ' (' . $paid . ')', $by);
    return [$msg, $warn, $bid];
}

/* ============================== tick ============================== */
function dp_supplies_tick(PDO $pdo, $now) {
    dp_su_ensure($pdo);
    if (function_exists('ofp_tick')) { try { ofp_tick($pdo, $now); } catch (Throwable $e) { error_log('Places tick: ' . $e->getMessage()); } }   // registered places keep their Boss Engine duties in step (office_place.php)
    if (!dp_live($pdo, 'supplies')) return ['live' => false];
    $o = ['live' => true];
    $o['needs'] = dp_su_gen_needs($pdo, $now);
    $o['items'] = dp_su_sync_items($pdo, $now);
    $o['checks'] = dp_su_sync_checks($pdo, $now);
    $o['missed'] = dp_su_misses($pdo, $now);
    if (function_exists('dp_wk_repeat')) $o['reminded'] = dp_wk_repeat($pdo, $now);
    $day = date('Y-m-d', $now);
    if (dp_get('su_learn_day', '') !== $day) { $o['learned'] = dp_su_learn($pdo); dp_put('su_learn_day', $day); }
    return $o;
}

/* ============================== endpoints ============================== */
try {
if (strpos((string)$action, 'dp.su_') === 0) {
    dp_ensure($pdo); dp_su_ensure($pdo);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $auth = require_auth();
    $acc = dp_su_acc($pdo, $auth);
    $by = (string)($auth['username'] ?? 'user');
    $in = $isPost ? json_input() : [];
    if (!is_array($in)) $in = [];
    $now = time();
    $needMgr = function () use ($acc) { if (!$acc['mgr']) json_response(['error' => 'Only Admin, HR or a section boss can do this.'], 403); };
    $anyAccess = function () use ($acc) { if (!$acc['mgr'] && !$acc['buy'] && !$acc['chk']) json_response(['error' => 'Office supplies are for the buyer, the checker and the bosses.'], 403); };
    $getPlace = function ($code) use ($pdo) { $p = dp_su_place($pdo, (string)$code); if (!$p) json_response(['error' => 'Unknown place.'], 404); return $p; };

    switch ($action) {

    case 'dp.su_overview': {
        $anyAccess();
        $o = [];
        foreach ($acc['places'] as $p) { if (!($acc['mgr'] || isset($acc['buy'][$p['code']]) || isset($acc['chk'][$p['code']]))) continue; $c = dp_su_place_card($pdo, $p); $c['can_buy'] = $acc['mgr'] || isset($acc['buy'][$p['code']]); $c['can_check'] = $acc['mgr'] || isset($acc['chk'][$p['code']]); $o[] = $c; }
        $r = dp_rule($pdo, 'supply_missed');
        json_response(['places' => $o, 'totals' => $acc['mgr'] ? dp_su_totals($pdo) : null, 'live' => dp_live($pdo, 'supplies'), 'topic_state' => dp_topic_state($pdo, 'supplies'), 'mode' => bd_mode(),
            'rule' => $r ? ['amount' => (float)$r['amount'], 'decide' => (int)$r['decide'], 'grace_min' => (int)$r['grace_min'], 'mode' => $r['mode'], 'active' => (int)$r['active']] : null,
            'access' => ['manager' => $acc['mgr'], 'admin' => $acc['admin'], 'buyer' => $acc['mgr'] || !empty($acc['buy']), 'checker' => $acc['mgr'] || !empty($acc['chk'])],
            'settings' => $acc['mgr'] ? ['daily_by' => dp_get('su_daily_by', '10:00'), 'check_by' => dp_get('su_check_by', '12:00'), 'low_h' => (int)dp_get('su_low_h', '24'), 'empty_h' => (int)dp_get('su_empty_h', '4')] : null]);
    }

    case 'dp.su_place': {
        $p = $getPlace($_GET['code'] ?? ''); dp_su_need_access($acc, $p['code'], 'view');
        $st = $pdo->prepare("SELECT * FROM dp_su_items WHERE place_id = ? AND status IN ('active','proposed') ORDER BY status, name"); $st->execute([(int)$p['id']]);
        $items = array_map('dp_su_item_view', $st->fetchAll());
        $ns = $pdo->prepare("SELECT * FROM dp_su_needs WHERE place_id = ? AND status = 'open' ORDER BY needed_by, id"); $ns->execute([(int)$p['id']]);
        $needs = array_map(function ($n) use ($pdo) { return dp_su_need_view($pdo, $n); }, $ns->fetchAll());
        $today = []; $ck = $pdo->prepare("SELECT item_id, state FROM dp_su_checks WHERE place_id = ? AND checked_on = ?"); $ck->execute([(int)$p['id'], bd_today()]); foreach ($ck->fetchAll() as $r) $today[(int)$r['item_id']] = $r['state'];
        $rb = $pdo->prepare("SELECT b.*, s.name AS supplier FROM dp_su_bought b LEFT JOIN dp_su_suppliers s ON s.id = b.supplier_id WHERE b.place_id = ? ORDER BY b.id DESC LIMIT 8"); $rb->execute([(int)$p['id']]);
        $recent = [];
        foreach ($rb->fetchAll() as $b) {
            $li = $pdo->prepare("SELECT i.name, bi.qty, i.unit FROM dp_su_bought_items bi JOIN dp_su_items i ON i.id = bi.item_id WHERE bi.bought_id = ?"); $li->execute([(int)$b['id']]);
            $recent[] = ['id' => (int)$b['id'], 'at' => $b['bought_at'], 'by' => bd_emp_name($pdo, $b['buyer_emp']), 'paid_by' => $b['paid_by'], 'amount' => (float)$b['amount'], 'supplier' => $b['supplier'], 'note' => $b['note'], 'has_shot' => !empty($b['shot']), 'shot' => $b['shot'], 'reimb' => $b['reimb_status'],
                'items' => array_map(function ($r) { return $r['name'] . ' ' . dp_su_q($r['qty']) . ' ' . $r['unit']; }, $li->fetchAll())];
        }
        $sup = array_map(function ($s) use ($pdo) { return ['id' => (int)$s['id'], 'name' => $s['name'], 'balance' => dp_su_balance($pdo, $s['id'])]; }, $pdo->query("SELECT * FROM dp_su_suppliers WHERE active = 1 ORDER BY name")->fetchAll());
        $out = ['place' => dp_su_place_card($pdo, $p), 'items' => $items, 'needs' => $needs, 'today' => $today, 'recent' => $recent, 'suppliers' => $sup, 'live' => dp_live($pdo, 'supplies'),
            'can' => ['buy' => $acc['mgr'] || isset($acc['buy'][$p['code']]), 'check' => $acc['mgr'] || isset($acc['chk'][$p['code']]), 'edit' => $acc['mgr'], 'admin' => $acc['admin']]];
        if ($acc['mgr']) {
            $out['buyer_ids'] = dp_holders($pdo, dp_su_buyer_code($p['code'])); $out['checker_ids'] = dp_holders($pdo, dp_su_checker_code($p['code']));
            $out['shared_buyer'] = dp_su_names($pdo, dp_holders($pdo, 'supplies.buyer'));
            $out['suggest'] = array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'line' => $c['line']]; }, dp_suggest($pdo, 'supplies.buyer', 3));
            $out['employees'] = bd_employees($pdo);
        }
        json_response($out);
    }

    case 'dp.su_check': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $p = $getPlace($in['code'] ?? ''); dp_su_need_access($acc, $p['code'], 'check'); dp_su_need_live($pdo);
        $marks = (array)($in['marks'] ?? []); if (!$marks) json_response(['error' => 'Mark at least one item.'], 422);
        $day = bd_today(); $cnt = ['ok' => 0, 'low' => 0, 'empty' => 0]; $needsMade = 0;
        foreach ($marks as $m) {
            $it = dp_su_item($pdo, (int)($m['item_id'] ?? 0)); $s = (string)($m['state'] ?? '');
            if (!$it || (int)$it['place_id'] !== (int)$p['id'] || $it['status'] !== 'active') json_response(['error' => 'One of the items does not belong to ' . $p['name'] . '.'], 422);
            if (!in_array($s, ['ok', 'low', 'empty'], true)) json_response(['error' => 'Each item must be Ok, Low or Empty.'], 422);
            $pdo->prepare("INSERT INTO dp_su_checks (item_id, place_id, state, emp_id, checked_on, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state = VALUES(state), emp_id = VALUES(emp_id), created_at = VALUES(created_at)")->execute([(int)$it['id'], (int)$p['id'], $s, $acc['emp'] ?: null, $day, bd_now()]);
            $pdo->prepare("UPDATE dp_su_items SET state = ?, state_at = ? WHERE id = ?")->execute([$s, bd_now(), (int)$it['id']]);
            $cnt[$s]++;
            if ($s === 'ok') { $pdo->prepare("UPDATE dp_su_needs SET status = 'cancelled', closed_at = ? WHERE item_id = ? AND status = 'open' AND reason IN ('low','empty')")->execute([bd_now(), (int)$it['id']]); }
            else { $before = (int)$pdo->query("SELECT COUNT(*) FROM dp_su_needs WHERE item_id = " . (int)$it['id'] . " AND status = 'open'")->fetchColumn(); dp_su_open_need($pdo, $it, $s, $now); if (!$before) $needsMade++; }
        }
        // the daily check item is done once every active item of this place has today's mark
        $tot = (int)$pdo->query("SELECT COUNT(*) FROM dp_su_items WHERE status = 'active' AND mode != 'count' AND place_id = " . (int)$p['id'])->fetchColumn();
        $got = $pdo->prepare("SELECT COUNT(*) FROM dp_su_checks c JOIN dp_su_items i ON i.id = c.item_id WHERE c.place_id = ? AND c.checked_on = ? AND i.status = 'active' AND i.mode != 'count'"); $got->execute([(int)$p['id'], $day]); $done = (int)$got->fetchColumn();
        if ($done >= $tot) { $st = $pdo->prepare("SELECT id FROM dp_items WHERE kind = 'supplies' AND ref_key = ? AND status = 'open'"); $st->execute(['su:check:' . $p['code'] . ':' . $day]); foreach ($st->fetchAll() as $r) dp_item_done($pdo, $r['id']); }
        dp_su_sync_items($pdo, $now);
        bd_log('supplies', $p['code'], 'checked: ' . $cnt['ok'] . ' ok, ' . $cnt['low'] . ' low, ' . $cnt['empty'] . ' empty', $by);
        json_response(['ok' => true, 'message' => 'Saved. ' . ($cnt['low'] + $cnt['empty'] ? ($cnt['low'] + $cnt['empty']) . ' item' . (($cnt['low'] + $cnt['empty']) === 1 ? ' goes' : 's go') . ' to "Need to buy".' : 'Nothing to buy.') . ($done < $tot ? ' ' . ($tot - $done) . ' item(s) still to mark.' : ''), 'counts' => $cnt, 'remaining' => max(0, $tot - $done)]);
    }

    case 'dp.su_bought': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        $p = $getPlace($_POST['code'] ?? ''); dp_su_need_access($acc, $p['code'], 'buy'); dp_su_need_live($pdo);
        $paid = (string)($_POST['paid_by'] ?? ''); if (!in_array($paid, ['upi', 'credit', 'pocket', 'cash'], true)) json_response(['error' => 'Choose how it was paid: Company UPI, Supplier ledger (credit), Own pocket or Cash.'], 422);
        $amount = round((float)($_POST['amount'] ?? 0), 2); if ($amount <= 0 || $amount > 1000000) json_response(['error' => 'Enter the amount paid (more than 0).'], 422);
        $lines = json_decode((string)($_POST['items'] ?? '[]'), true); if (!is_array($lines) || !$lines) json_response(['error' => 'Choose at least one item you bought.'], 422);
        $sid = (int)($_POST['supplier_id'] ?? 0); $sup = $sid ? dp_su_supplier($pdo, $sid) : null;
        if ($sid && !$sup) json_response(['error' => 'Unknown supplier.'], 422);
        if ($paid === 'credit' && !$sup) json_response(['error' => 'Supplier ledger needs the supplier. Add the supplier first (Suppliers tab).'], 422);
        $rows = [];
        foreach ($lines as $l) {
            $it = dp_su_item($pdo, (int)($l['item_id'] ?? 0)); $q = (float)($l['qty'] ?? 0);
            if (!$it || (int)$it['place_id'] !== (int)$p['id']) json_response(['error' => 'One of the items is not on the list of ' . $p['name'] . '.'], 422);
            if ($q <= 0 || $q > 100000) json_response(['error' => 'Enter a quantity for ' . $it['name'] . '.'], 422);
            $rows[] = [$it, $q];
        }
        $shot = dp_su_upload('shot', 'su', $paid === 'upi', 'Company UPI payments need the payment screenshot. Take or choose it first.');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 240);
        list($msg, $warn, $bid) = dp_su_record_purchase($pdo, (int)$acc['emp'], $p, $paid, $amount, $rows, $sup, $shot, $note, $by, $now, []);
        json_response(['ok' => true, 'message' => $msg, 'warning' => $warn, 'id' => $bid]);
    }

    case 'dp.su_item_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $p = $getPlace($in['place_code'] ?? ''); $id = (int)($in['id'] ?? 0);
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100); $unit = mb_substr(trim((string)($in['unit'] ?? 'pc')), 0, 20) ?: 'pc';
        $mode = (string)($in['mode'] ?? 'learn'); if (!in_array($mode, ['daily', 'cycle', 'learn', 'count'], true)) json_response(['error' => 'Mode must be daily, cycle, learn or count.'], 422);
        $cyc = (int)($in['cycle_days'] ?? 0); if ($mode === 'cycle' && ($cyc < 2 || $cyc > 365)) json_response(['error' => 'A cycle must be 2 to 365 days.'], 422);
        $qty = (float)($in['qty'] ?? 1); if ($qty <= 0 || $qty > 100000) json_response(['error' => 'Quantity must be more than 0.'], 422);
        $sup = (int)($in['supplier_id'] ?? 0); if ($sup && !dp_su_supplier($pdo, $sup)) json_response(['error' => 'Unknown supplier.'], 422);
        $status = (string)($in['status'] ?? 'active'); if (!in_array($status, ['active', 'proposed', 'off'], true)) $status = 'active';
        if ($id) {
            $it = dp_su_item($pdo, $id); if (!$it || (int)$it['place_id'] !== (int)$p['id']) json_response(['error' => 'Item not found.'], 404);
            if ($name === '') $name = $it['name'];
            try { $pdo->prepare("UPDATE dp_su_items SET name = ?, unit = ?, mode = ?, cycle_days = ?, qty = ?, supplier_id = ?, status = ? WHERE id = ?")->execute([$name, $unit, $mode, $mode === 'cycle' ? $cyc : null, $qty, $sup ?: null, $status, $id]); }
            catch (PDOException $e) { json_response(['error' => 'This place already has an item with that name.'], 409); }
            if ($status === 'off') dp_su_close_needs($pdo, $id, 'cancelled');
        } else {
            if ($name === '') json_response(['error' => 'Give the item a name.'], 422);
            try { $pdo->prepare("INSERT INTO dp_su_items (place_id, name, unit, mode, cycle_days, qty, supplier_id, status, source, created_at) VALUES (?,?,?,?,?,?,?,?, 'manual', ?)")->execute([(int)$p['id'], $name, $unit, $mode, $mode === 'cycle' ? $cyc : null, $qty, $sup ?: null, $status, bd_now()]); $id = (int)$pdo->lastInsertId(); }
            catch (PDOException $e) { json_response(['error' => 'This place already has an item with that name.'], 409); }
        }
        json_response(['ok' => true, 'id' => $id]);
    }

    case 'dp.su_place_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120); $id = (int)($in['id'] ?? 0);
        if ($id) {
            $p = dp_su_place($pdo, $id); if (!$p) json_response(['error' => 'Unknown place.'], 404);
            $pdo->prepare("UPDATE dp_su_places SET name = ?, active = ? WHERE id = ?")->execute([$name !== '' ? $name : $p['name'], isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : (int)$p['active'], $id]);
        } else {
            if ($name === '') json_response(['error' => 'Give the place a name.'], 422);
            $code = dp_su_slug($name); if (dp_su_place($pdo, $code)) json_response(['error' => 'A place with that name already exists.'], 409);
            $pdo->prepare("INSERT INTO dp_su_places (code, name, sort_no, created_at) VALUES (?,?,?,?)")->execute([$code, $name, 200 + (int)$pdo->query("SELECT COUNT(*) FROM dp_su_places")->fetchColumn(), bd_now()]); $id = (int)$pdo->lastInsertId();
            dp_su_seed_items($pdo, $id);
        }
        json_response(['ok' => true, 'id' => $id]);
    }

    case 'dp.su_seat_set': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $p = $getPlace($in['place_code'] ?? ''); $role = (string)($in['role'] ?? ''); if (!in_array($role, ['buyer', 'checker'], true)) json_response(['error' => 'Role must be buyer or checker.'], 422);
        $emps = array_values(array_filter(array_map('intval', (array)($in['emp_ids'] ?? []))));
        foreach ($emps as $e) { if (!bd_emp_active(bd_emp($pdo, $e))) json_response(['error' => 'One of those people is not active.'], 422); }
        $code = $role === 'buyer' ? dp_su_buyer_code($p['code']) : dp_su_checker_code($p['code']);
        $sid = dp_seat_ensure($pdo, $code, ($role === 'buyer' ? 'Supplies buyer: ' : 'Supplies checker: ') . $p['name'], 'supplies');
        bd_set_holders($pdo, $sid, $emps); bd_log('seat', $code, 'holders: ' . implode(',', $emps), $by);
        foreach ($emps as $e) bd_push($pdo, $e, 'You now hold a seat', ($role === 'buyer' ? 'Supplies buyer: ' : 'Supplies checker: ') . $p['name'] . '. Your tasks are in My Day.');
        if ($emps && $role === 'buyer') $pdo->prepare("UPDATE dp_items SET owner_emp = ? WHERE kind = 'supplies' AND status = 'open' AND ref_key LIKE ?")->execute([$emps[0], 'su:buy:' . $p['code'] . ':%']);
        if ($emps && $role === 'checker') $pdo->prepare("UPDATE dp_items SET owner_emp = ? WHERE kind = 'supplies' AND status = 'open' AND ref_key LIKE ?")->execute([$emps[0], 'su:check:' . $p['code'] . ':%']);
        json_response(['ok' => true]);
    }

    case 'dp.su_supplier_save': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120); $id = (int)($in['id'] ?? 0);
        $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 30); $items = mb_substr(trim((string)($in['items'] ?? '')), 0, 200); $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 250);
        if ($id) {
            $s = dp_su_supplier($pdo, $id); if (!$s) json_response(['error' => 'Supplier not found.'], 404);
            $pdo->prepare("UPDATE dp_su_suppliers SET name = ?, phone = ?, items = ?, note = ?, active = ? WHERE id = ?")->execute([$name !== '' ? $name : $s['name'], $phone ?: null, $items ?: null, $note ?: null, isset($in['active']) ? (!empty($in['active']) ? 1 : 0) : (int)$s['active'], $id]);
        } else {
            if ($name === '') json_response(['error' => 'Give the supplier a name.'], 422);
            $pdo->prepare("INSERT INTO dp_su_suppliers (name, phone, items, note, created_at) VALUES (?,?,?,?,?)")->execute([$name, $phone ?: null, $items ?: null, $note ?: null, bd_now()]); $id = (int)$pdo->lastInsertId();
        }
        json_response(['ok' => true, 'id' => $id]);
    }

    case 'dp.su_ledger': {
        $anyAccess();
        $sid = (int)($_GET['supplier_id'] ?? 0);
        if (!$sid) {
            $o = []; foreach ($pdo->query("SELECT * FROM dp_su_suppliers ORDER BY active DESC, name")->fetchAll() as $s) {
                $p = $pdo->prepare("SELECT COUNT(*) c, MIN(on_date) o FROM dp_su_ledger WHERE supplier_id = ? AND kind = 'bill' AND status = 'pending'"); $p->execute([(int)$s['id']]); $pp = $p->fetch();
                $o[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'phone' => $s['phone'], 'items' => $s['items'], 'note' => $s['note'], 'active' => (int)$s['active'], 'balance' => dp_su_balance($pdo, $s['id']), 'pending_bills' => (int)$pp['c'], 'oldest' => $pp['o']];
            }
            json_response(['suppliers' => $o, 'can_edit' => $acc['mgr']]);
        }
        $s = dp_su_supplier($pdo, $sid); if (!$s) json_response(['error' => 'Supplier not found.'], 404);
        $q = $pdo->prepare("SELECT * FROM dp_su_ledger WHERE supplier_id = ? ORDER BY on_date DESC, id DESC LIMIT 100"); $q->execute([$sid]);
        $rows = array_map(function ($r) { return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'amount' => (float)$r['amount'], 'settled' => (float)$r['settled'], 'status' => $r['status'], 'date' => $r['on_date'], 'note' => $r['note'], 'has_shot' => !empty($r['shot']), 'shot' => $r['shot'], 'by' => $r['created_by']]; }, $q->fetchAll());
        json_response(['supplier' => ['id' => (int)$s['id'], 'name' => $s['name'], 'phone' => $s['phone'], 'items' => $s['items']], 'balance' => dp_su_balance($pdo, $sid), 'rows' => $rows]);
    }

    case 'dp.su_ledger_pay': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $anyAccess();
        if (!$acc['mgr'] && !$acc['buy']) json_response(['error' => 'Only a buyer or a boss can record a payment.'], 403);
        dp_su_need_live($pdo);
        $s = dp_su_supplier($pdo, (int)($_POST['supplier_id'] ?? 0)); if (!$s) json_response(['error' => 'Supplier not found.'], 404);
        $amt = round((float)($_POST['amount'] ?? 0), 2); if ($amt <= 0 || $amt > 1000000) json_response(['error' => 'Enter the amount paid.'], 422);
        $shot = dp_su_upload('shot', 'sup', true, 'Every payment to a supplier needs the payment screenshot (UPI or bank). Attach it first.');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 240);
        $pdo->prepare("INSERT INTO dp_su_ledger (supplier_id, kind, amount, note, shot, status, on_date, created_by, created_at) VALUES (?, 'payment', ?, ?, ?, 'paid', ?, ?, ?)")->execute([(int)$s['id'], $amt, $note ?: null, $shot, bd_today(), $by, bd_now()]);
        dp_su_settle($pdo, $s['id'], $amt);
        dp_su_money_out($pdo, $amt, $s['name'], 'Supplier payment', $shot, 'sup:' . $pdo->lastInsertId());
        bd_log('supplies', 'sup' . $s['id'], 'paid ' . dp_inr($amt), $by);
        json_response(['ok' => true, 'message' => 'Payment of ' . dp_inr($amt) . ' recorded for ' . $s['name'] . '.', 'balance' => dp_su_balance($pdo, $s['id'])]);
    }

    case 'dp.su_reimb_list': {
        $anyAccess();
        $mine = !$acc['mgr'];
        $sql = "SELECT b.*, p.name AS place FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.reimb_status IN ('pending','paid')" . ($mine ? " AND b.buyer_emp = " . (int)$acc['emp'] : '') . " ORDER BY b.reimb_status = 'paid', b.id DESC LIMIT 60";
        $o = []; foreach ($pdo->query($sql)->fetchAll() as $b) $o[] = ['id' => (int)$b['id'], 'who' => bd_emp_name($pdo, $b['buyer_emp']), 'place' => $b['place'], 'amount' => (float)$b['amount'], 'note' => $b['note'], 'at' => $b['bought_at'], 'status' => $b['reimb_status'], 'paid_at' => $b['reimb_paid_at'], 'paid_by' => $b['reimb_paid_by'], 'has_receipt' => !empty($b['shot']), 'has_proof' => !empty($b['reimb_shot']), 'receipt' => $b['shot'], 'proof' => $b['reimb_shot']];
        json_response(['rows' => $o, 'can_pay' => $acc['admin']]);
    }

    case 'dp.su_reimb_pay': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        $q = $pdo->prepare("SELECT b.*, p.name AS place FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.id = ?"); $q->execute([(int)($_POST['id'] ?? 0)]); $b = $q->fetch();
        if (!$b || $b['reimb_status'] !== 'pending') json_response(['error' => 'This reimbursement is not waiting for payment.'], 404);
        $shot = dp_su_upload('shot', 'rb', true, 'Attach the UPI transaction screenshot of the payback.');
        $pdo->prepare("UPDATE dp_su_bought SET reimb_status = 'paid', reimb_paid_by = ?, reimb_paid_at = ?, reimb_shot = ? WHERE id = ?")->execute([$by, bd_now(), $shot, (int)$b['id']]);
        $pdo->prepare("UPDATE bd_inbox SET status = 'done', resolved_at = ?, resolved_by = ? WHERE ref_key = ? AND status = 'open'")->execute([bd_now(), $by, 'su_reimb:' . $b['id']]);
        dp_su_money_out($pdo, $b['amount'], bd_emp_name($pdo, $b['buyer_emp']), 'Reimbursement: supplies for ' . $b['place'], $shot, 'rb:' . $b['id']);
        if ($b['buyer_emp']) bd_push($pdo, $b['buyer_emp'], 'You were paid back', dp_inr($b['amount']) . ' for the supplies you bought for ' . $b['place'] . ' was sent by UPI.');
        json_response(['ok' => true, 'message' => 'Marked as paid back (' . dp_inr($b['amount']) . ').']);
    }

    case 'dp.su_cycles': {
        $needMgr();
        $o = []; foreach ($pdo->query("SELECT c.*, i.name, i.unit, i.mode AS imode, i.cycle_days AS icycle, p.name AS place FROM dp_su_cycles c JOIN dp_su_items i ON i.id = c.item_id JOIN dp_su_places p ON p.id = i.place_id ORDER BY c.status = 'proposed' DESC, c.id DESC LIMIT 60")->fetchAll() as $c)
            $o[] = ['id' => (int)$c['id'], 'item' => $c['name'], 'place' => $c['place'], 'cycle_days' => (int)$c['cycle_days'], 'current' => $c['imode'] === 'cycle' ? (int)$c['icycle'] : ($c['imode'] === 'daily' ? 1 : null), 'spans' => (int)$c['spans'], 'basis' => $c['basis'], 'status' => $c['status'], 'decided_by' => $c['decided_by']];
        json_response(['cycles' => $o]);
    }

    case 'dp.su_cycle_act': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $q = $pdo->prepare("SELECT * FROM dp_su_cycles WHERE id = ?"); $q->execute([(int)($in['id'] ?? 0)]); $c = $q->fetch();
        if (!$c || $c['status'] !== 'proposed') json_response(['error' => 'This proposal is no longer open.'], 404);
        $act = (string)($in['act'] ?? ''); $it = dp_su_item($pdo, $c['item_id']);
        if ($act === 'approve') {
            $days = (int)($in['cycle_days'] ?? $c['cycle_days']); if ($days < 1 || $days > 365) json_response(['error' => 'A cycle must be 1 to 365 days.'], 422);
            if ($days === 1) $pdo->prepare("UPDATE dp_su_items SET mode = 'daily', cycle_days = NULL WHERE id = ?")->execute([$it['id']]);
            else $pdo->prepare("UPDATE dp_su_items SET mode = 'cycle', cycle_days = ? WHERE id = ?")->execute([$days, $it['id']]);
            $pdo->prepare("UPDATE dp_su_cycles SET status = 'approved', cycle_days = ?, decided_by = ?, decided_at = ? WHERE id = ?")->execute([$days, $by, bd_now(), $c['id']]);
            $msg = $it['name'] . ' will now come ' . ($days === 1 ? 'every day' : 'every ' . $days . ' days') . '.';
        } elseif ($act === 'reject') { $pdo->prepare("UPDATE dp_su_cycles SET status = 'rejected', decided_by = ?, decided_at = ? WHERE id = ?")->execute([$by, bd_now(), $c['id']]); $msg = 'Not changed. Claude keeps watching.'; }
        else json_response(['error' => 'Choose approve or reject.'], 422);
        $pdo->prepare("UPDATE bd_inbox SET status = 'done', resolved_at = ?, resolved_by = ? WHERE ref_key = ? AND status = 'open'")->execute([bd_now(), $by, 'su_cyc:' . $c['id']]);
        json_response(['ok' => true, 'message' => $msg]);
    }

    case 'dp.su_learn': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $n = dp_su_learn($pdo);
        json_response(['ok' => true, 'new' => $n, 'message' => $n ? $n . ' new proposal' . ($n === 1 ? '' : 's') . ' from the checks so far.' : 'Nothing new. Claude needs 2 refills, or 3 days of checks after a refill, to be sure.']);
    }

    case 'dp.su_propose': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); $needMgr();
        $p = $getPlace($in['place_code'] ?? ''); list($n, $msg) = dp_su_propose($pdo, $p);
        json_response(['ok' => true, 'made' => $n, 'message' => $msg]);
    }

    case 'dp.su_settings': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); bd_need_admin($auth);
        foreach (['daily_by' => 'su_daily_by', 'check_by' => 'su_check_by'] as $k => $sk) { if (isset($in[$k])) { if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$in[$k])) json_response(['error' => 'Time must look like 10:00.'], 422); dp_put($sk, $in[$k]); } }
        foreach (['low_h' => 'su_low_h', 'empty_h' => 'su_empty_h'] as $k => $sk) { if (isset($in[$k])) dp_put($sk, (string)max(1, min(72, (int)$in[$k]))); }
        json_response(['ok' => true]);
    }

    case 'dp.su_file': {
        $name = basename((string)($_GET['name'] ?? ''));
        $st = $pdo->prepare("SELECT b.buyer_emp, p.code FROM dp_su_bought b JOIN dp_su_places p ON p.id = b.place_id WHERE b.shot = ? OR b.reimb_shot = ? OR b.bill_shot = ? LIMIT 1"); $st->execute([$name, $name, $name]); $r = $st->fetch();
        if (!$r) { $l = $pdo->prepare("SELECT 1 FROM dp_su_ledger WHERE shot = ? LIMIT 1"); $l->execute([$name]); if (!$l->fetchColumn()) json_response(['error' => 'File not found.'], 404); }
        elseif (!$acc['mgr'] && (int)$r['buyer_emp'] !== (int)$acc['emp'] && !isset($acc['chk'][$r['code']])) json_response(['error' => 'Not allowed.'], 403);
        if (!$r && !$acc['mgr']) json_response(['error' => 'Not allowed.'], 403);
        dp_file_out($name);
    }
    }
}
} catch (PDOException $e) {
    json_response(['error' => 'Database problem in Office supplies: ' . mb_substr($e->getMessage(), 0, 200)], 500);
}

/* the Shopping list for all offices (assign, buyer screen, screenshot check, proof missing) lives in its own file */
if (is_file(__DIR__ . '/dept_work.php')) { require_once __DIR__ . '/dept_work.php'; }
