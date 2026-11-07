<?php
// Inventory usage report CSV — ?from=YYYY-MM-DD&to=YYYY-MM-DD
// Shows per product: qty sold in range, revenue, stock added, current stock
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

$st = $pdo->prepare("
    SELECT p.id, p.title, p.variant_title, p.sku, p.barcode, p.price, p.hsn,
        COALESCE(i.qty, 0) AS current_stock,
        COALESCE(sold.qty_sold, 0) AS qty_sold,
        COALESCE(sold.revenue, 0) AS revenue,
        COALESCE(added.qty_added, 0) AS qty_added
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    LEFT JOIN (
        SELECT oi.product_id, SUM(oi.qty) AS qty_sold, SUM(oi.line_total) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY oi.product_id
    ) sold ON sold.product_id = p.id
    LEFT JOIN (
        SELECT product_id, SUM(change_qty) AS qty_added
        FROM inventory_log
        WHERE type IN ('opening','adjustment') AND change_qty > 0
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY product_id
    ) added ON added.product_id = p.id
    WHERE p.status = 1
    ORDER BY qty_sold DESC, p.title ASC
");
$st->execute([$from, $to, $from, $to]);
$rows = $st->fetchAll();

$filename = 'inventory-report-' . $from . '_to_' . $to . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");

fputcsv($out, ['Report period', $from . ' to ' . $to]);
fputcsv($out, []);
fputcsv($out, ['Product','Variant','SKU','Barcode','HSN','Price (Rs)','Qty Sold','Revenue (Rs)','Stock Added','Current Stock']);

$totSold = 0; $totRev = 0;
foreach ($rows as $r) {
    // skip products with no movement and no stock to keep the file readable
    if ((int)$r['qty_sold'] === 0 && (int)$r['current_stock'] === 0 && (int)$r['qty_added'] === 0) continue;
    fputcsv($out, [
        $r['title'], $r['variant_title'], $r['sku'], $r['barcode'], $r['hsn'],
        number_format($r['price'], 2, '.', ''),
        (int)$r['qty_sold'],
        number_format($r['revenue'], 2, '.', ''),
        (int)$r['qty_added'],
        (int)$r['current_stock'],
    ]);
    $totSold += (int)$r['qty_sold'];
    $totRev += (float)$r['revenue'];
}
fputcsv($out, []);
fputcsv($out, ['TOTALS','','','','','', $totSold, number_format($totRev, 2, '.', ''), '', '']);
fclose($out);
