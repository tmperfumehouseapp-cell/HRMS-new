<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_login(true);

$barcode = trim($_GET['barcode'] ?? '');
$q = trim($_GET['q'] ?? '');

/* ---- clean, short display name for billing (same style as Request) ---- */
function clean_title($t){
    $t = (string)$t; $cut = null;
    foreach ([' - ',' – ',' — ',' | ',' / '] as $sep){
        $i = mb_strpos($t, $sep); if ($i !== false) $cut = ($cut===null) ? $i : min($cut, $i);
    }
    if ($cut !== null) $t = mb_substr($t, 0, $cut);
    $t = preg_replace('/\s*[|\-–—\/]?\s*TM\s*Perfume\s*House\b\.?/iu','',$t);
    $t = preg_replace('/\s*[|\-–—\/]?\s*TMPH\b\.?/iu','',$t);
    $t = trim($t, " \t\n\r\0\x0B|-–—/");
    return $t !== '' ? $t : 'Product';
}
/* shorten the variant to just the size (e.g. "50 ml") for a clean card */
function pos_format($p){
    if (isset($p['title']))        $p['title'] = clean_title($p['title']);
    if (!empty($p['variant_title']) && preg_match('/(\d+)\s*ml/i', $p['variant_title'], $m)) {
        $p['variant_title'] = $m[1] . ' ml';
    }
    return $p;
}

if ($barcode !== '') {
    // Scanner-safe: also try a digits-only version (guns can prepend/append stray chars).
    // A scanned barcode resolves regardless of status — if you scanned a real bottle,
    // you want to bill it even if the product is inactive/archived in Shopify.
    $digits = preg_replace('/\D+/', '', $barcode);

    // ── 1) Serialized per-bottle lookup (stock_units) ──
    // The printed barcode carries a short code "U<id>" (e.g. U6) so tiny labels scan.
    // The human-readable serial (DRSA-50-0006) also resolves when typed.
    $unitSql = '
        SELECT su.id AS unit_id, su.serial AS unit_serial, su.status AS unit_status,
               p.*, COALESCE(i.qty,0) AS stock
        FROM stock_units su
        JOIN products p ON p.id = su.product_id
        LEFT JOIN inventory i ON i.product_id = su.product_id
        WHERE %s
        LIMIT 1
    ';
    $unit = false;
    if (preg_match('/^[Uu](\d+)$/', $barcode, $mm)) {
        $su = $pdo->prepare(sprintf($unitSql, 'su.id = ?'));
        $su->execute([(int)$mm[1]]);
        $unit = $su->fetch();
    }
    if (!$unit) {
        $su = $pdo->prepare(sprintf($unitSql, 'su.serial = ?'));
        $su->execute([$barcode]);
        $unit = $su->fetch();
    }
    if ($unit) {
        if ($unit['unit_status'] === 'sold') {
            json_out(['ok' => false, 'sold' => true, 'serial' => $unit['unit_serial'],
                      'error' => 'Already sold: ' . $unit['unit_serial']], 409);
        }
        if ($unit['unit_status'] === 'void') {
            json_out(['ok' => false, 'void' => true, 'serial' => $unit['unit_serial'],
                      'error' => 'Voided bottle: ' . $unit['unit_serial']], 409);
        }
        if ($unit['unit_status'] === 'ready') {
            json_out(['ok' => false, 'notready' => true, 'serial' => $unit['unit_serial'],
                      'error' => 'Not in stock yet — receive it first: ' . $unit['unit_serial']], 409);
        }
        // in_stock or dispatched → bill this exact bottle
        json_out(['ok' => true, 'product' => pos_format($unit),
                  'unit' => ['id' => (int)$unit['unit_id'], 'serial' => $unit['unit_serial']]]);
    }

    // ── 2) Shared product barcode / SKU (existing behaviour) ──
    $st = $pdo->prepare('
        SELECT p.*, COALESCE(i.qty,0) AS stock
        FROM products p LEFT JOIN inventory i ON i.product_id = p.id
        WHERE (p.barcode = ? OR p.barcode = ? OR p.sku = ?)
        ORDER BY (p.barcode = ?) DESC, (p.barcode = ?) DESC
        LIMIT 1
    ');
    $st->execute([$barcode, $digits, $barcode, $barcode, $digits]);
    $product = $st->fetch();

    if ($product) json_out(['ok' => true, 'product' => pos_format($product)]);
    json_out(['ok' => false, 'error' => 'No product for barcode: ' . $barcode], 404);
}

if ($q !== '') {
    // Billing shows ONLY products that have a POS SKU (your curated perfumes).
    // Typing the full SKU (TMPRO310) = exact match.
    // Typing JUST the number (310) = matches the numeric part of the SKU, so you
    //   don't have to type "TMPRO" every time. Numbers are unique per perfume.
    // Typing a name = LIKE match on the clean title / size.
    $like = '%' . $q . '%';
    if (ctype_digit($q)) {
        // numeric shortcut: "310" -> SKU whose number is 310 (TMPRO310)
        $st = $pdo->prepare('
            SELECT p.*, COALESCE(i.qty,0) AS stock
            FROM products p LEFT JOIN inventory i ON i.product_id = p.id
            WHERE p.status = 1 AND p.pos_sku IS NOT NULL AND p.pos_sku <> ""
              AND ( p.pos_sku = ?
                 OR CAST(REGEXP_REPLACE(p.pos_sku, "[^0-9]", "") AS UNSIGNED) = ?
                 OR p.title LIKE ? OR p.variant_title LIKE ? )
            ORDER BY p.title ASC, p.price ASC
            LIMIT 60
        ');
        $st->execute([$q, (int)$q, $like, $like]);
    } else {
        $st = $pdo->prepare('
            SELECT p.*, COALESCE(i.qty,0) AS stock
            FROM products p LEFT JOIN inventory i ON i.product_id = p.id
            WHERE p.status = 1 AND p.pos_sku IS NOT NULL AND p.pos_sku <> ""
              AND (p.pos_sku = ? OR p.title LIKE ? OR p.variant_title LIKE ?)
            ORDER BY p.title ASC, p.price ASC
            LIMIT 60
        ');
        $st->execute([$q, $like, $like]);
    }
    $rows = array_map('pos_format', $st->fetchAll());
    json_out(['ok' => true, 'products' => $rows]);
}

// No query = full grid: only POS-SKU products (your 180), paged
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * 60;
$st = $pdo->prepare('
    SELECT p.*, COALESCE(i.qty,0) AS stock
    FROM products p LEFT JOIN inventory i ON i.product_id = p.id
    WHERE p.status = 1 AND p.pos_sku IS NOT NULL AND p.pos_sku <> ""
    ORDER BY p.title ASC, p.price ASC
    LIMIT 60 OFFSET ' . $offset);
$st->execute();
$rows = array_map('pos_format', $st->fetchAll());
json_out(['ok' => true, 'products' => $rows]);
