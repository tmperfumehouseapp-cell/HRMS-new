<?php
// Pulls all products + variants from Shopify into local products table.
// Client credentials grant (client_id + client_secret -> 24h token, auto-refreshed).
// Auto-assigns HSN + GST rate: title containing "chain" -> 71179090 @ 3%, else 33030050 @ 18%.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin(true);

set_time_limit(300);

$store = get_setting('shopify_store_url');
$clientId = get_setting('shopify_client_id');
$clientSecret = get_setting('shopify_client_secret');

if (!$store || !$clientId || !$clientSecret) {
    json_out(['ok' => false, 'error' => 'Set store URL, client ID and client secret in Settings first'], 400);
}

function shopify_token(string $store, string $id, string $secret): string {
    $cached = get_setting('shopify_cached_token');
    $expiry = (int)get_setting('shopify_token_expiry');
    if ($cached && $expiry > time() + 120) return $cached;

    $ch = curl_init("https://{$store}/admin/oauth/access_token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'client_id' => $id,
            'client_secret' => $secret,
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        json_out(['ok' => false, 'error' => "Token exchange failed (HTTP {$code}). Check client ID/secret and store URL. Response: " . substr((string)$resp, 0, 200)]);
    }
    $data = json_decode($resp, true);
    $token = $data['access_token'] ?? '';
    $expiresIn = (int)($data['expires_in'] ?? 86400);
    if (!$token) json_out(['ok' => false, 'error' => 'No access_token in Shopify response']);

    set_setting('shopify_cached_token', $token);
    set_setting('shopify_token_expiry', (string)(time() + $expiresIn));
    return $token;
}

$token = shopify_token($store, $clientId, $clientSecret);

$apiVersion = '2025-10';
$url = "https://{$store}/admin/api/{$apiVersion}/products.json?limit=250&fields=id,title,image,images,variants,status";

$inserted = 0; $updated = 0; $pages = 0;

$upsert = $pdo->prepare('
    INSERT INTO products (shopify_product_id, shopify_variant_id, title, variant_title, sku, barcode, price, image_url, status, hsn, gst_rate)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        title = VALUES(title), variant_title = VALUES(variant_title),
        sku = VALUES(sku), barcode = VALUES(barcode),
        price = VALUES(price), image_url = VALUES(image_url), status = VALUES(status),
        hsn = VALUES(hsn), gst_rate = VALUES(gst_rate)
');
$invInit = $pdo->prepare('INSERT IGNORE INTO inventory (product_id, qty) SELECT id, 0 FROM products WHERE shopify_variant_id = ?');

while ($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . $token],
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($code !== 200) {
        json_out(['ok' => false, 'error' => "Shopify API error (HTTP {$code}). Check app scopes (read_products).", 'synced' => $inserted + $updated]);
    }

    $headers = substr($resp, 0, $headerSize);
    $body = json_decode(substr($resp, $headerSize), true);

    foreach ($body['products'] ?? [] as $p) {
        $active = ($p['status'] === 'active') ? 1 : 0;
        $mainImage = $p['image']['src'] ?? '';

        // HSN / GST by title
        $isChain = (stripos($p['title'], 'chain') !== false);
        $hsn = $isChain ? '71179090' : '33030050';
        $rate = $isChain ? 3.00 : 18.00;

        foreach ($p['variants'] as $v) {
            $img = $mainImage;
            if (!empty($v['image_id'])) {
                foreach ($p['images'] ?? [] as $im) {
                    if ($im['id'] == $v['image_id']) { $img = $im['src']; break; }
                }
            }
            $vt = ($v['title'] === 'Default Title') ? null : $v['title'];

            $upsert->execute([
                $p['id'], $v['id'], $p['title'], $vt,
                $v['sku'] ?: null, $v['barcode'] ?: null,
                (float)$v['price'], $img, $active, $hsn, $rate
            ]);
            if ($upsert->rowCount() === 1) $inserted++; else $updated++;
            $invInit->execute([$v['id']]);
        }
    }

    $url = null;
    if (preg_match('/<([^>]+)>;\s*rel="next"/', $headers, $m)) {
        $url = $m[1];
    }
    $pages++;
    if ($pages > 40) break;
}


// Auto-assign EAN-13 barcodes to any variant still missing one
function ean13_check_sync($d12){ $sum=0; for($i=0;$i<12;$i++){ $n=(int)$d12[$i]; $sum+=($i%2===0)?$n:$n*3; } return $d12.((10-($sum%10))%10); }
$missing = $pdo->query("SELECT id FROM products WHERE barcode IS NULL OR barcode=''")->fetchAll(PDO::FETCH_COLUMN);
$bcUpd = $pdo->prepare('UPDATE products SET barcode=? WHERE id=?');
$newBc = 0;
foreach ($missing as $mid) {
    $body = '890'.str_pad((string)$mid,9,'0',STR_PAD_LEFT);
    $bcUpd->execute([ean13_check_sync($body), $mid]);
    $newBc++;
}

json_out(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'new_barcodes' => $newBc, 'message' => "Synced. {$inserted} new, {$updated} updated." . ($newBc ? " {$newBc} new barcodes." : "")]);
