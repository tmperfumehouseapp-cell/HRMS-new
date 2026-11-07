<?php
// Daily sales report CSV — ?date=YYYY-MM-DD (or from/to range)
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/biller_schema.php';
require_admin();
ensure_billers_schema($pdo);

$from = $_GET['from'] ?? ($_GET['date'] ?? date('Y-m-d'));
$to   = $_GET['to'] ?? $from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;

$st = $pdo->prepare("
    SELECT o.invoice_no, o.created_at, c.name AS customer, c.phone,
           COALESCE(NULLIF(o.billed_by,''), u.name) AS billed_by, o.subtotal, o.discount, o.total, o.payment_mode, o.whatsapp_sent, o.id
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.user_id
    WHERE DATE(o.created_at) BETWEEN ? AND ?
    ORDER BY o.id ASC
");
$st->execute([$from, $to]);
$orders = $st->fetchAll();

// Per-order GST from items (inclusive back-out with proportional discount)
$itemsStmt = $pdo->prepare('SELECT line_total, gst_rate FROM order_items WHERE order_id = ?');

$filename = 'sales-report-' . $from . ($to !== $from ? '_to_' . $to : '') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

fputcsv($out, ['Invoice No','Date','Time','Customer','Phone','Billed By','Gross (Rs)','Discount (Rs)','Taxable (Rs)','CGST (Rs)','SGST (Rs)','Total Paid (Rs)','Payment','WhatsApp Sent']);

$sumGross = 0; $sumDisc = 0; $sumTaxable = 0; $sumTax = 0; $sumTotal = 0; $cash = 0; $online = 0;

foreach ($orders as $o) {
    $itemsStmt->execute([$o['id']]);
    $items = $itemsStmt->fetchAll();
    $factor = ($o['subtotal'] > 0) ? ($o['total'] / $o['subtotal']) : 1;
    $taxable = 0; $tax = 0;
    foreach ($items as $i) {
        $eff = $i['line_total'] * $factor;
        $t = $eff / (1 + ((float)$i['gst_rate']) / 100);
        $taxable += $t;
        $tax += $eff - $t;
    }
    fputcsv($out, [
        $o['invoice_no'],
        date('d-m-Y', strtotime($o['created_at'])),
        date('h:i A', strtotime($o['created_at'])),
        $o['customer'], $o['phone'], $o['billed_by'],
        number_format($o['subtotal'], 2, '.', ''),
        number_format($o['discount'], 2, '.', ''),
        number_format($taxable, 2, '.', ''),
        number_format($tax / 2, 2, '.', ''),
        number_format($tax / 2, 2, '.', ''),
        number_format($o['total'], 2, '.', ''),
        strtoupper($o['payment_mode']),
        $o['whatsapp_sent'] ? 'YES' : 'NO',
    ]);
    $sumGross += $o['subtotal']; $sumDisc += $o['discount'];
    $sumTaxable += $taxable; $sumTax += $tax; $sumTotal += $o['total'];
    if ($o['payment_mode'] === 'cash') $cash += $o['total']; else $online += $o['total'];
}

fputcsv($out, []);
fputcsv($out, ['TOTALS', '', '', count($orders) . ' orders', '', '',
    number_format($sumGross, 2, '.', ''), number_format($sumDisc, 2, '.', ''),
    number_format($sumTaxable, 2, '.', ''), number_format($sumTax / 2, 2, '.', ''),
    number_format($sumTax / 2, 2, '.', ''), number_format($sumTotal, 2, '.', ''), '', '']);
fputcsv($out, ['Cash collected', number_format($cash, 2, '.', '')]);
fputcsv($out, ['Online (POS machine)', number_format($online, 2, '.', '')]);
fclose($out);