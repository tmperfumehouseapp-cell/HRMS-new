<?php
// api/transfer.php — warehouse↔store transfer actions (JSON)
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$user = require_login(true);

function t_role($u){ return $u['role'] ?? ''; }
function is_store($u){ return in_array(t_role($u), ['store','admin'], true); }
function is_wh($u){ return in_array(t_role($u), ['warehouse','admin'], true); }

function unit_prefix($t){ $p = strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)$t),0,4)); return $p!==''?$p:'ITM'; }
function unit_size($v){ return preg_match('/\d+/', (string)$v, $m) ? $m[0] : 'NA'; }

function next_no($pdo,$table,$col,$prefix){
    $n = (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() + 1;
    $no = $prefix.'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
    while (true) { $chk->execute([$no]); if ((int)$chk->fetchColumn()===0) break; $n++; $no=$prefix.'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT); }
    return $no;
}

// Make the number of 'ready' (label-printed, not yet dispatched) bottles for a
// request+product equal $want: create missing ones (with serial), delete extras.
function reconcile_ready($pdo,$rid,$pid,$title,$variant,$want,$uid){
    $c = $pdo->prepare("SELECT id FROM stock_units WHERE request_id=? AND product_id=? AND status='ready' ORDER BY id");
    $c->execute([$rid,$pid]); $have = $c->fetchAll(PDO::FETCH_COLUMN);
    $n = count($have);
    if ($n < $want) {
        $insU = $pdo->prepare("INSERT INTO stock_units (serial,product_id,variant_label,status,request_id,created_by) VALUES (?,?,?,'ready',?,?)");
        $updU = $pdo->prepare('UPDATE stock_units SET serial=? WHERE id=?');
        $prefix = unit_prefix($title); $sizePart = unit_size($variant);
        $sizeLbl = trim((string)$variant) !== '' ? trim((string)$variant) : null;
        for ($i=0; $i < $want-$n; $i++) {
            $tmp = 'TMP-'.bin2hex(random_bytes(12));
            $insU->execute([$tmp,$pid,$sizeLbl,$rid,$uid]);
            $id = (int)$pdo->lastInsertId();
            $updU->execute([$prefix.'-'.$sizePart.'-'.str_pad((string)$id,4,'0',STR_PAD_LEFT),$id]);
        }
    } elseif ($n > $want) {
        $extra = array_slice($have, $want); // delete the newest extras
        if ($extra) { $inq = implode(',', array_fill(0,count($extra),'?')); $pdo->prepare("DELETE FROM stock_units WHERE id IN ($inq)")->execute($extra); }
    }
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? ($_GET['action'] ?? '');

try {

// ── Store: create a stock request ──
if ($action === 'create_request') {
    if (!is_store($user)) json_out(['ok'=>false,'error'=>'Store access only'],403);
    $items = $in['items'] ?? [];
    $note  = trim($in['note'] ?? '');
    $gp = $pdo->prepare('SELECT id,title,variant_title FROM products WHERE id = ?');
    $valid = [];
    foreach ($items as $it) {
        $pid=(int)($it['product_id']??0); $q=(int)($it['qty']??0);
        if ($pid<=0 || $q<=0) continue;
        $gp->execute([$pid]); $p=$gp->fetch(); if(!$p) continue;
        $valid[] = ['p'=>$p,'q'=>$q];
    }
    if (!$valid) json_out(['ok'=>false,'error'=>'Add at least one product with quantity'],400);
    $pdo->beginTransaction();
    $no = next_no($pdo,'stock_requests','request_no','REQ');
    $pdo->prepare('INSERT INTO stock_requests (request_no,requested_by,status,note) VALUES (?,?,?,?)')
        ->execute([$no,$user['id'],'open',$note!==''?$note:null]);
    $rid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO stock_request_items (request_id,product_id,title,variant_title,qty_requested) VALUES (?,?,?,?,?)');
    foreach ($valid as $v) $ins->execute([$rid,$v['p']['id'],$v['p']['title'],$v['p']['variant_title'],$v['q']]);
    $pdo->commit();
    json_out(['ok'=>true,'request_no'=>$no,'request_id'=>$rid]);
}

// ── Store: generate barcodes for existing unbarcoded stock (status 'ready') ──
if ($action === 'addinv_generate') {
    if (!is_store($user)) json_out(['ok'=>false,'error'=>'Store access only'],403);
    $items = $in['items'] ?? [];
    $gp = $pdo->prepare('SELECT id,title,variant_title FROM products WHERE id=?');
    $valid = [];
    foreach ($items as $it) {
        $pid=(int)($it['product_id']??0); $q=(int)($it['qty']??0);
        if ($pid<=0 || $q<=0) continue;
        $gp->execute([$pid]); $p=$gp->fetch(); if(!$p) continue;
        $valid[] = ['p'=>$p,'q'=>$q];
    }
    if (!$valid) json_out(['ok'=>false,'error'=>'Add at least one bottle'],400);
    $batch = 'ADD-'.date('ymd').'-'.substr(bin2hex(random_bytes(4)),0,6);
    $pdo->beginTransaction();
    $insU = $pdo->prepare("INSERT INTO stock_units (serial,product_id,variant_label,status,batch_id,created_by) VALUES (?,?,?,'ready',?,?)");
    $updU = $pdo->prepare('UPDATE stock_units SET serial=? WHERE id=?');
    $units = [];
    foreach ($valid as $v) {
        $p=$v['p']; $prefix=unit_prefix($p['title']); $sizePart=unit_size($p['variant_title']);
        $sizeLbl = trim((string)$p['variant_title'])!==''?trim((string)$p['variant_title']):null;
        for ($i=0;$i<$v['q'];$i++) {
            $tmp='TMP-'.bin2hex(random_bytes(12));
            $insU->execute([$tmp,$p['id'],$sizeLbl,$batch,$user['id']]);
            $id=(int)$pdo->lastInsertId();
            $serial=$prefix.'-'.$sizePart.'-'.str_pad((string)$id,4,'0',STR_PAD_LEFT);
            $updU->execute([$serial,$id]);
            $units[] = ['id'=>$id,'serial'=>$serial,'title'=>$p['title'],'variant'=>$p['variant_title']];
        }
    }
    $pdo->commit();
    json_out(['ok'=>true,'batch_id'=>$batch,'count'=>count($units),'units'=>$units]);
}

// ── Store: scan a generated barcode in → adds to store inventory ──
if ($action === 'addinv_scan') {
    if (!is_store($user)) json_out(['ok'=>false,'error'=>'Store access only'],403);
    $batch = trim($in['batch_id'] ?? '');
    $code  = trim($in['code'] ?? '');
    $u = false;
    if (preg_match('/^[Uu](\d+)$/',$code,$m)) { $q=$pdo->prepare('SELECT * FROM stock_units WHERE id=?'); $q->execute([(int)$m[1]]); $u=$q->fetch(); }
    if (!$u) { $q=$pdo->prepare('SELECT * FROM stock_units WHERE serial=?'); $q->execute([$code]); $u=$q->fetch(); }
    if (!$u) json_out(['ok'=>false,'error'=>'Unknown code: '.$code],404);
    if ($batch!=='' && $u['batch_id']!==$batch) json_out(['ok'=>false,'wrong'=>true,'error'=>'Not in this batch: '.$u['serial']],409);
    if ($u['status']==='in_stock') json_out(['ok'=>false,'already'=>true,'serial'=>$u['serial'],'error'=>'Already scanned: '.$u['serial']],409);
    if ($u['status']!=='ready') json_out(['ok'=>false,'error'=>'Bottle status is '.$u['status']],409);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE stock_units SET status='in_stock', received_by=?, received_at=NOW() WHERE id=? AND status='ready'")->execute([$user['id'],(int)$u['id']]);
    $up=$pdo->prepare('UPDATE inventory SET qty=qty+1 WHERE product_id=?'); $up->execute([(int)$u['product_id']]);
    if ($up->rowCount()===0) $pdo->prepare('INSERT INTO inventory (product_id,qty) VALUES (?,1)')->execute([(int)$u['product_id']]);
    $pdo->commit();
    $pt=$pdo->prepare('SELECT title,variant_title FROM products WHERE id=?'); $pt->execute([(int)$u['product_id']]); $P=$pt->fetch();
    json_out(['ok'=>true,'serial'=>$u['serial'],'product_id'=>(int)$u['product_id'],'title'=>$P['title']??'']);
}

// ── Warehouse: stage a line (how many ready / pending + reason + date) ──
if ($action === 'fulfill_line') {
    if (!is_wh($user)) json_out(['ok'=>false,'error'=>'Warehouse access only'],403);
    $iid = (int)($in['item_id'] ?? 0);
    $qd  = max(0,(int)($in['qty_dispatched'] ?? 0));
    $reason = trim($in['pending_reason'] ?? '');
    $exp = $in['expected_date'] ?? null;
    if ($exp && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$exp)) $exp = null;

    $ln = $pdo->prepare('SELECT * FROM stock_request_items WHERE id = ?'); $ln->execute([$iid]);
    $L = $ln->fetch();
    if (!$L) json_out(['ok'=>false,'error'=>'Line not found'],404);

    // already dispatched in earlier memos for this product+request
    $sq = $pdo->prepare('SELECT COALESCE(SUM(mi.qty_dispatched),0) FROM memo_items mi JOIN memos m ON m.id=mi.memo_id WHERE m.request_id=? AND mi.product_id=?');
    $sq->execute([(int)$L['request_id'],(int)$L['product_id']]);
    $already = (int)$sq->fetchColumn();
    $remaining = max(0,(int)$L['qty_requested'] - $already);
    if ($qd > $remaining) $qd = $remaining;

    $total = $already + $qd;
    $status = $total >= (int)$L['qty_requested'] ? 'complete' : ($total>0 ? 'partial' : 'pending');

    $pdo->prepare('UPDATE stock_request_items SET qty_dispatched=?, line_status=?, pending_reason=?, expected_date=? WHERE id=?')
        ->execute([$qd,$status,($status==='complete'?null:($reason!==''?$reason:null)),($status==='complete'?null:$exp),$iid]);
    $pdo->prepare("UPDATE stock_requests SET status='fulfilling' WHERE id=? AND status='open'")->execute([(int)$L['request_id']]);
    json_out(['ok'=>true,'line_status'=>$status,'qty_dispatched'=>$qd,'remaining'=>$remaining]);
}

// ── Warehouse: generate + ready the labels for a line (or all lines) ──
if ($action === 'print_labels') {
    if (!is_wh($user)) json_out(['ok'=>false,'error'=>'Warehouse access only'],403);
    $rid = (int)($in['request_id'] ?? 0);
    $iid = (int)($in['item_id'] ?? 0); // 0 = all lines
    $sql = 'SELECT * FROM stock_request_items WHERE request_id=? AND qty_dispatched>0';
    $args = [$rid];
    if ($iid > 0) { $sql .= ' AND id=?'; $args[] = $iid; }
    $li = $pdo->prepare($sql); $li->execute($args); $lines = $li->fetchAll();
    if (!$lines) json_out(['ok'=>false,'error'=>'Nothing ready to print — set a ready qty first'],400);
    $pdo->beginTransaction();
    $total = 0;
    foreach ($lines as $L) {
        // ready qty cannot exceed what's still remaining for the product
        $sq = $pdo->prepare('SELECT COALESCE(SUM(mi.qty_dispatched),0) FROM memo_items mi JOIN memos m ON m.id=mi.memo_id WHERE m.request_id=? AND mi.product_id=?');
        $sq->execute([$rid,(int)$L['product_id']]); $already=(int)$sq->fetchColumn();
        $want = min((int)$L['qty_dispatched'], max(0,(int)$L['qty_requested']-$already));
        reconcile_ready($pdo,$rid,(int)$L['product_id'],$L['title'],$L['variant_title'],$want,$user['id']);
        $total += $want;
    }
    $pdo->commit();
    json_out(['ok'=>true,'count'=>$total,'request_id'=>$rid,'item_id'=>$iid]);
}

// ── Warehouse: dispatch staged qtys → create memo + serials ──
if ($action === 'dispatch') {
    if (!is_wh($user)) json_out(['ok'=>false,'error'=>'Warehouse access only'],403);
    $rid = (int)($in['request_id'] ?? 0);
    $rq = $pdo->prepare('SELECT * FROM stock_requests WHERE id=?'); $rq->execute([$rid]);
    $R = $rq->fetch(); if(!$R) json_out(['ok'=>false,'error'=>'Request not found'],404);

    $li = $pdo->prepare('SELECT * FROM stock_request_items WHERE request_id=? AND qty_dispatched>0'); $li->execute([$rid]);
    $lines = $li->fetchAll();
    if (!$lines) json_out(['ok'=>false,'error'=>'Nothing staged to dispatch — set "ready" qty on at least one line'],400);

    $pdo->beginTransaction();
    $mno = next_no($pdo,'memos','memo_no','MEMO');
    $pdo->prepare('INSERT INTO memos (memo_no,request_id,warehouse_by,status) VALUES (?,?,?,?)')
        ->execute([$mno,$rid,$user['id'],'dispatched']);
    $mid = (int)$pdo->lastInsertId();

    $insMI = $pdo->prepare('INSERT INTO memo_items (memo_id,product_id,title,variant_title,qty_dispatched) VALUES (?,?,?,?,?)');
    $flip  = $pdo->prepare("UPDATE stock_units SET status='dispatched', memo_id=? WHERE request_id=? AND product_id=? AND status='ready'");
    $units = 0;
    foreach ($lines as $L) {
        // how many are still owed for this product, capped by staged ready qty
        $sq = $pdo->prepare('SELECT COALESCE(SUM(mi.qty_dispatched),0) FROM memo_items mi JOIN memos m ON m.id=mi.memo_id WHERE m.request_id=? AND mi.product_id=? AND m.id<>?');
        $sq->execute([$rid,(int)$L['product_id'],$mid]); $already=(int)$sq->fetchColumn();
        $qd = min((int)$L['qty_dispatched'], max(0,(int)$L['qty_requested']-$already));
        if ($qd <= 0) continue;
        // make sure exactly $qd 'ready' bottles exist (covers dispatch-without-printing), then flip them into this memo
        reconcile_ready($pdo,$rid,(int)$L['product_id'],$L['title'],$L['variant_title'],$qd,$user['id']);
        $insMI->execute([$mid,$L['product_id'],$L['title'],$L['variant_title'],$qd]);
        $flip->execute([$mid,$rid,(int)$L['product_id']]);
        $units += $qd;
    }

    // reset staging + recompute each line's status from total dispatched
    $allLines = $pdo->prepare('SELECT id,product_id,qty_requested FROM stock_request_items WHERE request_id=?'); $allLines->execute([$rid]);
    $sumQ = $pdo->prepare('SELECT COALESCE(SUM(mi.qty_dispatched),0) FROM memo_items mi JOIN memos m ON m.id=mi.memo_id WHERE m.request_id=? AND mi.product_id=?');
    $setL = $pdo->prepare('UPDATE stock_request_items SET qty_dispatched=0, line_status=? WHERE id=?');
    foreach ($allLines->fetchAll() as $AL) {
        $sumQ->execute([$rid,$AL['product_id']]); $done=(int)$sumQ->fetchColumn();
        $st = $done>=(int)$AL['qty_requested']?'complete':($done>0?'partial':'pending');
        $setL->execute([$st,$AL['id']]);
    }
    // request status
    $pend = $pdo->prepare("SELECT COUNT(*) FROM stock_request_items WHERE request_id=? AND line_status IN ('pending','partial')"); $pend->execute([$rid]);
    $hasPending = (int)$pend->fetchColumn() > 0;
    $pdo->prepare('UPDATE stock_requests SET status=? WHERE id=?')->execute([$hasPending?'fulfilling':'dispatched',$rid]);
    $pdo->commit();
    json_out(['ok'=>true,'memo_no'=>$mno,'memo_id'=>$mid,'units'=>$units]);
}

// ── Store: scan a bottle in from a memo ──
if ($action === 'receive_scan') {
    if (!is_store($user)) json_out(['ok'=>false,'error'=>'Store access only'],403);
    $mid = (int)($in['memo_id'] ?? 0);
    $code = trim($in['code'] ?? '');
    $u = false;
    if (preg_match('/^[Uu](\d+)$/',$code,$m)) { $q=$pdo->prepare('SELECT * FROM stock_units WHERE id=?'); $q->execute([(int)$m[1]]); $u=$q->fetch(); }
    if (!$u) { $q=$pdo->prepare('SELECT * FROM stock_units WHERE serial=?'); $q->execute([$code]); $u=$q->fetch(); }
    if (!$u) json_out(['ok'=>false,'error'=>'Unknown code: '.$code],404);
    if ((int)$u['memo_id'] !== $mid) json_out(['ok'=>false,'wrong'=>true,'error'=>'Not on this memo: '.$u['serial']],409);
    if ($u['status'] === 'in_stock') json_out(['ok'=>false,'already'=>true,'serial'=>$u['serial'],'error'=>'Already scanned: '.$u['serial']],409);
    if ($u['status'] !== 'dispatched') json_out(['ok'=>false,'error'=>'Bottle status is '.$u['status']],409);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE stock_units SET status='in_stock', received_by=?, received_at=NOW() WHERE id=? AND status='dispatched'")->execute([$user['id'],(int)$u['id']]);
    $up = $pdo->prepare('UPDATE inventory SET qty=qty+1 WHERE product_id=?'); $up->execute([(int)$u['product_id']]);
    if ($up->rowCount()===0) $pdo->prepare('INSERT INTO inventory (product_id,qty) VALUES (?,1)')->execute([(int)$u['product_id']]);
    $pdo->prepare('UPDATE memo_items SET qty_received=qty_received+1 WHERE memo_id=? AND product_id=?')->execute([$mid,(int)$u['product_id']]);
    $pdo->commit();

    $pt=$pdo->prepare('SELECT title,variant_title FROM products WHERE id=?'); $pt->execute([(int)$u['product_id']]); $P=$pt->fetch();
    json_out(['ok'=>true,'serial'=>$u['serial'],'product_id'=>(int)$u['product_id'],'title'=>$P['title']??'','variant'=>$P['variant_title']??'']);
}

// ── Store: finalize receive → memo becomes the store invoice ──
if ($action === 'receive_finalize') {
    if (!is_store($user)) json_out(['ok'=>false,'error'=>'Store access only'],403);
    $mid = (int)($in['memo_id'] ?? 0);
    $total = max(0,(float)($in['invoice_total'] ?? 0));
    $mq=$pdo->prepare('SELECT * FROM memos WHERE id=?'); $mq->execute([$mid]); $M=$mq->fetch();
    if (!$M) json_out(['ok'=>false,'error'=>'Memo not found'],404);
    $pdo->prepare("UPDATE memos SET status='received', invoice_total=?, received_by=?, received_at=NOW() WHERE id=?")->execute([$total,$user['id'],$mid]);
    $rid=(int)$M['request_id'];
    $rem=$pdo->prepare("SELECT COUNT(*) FROM stock_units WHERE request_id=? AND status='dispatched'"); $rem->execute([$rid]);
    if ((int)$rem->fetchColumn()===0) $pdo->prepare("UPDATE stock_requests SET status='received' WHERE id=?")->execute([$rid]);
    $disc=$pdo->prepare("SELECT COUNT(*) FROM stock_units WHERE memo_id=? AND status='dispatched'"); $disc->execute([$mid]);
    json_out(['ok'=>true,'memo_no'=>$M['memo_no'],'not_received'=>(int)$disc->fetchColumn(),'invoice_total'=>$total]);
}

json_out(['ok'=>false,'error'=>'Unknown action'],400);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'Failed: '.$e->getMessage()],500);
}
