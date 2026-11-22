<?php
/** diag_ask.php — read-only, command line only (owner 22 Nov asked): who is blocked by the query gate, how many 131049-type failures went again as utility, and what the 30-day Lineup learning did
 *  (new topics, new wordings, winners). Usage: php diag_ask.php <path with trailing slash to the site root>. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? ''; require $root . 'empmanagment/config.php';
$pdo = new PDO('mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['pdo'] = $pdo; $action = ''; $t0 = date('Y-m-d 00:00:00'); $t36 = date('Y-m-d H:i:s', time() - 36 * 3600);
$one = function (string $sql, array $a = []) use ($pdo) { try { $q = $pdo->prepare($sql); $q->execute($a); return $q->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return [['ERR ' . substr($e->getMessage(), 0, 70)]]; } };
// 1) the query gate: who is blocked right now - counted straight from the query ledger (the gate function needs the web context): combined normal + campaign queries of the working day (10:00 -> 10:00), the same rule as wag_mkt_gate_msg
try { $h = (int)date('G'); $f = date('Y-m-d 10:00:00', $h >= 10 ? time() : time() - 86400); $f7 = date('Y-m-d 10:00:00', time() - 7 * 86400);
    $sql = "SELECT q.emp_id, COUNT(*) n, SUM(CASE WHEN COALESCE(s.orders, 0) > 0 THEN 1 ELSE 0 END) o FROM wa_queries q LEFT JOIN wq_qtype t ON t.query_id = q.id LEFT JOIN wq_qstat s ON s.query_id = q.id WHERE q.opened_at >= ? AND q.emp_id > 0 AND COALESCE(t.type, 'unclear') NOT IN ('community') GROUP BY q.emp_id";
    $q = $pdo->prepare($sql); $q->execute([$f]); $d = $q->fetchAll(PDO::FETCH_NUM); $q->execute([$f7]); $tq = 0; $to = 0; foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) { $tq += (int)$r[1]; $to += (int)$r[2]; }
    $cavg = $tq >= 40 && $to > 0 ? min(0.5, $to / $tq) : 0.0; $ref = max($cavg, 0.2); $blocked = []; $list = [];
    foreach ($d as $r) { $e = (int)$r[0]; $n = (int)$r[1]; $o = (int)$r[2]; $list[] = $n; if (($n >= 10 && $o === 0) || ($n >= 12 && $o / $n < 0.5 * $ref)) $blocked[] = 'emp ' . $e . ' ' . $o . '/' . $n; }
    echo "ASK GATE (ledger estimate) people with queries " . count($d) . " · team 7d ratio " . round(100 * ($tq > 0 ? $to / $tq : 0), 1) . "% (reference " . round(100 * $ref) . "%) · would be blocked " . count($blocked) . " · " . implode(' | ', array_slice($blocked, 0, 14)) . "\n";
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
// 4) why the new topics have not started: the state of their messages at Meta (per WhatsApp account)
try { $st2 = function ($k) use ($pdo) { $x = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $x->execute([$k]); return (string)$x->fetchColumn(); }; $ids = json_decode($st2('wl_tpls'), true) ?: []; $want = []; foreach ($ids as $k => $id) if (preg_match('/^ln_(brand|party)_/', (string)$k)) $want[(string)$k] = (int)$id;
    echo "ASK TOPICTPL ids " . json_encode($want) . "\n"; if ($want) { $in = implode(',', array_map('intval', $want));
        echo "ASK TOPICTPL waba " . json_encode($one("SELECT tpl_id, UPPER(status) s, COUNT(*) FROM wa_tpl_waba WHERE tpl_id IN ($in) GROUP BY tpl_id, s ORDER BY tpl_id")) . "\n";
        echo "ASK TOPICTPL tpl " . json_encode($one("SELECT id, category, relabelled_at, LEFT(COALESCE(note, ''), 60) FROM wa_templates WHERE id IN ($in)")) . "\n";
        echo "ASK TOPICTPL reasons " . json_encode($one("SELECT * FROM wa_tpl_waba WHERE tpl_id IN ($in) AND UPPER(status) <> 'APPROVED' LIMIT 3")) . "\n"; }
    echo "ASK TOTAL accounts " . json_encode($one("SELECT COUNT(DISTINCT waba_id) FROM wa_tpl_waba")) . "\n";
} catch (Throwable $e) { echo "ASK TOPICTPL ERR " . substr($e->getMessage(), 0, 90) . "\n"; }
