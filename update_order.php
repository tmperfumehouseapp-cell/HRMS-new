<?php
// api/update_order.php — edit an existing order, adjust stock by the diff,
// optionally resend the WhatsApp invoice.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$user = require_login(true);

$in = json_decode(file_get_contents('php://input'), true);
if (!$in) json_out(['ok' => false, 'error' => 'Invalid request'], 400);

$orderId  = (int)($in['order_id'] ?? 0);
$items    = $in['items'] ?? [];
$custName = trim($in['customer_name'] ?? '');
$custPhone = preg_replace('/\D/', '', $in['customer_phone'] ?? '');
$discount = max(0, (float)($in['discount'] ?? 0));
$payMode  = ($in['payment_mode'] ?? '') === 'cash' ? 'cash' : 'online';
$resendWa = !empty($in['resend_whatsapp']);

if ($orderId <= 0) json_out(['ok' => false, 'error' => 'Invalid order id'], 400);
if (empty($items)) json_out(['ok' => false, 'error' => 'Cart is empty'], 400);
if ($custName === '' || strlen($custPhone) < 10) json_out(['ok' => false, 'error' => 'Customer name and valid phone required'], 400);
if (strlen($custPhone) === 10) $custPhone = '91' . $custPhone;

try {
    $pdo->beginTransaction();

    // Existing order
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) throw new Exception('Order not found');
    $oldTotal   = (float)$order['total'];
    $oldCustId  = (int)$order['customer_id'];
    $invoiceNo  = $order['invoice_no'];

    // Restore stock from the OLD items
    $oldItems = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ?');
    $oldItems->execute([$orderId]);
    $restore = $pdo->prepare('UPDATE inventory SET qty = qty + ? WHERE product_id = ?');
    foreach ($oldItems->fetchAll() as $oi) {
        $pid = (int)$oi['product_id']; $q = (int)$oi['qty'];
        if ($pid > 0 && $q > 0) $restore->execute([$q, $pid]);
    }

    // Customer: keep the same record, update name; if phone changed, move to the right customer
    $findCust = $pdo->prepare('SELECT id FROM customers WHERE phone = ?');
    $findCust->execute([$custPhone]);
    $customerId = (int)$findCust->fetchColumn();
    if ($customerId) {
        $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute([$custName, $customerId]);
    } else {
        $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)')->execute([$custName, $custPhone]);
        $customerId = (int)$pdo->lastInsertId();
    }

    // Validate NEW items against DB prices (each product charged its own price)
    $getProduct = $pdo->prepare('SELECT id, title, variant_title, price, hsn, gst_rate FROM products WHERE id = ?');
    $subtotal = 0; $lines = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = max(1, (int)($it['qty'] ?? 1));
        $getProduct->execute([$pid]);
        $p = $getProduct->fetch();
        if (!$p) throw new Exception('Product not found: ' . $pid);
        $lineTotal = $p['price'] * $qty;
        $subtotal += $lineTotal;
        $lines[] = ['p' => $p, 'qty' => $qty, 'line_total' => $lineTotal];
    }

    if ($discount > $subtotal) $discount = $subtotal;
    $total = $subtotal - $discount;

    // Replace order items
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
    $insItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, title, variant_title, price, qty, line_total, hsn, gst_rate) VALUES (?,?,?,?,?,?,?,?,?)');
    $dedStock = $pdo->prepare('UPDATE inventory SET qty = qty - ? WHERE product_id = ?');
    foreach ($lines as $l) {
        $insItem->execute([$orderId, $l['p']['id'], $l['p']['title'], $l['p']['variant_title'], $l['p']['price'], $l['qty'], $l['line_total'], $l['p']['hsn'], $l['p']['gst_rate']]);
        $dedStock->execute([$l['qty'], $l['p']['id']]);
    }

    // Update order header
    $pdo->prepare('UPDATE orders SET customer_id = ?, subtotal = ?, discount = ?, total = ?, payment_mode = ? WHERE id = ?')
        ->execute([$customerId, $subtotal, $discount, $total, $payMode, $orderId]);

    // Adjust customer spend totals (move the order between customers if phone changed)
    if ($oldCustId && $oldCustId !== $customerId) {
        $pdo->prepare('UPDATE customers SET total_orders = GREATEST(total_orders - 1, 0), total_spent = GREATEST(total_spent - ?, 0) WHERE id = ?')
            ->execute([$oldTotal, $oldCustId]);
        $pdo->prepare('UPDATE customers SET total_orders = total_orders + 1, total_spent = total_spent + ? WHERE id = ?')
            ->execute([$total, $customerId]);
    } else {
        // same customer: apply the delta
        $pdo->prepare('UPDATE customers SET total_spent = GREATEST(total_spent - ? + ?, 0) WHERE id = ?')
            ->execute([$oldTotal, $total, $customerId]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Update failed: ' . $e->getMessage()], 500);
}

// ── Optional: regenerate PDF + resend WhatsApp ──
$waSent = false; $waError = ''; $pdfUrl = '';
if ($resendWa) {
    $pdfOk = false;
    try {
        require __DIR__ . '/../invoice_pdf.php';
        generate_invoice_pdf($invoiceNo);
        $scheme = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['REQUEST_URI'] ?? '/tmpos/api/x'));
        $pdfUrl = rtrim($base, '/') . '/invoices/' . rawurlencode($invoiceNo) . '.pdf';
        $pdfOk = true;
    } catch (Exception $e) { $pdfUrl = ''; }

    $apiKey = get_setting('aisensy_api_key');
    $campaign = get_setting('aisensy_campaign_name');
    if ($apiKey && $campaign) {
        $payload = [
            'apiKey' => $apiKey,
            'campaignName' => $campaign,
            'destination' => $custPhone,
            'userName' => 'TM Perfume House',
            'templateParams' => [$custName, $invoiceNo, number_format($total, 0)],
        ];
        if ($pdfOk) $payload['media'] = ['url' => $pdfUrl, 'filename' => $invoiceNo . '.pdf'];

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
}

json_out([
    'ok' => true,
    'order_id' => $orderId,
    'invoice_no' => $invoiceNo,
    'total' => $total,
    'whatsapp_sent' => $waSent,
    'whatsapp_error' => $waError,
]);
