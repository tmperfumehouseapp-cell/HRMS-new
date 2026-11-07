<?php
// Pushes each variant's POS barcode into Shopify (variant barcode field) by variant ID.
// Requires write_products scope on the TM POS app.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin(true);
set_time_limit(600);

$store = get_setting('shopify_store_url');
$clientId = get_setting('shopify_client_id');
$clientSecret = get_setting('shopify_client_secret');
if (!$store || !$clientId || !$clientSecret) {
    json_out(['ok' => false, 'error' => 'Set Shopify credentials in Settings first'], 400);
}

function sp_token(string $store, string $id, string $secret): string {
    // force fresh token so new scope (write_products) is picked up
    $ch = curl_init("https://{$store}/admin/oauth/access_token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['client_id'=>$id,'client_secret'=>$secret,'grant_type'=>'client_credentials']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) json_out(['ok'=>false,'error'=>"Token failed (HTTP {$code}): ".substr((string)$resp,0,160)]);
    $t = json_decode($resp, true)['access_token'] ?? '';
    if (!$t) json_out(['ok'=>false,'error'=>'No access token returned']);
    set_setting('shopify_cached_token', $t);
    set_setting('shopify_token_expiry', (string)(time()+86400));
    return $t;
}

$token = sp_token($store, $clientId, $clientSecret);
$api = '2025-10';

// how many to push this run (paged so the request doesn't time out)
$limit = max(1, min(250, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$rows = $pdo->query("
    SELECT shopify_variant_id, barcode
    FROM products
    WHERE barcode IS NOT NULL AND barcode <> ''
    ORDER BY id
    LIMIT $limit OFFSET $offset
")->fetchAll();

$totalPending = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE barcode IS NOT NULL AND barcode <> ''")->fetchColumn();

$ok = 0; $fail = 0; $errors = [];
foreach ($rows as $r) {
    $vid = (int)$r['shopify_variant_id'];
    $payload = json_encode(['variant' => ['id' => $vid, 'barcode' => $r['barcode']]]);
    $ch = curl_init("https://{$store}/admin/api/{$api}/variants/{$vid}.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: '.$token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $ok++; }
    else {
        $fail++;
        if ($code === 403) json_out(['ok'=>false,'error'=>'403 Forbidden — add write_products scope to the TM POS app and release a new version, then retry.']);
        if (count($errors) < 3) $errors[] = "variant {$vid}: HTTP {$code}";
    }
    usleep(120000); // ~8/sec, under Shopify rate limit
}

$nextOffset = $offset + count($rows);
$done = $nextOffset >= $totalPending;

json_out([
    'ok' => true,
    'pushed' => $ok, 'failed' => $fail,
    'next_offset' => $done ? null : $nextOffset,
    'total' => $totalPending,
    'done' => $done,
    'errors' => $errors,
    'message' => "Pushed {$ok} this batch" . ($fail ? ", {$fail} failed" : '') . ($done ? ". All done." : " — continuing…"),
]);
