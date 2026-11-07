<?php
/**
 * wa_late.php — ⏰ late replies. Every chat given to a salesperson where the customer waits more than 10 minutes (Team & routing → late_min)
 * for our answer is MARKED once (table wa_late): who had it, when the customer wrote, whether that person was punched in, and — once answered —
 * how many minutes it took. Runs on every WhatsApp tick (webhook / cron) and whenever the Overview is opened, so it never depends on the cron alone.
 *   wa_late_scan($pdo)      → marks new late chats, closes the answered ones
 *   wa_late_summary($pdo)   → for the Overview: waiting late now (per person, longest wait) and late today (per person, average answer time)
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }
if (!function_exists('wa_late_scan')) {

function wa_late_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_late (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, emp_id INT NOT NULL DEFAULT 0, in_at DATETIME NOT NULL, late_at DATETIME NOT NULL,
            replied_at DATETIME NULL, mins INT NULL, on_shift TINYINT NOT NULL DEFAULT 0, UNIQUE KEY u_pi (phone, in_at), KEY k_emp (emp_id, in_at), KEY k_open (replied_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (PDOException $e) { error_log('wa_late_tables: ' . $e->getMessage()); return $ok = false; }
}
function wa_late_min(PDO $pdo): int { $c = function_exists('wa_team_cfg') ? wa_team_cfg($pdo) : []; $m = (int)($c['late_min'] ?? 10); return $m >= 2 && $m <= 240 ? $m : 10; }

function wa_late_scan(PDO $pdo, bool $force = false): array
{
    $o = ['marked' => 0, 'answered' => 0]; if (!wa_late_tables($pdo)) return $o;
    if (!$force && (int)setting_get($pdo, 'wa_late_scan_at', '0') > time() - 45) return $o; setting_set($pdo, 'wa_late_scan_at', (string)time());
    $lim = wa_late_min($pdo); $now = date('Y-m-d H:i:s'); $cut = date('Y-m-d H:i:s', time() - $lim * 60); $old = date('Y-m-d H:i:s', time() - 3 * 86400);
    $q = $pdo->prepare("SELECT phone, assigned_emp, last_out_at FROM wa_contacts WHERE assigned_emp > 0 AND status = 'open' AND optout = 0 AND last_in_at IS NOT NULL AND last_in_at >= ?
        AND (last_out_at IS NULL OR last_out_at < last_in_at) ORDER BY last_in_at LIMIT 2000"); $q->execute([$old]);   // longest-waiting first, up to 2,000 (busy days)
    $marked = []; $mq = $pdo->prepare("SELECT phone FROM wa_late WHERE replied_at IS NULL AND in_at >= ?"); $mq->execute([$old]); foreach ($mq->fetchAll() as $m) $marked[(string)$m['phone']] = 1;   // already marked late: no need to ask again
    $first = $pdo->prepare("SELECT MIN(ts) FROM wa_messages WHERE phone = ? AND dir = 'in' AND ts > ? AND ts >= ?");   // the FIRST message we have not answered: the wait starts there, not at the newest one
    $shift = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND punch_in IS NOT NULL AND punch_out IS NULL AND punch_in >= ?");
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_late (phone, emp_id, in_at, late_at, on_shift) VALUES (?, ?, ?, ?, ?)");
    foreach ($q->fetchAll() as $c) {
        if (isset($marked[(string)$c['phone']])) continue;
        $first->execute([$c['phone'], (string)($c['last_out_at'] ?: '2000-01-01 00:00:00'), $old]); $in = (string)$first->fetchColumn(); if ($in === '' || $in > $cut) continue;
        $on = 0; try { $shift->execute([(int)$c['assigned_emp'], date('Y-m-d H:i:s', time() - 20 * 3600)]); $on = (int)$shift->fetchColumn() > 0 ? 1 : 0; } catch (PDOException $e) { }
        $ins->execute([$c['phone'], (int)$c['assigned_emp'], $in, $now, $on]); $o['marked'] += $ins->rowCount();
    }
    // answered since: when and after how many minutes
    $op = $pdo->query("SELECT l.id, l.in_at, c.last_out_at FROM wa_late l JOIN wa_contacts c ON c.phone = l.phone WHERE l.replied_at IS NULL AND c.last_out_at IS NOT NULL AND c.last_out_at >= l.in_at LIMIT 500")->fetchAll();
    $up = $pdo->prepare("UPDATE wa_late SET replied_at = ?, mins = ? WHERE id = ?");
    foreach ($op as $r) { $up->execute([$r['last_out_at'], (int)round((strtotime((string)$r['last_out_at']) - strtotime((string)$r['in_at'])) / 60), $r['id']]); $o['answered']++; }
    return $o;
}

function wa_late_summary(PDO $pdo): array
{
    $o = ['min' => wa_late_min($pdo), 'now' => [], 'now_total' => 0, 'today' => [], 'today_total' => 0];
    if (!wa_late_tables($pdo)) return $o;
    $names = []; try { foreach ($pdo->query("SELECT employee_id, full_name FROM employees")->fetchAll() as $e) $names[(int)$e['employee_id']] = (string)$e['full_name']; } catch (PDOException $e) { }
    $nm = fn($id) => $names[(int)$id] ?? ('Employee #' . (int)$id);
    // still waiting: the chat is still unanswered (and still with someone)
    $q = $pdo->query("SELECT l.phone, l.in_at, c.assigned_emp, c.name FROM wa_late l JOIN wa_contacts c ON c.phone = l.phone WHERE l.replied_at IS NULL AND c.status = 'open' AND c.assigned_emp > 0 AND (c.last_out_at IS NULL OR c.last_out_at < l.in_at) ORDER BY l.in_at");
    $by = []; foreach ($q->fetchAll() as $r) { $e = (int)$r['assigned_emp']; $w = (int)round((time() - strtotime((string)$r['in_at'])) / 60);
        if (!isset($by[$e])) $by[$e] = ['emp' => $e, 'name' => $nm($e), 'n' => 0, 'longest' => 0, 'phones' => []]; $by[$e]['n']++; $by[$e]['longest'] = max($by[$e]['longest'], $w); if (count($by[$e]['phones']) < 60) $by[$e]['phones'][] = ['phone' => (string)$r['phone'], 'name' => (string)$r['name'], 'mins' => $w]; }
    usort($by, fn($a, $b) => [$b['longest'], $b['n']] <=> [$a['longest'], $a['n']]); $o['now'] = array_values($by); $o['now_total'] = array_sum(array_column($by, 'n'));
    // marked late today, per person
    $q = $pdo->prepare("SELECT emp_id, COUNT(*) AS n, AVG(mins) AS avg_m, MAX(mins) AS max_m, SUM(on_shift = 0) AS off FROM wa_late WHERE in_at >= ? GROUP BY emp_id ORDER BY n DESC"); $q->execute([date('Y-m-d 00:00:00')]);
    foreach ($q->fetchAll() as $r) $o['today'][] = ['emp' => (int)$r['emp_id'], 'name' => $nm($r['emp_id']), 'n' => (int)$r['n'], 'avg' => $r['avg_m'] === null ? null : (int)round((float)$r['avg_m']), 'max' => $r['max_m'] === null ? null : (int)$r['max_m'], 'off_shift' => (int)$r['off']];
    $o['today_total'] = array_sum(array_column($o['today'], 'n'));
    return $o;
}

}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ router: the Late replies tab
$__wl8 = (string)($action ?? ($_GET['action'] ?? ''));
if ($__wl8 === 'wa.late_board' || $__wl8 === 'wa.reassign_set') {
    $auth = require_auth(); if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    if ($__wl8 === 'wa.reassign_set') {   // 🔁 auto transfer on / off: a chat its salesperson does not accept in time moves to the next free person (max 2 times)
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
        $on = !empty(json_input()['on']); setting_set($pdo, 'wa_reassign', $on ? '1' : '0');
        if (function_exists('wa_log')) wa_log($pdo, 'engine', true, 'Auto transfer switched ' . ($on ? 'ON' : 'OFF') . ' by ' . (string)($auth['username'] ?? ('employee #' . (int)($auth['employee_id'] ?? 0))));
        json_response(['ok' => true, 'on' => $on]);
    }
    try { wa_late_scan($pdo); } catch (Throwable $e) { }
    json_response(['ok' => true, 'auto_transfer' => (string)setting_get($pdo, 'wa_reassign', '0') === '1', 'late' => wa_late_summary($pdo)]);
}

