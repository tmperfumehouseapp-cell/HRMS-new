<?php
/**
 * store_bill.php — the customer's own bill page (PUBLIC: no login). The link comes in the WhatsApp message: ...store_bill.php?t=TOKEN
 * It shows the bill and three buttons (save our contacts, join the community, Google review) and records every visit / tap.
 * All the logic is st_bill_page() in store_lib.php. Put this file in the SAME folder as api.php.
 */
if (!isset($pdo)) {
    if (!defined('DB_HOST') && is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8]);
    } catch (Throwable $e) { http_response_code(503); exit('Please try again in a moment.'); }
}
require_once __DIR__ . '/store_lib.php';
try {
    if (!ensure_st_tables($pdo)) { http_response_code(503); exit('Please try again in a moment.'); }
    $r = st_bill_page($pdo, $_GET);
} catch (Throwable $e) { error_log('store_bill: ' . $e->getMessage()); http_response_code(500); exit('Something went wrong. Please try again.'); }
http_response_code($r['status']);
foreach ($r['headers'] as $hd) header($hd);
echo $r['body'];
