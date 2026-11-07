<?php
// Deletes an order, its line items, and restores the sold stock back to inventory.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin(true);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = (int)($in['order_id'] ?? 0);
if ($orderId <= 0) json_out(['ok' => false, 'error' => 'Invalid order id'], 400);

// Confirm the order exists
$chk = $pdo->prepare('SELECT invoice_no FROM orders WHERE id = ?');
$chk->execute([$orderId]);
$order = $chk->fetch();
if (!$order) json_out(['ok' => false, 'error' => 'Order not found'], 404);

try {
    $pdo->beginTransaction();

    // Restore stock for each line item (add qty back to inventory)
    $items = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $rows = $items->fetchAll();

    $restore = $pdo->prepare('UPDATE inventory SET qty = qty + ? WHERE product_id = ?');
    $restored = 0;
    foreach ($rows as $it) {
        $pid = (int)$it['product_id'];
        $q   = (int)$it['qty'];
        if ($pid > 0 && $q > 0) { $restore->execute([$q, $pid]); $restored += $q; }
    }

    // Delete line items then the order
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);

    $pdo->commit();
    json_out(['ok' => true, 'invoice_no' => $order['invoice_no'], 'stock_restored' => $restored]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Delete failed: ' . $e->getMessage()], 500);
}
