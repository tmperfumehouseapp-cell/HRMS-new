<?php
// api/payment_modes_list.php — active payment modes for the billing dropdown
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_login(true);

$modes = array_map(function ($m) {
    return ['id' => (int)$m['id'], 'name' => $m['name'], 'kind' => $m['kind']];
}, $pdo->query("SELECT id, name, kind FROM payment_modes WHERE active = 1 ORDER BY sort_order, id")->fetchAll());

json_out(['ok' => true, 'modes' => $modes]);
