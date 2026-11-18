<?php
/**
 * tk_goal.php — TICKET-SIZE GOAL (one number, shown everywhere). Settings (hrms_settings):
 *   tk_on (1) · tk_goal (2700) · tk_bonus_pct (10: shown as an ESTIMATE only until the commission engine pays it) · tk_min_orders (3) · tk_hold (0: off) · tk_hold_below (2300) · tk_hold_orders (4) · tk_hold_wait (3) · tk_ratio_relief (3 points)
 * The ticket is the one the Control Tower already uses (clean ticket when known, else sales ÷ orders of the business day).
 */
require_once __DIR__ . '/tk_lib.php';

// the admin's two controls (Team performance → Auto assign): the goal, and the hold switch
$__tkA = (string)($action ?? ($_GET['action'] ?? ''));
if ($__tkA === 'wq.tk_me') {   // the salesperson's own ticket for the WhatsApp panel chip: reads the cached day rows only (cheap)
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $card = null;
    try { if ($me > 0 && function_exists('waa_cp')) { $r = (array)(waa_cp($pdo)[$me] ?? []); $card = tk_card($pdo, ['orders' => (int)($r['n'] ?? 0), 'sales' => (float)($r['s'] ?? 0), 'clean_ticket' => (float)($r['ctk'] ?? 0), 'ticket' => (float)($r['tk'] ?? 0), 'com_today' => 0]); } } catch (Throwable $e) { }
    $slow = null; try { if ($me > 0 && function_exists('waa_state')) { $x = (array)(waa_state($pdo)['e'][$me] ?? []); if ((int)($x['sh'] ?? 0) > time()) $slow = ['state' => 'hold', 'until' => (int)$x['sh'], 'mins' => (int)ceil(((int)$x['sh'] - time()) / 60)];
        elseif ((int)($x['sw'] ?? 0) > 0 && (int)($x['sw']) > time() - 3600) $slow = ['state' => 'warn', 'since' => (int)$x['sw']]; } } catch (Throwable $e) { }
    json_response(['ok' => true, 'tk' => $card, 'slow' => $slow]);
}
// ================= 📞 Call-backs: today's orders where no add-on (extra perfume) was offered and none was added. The salesperson calls / chats, then RESOLVES with a remark; an added add-on resolves it by itself. =================
function wq_cb_ready(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS grow_callback (id INT AUTO_INCREMENT PRIMARY KEY, order_key VARCHAR(80) NOT NULL, order_name VARCHAR(40) NOT NULL DEFAULT '', emp INT NOT NULL DEFAULT 0, phone VARCHAR(20) NOT NULL DEFAULT '', day DATE NULL, created_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', resolved_at DATETIME NULL, outcome VARCHAR(16) NOT NULL DEFAULT '', remark VARCHAR(300) NOT NULL DEFAULT '', snooze_until DATETIME NULL, UNIQUE KEY u_order (order_key), KEY k_emp (emp, status), KEY k_day (day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('wq_cb_ready: ' . $e->getMessage()); return $ok = false; }
}
if ($__tkA === 'wq.grow_resolve') {   // the salesperson resolves one of their own call-backs (needs a remark) or snoozes it for 30 minutes
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $in = json_input();
    $key = trim((string)($in['key'] ?? '')); $oc = (string)($in['outcome'] ?? ''); $rm = trim((string)($in['remark'] ?? ''));
    if ($me <= 0 || $key === '' || !in_array($oc, ['addon_done', 'declined', 'no_answer', 'later'], true)) json_response(['error' => 'Choose what happened.'], 422);
    if ($oc !== 'later' && mb_strlen($rm) < 4) json_response(['error' => 'Remark likho (kam se kam 4 akshar).'], 422);
    if (!wq_cb_ready($pdo)) json_response(['error' => 'Not available right now.'], 500);
    $adm = function_exists('wa_is_admin') && wa_is_admin($pdo, $auth);
    if ($oc === 'later') $u = $pdo->prepare("UPDATE grow_callback SET snooze_until = ? WHERE order_key = ? AND status = 'open' AND (emp = ? OR ?)");
    else $u = $pdo->prepare("UPDATE grow_callback SET status = 'resolved', resolved_at = ?, outcome = ?, remark = ? WHERE order_key = ? AND status = 'open' AND (emp = ? OR ?)");
    if ($oc === 'later') $u->execute([date('Y-m-d H:i:s', time() + 1800), $key, $me, $adm ? 1 : 0]); else $u->execute([date('Y-m-d H:i:s'), $oc, mb_substr($rm, 0, 300), $key, $me, $adm ? 1 : 0]);
    json_response(['ok' => true, 'changed' => $u->rowCount()]);
}
if ($__tkA === 'wq.grow_callbacks') {   // Admin / HR: today's call-backs — open and resolved with the remarks, and what the add-ons brought afterwards
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can see the call-backs.'], 403);
    $out = ['ok' => true, 'rows' => [], 'open' => 0, 'resolved' => 0, 'by_outcome' => [], 'addon_rs' => 0, 'addon_orders' => 0];
    try { if (wq_cb_ready($pdo)) { $day = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        $q = $pdo->prepare("SELECT order_key, order_name, emp, phone, created_at, status, resolved_at, outcome, remark FROM grow_callback WHERE day = ? ORDER BY (status = 'open') DESC, id DESC LIMIT 200"); $q->execute([$day]); $names = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['status'] === 'open') $out['open']++; else { $out['resolved']++; $out['by_outcome'][$r['outcome']] = ($out['by_outcome'][$r['outcome']] ?? 0) + 1; if ($r['outcome'] === 'addon_done') $names[] = strtolower(ltrim((string)$r['order_name'], '#')); }
            if (count($out['rows']) < 60) { $cn = ''; try { $c = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ? LIMIT 1"); $c->execute([substr(preg_replace('/\D+/', '', (string)$r['phone']), -10)]); $cn = trim((string)$c->fetchColumn()); } catch (Throwable $e) { }
                $out['rows'][] = ['order' => (string)$r['order_name'], 'emp' => $nm[(int)$r['emp']] ?? ('#' . $r['emp']), 'customer' => $cn, 'at' => substr((string)$r['created_at'], 11, 5), 'status' => (string)$r['status'], 'done_at' => $r['resolved_at'] ? substr((string)$r['resolved_at'], 11, 5) : '', 'outcome' => (string)$r['outcome'], 'remark' => (string)$r['remark'], 'mins' => $r['status'] === 'open' ? (int)round((time() - strtotime((string)$r['created_at'])) / 60) : ($r['resolved_at'] ? (int)round((strtotime((string)$r['resolved_at']) - strtotime((string)$r['created_at'])) / 60) : 0)]; } }
        if ($names) { $in = implode(',', array_fill(0, count($names), '?')); $a = $pdo->prepare("SELECT COUNT(DISTINCT REPLACE(LOWER(order_name), '#', '')) n, COALESCE(SUM(price), 0) s FROM employee_addons WHERE REPLACE(LOWER(order_name), '#', '') IN ($in)"); $a->execute($names); $x = $a->fetch(PDO::FETCH_ASSOC); $out['addon_orders'] = (int)($x['n'] ?? 0); $out['addon_rs'] = (int)round((float)($x['s'] ?? 0)); } } } catch (Throwable $e) { error_log('wq.grow_callbacks: ' . $e->getMessage()); }
    json_response($out);
}
function wq_nt_ready(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS grow_note (id INT AUTO_INCREMENT PRIMARY KEY, nkey VARCHAR(100) NOT NULL, day DATE NULL, kind VARCHAR(12) NOT NULL DEFAULT '', emp INT NOT NULL DEFAULT 0, text VARCHAR(300) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', resolved_at DATETIME NULL, resolved_by INT NOT NULL DEFAULT 0, remark VARCHAR(300) NOT NULL DEFAULT '', UNIQUE KEY u_nkey (nkey), KEY k_day (day, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('wq_nt_ready: ' . $e->getMessage()); return $ok = false; }
}
if ($__tkA === 'wq.grow_notes') {   // Admin / HR: the owner's to-do list — who needs a talking-to (behind on bigger / add-on), who left call-backs open. Open ones only; resolving needs a remark.
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can see the notifications.'], 403);
    $out = ['ok' => true, 'open' => [], 'done' => [], 'resolved' => 0];
    try { if (wq_nt_ready($pdo)) {
        $day = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d'); $now = date('Y-m-d H:i:s'); $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : [];
        // raise a note for (kind, emp): one open note at a time; after it was resolved (by the owner or by itself) a NEW one comes back if the same problem shows again at least 30 minutes later — counted ("aaj N-vi baar") so repeats are visible
        $raise = function (string $kind, int $e, string $text) use ($pdo, $day, $now): void {
            $o = $pdo->prepare("SELECT id FROM grow_note WHERE day = ? AND kind = ? AND emp = ? AND status = 'open' LIMIT 1"); $o->execute([$day, $kind, $e]); $oid = (int)$o->fetchColumn();
            if ($oid > 0) return;
            $c = $pdo->prepare("SELECT COUNT(*), MAX(COALESCE(resolved_at, created_at)) FROM grow_note WHERE day = ? AND kind = ? AND emp = ?"); $c->execute([$day, $kind, $e]); $r = $c->fetch(PDO::FETCH_NUM); $n = (int)$r[0];
            if ($n > 0 && strtotime((string)$r[1]) > time() - 1800) return;
            $d = $pdo->prepare("SELECT COUNT(DISTINCT day) FROM grow_note WHERE kind = ? AND emp = ? AND day >= ? AND day < ?"); $d->execute([$kind, $e, date('Y-m-d', strtotime($day . ' -6 day')), $day]); $days = (int)$d->fetchColumn();
            $pre = ($n > 0 ? '🔁 Aaj ' . ($n + 1) . '-vi baar. ' : '') . ($days >= 2 ? '📅 Pichhle 7 din me ' . $days . ' din aisa hua — roz ki aadat lag rahi hai. ' : '');
            $pdo->prepare("INSERT IGNORE INTO grow_note (nkey, day, kind, emp, text, created_at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$kind . ':' . $e . ':' . $day . ':' . ($n + 1), $day, $kind, $e, mb_substr($pre . $text, 0, 300), $now]);
        };
        $cl = wq_coach_levels($pdo); $behind = [];
        foreach ($cl as $e => $v) { $lv = (int)($v['level'] ?? 0); if ($lv < 1) continue; $behind[] = (int)$e; $h1 = $v['h1'] ?? [0, 0];
            $raise('coach', (int)$e, ($lv === 2 ? '🔴 2 ghante se lagatar: ' : '💡 Pichhle ghante: ') . (int)$h1[0] . ' orders me se ' . (int)$h1[1] . ' me bada size aur add-on dono ka offer nahi hua. Isse call karke baat karo.'); }
        // the two lanes on their own + the ticket against the goal (the owner wants ticket-size instructions here too, not only the "both missing" case)
        $lane = []; $behind2 = ['big' => [], 'add' => [], 'ticket' => []];
        try { $A = "(CASE WHEN r.worked = 1 OR r.tried = 1 OR r.tries >= 1 THEN 1 ELSE 0 END)"; $B = "(CASE WHEN r.sz_worked = 1 OR r.sz_tried = 1 OR r.sz_tries >= 1 THEN 1 ELSE 0 END)";
            $q = $pdo->query("SELECT r.employee_id e, COUNT(*) n, SUM($B = 0) zb, SUM($A = 0) za FROM order_rate_auto r JOIN shop_orders o ON o.store_key COLLATE utf8mb4_unicode_ci = SUBSTRING_INDEX(r.order_key, ':', 1) COLLATE utf8mb4_unicode_ci AND o.shopify_id = CAST(SUBSTRING_INDEX(r.order_key, ':', -1) AS UNSIGNED) WHERE r.sz_ok = 3 AND r.rating IS NOT NULL AND r.employee_id > 0 AND o.created_at >= NOW() - INTERVAL 60 MINUTE GROUP BY r.employee_id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $n = (int)$x['n']; if ($n < 3) continue; $e = (int)$x['e'];
                if ((int)$x['zb'] * 2 >= $n) { $behind2['big'][] = $e; $raise('big', $e, '⬆ Ticket size: pichhle ghante ' . $n . ' orders me se ' . (int)$x['zb'] . ' me bada size / value ka offer nahi hua. Isse bolo: "4 ki jagah 5 le lo, 5th pe ₹100 off" jaisa offer har order me rakhe.'); }
                if ((int)$x['za'] * 2 >= $n) { $behind2['add'][] = $e; $raise('add', $e, '➕ Add-on: pichhle ghante ' . $n . ' orders me se ' . (int)$x['za'] . ' me extra perfume ka offer nahi hua. Isse bolo: "10ml tester sirf ₹99 mein add kar du" jaisa offer rakhe.'); } }
            $goal = max(1, (int)setting_get($pdo, 'tk_goal', '2700'));
            $q = $pdo->prepare("SELECT r.employee_id e, COUNT(*) n, AVG(o.total) a FROM order_rate_auto r JOIN shop_orders o ON o.store_key COLLATE utf8mb4_unicode_ci = SUBSTRING_INDEX(r.order_key, ':', 1) COLLATE utf8mb4_unicode_ci AND o.shopify_id = CAST(SUBSTRING_INDEX(r.order_key, ':', -1) AS UNSIGNED) WHERE r.day = ? AND r.employee_id > 0 AND o.total > 0 GROUP BY r.employee_id HAVING n >= 5"); $q->execute([$day]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $a = (float)$x['a']; if ($a < $goal * 0.8) { $e = (int)$x['e']; $behind2['ticket'][] = $e; $raise('ticket', $e, '🎯 Ticket goal se neeche: aaj ' . (int)$x['n'] . ' orders ka average ₹' . number_format($a, 0) . ' (goal ₹' . number_format($goal, 0) . '). Isse bolo ki bade size / extra perfume ka offer rakhe.'); } }
        } catch (Throwable $e) { error_log('wq.grow_notes lanes: ' . $e->getMessage()); }
        foreach ($behind2 as $k => $ids) $pdo->prepare("UPDATE grow_note SET status = 'resolved', resolved_at = ?, remark = 'auto: ab theek hai' WHERE kind = ? AND status = 'open' AND day = ? AND emp NOT IN (" . ($ids ? implode(',', array_map('intval', $ids)) : '0') . ")")->execute([$now, $k, $day]);
        // a behind-note closes by itself once the person is back in line (shown as "auto")
        $pdo->prepare("UPDATE grow_note SET status = 'resolved', resolved_at = ?, remark = 'auto: ab peeche nahi' WHERE kind = 'coach' AND status = 'open' AND day = ? AND emp NOT IN (" . ($behind ? implode(',', $behind) : '0') . ")")->execute([$now, $day]);
        if (wq_cb_ready($pdo)) { $q = $pdo->prepare("SELECT emp, COUNT(*) n FROM grow_callback WHERE day = ? AND status = 'open' AND created_at < ? GROUP BY emp HAVING n >= 3"); $q->execute([$day, date('Y-m-d H:i:s', time() - 3600)]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $raise('callback', (int)$r['emp'], '📞 ' . (int)$r['n'] . ' call-backs ek ghante se zyada se pending hain. Isse bolo ki customers ko call kare.'); }
        $q = $pdo->prepare("SELECT id, kind, emp, text, created_at FROM grow_note WHERE day = ? AND status = 'open' ORDER BY id DESC LIMIT 60"); $q->execute([$day]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out['open'][] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'emp' => $nm[(int)$r['emp']] ?? ('#' . $r['emp']), 'text' => (string)$r['text'], 'at' => substr((string)$r['created_at'], 11, 5)];
        $c = $pdo->prepare("SELECT kind, emp, text, created_at, resolved_at, resolved_by, remark FROM grow_note WHERE day = ? AND status = 'resolved' ORDER BY resolved_at DESC LIMIT 60"); $c->execute([$day]);
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $out['done'][] = ['kind' => (string)$r['kind'], 'emp' => $nm[(int)$r['emp']] ?? ('#' . $r['emp']), 'text' => (string)$r['text'], 'at' => substr((string)$r['created_at'], 11, 5), 'done_at' => substr((string)$r['resolved_at'], 11, 5), 'auto' => (int)$r['resolved_by'] === 0, 'remark' => (string)$r['remark']];
        $out['resolved'] = count($out['done']);
    } } catch (Throwable $e) { error_log('wq.grow_notes: ' . $e->getMessage()); }
    json_response($out);
}
if ($__tkA === 'wq.grow_note_resolve') {   // Admin / HR resolves one notification — a remark is required; it then disappears from the list
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can resolve.'], 403);
    $in = json_input(); $id = (int)($in['id'] ?? 0); $rm = trim((string)($in['remark'] ?? ''));
    if ($id <= 0 || mb_strlen($rm) < 4) json_response(['error' => 'Remark likho (kam se kam 4 akshar).'], 422);
    if (!wq_nt_ready($pdo)) json_response(['error' => 'Not available right now.'], 500);
    $u = $pdo->prepare("UPDATE grow_note SET status = 'resolved', resolved_at = ?, resolved_by = ?, remark = ? WHERE id = ? AND status = 'open'"); $u->execute([date('Y-m-d H:i:s'), (int)($auth['employee_id'] ?? 0), mb_substr($rm, 0, 300), $id]);
    json_response(['ok' => true, 'changed' => $u->rowCount()]);
}
if ($__tkA === 'wq.ad_me') {   // ➕ the salesperson's OWN add-on effort today (read from the chats after the orders): four counts, stars, add-on rupees, the orders where nothing was offered, the words that worked. Only the person's own rows.
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    $out = ['ok' => true, 'n' => [0, 0, 0, 0], 'sz' => [0, 0, 0, 0], 'stars' => null, 'addon_rs' => 0, 'missed' => [], 'missed_n' => 0, 'missed_big' => [], 'tip' => '', 'team' => [0, 0, 0, 0], 'team_sz' => [0, 0, 0, 0], 'yd' => ['n' => [0, 0, 0, 0], 'sz' => [0, 0, 0, 0], 'read' => 0], 'read' => 0];
    try {
        if ($me > 0 && function_exists('ora_tables') && ora_tables($pdo)) {
            $day = biz_today($pdo); $st = 0.0; $rows = []; $cbRows = [];
            $q = $pdo->prepare("SELECT order_key, order_name, rating, tried, worked, tries, sz_tried, sz_worked, sz_tries, reason, phone FROM order_rate_auto WHERE employee_id = ? AND day = ? AND rating IS NOT NULL AND sz_ok = 3 ORDER BY at DESC LIMIT 400"); $q->execute([$me, $day]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            try { if (function_exists('addons_attach')) addons_attach($pdo, $rows, 'order_name'); } catch (Throwable $e) { }
            foreach ($rows as $r) {
                $lv = ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries']); $out['n'][$lv]++; $out['sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; $st += (float)$r['rating'] / 2; $out['addon_rs'] += (float)($r['addon_total'] ?? 0);
                if ($lv === 0 && wq_cb_ready($pdo)) { $cbRows[] = [(string)$r['order_key'], (string)$r['order_name'], (string)$r['phone'], (float)($r['addon_total'] ?? 0)]; }
                if (ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries']) === 0 && count($out['missed_big']) < 5) { $out['missed_big'][] = ['order' => (string)$r['order_name'], 'phone' => (string)$r['phone'], 'why' => mb_substr((string)$r['reason'], 0, 140)]; }
            }
            // 📞 call-backs: every order of today without an add-on offer becomes (once) an open call-back; an add-on that got recorded resolves it by itself; the list shown is the OPEN ones (not snoozed)
            try { if ($cbRows && wq_cb_ready($pdo)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO grow_callback (order_key, order_name, emp, phone, day, created_at) VALUES (?, ?, ?, ?, ?, ?)"); $auto = $pdo->prepare("UPDATE grow_callback SET status = 'resolved', resolved_at = ?, outcome = 'addon_done', remark = 'auto: add-on recorded on the order' WHERE order_key = ? AND status = 'open'");
                foreach ($cbRows as $c) { if ($c[3] > 0) $auto->execute([date('Y-m-d H:i:s'), $c[0]]); else $ins->execute([$c[0], $c[1], $me, substr(preg_replace('/\D+/', '', $c[2]), -10), $day, date('Y-m-d H:i:s')]); } } } catch (Throwable $e) { error_log('wq.ad_me callbacks: ' . $e->getMessage()); }
            try { if (wq_cb_ready($pdo)) { $oq = $pdo->prepare("SELECT order_key, order_name, phone FROM grow_callback WHERE emp = ? AND day = ? AND status = 'open' AND (snooze_until IS NULL OR snooze_until < ?) ORDER BY id DESC LIMIT 30"); $oq->execute([$me, $day, date('Y-m-d H:i:s')]);
                foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $c) $out['missed'][] = ['key' => (string)$c['order_key'], 'order' => (string)$c['order_name'], 'phone' => (string)$c['phone'], 'why' => '']; $out['missed_n'] = count($out['missed']); } } catch (Throwable $e) { }
            $n = array_sum($out['n']); $out['read'] = $n; $out['stars'] = $n ? round($st / $n, 1) : null; $out['addon_rs'] = round($out['addon_rs']);
            $t = $pdo->prepare("SELECT tip FROM order_rate_auto WHERE employee_id = ? AND worked = 1 AND tip <> '' AND at >= ? ORDER BY at DESC LIMIT 1"); $t->execute([$me, date('Y-m-d H:i:s', time() - 14 * 86400)]); $out['tip'] = (string)$t->fetchColumn();
            $tm = $pdo->prepare("SELECT tried, worked, tries, sz_tried, sz_worked, sz_tries FROM order_rate_auto WHERE day = ? AND rating IS NOT NULL AND sz_ok = 3 LIMIT 3000"); $tm->execute([$day]); foreach ($tm->fetchAll(PDO::FETCH_ASSOC) as $r) { $out['team'][ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries'])]++; $out['team_sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; }
            $yd = date('Y-m-d', strtotime($day . ' -1 day')); $yq = $pdo->prepare("SELECT tried, worked, tries, sz_tried, sz_worked, sz_tries FROM order_rate_auto WHERE employee_id = ? AND day = ? AND rating IS NOT NULL AND sz_ok = 3 LIMIT 600"); $yq->execute([$me, $yd]);
            foreach ($yq->fetchAll(PDO::FETCH_ASSOC) as $r) { $out['yd']['n'][ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries'])]++; $out['yd']['sz'][ora_effort((int)$r['sz_tried'], (int)$r['sz_worked'], (int)$r['sz_tries'])]++; $out['yd']['read']++; }
        }
    } catch (Throwable $e) { error_log('wq.ad_me: ' . $e->getMessage()); }
    // 💡 ideas from the team's own winning chats (last 7 days, no names): what to say to sell bigger / add an item. Falls back to two fixed examples until enough chats are read.
    try { foreach ($out['missed'] as &$__m) { $__m['name'] = ''; $nq = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ? LIMIT 1"); $nq->execute([substr(preg_replace('/\D+/', '', (string)$__m['phone']), -10)]); $__m['name'] = trim((string)$nq->fetchColumn()); } unset($__m); } catch (Throwable $e) { }
    $ideas = ['big' => [], 'add' => []];
    try { if (function_exists('ora_tables') && ora_tables($pdo)) { $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        foreach (['big' => "sz_worked = 1 AND sz_tip <> ''", 'add' => "worked = 1 AND tip <> ''"] as $k => $w) { $col = $k === 'big' ? 'sz_tip' : 'tip';
            $q = $pdo->prepare("SELECT $col FROM order_rate_auto WHERE sz_ok = 3 AND $w AND at >= ? ORDER BY at DESC LIMIT 40"); $q->execute([$since]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) { $t = trim((string)$t); if ($t !== '' && !in_array($t, $ideas[$k], true)) $ideas[$k][] = $t; if (count($ideas[$k]) >= 3) break; } } } } catch (Throwable $e) { }
    if (!$ideas['big']) $ideas['big'][] = '50ml ki jagah 100ml le lo, per ml sasta padega';
    if (!$ideas['add']) $ideas['add'][] = '4 ki jagah 5 perfume le lo, 5th pe ₹100 off';
    $out['ideas'] = $ideas;
    try { if (function_exists('grow_learn_tick')) grow_learn_tick($pdo); } catch (Throwable $e) { }   // the learning pulse (throttled to once per 15 minutes) also starts from the salespeople's own refresh
    try { $cl = wq_coach_levels($pdo); $out['coach'] = $cl[$me] ?? ['level' => 0, 'h1' => [0, 0], 'h2' => [0, 0]]; } catch (Throwable $e) { $out['coach'] = ['level' => 0, 'h1' => [0, 0], 'h2' => [0, 0]]; }
    json_response($out);
}
if ($__tkA === 'wq.grow_chat') {   // 📈 the live effort of ONE open chat (bigger + add-on), shown only while the order is being confirmed. Own chats only (Admin / manager: any). Read from the transcript by the same strict judge; cached 45 s per last message.
    $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0); $k = substr(preg_replace('/\D+/', '', (string)($_GET['phone'] ?? '')), -10);
    $empty = ['ok' => true, 'big' => null, 'add' => null];
    if (strlen($k) !== 10 || !function_exists('ora_judge_text')) json_response($empty);
    try {
        $c = $pdo->prepare("SELECT assigned_emp FROM wa_contacts WHERE phone = ? LIMIT 1"); $c->execute([$k]); $ae = (int)$c->fetchColumn();
        $adm = function_exists('wa_is_admin') && wa_is_admin($pdo, $auth); $mgr = function_exists('wa_is_manager') && wa_is_manager($pdo, $auth);
        if (!$adm && !$mgr && ($me <= 0 || $ae !== $me)) json_response(['error' => 'Not your chat.'], 403);
        $q = $pdo->prepare("SELECT id, dir, who, emp_id, mtype, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND dir IN ('in', 'out') ORDER BY id ASC LIMIT 300"); $q->execute([$k, date('Y-m-d H:i:s', time() - 3 * 86400)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) json_response($empty);
        $last = (int)end($rows)['id']; [$text, $nc, $ns] = ora_transcript($rows); if ($nc < 1 || $ns < 1) json_response($empty);
        $r = function_exists('hrms_memo') ? hrms_memo('growchat:' . $k . ':' . $last, 45, fn() => ora_judge_text($text, 'This chat is still running: the order is being confirmed now.')) : ora_judge_text($text, '');
        if (!empty($r['retry']) || !is_array($r)) json_response($empty);
        $mk = fn($tr, $wk, $wo, $rf) => ['tries' => (int)$tr, 'weak' => (int)$wk, 'worked' => (int)$wo, 'refused' => (int)$rf, 'level' => ora_effort((int)($tr >= 1 || $wo), (int)$wo, (int)$tr)];
        json_response(['ok' => true, 'big' => $mk($r['big_tries'] ?? 0, $r['big_weak'] ?? 0, $r['big_worked'] ?? 0, $r['big_refused'] ?? 0), 'add' => $mk($r['tries'] ?? 0, $r['weak'] ?? 0, $r['worked'] ?? 0, $r['refused'] ?? 0), 'last' => $last]);
    } catch (Throwable $e) { error_log('wq.grow_chat: ' . $e->getMessage()); json_response($empty); }
}

// ================= 🔗 Payment-link rule: no link before BOTH efforts (extra perfume + bigger size) were really offered =================
// Settings (hrms_settings): grow_gate_mode = off | warn | lock | auto (default auto: warning-only until the day before grow_lock_from, strict from that business day) · grow_lock_from (default 2026-11-19 = strict from the business day that starts tomorrow morning) · grow_link_re (regex of what counts as a payment link).
// Only the Employee role is held (Admin / HR / managers never are). A person is never blocked because OUR side failed (AI silent, no chat read, error). Effort, not result, unlocks: one proper offer per lane, or the customer clearly refused, or the cart already reaches the ticket goal, or a written reason (counted and shown to the owner).
function wq_grow_ready(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS grow_gate_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, day DATE NULL, emp INT NOT NULL DEFAULT 0, phone VARCHAR(20) NOT NULL DEFAULT '', mode VARCHAR(8) NOT NULL DEFAULT '', decision VARCHAR(12) NOT NULL DEFAULT '', add_lv TINYINT NOT NULL DEFAULT 0, big_lv TINYINT NOT NULL DEFAULT 0, reason VARCHAR(300) NOT NULL DEFAULT '', KEY k_day (day), KEY k_emp (emp)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); return $ok = true; }
    catch (Throwable $e) { error_log('wq_grow_ready: ' . $e->getMessage()); return $ok = false; }
}
function wq_grow_cfg(PDO $pdo): array
{
    $m = (string)setting_get($pdo, 'grow_gate_mode', 'warn'); if (!in_array($m, ['off', 'warn', 'lock', 'auto'], true)) $m = 'warn';   // notification-only for everybody until the owner switches it on step by step
    $from = (string)setting_get($pdo, 'grow_lock_from', '2026-11-19'); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '2026-11-19';
    $today = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
    return ['mode' => $m, 'from' => $from, 'today' => $today, 'effective' => $m === 'auto' ? ($today >= $from ? 'lock' : 'warn') : $m];
}
function wq_grow_is_pay_link(PDO $pdo, string $text): bool
{
    if (!preg_match_all('~https?://[^\s]+~i', $text, $m)) return false;
    $re = (string)setting_get($pdo, 'grow_link_re', ''); if ($re === '' || @preg_match($re, '') === false) $re = '~(/invoices/|/checkouts/|/cart/|go\.kwik\.pe|kwik\.pe|rzp\.io|razorpay\.com|paytm|payu|cashfree|/payment)~i';
    foreach ($m[0] as $u) if (preg_match($re, $u)) return true;
    return false;
}
/** The two efforts of the running chat, read by the same strict judge (cached 45 s, shared with wq.grow_chat). null = could not be read (never blocks). */
function wq_grow_levels(PDO $pdo, string $k): ?array
{
    if (strlen($k) !== 10 || !function_exists('ora_judge_text') || !function_exists('ora_transcript') || !function_exists('hrms_memo')) return null;
    try {
        $q = $pdo->prepare("SELECT id, dir, who, emp_id, mtype, body, ts FROM wa_messages WHERE phone = ? AND ts >= ? AND dir IN ('in', 'out') ORDER BY id ASC LIMIT 300"); $q->execute([$k, date('Y-m-d H:i:s', time() - 3 * 86400)]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null; $last = (int)end($rows)['id']; [$text, $nc, $ns] = ora_transcript($rows); if ($nc < 1 || $ns < 1) return null;
        $r = hrms_memo('growchat:' . $k . ':' . $last, 45, fn() => ora_judge_text($text, 'This chat is still running: the order is being confirmed now.'));
        if (!is_array($r) || !empty($r['retry']) || !array_key_exists('tries', $r)) return null;
        return ['add' => ora_effort((int)$r['tried'], (int)$r['worked'], (int)$r['tries']), 'big' => ora_effort((int)($r['big_tried'] ?? 0), (int)($r['big_worked'] ?? 0), (int)($r['big_tries'] ?? 0)), 'add_ref' => !empty($r['refused']), 'big_ref' => !empty($r['big_refused'])];
    } catch (Throwable $e) { return null; }
}
function wq_grow_ideas(PDO $pdo): array
{
    $o = ['big' => ['50ml ki jagah 100ml le lo, per ml sasta padega'], 'add' => ['4 ki jagah 5 perfume le lo, 5th pe ₹100 off']];
    try { if (function_exists('ora_tables') && ora_tables($pdo)) { $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        foreach (['big' => ['sz_tip', "sz_worked = 1 AND sz_tip <> ''"], 'add' => ['tip', "worked = 1 AND tip <> ''"]] as $k => $d) { $q = $pdo->prepare("SELECT {$d[0]} FROM order_rate_auto WHERE sz_ok = 3 AND {$d[1]} AND at >= ? ORDER BY at DESC LIMIT 1"); $q->execute([$since]); $t = trim((string)$q->fetchColumn()); if ($t !== '') $o[$k] = [$t]; } } } catch (Throwable $e) { }
    return $o;
}
function wq_grow_log(PDO $pdo, int $emp, string $phone, string $mode, string $decision, int $add, int $big, string $reason = ''): void
{
    if (!wq_grow_ready($pdo)) return;
    try { $day = function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d');
        if (in_array($decision, ['blocked', 'warn_shown'], true)) { $q = $pdo->prepare("SELECT id FROM grow_gate_log WHERE emp = ? AND phone = ? AND decision = ? AND at >= ? LIMIT 1"); $q->execute([$emp, $phone, $decision, date('Y-m-d H:i:s', time() - 600)]); if ($q->fetchColumn()) return; }   // the same tap twice is one entry
        $pdo->prepare("INSERT INTO grow_gate_log (at, day, emp, phone, mode, decision, add_lv, big_lv, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([date('Y-m-d H:i:s'), $day, $emp, $phone, $mode, $decision, $add, $big, mb_substr($reason, 0, 300)]); } catch (Throwable $e) { }
}
/** 💬 Hourly coaching level of every salesperson (NOTIFICATION ONLY): from the orders made in the last 60 minutes and the 60 before, read by the strict judge.
 *  An order is "no effort" when neither a proper bigger-size nor a proper add-on offer was made. A person is "behind" in a window with at least 3 orders and at least half of them no effort.
 *  level 0 = fine / too few orders · 1 = behind in the last hour (a blue instruction) · 2 = behind two hours in a row (a red warning). Nothing is blocked. Cached 60 s. emp => ['level', 'h1' => [orders, no effort], 'h2' => […]] */
function wq_coach_levels(PDO $pdo): array
{
    if (!function_exists('hrms_memo') || !function_exists('ora_tables')) return [];
    return hrms_memo('wq_coach_levels', 60, function () use ($pdo) {
        $out = [];
        try { if (!ora_tables($pdo)) return [];
            $A = "(CASE WHEN r.worked = 1 OR r.tried = 1 OR r.tries >= 1 THEN 1 ELSE 0 END)"; $B = "(CASE WHEN r.sz_worked = 1 OR r.sz_tried = 1 OR r.sz_tries >= 1 THEN 1 ELSE 0 END)";
            $q = $pdo->query("SELECT r.employee_id e, CASE WHEN o.created_at >= NOW() - INTERVAL 60 MINUTE THEN 1 ELSE 2 END w, COUNT(*) n, SUM($A = 0 AND $B = 0) z FROM order_rate_auto r JOIN shop_orders o ON o.store_key COLLATE utf8mb4_unicode_ci = SUBSTRING_INDEX(r.order_key, ':', 1) COLLATE utf8mb4_unicode_ci AND o.shopify_id = CAST(SUBSTRING_INDEX(r.order_key, ':', -1) AS UNSIGNED) WHERE r.sz_ok = 3 AND r.rating IS NOT NULL AND r.employee_id > 0 AND o.created_at >= NOW() - INTERVAL 120 MINUTE GROUP BY r.employee_id, w");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $out[(int)$x['e']]['h' . (int)$x['w']] = [(int)$x['n'], (int)$x['z']];
            foreach ($out as $e => $v) { $h1 = $v['h1'] ?? [0, 0]; $h2 = $v['h2'] ?? [0, 0]; $b1 = $h1[0] >= 3 && $h1[1] * 2 >= $h1[0]; $b2 = $h2[0] >= 3 && $h2[1] * 2 >= $h2[0]; $out[$e] = ['level' => $b1 ? ($b2 ? 2 : 1) : 0, 'h1' => $h1, 'h2' => $h2]; }
        } catch (Throwable $e) { error_log('wq_coach_levels: ' . $e->getMessage()); return []; }
        return $out;
    });
}
/** 🛟 Safety brake: in the last 2 hours, if more than grow_breaker_pct % (default 40) of the link attempts that reached a decision would have been held back (blocked + warned vs passed; at least 10 attempts), the strict rule steps down to a warning by itself until the share is lower again — so the rule can never starve the orders. Returns [active, share %, attempts]. */
function wq_grow_breaker(PDO $pdo): array
{
    if (!wq_grow_ready($pdo)) return [false, 0, 0];
    $offFrom = (string)setting_get($pdo, 'grow_breaker_off_from', '2026-11-19'); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $offFrom)) $offFrom = '2026-11-19';
    if ((function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d')) >= $offFrom) return [false, 0, 0];   // the owner switched the brake off from this business day (default: from the day the strict rule starts)
    try { $pct = max(10, min(90, (int)setting_get($pdo, 'grow_breaker_pct', '40')));
        $q = $pdo->prepare("SELECT SUM(decision IN ('blocked', 'warn_shown')) b, SUM(decision = 'passed') p FROM grow_gate_log WHERE at >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 7200)]); $r = $q->fetch(PDO::FETCH_ASSOC); $b = (int)($r['b'] ?? 0); $p = (int)($r['p'] ?? 0); $n = $b + $p;
        $share = $n > 0 ? (int)round($b * 100 / $n) : 0; return [$n >= 10 && $share > $pct, $share, $n]; } catch (Throwable $e) { return [false, 0, 0]; }
}
/** The rule. Returns ['gate' => ''] to let the link go, or ['gate' => 'warn'|'lock', 'missing' => ['add','big'], 'error' => text, 'ideas' => …]. $totalRs = the cart total in rupees when known (0 = unknown). */
function wq_grow_check(PDO $pdo, array $auth, string $phone, array $in, int $totalRs = 0): array
{
    $pass = ['gate' => '']; $cfg = wq_grow_cfg($pdo); $mode = $cfg['effective'];
    if ($mode === 'off' || strtolower(trim((string)($auth['role'] ?? ''))) !== 'employee') return $pass;
    $emp = (int)($auth['employee_id'] ?? 0); $k = substr(preg_replace('/\D+/', '', $phone), -10);
    $lv = wq_grow_levels($pdo, $k); if ($lv === null) return $pass;                                  // we could not read the chat: never block
    $goal = (int)setting_get($pdo, 'tk_goal', '2700'); if ($totalRs > 0 && $goal > 0 && $totalRs >= $goal) { wq_grow_log($pdo, $emp, $k, $mode, 'passed', (int)$lv['add'], (int)$lv['big'], 'goal'); return $pass; }   // the cart already reaches the ticket goal
    $addOk = $lv['add'] >= 1 || $lv['add_ref']; $bigOk = $lv['big'] >= 1 || $lv['big_ref'];
    if ($addOk && $bigOk) { wq_grow_log($pdo, $emp, $k, $mode, 'passed', (int)$lv['add'], (int)$lv['big']); return $pass; }
    if ($mode === 'lock' && wq_grow_breaker($pdo)[0]) $mode = 'warn';   // 🛟 too many links would be held: warning only for now
    $reason = trim((string)($in['grow_waive'] ?? '')); if (mb_strlen($reason) >= 4) { wq_grow_log($pdo, $emp, $k, $mode, 'waived', (int)$lv['add'], (int)$lv['big'], $reason); return $pass; }
    if ($mode === 'warn' && !empty($in['grow_ok'])) { wq_grow_log($pdo, $emp, $k, $mode, 'warned_sent', (int)$lv['add'], (int)$lv['big']); return $pass; }
    $miss = []; if (!$addOk) $miss[] = 'add'; if (!$bigOk) $miss[] = 'big';
    wq_grow_log($pdo, $emp, $k, $mode, $mode === 'lock' ? 'blocked' : 'warn_shown', (int)$lv['add'], (int)$lv['big']);
    $what = in_array('add', $miss, true) && in_array('big', $miss, true) ? 'Extra perfume aur bada size, dono ki' : (in_array('add', $miss, true) ? 'Extra perfume (add-on) ki' : 'Bada size (ticket size) ki');
    return ['gate' => $mode, 'missing' => $miss, 'error' => $what . ' sahi offer abhi chat me nahi hui.', 'ideas' => wq_grow_ideas($pdo)];
}
if ($__tkA === 'wq.grow_learn') {   // Admin / HR: the learning feed (every 15 minutes a pulse, every hour a digest) and what went to the guidebook
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can see the learning feed.'], 403);
    try { if (function_exists('grow_learn_tick')) grow_learn_tick($pdo); } catch (Throwable $e) { }   // the 15-minute pulse also starts from here (the engine step may not run every minute); it is throttled by its own 15-minute clock
    $rows = [];
    try { if (function_exists('grow_learn_ready') && grow_learn_ready($pdo)) { foreach ($pdo->query("SELECT at, kind, orders_read, add_tries, add_won, big_tries, big_won, plays, people_behind, guide_use FROM grow_learn_log ORDER BY id DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['plays'] = json_decode((string)$r['plays'], true) ?: ['add' => [], 'big' => []]; $rows[] = $r; } } } catch (Throwable $e) { }
    json_response(['ok' => true, 'rows' => $rows, 'guide_note' => (string)setting_get($pdo, 'lib_guide_nightly_note', ''), 'server_time' => date('H:i')]);
}
if ($__tkA === 'wq.grow_cfg') {   // Admin / HR: the rule's switch (off / warn / lock / auto from a date) + today's numbers
    $auth = require_auth(); $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm) json_response(['error' => 'Only Admin or HR can see or change the link rule.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { $in = json_input();
        if (isset($in['mode']) && in_array((string)$in['mode'], ['off', 'warn', 'lock', 'auto'], true)) setting_set($pdo, 'grow_gate_mode', (string)$in['mode']);
        if (isset($in['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['from'])) setting_set($pdo, 'grow_lock_from', (string)$in['from']);
        if (isset($in['breaker_on'])) setting_set($pdo, 'grow_breaker_off_from', !empty($in['breaker_on']) ? '2099-01-01' : (function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d')));
        if (isset($in['breaker_pct'])) setting_set($pdo, 'grow_breaker_pct', (string)max(10, min(90, (int)$in['breaker_pct']))); }
    [$__ba, $__bs, $__bn] = wq_grow_breaker($pdo);
    $out = wq_grow_cfg($pdo) + ['breaker' => ['on' => (string)setting_get($pdo, 'grow_breaker_off_from', '2026-11-19') > (function_exists('biz_today') ? biz_today($pdo) : date('Y-m-d')), 'off_from' => (string)setting_get($pdo, 'grow_breaker_off_from', '2026-11-19'), 'active' => $__ba, 'share' => $__bs, 'attempts' => $__bn, 'limit' => max(10, min(90, (int)setting_get($pdo, 'grow_breaker_pct', '40')))], 'today_counts' => ['blocked' => 0, 'warn_shown' => 0, 'warned_sent' => 0, 'waived' => 0, 'passed' => 0], 'waivers' => []];
    try { if (wq_grow_ready($pdo)) { $q = $pdo->prepare("SELECT decision, COUNT(*) n FROM grow_gate_log WHERE day = ? GROUP BY decision"); $q->execute([$out['today']]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out['today_counts'][(string)$r['decision']] = (int)$r['n'];
        $nm = function_exists('employee_names_by_id') ? employee_names_by_id($pdo) : []; $w = $pdo->prepare("SELECT emp, at, reason FROM grow_gate_log WHERE day = ? AND decision = 'waived' ORDER BY id DESC LIMIT 20"); $w->execute([$out['today']]);
        foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $r) $out['waivers'][] = ['emp' => $nm[(int)$r['emp']] ?? ('#' . $r['emp']), 'at' => substr((string)$r['at'], 11, 5), 'reason' => (string)$r['reason']]; } } catch (Throwable $e) { }
    json_response(['ok' => true] + $out);
}
if ($__tkA === 'wq.tk_cfg') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth();
    $adm = function_exists('wa_is_admin') ? wa_is_admin($pdo, $auth) : in_array((string)($auth['role'] ?? ''), ['Admin', 'HR'], true);
    if (!$adm && function_exists('wa_boss_ok') && wa_boss_ok($pdo, $auth)) $adm = true;
    if (!$adm) json_response(['error' => 'Only Admin, HR or a Sales Head can change the ticket goal.'], 403);
    if ($isPost) { $in = json_input();
        if (isset($in['goal'])) setting_set($pdo, 'tk_goal', (string)max(0, min(50000, (int)$in['goal'])));
        if (isset($in['hold'])) setting_set($pdo, 'tk_hold', !empty($in['hold']) ? '1' : '0');
        if (isset($in['pct'])) setting_set($pdo, 'tk_bonus_pct', (string)max(0, min(50, (float)$in['pct'])));
        if (isset($in['offer'])) setting_set($pdo, 'tk_offer', mb_substr(trim((string)$in['offer']), 0, 300));
        if (isset($in['cust'])) setting_set($pdo, 'tk_cust_goal', mb_substr(trim((string)$in['cust']), 0, 300));
        if (isset($in['pay'])) setting_set($pdo, 'tk_pay', !empty($in['pay']) ? '1' : '0');
    }
    $c = tk_cfg($pdo); json_response(['ok' => true, 'goal' => (int)$c['goal'], 'hold' => $c['hold'], 'pct' => $c['pct'], 'offer' => $c['offer'], 'cust' => $c['cust']]);
}
