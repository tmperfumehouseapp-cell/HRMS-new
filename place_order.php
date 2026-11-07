<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/biller_schema.php';
$user = require_login(true);
ensure_billers_schema($pdo);

$in = json_decode(file_get_contents('php://input'), true);
if (!$in) json_out(['ok' => false, 'error' => 'Invalid request'], 400);

$items = $in['items'] ?? [];
$custName = trim($in['customer_name'] ?? '');
$custPhone = preg_replace('/\D/', '', $in['customer_phone'] ?? '');
$custDob = $in['customer_dob'] ?? null;
$discount = max(0, (float)($in['discount'] ?? 0));
$payMode = in_array(($in['payment_mode'] ?? ''), ['cash','online','split'], true) ? $in['payment_mode'] : 'cash';
$payModeId = (int)($in['payment_mode_id'] ?? 0);
$txnId = trim($in['transaction_id'] ?? '');
$cashAmt   = max(0, (float)($in['cash_amt'] ?? 0));
$onlineAmt = max(0, (float)($in['online_amt'] ?? 0));
$payProof  = $in['pay_proof'] ?? '';

if (empty($items)) json_out(['ok' => false, 'error' => 'Cart is empty'], 400);

// "Billed by" is mandatory — the bill cannot be created without a name from the staff list
$billerId = (int)($in['biller_id'] ?? 0);
$bq = $pdo->prepare('SELECT id, name FROM billers WHERE id = ? AND active = 1');
$bq->execute([$billerId]);
$biller = $bq->fetch();
if (!$biller) json_out(['ok' => false, 'error' => 'Select who is billing (Billed by)'], 400);
if ($custName === '' || strlen($custPhone) < 10) json_out(['ok' => false, 'error' => 'Customer name and valid phone required'], 400);
if (strlen($custPhone) === 10) $custPhone = '91' . $custPhone;
if ($custDob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custDob)) $custDob = null;

// payment_mode_id is the ONLINE mode (UPI/Card…) used for the online portion
if ($payModeId > 0) {
    $pm = $pdo->prepare('SELECT COUNT(*) FROM payment_modes WHERE id = ?');
    $pm->execute([$payModeId]);
    if (!$pm->fetchColumn()) $payModeId = 0;
}

