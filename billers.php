<?php
// api/billers.php — manage the "Billed by" staff list.
//   GET  ?action=list                       any logged-in user  -> active names (for the billing dropdown)
//   POST {action:'add',    name}            admin only
//   POST {action:'rename', id, name}        admin only
//   POST {action:'remove', id}              admin only (hides the name; old bills keep it)
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/biller_schema.php';
$user = require_login(true);
ensure_billers_schema($pdo);

function billers_list(PDO $pdo): array {
    return $pdo->query('SELECT id, name FROM billers WHERE active = 1 ORDER BY name ASC')->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['ok' => true, 'billers' => billers_list($pdo)]);
}

if (($user['role'] ?? '') !== 'admin') json_out(['ok' => false, 'error' => 'Admin only'], 403);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
$id = (int)($in['id'] ?? 0);

if ($action === 'add' || $action === 'rename') {
    if ($name === '' || mb_strlen($name) > 100) json_out(['ok' => false, 'error' => 'Enter a name (max 100 characters)'], 400);
    $dup = $pdo->prepare('SELECT id FROM billers WHERE active = 1 AND LOWER(name) = LOWER(?) AND id <> ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) json_out(['ok' => false, 'error' => '"' . $name . '" is already in the list'], 400);
}

if ($action === 'add') {
    $pdo->prepare('INSERT INTO billers (name) VALUES (?)')->execute([$name]);
} elseif ($action === 'rename') {
    if ($id <= 0) json_out(['ok' => false, 'error' => 'Invalid id'], 400);
    $pdo->prepare('UPDATE billers SET name = ? WHERE id = ?')->execute([$name, $id]);
} elseif ($action === 'remove') {
    if ($id <= 0) json_out(['ok' => false, 'error' => 'Invalid id'], 400);
    $pdo->prepare('UPDATE billers SET active = 0 WHERE id = ?')->execute([$id]);
} else {
    json_out(['ok' => false, 'error' => 'Unknown action'], 400);
}
json_out(['ok' => true, 'billers' => billers_list($pdo)]);
