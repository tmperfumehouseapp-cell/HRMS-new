<?php
// Shopify-import CSV: Handle + Variant SKU + Variant Barcode.
// Upload via Shopify Admin → Products → Import (updates existing products, fills barcode).
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

$rows = $pdo->query("
    SELECT shopify_product_id, sku, barcode, title, variant_title
    FROM products
    WHERE barcode IS NOT NULL AND barcode <> ''
    ORDER BY title, variant_title
")->fetchAll();

// We don't store Shopify 'handle'; Shopify import matches on SKU when Handle is blank IF
// 'sku' is unique. Safer: include Variant SKU only. Shopify matches variant by SKU.
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shopify-barcodes.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");
// Minimal Shopify variant-update columns
fputcsv($out, ['Variant SKU', 'Variant Barcode']);
foreach ($rows as $r) {
    if (!$r['sku']) continue;  // need SKU to match in Shopify
    fputcsv($out, [$r['sku'], $r['barcode']]);
}
fclose($out);
