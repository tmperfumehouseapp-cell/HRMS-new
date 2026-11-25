<?php
$src = file_get_contents(__DIR__ . '/../empmanagment/api.php'); $i = strpos($src, 'function ora_bot_addon_apply'); $j = strpos($src, 'function ora_rate_one'); eval(substr($src, $i, $j - $i));
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE wag_addon (id INTEGER PRIMARY KEY, phone TEXT, at TEXT, status TEXT, size_ml INT, p1 INT, p2 INT)");
$pdo->exec("INSERT INTO wag_addon (phone, at, status, size_ml, p1, p2) VALUES ('9606303346', '" . date('Y-m-d H:i:s', time() - 600) . "', 'declined', 50, 880, 1200)");
$none = ['rating' => 2, 'tried' => 0, 'worked' => 0, 'tries' => 0, 'refused' => 0, 'why' => 'no offer', 'offered' => ''];
$r = ora_bot_addon_apply($pdo, $none, ['9606303346'], time() - 86400, time() + 60); assert($r['tried'] === 1 && $r['tries'] === 1 && $r['refused'] === 1 && strpos($r['why'], 'said no') !== false); echo $r['why'], "\n";
$r = ora_bot_addon_apply($pdo, $none, ['9000000000'], time() - 86400, time() + 60); assert($r['tried'] === 0);
$had = ['tried' => 1, 'worked' => 1, 'tries' => 2, 'refused' => 0, 'why' => 'kept']; $r = ora_bot_addon_apply($pdo, $had, ['9606303346'], time() - 86400, time() + 60); assert($r['why'] === 'kept' && $r['worked'] === 1 && $r['tries'] === 2);
$r = ora_bot_addon_apply($pdo, ['rating' => null, 'why' => 'x'], ['9606303346'], 0, time()); assert(!isset($r['tried']));
echo "bot addon test: ok\n";
