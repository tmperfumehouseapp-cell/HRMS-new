<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$user = require_login(true);

$in = json_decode(file_get_contents('php://input'), true);
$pid = (int)($in['product_id'] ?? 0);
$qty = (int)($in['qty'] ?? -1);

if ($pid <= 0 || $qty < 0) json_out(['ok' => false, 'error' => 'Invalid input'], 400);

$st = $pdo->prepare('SELECT COALESCE(qty,0) FROM inventory WHERE product_id = ?');
$st->execute([$pid]);
$current = $st->fetchColumn();
$isNew = ($current === false);
$current = (int)$current;

$pdo->prepare('INSERT INTO inventory (product_id, qty, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_by = VALUES(updated_by)')
    ->execute([$pid, $qty, $user['id']]);

$change = $qty - $current;
$type = ($isNew || $current === 0) ? 'opening' : 'adjustment';
if ($change !== 0) {
    $pdo->prepare('INSERT INTO inventory_log (product_id, change_qty, type, user_id) VALUES (?, ?, ?, ?)')
        ->execute([$pid, $change, $type, $user['id']]);
}

json_out(['ok' => true, 'qty' => $qty]);
