<?php
// Assigns a unique EAN-13 barcode to every variant that lacks one.
// Barcode = 890 (India GS1 prefix) + zero-padded product id (9 digits) + EAN-13 check digit.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin(true);
set_time_limit(300);

function ean13_check(string $d12): string {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $n = (int)$d12[$i];
        $sum += ($i % 2 === 0) ? $n : $n * 3;
    }
    $c = (10 - ($sum % 10)) % 10;
    return $d12 . $c;
}

$force = isset($_GET['force']) && $_GET['force'] === '1';
$sql = $force ? 'SELECT id FROM products' : "SELECT id FROM products WHERE barcode IS NULL OR barcode = ''";
$ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

$upd = $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ?');
$assigned = 0;
foreach ($ids as $pid) {
    $body = '890' . str_pad((string)$pid, 9, '0', STR_PAD_LEFT);
    $upd->execute([ean13_check($body), $pid]);
    $assigned++;
}

$total = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$withBc = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NOT NULL AND barcode <> ''")->fetchColumn();
json_out(['ok' => true, 'assigned' => $assigned, 'total' => $total, 'with_barcode' => $withBc,
    'message' => "Assigned {$assigned} new barcodes. {$withBc}/{$total} variants now have a barcode."]);
