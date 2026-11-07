<?php
/* restock_lib.php — Auto restock engine: plan → memo → dispatch → barcode scan → cross-check → delay alerts.
   Everything lives in the POS database. Tables are created the first time they are needed (nothing to run by hand).
   Order of life of a memo:  draft → sent → partial (some pending) → dispatched (all sent, waiting for scan) → received → closed.
   Rules in one line: a bottle only COUNTS when it is scanned in (inventory.php). Barcodes made / parcels sent are only promises. */

function rs_setting(string $k, $d) { $v = function_exists('get_setting') ? get_setting($k) : ''; return ($v !== '' && $v !== null) ? $v : $d; }
function rs_cfg(): array {
    return [
        'cover'     => (float)rs_setting('rs_cover_days', 7),    // days of stock we want on the shelf
        'lead'      => (float)rs_setting('rs_lead_days', 3),     // days the supplier needs to send
        'cycle'     => (float)rs_setting('rs_cycle_days', 2),    // we order every N days
        'empty_min' => (int)rs_setting('rs_empty_min', 3),       // an EMPTY selling perfume is refilled with at least this many
        'slow_min'  => (int)rs_setting('rs_slow_min', 2),        // an EMPTY perfume that hardly sells
        'top_pct'   => (int)rs_setting('rs_top_pct', 20),        // best X% sellers get an extra 2-day buffer
        'over_days' => (int)rs_setting('rs_over_days', 30),      // more than this many days of stock = never order
        'disp_hrs'  => (int)rs_setting('rs_dispatch_hours', 48), // memo sent → supplier must dispatch within
        'scan_hrs'  => (int)rs_setting('rs_scan_hours', 24),     // parcel sent / barcode printed → must be scanned within
        'learn'     => rs_setting('rs_learn', '1') !== '0',      // learn the real rhythm (starts at the numbers above, then adjusts itself)
        'learned'   => false, 'dow' => [], 'gap' => null,         // filled by rs_learn_apply()
    ];
}