try {
    $pdo->beginTransaction();

    // Customer: find or create by phone
    $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
    $st->execute([$custPhone]);
    $customerId = $st->fetchColumn();

    if ($customerId) {
        $pdo->prepare('UPDATE customers SET name = ?, dob = COALESCE(?, dob) WHERE id = ?')
            ->execute([$custName, $custDob, $customerId]);
    } else {
        $pdo->prepare('INSERT INTO customers (name, phone, dob) VALUES (?, ?, ?)')
            ->execute([$custName, $custPhone, $custDob]);
        $customerId = (int)$pdo->lastInsertId();
    }

    // Validate items against DB prices (never trust client)
    $getProduct = $pdo->prepare('SELECT id, title, variant_title, price, hsn, gst_rate FROM products WHERE id = ? AND status = 1');
    $subtotal = 0;
    $lines = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = max(1, (int)($it['qty'] ?? 1));
        $unitIds = array_values(array_filter(array_map('intval', (array)($it['unit_ids'] ?? []))));
        $getProduct->execute([$pid]);
        $p = $getProduct->fetch();
        if (!$p) throw new Exception('Product not found: ' . $pid);
        $lineTotal = $p['price'] * $qty;
        $subtotal += $lineTotal;
        $lines[] = ['p' => $p, 'qty' => $qty, 'line_total' => $lineTotal, 'unit_ids' => $unitIds];
    }

    // ── Perfume price-chart: replace perfume prices with the chosen chart column ──
    require_once __DIR__ . '/../includes/ml_pricing.php';
    $pMl  = isset($in['perfume_ml'])  ? (int)$in['perfume_ml']  : 0;
    $pCol = (isset($in['perfume_col']) && $in['perfume_col'] !== null) ? (int)$in['perfume_col'] : -1;
    if ($pMl >= 100 && $pMl <= 600 && $pCol >= 0 && $pCol <= 6) {
        $chart = json_decode(@file_get_contents(__DIR__ . '/../assets/price_chart.json'), true) ?: [];
        $key = (string)((int)(round($pMl / 10) * 10));
        if (isset($chart[$key])) {
            $row = $chart[$key];              // 6 prices
            $idx = min($pCol, 5);             // col 6 "Lowest" uses the 3% price
            $tierPrice = (float)$row[$idx];
            // actual perfume ml in the cart (guard against a faked ml value)
            $mlSum = 0;
            foreach ($lines as $l) {
                if (is_perfume($l['p']['title'], $l['p']['variant_title'])) {
                    $mlSum += parse_ml($l['p']['variant_title'] ?: $l['p']['title']) * $l['qty'];
                }
            }
            $rounded = (int)(round($mlSum / 10) * 10);
            if ($mlSum > 0 && abs($rounded - (int)$key) <= 20) {
                // apportion the chart price across perfume lines by their ml share
                $newSubtotal = 0; $assigned = 0; $lastPerfumeIdx = -1;
                foreach ($lines as $i2 => $l) {
                    if (is_perfume($l['p']['title'], $l['p']['variant_title'])) $lastPerfumeIdx = $i2;
                }
                foreach ($lines as $i2 => &$l) {
                    if (is_perfume($l['p']['title'], $l['p']['variant_title'])) {
                        $lineMl = parse_ml($l['p']['variant_title'] ?: $l['p']['title']) * $l['qty'];
                        if ($i2 === $lastPerfumeIdx) { $share = $tierPrice - $assigned; }
                        else { $share = round($tierPrice * ($lineMl / $mlSum), 2); $assigned += $share; }
                        $l['line_total'] = $share;
                        $l['p']['price'] = ($l['qty'] > 0) ? round($share / $l['qty'], 2) : $share;
                    }
                }
                unset($l);
                foreach ($lines as $l) $newSubtotal += $l['line_total'];
                $subtotal = $newSubtotal;
            }
        }
    }

    if ($discount > $subtotal) $discount = $subtotal;
    $total = $subtotal - $discount;

    // ── Payment split: cash + online must equal total; online needs a screenshot ──
    if ($payMode === 'cash')       { $cashAmt = $total; $onlineAmt = 0; }
    elseif ($payMode === 'online') { $onlineAmt = $total; $cashAmt = 0; }
    else { // split
        if (abs(($cashAmt + $onlineAmt) - $total) > 1) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'Cash + Online must equal ₹'.round($total)],400); }
    }
    $proofFile = null;
    if ($onlineAmt > 0) {
        if ($payProof === '') { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'Online payment needs a screenshot'],400); }
        if (preg_match('#^data:image/(\w+);base64,#', $payProof, $pm0)) {
            $ext = strtolower($pm0[1]); if ($ext === 'jpeg') $ext = 'jpg';
            if (!in_array($ext, ['jpg','png','webp'])) $ext = 'jpg';
            $raw = base64_decode(substr($payProof, strpos($payProof, ',') + 1));
            if ($raw !== false) {
                $pdir = __DIR__ . '/../invoices/proofs'; if (!is_dir($pdir)) @mkdir($pdir, 0755, true);
                $pfn = 'proof_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
                if (@file_put_contents($pdir . '/' . $pfn, $raw) !== false) $proofFile = 'proofs/' . $pfn;
            }
        }
    }

    // Invoice number
    $prefix = get_setting('invoice_prefix') ?: 'TMPOS';
    $next = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() + 1;
    $invoiceNo = $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE invoice_no = ?');
    while (true) {
        $chk->execute([$invoiceNo]);
        if ((int)$chk->fetchColumn() === 0) break;
        $next++;
        $invoiceNo = $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    // Order
    $pdo->prepare('INSERT INTO orders (invoice_no, customer_id, user_id, subtotal, discount, total, payment_mode, payment_mode_id, transaction_id, cash_amt, online_amt, pay_proof, biller_id, billed_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$invoiceNo, $customerId, $user['id'], $subtotal, $discount, $total, $payMode, $payModeId ?: null, $txnId !== '' ? $txnId : null, $cashAmt, $onlineAmt, $proofFile, (int)$biller['id'], $biller['name']]);
    $orderId = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, variant_title, price, qty, line_total, hsn, gst_rate) VALUES (?,?,?,?,?,?,?,?,?)');
    $dedStock = $pdo->prepare('UPDATE inventory SET qty = qty - ? WHERE product_id = ?');
    $logStock = $pdo->prepare('INSERT INTO inventory_log (product_id, change_qty, type, ref_order_id, user_id) VALUES (?, ?, "sale", ?, ?)');
    // Serialized bottles: flip in_stock -> sold. WHERE status='in_stock' blocks double-selling.
    $markUnit = $pdo->prepare("UPDATE stock_units SET status = 'sold', order_id = ?, sold_by = ?, sold_at = NOW() WHERE id = ? AND status = 'in_stock'");
    // Does this product have barcoded bottles at all?
    $isMgd    = $pdo->prepare('SELECT 1 FROM stock_units WHERE product_id = ? LIMIT 1');

    foreach ($lines as $l) {
        $pid  = (int)$l['p']['id'];
        $need = (int)$l['qty'];
        // bottles already chosen by scanning (if any)
        $unitIds = array_values(array_filter(array_map('intval', (array)($l['unit_ids'] ?? []))));

        // Barcode-tracked = the product has stock_units bottles.
        // Scanned bottles are used first; any shortfall is AUTO-PICKED (oldest in_stock first),
        // so a product added by SKU/name search bills without scanning. Never oversells.
        $isMgd->execute([$pid]);
        if ($isMgd->fetchColumn()) {
            if (count($unitIds) > $need) $unitIds = array_slice($unitIds, 0, $need);
            $missing = $need - count($unitIds);
            if ($missing > 0) {
                $notIn = $unitIds ? (' AND id NOT IN (' . implode(',', array_map('intval', $unitIds)) . ')') : '';
                // FOR UPDATE locks the picked rows so two counters can't grab the same bottle
                $pickSql = "SELECT id FROM stock_units
                            WHERE product_id = " . $pid . " AND status = 'in_stock'" . $notIn . "
                            ORDER BY id ASC LIMIT " . (int)$missing . " FOR UPDATE";
                $picked = $pdo->query($pickSql)->fetchAll(PDO::FETCH_COLUMN);
                if (count($picked) < $missing) {
                    $have = count($unitIds) + count($picked);
                    throw new Exception('"' . $l['p']['title'] . '" — only ' . $have . ' in stock, need ' . $need . '.');
                }
                foreach ($picked as $pk) $unitIds[] = (int)$pk;
            }
        }

        $insItem->execute([$orderId, $pid, $l['p']['title'], $l['p']['variant_title'], $l['p']['price'], $need, $l['line_total'], $l['p']['hsn'], $l['p']['gst_rate']]);
        $dedStock->execute([$need, $pid]);
        $logStock->execute([$pid, -$need, $orderId, $user['id']]);

        // Flip every chosen bottle in_stock -> sold; abort the whole order if one was already taken
        foreach ($unitIds as $uid) {
            $markUnit->execute([$orderId, $user['id'], $uid]);
            if ($markUnit->rowCount() === 0) {
                throw new Exception('A bottle for "' . $l['p']['title'] . '" was just sold elsewhere. Order cancelled — please try again.');
            }
        }
    }

    $pdo->prepare('UPDATE customers SET total_orders = total_orders + 1, total_spent = total_spent + ? WHERE id = ?')
        ->execute([$total, $customerId]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Order failed: ' . $e->getMessage()], 500);
}

// ── Generate PDF invoice (order stays even if PDF/WA fails) ──
$pdfOk = false;
$pdfUrl = '';
try {
    require __DIR__ . '/../invoice_pdf.php';
    generate_invoice_pdf($invoiceNo);
    $scheme = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
    $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI'] ?? '/tmpos/api/x'));
    $pdfUrl = rtrim($base, '/') . '/invoices/' . rawurlencode($invoiceNo) . '.pdf';
    $pdfOk = true;
} catch (Exception $e) {
    $pdfUrl = '';
}

// ── WhatsApp via AiSensy: media (document) template with PDF attached ──
$waSent = false;
$waError = '';
$apiKey = get_setting('aisensy_api_key');
$campaign = get_setting('aisensy_campaign_name');

if ($apiKey && $campaign) {
    $payload = [
        'apiKey' => $apiKey,
        'campaignName' => $campaign,
        'destination' => $custPhone,
        'userName' => 'TM Perfume House',
        'templateParams' => [
            $custName,                      // {{1}} customer name
            $invoiceNo,                     // {{2}} invoice no
            number_format($total, 0),       // {{3}} amount
        ],
    ];
    if ($pdfOk) {
        $payload['media'] = [
            'url' => $pdfUrl,
            'filename' => $invoiceNo . '.pdf',
        ];
    }

    $ch = curl_init('https://backend.aisensy.com/campaign/t1/api/v2');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $waSent = true;
        $pdo->prepare('UPDATE orders SET whatsapp_sent = 1 WHERE id = ?')->execute([$orderId]);
    } else {
        $waError = 'AiSensy HTTP ' . $code . ' ' . substr((string)$resp, 0, 120);
    }
} else {
    $waError = 'AiSensy not configured in Settings';
}

json_out([
    'ok' => true,
    'order_id' => $orderId,
    'invoice_no' => $invoiceNo,
    'total' => $total,
    'pdf_url' => $pdfUrl,
    'whatsapp_sent' => $waSent,
    'whatsapp_error' => $waError,
]);