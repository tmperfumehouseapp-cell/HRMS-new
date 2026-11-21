<?php
/** diag_won.php — read-only, command line only. Which chats ended in an order, who closed them (bot only / bot then a salesperson / salesperson only), and which salesperson messages
 *  were used in the orders the bot could not carry to the address but a person closed. Usage: php diag_won.php <path with trailing slash to the site root>. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? ''; require $root . 'empmanagment/config.php';
$pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$days = max(1, min(30, (int)($argv[2] ?? 7))); $t7 = date('Y-m-d H:i:s', time() - $days * 86400);
$norm = function (string $s): string { $s = mb_strtolower($s); $s = preg_replace('/[0-9]+/', '#', $s); $s = preg_replace('/[^\p{L}# ]+/u', ' ', $s); $s = trim(preg_replace('/\s+/', ' ', $s)); return mb_substr($s, 0, 55); };
// the guidebook versions: what the learning changed
try { foreach ($pdo->query("SELECT version, status, source, created_at, LEFT(change_note, 230) FROM lib_guide ORDER BY version DESC LIMIT 6")->fetchAll(PDO::FETCH_NUM) as $r) echo "WON guide v" . $r[0] . " " . $r[1] . " " . $r[2] . " " . $r[3] . " | " . str_replace("\n", ' ', (string)$r[4]) . "\n"; } catch (Throwable $e) { echo "WON guide ERR " . substr($e->getMessage(), 0, 80) . "\n"; }
// bot messages of the period (normalised) = what the bot said
$botSet = []; try { foreach ($pdo->query("SELECT body FROM wa_messages WHERE dir = 'out' AND who = 'bot' AND mtype = 'text' AND ts >= '" . $t7 . "' ORDER BY id DESC LIMIT 60000")->fetchAll(PDO::FETCH_COLUMN) as $b) { $k = $norm((string)$b); if ($k !== '') $botSet[$k] = 1; } } catch (Throwable $e) { }
$cat = ['bot only' => [0, 0], 'bot then a person' => [0, 0], 'person only' => [0, 0], 'no chat message' => [0, 0]]; $empPat = []; $empEx = []; $n = 0;
try { $q = $pdo->query("SELECT phone, created_at, total FROM shop_orders WHERE created_at >= '" . $t7 . "' AND phone IS NOT NULL AND phone <> '' AND total > 0 AND COALESCE(tags, '') NOT LIKE '%wa-rx%' ORDER BY id DESC LIMIT 2500")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { echo "WON orders ERR " . substr($e->getMessage(), 0, 80) . "\n"; $q = []; }
$m = $pdo->prepare("SELECT who, body, mtype FROM wa_messages WHERE phone = ? AND dir = 'out' AND ts >= ? AND ts <= ? AND who IN ('bot', 'emp') ORDER BY id LIMIT 300");
foreach ($q as $o) { $n++; $k = substr(preg_replace('/\D+/', '', (string)$o['phone']), -10); $ot = (int)strtotime((string)$o['created_at']); $m->execute([$k, date('Y-m-d H:i:s', $ot - 3 * 86400), date('Y-m-d H:i:s', $ot)]); $rows = $m->fetchAll(PDO::FETCH_ASSOC);
    $bot = 0; $emp = 0; $mine = []; foreach ($rows as $r) { if ($r['who'] === 'bot') $bot++; else { $emp++; if ($r['mtype'] === 'text' && mb_strlen((string)$r['body']) > 25) { $p = $norm((string)$r['body']); if ($p !== '') $mine[$p] = (string)$r['body']; } } }
    $c = $bot > 0 && $emp === 0 ? 'bot only' : ($bot > 0 && $emp > 0 ? 'bot then a person' : ($emp > 0 ? 'person only' : 'no chat message')); $cat[$c][0]++; $cat[$c][1] += (float)$o['total'];
    if ($c === 'bot then a person' || $c === 'person only') foreach ($mine as $p => $b) { $empPat[$c][$p] = ($empPat[$c][$p] ?? 0) + 1; $empEx[$p] = $b; } }
echo "WON orders " . $days . " d: " . $n . " · " . implode(' · ', array_map(fn($k, $v) => $k . ' ' . $v[0] . ' (AOV ' . ($v[0] ? (int)round($v[1] / $v[0]) : 0) . ')', array_keys($cat), $cat)) . "\n";
foreach (['bot then a person', 'person only'] as $c) { $p = $empPat[$c] ?? []; arsort($p); $top = array_slice($p, 0, 14, true); $notBot = 0; $tot = 0; foreach ($p as $k => $v) if ($v >= 2) { $tot++; if (!isset($botSet[$k])) $notBot++; }
    echo "WON " . $c . ": patterns used in 2+ orders " . $tot . ", of them the bot never said " . $notBot . "\n";
    foreach ($top as $k => $v) echo "WON   " . $v . "x " . (isset($botSet[$k]) ? '[bot also]' : '[bot never]') . " " . str_replace("\n", ' ', mb_substr($empEx[$k] ?? $k, 0, 140)) . "\n"; }