function ensure_restock_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    require_once __DIR__ . '/schema_flag.php';
    if (pos_schema_seen('restock')) return;
    // An older/other copy of these tables may already exist with different columns (then CREATE TABLE IF NOT EXISTS keeps the wrong shape
    // and every query fails with "Unknown column"). Such a table is renamed aside (its rows are kept) and made again in the right shape.
    $need = [
        'rs_orders' => ['id','code','status','source','note','created_by','created_at','sent_at','sent_by','closed_at'],
        'rs_items' => ['id','order_id','product_id','ordered_qty','dispatched_qty','cancelled_qty','reason','note','status','created_at'],
        'rs_ships' => ['id','order_id','item_id','qty','parcel_ref','note','by_user','by_name','created_at'],
        'rs_dispatchers' => ['user_id','added_at'],
    ];
    foreach ($need as $tbl => $cols) {
        try {
            $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl'")->fetchAll(PDO::FETCH_COLUMN);
            if ($have && array_diff($cols, array_map('strtolower', $have))) {
                $pdo->exec("RENAME TABLE $tbl TO {$tbl}_old_" . date('Ymd_His'));
                @error_log("[restock schema] $tbl had the wrong columns; renamed aside and recreated");
            }
        } catch (Throwable $e) { @error_log('[restock schema] ' . $e->getMessage()); }
    }
    // every step is its own try: one failing statement must never stop the others (nor break the POS)
    $steps = [
        "CREATE TABLE IF NOT EXISTS rs_orders (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'draft', source VARCHAR(8) NOT NULL DEFAULT 'auto',
            note VARCHAR(255) NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL, sent_by INT NULL, closed_at DATETIME NULL, KEY (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rs_items (
            id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL,
            ordered_qty INT NOT NULL DEFAULT 0, dispatched_qty INT NOT NULL DEFAULT 0, cancelled_qty INT NOT NULL DEFAULT 0,
            reason VARCHAR(14) NOT NULL DEFAULT 'manual', note VARCHAR(255) NULL, status VARCHAR(8) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id), KEY (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rs_ships (
            id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, item_id INT NOT NULL, qty INT NOT NULL,
            parcel_ref VARCHAR(60) NULL, note VARCHAR(255) NULL, by_user INT NULL, by_name VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (order_id), KEY (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rs_learn (
            day DATE PRIMARY KEY, cover DECIMAL(4,1) NOT NULL, lead_days DECIMAL(4,1) NOT NULL, gap DECIMAL(4,1) NULL,
            arrivals INT NOT NULL DEFAULT 0, lead_samples INT NOT NULL DEFAULT 0, dow TEXT NULL, learned TINYINT(1) NOT NULL DEFAULT 0, note VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rs_dispatchers (user_id INT PRIMARY KEY, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($steps as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) { @error_log('[restock schema] ' . $e->getMessage()); } }
    try {   // older copy of rs_learn named the column `lead` (a reserved word on MySQL 8) — rename it in place
        $lc = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rs_learn' AND COLUMN_NAME IN ('lead','lead_days')")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('lead', $lc, true) && !in_array('lead_days', $lc, true)) $pdo->exec("ALTER TABLE rs_learn CHANGE `lead` lead_days DECIMAL(4,1) NOT NULL");
    } catch (Throwable $e) { @error_log('[restock schema] ' . $e->getMessage()); }
    try {
        $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_units' AND COLUMN_NAME='rs_item_id'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$have) $pdo->exec("ALTER TABLE stock_units ADD COLUMN rs_item_id INT NULL, ADD KEY (rs_item_id)");
    } catch (Throwable $e) { @error_log('[restock schema] ' . $e->getMessage()); }
    try {   // remembered only when all five tables and the stock_units column really exist
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('rs_orders','rs_items','rs_ships','rs_learn','rs_dispatchers')")->fetchColumn();
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_units' AND COLUMN_NAME='rs_item_id'")->fetchColumn();
        if ($n === 5 && $c === 1) pos_schema_mark('restock');
    } catch (Throwable $e) { }
}

function rs_is_dispatcher(PDO $pdo, int $uid): bool {
    try { ensure_restock_schema($pdo); $s = $pdo->prepare('SELECT 1 FROM rs_dispatchers WHERE user_id=?'); $s->execute([$uid]); return (bool)$s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

/* ---------- the maths (pure: easy to test, no database) ----------
   $p = ['v14'=>bottles sold in last 14 days, 'v7'=>bottles sold in last 7 days, 'stock'=>in shelf now, 'on_order'=>promised & not scanned yet, 'top'=>bool] */
/* average weekday weight over the next $days days (dow = [1..7 => factor]; empty = 1) */
function rs_dow_mult(array $dow, float $days, int $startDow): float {
    if (!$dow) return 1.0; $n = max(1, (int)ceil($days)); $sum = 0.0;
    for ($i = 1; $i <= $n; $i++) $sum += (float)($dow[(($startDow - 1 + $i) % 7) + 1] ?? 1.0);
    return round($sum / $n, 3);
}

function rs_calc_line(array $p, array $c): array {
    $v14 = max(0, (float)$p['v14']) / 14.0;
    $v7  = max(0, (float)$p['v7']) / 7.0;
    $trend = ($v14 > 0) ? max(0.7, min(1.4, $v7 / $v14)) : 1.0;      // selling faster → order more, slower → order less
    $vel = $v14 * $trend;
    $have = (int)$p['stock'] + (int)$p['on_order'];
    $days = $vel > 0 ? round($p['stock'] / $vel, 1) : null;
    $horizon = $c['cover'] + $c['lead'] + (empty($c['learned']) ? $c['cycle'] : 0);   // once learned, the real arrival gap is already inside 'cover'
    $mult = rs_dow_mult($c['dow'] ?? [], $horizon, (int)($c['start_dow'] ?? date('N')));  // busy weekdays count more than quiet ones
    $target = (int)ceil($vel * $horizon * $mult) + (!empty($p['top']) ? (int)ceil($vel * 2) : 0);
    $need = $target - $have;
    if ($days !== null && $days > $c['over_days'] && (int)$p['on_order'] === 0) return ['qty' => 0, 'reason' => 'overstock', 'vel' => round($vel, 2), 'days' => $days, 'trend' => round($trend, 2)];
    if ($have <= 0) {                                                // EMPTY: always refill, even if it hardly sells
        $min = $vel > 0 ? $c['empty_min'] : $c['slow_min'];
        return ['qty' => max($need, $min), 'reason' => 'empty', 'vel' => round($vel, 2), 'days' => $days, 'trend' => round($trend, 2)];
    }
    if ($need <= 0) return ['qty' => 0, 'reason' => 'ok', 'vel' => round($vel, 2), 'days' => $days, 'trend' => round($trend, 2)];
    $why = !empty($p['top']) ? 'top_seller' : 'low_cover';
    return ['qty' => $need, 'reason' => $why, 'vel' => round($vel, 2), 'days' => $days, 'trend' => round($trend, 2)];
}

function rs_clean_title($t) {
    $t = (string)$t; $cut = null;
    foreach ([' - ', ' – ', ' — ', ' | ', ' / '] as $sep) { $i = mb_strpos($t, $sep); if ($i !== false) $cut = ($cut === null) ? $i : min($cut, $i); }
    if ($cut !== null) $t = mb_substr($t, 0, $cut);
    $t = preg_replace('/\s*[|\-–—\/]?\s*TM\s*Perfume\s*House\b\.?/iu', '', $t);
    $t = preg_replace('/\s*[|\-–—\/]?\s*TMPH\b\.?/iu', '', $t);
    $t = trim($t, " \t\n\r\0\x0B|-–—/"); return $t !== '' ? $t : 'Product';
}
function rs_size($v, $t) { return preg_match('/(\d+)\s*ml/i', ($v . ' ' . $t), $m) ? $m[1] : ''; }

/* promised but not yet scanned in, per product (draft memos do not count: they are still being edited) */
function rs_on_order_map(PDO $pdo): array {
    $m = [];
    $sql = "SELECT i.product_id pid, SUM(GREATEST(i.ordered_qty - i.cancelled_qty - COALESCE(r.n,0),0)) q
            FROM rs_items i JOIN rs_orders o ON o.id=i.order_id AND o.status NOT IN ('draft','closed','cancelled')
            LEFT JOIN (SELECT rs_item_id, COUNT(*) n FROM stock_units WHERE rs_item_id IS NOT NULL AND received_at IS NOT NULL AND status<>'void' GROUP BY rs_item_id) r ON r.rs_item_id=i.id
            WHERE i.status='open' GROUP BY i.product_id";
    foreach ($pdo->query($sql) as $r) $m[(int)$r['pid']] = (int)$r['q'];
    return $m;
}

/* every sellable perfume+size with what the plan would order */
function rs_suggest(PDO $pdo): array {
    $c = rs_learn_apply($pdo, rs_cfg());
    $s14 = []; foreach ($pdo->query("SELECT oi.product_id pid, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.created_at >= (CURDATE() - INTERVAL 14 DAY) GROUP BY oi.product_id") as $r) $s14[(int)$r['pid']] = (int)$r['q'];
    $s7  = []; foreach ($pdo->query("SELECT oi.product_id pid, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.created_at >= (CURDATE() - INTERVAL 7 DAY) GROUP BY oi.product_id") as $r) $s7[(int)$r['pid']] = (int)$r['q'];
    $stock = []; foreach ($pdo->query("SELECT product_id pid, COUNT(*) n FROM stock_units WHERE status='in_stock' GROUP BY product_id") as $r) $stock[(int)$r['pid']] = (int)$r['n'];
    $onord = rs_on_order_map($pdo);
    $prods = $pdo->query("SELECT id,title,variant_title,pos_sku FROM products WHERE status=1 AND pos_sku IS NOT NULL AND pos_sku<>''")->fetchAll(PDO::FETCH_ASSOC);
    $sold = array_filter($s14, function ($q) { return $q > 0; }); arsort($sold);
    $topN = max(1, (int)ceil(count($sold) * $c['top_pct'] / 100)); $top = array_flip(array_slice(array_keys($sold), 0, $topN));
    $rows = []; $rank = ['empty' => 0, 'top_seller' => 1, 'low_cover' => 2];
    foreach ($prods as $p) {
        $pid = (int)$p['id'];
        $r = rs_calc_line(['v14' => $s14[$pid] ?? 0, 'v7' => $s7[$pid] ?? 0, 'stock' => $stock[$pid] ?? 0, 'on_order' => $onord[$pid] ?? 0, 'top' => isset($top[$pid])], $c);
        if ($r['qty'] <= 0) continue;
        $rows[] = ['product_id' => $pid, 'name' => rs_clean_title($p['title']), 'size' => rs_size($p['variant_title'], $p['title']), 'sku' => $p['pos_sku'],
                   'in_stock' => $stock[$pid] ?? 0, 'on_order' => $onord[$pid] ?? 0, 'sold14' => $s14[$pid] ?? 0, 'qty' => $r['qty'], 'reason' => $r['reason'], 'vel' => $r['vel'], 'days' => $r['days'], 'trend' => $r['trend']];
    }
    usort($rows, function ($a, $b) use ($rank) { return [$rank[$a['reason']] ?? 9, $a['days'] ?? 999] <=> [$rank[$b['reason']] ?? 9, $b['days'] ?? 999]; });
    return $rows;
}

/* the one open draft (create it if none, add suggestions that are missing; never touches lines somebody edited) */
function rs_draft(PDO $pdo, int $uid, string $source = 'auto'): int {
    $id = (int)$pdo->query("SELECT id FROM rs_orders WHERE status='draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO rs_orders (code,status,source,created_by) VALUES ('','draft',?,?)")->execute([$source, $uid]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE rs_orders SET code=? WHERE id=?')->execute(['RO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
    }
    $have = []; foreach ($pdo->query("SELECT product_id FROM rs_items WHERE order_id=$id") as $r) $have[(int)$r['product_id']] = 1;
    $ins = $pdo->prepare("INSERT INTO rs_items (order_id,product_id,ordered_qty,reason) VALUES (?,?,?,?)");
    foreach (rs_suggest($pdo) as $s) if (empty($have[$s['product_id']])) $ins->execute([$id, $s['product_id'], $s['qty'], $s['reason']]);
    return $id;
}

/* an automatic draft every N days (the page that is opened first does it; no cron needed) */
function rs_auto_tick(PDO $pdo, int $uid): ?int {
    $c = rs_cfg();
    if ((int)$pdo->query("SELECT COUNT(*) FROM rs_orders WHERE status='draft'")->fetchColumn() > 0) return null;
    $last = $pdo->query("SELECT MAX(created_at) FROM rs_orders WHERE source='auto'")->fetchColumn();
    if ($last && (time() - strtotime($last)) < $c['cycle'] * 86400) return null;
    $id = rs_draft($pdo, $uid, 'auto');
    if ((int)$pdo->query("SELECT COUNT(*) FROM rs_items WHERE order_id=$id")->fetchColumn() === 0) { $pdo->exec("DELETE FROM rs_orders WHERE id=$id"); return null; }
    return $id;
}

/* a bottle was scanned into the shelf: tie it to the oldest dispatched line still waiting for this perfume */
function rs_on_scan(PDO $pdo, int $unitId, int $productId): void {
    try {
        ensure_restock_schema($pdo);
        $q = $pdo->prepare("SELECT i.id FROM rs_items i JOIN rs_orders o ON o.id=i.order_id AND o.status NOT IN ('draft','closed','cancelled')
            WHERE i.product_id=? AND i.status='open' AND i.dispatched_qty >
              (SELECT COUNT(*) FROM stock_units s WHERE s.rs_item_id=i.id AND s.received_at IS NOT NULL AND s.status<>'void')
            ORDER BY i.id ASC LIMIT 1");
        $q->execute([$productId]); $iid = (int)$q->fetchColumn();
        if ($iid) {
            $pdo->prepare('UPDATE stock_units SET rs_item_id=? WHERE id=? AND rs_item_id IS NULL')->execute([$iid, $unitId]);
            $oid = (int)$pdo->query("SELECT order_id FROM rs_items WHERE id=$iid")->fetchColumn(); if ($oid) rs_close_if_done($pdo, $oid);   // everything sent AND scanned → memo closes itself
        }
    } catch (Throwable $e) { /* scanning must never fail because of restock */ }
}

/* full memo(s) with lines, shipments and the cross-check numbers */
function rs_orders(PDO $pdo, string $where = '1=1', array $args = [], int $limit = 60): array {
    $st = $pdo->prepare("SELECT * FROM rs_orders WHERE $where ORDER BY id DESC LIMIT $limit"); $st->execute($args);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC); if (!$orders) return [];
    $ids = implode(',', array_map(function ($o) { return (int)$o['id']; }, $orders));
    $rec = []; foreach ($pdo->query("SELECT rs_item_id i, COUNT(*) n FROM stock_units WHERE rs_item_id IS NOT NULL AND received_at IS NOT NULL AND status<>'void' GROUP BY rs_item_id") as $r) $rec[(int)$r['i']] = (int)$r['n'];
    $lines = []; $it = $pdo->query("SELECT i.*, p.title, p.variant_title, p.pos_sku, (SELECT COUNT(*) FROM stock_units s WHERE s.product_id=i.product_id AND s.status='in_stock') in_stock,
            (SELECT MAX(created_at) FROM rs_ships h WHERE h.item_id=i.id) last_ship FROM rs_items i JOIN products p ON p.id=i.product_id WHERE i.order_id IN ($ids) ORDER BY p.title, i.id");
    foreach ($it as $r) {
        $iid = (int)$r['id']; $ord = (int)$r['ordered_qty']; $dis = (int)$r['dispatched_qty']; $can = (int)$r['cancelled_qty']; $got = $rec[$iid] ?? 0;
        $lines[(int)$r['order_id']][] = ['id' => $iid, 'product_id' => (int)$r['product_id'], 'name' => rs_clean_title($r['title']), 'size' => rs_size($r['variant_title'], $r['title']), 'sku' => $r['pos_sku'],
            'ordered' => $ord, 'cancelled' => $can, 'dispatched' => $dis, 'received' => $got, 'pending' => max(0, $ord - $can - $dis), 'in_transit' => max(0, $dis - $got),
            'reason' => $r['reason'], 'note' => $r['note'], 'in_stock' => (int)$r['in_stock'], 'last_ship' => $r['last_ship'], 'status' => $r['status']];
    }
    $ships = []; foreach ($pdo->query("SELECT * FROM rs_ships WHERE order_id IN ($ids) ORDER BY id") as $r) $ships[(int)$r['order_id']][] = $r;
    foreach ($orders as &$o) {
        $oid = (int)$o['id']; $o['lines'] = $lines[$oid] ?? []; $o['ships'] = $ships[$oid] ?? [];
        $t = ['ordered' => 0, 'dispatched' => 0, 'received' => 0, 'pending' => 0, 'in_transit' => 0];
        foreach ($o['lines'] as $l) { if ($l['status'] === 'removed') continue; $t['ordered'] += $l['ordered'] - $l['cancelled']; $t['dispatched'] += $l['dispatched']; $t['received'] += $l['received']; $t['pending'] += $l['pending']; $t['in_transit'] += $l['in_transit']; }
        $o['totals'] = $t; $o['live'] = rs_status($o['status'], $t);
    }
    return $orders;
}
function rs_status(string $status, array $t): string {
    if (in_array($status, ['draft', 'closed', 'cancelled'], true)) return $status;
    if ($t['ordered'] > 0 && $t['dispatched'] === 0) return 'sent';
    if ($t['pending'] > 0) return 'partial';
    if ($t['in_transit'] > 0) return 'dispatched';
    return 'received';
}
function rs_close_if_done(PDO $pdo, int $oid): void {
    $o = rs_orders($pdo, 'id=?', [$oid], 1); if (!$o) return; $o = $o[0];
    if (in_array($o['status'], ['sent'], true) && $o['live'] === 'received') $pdo->prepare("UPDATE rs_orders SET status='closed', closed_at=NOW() WHERE id=?")->execute([$oid]);
}

/* WhatsApp / print text for a memo — shows ordered, already sent, still pending and what is waiting from older memos */
function rs_memo_text(array $o): string {
    $t = "*TM Perfume House — Order {$o['code']}*\n" . date('d M Y', strtotime($o['sent_at'] ?: $o['created_at'])) . "\n\n"; $n = 0;
    foreach ($o['lines'] as $l) { if ($l['status'] === 'removed') continue; $n++;
        $t .= $n . '. ' . $l['name'] . ($l['size'] ? ' · ' . $l['size'] . 'ml' : '') . ' × ' . ($l['ordered'] - $l['cancelled']);
        if ($l['dispatched']) $t .= ' (sent ' . $l['dispatched'] . ', pending ' . $l['pending'] . ')';
        $t .= "\n"; }
    return $t . "\nTotal: {$o['totals']['ordered']} bottles · pending {$o['totals']['pending']}";
}

/* everything that is late or does not add up. 'to' says who must act. Used by the page, and the dispatcher. */
function rs_alerts(PDO $pdo): array {
    $c = rs_cfg(); $out = []; $now = time();
    foreach (rs_orders($pdo, "status NOT IN ('closed','cancelled')", [], 100) as $o) {
        $age = function ($ts) use ($now) { return $ts ? max(0, (int)floor(($now - strtotime($ts)) / 3600)) : 0; };
        if ($o['status'] === 'draft') { if ($age($o['created_at']) >= 24) $out[] = ['type' => 'draft_not_sent', 'sev' => 'amber', 'to' => 'admin', 'ref' => $o['code'], 'title' => $o['code'] . ' is ready but not sent', 'detail' => 'Waiting ' . $age($o['created_at']) . ' h for you to review and send.', 'hours' => $age($o['created_at'])]; continue; }
        if ($o['totals']['pending'] > 0 && $age($o['sent_at']) >= $c['disp_hrs']) {
            $lastShip = 0; foreach ($o['ships'] as $s) $lastShip = max($lastShip, strtotime($s['created_at']));
            $wait = $lastShip ? (int)floor(($now - $lastShip) / 3600) : $age($o['sent_at']);
            if ($wait >= $c['disp_hrs']) $out[] = ['type' => 'dispatch_late', 'sev' => 'red', 'to' => 'dispatch', 'ref' => $o['code'], 'title' => $o['code'] . ': ' . $o['totals']['pending'] . ' bottles still pending', 'detail' => 'Sent ' . $age($o['sent_at']) . ' h ago, nothing dispatched for ' . $wait . ' h.', 'hours' => $wait];
        }
        foreach ($o['lines'] as $l) {
            if ($l['status'] === 'removed' || $l['in_transit'] <= 0 || !$l['last_ship']) continue;
            $h = $age($l['last_ship']);
            if ($h >= $c['scan_hrs']) $out[] = ['type' => 'not_scanned', 'sev' => $h >= $c['scan_hrs'] * 2 ? 'red' : 'amber', 'to' => 'store', 'ref' => $o['code'], 'title' => $l['name'] . ' ' . $l['size'] . 'ml: ' . $l['in_transit'] . ' sent, not scanned in', 'detail' => 'Dispatched ' . $h . ' h ago — received/scanned ' . $l['received'] . ' of ' . $l['dispatched'] . '. Parcel missing, or stickers not scanned.', 'hours' => $h];
        }
    }
    try {   // optional: printed-but-not-scanned barcodes (needs the old POS barcode columns; if they are not there the rest still works)
        $st = $pdo->prepare("SELECT p.title, p.variant_title, COUNT(*) n, MIN(su.printed_at) since FROM stock_units su JOIN products p ON p.id=su.product_id
            WHERE su.status='ready' AND su.printed_at IS NOT NULL AND su.printed_at < (NOW() - INTERVAL ? HOUR) GROUP BY su.product_id, p.title, p.variant_title");
        $st->execute([$c['scan_hrs']]);
        foreach ($st as $r) $out[] = ['type' => 'barcode_not_scanned', 'sev' => 'amber', 'to' => 'store', 'ref' => '', 'title' => rs_clean_title($r['title']) . ' ' . rs_size($r['variant_title'], $r['title']) . 'ml: ' . $r['n'] . ' barcodes printed, not scanned', 'detail' => 'Printed since ' . $r['since'] . '. Only scanned bottles count as stock.', 'hours' => (int)floor(($now - strtotime($r['since'])) / 3600)];
    } catch (Throwable $e) { @error_log('[restock alerts] ' . $e->getMessage()); }
    $empty = 0; foreach (rs_suggest($pdo) as $s) if ($s['reason'] === 'empty') $empty++;
    if ($empty) $out[] = ['type' => 'empty_open', 'sev' => 'amber', 'to' => 'admin', 'ref' => '', 'title' => $empty . ' perfumes are empty with nothing on order', 'detail' => 'They are in the next plan; open Restock → Plan and send.', 'hours' => 0];
    usort($out, function ($a, $b) { return [$a['sev'] === 'red' ? 0 : 1, -$a['hours']] <=> [$b['sev'] === 'red' ? 0 : 1, -$b['hours']]; });
    return $out;
}


/* ================= LEARNING =================
   Starts with the numbers in Settings (7 days cover, 3 days lead). Every day it looks at what really happened:
   - ARRIVALS: on which days did 3+ bottles get scanned in? the average gap between those days = how often stock really comes
   - LEAD: hours from "memo sent" to the first scanned bottle of that line
   - WEEKDAYS: which days of the week sell more
   cover = gap + 1.5 days (stock every 2 days → keep ~3.5 days; every 6 days → ~7.5), clamped 3..10. lead = real average, clamped 1..7.
   It needs at least 3 delivery days to trust itself; before that the defaults stay. Result is saved once a day in rs_learn (a visible log). */
function rs_learn_compute(PDO $pdo): array {
    $c = rs_cfg(); $res = ['cover' => $c['cover'], 'lead' => $c['lead'], 'gap' => null, 'arrivals' => 0, 'lead_samples' => 0, 'dow' => [], 'learned' => 0, 'note' => 'Using the starting numbers (not enough history yet).'];
    $days = $pdo->query("SELECT DATE(received_at) d, COUNT(*) n FROM stock_units WHERE received_at >= (CURDATE() - INTERVAL 30 DAY) AND status NOT IN ('void','tester') GROUP BY DATE(received_at) HAVING n >= 3 ORDER BY d")->fetchAll(PDO::FETCH_COLUMN);
    $res['arrivals'] = count($days);
    if (count($days) >= 3) {
        $g = []; for ($i = 1; $i < count($days); $i++) $g[] = max(1, (strtotime($days[$i]) - strtotime($days[$i - 1])) / 86400);
        $gap = array_sum($g) / count($g); $res['gap'] = round($gap, 1);
        $res['cover'] = round(max(3, min(10, $gap + 1.5)) * 2) / 2; $res['learned'] = 1;
        $res['note'] = 'Stock arrives about every ' . $res['gap'] . ' days → keep ' . $res['cover'] . ' days on the shelf.';
    }
    $ls = $pdo->query("SELECT TIMESTAMPDIFF(HOUR, MIN(o.sent_at), MIN(s.received_at)) h FROM rs_items i JOIN rs_orders o ON o.id=i.order_id AND o.sent_at IS NOT NULL
        JOIN stock_units s ON s.rs_item_id=i.id AND s.received_at IS NOT NULL WHERE o.sent_at >= (NOW() - INTERVAL 45 DAY) GROUP BY i.id")->fetchAll(PDO::FETCH_COLUMN);
    $ls = array_filter(array_map('floatval', $ls), function ($h) { return $h >= 0; }); $res['lead_samples'] = count($ls);
    if (count($ls) >= 3) { $res['lead'] = round(max(1, min(7, (array_sum($ls) / count($ls)) / 24)) * 2) / 2; $res['learned'] = 1; $res['note'] .= ' Memo → shelf takes about ' . $res['lead'] . ' days.'; }
    $rows = $pdo->query("SELECT DAYOFWEEK(o.created_at) dw, COUNT(DISTINCT DATE(o.created_at)) nd, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.created_at >= (CURDATE() - INTERVAL 56 DAY) GROUP BY dw")->fetchAll(PDO::FETCH_ASSOC);
    $per = []; foreach ($rows as $r) if ((int)$r['nd'] >= 2) $per[(int)$r['dw']] = $r['q'] / $r['nd'];   // MySQL: 1=Sunday..7=Saturday
    if (count($per) === 7) { $mean = array_sum($per) / 7; if ($mean > 0) foreach ($per as $mdw => $v) { $iso = $mdw === 1 ? 7 : $mdw - 1; $res['dow'][$iso] = round(max(0.5, min(1.8, $v / $mean)), 2); } }
    return $res;
}
function rs_learn_apply(PDO $pdo, array $c): array {
    if (!$c['learn']) return $c;
    try {
        ensure_restock_schema($pdo);
        $row = $pdo->query("SELECT * FROM rs_learn WHERE day=CURDATE()")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $r = rs_learn_compute($pdo);
            $pdo->prepare("REPLACE INTO rs_learn (day,cover,lead_days,gap,arrivals,lead_samples,dow,learned,note) VALUES (CURDATE(),?,?,?,?,?,?,?,?)")
                ->execute([$r['cover'], $r['lead'], $r['gap'], $r['arrivals'], $r['lead_samples'], json_encode($r['dow']), $r['learned'], $r['note']]);
            $row = $pdo->query("SELECT * FROM rs_learn WHERE day=CURDATE()")->fetch(PDO::FETCH_ASSOC);
        }
        if ($row && (int)$row['learned'] === 1) { $c['cover'] = (float)$row['cover']; $c['lead'] = (float)$row['lead_days']; $c['learned'] = true; $c['gap'] = $row['gap']; }
        $c['dow'] = $row ? (json_decode($row['dow'] ?: '[]', true) ?: []) : [];
        if (is_array($c['dow'])) { $fix = []; foreach ($c['dow'] as $k => $v) $fix[(int)$k] = (float)$v; $c['dow'] = $fix; }
    } catch (Throwable $e) { /* planning still works with the starting numbers */ }
    return $c;
}
