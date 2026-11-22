<?php
/** diag_ask.php — read-only, command line only (owner 22 Nov asked): who is blocked by the query gate, how many 131049-type failures went again as utility, and what the 30-day Lineup learning did
 *  (new topics, new wordings, winners). Usage: php diag_ask.php <path with trailing slash to the site root>. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? ''; require $root . 'empmanagment/config.php';
$pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo; $action = ''; $t0 = date('Y-m-d 00:00:00'); $t36 = date('Y-m-d H:i:s', time() - 36 * 3600);
$one = function (string $sql, array $a = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return [['ERR ' . substr($e->getMessage(), 0, 70)]]; } };
// 1) the query gate: who is blocked right now (people with queries today)
try { foreach (['wa.php', 'wa_growth.php', 'wq.php'] as $f) if (is_file($root . 'empmanagment/' . $f)) { try { require_once $root . 'empmanagment/' . $f; } catch (Throwable $e) { echo "ASK load " . $f . " " . substr($e->getMessage(), 0, 60) . "\n"; } }
    $d = wag_real_queries($pdo, 1, true); $blocked = []; $names = []; foreach ($pdo->query("SELECT id, name FROM employees")->fetchAll(PDO::FETCH_NUM) as $r) $names[(int)$r[0]] = (string)$r[1];
    foreach ($d as $emp => $v) { $m = wag_mkt_gate_msg($pdo, (int)$emp, null); if ($m !== '') $blocked[] = ($names[$emp] ?? $emp) . ' ' . $v[1] . '/' . $v[0]; }
    echo "ASK GATE people with queries today " . count($d) . " · blocked now " . count($blocked) . " · " . implode(' | ', array_slice($blocked, 0, 14)) . "\n";
} catch (Throwable $e) { echo "ASK GATE ERR " . substr($e->getMessage(), 0, 90) . "\n"; }
// 2) rescue: marketing failures that went again as utility (30-day Lineup and After-Order), last 36 h and today
echo "ASK RESCUE lineup 36h " . json_encode($one("SELECT CASE WHEN why LIKE 'rescued%' THEN 'rescued' WHEN why LIKE 'rescue: no utility%' THEN 'no utility msg left' WHEN why LIKE 'rescue not needed%' THEN 'he wrote' WHEN why LIKE 'rescue:%' THEN 'other' ELSE 'x' END k, COUNT(*) FROM wa_line_plan WHERE why LIKE 'rescue%' AND sent_at >= ? GROUP BY k", [$t36])) . " · utility rescue sends today " . json_encode($one("SELECT COUNT(*) FROM wa_line_plan WHERE why LIKE 'utility rescue of%' AND sent_at >= ?", [$t0])) . " · all-time " . json_encode($one("SELECT COUNT(*) FROM wa_line_plan WHERE why LIKE 'utility rescue of%'")) . "\n";
echo "ASK RESCUE after-order 36h " . json_encode($one("SELECT SUBSTR(why, 1, 22) k, COUNT(*) FROM wa_aft_plan WHERE why LIKE '%rescue%' AND sent_at >= ? GROUP BY k ORDER BY 2 DESC LIMIT 5", [$t36])) . "\n";
echo "ASK FAILED today by code " . json_encode($one("SELECT err_code, COUNT(*) FROM wa_messages WHERE dir = 'out' AND mtype = 'template' AND status = 'failed' AND ts >= ? GROUP BY err_code ORDER BY 2 DESC LIMIT 5", [$t0])) . "\n";
// 3) the learning of the 30-day Lineup: new topics, new wordings, winners, the nightly log
try { $st = function ($k) use ($pdo) { $x = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $x->execute([$k]); return (string)$x->fetchColumn(); };
    $te = json_decode($st('wl_topics_extra'), true) ?: []; echo "ASK TOPICS new " . count($te) . " · " . implode(' · ', array_map(function ($t) { return ($t['key'] ?? '?') . ' ' . ($t['label'] ?? '') . ' ready=' . (!empty($t['ready']) ? 1 : 0); }, $te)) . "\n";
    foreach ($te as $t) { $k = (string)($t['key'] ?? ''); if ($k === '') continue; echo "ASK TOPIC " . $k . " customers " . json_encode($one("SELECT COUNT(*) FROM wa_line_cust WHERE topic = ?", [$k])) . " sent " . json_encode($one("SELECT COUNT(*), SUM(sent_at >= ?) FROM wa_line_plan WHERE topic = ? AND status = 'sent'", [$t0, $k])) . "\n"; }
    $ex = json_decode($st('wl_extra'), true) ?: []; echo "ASK WORDINGS written by the learning " . count($ex) . " · " . implode(' | ', array_slice(array_map(function ($e) { return ($e['key'] ?? '?') . ' of ' . ($e['of'] ?? '-') . ' s' . ($e['step'] ?? '?'); }, $ex), -10)) . "\n";
    $sc = json_decode($st('wl_scores'), true) ?: []; arsort($sc); echo "ASK SCORES winners " . json_encode(array_slice($sc, 0, 8), JSON_UNESCAPED_UNICODE) . "\n";
    $ll = json_decode($st('wl_learn_log'), true) ?: []; foreach (array_slice($ll, 0, 12) as $r) echo "ASK LOG " . substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 230) . "\n";
} catch (Throwable $e) { echo "ASK LEARN ERR " . substr($e->getMessage(), 0, 90) . "\n"; }
