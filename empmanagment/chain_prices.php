<?php
/* 💰 Prices for the public chain page (chain/chain_index.html): the chain / chain-combo products of the shop with the price Shopify has now.
 *    GET api.php?action=chain.prices  →  {ok, at, items: [{title, price}]}       PUBLIC, read-only: product names and prices only (the same the shop shows).
 *    Reads the catalogue HRMS already keeps in step with Shopify (wch_prod / wch_var, "Chain cart"). Kept for 5 minutes so the page costs the server nothing. PHP 7.4 safe. */
if (!isset($pdo) || !function_exists('json_response')) { return; }
if ((string)($action ?? ($_GET['action'] ?? '')) === 'chain.prices') {
    $run = function () use ($pdo) {
        $out = [];
        try {
            $q = $pdo->query("SELECT p.title, MIN(v.price_p) mn FROM wch_prod p JOIN wch_var v ON v.pid = p.pid WHERE v.price_p > 0 AND (p.kind = 'chain' OR LOWER(p.ptype) = 'chain') GROUP BY p.pid, p.title ORDER BY p.title LIMIT 400");
            foreach ($q->fetchAll() as $r) $out[] = ['title' => (string)$r['title'], 'price' => round(((int)$r['mn']) / 100, 2)];
        } catch (Throwable $e) { error_log('chain.prices: ' . $e->getMessage()); }
        return $out;
    };
    $items = function_exists('hrms_memo') ? hrms_memo('chain_prices_v1', 300, $run) : $run();
    header('Cache-Control: public, max-age=300');
    json_response(['ok' => true, 'at' => date('Y-m-d H:i'), 'items' => is_array($items) ? $items : []]);
}
