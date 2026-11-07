<?php
// restock_api.php — Auto restock: plan → memo → dispatch → scan cross-check → alerts.
// Admin: plan | search | add | set | remove | send | cancel | orders | memo | alerts | learn | dispatchers | disp_add | disp_del | settings
// Dispatcher (assigned in Restock → People): open | ship | cant
// Any crash (also a parse error in an included file) is answered as JSON with the real reason, never as an empty HTTP 500.
function rs_crash_out($msg) { if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); } echo json_encode(['ok' => false, 'error' => 'Server error: ' . $msg]); }
set_exception_handler(function ($e) { @error_log('[restock_api] ' . $e->getMessage()); rs_crash_out($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'); });
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { @error_log('[restock_api] ' . $e['message']); while (ob_get_level()) @ob_end_clean(); rs_crash_out($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'); } });
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/restock_lib.php';
if (!function_exists('json_out')) { function json_out($d, $c = 200) { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; } }
$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

$user = require_login(true);
ensure_restock_schema($pdo);   // only after login: nothing here may run for an anonymous visitor
$admin = (($user['role'] ?? '') === 'admin');
$disp  = !empty($user['dispatch']) || rs_is_dispatcher($pdo, (int)$user['id']);
$DISP_OK = ['open', 'ship', 'cant', 'plan', 'search', 'add', 'set', 'remove', 'send', 'cancel', 'orders', 'memo', 'alerts', 'learn'];   // dispatch login: plan, create & send orders, record dispatch. NOT people or rules.
if (!$admin && !($disp && in_array($action, $DISP_OK, true))) json_out(['ok' => false, 'error' => 'Not allowed'], 403);

function one_order(PDO $pdo, int $id) { $o = rs_orders($pdo, 'id=?', [$id], 1); return $o ? $o[0] : null; }

/* ---------- PLAN: the draft memo (auto-made every 2 days; merges new needs, never overwrites your edits) ---------- */
if ($action === 'plan') {
    $made = rs_auto_tick($pdo, (int)$user['id']);
    $id = (int)$pdo->query("SELECT id FROM rs_orders WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (($_GET['refresh'] ?? '') === '1' || !$id) $id = rs_draft($pdo, (int)$user['id'], 'auto');   // "Refresh" pulls in perfumes that became low/empty since
    $o = one_order($pdo, $id); $c = rs_learn_apply($pdo, rs_cfg());
    json_out(['ok' => true, 'order' => $o, 'auto_made' => (bool)$made, 'cfg' => ['cover' => $c['cover'], 'lead' => $c['lead'], 'learned' => $c['learned'], 'gap' => $c['gap']]]);
}
if ($action === 'search') {
    $q = trim($_GET['q'] ?? ''); if ($q === '') json_out(['ok' => true, 'rows' => []]);
    $st = $pdo->prepare("SELECT id,title,variant_title,pos_sku FROM products WHERE status=1 AND pos_sku IS NOT NULL AND pos_sku<>'' AND (title LIKE ? OR pos_sku=?) ORDER BY title LIMIT 40");
    $st->execute(['%' . $q . '%', $q]); $rows = [];
    foreach ($st as $r) $rows[] = ['product_id' => (int)$r['id'], 'name' => rs_clean_title($r['title']), 'size' => rs_size($r['variant_title'], $r['title']), 'sku' => $r['pos_sku']];
    json_out(['ok' => true, 'rows' => $rows]);
}
if ($action === 'add') {   // manual line into the draft (or add to a line that is already there)
    $pid = (int)($in['product_id'] ?? 0); $qty = max(1, (int)($in['qty'] ?? 1)); if (!$pid) json_out(['ok' => false, 'error' => 'Pick a perfume'], 400);
    $oid = rs_draft($pdo, (int)$user['id'], 'manual');
    $ex = $pdo->prepare("SELECT id FROM rs_items WHERE order_id=? AND product_id=?"); $ex->execute([$oid, $pid]); $iid = (int)$ex->fetchColumn();
    if ($iid) $pdo->prepare("UPDATE rs_items SET ordered_qty=ordered_qty+?, status='open' WHERE id=?")->execute([$qty, $iid]);
    else $pdo->prepare("INSERT INTO rs_items (order_id,product_id,ordered_qty,reason) VALUES (?,?,?, 'manual')")->execute([$oid, $pid, $qty]);
    json_out(['ok' => true, 'order' => one_order($pdo, $oid)]);
}
if ($action === 'set') {   // change the quantity of a line (not below what is already dispatched)
    $iid = (int)($in['item_id'] ?? 0); $qty = max(0, (int)($in['qty'] ?? 0));
    $it = $pdo->prepare("SELECT i.*, o.status ost FROM rs_items i JOIN rs_orders o ON o.id=i.order_id WHERE i.id=?"); $it->execute([$iid]); $r = $it->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Line not found'], 404);
    if ($qty < (int)$r['dispatched_qty']) json_out(['ok' => false, 'error' => 'Already dispatched ' . $r['dispatched_qty'] . ' — cannot go below that'], 400);
    $pdo->prepare("UPDATE rs_items SET ordered_qty=?, cancelled_qty=LEAST(cancelled_qty,?) WHERE id=?")->execute([$qty, $qty, $iid]);
    if (isset($in['note'])) $pdo->prepare("UPDATE rs_items SET note=? WHERE id=?")->execute([mb_substr(trim((string)$in['note']), 0, 250), $iid]);
    rs_close_if_done($pdo, (int)$r['order_id']);
    json_out(['ok' => true, 'order' => one_order($pdo, (int)$r['order_id'])]);
}
if ($action === 'remove') {   // remove a line: draft → deleted; sent → the not-yet-dispatched part is cancelled (history stays)
    $iid = (int)($in['item_id'] ?? 0);
    $it = $pdo->prepare("SELECT i.*, o.status ost FROM rs_items i JOIN rs_orders o ON o.id=i.order_id WHERE i.id=?"); $it->execute([$iid]); $r = $it->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Line not found'], 404);
    if ($r['ost'] === 'draft') $pdo->prepare("DELETE FROM rs_items WHERE id=?")->execute([$iid]);
    else { $pdo->prepare("UPDATE rs_items SET cancelled_qty=ordered_qty-dispatched_qty, status=IF(dispatched_qty=0,'removed','open') WHERE id=?")->execute([$iid]); rs_close_if_done($pdo, (int)$r['order_id']); }
    json_out(['ok' => true, 'order' => one_order($pdo, (int)$r['order_id'])]);
}
if ($action === 'send') {   // draft → sent to the dispatcher (this starts the dispatch clock)
    $oid = (int)($in['order_id'] ?? 0); $o = one_order($pdo, $oid);
    if (!$o || $o['status'] !== 'draft') json_out(['ok' => false, 'error' => 'Only a draft can be sent'], 400);
    $n = 0; foreach ($o['lines'] as $l) if ($l['ordered'] > 0) $n++;
    if (!$n) json_out(['ok' => false, 'error' => 'The memo is empty'], 400);
    $pdo->prepare("DELETE FROM rs_items WHERE order_id=? AND ordered_qty<=0")->execute([$oid]);
    $pdo->prepare("UPDATE rs_orders SET status='sent', sent_at=NOW(), sent_by=?, note=? WHERE id=?")->execute([$user['id'], mb_substr(trim((string)($in['note'] ?? '')), 0, 250) ?: null, $oid]);
    json_out(['ok' => true, 'order' => one_order($pdo, $oid), 'memo' => rs_memo_text(one_order($pdo, $oid))]);
}
if ($action === 'cancel') {
    $oid = (int)($in['order_id'] ?? 0); $o = one_order($pdo, $oid); if (!$o) json_out(['ok' => false, 'error' => 'Not found'], 404);
    if ($o['status'] === 'draft') { $pdo->prepare("DELETE FROM rs_items WHERE order_id=?")->execute([$oid]); $pdo->prepare("DELETE FROM rs_orders WHERE id=?")->execute([$oid]); json_out(['ok' => true]); }
    if ($o['totals']['dispatched'] > 0) json_out(['ok' => false, 'error' => 'Parcels already went — remove the pending lines instead'], 400);
    $pdo->prepare("UPDATE rs_orders SET status='cancelled', closed_at=NOW() WHERE id=?")->execute([$oid]);
    json_out(['ok' => true]);
}
if ($action === 'orders') {   // every memo ever made stays here (nothing is deleted once sent)
    $f = $_GET['f'] ?? 'open'; $w = $f === 'all' ? "status<>'draft'" : ($f === 'done' ? "status IN ('closed','cancelled')" : "status NOT IN ('draft','closed','cancelled')");
    json_out(['ok' => true, 'orders' => rs_orders($pdo, $w, [], 80)]);
}
if ($action === 'memo') { $o = one_order($pdo, (int)($_GET['id'] ?? 0)); if (!$o) json_out(['ok' => false, 'error' => 'Not found'], 404); json_out(['ok' => true, 'text' => rs_memo_text($o), 'order' => $o]); }
if ($action === 'alerts') json_out(['ok' => true, 'alerts' => rs_alerts($pdo)]);
if ($action === 'learn') {
    $rows = $pdo->query("SELECT day,cover,lead_days,gap,arrivals,lead_samples,learned,note FROM rs_learn ORDER BY day DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $c = rs_learn_apply($pdo, rs_cfg());
    json_out(['ok' => true, 'now' => ['cover' => $c['cover'], 'lead' => $c['lead'], 'learned' => $c['learned'], 'dow' => $c['dow']], 'log' => $rows]);
}

/* ---------- who is the dispatcher + numbers ---------- */
if ($action === 'dispatchers') {
    $us = $pdo->query("SELECT u.id,u.name,u.username,u.status, (d.user_id IS NOT NULL) is_disp FROM users u LEFT JOIN rs_dispatchers d ON d.user_id=u.id ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'users' => $us]);
}
if ($action === 'disp_add') { $pdo->prepare("INSERT IGNORE INTO rs_dispatchers (user_id) VALUES (?)")->execute([(int)($in['user_id'] ?? 0)]); json_out(['ok' => true]); }
if ($action === 'disp_del') { $pdo->prepare("DELETE FROM rs_dispatchers WHERE user_id=?")->execute([(int)($in['user_id'] ?? 0)]); json_out(['ok' => true]); }
if ($action === 'settings') {
    $allow = ['rs_cover_days', 'rs_lead_days', 'rs_cycle_days', 'rs_empty_min', 'rs_slow_min', 'rs_top_pct', 'rs_over_days', 'rs_dispatch_hours', 'rs_scan_hours', 'rs_learn'];
    foreach ($allow as $k) if (isset($in[$k]) && $in[$k] !== '') set_setting($k, trim((string)$in[$k]));
    $c = rs_cfg(); json_out(['ok' => true, 'cfg' => $c]);
}

/* ---------- DISPATCHER: what is waiting, ship some, or say "cannot supply the rest" ---------- */
if ($action === 'open') {
    $os = rs_orders($pdo, "status NOT IN ('draft','closed','cancelled')", [], 40); $mine = [];
    foreach (array_reverse($os) as $o) { $ls = array_values(array_filter($o['lines'], function ($l) { return $l['status'] !== 'removed' && ($l['pending'] > 0 || $l['in_transit'] > 0); })); if ($ls) { $o['lines'] = $ls; $mine[] = $o; } }
    json_out(['ok' => true, 'orders' => $mine]);
}
if ($action === 'ship') {   // {order_id, parcel_ref, note, lines:[{item_id, qty}]}
    $oid = (int)($in['order_id'] ?? 0); $o = one_order($pdo, $oid);
    if (!$o || in_array($o['status'], ['draft', 'closed', 'cancelled'], true)) json_out(['ok' => false, 'error' => 'This memo is not open'], 400);
    $byId = []; foreach ($o['lines'] as $l) $byId[$l['id']] = $l; $total = 0; $plan = [];
    foreach (($in['lines'] ?? []) as $ln) {
        $iid = (int)($ln['item_id'] ?? 0); $q = (int)($ln['qty'] ?? 0); if ($q <= 0) continue;
        if (!isset($byId[$iid])) json_out(['ok' => false, 'error' => 'Line does not belong to this memo'], 400);
        if ($q > $byId[$iid]['pending']) json_out(['ok' => false, 'error' => $byId[$iid]['name'] . ': only ' . $byId[$iid]['pending'] . ' pending'], 400);
        $plan[] = [$iid, $q]; $total += $q;
    }
    if (!$total) json_out(['ok' => false, 'error' => 'Enter how many you are sending'], 400);
    $ref = mb_substr(trim((string)($in['parcel_ref'] ?? '')), 0, 60) ?: null; $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 250) ?: null;
    $pdo->beginTransaction();
    try {
        foreach ($plan as [$iid, $q]) {
            $pdo->prepare("UPDATE rs_items SET dispatched_qty=dispatched_qty+? WHERE id=?")->execute([$q, $iid]);
            $pdo->prepare("INSERT INTO rs_ships (order_id,item_id,qty,parcel_ref,note,by_user,by_name) VALUES (?,?,?,?,?,?,?)")->execute([$oid, $iid, $q, $ref, $note, $user['id'], $user['name'] ?? '']);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Could not save'], 500); }
    json_out(['ok' => true, 'sent' => $total]);
}
if ($action === 'cant') {   // supplier cannot make the rest of a line → it is cancelled, store sees it and the next plan re-orders it
    $iid = (int)($in['item_id'] ?? 0);
    $it = $pdo->prepare("SELECT * FROM rs_items WHERE id=?"); $it->execute([$iid]); $r = $it->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Line not found'], 404);
    $pdo->prepare("UPDATE rs_items SET cancelled_qty=ordered_qty-dispatched_qty, note=? WHERE id=?")->execute([mb_substr('Supplier cannot supply: ' . trim((string)($in['note'] ?? '')), 0, 250), $iid]);
    rs_close_if_done($pdo, (int)$r['order_id']);
    json_out(['ok' => true]);
}
json_out(['ok' => false, 'error' => 'Unknown action'], 400);
